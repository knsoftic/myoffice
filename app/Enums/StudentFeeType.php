<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What a fee charge is for (`student_fees.fee_type`, finance spine §3, phase-10-12 §3).
 *
 * **`isCommissionableByDefault()` is the default, not the rule.** Two settings overrule it —
 * `collaborator.commission_on_admission_fee` and `.commission_on_registration_fee` — because a business
 * that treats admission as a real sale should be able to pay on it, and one that treats it as a
 * paperwork charge should not. What is *never* commissionable is a certificate or an exam fee: those
 * are services the institute performs, not business somebody brought in.
 */
enum StudentFeeType: string
{
    use HasOptions;

    case CourseFee = 'course_fee';
    case AdmissionFee = 'admission_fee';
    case RegistrationFee = 'registration_fee';
    case MonthlyFee = 'monthly_fee';
    case Installment = 'installment';
    case ExamFee = 'exam_fee';
    case CertificateFee = 'certificate_fee';
    case ExtraFee = 'extra_fee';
    case Tax = 'tax';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CourseFee => 'Course fee',
            self::AdmissionFee => 'Admission fee',
            self::RegistrationFee => 'Registration fee',
            self::MonthlyFee => 'Monthly fee',
            self::Installment => 'Installment',
            self::ExamFee => 'Exam fee',
            self::CertificateFee => 'Certificate fee',
            self::ExtraFee => 'Extra fee',
            self::Tax => 'Tax',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CourseFee, self::MonthlyFee, self::Installment => 'sky',
            self::AdmissionFee, self::RegistrationFee => 'violet',
            self::ExamFee, self::CertificateFee => 'slate',
            self::ExtraFee => 'amber',
            self::Tax => 'rose',
            self::Other => 'slate',
        };
    }

    /**
     * Does commission follow this kind of charge unless a setting says otherwise?
     *
     * Admission and registration answer **false** here and are turned on by their own settings; exam and
     * certificate fees answer false and have no setting at all, because there is no reading under which
     * a partner earned them.
     */
    public function isCommissionableByDefault(): bool
    {
        return match ($this) {
            self::CourseFee, self::MonthlyFee, self::Installment, self::Other => true,
            self::AdmissionFee, self::RegistrationFee, self::ExamFee, self::CertificateFee => false,
            // Neither is the institute's earning to share. The extra fee covers a cost -- a kit, a
            // lab, an exam body's charge -- and the tax is the government's. `Other` answers true
            // here, which is exactly why neither of these is `Other`.
            self::ExtraFee, self::Tax => false,
        };
    }

    /**
     * The settings key that can turn this type on, or null when nothing can.
     */
    public function commissionSettingKey(): ?string
    {
        return match ($this) {
            self::AdmissionFee => 'collaborator.commission_on_admission_fee',
            self::RegistrationFee => 'collaborator.commission_on_registration_fee',
            // `ExtraFee` and `Tax` fall through to null deliberately: unlike the admission fee, there
            // is no setting that could turn them on, because there is no argument for turning them on.
            default => null,
        };
    }
}
