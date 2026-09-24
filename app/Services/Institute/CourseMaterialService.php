<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Files\FileRules;
use App\DataObjects\Files\FileTarget;
use App\DataObjects\Institute\TargetResult;
use App\DataObjects\Support\AudienceInput;
use App\Enums\CourseResourceType;
use App\Enums\EnrollmentStatus;
use App\Enums\MaterialAccessAction;
use App\Enums\MaterialStatus;
use App\Enums\MaterialTargetType;
use App\Models\Institute\Batch;
use App\Models\Institute\CourseMaterial;
use App\Models\Institute\CourseMaterialTarget;
use App\Models\Institute\Student;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Files\SecureFileService;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Services\Support\NotificationService;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sharing a material, and deciding who gets it (phase-19-23 §6.5, requirement §79).
 *
 * **[D-19-1] This is distribution, not syllabus.** `CourseOutlineService` owns what a course *teaches*;
 * this owns what a teacher *handed out*. A material may point at a topic for organisation, and it never
 * replaces the syllabus row.
 *
 * **`setTargets()` asserts that every target resolves to the material's own course**, and says which one
 * did not rather than dropping it. §6.5 is explicit about that: a silent drop means a teacher believes
 * a batch received something it never did, and they find out when a student asks.
 *
 * **Caches are recounted, never incremented** (the Phase 6 INV-P6 discipline). An increment is a second
 * source of truth that drifts under concurrency and cannot be checked; a COUNT under a row lock is
 * re-derivable, and the verifier re-derives it.
 */
final class CourseMaterialService
{
    use WritesAuditTrail;

    private const MODULE = 'course_materials';

