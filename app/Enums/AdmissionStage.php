<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * §68's admission pipeline as one column (`student_admissions.stage`, phase-14-17 §2.30.5, §2.31).
 *
 * **One column, because "where are we" must have exactly one answer.** The pipeline spans an enquiry,
 * an application, a student and an admission; if each carried its own idea of the stage, a screen
 * would have to pick one to believe. The admission is the record the money and the batch hang off, so
 * it is the one that carries the stage, and `students.status` advances in lockstep beside it.
 *
 * **Fee collection and batch assignment are ordered here but not enforced here.** Institutes seat a
 * student before the first installment clears every day, so both orders are legal; the rule lives in
 * the single transition into `active`, governed by `institute.require_fee_before_activation`. A stage
 * enum that forbade one order would be a policy hard-coded where nobody could find it.
 */
enum AdmissionStage: string
{
    use HasOptions;

    case Application = 'application';
    case Registration = 'registration';
    case FeeCollection = 'fee_collection';
    case BatchAssignment = 'batch_assignment';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Application => 'Application',
            self::Registration => 'Registration',
            self::FeeCollection => 'Fee collection',
            self::BatchAssignment => 'Batch assignment',
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Application => 'sky',
            self::Registration => 'indigo',
            self::FeeCollection => 'amber',
            self::BatchAssignment => 'violet',
            self::Active => 'emerald',
            self::Completed => 'teal',
            self::Cancelled => 'rose',
            self::Withdrawn => 'slate',
        };
    }

    /**
     * Is this admission still running? The `active_guard` generated column answers the same question
     * in SQL, and `uq_sadm_live` depends on the two agreeing.
     */
    public function isLive(): bool
    {
        return ! $this->isTerminal();
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled, self::Withdrawn => true,
            default => false,
        };
    }

    /**
     * Position in the stepper. The two ways out of the pipeline share the last rung because neither is
     * a step somebody works towards.
     */
    public function order(): int
    {
        return match ($this) {
            self::Application => 1,
            self::Registration => 2,
            self::FeeCollection => 3,
            self::BatchAssignment => 4,
            self::Active => 5,
            self::Completed => 6,
            self::Cancelled, self::Withdrawn => 7,
        };
    }
}
