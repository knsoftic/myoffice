<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Support\AudienceInput;
use App\Enums\CertificateStatus;
use App\Enums\PrintTemplateType;
use App\Models\Institute\Certificate;
use App\Models\Institute\PrintTemplate;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Finance\DocumentNumberService;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Services\Support\NotificationService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
        private readonly PrintTemplateService $templates,
        private readonly DocumentPdfRenderer $renderer,
        private readonly NotificationService $notifications,
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
     * Edit a draft (§7.5 — `PUT /admin/certificates/{certificate}`, drafts only).
     *
     * **Only a draft, and only the fields somebody chose.** Everything else on a certificate is a
     * snapshot taken from the record at draft time; re-deriving it here would quietly move a date or
     * a grade the office had already settled. The five fields below are exactly the five the draft
     * form offers, and an absent key means unchanged — D121's rule, applied at its third call site.
     *
     * A `print_template_id` set to null is a real instruction: it means "print on whatever the
     * institute's default is", and a certificate issued before any template existed has to be able
     * to say that.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(Certificate $certificate, array $data, ?User $actor = null): Certificate
    {
        $actor ??= Auth::user();

        if ($certificate->status !== CertificateStatus::Draft) {
            throw CourseRuleException::refuse('status', sprintf(
                'This certificate is %s. Only a draft can be edited — an issued one is corrected by '
                .'revoking it and issuing a replacement.',
                mb_strtolower($certificate->status->label()),
            ));
        }

        $writable = ['print_template_id', 'grade_scale_id', 'completion_date', 'grade', 'grade_point', 'percentage', 'notes'];

        $changes = [];

        foreach ($writable as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }

        if ($changes === []) {
            return $certificate;
        }

        return DB::transaction(function () use ($certificate, $changes): Certificate {
            $before = $certificate->only(array_keys($changes));

            $certificate->forceFill($changes)->save();

            $this->audit($certificate, 'Certificate draft edited', [
                'old' => $before,
                'attributes' => $changes,
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

        // **`no_live_certificate` is not overridable, and every other rule is.**
        //
        // The difference is what kind of thing the rule is. Attendance, fees, progress and exam
        // results are *judgements* — an institute may decide a student has earned a certificate
        // despite one of them, and recording that decision with a reason is exactly what an override
        // is for. "This enrolment already has an issued certificate" is not a judgement: it is what
        // `uq_ce_live` enforces, so overriding it does not produce a second certificate, it produces
        // a 1062 from the database with no explanation attached.
        //
        // Refusing it here means the person gets the sentence instead of the integrity error.
        foreach ($report->failures() as $failure) {
            if ($failure->key === 'no_live_certificate') {
                throw CourseRuleException::refuse('eligibility', $failure->message);
            }
        }

        if (! $report->eligible()) {
            if ($overrideReason === '') {
                throw CourseRuleException::refuse('eligibility', $report->summary());
            }

            $report = $report->overriddenBy((int) $actor?->getKey(), $overrideReason);
        }

        $stored = $report;

        $issued = $this->numbers->assign(
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

        // **After the numbering transaction, not inside it** (INV-22-8, and the nested-transaction
        // trap `FeeReminderService` fell into): a notification queued inside a nested transaction
        // loses its after-commit callback when the savepoint commits, so the student would never
        // have been told and nothing would have said so.
        //
        // A certificate with no student login reaches nobody, which `NotificationService` counts
        // and moves past — a student who has never been given an account is reached in person.
        $this->announce($issued, 'certificate.generated', [
            'title' => 'Your certificate is ready',
            'body' => sprintf(
                '%s — verify it at any time with the code on the certificate.',
                $issued->getAttribute('certificate_number'),
            ),
            'certificate_id' => (int) $issued->getKey(),
            'certificate_number' => $issued->getAttribute('certificate_number'),
        ], $actor);

        return $issued;
    }

    /**
     * Tell the student a certificate of theirs has changed hands.
     *
     * One method for both events because the audience question is identical — the student the
     * certificate belongs to — and only the wording differs. Holders of `certificates.view_any` are
     * added for a revocation, because §10.3 says so and because a revoked certificate is something
     * the office needs to know has happened, not only the person holding it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function announce(Certificate $certificate, string $eventKey, array $payload, ?User $actor): void
    {
        $studentUser = $certificate->student?->user;

        $audience = $eventKey === 'certificate.revoked'
            ? AudienceInput::permission('certificates.view_any')
            : AudienceInput::none();

        if ($studentUser !== null) {
            $audience = $audience->plus($studentUser);
        }

        if ($audience->isEmpty()) {
            return;
        }

        $this->notifications->dispatch($eventKey, $audience, $payload + [
            'url' => '/student/certificates',
        ], $actor);
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

        $revoked = DB::transaction(function () use ($certificate, $reason, $actor): Certificate {
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

        $this->announce($revoked, 'certificate.revoked', [
            'title' => 'A certificate has been revoked',
            'body' => $reason,
            'certificate_id' => (int) $revoked->getKey(),
            'certificate_number' => $revoked->getAttribute('certificate_number'),
        ], $actor);

        return $revoked;
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
        $this->assertPrintable($certificate);

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

    /**
     * Render this certificate to PDF bytes, from its **stored snapshots and its stored template**.
     *
     * **A template edit therefore changes the layout and never the content.** Every value comes off
     * the certificate row — the name it was issued under, the course it named, the grade it carried,
     * the QR payload it was printed with — so a reprint a year later says exactly what the original
     * said, however the template has moved on since (INV-21-4). The activity row for a template edit
     * says so explicitly, because "this reprint looks different" is a question somebody will ask.
     */
    public function renderPdf(Certificate $certificate): string
    {
        $this->assertPrintable($certificate);

        $template = $this->templateFor($certificate);

        return $this->renderer->render(
            $template,
            $this->templates->render($template, $this->tokensFor($certificate)),
            (string) ($certificate->getAttribute('certificate_number') ?? 'Certificate'),
        );
    }

    /**
     * The same document as HTML, for the browser's print dialog.
     *
     * **Same template, same tokens, same snapshots as `renderPdf()`** — the two share every step but
     * the last, so what somebody sees on screen and what lands in the PDF cannot disagree. A screen
     * that rendered from a different path would eventually show a grade the PDF did not.
     */
    public function renderHtml(Certificate $certificate): string
    {
        $this->assertPrintable($certificate);

        $template = $this->templateFor($certificate);

        return $this->templates->render($template, $this->tokensFor($certificate));
    }

    /**
     * The verification code, grouped for reading aloud.
     *
     * A screen and a printed certificate must show the same grouping, so both go through
     * `QrCodeService::forDisplay()` — this exists only so a Blade file never has to reach into the
     * container for a service to render one field.
     */
    public function displayCodeFor(Certificate $certificate): string
    {
        return $this->qr->forDisplay((string) $certificate->getAttribute('verification_code'));
    }

    /**
     * Refuse to render anything a draft.
     *
     * **`CertificatePolicy::print()` already says this and it is not enough — D124.** `Gate::before`
     * allows a Super Admin everything *before* a policy runs, so the policy protects a draft from
     * everybody except the one role most able to hand one out. A draft has no number and no
     * `qr_payload`: printed, it is an unverifiable document on institute letterhead, and its
     * "Reprint #2" line would be counting copies of something that was never issued.
     *
     * So the refusal lives here, below the gate, where every route to a printed page passes through.
     */
    private function assertPrintable(Certificate $certificate): void
    {
        if ($certificate->status->isPublic()) {
            return;
        }

        throw CourseRuleException::refuse('status', sprintf(
            'This certificate is %s, so there is nothing to print: it has no number and its QR code '
            .'resolves to nothing. Issue it first.',
            mb_strtolower($certificate->status->label()),
        ));
    }

    /**
     * The template this certificate prints with: its own, or the institute's default.
     *
     * A certificate issued before any template existed still has to be printable, which is why the
     * fallback is here rather than the column being required. With neither, the refusal names what
     * to do rather than throwing on a null.
     */
    public function templateFor(Certificate $certificate): PrintTemplate
    {
        $template = $certificate->template ?? $this->defaultTemplate();

        if ($template === null) {
            throw CourseRuleException::refuse(
                'print_template_id',
                'There is no certificate template to print with. Create one, or mark an existing one '
                .'as the default.',
            );
        }

        return $template;
    }

    /**
     * Generate the PDF and store it on the **private** disk, returning the path.
     *
     * Private, never public: a certificate names a student, and D21 says no private artefact is ever
     * written where a web server will serve it directly. It is read back through a controller that
     * re-runs the permission chain.
     */
    public function generatePdf(Certificate $certificate): string
    {
        $bytes = $this->renderPdf($certificate);

        $path = sprintf(
            'certificates/%s.pdf',
            (string) ($certificate->getAttribute('certificate_number') ?? $certificate->getAttribute('verification_code')),
        );

        Storage::disk('private')->put($path, $bytes);

        $certificate->forceFill([
            'pdf_path' => $path,
            'pdf_generated_at' => Carbon::now(),
        ])->saveQuietly();

        return $path;
    }

    /**
     * Re-render from the stored snapshots.
     *
     * Exists as its own method rather than as "delete the file and call generatePdf" because the
     * distinction is worth logging: a regeneration is somebody saying "the file is wrong or missing",
     * which is different from the first render and different again from an amendment.
     */
    public function regeneratePdf(Certificate $certificate, ?User $actor = null): string
    {
        $path = $this->generatePdf($certificate);

        $this->audit($certificate, 'Certificate PDF regenerated', [
            'attributes' => ['pdf_path' => $path],
        ], self::MODULE);

        return $path;
    }

    /**
     * The token map a certificate prints with.
     *
     * **Every value comes off the row**, and every one goes through `Format` — so a date-format
     * change restyles every future print and alters no past one. `{qr}` is built from the row's
     * stored `qr_payload` and arrives as a `data:` URI, so dompdf never makes a network request.
     *
     * @return array<string, string>
     */
    private function tokensFor(Certificate $certificate): array
    {
        // The **resolved** template, not `$certificate->template`: a certificate that names none
        // prints on the institute's default, and its signatories are that template's business too.
        // Reading the relation alone left the three signatory lines blank on exactly those rows.
        $template = $this->templateFor($certificate);

        $signatories = (array) ($template->getAttribute('signatories') ?? []);

        $tokens = [
            'certificate_number' => (string) ($certificate->getAttribute('certificate_number') ?? ''),
            'verification_code' => $this->qr->forDisplay((string) $certificate->getAttribute('verification_code')),
            'verification_url' => (string) ($certificate->getAttribute('qr_payload') ?? ''),
            'qr' => $this->qrImageFor($certificate, $template),
            'issued_on' => app_date($certificate->getAttribute('issued_on')),

            'student_name' => (string) $certificate->getAttribute('student_name_snapshot'),
            'father_name' => (string) ($certificate->getAttribute('father_name_snapshot') ?? ''),
            'student_code' => (string) $certificate->getAttribute('student_code_snapshot'),
            'registration_number' => (string) ($certificate->getAttribute('registration_number_snapshot') ?? ''),

            'course_name' => (string) $certificate->getAttribute('course_name_snapshot'),
            'batch_name' => (string) ($certificate->getAttribute('batch_name_snapshot') ?? ''),
            'trainer_name' => (string) ($certificate->getAttribute('teacher_name_snapshot') ?? ''),
            'branch_name' => (string) ($certificate->getAttribute('branch_name_snapshot') ?? ''),
            'course_start_date' => app_date($certificate->getAttribute('course_start_date')),
            'completion_date' => app_date($certificate->getAttribute('completion_date')),

            'grade' => (string) ($certificate->getAttribute('grade') ?? ''),
            'grade_point' => $certificate->getAttribute('grade_point') === null
                ? '' : app_number($certificate->getAttribute('grade_point'), 2),
            'percentage' => $certificate->getAttribute('percentage') === null
                ? '' : app_number($certificate->getAttribute('percentage'), 2).'%',
            'attendance_percentage' => $certificate->getAttribute('attendance_percentage') === null
                ? '' : app_number($certificate->getAttribute('attendance_percentage'), 2).'%',

            'company_name' => (string) setting('company.name', config('app.name')),
            'company_logo' => '',
            'footer_note' => (string) (setting('institute.certificate_footer_note', '') ?? ''),
        ];

        // Three signatory slots, filled from the template's own list. A slot with nobody in it
        // prints empty rather than leaving `{signatory_2_name}` across the page.
        foreach ([1, 2, 3] as $slot) {
            $person = (array) ($signatories[$slot - 1] ?? []);

            $tokens['signatory_'.$slot.'_name'] = (string) ($person['name'] ?? '');
            $tokens['signatory_'.$slot.'_title'] = (string) ($person['title'] ?? '');
            $tokens['signatory_'.$slot.'_image'] = '';
        }

        return $tokens;
    }

    /**
     * The QR image, or an empty string when the template does not want one.
     *
     * Empty rather than a thrown exception: a template that omits `{qr}` is a legitimate design, and
     * a certificate whose `show_qr` is off should print without one rather than refusing.
     */
    private function qrImageFor(Certificate $certificate, PrintTemplate $template): string
    {
        if (! (bool) $template->getAttribute('show_qr')) {
            return '';
        }

        if (trim((string) $certificate->getAttribute('qr_payload')) === '') {
            return '';
        }

        $size = (float) $template->getAttribute('qr_size_mm');

        return sprintf(
            '<img src="%s" alt="" style="width:%smm;height:%smm">',
            $this->qr->forCertificate($certificate),
            number_format($size, 2, '.', ''),
            number_format($size, 2, '.', ''),
        );
    }

    private function defaultTemplate(): ?PrintTemplate
    {
        return PrintTemplate::query()
            ->ofType(PrintTemplateType::Certificate)
            ->where('is_default', true)
            ->active()
            ->first();
    }

    private function cleanText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
