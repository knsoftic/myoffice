<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why a leave ledger row exists (phase-07 §2.14, §3, HR-7).
 *
 * The balance is a cache with no truth of its own — every figure on it is the sum of these rows — so the
 * reason is the only explanation of a number a person will one day disagree with.
 *
 * {@see requiresNote()} marks the three that are somebody's decision rather than the system's: a manual
 * adjustment, an encashment and an exit settlement all need a sentence saying why (HR-20).
 */
enum LeaveLedgerReason: string
{
    use HasOptions;

    case AnnualGrant = 'annual_grant';
    case MonthlyAccrual = 'monthly_accrual';
    case JoiningProration = 'joining_proration';
    case CarryForwardIn = 'carry_forward_in';
    case CarryForwardExpiry = 'carry_forward_expiry';
    case Reservation = 'reservation';
    case ReservationRelease = 'reservation_release';
    case LeaveConsumed = 'leave_consumed';
    case LeaveCancelled = 'leave_cancelled';
    case ManualAdjustment = 'manual_adjustment';
    case Encashment = 'encashment';
    case YearEndLapse = 'year_end_lapse';
    case ExitSettlement = 'exit_settlement';

    public function label(): string
    {
        return match ($this) {
            self::AnnualGrant => 'Annual grant',
            self::MonthlyAccrual => 'Monthly accrual',
            self::JoiningProration => 'Joining proration',
            self::CarryForwardIn => 'Carried forward',
            self::CarryForwardExpiry => 'Carry-forward expired',
            self::Reservation => 'Reserved for a request',
            self::ReservationRelease => 'Reservation released',
            self::LeaveConsumed => 'Leave taken',
            self::LeaveCancelled => 'Leave cancelled',
            self::ManualAdjustment => 'Manual adjustment',
            self::Encashment => 'Encashed',
            self::YearEndLapse => 'Lapsed at year end',
            self::ExitSettlement => 'Exit settlement',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AnnualGrant => 'emerald',
            self::MonthlyAccrual => 'sky',
            self::JoiningProration => 'cyan',
            self::CarryForwardIn => 'teal',
            self::CarryForwardExpiry => 'slate',
            self::Reservation => 'amber',
            self::ReservationRelease => 'lime',
            self::LeaveConsumed => 'violet',
            self::LeaveCancelled => 'slate',
            self::ManualAdjustment => 'orange',
            self::Encashment => 'indigo',
            self::YearEndLapse => 'slate',
            self::ExitSettlement => 'rose',
        };
    }

    /**
     * Does this reason need a written note? The three that are a human's decision (HR-20).
     */
    public function requiresNote(): bool
    {
        return in_array($this, [self::ManualAdjustment, self::Encashment, self::ExitSettlement], true);
    }
}
