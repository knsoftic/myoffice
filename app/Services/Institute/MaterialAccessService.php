<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Files\FileRules;
use App\DataObjects\Files\StreamOptions;
use App\DataObjects\Institute\MaterialGrant;
use App\Enums\CourseResourceType;
use App\Enums\EnrollmentStatus;
use App\Enums\MaterialAccessAction;
use App\Enums\MaterialStatus;
use App\Enums\MaterialTargetType;
use App\Enums\PanelType;
use App\Models\Institute\CourseMaterial;
use App\Models\Institute\CourseMaterialDownload;
use App\Models\Institute\Student;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\Institute\Teacher;
use App\Models\User;
use App\Services\Files\SecureFileService;
use App\Support\Device;
use App\Support\Institute\TeacherScope;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Who may open a material, and the record that they did (phase-19-23 §6.5, §6.6, INV-19-3, INV-19-4).
 *
 * **`grantFor()` is the single implementation of INV-19-3.** The student list query, the download
 * action and the link redirect all ask it; none of them decides for itself. A second copy of "is this
 * student entitled" is the defect D107 was about, and here it would mean a file served to somebody the
 * list would not have shown.
 *
 * **Targets are resolved live, every time.** `course_materials.audience_scope` caches the broadest
 * target for a badge on the index, and nothing reads it to decide access — a cache that granted access
 * would keep granting it for as long as it was stale.
 *
 * **INV-19-4: the row is written before the bytes move** (§6.4 step 6 precedes step 7). Logging after
 * the stream loses precisely the case worth keeping: the download that failed half way. `bytes_sent`
 * stays null until the stream finishes, so null means "did not complete" rather than "sent nothing".
 *
 * **`logAccess()` never throws into the response path.** A failure to log is itself logged and the file
 * still serves. That is a deliberate trade: a student who cannot open their coursework because an audit
 * table is full is a worse outcome than a missing log line, and the alternative — refusing the stream —
 * turns a storage problem into an outage. The acceptance test asserts the row is normally there.
 */
final class MaterialAccessService
{
    public function __construct(
        private readonly SecureFileService $files,
        private readonly StudentFeeService $fees,
    ) {}

    /**
     * May this user open this material? §6.5's `{allowed, reason, inline}`.
     *
     * The order is deliberate: the cheapest and most absolute checks first, so a disabled module never
     * reaches an enrollment query, and the reason returned is the *first* thing that was wrong rather
     * than whichever check happened to run last.
     */
    public function grantFor(CourseMaterial $material, User $user, ?Carbon $at = null): MaterialGrant
    {
        $at ??= Carbon::now();

        // (1) A disabled module denies everyone, Super Admin included (Gate::before step 1).
        if (! Modules::enabled('course_materials')) {
            return MaterialGrant::deny(MaterialGrant::MODULE_DISABLED);
        }

        // (2) Staff and the teacher who shared it see drafts; everybody else needs a published row.
        if ($this->isPrivileged($material, $user)) {
            return MaterialGrant::allow(inline: ! $this->isDownloadable($material));
        }

        if ($material->status !== MaterialStatus::Published || $material->trashed()) {
            return MaterialGrant::deny(MaterialGrant::NOT_PUBLISHED);
        }

        // (3) A teacher of a targeted batch reads it whatever their own enrollment says.
        $teacher = $this->teacherFor($user);

        if ($teacher instanceof Teacher && $this->teacherIsTargeted($material, $teacher)) {
            return MaterialGrant::allow(inline: ! $this->isDownloadable($material));
        }

        // (4) The window applies to students and to nobody else — staff upload ahead of the class.
        if (! $material->isWithinWindow($at)) {
            return MaterialGrant::deny(MaterialGrant::OUTSIDE_WINDOW);
        }

        $student = $this->studentFor($user);

        if (! $student instanceof Student) {
            return MaterialGrant::deny(MaterialGrant::NOT_TARGETED);
        }

        // (5) INV-19-3 proper: does a target resolve to this student, through a live enrollment or one
        //     inside the post-batch grace?
        $resolution = $this->resolveForStudent($material, $student, $at);

        if ($resolution !== null) {
            return MaterialGrant::deny($resolution);
        }

        // (6) Entitled — unless the institute withholds material over an unpaid balance.
        if ($this->isFeeBlocked($student)) {
            return MaterialGrant::deny(MaterialGrant::FEE_BLOCKED);
        }

        return MaterialGrant::allow(inline: ! $this->isDownloadable($material));
    }

