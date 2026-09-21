<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a student stands with the institute (`students.status`, requirement §66's seven, §2.30.4).
 *
 * **`inquiry` and `applied` exist because a student record can precede a decision.** A walk-in who is
 * being counselled has a name, a phone and a course in mind, and giving them a row means the next
 * conversation starts where the last one ended; it does not mean they have been admitted, and the
 * status is what keeps those two facts apart.
 *
 * **`suspended` and `dropped` both stop a login, and only one is reversible on its own.** A
 * suspension is a decision the institute can undo; a drop is the student leaving, and coming back is
 * a new admission rather than a status flipped in a dropdown — which is why re-admission moves
 * through `AdmissionService`, not through here.
 */
enum StudentStatus: string
{
    use HasOptions;

    case Inquiry = 'inquiry';
    case Applied = 'applied';
    case Registered = 'registered';
    case Active = 'active';
    case Completed = 'completed';
    case Dropped = 'dropped';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Inquiry => 'Inquiry',
            self::Applied => 'Applied',
            self::Registered => 'Registered',
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Dropped => 'Dropped',
            self::Suspended => 'Suspended',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Inquiry => 'slate',
            self::Applied => 'sky',
            self::Registered => 'indigo',
            self::Active => 'emerald',
            self::Completed => 'violet',
            self::Dropped => 'rose',
            self::Suspended => 'amber',
        };
    }

    /**
     * May the linked `users` row sign in?
     *
     * Asked by `StudentService::changeStatus()`, which sets the user Inactive when this turns false —
     * the panel's own gate is Phase 1's `active` middleware, and this is what feeds it.
     */
    public function canLogin(): bool
    {
        return $this !== self::Dropped && $this !== self::Suspended;
    }

    /** May they be put on a batch roster? Not before registration, and not after leaving. */
    public function isEnrollable(): bool
    {
        return $this === self::Registered || $this === self::Active;
    }

    /** Counted in "active students" on the dashboard and in §99's headcount. */
    public function countsAsActive(): bool
    {
        return $this === self::Active;
    }

    /** Is this a student the institute has finished with, one way or the other? */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Dropped;
    }
}
