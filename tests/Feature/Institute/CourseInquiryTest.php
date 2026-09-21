<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\CourseInquiryStatus;
use App\Enums\FollowUpOutcome;
use App\Models\Institute\CourseInquiry;
use App\Models\Institute\CourseInquiryFollowUp;
use App\Models\User;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\TestCase;

/**
 * The counsellor's queue (§86, §68, phase-14-17 §2.30.2, §6.12).
 *
 * **The outcome of a call moves the status, and that is the point.** A follow-up logged as "not
 * interested" against an enquiry that still reads `contacted` is a queue that disagrees with its own
 * history, and whichever half the next person reads is the one they act on.
 *
 * **The contact log is append-only.** "We called four times" is a claim somebody makes to a manager
 * or to the person on the other end of the phone, and a log whose rows can be removed cannot support
 * it.
 */
final class CourseInquiryTest extends TestCase
{
    use BuildsAdmissions;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Follow-ups drive the status
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_first_contact_moves_a_new_inquiry_to_contacted(): void
    {
        $actor = $this->createSuperAdmin();
        $inquiry = $this->inquiry([], $actor);

        $this->assertSame(CourseInquiryStatus::New, $inquiry->status);

        $this->inquiryService()->logFollowUp($inquiry, [
            'channel' => 'call',
            'outcome' => 'reached',
            'notes' => 'Spoke to them, sending details.',
        ], $actor);

        $this->assertSame(CourseInquiryStatus::Contacted, $inquiry->refresh()->status);
        $this->assertSame(1, $inquiry->contact_attempts);
        $this->assertNotNull($inquiry->last_contacted_at);
        $this->assertNotNull($inquiry->follow_up_date, 'an open inquiry always has a next action');
    }

    #[Test]
    public function an_unanswered_call_counts_but_changes_nothing_else(): void
    {
        $actor = $this->createSuperAdmin();
        $inquiry = $this->inquiry([], $actor);

        $this->inquiryService()->logFollowUp($inquiry, ['channel' => 'call', 'outcome' => 'no_answer'], $actor);

        $this->assertSame(CourseInquiryStatus::New, $inquiry->refresh()->status,
            'nobody was reached, so nothing about what they want has changed');
        $this->assertSame(1, $inquiry->contact_attempts);
    }

    #[Test]
    public function a_lost_outcome_carries_the_reason_onto_the_inquiry(): void
    {
        $actor = $this->createSuperAdmin();
        $inquiry = $this->inquiry([], $actor);

        $this->inquiryService()->logFollowUp($inquiry, [
            'channel' => 'call',
            'outcome' => 'not_interested',
            'notes' => 'Joined another institute last week.',
        ], $actor);

        $inquiry->refresh();

        $this->assertSame(CourseInquiryStatus::NotInterested, $inquiry->status);
        $this->assertSame('Joined another institute last week.', $inquiry->lost_reason);
        $this->assertNull($inquiry->follow_up_date, 'a finished enquiry needs no next action');
    }

    #[Test]
    public function the_follow_up_records_the_status_on_both_sides_of_the_call(): void
    {
        $actor = $this->createSuperAdmin();
        $inquiry = $this->inquiry([], $actor);

        $followUp = $this->inquiryService()->logFollowUp($inquiry, [
            'channel' => 'whatsapp',
            'outcome' => 'interested',
        ], $actor);

        $this->assertSame(CourseInquiryStatus::New, $followUp->status_before);
        $this->assertSame(CourseInquiryStatus::Interested, $followUp->status_after);
        $this->assertSame($actor->name, $followUp->contactedBy());
    }