    /**
     * §6.4 step 6 then step 7: log, then stream. The caller has already run steps 1–5.
     *
     * A grant refusal is a 404 when the material was never theirs and a 403 with a reason when it was:
     * telling somebody "you are not in the audience for this" confirms there is something to be in the
     * audience for.
     */
    public function stream(CourseMaterial $material, User $user, MaterialAccessAction $action, ?Carbon $at = null): StreamedResponse
    {
        $grant = $this->grantFor($material, $user, $at);

        if (! $grant->allowed) {
            abort($grant->isDiscoverable() ? Response::HTTP_FORBIDDEN : Response::HTTP_NOT_FOUND, $grant->message());
        }

        if (! $material->isFile()) {
            abort(Response::HTTP_NOT_FOUND, 'This material is a link, not a file.');
        }

        // `is_downloadable = false` means inline, and the request cannot argue with it. §6.5 says
        // plainly that this is deterrence and not DRM — the bytes still reach the browser — but the
        // request does not get to choose which it was.
        $action = $grant->inline ? MaterialAccessAction::View : $action;

        $stored = $material->storedFile();
        $rules = FileRules::courseMaterial($this->typeOf($material));

        if (! $this->files->exists($stored)) {
            abort(Response::HTTP_NOT_FOUND, 'The file is no longer available.');
        }

        // INV-19-4 — before the bytes move, not after.
        $record = $this->logAccess($material, $user, $action);

        $response = $this->files->stream(
            $stored,
            $grant->inline ? StreamOptions::inline() : StreamOptions::attachment(),
            $rules,
        );

        return $this->stampBytesWhenTheStreamFinishes($response, $record, $stored->sizeBytes);
    }

    /**
     * Stamp `bytes_sent` once the body has actually gone out.
     *
     * **The existing callback is wrapped, never replaced.** `setCallback()` overwrites the closure that
     * sends the file, so setting a bare one here would serve a 200 with an empty body — the response
     * would look perfectly healthy and contain nothing. The original runs first; the stamp happens only
     * if it returned, which is exactly the semantics `bytes_sent IS NULL` is meant to carry.
     */
    private function stampBytesWhenTheStreamFinishes(
        StreamedResponse $response,
        ?CourseMaterialDownload $record,
        int $bytes,
    ): StreamedResponse {
        if (! $record instanceof CourseMaterialDownload) {
            return $response;
        }

        $original = $response->getCallback();

        $response->setCallback(static function () use ($original, $record, $bytes): void {
            if ($original !== null) {
                $original();
            }

            try {
                $record->recordBytesSent($bytes);
            } catch (Throwable) {
                // The bytes are already on the wire; a failed stamp must not become an exception
                // thrown after the headers were sent.
            }
        });

        return $response;
    }

