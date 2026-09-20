<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What a stored employee document is (phase-07 §2.6, §3, requirement §24).
 *
 * {@see expectsExpiry()} drives the warning the documents screen shows before a document lapses — a CNIC
 * or a contract that quietly expired is the kind of thing nobody notices until it matters.
 *
 * {@see isConfidentialByDefault()} decides the default visibility on upload. A medical report or a police
 * verification is not something the whole office should be able to open, so the safe answer is the
 * default and the open one is the deliberate act.
 */
enum EmployeeDocumentType: string
{
    use HasOptions;

    case Cnic = 'cnic';
    case Passport = 'passport';
    case Contract = 'contract';
    case OfferLetter = 'offer_letter';
    case AppointmentLetter = 'appointment_letter';
    case Degree = 'degree';
    case Certificate = 'certificate';
    case ExperienceLetter = 'experience_letter';
    case Resume = 'resume';
    case PoliceVerification = 'police_verification';
    case Medical = 'medical';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cnic => 'CNIC',
            self::Passport => 'Passport',
            self::Contract => 'Contract',
            self::OfferLetter => 'Offer letter',
            self::AppointmentLetter => 'Appointment letter',
            self::Degree => 'Degree',
            self::Certificate => 'Certificate',
            self::ExperienceLetter => 'Experience letter',
            self::Resume => 'Resume',
            self::PoliceVerification => 'Police verification',
            self::Medical => 'Medical',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cnic => 'sky',
            self::Passport => 'indigo',
            self::Contract => 'violet',
            self::OfferLetter => 'cyan',
            self::AppointmentLetter => 'teal',
            self::Degree => 'emerald',
            self::Certificate => 'lime',
            self::ExperienceLetter => 'amber',
            self::Resume => 'slate',
            self::PoliceVerification => 'rose',
            self::Medical => 'pink',
            self::Other => 'slate',
        };
    }

    /**
     * Does this kind of document normally carry an expiry date the business should be warned about?
     */
    public function expectsExpiry(): bool
    {
        return in_array($this, [
            self::Cnic,
            self::Passport,
            self::Contract,
            self::Medical,
            self::PoliceVerification,
        ], true);
    }

    /**
     * Should this be confidential unless somebody deliberately opens it up?
     */
    public function isConfidentialByDefault(): bool
    {
        return in_array($this, [
            self::Cnic,
            self::Passport,
            self::Medical,
            self::PoliceVerification,
            self::Contract,
        ], true);
    }
}
