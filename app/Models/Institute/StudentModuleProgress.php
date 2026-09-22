<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\ProgressStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A module's share of one student's progress (`student_module_progress`, §83, §2.27).
 *
 * **Entirely derived, and therefore has no blameable columns and no soft delete ([D-IN-2]).** Nobody
 * ever marks a module: `CourseProgressService` computes it from the topic rows underneath, and a
 * module with no active topics takes its status from a direct mark on itself with weight 1. Recording
 * who "changed" a row that is only ever recomputed would be recording the recompute, which the course
 * row's activity log already says.
 *
 * It exists as a table rather than a query because the §8.17 board draws a roll-up bar per module for
 * every student in a batch, and recomputing that from topic rows on every render is the difference
 * between a screen that opens and one that thinks about it.
 *
 * @property ProgressStatus $status
 */
class StudentModuleProgress extends Model
{
    protected $table = 'student_module_progress';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_course_progress_id' => 'integer',
            'course_module_id' => 'integer',
            'status' => ProgressStatus::class,
            'completion_percentage' => 'decimal:4',
            'topics_total' => 'integer',
            'topics_completed' => 'integer',
            'completed_on' => 'date',
        ];
    }

    /** A module nobody put topics under is hand-markable, and then it stands for itself (§6.10). */
    public function isStandalone(): bool
    {
        return $this->topics_total === 0;
    }

    public function courseProgress(): BelongsTo
    {
        return $this->belongsTo(StudentCourseProgress::class, 'student_course_progress_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }
}
