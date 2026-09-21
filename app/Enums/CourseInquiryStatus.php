<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a course enquiry stands (`course_inquiries.status`, requirement §86, phase-14-17 §2.30.2).
 *
 * **§86's six, verbatim, and no seventh.** The funnel is the thing every conversion report counts, so
 * a status invented for one screen would quietly change what "converted" means. `demo_scheduled` is a
 * status rather than a flag because a booked demo is where the enquiry *is*, not a property of it: a
 * counsellor's queue shows "three waiting on a demo", which a boolean beside `contacted` could not say.
 *
 * `not_interested` is the one that is reachable from anywhere and always carries a reason — lost
 * enquiries are the ones worth reading later — and it is reversible, because somebody who said no in
 * March enrols in September more often than any other case in this table.
 */
enum CourseInquiryStatus: string
{
    use HasOptions;

    case New = 'new';
    case Contacted = 'contacted';
    case Interested = 'interested';
    case DemoScheduled = 'demo_scheduled';
    case AdmissionConfirmed = 'admission_confirmed';
    case NotInterested = 'not_interested';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Interested => 'Interested',
            self::DemoScheduled => 'Demo scheduled',
            self::AdmissionConfirmed => 'Admission confirmed',
            self::NotInterested => 'Not interested',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'sky',
            self::Contacted => 'indigo',
            self::Interested => 'violet',
            self::DemoScheduled => 'amber',
            self::AdmissionConfirmed => 'emerald',
            self::NotInterested => 'slate',
        };
    }

    /**
     * Still worth a follow-up. The work queue, the stale-enquiry flag and the counsellor's counts all
     * ask this one question, so none of them can disagree about what "open" means.
     */
    public function isOpen(): bool
    {
        return ! $this->isWon() && ! $this->isLost();
    }

    public function isWon(): bool
    {
        return $this === self::AdmissionConfirmed;
    }

    public function isLost(): bool
    {
        return $this === self::NotInterested;
    }
}
