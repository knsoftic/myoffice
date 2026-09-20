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
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CourseFee, self::MonthlyFee, self::Installment => 'sky',
            self::AdmissionFee, self::RegistrationFee => 'violet',
            self::ExamFee, self::CertificateFee => 'slate',
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
            default => null,
        };
    }
}