    /*
    |--------------------------------------------------------------------------
    | The transition table
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function losing_an_inquiry_demands_a_reason_and_so_does_re_opening_it(): void
    {
        $actor = $this->createSuperAdmin();
        $inquiry = $this->inquiry([], $actor);

        try {
            $this->inquiryService()->changeStatus($inquiry, CourseInquiryStatus::NotInterested, null, $actor);
            $this->fail('a loss with no reason was accepted');
        } catch (CourseRuleException $e) {
            $this->assertStringContainsString('takes a reason', $e->getMessage());
        }

        $this->inquiryService()->changeStatus($inquiry, CourseInquiryStatus::NotInterested, 'Price', $actor);
        $this->assertSame('Price', $inquiry->refresh()->lost_reason);

        try {
            $this->inquiryService()->changeStatus($inquiry, CourseInquiryStatus::Contacted, null, $actor);
            $this->fail('re-opening with no reason was accepted');
        } catch (CourseRuleException $e) {
            $this->assertStringContainsString('takes a reason', $e->getMessage());
        }

        $this->inquiryService()->changeStatus($inquiry, CourseInquiryStatus::Contacted, 'They called back', $actor);

        $inquiry->refresh();

        $this->assertSame(CourseInquiryStatus::Contacted, $inquiry->status);
        $this->assertNull($inquiry->lost_reason, 'a stale loss reason on a live enquiry reads as a current verdict');
    }

    #[Test]
    public function a_converted_inquiry_is_the_end_of_the_line(): void
    {
        $actor = $this->createSuperAdmin();
        $inquiry = $this->inquiry([], $actor);

        $this->inquiryService()->changeStatus($inquiry, CourseInquiryStatus::AdmissionConfirmed, null, $actor);

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessageMatches('/is where this one ends/');

        $this->inquiryService()->changeStatus($inquiry->refresh(), CourseInquiryStatus::Contacted, 'Trying again', $actor);
    }

    /*
    |--------------------------------------------------------------------------
    | The log is append-only
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_follow_up_log_has_no_soft_delete_column(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('course_inquiry_follow_ups', 'deleted_at'),
            'D-IN-2: a contact log with a deleted_at is one whose count nobody can stand behind',
        );
    }

    #[Test]
    public function deleting_an_inquiry_takes_its_follow_ups_with_it(): void
    {
        $actor = $this->createSuperAdmin();
        $inquiry = $this->inquiry([], $actor);

        $this->inquiryService()->logFollowUp($inquiry, ['channel' => 'call', 'outcome' => 'reached'], $actor);

        $this->assertDatabaseCount('course_inquiry_follow_ups', 1);

        // Hard delete, which is what the cascade is about — a soft delete leaves the log alone.
        $inquiry->forceDelete();

        $this->assertDatabaseCount('course_inquiry_follow_ups', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | The public path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_replayed_public_inquiry_returns_the_first_one(): void
    {
        $key = (string) Str::ulid();

        $first = $this->inquiryService()->createFromPublic([
            'name' => 'Website Visitor',
            'phone' => '03009998877',
            'idempotency_key' => $key,
        ]);

        $second = $this->inquiryService()->createFromPublic([
            'name' => 'Website Visitor',
            'phone' => '03009998877',
            'idempotency_key' => $key,
        ]);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('course_inquiries', 1);
    }

    #[Test]
    public function one_contact_form_row_can_only_ever_produce_one_inquiry(): void
    {
        // F-3.8: Phase 4's router may deliver the same `contact_inquiries` row twice — a retry, a
        // re-run of the backlog command — and the second delivery must find the first enquiry.
        $first = $this->inquiryService()->createFromPublic([
            'name' => 'Contact Form',
            'phone' => '03001110000',
            'contact_inquiry_id' => 4242,
            'idempotency_key' => (string) Str::ulid(),
        ]);

        $second = $this->inquiryService()->createFromPublic([
            'name' => 'Contact Form',
            'phone' => '03001110000',
            'contact_inquiry_id' => 4242,
            'idempotency_key' => (string) Str::ulid(),
        ]);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('course_inquiries', 1);
    }

    #[Test]
    public function a_phone_number_is_normalised_so_the_duplicate_lookup_can_find_it(): void
    {
        $actor = $this->createSuperAdmin();

        $inquiry = $this->inquiry(['phone' => '0300-123 4567', 'whatsapp' => '+92 300 1234567'], $actor);

        $this->assertSame('03001234567', $inquiry->phone);
        $this->assertSame('+923001234567', $inquiry->whatsapp);
    }

    /*
    |--------------------------------------------------------------------------
    | The queue
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_new_inquiry_is_shared_out_among_the_people_who_work_the_queue(): void
    {
        $this->createSuperAdmin();

        $counsellor = $this->createUserWithPermissions(['course_inquiries.view_any', 'course_inquiries.edit']);

        $first = $this->inquiryService()->createFromPublic([
            'name' => 'Website Visitor',
            'phone' => '03005556666',
            'idempotency_key' => (string) Str::ulid(),
        ]);

        $this->assertNotNull($first->assigned_to, 'an unassigned inquiry is one nobody is answerable for');

        $firstOwner = User::query()->findOrFail($first->assigned_to);

        $this->assertTrue($firstOwner->can('course_inquiries.edit'), 'it went to somebody who works the queue');
        $this->assertFalse(
            $firstOwner->hasRole(User::SUPER_ADMIN_ROLE),
            'the break-glass account holds every permission and works no queue',
        );

        // And it balances rather than always picking the same person: the second goes elsewhere.
        $second = $this->inquiryService()->createFromPublic([
            'name' => 'Another Visitor',
            'phone' => '03005557777',
            'idempotency_key' => (string) Str::ulid(),
        ]);

        // Not the same person twice while somebody else is carrying nothing. Which of the eligible
        // people wins is not asserted: several seeded roles hold this permission, and the point is
        // that the load moves, not that a particular account is first.
        $this->assertNotSame($first->assigned_to, $second->assigned_to);
        $this->assertTrue($counsellor->can('course_inquiries.edit'), 'the counsellor is one of the candidates');
    }

    #[Test]
    public function a_stale_inquiry_is_flagged_and_never_closed(): void
    {
        $actor = $this->createSuperAdmin();
        $inquiry = $this->inquiry([], $actor);

        $this->setting('institute.inquiry_stale_days', 7);

        CourseInquiry::query()->whereKey($inquiry->getKey())->update([
            'created_at' => now()->subDays(30),
            'last_contacted_at' => null,
        ]);

        $inquiry->refresh();

        $this->assertTrue($inquiry->isStale());
        $this->assertSame(CourseInquiryStatus::New, $inquiry->status,
            'an enquiry that went quiet is somebody who stopped being called — closing it hides that');
    }

    #[Test]
    public function the_outcome_enum_and_the_service_cannot_disagree(): void
    {
        // suggestsStatus() is the one mapping; the service applies it rather than repeating it.
        $this->assertSame(CourseInquiryStatus::Interested, FollowUpOutcome::Interested->suggestsStatus());
        $this->assertSame(CourseInquiryStatus::NotInterested, FollowUpOutcome::WrongNumber->suggestsStatus());
        $this->assertNull(FollowUpOutcome::CallLater->suggestsStatus());

        // Wanting a demo is not having one booked: the status that says a demo exists is set by the
        // booking, which is the row somebody can actually turn up to.
        $this->assertSame(CourseInquiryStatus::Interested, FollowUpOutcome::DemoRequested->suggestsStatus());
    }
}
