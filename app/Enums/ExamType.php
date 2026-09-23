<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What kind of exam this is (phase-19-23 §3.2, requirement §81).
 *
 * **§81's six, verbatim — there is no seventh.** An institute wanting a "revision test" uses a quiz and
 * names it; adding a case would make the enum a free-text field with extra steps, and every screen that
 * switches on it would gain a branch nobody wrote.
 *
 * **`isMajor()` decides what counts towards a certificate.** `institute.certificate_grade_source` reads
 * it, and `certificate_require_pass` means "every major exam of the course passed" — so a student who
 * failed one weekly quiz in March is not blocked from certification, and one who failed the final is.
 * That distinction is the whole reason this method exists rather than a `weight` on every row.
 */
enum ExamType: string
{
    use HasOptions;

    /** A short check during a class. */
    case Quiz = 'quiz';

    case WeeklyTest = 'weekly_test';

    case MonthlyTest = 'monthly_test';

    /** Major: counts towards the course grade and towards certificate eligibility. */
    case Midterm = 'midterm';

    /** Major. */
    case Final = 'final';

    /** Hands-on assessment. Not major by default — many courses have several. */
    case Practical = 'practical';

    public function label(): string
    {
        return match ($this) {
            self::Quiz => 'Quiz',
            self::WeeklyTest => 'Weekly test',
            self::MonthlyTest => 'Monthly test',
            self::Midterm => 'Midterm',
            self::Final => 'Final',
            self::Practical => 'Practical',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Quiz => 'slate',
            self::WeeklyTest => 'sky',
            self::MonthlyTest => 'violet',
            self::Midterm => 'amber',
            self::Final => 'rose',
            self::Practical => 'emerald',
        };
    }

    /**
     * Counts towards the course's aggregate grade and towards certificate eligibility.
     *
     * Deliberately narrow: a student who missed one weekly test in March should not be refused a
     * certificate for it, and one who failed the final should be.
     */
    public function isMajor(): bool
    {
        return $this === self::Midterm || $this === self::Final;
    }

    /**
     * The duration offered when the form is first opened. A prefill, never a rule — the column is
     * nullable and a two-hour practical is as legitimate as a ten-minute quiz.
     */
    public function defaultDurationMinutes(): int
    {
        return match ($this) {
            self::Quiz => 15,
            self::WeeklyTest => 30,
            self::MonthlyTest => 60,
            self::Midterm => 90,
            self::Final => 180,
            self::Practical => 120,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Quiz => 'A short check during a class.',
            self::WeeklyTest => 'The week’s work, tested.',
            self::MonthlyTest => 'A month’s ground covered.',
            self::Midterm => 'Counts towards the course grade and the certificate.',
            self::Final => 'Counts towards the course grade and the certificate.',
            self::Practical => 'Assessed by doing rather than by writing.',
        };
    }
}
