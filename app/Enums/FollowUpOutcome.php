<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What came of one contact attempt (`course_inquiry_follow_ups.outcome`, §68).
 *
 * **`suggestsStatus()` is why this is an enum and not a note.** A counsellor who logs "interested"
 * and leaves the enquiry on `contacted` produces a queue that disagrees with its own history, and
 * the next person works from whichever half they happen to read. The outcome drives the status
 * through `CourseInquiryService::logFollowUp()`, so the two cannot diverge — and the four outcomes
 * that suggest nothing (`no_answer`, `busy`, `wrong_number`, `call_later`) say so explicitly rather
 * than by omission.
 */
enum FollowUpOutcome: string
{
    use HasOptions;

    case Reached = 'reached';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case WrongNumber = 'wrong_number';
    case Interested = 'interested';
    case NotInterested = 'not_interested';
    case DemoRequested = 'demo_requested';
    case AdmissionRequested = 'admission_requested';
    case CallLater = 'call_later';

    public function label(): string
    {
        return match ($this) {
            self::Reached => 'Reached',
            self::NoAnswer => 'No answer',
            self::Busy => 'Busy',
            self::WrongNumber => 'Wrong number',
            self::Interested => 'Interested',
            self::NotInterested => 'Not interested',
            self::DemoRequested => 'Asked for a demo',
            self::AdmissionRequested => 'Ready to admit',
            self::CallLater => 'Call later',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Reached => 'sky',
            self::NoAnswer, self::Busy, self::CallLater => 'slate',
            self::WrongNumber => 'rose',
            self::Interested => 'violet',
            self::NotInterested => 'slate',
            self::DemoRequested => 'amber',
            self::AdmissionRequested => 'emerald',
        };
    }

    /**
     * The status this outcome implies, or null when it implies nothing.
     *
     * `demo_requested` deliberately does NOT return `demo_scheduled`: wanting a demo is not having
     * one booked, and the status that says a demo exists is set by the booking, which is the row
     * somebody can actually turn up to.
     */
    public function suggestsStatus(): ?CourseInquiryStatus
    {
        return match ($this) {
            self::Interested, self::DemoRequested => CourseInquiryStatus::Interested,
            self::NotInterested, self::WrongNumber => CourseInquiryStatus::NotInterested,
            self::AdmissionRequested => CourseInquiryStatus::Interested,
            self::Reached, self::NoAnswer, self::Busy, self::CallLater => null,
        };
    }

    /** Did anybody actually speak to them? The §88 "reach rate" counts these. */
    public function reachedSomebody(): bool
    {
        return match ($this) {
            self::NoAnswer, self::Busy, self::WrongNumber => false,
            default => true,
        };
    }
}
