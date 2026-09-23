<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\CertificateStatus;
use App\Enums\PrintTemplateType;
use App\Models\Institute\Certificate;
use App\Models\Institute\PrintTemplate;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Finance\DocumentNumberService;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Drafting, issuing, revoking and reissuing a certificate
 * (phase-19-23 §6.14, INV-21-1, INV-21-4, requirement §84).
 *
 * **Issuing is the moment everything is frozen**, and almost all of this class is about getting that
 * moment right. A number is taken once and never reused. A verification code is generated from the
 * CSPRNG. The QR payload is built **now** and stored, so changing the verification host next year
 * cannot redirect a code already on paper. Every name, code, date and grade is copied onto the row,
 * so renaming a student, a course or a trainer afterwards leaves the document saying what it said
 * when it was handed over (INV-21-4).
 *
 * **A draft is where all the negotiating happens.** It is editable, it has no number, and nobody has
 * been given it. `issue()` re-runs eligibility rather than trusting the report the draft was created
 * with — a fee that was cleared in March may be outstanding again in June, and the certificate that
 * matters is the one being handed over today.
 *
 * **An override is recorded, never silent.** Somebody holding `certificates.approve` may issue against
 * a failing report, and the report is snapshotted **with its failures intact** plus a mandatory
 * reason. An override that rewrote the report to say "eligible" would be worse than no record at all.
 *
 * **Nothing is ever deleted** (INV-21-1). A wrong certificate is revoked with a reason that the public
 * verification page then shows, and a replacement is a *new* draft linked by `reissue_of_id` —
 * [D-21-3], because one row is never simultaneously the revoked one and the new one.
 */
final class CertificateService
{
    use WritesAuditTrail;

    private const MODULE = 'certificates';

    public function __construct(
        private readonly CertificateEligibilityService $eligibility,
        private readonly ExamStatisticsService $exams,
        private readonly QrCodeService $qr,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * Prepare a certificate. It gets every snapshot it will ever have — but **no number**, because a
     * number is the thing that makes a document exist and a draft is not one yet.
     *
     * @param  array<string, mixed>  $data
     */
    public function draft(StudentBatchEnrollment $enrollment, array $data = [], ?User $actor = null): Certificate
    {
        $actor ??= Auth::user();
        $report = $this->eligibility->check($enrollment);

        return DB::transaction(function () use ($enrollment, $data, $report): Certificate {
            $certificate = new Certificate;
            $certificate->forceFill(array_merge(
                $this->snapshotsFor($enrollment, $data),
                [
                    'status' => CertificateStatus::Draft->value,
                    'eligibility_snapshot' => $report->toArray(),
                    // Generated now so the draft screen can show it, and **stored** — the QR payload
                    // built at issue time has to match the code the row already carries.
                    'verification_code' => $this->freshCode(),
                    'notes' => $this->cleanText($data['notes'] ?? null),
                ],
            ));
            $certificate->save();

            $this->audit($certificate, 'Certificate drafted', [
                'attributes' => ['eligible' => $report->eligible()],
            ], self::MODULE);

            return $certificate->refresh();
        });
    }

    /**
     * Hand it over. One transaction, and the point of no return.
     *
     * Eligibility is re-run here rather than trusted from the draft — see the class note. An
     * `$overrideReason` is required when the re-run fails and permitted only to somebody the caller
     * has already checked holds `certificates.approve`; this service records the decision, the policy
     * decides who may make it.
     */
    public function issue(Certificate $certificate, ?User $actor = null, ?string $overrideReason = null): Certificate
    {
        $actor ??= Auth::user();

        if ($certificate->status !== CertificateStatus::Draft) {
            throw CourseRuleException::refuse('status', sprintf(
                'This certificate is already %s. Only a draft can be issued.',
                mb_strtolower($certificate->status->label()),
            ));
        }

        $enrollment = $certificate->enrollment;

        if ($enrollment === null) {
            throw CourseRuleException::refuse(
                'student_batch_enrollment_id',
                'This certificate is not attached to an enrolment, so its eligibility cannot be confirmed.',
            );
        }

        $report = $this->eligibility->check($enrollment);
        $overrideReason = trim((string) $overrideReason);

        if (! $report->eligible()) {
            if ($overrideReason === '') {
                throw CourseRuleException::refuse('eligibility', $report->summary());
            }

            $report = $report->overriddenBy((int) $actor?->getKey(), $overrideReason);
        }

        $stored = $report;

        return $this->numbers->assign(
            'institute.certificate_prefix',
            'institute.certificate_next_number',
            '%05d',
            function (string $number) use ($certificate, $stored, $actor): Certificate {
                $locked = Certificate::query()->whereKey($certificate->getKey())->lockForUpdate()->firstOrFail();

                $code = (string) $locked->getAttribute('verification_code');

                $locked->forceFill([
                    'certificate_number' => $number,
                    // Built from the code the row already carries, and **stored** (INV-21-4).
                    'qr_payload' => $this->qr->verificationUrl($code),
                    'issued_on' => Carbon::now()->toDateString(),
                    'issued_by' => $actor?->getKey(),
                    'status' => CertificateStatus::Issued->value,
                    'eligibility_snapshot' => $stored->toArray(),
                ])->save();

                $this->audit($locked, 'Certificate issued', [
                    'attributes' => [
                        'certificate_number' => $number,
                        'overridden' => $stored->wasOverridden(),
                    ],
                ], self::MODULE, $stored->overrideReason);

                return $locked->refresh();
            },
            'uq_ce_number',
        );
    }

    /**
     * Withdraw it. The reason is **published on the verification page**, so it is written for the
     * person holding the certificate rather than for the office.
     */
    public function revoke(Certificate $certificate, string $reason, ?User $actor = null): Certificate
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::reasonRequired(
                'reason',
                'Say why this certificate is being revoked. Whoever scans it will read this.',
            );
        }