    public function __construct(
        private readonly SecureFileService $files,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Share a material. One transaction: the bytes land first (outside it, because a filesystem write
     * is not transactional and holding a row lock across one is how a slow upload becomes a deadlock),
     * then every row is written together.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{type: string, id: int}>  $targets
     */
    public function create(array $attributes, ?UploadedFile $file, array $targets = [], ?User $actor = null): CourseMaterial
    {
        $actor ??= Auth::user();
        $type = $this->typeFrom($attributes);

        $stored = null;

        if ($type->isFile()) {
            if (! $file instanceof UploadedFile) {
                throw CourseRuleException::refuse('file', 'A '.$type->label().' material needs a file.');
            }

            $stored = $this->files->store(
                $file,
                FileTarget::courseMaterial((int) $attributes['course_id']),
                FileRules::courseMaterial($type),
            );
        } elseif ($file instanceof UploadedFile) {
            throw CourseRuleException::refuse('file', 'A link material carries no file. Remove it or change the kind.');
        }

        return DB::transaction(function () use ($attributes, $type, $stored, $targets): CourseMaterial {
            $material = new CourseMaterial;

            $material->forceFill(array_merge([
                'course_id' => (int) $attributes['course_id'],
                'course_topic_id' => $attributes['course_topic_id'] ?? null,
                'teacher_id' => $attributes['teacher_id'] ?? null,
                'branch_id' => $attributes['branch_id'] ?? null,
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'type' => $type->value,
                'external_url' => $type->isFile() ? null : $this->assertSafeUrl($attributes['external_url'] ?? null),
                // A link is always openable: there is nothing to withhold.
                'is_downloadable' => $type->isFile() ? (bool) ($attributes['is_downloadable'] ?? true) : true,
                'available_from' => $attributes['available_from'] ?? null,
                'available_until' => $attributes['available_until'] ?? null,
                'status' => MaterialStatus::Draft->value,
                'sort_order' => (int) ($attributes['sort_order'] ?? 0),
                'notes' => $attributes['notes'] ?? null,
            ], $stored?->toColumns() ?? []));

            $material->save();

            if ($targets !== []) {
                $this->writeTargets($material, $targets);
            }

            $this->recountCaches($material);

            $this->audit($material, 'Material created', [
                'attributes' => [
                    'course_id' => $material->getAttribute('course_id'),
                    'title' => $material->getAttribute('title'),
                    'type' => $type->value,
                    'file_path' => $material->getAttribute('file_path'),
                    'external_url' => $material->getAttribute('external_url'),
                ],
            ], self::MODULE);

            return $material->refresh();
        });
    }

    /**
     * Everything except the bytes. `file_path` and its six companions are absent from the writable set
     * on purpose — a replacement goes through `replaceFile()`, which keeps the old file until the swap
     * has committed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(CourseMaterial $material, array $attributes, ?User $actor = null): CourseMaterial
    {
        return DB::transaction(function () use ($material, $attributes): CourseMaterial {
            $locked = $this->lock($material);
            $type = $locked->type;

            $before = $locked->only(['title', 'description', 'course_topic_id', 'is_downloadable', 'available_from', 'available_until', 'external_url']);

            // Absent means unchanged; present-and-null means cleared. `?? null` would turn every key
            // the caller did not send into an instruction to wipe the column, so a screen that posts
            // one field would silently clear the release window.
            $keep = static fn (string $key, mixed $current): mixed => array_key_exists($key, $attributes)
                ? $attributes[$key]
                : $current;

            $locked->forceFill([
                'title' => $keep('title', $locked->getAttribute('title')),
                'description' => $keep('description', $locked->getAttribute('description')),
                'course_topic_id' => $keep('course_topic_id', $locked->getAttribute('course_topic_id')),
                'teacher_id' => $keep('teacher_id', $locked->getAttribute('teacher_id')),
                'is_downloadable' => $type instanceof CourseResourceType && ! $type->isFile()
                    ? true
                    : (bool) $keep('is_downloadable', $locked->getAttribute('is_downloadable')),
                'available_from' => $keep('available_from', $locked->getAttribute('available_from')),
                'available_until' => $keep('available_until', $locked->getAttribute('available_until')),
                'sort_order' => (int) $keep('sort_order', $locked->getAttribute('sort_order')),
                'notes' => $keep('notes', $locked->getAttribute('notes')),
            ]);

            if (array_key_exists('external_url', $attributes) && $type instanceof CourseResourceType && ! $type->isFile()) {
                $locked->forceFill(['external_url' => $this->assertSafeUrl($attributes['external_url'])]);
            }

            $locked->save();

            $this->audit($locked, 'Material updated', [
                'old' => $before,
                'attributes' => $locked->only(array_keys($before)),
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    /**
     * Swap the bytes. The access log is deliberately kept: it records who *opened* the material, which
     * is still true of the material after its file is replaced. Losing it would erase the engagement
     * history every time a teacher fixed a typo in a handout.
     */
    public function replaceFile(CourseMaterial $material, UploadedFile $file, ?User $actor = null): CourseMaterial
    {
        $type = $material->type;

        if (! $type instanceof CourseResourceType || ! $type->isFile()) {
            throw CourseRuleException::refuse('file', 'A link material has no file to replace.');
        }

        $old = $material->storedFile();

        $stored = $this->files->replace(
            $old,
            $file,
            FileTarget::courseMaterial((int) $material->getAttribute('course_id'), $material),
            FileRules::courseMaterial($type),
        );

        return DB::transaction(function () use ($material, $old, $stored): CourseMaterial {
            $locked = $this->lock($material);
            $locked->forceFill($stored->toColumns());
            $locked->save();

            $this->audit($locked, 'Material file replaced', [
                'old' => ['file_path' => $old->path, 'checksum_sha256' => $old->checksumSha256],
                'attributes' => ['file_path' => $stored->path, 'checksum_sha256' => $stored->checksumSha256],
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    /**
     * Set the whole audience in one call. Targets not in `$targets` are removed; targets already there
     * keep their `notified_at`, so re-aiming never re-tells an audience that already heard.
     *
     * @param  list<array{type: string, id: int}>  $targets
     */
    public function setTargets(CourseMaterial $material, array $targets, ?User $actor = null): TargetResult
    {
        return DB::transaction(function () use ($material, $targets): TargetResult {
            $locked = $this->lock($material);
            $wanted = $this->validateTargets($locked, $targets);

            $existing = $locked->targets()->get()->keyBy(
                static fn (CourseMaterialTarget $t): string => $t->target_type->value.':'.$t->targetId()
            );

            $added = 0;
            $unchanged = 0;

            foreach ($wanted as $key => $row) {
                if ($existing->has($key)) {
                    $unchanged++;

                    continue;
                }

                $target = new CourseMaterialTarget;
                $target->forceFill([
                    'course_material_id' => (int) $locked->getKey(),
                    'target_type' => $row['type']->value,
                    $row['type']->column() => $row['id'],
                ]);
                $target->save();

                $this->audit($locked, 'Material target added', [
                    'attributes' => ['target_type' => $row['type']->value, 'target_id' => $row['id']],
                ], self::MODULE);

                $added++;
            }

            $removed = 0;

            foreach ($existing as $key => $target) {
                if (isset($wanted[$key])) {
                    continue;
                }

                $this->audit($locked, 'Material target removed', [
                    'old' => ['target_type' => $target->target_type->value, 'target_id' => $target->targetId()],
                ], self::MODULE);

                $target->forceDelete();
                $removed++;
            }

            $this->recountCaches($locked);

            return new TargetResult(
                added: $added,
                removed: $removed,
                unchanged: $unchanged,
                addedNotYetNotified: $added,
                scope: $locked->refresh()->audience_scope,
            );
        });
    }

    /** §2.28.1. Publishing stamps the date once and never re-stamps it on a later republish. */
    public function publish(CourseMaterial $material, ?User $actor = null): CourseMaterial
    {
        $published = DB::transaction(function () use ($material): CourseMaterial {
            $locked = $this->lock($material);

            if ($locked->targets()->doesntExist()) {
                throw CourseRuleException::refuse(
                    'targets',
                    'Choose who this material is for before publishing it. An untargeted material reaches nobody.',
                );
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => MaterialStatus::Published->value,
                'published_at' => $locked->getAttribute('published_at') ?? Carbon::now(),
            ]);
            $locked->save();

            $this->audit($locked, 'Material published', [
                'old' => ['status' => $from?->value],
                'attributes' => ['status' => MaterialStatus::Published->value],
            ], self::MODULE);

            return $locked->refresh();
        });

        // **Sharing something nobody is told about is not sharing it** (§79). The audience is the
        // material's own targets resolved to students with a login; `course_material_targets` is
        // what the student's library reads, so telling exactly those people is telling exactly the
        // people who can open it.
        //
        // Dispatched after the transaction, like every other notification in this system.
        $this->announcePublication($published, $actor);

        return $published;
    }

    /**
     * Tell the students this material was shared with.
     *
     * A re-publish tells them again, and that is deliberate for now: `notified_at` on
     * `course_material_targets` is the column that would stop it, and stamping it belongs with the
     * engagement screen that reads it — §10.2 assigns both to `NotifyMaterialAudience`. Until that
     * listener exists, a second publish is a second announcement, which is the safer of the two
     * wrong answers: the other one is a material nobody hears about because it was published once
     * before anybody was targeted.
     */
    private function announcePublication(CourseMaterial $material, ?User $actor): void
    {
        // **A target is a student, a batch or a course**, so the audience is the union of the three
        // — a material shared with a batch reaches that batch's students, and reading only
        // `target_student_id` would tell almost nobody.
        $targets = DB::table('course_material_targets')
            ->where('course_material_id', $material->getKey())
            ->get(['target_type', 'target_student_id', 'target_batch_id', 'target_course_id']);

        if ($targets->isEmpty()) {
            return;
        }

        $studentIds = $targets->pluck('target_student_id')->filter()->map(static fn ($id): int => (int) $id)->all();
        $batchIds = $targets->pluck('target_batch_id')->filter()->map(static fn ($id): int => (int) $id)->all();
        $courseIds = $targets->pluck('target_course_id')->filter()->map(static fn ($id): int => (int) $id)->all();

        $enrolled = DB::table('student_batch_enrollments')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($batchIds, $courseIds): void {
                $query->whereRaw('1 = 0');

                if ($batchIds !== []) {
                    $query->orWhereIn('batch_id', $batchIds);
                }

                if ($courseIds !== []) {
                    $query->orWhereIn('batch_id', DB::table('batches')
                        ->whereIn('course_id', $courseIds)
                        ->whereNull('deleted_at')
                        ->select('id'));
                }
            })
            ->pluck('student_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $studentIds = array_values(array_unique(array_merge($studentIds, $enrolled)));

        if ($studentIds === []) {
            return;
        }

        $userIds = DB::table('students')
            ->whereIn('id', $studentIds)
            ->whereNull('deleted_at')
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            return;
        }

        $this->notifications->dispatch('material.published', AudienceInput::of($userIds), [
            'title' => 'New material: '.$material->getAttribute('title'),
            'body' => 'It is in your library now.',
            'url' => '/student/materials/'.$material->getKey(),
            'course_material_id' => (int) $material->getKey(),
        ], $actor);
    }

    /** Off the students' list. A reason is mandatory — somebody will ask where it went. */
    public function unpublish(CourseMaterial $material, string $reason, ?User $actor = null): CourseMaterial
    {
        return $this->moveTo($material, MaterialStatus::Draft, $reason);
    }

    public function archive(CourseMaterial $material, string $reason, ?User $actor = null): CourseMaterial
    {
        return $this->moveTo($material, MaterialStatus::Archived, $reason);
    }

    public function restoreToPublished(CourseMaterial $material, string $reason, ?User $actor = null): CourseMaterial
    {
        return $this->moveTo($material, MaterialStatus::Published, $reason);
    }

    /**
     * The four caches, by COUNT under the row lock the caller already holds — never incremented.
     *
     * `audience_scope` is recomputed here too: it is the **broadest** remaining target, so removing the
     * course-wide target of a material that also names two batches correctly narrows the badge from
     * "whole course" to "batch".
     */
    /**
     * Who opened a material, against who was meant to (phase-19-23 6.22 `materialEngagement`).
     *
     * **Unique students, not download count.** One student opening a file nine times is one student
     * who engaged; counting downloads would turn a chart of "engagement" into a chart of how often
     * people lose their place in a PDF. `COUNT(DISTINCT student_id)` is the whole point.
     *
     * **The denominator is the resolved target audience**, counted per material rather than read
     * from a column: a material targeted at a course reaches every student enrolled on it today,
     * and that number moves as people enrol.
     *
     * @param  array<string, mixed>  $filters  `course_id`, `material_id`
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function engagement(DateRange $range, array $filters = []): array
    {
        $materials = DB::table('course_materials as m')
            ->leftJoin('courses as c', 'c.id', '=', 'm.course_id')
            ->whereNull('m.deleted_at')
            ->whereBetween('m.created_at', [$range->start(), $range->end()])
            ->when(! empty($filters['course_id']), fn ($q) => $q->where('m.course_id', $filters['course_id']))
            ->when(! empty($filters['material_id']), fn ($q) => $q->where('m.id', $filters['material_id']))
            ->orderBy('m.id')
            ->get(['m.id', 'm.title', 'm.course_id', 'c.name as course_name']);

        $rows = [];
        $totals = ['targeted' => 0, 'opened' => 0];

        foreach ($materials as $material) {
            $opened = (int) DB::table('course_material_downloads')
                ->where('course_material_id', $material->id)
                ->whereNotNull('student_id')
                ->distinct()
                ->count('student_id');

            $targeted = $this->targetedStudentCount((int) $material->id);

            $rows[] = [
                'material' => (string) $material->title,
                'course' => $material->course_name,
                'targeted' => $targeted,
                'opened' => $opened,
                // No audience means no percentage: a material nobody was assigned has not been
                // ignored by 100% of anybody.
                'rate' => $targeted > 0
                    ? Money::round(Money::mul(Money::div((string) $opened, (string) $targeted), '100'), 2)
                    : null,
            ];

            $totals['targeted'] += $targeted;
            $totals['opened'] += $opened;
        }

        $totals['rate'] = $totals['targeted'] > 0
            ? Money::round(Money::mul(Money::div((string) $totals['opened'], (string) $totals['targeted']), '100'), 2)
            : null;

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * How many students a material's targets resolve to right now.
     *
     * A distinct set across all three target kinds, so a student both enrolled on a targeted course
     * and named individually counts once rather than twice.
     */
    private function targetedStudentCount(int $materialId): int
    {
        $targets = DB::table('course_material_targets')
            ->where('course_material_id', $materialId)
            ->get(['target_type', 'target_course_id', 'target_batch_id', 'target_student_id']);

        if ($targets->isEmpty()) {
            return 0;
        }

        $students = [];

        foreach ($targets as $target) {
            if ($target->target_student_id !== null) {
                $students[(int) $target->target_student_id] = true;

                continue;
            }

            $query = DB::table('student_batch_enrollments')
                ->whereNull('deleted_at')
                ->where('status', EnrollmentStatus::Active->value);

            if ($target->target_batch_id !== null) {
                $query->where('batch_id', $target->target_batch_id);
            } elseif ($target->target_course_id !== null) {
                $query->where('course_id', $target->target_course_id);
            } else {
                continue;
            }

            foreach ($query->pluck('student_id') as $id) {
                $students[(int) $id] = true;
            }
        }

        return count($students);
    }

    public function recountCaches(CourseMaterial $material): void
    {
        $targets = $material->targets()->get();

        $scope = MaterialTargetType::broadest(
            $targets->map(static fn (CourseMaterialTarget $t): MaterialTargetType => $t->target_type)
        ) ?? MaterialTargetType::Course;

        $log = $material->accessLog();

        $material->forceFill([
            'targets_count' => $targets->count(),
            'audience_scope' => $scope->value,
            'view_count' => (clone $log)->where('action', MaterialAccessAction::View->value)->count(),
            'download_count' => (clone $log)->whereIn('action', [
                MaterialAccessAction::Download->value,
                MaterialAccessAction::LinkOpen->value,
            ])->count(),
            'unique_students_count' => (clone $log)->whereNotNull('student_id')->distinct()->count('student_id'),
        ]);

        $material->saveQuietly();
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Every target must resolve to this material's course, and a mismatch names the row rather than
     * being dropped (§6.5).
     *
     * @param  list<array{type: string, id: int}>  $targets
     * @return array<string, array{type: MaterialTargetType, id: int}>
     */
    private function validateTargets(CourseMaterial $material, array $targets): array
    {
        $courseId = (int) $material->getAttribute('course_id');
        $wanted = [];

        foreach ($targets as $row) {
            $type = MaterialTargetType::tryFrom((string) ($row['type'] ?? ''));
            $id = (int) ($row['id'] ?? 0);

            if (! $type instanceof MaterialTargetType || $id < 1) {
                throw CourseRuleException::refuse('targets', 'One of the chosen audiences is not a valid target.');
            }

            match ($type) {
                MaterialTargetType::Course => $this->assertCourse($id, $courseId),
                MaterialTargetType::Batch => $this->assertBatch($id, $courseId),
                MaterialTargetType::Student => $this->assertStudent($id, $courseId),
            };

            // Silently collapses an audience listed twice in one submission — that is a duplicate in
            // the request, not a conflict worth refusing.
            $wanted[$type->value.':'.$id] = ['type' => $type, 'id' => $id];
        }

        return $wanted;
    }

    private function assertCourse(int $id, int $courseId): void
    {
        if ($id !== $courseId) {
            throw CourseRuleException::refuse('targets', 'A material can only be shared with its own course.');
        }
    }

    private function assertBatch(int $id, int $courseId): void
    {
        $batch = Batch::query()->find($id);

        if (! $batch instanceof Batch) {
            throw CourseRuleException::refuse('targets', 'Batch #'.$id.' no longer exists.');
        }

        if ((int) $batch->getAttribute('course_id') !== $courseId) {
            throw CourseRuleException::refuse('targets', sprintf(
                'Batch %s runs a different course, so it cannot receive this material.',
                (string) ($batch->getAttribute('code') ?? '#'.$id),
            ));
        }
    }

    private function assertStudent(int $id, int $courseId): void
    {
        $student = Student::query()->find($id);

        if (! $student instanceof Student) {
            throw CourseRuleException::refuse('targets', 'Student #'.$id.' no longer exists.');
        }

        $enrolled = StudentBatchEnrollment::query()
            ->where('student_id', $id)
            ->whereHas('batch', static fn ($q) => $q->where('course_id', $courseId))
            ->exists();

        if (! $enrolled) {
            throw CourseRuleException::refuse('targets', sprintf(
                '%s is not enrolled on this course, so they cannot receive its material.',
                (string) ($student->getAttribute('full_name') ?? 'Student #'.$id),
            ));
        }
    }

    /**
     * @param  list<array{type: string, id: int}>  $targets
     */
    private function writeTargets(CourseMaterial $material, array $targets): void
    {
        foreach ($this->validateTargets($material, $targets) as $row) {
            $target = new CourseMaterialTarget;
            $target->forceFill([
                'course_material_id' => (int) $material->getKey(),
                'target_type' => $row['type']->value,
                $row['type']->column() => $row['id'],
            ]);
            $target->save();
        }
    }

    private function moveTo(CourseMaterial $material, MaterialStatus $to, string $reason): CourseMaterial
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::refuse('reason', 'Say why this material is moving. Somebody will ask.');
        }

        return DB::transaction(function () use ($material, $to, $reason): CourseMaterial {
            $locked = $this->lock($material);
            $from = $locked->status;

            if ($from === $to) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $to->value,
                'published_at' => $to === MaterialStatus::Published
                    ? ($locked->getAttribute('published_at') ?? Carbon::now())
                    : $locked->getAttribute('published_at'),
            ]);
            $locked->save();

            $this->audit($locked, 'Material status changed', [
                'old' => ['status' => $from?->value],
                'attributes' => ['status' => $to->value],
            ], self::MODULE, $reason);

            return $locked->refresh();
        });
    }

    private function lock(CourseMaterial $material): CourseMaterial
    {
        /** @var CourseMaterial $locked */
        $locked = CourseMaterial::query()->withTrashed()->whereKey($material->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function typeFrom(array $attributes): CourseResourceType
    {
        $type = CourseResourceType::tryFrom((string) ($attributes['type'] ?? ''));

        if (! $type instanceof CourseResourceType) {
            throw CourseRuleException::refuse('type', 'Choose what kind of material this is.');
        }

        $allowed = settings_repo()->get('institute.material_allowed_types');

        if (is_array($allowed) && $allowed !== [] && ! in_array($type->value, $allowed, true)) {
            throw CourseRuleException::refuse('type', sprintf(
                '%s material is switched off for this institute.',
                $type->label(),
            ));
        }

        return $type;
    }

    /** `http` and `https` only — never `javascript:`, never `data:` (§2.3). */
    private function assertSafeUrl(mixed $url): string
    {
        $url = trim((string) $url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || parse_url($url, PHP_URL_HOST) === null) {
            throw CourseRuleException::refuse('external_url', 'A link material needs a full http:// or https:// address.');
        }

        return $url;
    }
}
