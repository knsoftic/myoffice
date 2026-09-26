<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\ClassSessionStatus;
use App\Enums\TeacherStatus;
use App\Enums\UserStatus;
use App\Models\Institute\Teacher;
use App\Models\User;
use App\Notifications\Institute\PortalLoginCreatedNotification;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The teacher record (§72, phase-14-17 §6.11).
 *
 * **`teacher_code` is issued once and is never writable.** It goes through Phase 5's
 * `DocumentNumberService` inside the creating transaction, like every other document number in the
 * system (D27) — a second way to produce one is how two teachers come to share a code.
 *
 * **Moving off `active` is refused while the teacher still owns a scheduled class.** Not as a matter
 * of taste: a resigned trainer left on next week's timetable is a class nobody turns up to teach, and
 * the failure is only discovered by the students in the room. `changeStatus()` names the sessions and
 * asks for them to be reassigned or substituted first, rather than blocking with no way forward.
 *
 * **`linkEmployee()` copies, it does not join.** Phase 7's `employees` table does not exist yet, so
 * the link is by id with a unique index and no foreign key ([D-IN-9]); when it lands, the migration
 * that creates it attaches the key. Unlinking keeps the copied values — the history a teacher taught
 * under is not undone by an administrative change.
 */
final class TeacherService
{
    /** §72: every status can be moved to any other; what is guarded is what happens next. */
    private const REASON_REQUIRED = [
        TeacherStatus::Suspended->value,
        TeacherStatus::Resigned->value,
    ];

