<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Files\FileRules;
use App\DataObjects\Files\FileTarget;
use App\DataObjects\Institute\TargetResult;
use App\Enums\CourseResourceType;
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
        return DB::transaction(function () use ($material): CourseMaterial {
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
