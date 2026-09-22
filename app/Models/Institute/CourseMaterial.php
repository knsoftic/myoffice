<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\DataObjects\Files\StoredFile;
use App\Enums\CourseResourceType;
use App\Enums\MaterialStatus;
use App\Enums\MaterialTargetType;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A material a teacher handed out (phase-19-23 §2.3, requirement §79).
 *
 * **[D-19-1] Not the syllabus.** `CourseTopicResource` is what the course *teaches* — authored once,
 * public on the landing page. This is an *act of distribution*: targeted, time-windowed, enrollment-gated
 * and download-tracked. A material may point at a topic for organisation, but it never replaces the
 * syllabus row.
 *
 * **`audience_scope` is a badge, never an authorisation input.** Who may read a material is decided by
 * resolving its target rows against live enrollment (INV-19-3), every time, in `MaterialAccessService`.
 * This column caches the broadest live target so the index can show "whole course" or "one batch"
 * without a join, and a screen that used it to decide access would be wrong the moment a target changed.
 *
 * **`is_downloadable = false` is deterrence, not DRM**, and §6.5 says so outright. It serves the file
 * inline instead of as an attachment; anyone determined enough still has the bytes. It exists because
 * asking politely stops most casual redistribution, not because it stops any.
 *
 * @property MaterialStatus $status
 * @property CourseResourceType $type
 * @property MaterialTargetType $audience_scope
 */
class CourseMaterial extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'course_materials';

    /**
     * The file columns are absent on purpose: every one of them is decided by `SecureFileService` from
     * the bytes, and a request that could set `mime_type` could claim a `.php` was a PDF.
     *
     * @var list<string>
     */
    protected $fillable = [
        'course_topic_id',
        'teacher_id',
        'title',
        'description',
        'type',
        'external_url',
        'is_downloadable',
        'available_from',
        'available_until',
        'sort_order',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'course_id' => 'integer',
            'course_topic_id' => 'integer',
            'teacher_id' => 'integer',
            'type' => CourseResourceType::class,
            'file_size_bytes' => 'integer',
            'is_downloadable' => 'boolean',
            'available_from' => 'immutable_datetime',
            'available_until' => 'immutable_datetime',
            'status' => MaterialStatus::class,
            'published_at' => 'immutable_datetime',
            'audience_scope' => MaterialTargetType::class,
            'targets_count' => 'integer',
            'view_count' => 'integer',
            'download_count' => 'integer',
            'unique_students_count' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'course_materials';
    }

    protected function activityModule(): ?string
    {
        return 'course_materials';
    }

    /** The file half of `chk_cm_payload`, as the object `SecureFileService` speaks. */
    public function storedFile(): StoredFile
    {
        return StoredFile::fromColumns($this->attributesToArray());
    }

    public function isFile(): bool
    {
        return $this->getAttribute('file_path') !== null;
    }

    public function isLink(): bool
    {
        return $this->getAttribute('external_url') !== null;
    }

    /**
     * Inside its release window at the given moment. Window and status are separate questions — a
     * published material outside its window is correct and simply not yet (or no longer) available.
     */
    public function isWithinWindow(Carbon $at): bool
    {
        $from = $this->getAttribute('available_from');
        $until = $this->getAttribute('available_until');

        return ($from === null || $from->lessThanOrEqualTo($at))
            && ($until === null || $until->greaterThan($at));
    }

    /** Live for a student right now: published, not trashed, and inside its window. */
    public function isAvailable(Carbon $at): bool
    {
        return $this->status->isVisibleToStudents() && ! $this->trashed() && $this->isWithinWindow($at);
    }

    /**
     * The student-facing window filter, written once so the student list, the counts and the download
     * check cannot drift apart.
     */
    public function scopeAvailableAt(Builder $query, Carbon $at): Builder
    {
        return $query
            ->where('status', MaterialStatus::Published->value)
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('available_from')
                ->orWhere('available_from', '<=', $at))
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('available_until')
                ->orWhere('available_until', '>', $at));
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(CourseTopic::class, 'course_topic_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(CourseMaterialTarget::class, 'course_material_id');
    }

    public function accessLog(): HasMany
    {
        return $this->hasMany(CourseMaterialDownload::class, 'course_material_id')->orderByDesc('created_at');
    }
}
