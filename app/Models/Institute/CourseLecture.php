<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\LectureType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Level 3 of the outline (`course_lectures`, §65, phase-14-17 §2.7).
 *
 * A lecture is a line in the **syllabus** — "this topic contains a two-hour lab" — not a dated class.
 * The dated occurrence is a `class_sessions` row in Phase 16, and keeping the two apart is what lets one
 * syllabus run twenty times without twenty copies of itself.
 *
 * `is_preview` is the one free sample: the lecture a visitor may watch on the landing page before
 * applying (§90). Its `duration_minutes` feeds `courses.outline_minutes`, which is how the public page
 * can say "about 36 hours" without anybody typing that number a second time.
 *
 * @property LectureType $lecture_type
 */
class CourseLecture extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'course_lectures';

    /**
     * Neither FK is fillable — `CourseOutlineService` sets both from the topic (INV-I12).
     *
     * @var list<string>
     */
    protected $fillable = [
        'title', 'description', 'lecture_type', 'duration_minutes', 'video_url', 'is_preview', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_topic_id' => 'integer',
            'course_id' => 'integer',
            'lecture_type' => LectureType::class,
            'sort_order' => 'integer',
            'duration_minutes' => 'integer',
            'is_preview' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function moduleSlug(): string
    {
        return 'course_outline';
    }

    protected function activityModule(): ?string
    {
        return 'course_outline';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['title', 'course_topic_id', 'lecture_type', 'sort_order', 'duration_minutes', 'is_preview', 'is_active'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(CourseTopic::class, 'course_topic_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
