<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Collaborator\ReferralContext;
use App\Enums\CourseInquiryStatus;
use App\Enums\ReferralConversionSubject;
use App\Enums\ReferralSource;
use App\Enums\StudentApplicationStatus;
use App\Models\Institute\Course;
use App\Models\Institute\CourseInquiry;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentApplication;
use App\Models\User;
use App\Services\Collaborator\ReferralAttributionResolver;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\Collaborator\ReferralDecision;
use App\Support\Collaborator\ReferralResolutionContext;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * §67's public admission form, and the one transaction that turns one into a student (§6.4).
 *
 * **[D-IN-7] The public path creates one row and nothing else.** No student, no `users` row, no fee.
 * A form submitted by a stranger is a request; a student record is something a member of staff
 * decides to create. The distance between those two facts is the whole reason this table exists.
 *
 * **[D-IN-13] The referral is captured here and attached at conversion.** `collaborator_referrals`
 * needs a subject row and the form deliberately creates none, so the application carries the
 * evidence — the code as typed, the resolver's verdict, the visit, the landing URL, the address and
 * the browser — and `convert()` turns it into the one authoritative attribution through
 * `ReferralService::attach()`. A `collaborator_id` posted by the browser is discarded before it gets
 * anywhere near a column (INV-I4): who referred somebody is decided server-side or not at all.
 *
 * **Converting twice is a no-op, not a second student.** `convert()` returns the existing admission
 * when one is already recorded. Two people clicking Convert on the same screen is not a rare event,
 * and the second click must not produce a second student with a second code.
 */
final class StudentApplicationService
{
    /** §2.30.3, verbatim. */
    private const TRANSITIONS = [
        'submitted' => ['under_review', 'converted', 'rejected', 'duplicate', 'withdrawn'],
        'under_review' => ['converted', 'rejected', 'duplicate', 'withdrawn'],
        'converted' => [],
        'rejected' => [],
        'duplicate' => [],
        'withdrawn' => [],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly StudentNumberService $numbers,
        private readonly StudentService $students,
        private readonly AdmissionService $admissions,
        private readonly CourseInquiryService $inquiries,
        private readonly ReferralAttributionResolver $referrals,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Taking one in
    |--------------------------------------------------------------------------
    */

    /**
     * The public form. Every guard it needs is here, because the controller is not the place for one.
     *
     * @param  array<string, mixed>  $data
     */
    public function submitFromPublic(array $data, ?Request $request = null): StudentApplication
    {
        $key = trim((string) ($data['idempotency_key'] ?? ''));

        if ($key === '') {
            throw CourseRuleException::refuse('idempotency_key',
                'The form did not carry its one-time key. Reload the page and submit again.');
        }

        $existing = StudentApplication::query()->where('idempotency_key', $key)->first();

        if ($existing instanceof StudentApplication) {
            return $existing;
        }

        $course = $this->assertCourseIsOpen($data['course_id'] ?? null);
        $decision = $this->resolveReferral($data, $request);

        try {
            return $this->write($data, $course, $decision, $request, null, null);
        } catch (QueryException $e) {
            // A 1062 on `uq_sap_idem` is the same submission arriving twice. The visitor should see
            // one confirmation, not an error about a race they did not cause.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $row = StudentApplication::query()->where('idempotency_key', $key)->first();

                if ($row instanceof StudentApplication) {
                    return $row;
                }
            }

            throw $e;
        }
    }

