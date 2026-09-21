<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What kind of session a lecture in the outline is (`course_lectures.lecture_type`, requirement §65).
 *
 * It is a property of the **syllabus**, not of a dated class: it says "this part of the course is a lab"
 * so the public outline can show a student what the shape of the course is. The dated occurrence is a
 * `class_sessions` row, and its status is a different question with a different enum.
 */
enum LectureType: string
{
    use HasOptions;

    case Lecture = 'lecture';
    case Lab = 'lab';
    case Workshop = 'workshop';
    case Revision = 'revision';
    case Assessment = 'assessment';
    case Project = 'project';

    public function label(): string
    {
        return match ($this) {
            self::Lecture => 'Lecture',
            self::Lab => 'Lab',
            self::Workshop => 'Workshop',
            self::Revision => 'Revision',
            self::Assessment => 'Assessment',
            self::Project => 'Project',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Lecture => 'slate',
            self::Lab => 'sky',
            self::Workshop => 'violet',
            self::Revision => 'amber',
            self::Assessment => 'rose',
            self::Project => 'emerald',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Lecture => 'presentation-chart-bar',
            self::Lab => 'beaker',
            self::Workshop => 'wrench-screwdriver',
            self::Revision => 'arrow-path',
            self::Assessment => 'clipboard-document-check',
            self::Project => 'rocket-launch',
        };
    }

    /**
     * Is it hands-on? Read by the public outline, which marks practical sessions so a visitor can see
     * the course is not forty hours of slides.
     */
    public function isPractical(): bool
    {
        return in_array($this, [self::Lab, self::Workshop, self::Project], true);
    }
}