    /**
     * A `link` material: the same grant, the same log, then a 302.
     *
     * `Referrer-Policy: no-referrer` because the outbound request would otherwise carry our URL — which
     * names the course and the material id — into whatever the teacher linked to.
     */
    public function openLink(CourseMaterial $material, User $user, ?Carbon $at = null): RedirectResponse
    {
        $grant = $this->grantFor($material, $user, $at);

        if (! $grant->allowed) {
            abort($grant->isDiscoverable() ? Response::HTTP_FORBIDDEN : Response::HTTP_NOT_FOUND, $grant->message());
        }

        $url = (string) $material->getAttribute('external_url');

        if (! $material->isLink() || ! $this->isSafeUrl($url)) {
            abort(Response::HTTP_NOT_FOUND, 'This material has no link to open.');
        }

        $this->logAccess($material, $user, MaterialAccessAction::LinkOpen);

        return redirect()->away($url)->withHeaders([
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * The append-only write. **Never throws into the response path** — see the class note.
     */
    public function logAccess(CourseMaterial $material, User $user, MaterialAccessAction $action): ?CourseMaterialDownload
    {
        try {
            $student = $this->studentFor($user);
            $teacher = $this->teacherFor($user);
            $request = request();

            $record = new CourseMaterialDownload;
            $record->forceFill([
                'course_material_id' => (int) $material->getKey(),
                'user_id' => (int) $user->getKey(),
                'student_id' => $student?->getKey(),
                'teacher_id' => $teacher?->getKey(),
                'panel' => $this->panelFor($user, $student, $teacher)->value,
                'action' => $action->value,
                'ip_address' => $request?->ip(),
                'user_agent' => mb_substr((string) $request?->userAgent(), 0, 1000) ?: null,
                'device' => Device::device((string) $request?->userAgent()),
                'created_at' => Carbon::now(),
            ]);

            $record->saveQuietly();

            return $record;
        } catch (Throwable $e) {
            // A storage problem must not become an outage for the student. It is still a defect, so
            // it goes somewhere a human will find it.
            Log::error('Material access could not be logged', [
                'course_material_id' => $material->getKey(),
                'user_id' => $user->getKey(),
                'action' => $action->value,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * §6.6, written once — the query behind the student's library, their counts and their download
     * check, so none of the three can disagree with the other two.
     *
     * The final `whereIn('course_id', …)` is belt and braces the contract asks for by name: a target
     * row mis-written against a course the student is not on still cannot leak, because the material's
     * own course must also be one of theirs.
     */
    public function visibleToStudent(Student $student, ?Carbon $at = null): Builder
    {
        $at ??= Carbon::now();

        $courseIds = $this->reachableCourseIds($student, $at);
        $batchIds = $this->reachableBatchIds($student, $at);

        if ($courseIds === []) {
            return CourseMaterial::query()->whereRaw('1 = 0');
        }

        return CourseMaterial::query()
            ->availableAt($at)
            ->whereIn('course_id', $courseIds)
            ->whereHas('targets', static function (Builder $t) use ($courseIds, $batchIds, $student): void {
                $t->where(static function (Builder $q) use ($courseIds, $batchIds, $student): void {
                    $q->where(static fn (Builder $c): Builder => $c
                        ->where('target_type', MaterialTargetType::Course->value)
                        ->whereIn('target_course_id', $courseIds))
                        ->orWhere(static fn (Builder $b): Builder => $b
                            ->where('target_type', MaterialTargetType::Batch->value)
                            ->whereIn('target_batch_id', $batchIds))
                        ->orWhere(static fn (Builder $s): Builder => $s
                            ->where('target_type', MaterialTargetType::Student->value)
                            ->where('target_student_id', $student->getKey()));
                });
            });
    }

    /**
     * The teacher variant of §6.6: material shared with a batch they teach, **plus their own drafts** —
     * a teacher has to be able to see what they have not published yet.
     */
    public function visibleToTeacher(Teacher $teacher, User $user): Builder
    {
        $batchIds = TeacherScope::batchIds($teacher);

        return CourseMaterial::query()->where(static function (Builder $q) use ($batchIds, $teacher, $user): void {
            $q->where('teacher_id', $teacher->getKey())
                ->orWhere('created_by', $user->getKey())
                ->orWhereHas('targets', static fn (Builder $t): Builder => $t
                    ->where('target_type', MaterialTargetType::Batch->value)
                    ->whereIn('target_batch_id', $batchIds));
        });
    }

    // -------------------------------------------------------------------------------------------
    // INV-19-3's working parts
    // -------------------------------------------------------------------------------------------

    /**
     * Null when a target resolves; otherwise the reason it did not. Split out so `grantFor()` reads as
     * a sequence of questions rather than a nest of conditions.
     */
    private function resolveForStudent(CourseMaterial $material, Student $student, Carbon $at): ?string
    {
        $targets = $material->targets()->get();

        if ($targets->isEmpty()) {
            return MaterialGrant::NOT_TARGETED;
        }

        $studentId = (int) $student->getKey();
        $courseId = (int) $material->getAttribute('course_id');

        // A student target is the narrowest and needs no enrollment reasoning — somebody aimed this
        // at this person by name.
        foreach ($targets as $target) {
            if ($target->target_type === MaterialTargetType::Student
                && (int) $target->getAttribute('target_student_id') === $studentId) {
                return null;
            }
        }

        $live = $this->enrollments($student, $at);

        // Belt and braces (§6.6): the material's own course must be one this student can reach, so a
        // target written against the wrong course cannot let it through.
        if (! in_array($courseId, $live['courses'], true)) {
            return $this->lapsedOrNeverTheirs($live, $courseId);
        }

        foreach ($targets as $target) {
            $matched = match ($target->target_type) {
                MaterialTargetType::Course => in_array((int) $target->getAttribute('target_course_id'), $live['courses'], true),
                MaterialTargetType::Batch => in_array((int) $target->getAttribute('target_batch_id'), $live['batches'], true),
                MaterialTargetType::Student => false,
            };

            if ($matched) {
                return null;
            }
        }

        return $this->lapsedOrNeverTheirs($live, $courseId);
    }

    /**
     * "Was this course ever theirs?" decides between a 403 they can act on and a 404 that says nothing.
     *
     * **It must be asked about *this* course, not about enrollment in general.** Asking "does this
     * student have any enrollments at all" would tell every enrolled student in the institute that
     * every material they cannot see is one whose access has *expired* — which is false, and worse, a
     * 403 where a 404 belongs: it confirms the material exists to somebody who was never its audience.
     *
     * @param  array{courses: list<int>, batches: list<int>, everCourses: list<int>}  $live
     */
    private function lapsedOrNeverTheirs(array $live, int $courseId): string
    {
        return in_array($courseId, $live['everCourses'], true)
            ? MaterialGrant::ENROLLMENT_EXPIRED
            : MaterialGrant::NOT_TARGETED;
    }

    /**
     * The enrollments that still reach material: those whose status `countsInAttendance()`, plus those
     * whose batch ended less than `institute.material_visible_after_batch_end_days` ago.
     *
     * `everCourses` is every course the student was *ever* enrolled on, whatever the status — that is
     * what separates "this lapsed" from "this was never yours".
     *
     * @return array{courses: list<int>, batches: list<int>, everCourses: list<int>}
     */
    private function enrollments(Student $student, Carbon $at): array
    {
        $graceDays = max(0, (int) (settings_repo()->get('institute.material_visible_after_batch_end_days') ?? 90));
        $cutoff = $at->copy()->subDays($graceDays);

        $rows = StudentBatchEnrollment::query()
            ->with('batch:id,course_id,end_date')
            ->where('student_id', $student->getKey())
            ->get();

        $courses = [];
        $batches = [];
        $everCourses = [];

        foreach ($rows as $row) {
            $status = $row->status;
            $batch = $row->batch;

            if (! $batch) {
                continue;
            }

            $courseId = (int) $batch->getAttribute('course_id');
            $everCourses[] = $courseId;

            $endDate = $batch->getAttribute('end_date');

            $reaches = ($status instanceof EnrollmentStatus && $status->countsInAttendance())
                || ($endDate !== null && Carbon::parse($endDate)->greaterThanOrEqualTo($cutoff));

            if (! $reaches) {
                continue;
            }

            $courses[] = $courseId;
            $batches[] = (int) $batch->getKey();
        }

        return [
            'courses' => array_values(array_unique($courses)),
            'batches' => array_values(array_unique($batches)),
            'everCourses' => array_values(array_unique($everCourses)),
        ];
    }

    /** @return list<int> */
    private function reachableCourseIds(Student $student, Carbon $at): array
    {
        return $this->enrollments($student, $at)['courses'];
    }

    /** @return list<int> */
    private function reachableBatchIds(Student $student, Carbon $at): array
    {
        return $this->enrollments($student, $at)['batches'];
    }

    private function teacherIsTargeted(CourseMaterial $material, Teacher $teacher): bool
    {
        if ((int) $material->getAttribute('teacher_id') === (int) $teacher->getKey()) {
            return true;
        }

        $batchIds = TeacherScope::batchIds($teacher);

        return $batchIds !== [] && $material->targets()
            ->where('target_type', MaterialTargetType::Batch->value)
            ->whereIn('target_batch_id', $batchIds)
            ->exists();
    }

    /** Staff with the module permission, or the person who uploaded it. */
    private function isPrivileged(CourseMaterial $material, User $user): bool
    {
        if ((int) $material->getAttribute('created_by') === (int) $user->getKey()) {
            return true;
        }

        return $user->can('course_materials.download');
    }

    private function isFeeBlocked(Student $student): bool
    {
        if (! (bool) settings_repo()->get('institute.material_block_on_outstanding_fee')) {
            return false;
        }

        try {
            return bccomp($this->fees->outstandingFor($student), '0.00', 2) > 0;
        } catch (Throwable) {
            // A fee lookup that cannot answer must not silently withhold coursework.
            return false;
        }
    }

    private function isDownloadable(CourseMaterial $material): bool
    {
        return (bool) $material->getAttribute('is_downloadable');
    }

    private function typeOf(CourseMaterial $material): CourseResourceType
    {
        $type = $material->type;

        return $type instanceof CourseResourceType ? $type : CourseResourceType::Pdf;
    }

    /** `http` and `https` only — never `javascript:`, never `data:` (§2.3). */
    private function isSafeUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST) !== null;
    }

    private function studentFor(User $user): ?Student
    {
        return Student::query()->where('user_id', $user->getKey())->first();
    }

    private function teacherFor(User $user): ?Teacher
    {
        return Teacher::query()->where('user_id', $user->getKey())->first();
    }

    /** Which portal the open came from — the actor's own identity, not a request parameter. */
    private function panelFor(User $user, ?Student $student, ?Teacher $teacher): PanelType
    {
        if ($user->can('course_materials.view_any')) {
            return PanelType::Admin;
        }

        if ($teacher instanceof Teacher) {
            return PanelType::Teacher;
        }

        return $student instanceof Student ? PanelType::Student : PanelType::Admin;
    }
}
