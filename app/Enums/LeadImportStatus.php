<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The state of one CSV import batch (phase-05 §3, `lead_imports.status`).
 *
 * The wizard walks `pending` -> `mapping` -> `validating` -> `validated`; `run()` moves a validated batch to
 * `processing`, and it ends as `completed`, `completed_with_errors`, `failed` (the batch itself died) or
 * `cancelled` (already-created leads are kept, §6.6).
 */
enum LeadImportStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Mapping = 'mapping';
    case Validating = 'validating';
    case Validated = 'validated';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Mapping => 'Mapping columns',
            self::Validating => 'Validating',
            self::Validated => 'Validated',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::CompletedWithErrors => 'Completed with errors',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::Mapping => 'sky',
            self::Validating => 'cyan',
            self::Validated => 'indigo',
            self::Processing => 'amber',
            self::Completed => 'emerald',
            self::CompletedWithErrors => 'orange',
            self::Failed => 'rose',
            self::Cancelled => 'slate',
        };
    }

    /**
     * May `LeadImportService::run()` start this batch? Only after the dry run has validated it.
     */
    public function isRunnable(): bool
    {
        return $this === self::Validated;
    }

    /**
     * The batch has reached an end state; nothing further is dispatched for it.
     */
    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::CompletedWithErrors, self::Failed, self::Cancelled], true);
    }

    /**
     * May the batch still be cancelled? Anything not yet finished.
     */
    public function isCancellable(): bool
    {
        return ! $this->isFinished();
    }
}