    /**
     * Whether the last `createLogin()` call created the account but failed to deliver the password.
     *
     * Same contract as `StudentService`: the account is real either way, so a mail failure must not
     * throw — but the operator has to be told, because the password is now gone.
     */
    private bool $credentialsMailFailed = false;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly StudentNumberService $numbers,
    ) {}

    /** True when the login was created but its credentials email did not go out. */
    public function credentialsMailFailed(): bool
    {
        return $this->credentialsMailFailed;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Teacher
    {
        return $this->db->transaction(function () use ($data, $actor): Teacher {
            $teacher = new Teacher;
            $teacher->fill($this->columns($data));

            $teacher->forceFill([
                'teacher_code' => $this->numbers->nextTeacherCode(),
                'status' => $this->statusFrom($data['status'] ?? null)->value,
                'branch_id' => $data['branch_id'] ?? $actor?->branch_id ?? setting('institute.default_branch_id'),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ])->save();

            $this->assertPublicHasASlug($teacher);

            if (($data['create_login'] ?? null) !== false) {
                $this->createLogin($teacher, $actor);
            }

            return $teacher->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Teacher $teacher, array $data, ?User $actor = null): Teacher
    {
        return $this->db->transaction(function () use ($teacher, $data): Teacher {
            $columns = $this->columns($data);

            // Identity and salary belong to the employee record while one is linked (§6.11); the form
            // locks them, and this is what makes that more than a front-end courtesy.
            if ($teacher->isLinkedToEmployee()) {
                unset($columns['name'], $columns['email'], $columns['phone'], $columns['salary']);
            }

            $teacher->fill($columns)->save();

            $this->assertPublicHasASlug($teacher);

            return $teacher->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public function changeStatus(
        Teacher $teacher,
        TeacherStatus $to,
        ?string $reason = null,
        ?User $actor = null,
    ): Teacher {
        $from = $teacher->status;

        if ($from === $to) {
            return $teacher;
        }

        $reason = trim((string) $reason);

        if (in_array($to->value, self::REASON_REQUIRED, true) && $reason === '') {
            throw CourseRuleException::reasonRequired('status', sprintf(
                'Moving a teacher to %s takes a reason. It goes on the record, and it is the first '
                .'thing anybody asks afterwards.',
                $to->label(),
            ));
        }

        if ($from->canTeach() && ! $to->canTeach()) {
            $this->assertNothingScheduled($teacher, $to);
        }

        return $this->db->transaction(function () use ($teacher, $to, $reason, $actor): Teacher {
            $teacher->forceFill([
                'status' => $to->value,
                'status_reason' => $reason !== '' ? $reason : null,
                'updated_by' => $actor?->getKey(),
            ])->save();

            $this->syncLoginTo($teacher, $to);

            return $teacher->refresh();
        }, 3);
    }

    /**
     * The check that stops a resigned teacher silently owning next week's timetable.
     *
     * It looks only as far ahead as classes are actually generated — beyond that there is nothing to
     * reassign yet, and refusing on a class that does not exist would be a refusal nobody could act on.
     */
    private function assertNothingScheduled(Teacher $teacher, TeacherStatus $to): void
    {
        $weeks = max(1, (int) setting('institute.session_generation_weeks_ahead', 8));
        $horizon = Carbon::today()->addWeeks($weeks);

        $sessions = DB::table('class_sessions')
            ->whereNull('deleted_at')
            ->where('teacher_id', $teacher->getKey())
            ->where('status', ClassSessionStatus::Scheduled->value)
            ->whereDate('session_date', '>=', Carbon::today()->toDateString())
            ->whereDate('session_date', '<=', $horizon->toDateString())
            ->orderBy('session_date')
            ->limit(5)
            ->get(['id', 'session_date', 'start_time']);

        if ($sessions->isEmpty()) {
            return;
        }

        $total = DB::table('class_sessions')
            ->whereNull('deleted_at')
            ->where('teacher_id', $teacher->getKey())
            ->where('status', ClassSessionStatus::Scheduled->value)
            ->whereDate('session_date', '>=', Carbon::today()->toDateString())
            ->whereDate('session_date', '<=', $horizon->toDateString())
            ->count();

        $listed = $sessions
            ->map(static fn ($s): string => Carbon::parse($s->session_date)->format('d M').' '
                .Carbon::parse($s->start_time)->format('H:i'))
            ->implode(', ');

        throw CourseRuleException::refuse('status', sprintf(
            '%s still has %d scheduled %s in the next %d weeks (%s%s). Substitute or reassign %s '
            .'before moving them to %s — otherwise nobody is teaching those classes and nobody knows it.',
            $teacher->name,
            $total,
            $total === 1 ? 'class' : 'classes',
            $weeks,
            $listed,
            $total > $sessions->count() ? ', …' : '',
            $total === 1 ? 'it' : 'them',
            $to->label(),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Courses and the employee link
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, int>  $courseIds
     */
    public function assignCourses(Teacher $teacher, array $courseIds, ?int $primaryCourseId = null): void
    {
        $courseIds = array_values(array_unique(array_map('intval', $courseIds)));

        $this->db->transaction(function () use ($teacher, $courseIds, $primaryCourseId): void {
            $payload = [];

            foreach ($courseIds as $courseId) {
                $payload[$courseId] = [
                    'is_primary' => $courseId === $primaryCourseId,
                    'assigned_on' => Carbon::today()->toDateString(),
                ];
            }

            $teacher->courses()->sync($payload);

            // At most one primary per course (§2.18): naming this teacher as primary demotes whoever
            // held it, rather than leaving two rows both claiming to be the one in charge.
            if ($primaryCourseId !== null && in_array($primaryCourseId, $courseIds, true)) {
                DB::table('course_teacher')
                    ->where('course_id', $primaryCourseId)
                    ->where('teacher_id', '!=', $teacher->getKey())
                    ->update(['is_primary' => false, 'updated_at' => Carbon::now()]);
            }
        }, 3);
    }

    public function linkEmployee(Teacher $teacher, int $employeeId, string $reason, ?User $actor = null): Teacher
    {
        if ($teacher->isLinkedToEmployee()) {
            throw CourseRuleException::refuse('employee_id', sprintf(
                '%s is already linked to an employee record. Unlink that one first — a teacher with '
                .'two employee records is a salary paid twice.',
                $teacher->name,
            ));
        }

        $taken = Teacher::query()->where('employee_id', $employeeId)->first();

        if ($taken !== null) {
            throw CourseRuleException::refuse('employee_id', sprintf(
                'That employee record is already linked to %s.',
                $taken->name,
            ));
        }

        if (trim($reason) === '') {
            throw CourseRuleException::reasonRequired('reason',
                'Linking a teacher to an employee record copies their identity and their salary. Say why.');
        }

        return $this->db->transaction(function () use ($teacher, $employeeId, $reason, $actor): Teacher {
            $teacher->forceFill([
                'employee_id' => $employeeId,
                'updated_by' => $actor?->getKey(),
            ])->save();

            activity('teachers')
                ->performedOn($teacher)
                ->causedBy($actor)
                ->withProperties(['employee_id' => $employeeId, 'reason' => $reason])
                ->log('teacher.employee_linked');

            return $teacher->refresh();
        }, 3);
    }

    public function unlinkEmployee(Teacher $teacher, string $reason, ?User $actor = null): Teacher
    {
        if (! $teacher->isLinkedToEmployee()) {
            return $teacher;
        }

        if (trim($reason) === '') {
            throw CourseRuleException::reasonRequired('reason', 'Say why the link is being removed.');
        }

        return $this->db->transaction(function () use ($teacher, $reason, $actor): Teacher {
            $previous = $teacher->employee_id;

            // The copied name, email and salary stay: the record of what this teacher was paid is not
            // undone by an administrative change, it is only unlocked for editing.
            $teacher->forceFill([
                'employee_id' => null,
                'updated_by' => $actor?->getKey(),
            ])->save();

            activity('teachers')
                ->performedOn($teacher)
                ->causedBy($actor)
                ->withProperties(['employee_id' => $previous, 'reason' => $reason])
                ->log('teacher.employee_unlinked');

            return $teacher->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | The login
    |--------------------------------------------------------------------------
    */

    /**
     * The generated password leaves here only by `PortalLoginCreatedNotification`. Check
     * `credentialsMailFailed()` afterwards: a `true` means the account exists and nobody can sign
     * in to it until somebody resets it.
     */
    public function createLogin(Teacher $teacher, ?User $actor = null): ?User
    {
        if ($teacher->user_id !== null) {
            return $teacher->loadMissing('user')->user;
        }

        if (! (bool) setting('institute.auto_create_teacher_login', true)) {
            return null;
        }

        $email = trim((string) $teacher->email);

        if ($email === '') {
            return null;
        }

        if (User::query()->where('email', $email)->exists()) {
            throw CourseRuleException::refuse('email', sprintf(
                'There is already an account for %s. Link it to this teacher instead of creating a '
                .'second one.',
                $email,
            ));
        }

        // Reset here, not at the top: the early returns above must leave the previous result alone.
        $this->credentialsMailFailed = false;

        return $this->db->transaction(function () use ($teacher, $email, $actor): User {
            $user = new User;

            /*
            | Held in a local only long enough to mail it. Never returned by this method, never
            | logged, never written anywhere but the hash below — so the message sent after this
            | transaction commits is genuinely the only copy that ever exists.
            */
            $plainPassword = Str::password(16, true, true, false, false);

            $user->forceFill([
                'name' => $teacher->name,
                'email' => $email,
                'password' => $plainPassword,
                'status' => UserStatus::Active->value,
                'must_change_password' => true,
                'branch_id' => $teacher->branch_id,
                'email_verified_at' => null,
                'created_by' => $actor?->getKey(),
            ])->save();

            $user->assignRole('Teacher');

            $teacher->forceFill([
                'user_id' => $user->getKey(),
                'updated_by' => $actor?->getKey(),
            ])->save();

            /*
            | **After the commit, not inside it.** Sent inside, a later failure would roll the
            | account back while the teacher keeps an email holding credentials for a user that no
            | longer exists. Sent after, the worst case is an account that exists and an email that
            | did not arrive, which a password reset fixes — and which `credentialsMailFailed()`
            | tells the operator about instead of leaving them to find out from the teacher.
            */
            $this->db->afterCommit(function () use ($user, $teacher, $plainPassword): void {
                try {
                    $user->notify(new PortalLoginCreatedNotification(
                        $plainPassword,
                        (string) $teacher->name,
                        'teacher portal',
                        'see your timetable, classes, registers and the students in each batch',
                    ));
                } catch (Throwable $e) {
                    $this->credentialsMailFailed = true;

                    Log::error('Teacher login created but the credentials email failed', [
                        'teacher_id' => $teacher->getKey(),
                        'user_id' => $user->getKey(),
                        'message' => $e->getMessage(),
                    ]);
                }
            });

            return $user;
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    /**
     * What this teacher's week looks like, and whether they are filling in their registers (§99).
     *
     * @return array<string, mixed>
     */
    public function workload(Teacher $teacher, Carbon $from, Carbon $to): array
    {
        $sessions = DB::table('class_sessions')
            ->whereNull('deleted_at')
            ->where('teacher_id', $teacher->getKey())
            ->whereBetween('session_date', [$from->toDateString(), $to->toDateString()]);

        $held = (clone $sessions)->where('status', ClassSessionStatus::Held->value)->count();
        $marked = (clone $sessions)
            ->where('status', ClassSessionStatus::Held->value)
            ->whereNotNull('attendance_marked_at')
            ->count();
        $cancelled = (clone $sessions)->where('status', ClassSessionStatus::Cancelled->value)->count();

        $minutes = (int) (clone $sessions)
            ->whereIn('status', [ClassSessionStatus::Scheduled->value, ClassSessionStatus::Held->value])
            ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(MINUTE, start_time, end_time)), 0) AS m')
            ->value('m');

        $batchIds = DB::table('batches')
            ->whereNull('deleted_at')
            ->where('teacher_id', $teacher->getKey())
            ->pluck('id');

        $students = $batchIds->isEmpty() ? 0 : (int) DB::table('student_batch_enrollments')
            ->whereNull('deleted_at')
            ->whereIn('batch_id', $batchIds)
            ->where('status', 'active')
            ->count();

        return [
            'from' => $from->copy(),
            'to' => $to->copy(),
            'batches' => $batchIds->count(),
            'students' => $students,
            'sessions_scheduled' => (clone $sessions)->where('status', ClassSessionStatus::Scheduled->value)->count(),
            'sessions_held' => $held,
            'sessions_cancelled' => $cancelled,
            'hours' => round($minutes / 60, 1),
            'registers_marked' => $marked,
            // "Did they fill in the register" — the one compliance number §99 asks for.
            'marking_compliance' => $held > 0 ? round(($marked / $held) * 100, 1) : null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function statusFrom(mixed $status): TeacherStatus
    {
        if ($status instanceof TeacherStatus) {
            return $status;
        }

        return TeacherStatus::tryFrom((string) $status) ?? TeacherStatus::Active;
    }

    private function assertPublicHasASlug(Teacher $teacher): void
    {
        if ($teacher->is_public && trim((string) $teacher->slug) === '') {
            throw CourseRuleException::refuse('slug',
                'A teacher shown on the website needs a slug — that is the address their profile lives at.');
        }
    }

    /**
     * A teacher who has resigned or been suspended must not be able to sign in, and one reinstated
     * must. Phase 1's `active` middleware reads `users.status`, so this is where the two agree.
     */
    private function syncLoginTo(Teacher $teacher, TeacherStatus $status): void
    {
        $user = $teacher->user;

        if (! $user instanceof User) {
            return;
        }

        $wanted = $status->canLogin() ? UserStatus::Active : UserStatus::Inactive;

        if ($user->status === $wanted) {
            return;
        }

        $user->forceFill(['status' => $wanted->value])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'branch_id', 'slug', 'name', 'photo_path', 'phone', 'whatsapp', 'email', 'gender',
            'qualification', 'experience_years', 'experience_note', 'skills', 'specialization',
            'bio', 'public_bio', 'social_links', 'joining_date', 'salary', 'is_public',
            'sort_order', 'notes',
        ]));
    }
}
