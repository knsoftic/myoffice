<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The staff handling state of a contact inquiry (phase-04 §3, `contact_inquiries.status`).
 *
 * Independent of the routing state (`InquiryRoutingStatus`): an inquiry can be `routed` to the CRM and
 * still `new` in the website queue.
 */
enum ContactInquiryStatus: string
{
    use HasOptions;

    case New = 'new';
    case Read = 'read';
    case InProgress = 'in_progress';
    case Responded = 'responded';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Read => 'Read',
            self::InProgress => 'In progress',
            self::Responded => 'Responded',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'sky',
            self::Read => 'slate',
            self::InProgress => 'amber',
            self::Responded => 'emerald',
            self::Closed => 'slate',
        };
    }

    /**
     * Still needs attention: everything except `closed`.
     */
    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }
}
