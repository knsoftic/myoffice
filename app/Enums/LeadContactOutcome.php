<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a contact attempt ended (phase-05 §3): `lead_activities.outcome` on call / WhatsApp / email rows and
 * `lead_follow_ups.outcome`, which is mandatory when a follow-up is completed.
 */
enum LeadContactOutcome: string
{
    use HasOptions;

    case Connected = 'connected';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case WrongNumber = 'wrong_number';
    case CallBackLater = 'call_back_later';
    case NotInterested = 'not_interested';
    case LeftMessage = 'left_message';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::NoAnswer => 'No answer',
            self::Busy => 'Busy',
            self::WrongNumber => 'Wrong number',
            self::CallBackLater => 'Call back later',
            self::NotInterested => 'Not interested',
            self::LeftMessage => 'Left a message',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Connected => 'emerald',
            self::NoAnswer => 'amber',
            self::Busy => 'orange',
            self::WrongNumber => 'rose',
            self::CallBackLater => 'sky',
            self::NotInterested => 'slate',
            self::LeftMessage => 'indigo',
        };
    }

    /**
     * Does this outcome stamp `leads.last_contacted_at`? Every outcome except a wrong number (§3).
     */
    public function countsAsContact(): bool
    {
        return $this !== self::WrongNumber;
    }
}