    /**
     * The staff path: a walk-in typed at the front desk, or an enquiry promoted.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForStaff(array $data, ?User $actor = null, ?CourseInquiry $inquiry = null): StudentApplication
    {
        $course = $this->assertCourseIsOpen($data['course_id'] ?? null, staffOverride: true);
        $decision = $this->resolveReferral($data, null, $actor);

        $data['idempotency_key'] = $data['idempotency_key'] ?? ('staff-'.Str::uuid()->toString());

        return $this->write($data, $course, $decision, null, $actor, $inquiry);
    }

    /**
     * §6.4: the resolver decides, this class only records.
     *
     * Phase 9 owns the precedence ladder — a staff pick outranks a typed code, which outranks a
     * captured visit — and re-implementing any part of it here would give the institute a second
     * opinion about who earned a commission.
     *
     * @param  array<string, mixed>  $data
     */
    public function resolveReferral(array $data, ?Request $request = null, ?User $actor = null): ReferralDecision
    {
        $context = new ReferralResolutionContext(
            request: $request ?? request(),
            subject: ReferralConversionSubject::Student,
            subjectId: null,
            // Only a member of staff may name a collaborator, and only when they are signed in. A
            // `collaborator_id` in a public request body is ignored entirely (INV-I4).
            staffCollaboratorId: $actor !== null ? ($data['collaborator_id'] ?? null) : null,
            staffChoseNone: $actor !== null && ($data['referral_none'] ?? false) === true,
            typedCode: $data['referral_code'] ?? null,
            actor: $actor,
            overrideReason: $data['referral_override_reason'] ?? null,
        );

        return $this->referrals->resolve($context);
    }

    /*
    |--------------------------------------------------------------------------
    | Triage
    |--------------------------------------------------------------------------
    */

