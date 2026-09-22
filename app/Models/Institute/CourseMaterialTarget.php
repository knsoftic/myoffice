<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\MaterialTargetType;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who one material is aimed at (phase-19-23 §2.4, requirement §79).
 *
 * **[D-19-2] Three real foreign keys, not a polymorphic pair.** `target_type` says which of the three is
 * set and `chk_cmt_one` makes the database agree — so "a target always resolves to something that still
 * exists" is a fact rather than a hope. A `target_id` column could point at a deleted batch, or at a
 * student id that happens to equal a course id, and nothing would notice.
 *
 * **Added and removed, never edited** (§2.4, D19): there is no `deleted_at` and no `updated_by`, and the
 * `updating` hook below refuses a change outright. Re-aiming a material is removing one row and adding
 * another, which is also what keeps `notified_at` honest — a new audience gets told, an existing one
 * does not get told twice.
 *
 * @property MaterialTargetType $target_type
 */
class CourseMaterialTarget extends Model
{
    use LogsActivityWithContext;

    protected $table = 'course_material_targets';

    /**
     * Nothing: `CourseMaterialService::setTargets()` composes every column, because a target is only
     * valid once it has been checked to resolve to the material's own course, and a mass assignment
     * cannot have done that checking.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_material_id' => 'integer',
            'target_type' => MaterialTargetType::class,
            'target_course_id' => 'integer',
            'target_batch_id' => 'integer',
            'target_student_id' => 'integer',
            'target_key' => 'integer',
            'notified_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // `notified_at` is the one thing that legitimately changes after insert, and it is stamped
        // through markNotified() rather than by an update anywhere. Everything else about a target
        // is its identity: changing it would be re-aiming the material while keeping the row that
        // recorded who was told about the old aim.
        static::updating(static function (self $target): void {
            $changed = array_keys($target->getDirty());

            if ($changed !== ['notified_at'] && $changed !== ['notified_at', 'updated_at']) {
                throw new \LogicException(sprintf(
                    'Material target #%s is not edited. Remove it and add the target you meant, so the '
                    .'audience that was already notified keeps its own row.',
                    (string) $target->getKey(),
                ));
            }
        });
    }

    public function moduleSlug(): string
    {
        return 'course_materials';
    }

    protected function activityModule(): ?string
    {
        return 'course_materials';
    }

    /** The id this row actually points at, whichever of the three columns holds it. */
    public function targetId(): ?int
    {
        $value = $this->getAttribute($this->target_type->column());

        return $value === null ? null : (int) $value;
    }

    public function hasBeenNotified(): bool
    {
        return $this->getAttribute('notified_at') !== null;
    }

    /** Rows aimed at one specific thing — the student and batch lookups both use this. */
    public function scopeAimedAt(Builder $query, MaterialTargetType $type, int $id): Builder
    {
        return $query->where('target_type', $type->value)->where($type->column(), $id);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(CourseMaterial::class, 'course_material_id');
    }

    public function targetCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'target_course_id');
    }

    public function targetBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'target_batch_id');
    }

    public function targetStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'target_student_id');
    }
}