        if (! $certificate->isIssued()) {
            throw CourseRuleException::refuse('status', 'Only an issued certificate can be revoked.');
        }

        $actor ??= Auth::user();

        return DB::transaction(function () use ($certificate, $reason, $actor): Certificate {
            $locked = Certificate::query()->whereKey($certificate->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'status' => CertificateStatus::Revoked->value,
                'revoked_at' => Carbon::now(),
                'revoked_by' => $actor?->getKey(),
                'revocation_reason' => mb_substr($reason, 0, 500),
            ])->save();

            $this->audit($locked, 'Certificate revoked', [
                'old' => ['status' => CertificateStatus::Issued->value],
                'attributes' => ['status' => CertificateStatus::Revoked->value],
            ], self::MODULE, $reason);

            return $locked->refresh();
        });
    }

    /**
     * A fresh draft to replace a revoked one.
     *
     * **The original must already be revoked.** Creating a successor for a live certificate would
     * leave two standing, which `uq_ce_live` would refuse at the moment of issue anyway — refusing it
     * here means the refusal has a sentence attached rather than being a 1062.
     *
     * The new row gets **new snapshots**, not copies: a reissue usually exists because something on
     * the original was wrong, and carrying its values forward would carry the mistake with them.
     */
    public function reissue(Certificate $certificate, string $reason, ?User $actor = null): Certificate
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::reasonRequired('reason', 'Say why a replacement is being issued.');
        }

        if (! $certificate->isRevoked()) {
            throw CourseRuleException::refuse(
                'status',
                'Revoke the original first. A replacement exists because the one before it was withdrawn.',
            );
        }

        if ($certificate->wasReissued()) {
            throw CourseRuleException::refuse(
                'reissue_of_id',
                'This certificate has already been replaced. One successor, and it exists.',
            );
        }

        $enrollment = $certificate->enrollment;

        if ($enrollment === null) {
            throw CourseRuleException::refuse(
                'student_batch_enrollment_id',
                'The original is not attached to an enrolment, so a replacement cannot be prepared from it.',
            );
        }

        $actor ??= Auth::user();

        return DB::transaction(function () use ($certificate, $enrollment, $reason, $actor): Certificate {
            $replacement = $this->draft($enrollment, [
                'print_template_id' => $certificate->getAttribute('print_template_id'),
            ], $actor);

            $replacement->forceFill([
                'reissue_of_id' => $certificate->getKey(),
                'reissue_reason' => mb_substr($reason, 0, 255),
            ])->save();

            $this->audit($replacement, 'Certificate reissued', [
                'attributes' => ['reissue_of_id' => $certificate->getKey()],
            ], self::MODULE, $reason);

            return $replacement->refresh();
        });
    }

    /**
     * Count a print. The printed sheet says "Reprint #n" from `print_count` when it is above one,
     * which is what stops two copies of one certificate circulating as though both were the original.
     */
    public function markPrinted(Certificate $certificate, ?User $actor = null): Certificate
    {
        $actor ??= Auth::user();

        return DB::transaction(function () use ($certificate, $actor): Certificate {
            $locked = Certificate::query()->whereKey($certificate->getKey())->lockForUpdate()->firstOrFail();

            $count = (int) $locked->getAttribute('print_count') + 1;

            $locked->forceFill([
                'print_count' => $count,
                'last_printed_at' => Carbon::now(),
                'last_printed_by' => $actor?->getKey(),
            ])->save();

            $this->audit($locked, 'Certificate printed', [
                'attributes' => ['print_count' => $count],
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    // ===========================================================================================

    /**
     * Every column a certificate carries about the world at this moment (INV-21-4).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function snapshotsFor(StudentBatchEnrollment $enrollment, array $data): array
    {
        $student = $enrollment->student;
        $batch = $enrollment->batch;
        $course = $batch?->course;
        $teacher = $batch?->teacher;

        if ($student === null || $course === null) {
            throw CourseRuleException::refuse(
                'student_batch_enrollment_id',
                'This enrolment has no student or no course behind it, so there is nothing to certify.',
            );
        }

        $mode = (string) setting('institute.certificate_grade_source', 'weighted_average');
        $aggregate = $this->exams->aggregateFor($enrollment, $mode);

        $progress = $enrollment->getAttribute('attendance_percentage');

        return [
            'branch_id' => $student->getAttribute('branch_id'),
            'student_id' => $student->getKey(),
            'course_id' => $course->getKey(),
            'batch_id' => $batch?->getKey(),
            'student_batch_enrollment_id' => $enrollment->getKey(),
            'teacher_id' => $teacher?->getKey(),
            'print_template_id' => $data['print_template_id'] ?? $this->defaultTemplateId(),
            'grade_scale_id' => $data['grade_scale_id'] ?? null,

            'student_name_snapshot' => $student->getAttribute('name'),
            'father_name_snapshot' => $student->getAttribute('father_name'),
            'student_code_snapshot' => $student->getAttribute('student_code'),
            'registration_number_snapshot' => $student->getAttribute('registration_number'),
            'course_name_snapshot' => $course->getAttribute('name'),
            'batch_name_snapshot' => $batch?->getAttribute('name') ?? $batch?->getAttribute('code'),
            'teacher_name_snapshot' => $teacher?->getAttribute('name'),
            'branch_name_snapshot' => $student->branch?->getAttribute('name'),

            'course_start_date' => $batch?->getAttribute('start_date'),
            'completion_date' => $data['completion_date'] ?? Carbon::now()->toDateString(),

            // `manual` means somebody types it; every other mode takes the computed aggregate.
            'grade' => $mode === 'manual' ? ($data['grade'] ?? null) : $aggregate->grade,
            'grade_point' => $mode === 'manual' ? ($data['grade_point'] ?? null) : $aggregate->gradePoint,
            'percentage' => $mode === 'manual' ? ($data['percentage'] ?? null) : $aggregate->percentage,

            'attendance_percentage' => $progress === null ? null : Money::round((string) $progress, 4),
            'progress_percentage' => $data['progress_percentage'] ?? null,
        ];
    }

    /**
     * A verification code nothing else holds.
     *
     * The loop is belt-and-braces over a 32^16 space: `uq_ce_code` is the real guarantee and would
     * refuse a collision anyway. Ten attempts then gives up rather than spinning, because a loop that
     * cannot fail is a loop that hangs when the assumption behind it breaks.
     */
    private function freshCode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $this->qr->newCode();

            $taken = Certificate::query()->withTrashed()->where('verification_code', $code)->exists();

            if (! $taken) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate an unused verification code after ten attempts.');
    }

    /** The institute's default certificate template, when it has one. */
    private function defaultTemplateId(): ?int
    {
        $template = PrintTemplate::query()
            ->where('type', PrintTemplateType::Certificate->value)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first(['id']);

        return $template === null ? null : (int) $template->getKey();
    }

    private function cleanText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
