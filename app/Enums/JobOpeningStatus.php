<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The lifecycle of a job opening (phase-04 §3, `job_openings.status`).
 *
 * Only `open` accepts applications and only `open` lists publicly — a `filled` role is history, not an
 * advert. A passed `deadline` closes applications even while the status still reads `open` (§6.8,
 * `JobOpening::scopePublic()`), and `careers:close-expired` flips such rows to `closed` daily.
 */
enum JobOpeningStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case Filled = 'filled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Filled => 'Filled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Open => 'emerald',
            self::Closed => 'rose',
            self::Filled => 'indigo',
        };
    }

    /**
     * True only for `open` (the deadline is checked separately, by the model and the service).
     */
    public function acceptsApplications(): bool
    {
        return $this === self::Open;
    }

    /**
     * True only for `open`: neither `closed` nor `filled` is listed on the public careers page.
     */
    public function isPublic(): bool
    {
        return $this === self::Open;
    }

    /**
     * Moving into this status stamps `job_openings.closed_at` (§2.18).
     */
    public function isClosed(): bool
    {
        return in_array($this, [self::Closed, self::Filled], true);
    }
}