    /** A reviewer opens it and puts their name on it. */
    public function claim(StudentApplication $application, User $actor): StudentApplication
    {
        if ($application->status !== StudentApplicationStatus::Submitted) {
            return $application;
        }

        return $this->db->transaction(function () use ($application, $actor): StudentApplication {
            $application->forceFill([
                'status' => StudentApplicationStatus::UnderReview->value,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => Carbon::now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            return $application->refresh();
        }, 3);
    }

    public function markDuplicate(StudentApplication $application, StudentApplication $of, string $reason, ?User $actor = null): StudentApplication
    {
        if ($of->is($application)) {
            // `chk_sap_not_self_duplicate` could not be a CHECK — MariaDB refuses one that reads an
            // AUTO_INCREMENT column — so the rule lives here, and a test pins it.
            throw CourseRuleException::refuse('duplicate_of_application_id',
                'An application cannot be a duplicate of itself. Pick the earlier one it repeats.');
        }

        return $this->transition($application, StudentApplicationStatus::Duplicate, $reason, $actor, [
            'duplicate_of_application_id' => $of->getKey(),
        ]);
    }

    public function reject(StudentApplication $application, string $reason, ?User $actor = null): StudentApplication
    {
        return $this->transition($application, StudentApplicationStatus::Rejected, $reason, $actor, [
            'rejection_reason' => $reason,
        ]);
    }

    public function withdraw(StudentApplication $application, string $reason, ?User $actor = null): StudentApplication
    {
        return $this->transition($application, StudentApplicationStatus::Withdrawn, $reason, $actor);
    }

    /*
    |--------------------------------------------------------------------------
    | Conversion — §2.31 step 3, one transaction
    |--------------------------------------------------------------------------
    */

    /**
     * Student + admission + attribution, or nothing at all.
     *
     * @param  array<string, mixed>  $overrides  the agreed figures and anything the reviewer corrected
     */
    public function convert(StudentApplication $application, array $overrides = [], ?User $actor = null): StudentAdmission
    {
        if ($application->isConverted()) {
            return $application->convertedAdmission;
        }

        if ($application->status->isTerminal()) {
            throw CourseRuleException::refuse('status', sprintf(
                'This application is %s. A %s application is not converted — the applicant re-applies, '
                .'which is a new row with its own date.',
                $application->status->label(),
                strtolower($application->status->label()),
            ));
        }

        $course = Course::query()->findOrFail($overrides['course_id'] ?? $application->course_id);

        return $this->db->transaction(function () use ($application, $course, $overrides, $actor): StudentAdmission {
            $student = $this->students->create(array_merge([
                'name' => $application->name,
                'father_name' => $application->father_name,
                'phone' => $application->phone,
                'whatsapp' => $application->whatsapp,
                'email' => $application->email,
                'city' => $application->city,
                'education' => $application->education,
                'branch_id' => $application->branch_id,
                'joining_date' => Carbon::now()->toDateString(),
            ], $overrides['student'] ?? []), $actor);

            $admission = $this->admissions->create($student, $course, $overrides, $application, $actor);

            $this->attachReferral($application, $student);

            $application->forceFill([
                'status' => StudentApplicationStatus::Converted->value,
                'converted_student_id' => $student->getKey(),
                'converted_admission_id' => $admission->getKey(),
                'converted_at' => Carbon::now(),
                'reviewed_by' => $application->reviewed_by ?? $actor?->getKey(),
                'reviewed_at' => $application->reviewed_at ?? Carbon::now(),
                'updated_by' => $actor?->getKey(),
            ])->save();

            $this->closeLinkedInquiry($application, $student, $actor);

            return $admission->refresh();
        }, 3);
    }

    /**
     * The duplicate case: this is somebody the institute already has. A new admission, no new student,
     * and the existing attribution is left alone — changing who referred a student is its own act,
     * with its own screen and its own reason (spine §7.3).
     */
    public function linkExistingStudent(StudentApplication $application, Student $student, array $overrides = [], ?User $actor = null): StudentAdmission
    {
        if ($application->isConverted()) {
            return $application->convertedAdmission;
        }

        $course = Course::query()->findOrFail($overrides['course_id'] ?? $application->course_id);

        return $this->db->transaction(function () use ($application, $student, $course, $overrides, $actor): StudentAdmission {
            $admission = $this->admissions->create($student, $course, $overrides, $application, $actor);

            if ($student->collaborator_id === null) {
                $this->attachReferral($application, $student);
            }

            $application->forceFill([
                'status' => StudentApplicationStatus::Converted->value,
                'converted_student_id' => $student->getKey(),
                'converted_admission_id' => $admission->getKey(),
                'converted_at' => Carbon::now(),
                'updated_by' => $actor?->getKey(),
            ])->save();

            $this->closeLinkedInquiry($application, $student, $actor);

            return $admission->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(
        array $data,
        Course $course,
        ReferralDecision $decision,
        ?Request $request,
        ?User $actor,
        ?CourseInquiry $inquiry,
    ): StudentApplication {
        return $this->db->transaction(function () use ($data, $course, $decision, $request, $actor, $inquiry): StudentApplication {
            $application = new StudentApplication;

            $application->fill([
                'branch_id' => $data['branch_id'] ?? $course->branch_id,
                'course_inquiry_id' => $inquiry?->getKey() ?? ($data['course_inquiry_id'] ?? null),
                'name' => $data['name'] ?? null,
                'father_name' => $data['father_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'whatsapp' => $data['whatsapp'] ?? null,
                'email' => $data['email'] ?? null,
                'city' => $data['city'] ?? null,
                'education' => $data['education'] ?? null,
                'course_id' => $course->getKey(),
                'batch_id' => $data['batch_id'] ?? null,
                'preferred_timing' => $data['preferred_timing'] ?? null,
                'preferred_delivery_mode' => $data['preferred_delivery_mode'] ?? null,
                'message' => $data['message'] ?? null,
            ]);

            $phone = $this->normalisePhone((string) ($data['phone'] ?? ''));

            $application->forceFill([
                'application_number' => $this->numbers->nextApplicationNumber(),
                'phone' => $phone,
                'whatsapp' => $this->optionalPhone($data['whatsapp'] ?? null),
                'status' => StudentApplicationStatus::Submitted->value,
                'idempotency_key' => (string) $data['idempotency_key'],
                'duplicate_fingerprint' => StudentApplication::fingerprintFor($phone, $course->getKey(), $data['name'] ?? ''),
                // The code exactly as submitted, valid or not: a partner's mistyped code is worth
                // seeing, and a blank field would hide that anybody quoted one.
                'referral_code' => $data['referral_code'] ?? $decision->visit?->referral_code,
                'referral_code_valid' => $decision->hasWinner(),
                'collaborator_id' => $decision->winnerId(),
                'referral_source' => $decision->source?->value,
                'referral_visit_id' => $decision->visit?->getKey(),
                'landing_url' => $decision->visit?->landing_url ?? ($data['landing_url'] ?? null),
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'created_by' => $actor?->getKey(),
            ])->save();

            return $application->refresh();
        }, 3);
    }

    /**
     * §2.30.3, with the reason every terminal move takes.
     *
     * @param  array<string, mixed>  $extra
     */
    private function transition(
        StudentApplication $application,
        StudentApplicationStatus $to,
        string $reason,
        ?User $actor,
        array $extra = [],
    ): StudentApplication {
        $from = $application->status;
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw CourseRuleException::refuse('status', sprintf(
                'An application cannot go from %s to %s. %s',
                $from->label(),
                $to->label(),
                $allowed === [] ? sprintf('%s is final.', $from->label()) : 'From here: '.implode(', ', $allowed).'.',
            ));
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::reasonRequired('reason', sprintf(
                'Marking an application %s takes a reason. Somebody filled this in and is waiting to '
                .'hear why the answer was no.',
                $to->label(),
            ));
        }

        return $this->db->transaction(function () use ($application, $to, $reason, $actor, $extra): StudentApplication {
            $application->withReason($reason);

            $application->forceFill(array_merge($extra, [
                'status' => $to->value,
                'reviewed_by' => $application->reviewed_by ?? $actor?->getKey(),
                'reviewed_at' => $application->reviewed_at ?? Carbon::now(),
                'updated_by' => $actor?->getKey(),
            ]))->save();

            return $application->refresh();
        }, 3);
    }

    /**
     * Turn the captured evidence into the one authoritative attribution — through Phase 10's service,
     * never by writing `collaborator_referrals` here.
     */
    private function attachReferral(StudentApplication $application, Student $student): void
    {
        if (! $application->hasValidReferral()) {
            return;
        }

        $service = 'App\\Services\\Collaborator\\ReferralService';

        if (! class_exists($service)) {
            return;
        }

        $collaborator = $application->collaborator;

        if ($collaborator === null) {
            return;
        }

        // The business date is the application's own, not today's: a partner earns the credit on the
        // day their code was quoted, even when the form sits in the inbox for a fortnight.
        $on = $application->created_at ?? Carbon::now();

        app($service)->attach(
            $student,
            $collaborator,
            $application->referral_source ?? ReferralSource::AdmissionForm,
            (string) $application->referral_code,
            $on,
            new ReferralContext(
                referralVisitId: $application->referral_visit_id,
                landingUrl: $application->landing_url,
                ipAddress: $application->ip_address,
                userAgent: $application->user_agent,
                referralDate: $on,
                notes: sprintf('Attached on conversion of application %s.', $application->application_number),
            ),
        );
    }

    /** The funnel is never left with a step missing: a converted application closes its enquiry. */
    private function closeLinkedInquiry(StudentApplication $application, Student $student, ?User $actor): void
    {
        $inquiry = $application->inquiry;

        if (! $inquiry instanceof CourseInquiry || $inquiry->status === CourseInquiryStatus::AdmissionConfirmed) {
            return;
        }

        $this->inquiries->changeStatus($inquiry, CourseInquiryStatus::AdmissionConfirmed, null, $actor, silent: true);

        $inquiry->forceFill([
            'converted_application_id' => $application->getKey(),
            'converted_student_id' => $student->getKey(),
            'updated_by' => $actor?->getKey(),
        ])->save();
    }

    /**
     * The course has to be one the public could have applied to. Staff may admit to a course that is
     * closed — somebody walking in with cash is not turned away because a switch is off — but never
     * to one that was never published.
     */
    private function assertCourseIsOpen(mixed $courseId, bool $staffOverride = false): Course
    {
        $course = Course::query()->find($courseId);

        if (! $course instanceof Course || ! $course->status->isPublic()) {
            throw CourseRuleException::refuse('course_id',
                'That course is not open for admission.');
        }

        if ($staffOverride) {
            return $course;
        }

        $open = app(CourseService::class)->effectiveAdmissionOpen($course);

        if (! $open) {
            throw CourseRuleException::refuse('course_id', sprintf(
                'Admissions for %s are closed at the moment.', $course->name,
            ));
        }

        return $course;
    }

    private function normalisePhone(string $phone): string
    {
        $trimmed = trim($phone);
        $plus = str_starts_with($trimmed, '+') ? '+' : '';

        return $plus.(preg_replace('/\D/', '', $trimmed) ?? '');
    }

    private function optionalPhone(mixed $phone): ?string
    {
        $value = trim((string) ($phone ?? ''));

        return $value === '' ? null : $this->normalisePhone($value);
    }
}
