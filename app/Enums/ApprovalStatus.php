<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The moderation state of a visitor- or staff-submitted review (phase-04 §3):
 * `testimonials.status` and `student_reviews.status`.
 *
 * Only `approved` is ever public (§9.2) — enforced centrally by each model's `scopePublic()`, never by a
 * Blade file. The allowed moves (Pending → Approved/Rejected, Approved ⇄ Rejected, any → Pending via
 * reset) are `ModerationService`'s rule (§6.5), not this enum's.
 */
enum ApprovalStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'emerald',
            self::Rejected => 'rose',
        };
    }

    /**
     * May an anonymous visitor see a record in this state? True for `approved` only.
     */
    public function isPublic(): bool
    {
        return $this === self::Approved;
    }
}
