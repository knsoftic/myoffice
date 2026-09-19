<?php

declare(strict_types=1);

namespace Tests\Unit\Cms\Marketing;

use App\Enums\ApprovalStatus;
use App\Enums\ContentSource;
use App\Enums\EmploymentType;
use App\Enums\InquiryRoutingStatus;
use App\Enums\InquiryType;
use App\Enums\JobApplicationStatus;
use App\Enums\JobOpeningStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The phase-04 state machines and routing contract, pinned at the enum level (phase-04 §3, phase-07 §3 for
 * `EmploymentType`).
 *
 *   · test 38: `JobApplicationStatus::allowedNext()` is exactly the binding six-stage table, `rejected` needs a
 *     reason, `interview` needs a slot, `selected` / `rejected` are terminal;
 *   · tests 42-49: `InquiryType::routingTarget()` is the only form-input → target mapping (`service` → `crm_lead`,
 *     `course` → `course_inquiry`, `general` → none) and only `pending` / `failed` need a retry;
 *   · tests 15-20: only `approved` is public, and only staff sources (`admin`, `import`) skip moderation;
 *   · tests 36 / 40: only an `open` opening accepts applications or lists publicly; `closed` and `filled` stamp
 *     `closed_at`;
 *   · E9: `EmploymentType` ships with phase-07 §3's seven cases and both members, verbatim.
 */
final class PipelineAndRoutingEnumsTest extends TestCase
{
    /** phase-04 §3, binding. */
    private const PIPELINE = [
        'new' => ['reviewing', 'shortlisted', 'rejected'],
        'reviewing' => ['shortlisted', 'interview', 'rejected'],
        'shortlisted' => ['interview', 'selected', 'rejected'],
        'interview' => ['selected', 'rejected', 'shortlisted'],
        'selected' => ['rejected'],
        'rejected' => ['reviewing'],
    ];

    #[Test]
    public function test_38_the_application_pipeline_is_exactly_the_binding_table(): void
    {
        $this->assertSame(array_keys(self::PIPELINE), array_map(static fn (JobApplicationStatus $case): string => $case->value, JobApplicationStatus::cases()), 'Six stages, in pipeline order.');

        foreach (JobApplicationStatus::cases() as $from) {
            $allowed = array_map(static fn (JobApplicationStatus $to): string => $to->value, $from->allowedNext());

            $this->assertEqualsCanonicalizing(self::PIPELINE[$from->value], $allowed, sprintf('allowedNext() of %s.', $from->value));

            foreach (JobApplicationStatus::cases() as $to) {
                $this->assertSame(
                    in_array($to->value, self::PIPELINE[$from->value], true),
                    $from->canTransitionTo($to),
                    sprintf('%s → %s.', $from->value, $to->value),
                );
            }
        }

        $this->assertFalse(JobApplicationStatus::New->canTransitionTo(JobApplicationStatus::Interview), 'new → interview is refused (§11 test 38).');
        $this->assertTrue(JobApplicationStatus::New->canTransitionTo(JobApplicationStatus::Reviewing));

        foreach (JobApplicationStatus::cases() as $case) {
            $this->assertSame($case === JobApplicationStatus::Rejected, $case->requiresReason(), $case->value);
            $this->assertSame($case === JobApplicationStatus::Interview, $case->requiresInterviewSlot(), $case->value);
            $this->assertSame(in_array($case, [JobApplicationStatus::Selected, JobApplicationStatus::Rejected], true), $case->isTerminal(), $case->value);
        }
    }

    #[Test]
    public function test_42_49_the_inquiry_type_decides_the_routing_target(): void
    {
        $this->assertSame('crm_lead', InquiryType::Service->routingTarget());
        $this->assertSame('course_inquiry', InquiryType::Course->routingTarget());
        $this->assertNull(InquiryType::General->routingTarget());

        $this->assertSame(InquiryType::TARGET_CRM_LEAD, InquiryType::Service->routingTarget());
        $this->assertSame(InquiryType::TARGET_COURSE_INQUIRY, InquiryType::Course->routingTarget());
        $this->assertSame(['service', 'course', 'general'], array_map(static fn (InquiryType $case): string => $case->value, InquiryType::cases()));

        foreach (InquiryRoutingStatus::cases() as $status) {
            $this->assertSame(
                in_array($status, [InquiryRoutingStatus::Pending, InquiryRoutingStatus::Failed], true),
                $status->needsRetry(),
                sprintf('needsRetry() of %s.', $status->value),
            );
        }
    }

    #[Test]
    public function test_15_20_only_approved_is_public_and_only_staff_sources_skip_moderation(): void
    {
        foreach (ApprovalStatus::cases() as $status) {
            $this->assertSame($status === ApprovalStatus::Approved, $status->isPublic(), $status->value);
        }

        foreach (ContentSource::cases() as $source) {
            $this->assertSame(
                ! in_array($source, [ContentSource::Admin, ContentSource::Import], true),
                $source->requiresModeration(),
                sprintf('requiresModeration() of %s.', $source->value),
            );
        }
    }

    #[Test]
    public function test_36_40_only_an_open_opening_accepts_applications(): void
    {
        foreach (JobOpeningStatus::cases() as $status) {
            $this->assertSame($status === JobOpeningStatus::Open, $status->acceptsApplications(), $status->value);
            $this->assertSame($status === JobOpeningStatus::Open, $status->isPublic(), sprintf('%s: neither closed nor filled lists publicly.', $status->value));
            $this->assertSame(in_array($status, [JobOpeningStatus::Closed, JobOpeningStatus::Filled], true), $status->isClosed(), $status->value);
        }
    }

    #[Test]
    public function employment_type_ships_phase_seven_cases_and_members_verbatim(): void
    {
        $this->assertSame(
            ['full_time', 'part_time', 'contract', 'internship', 'temporary', 'consultant', 'freelance'],
            array_map(static fn (EmploymentType $case): string => $case->value, EmploymentType::cases()),
        );

        foreach (EmploymentType::cases() as $case) {
            $nonSalaried = in_array($case, [EmploymentType::Consultant, EmploymentType::Freelance], true);

            $this->assertSame(! $nonSalaried, $case->isSalaried(), sprintf('isSalaried() of %s.', $case->value));
            $this->assertSame(! $nonSalaried, $case->leaveEligibleByDefault(), sprintf('leaveEligibleByDefault() of %s.', $case->value));
        }
    }
}
