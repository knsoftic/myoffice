<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Enums\Cms\ContentStatus;
use App\Enums\InquiryRoutingStatus;
use App\Enums\InquiryType;
use App\Jobs\Cms\RouteContactInquiry;
use App\Models\Cms\ContactInquiry;
use App\Models\Cms\Service;
use App\Services\Cms\InquiryRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Cms\Marketing\Behaviour\Fixtures\FakeInquiryTarget;
use Tests\Feature\Cms\Marketing\Behaviour\Fixtures\FakeRoutedRecord;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 tests 42-49 — the inquiry routing contract (§6.10).
 *
 * Phase 4 ships with no target registered; these tests register `FakeInquiryTarget` to prove the pipeline:
 *
 *  42. a general inquiry is `not_applicable` and no target is attempted;
 *  43. with no target, a service inquiry waits `pending` / `target_unregistered`, is listed with a disabled
 *      Route now, throws nothing and leaves `failed_jobs` empty;
 *  44. registering a target and running `inquiries:route-pending` routes the backlog and logs both records;
 *  45. routing is idempotent, and a second inquiry cannot claim the same target row (`uq_contact_inquiry_routed_target`);
 *  46. a disabled target module leaves the inquiry `pending` / `module_disabled`; re-enabling routes it;
 *  47. a throwing target keeps the inquiry intact, counts attempts, stores the message, fails on the third try and
 *      stays retryable;
 *  48. `website.inquiry_auto_route = false` attempts nothing; the manual Route now routes it, and 403s without
 *      `contact_inquiries.change_status`;
 *  49. a course inquiry with only a free-text `course_name` routes to the course target carrying the name.
 */
final class InquiryRoutingTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        $this->setSetting('website.inquiry_auto_route', true);

        // Start from the Phase 4 shipping state — a router with no target — even once a later phase's service
        // provider registers its real target: every test below registers exactly the fakes it needs.
        $this->app->forgetInstance(InquiryRouter::class);
    }

    public function test_42_a_general_inquiry_is_not_applicable_and_attempts_no_target(): void
    {
        $lead = $this->registerFakeTarget(InquiryType::TARGET_CRM_LEAD);
        $course = $this->registerFakeTarget(InquiryType::TARGET_COURSE_INQUIRY);

        $inquiry = $this->submitInquiry(['inquiry_type' => InquiryType::General->value]);

        $this->assertSame(InquiryRoutingStatus::NotApplicable, $inquiry->routing_status);
        $this->assertNull($inquiry->routing_target);
        $this->assertSame(0, (int) $inquiry->routing_attempts);
        $this->assertNull($inquiry->routed_id);

        $this->assertSame(InquiryRoutingStatus::NotApplicable, $this->router()->route($inquiry->fresh()));
        $this->assertSame(0, $lead->handled + $course->handled, 'No target is ever attempted for a general inquiry.');
        $this->assertSame(0, (int) $inquiry->fresh()->routing_attempts);
    }

    public function test_43_without_a_target_an_inquiry_waits_without_failing(): void
    {
        // Phase 5 registered the crm_lead target, so a service inquiry now becomes a lead (test 43a).
        // The waiting path is still real, and is proved on the course inquiry target, which no phase has
        // shipped yet: an inquiry for a module that has not arrived waits, and never fails a job.
        $this->assertNull(
            $this->router()->target(InquiryType::TARGET_COURSE_INQUIRY),
            'The course inquiry target arrives with Phase 15.'
        );

        $inquiry = $this->submitInquiry([
            'inquiry_type' => InquiryType::Course->value,
            'name' => 'Awaiting Course Visitor',
        ]);

        $this->assertSame('course_inquiry', $inquiry->routing_target);
        $this->assertSame(InquiryRoutingStatus::Pending, $inquiry->routing_status);
        $this->assertSame('target_unregistered', $inquiry->routing_error);
        $this->assertNull($inquiry->routed_id);

        // Retrying — directly, through the queued job and through the command — still throws nothing.
        $this->assertSame(InquiryRoutingStatus::Pending, $this->router()->route($inquiry->fresh()));
        (new RouteContactInquiry((int) $inquiry->getKey()))->handle($this->router());
        $this->assertSame(0, Artisan::call('inquiries:route-pending'));

        $this->assertSame(InquiryRoutingStatus::Pending, $inquiry->fresh()->routing_status);
        $this->assertSame('target_unregistered', $inquiry->fresh()->routing_error);
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'A missing later phase never lands in failed_jobs.');

        $this->assertFalse($this->router()->canRoute($inquiry->fresh()), 'Route now is disabled.');
        $this->assertSame('Course inquiry module not installed yet', $this->router()->waitingReason($inquiry->fresh()));

        $this->actingAs($this->createSuperAdmin())
            ->get(route('admin.contact-inquiries.index'))
            ->assertOk()
            ->assertSee('Awaiting Course Visitor')
            ->assertSee('Course inquiry module not installed yet')
            ->assertDontSee(route('admin.contact-inquiries.route', $inquiry), false);
    }

    public function test_44_registering_a_target_and_running_the_command_routes_the_backlog(): void
    {
        // The crm_lead slot is taken by Phase 5's real target, so the backlog is proved on the course slot.
        $inquiry = $this->submitInquiry(['inquiry_type' => InquiryType::Course->value, 'name' => 'Backlog Visitor', 'course_name' => 'Backlog Course']);
        $this->assertSame(InquiryRoutingStatus::Pending, $inquiry->routing_status);

        $target = $this->registerFakeTarget(InquiryType::TARGET_COURSE_INQUIRY);
        $marker = $this->lastActivityId();

        $this->assertSame(0, Artisan::call('inquiries:route-pending'));
        $this->assertStringContainsString('1 routed', Artisan::output());

        $fresh = $inquiry->fresh();
        $this->assertSame(InquiryRoutingStatus::Routed, $fresh->routing_status);
        $this->assertSame(FakeRoutedRecord::class, $fresh->routed_type);
        $this->assertNotNull($fresh->routed_id);
        $this->assertNotNull($fresh->routed_at);
        $this->assertNull($fresh->routing_error);
        $this->assertSame(1, $target->handled);

        $record = FakeRoutedRecord::query()->findOrFail($fresh->routed_id);
        $this->assertSame(FakeInquiryTarget::slugFor(InquiryType::TARGET_COURSE_INQUIRY, (int) $inquiry->getKey()), $record->getAttribute('slug'));
        $this->assertSame('Backlog Course', $record->getAttribute('name'));

        $entries = $this->activitiesSince($marker, 'contact_inquiries', 'routed');
        $this->assertCount(1, $entries, 'One ContactInquiryRouted entry.');

        $entry = $entries->first();
        $properties = $this->propertiesOf($entry);
        $this->assertSame((int) $inquiry->getKey(), (int) $entry->subject_id);
        $this->assertSame(FakeRoutedRecord::class, $properties['routed_type'] ?? null);
        $this->assertSame((int) $record->getKey(), (int) ($properties['routed_id'] ?? 0));
        $this->assertStringContainsString('#'.$inquiry->getKey(), (string) $entry->description, 'The entry names the inquiry.');
        $this->assertStringContainsString('#'.$record->getKey(), (string) $entry->description, 'The entry names the created record.');

        // The inquiry itself is kept, never moved or edited.
        $this->assertSame('Backlog Visitor', $fresh->name);
        $this->assertNull($fresh->deleted_at);
    }

    public function test_45_routing_is_idempotent_and_a_target_row_is_claimed_once(): void
    {
        $target = $this->registerFakeTarget();

        $first = $this->submitInquiry(['inquiry_type' => InquiryType::Service->value]);
        $this->assertSame(InquiryRoutingStatus::Routed, $first->routing_status, 'Auto-routing ran on submission.');
        $this->assertSame(1, FakeInquiryTarget::recordCount('crm_lead'));

        $this->assertSame(InquiryRoutingStatus::Routed, $this->router()->route($first->fresh()));
        $this->assertSame(InquiryRoutingStatus::Routed, $this->router()->route($first->fresh()));
        $this->assertSame(0, Artisan::call('inquiries:route-pending'));

        $this->assertSame(1, FakeInquiryTarget::recordCount('crm_lead'), 'Calling route() again creates no second record.');
        $this->assertSame(1, $target->handled, 'An already-routed inquiry never reaches the target again.');

        // A second inquiry whose target hands back the same row loses the unique claim and stays pending.
        $record = FakeRoutedRecord::query()->findOrFail($first->fresh()->routed_id);
        $target->sticky = $record;

        $second = $this->submitInquiry(['inquiry_type' => InquiryType::Service->value]);
        $fresh = $second->fresh();

        $this->assertSame(InquiryRoutingStatus::Pending, $fresh->routing_status);
        $this->assertNull($fresh->routed_id);
        $this->assertNull($fresh->routed_type);
        $this->assertSame(1, (int) $fresh->routing_attempts);
        $this->assertNotNull($fresh->routing_error, 'The failed claim is recorded on the inquiry.');

        $this->assertSame(1, ContactInquiry::query()->where('routed_type', FakeRoutedRecord::class)->where('routed_id', $record->getKey())->count(), 'Exactly one inquiry claims the target row.');
        $this->assertSame((int) $record->getKey(), (int) $first->fresh()->routed_id);
        $this->assertSame(1, FakeInquiryTarget::recordCount('crm_lead'));
    }

    public function test_46_a_disabled_target_module_waits_and_routes_once_re_enabled(): void
    {
        $this->registerFakeTarget(InquiryType::TARGET_CRM_LEAD, 'leads');
        $this->switchModule('leads', false);

        $inquiry = $this->submitInquiry(['inquiry_type' => InquiryType::Service->value]);

        $this->assertSame(InquiryRoutingStatus::Pending, $inquiry->routing_status);
        $this->assertSame('module_disabled', $inquiry->routing_error);
        $this->assertFalse($this->router()->canRoute($inquiry));

        $this->assertSame(0, Artisan::call('inquiries:route-pending'));
        $this->assertSame('module_disabled', $inquiry->fresh()->routing_error, 'Still waiting while the module is off.');
        $this->assertSame(0, FakeInquiryTarget::recordCount('crm_lead'));

        $this->switchModule('leads', true);

        $this->assertSame(0, Artisan::call('inquiries:route-pending'));

        $fresh = $inquiry->fresh();
        $this->assertSame(InquiryRoutingStatus::Routed, $fresh->routing_status);
        $this->assertNull($fresh->routing_error);
        $this->assertSame(1, FakeInquiryTarget::recordCount('crm_lead'));
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_47_a_throwing_target_counts_attempts_fails_on_the_third_and_stays_retryable(): void
    {
        $this->setSetting('website.inquiry_auto_route', false);

        $inquiry = $this->submitInquiry(['inquiry_type' => InquiryType::Service->value, 'message' => 'Please call me about a mobile app for our clinic chain.']);
        $this->assertSame(0, (int) $inquiry->routing_attempts);

        $target = $this->registerFakeTarget();
        $target->throwMessage = 'CRM API timed out';
        $marker = $this->lastActivityId();

        $expected = [1 => InquiryRoutingStatus::Pending, 2 => InquiryRoutingStatus::Pending, 3 => InquiryRoutingStatus::Failed];

        foreach ($expected as $attempt => $status) {
            $this->assertSame($status, $this->router()->route($inquiry->fresh()), sprintf('Attempt %d.', $attempt));

            $fresh = $inquiry->fresh();
            $this->assertSame($attempt, (int) $fresh->routing_attempts);
            $this->assertSame('CRM API timed out', $fresh->routing_error);
            $this->assertSame($status, $fresh->routing_status);
            $this->assertNull($fresh->routed_id);
        }

        $this->assertSame(0, FakeInquiryTarget::recordCount('crm_lead'), 'The failed target record was rolled back.');
        $this->assertCount(3, $this->activitiesSince($marker, 'contact_inquiries', 'routing_failed'));
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // Intact and visible.
        $fresh = $inquiry->fresh();
        $this->assertNotNull($fresh, 'The inquiry survives every failure.');
        $this->assertSame('Please call me about a mobile app for our clinic chain.', $fresh->message);
        $this->assertFalse((bool) $fresh->is_spam);

        $admin = $this->createSuperAdmin();
        $this->assertTrue(ContactInquiry::query()->visibleTo($admin)->tab('awaiting')->whereKey($inquiry->getKey())->exists(), 'A failed inquiry is listed in the awaiting queue.');

        // Retryable: once the target recovers, the backlog walk routes it.
        $target->throwMessage = null;

        $this->assertSame(0, Artisan::call('inquiries:route-pending', ['--force' => true]));

        $this->assertSame(InquiryRoutingStatus::Routed, $inquiry->fresh()->routing_status);
        $this->assertNull($inquiry->fresh()->routing_error);
        $this->assertSame(1, FakeInquiryTarget::recordCount('crm_lead'));
    }

    public function test_48_with_auto_route_off_nothing_is_attempted_until_route_now(): void
    {
        $this->setSetting('website.inquiry_auto_route', false);
        $target = $this->registerFakeTarget();

        $inquiry = $this->submitInquiry(['inquiry_type' => InquiryType::Service->value]);

        $this->assertSame(InquiryRoutingStatus::Pending, $inquiry->routing_status);
        $this->assertNull($inquiry->routing_error);
        $this->assertSame(0, (int) $inquiry->routing_attempts);
        $this->assertSame(0, $target->handled, 'No target attempt on submission.');

        $this->assertSame(0, Artisan::call('inquiries:route-pending'));
        $this->assertSame(0, $target->handled, 'The hourly command creates nothing while auto-route is off.');
        $this->assertSame(InquiryRoutingStatus::Pending, $inquiry->fresh()->routing_status);

        $viewer = $this->createUserWithPermissions(['contact_inquiries.view_any', 'contact_inquiries.view']);

        $this->actingAs($viewer)
            ->post(route('admin.contact-inquiries.route', $inquiry))
            ->assertForbidden();

        $this->assertSame(0, $target->handled);
        $this->assertSame(InquiryRoutingStatus::Pending, $inquiry->fresh()->routing_status);

        $this->actingAs($this->createSuperAdmin())
            ->post(route('admin.contact-inquiries.route', $inquiry))
            ->assertSessionHasNoErrors();

        $this->assertSame(InquiryRoutingStatus::Routed, $inquiry->fresh()->routing_status);
        $this->assertSame(1, $target->handled);
        $this->assertSame(1, FakeInquiryTarget::recordCount('crm_lead'));
    }

    public function test_49_a_course_inquiry_before_courses_exist_routes_with_its_free_text_name(): void
    {
        $lead = $this->registerFakeTarget(InquiryType::TARGET_CRM_LEAD);
        $course = $this->registerFakeTarget(InquiryType::TARGET_COURSE_INQUIRY);

        $inquiry = $this->submitInquiry([
            'inquiry_type' => InquiryType::Course->value,
            'course_name' => 'Flutter Mobile Bootcamp',
            'course_id' => null,
        ]);

        $fresh = $inquiry->fresh();
        $this->assertNull($fresh->course_id, 'course_id stays null before Phase 14.');
        $this->assertSame('Flutter Mobile Bootcamp', $fresh->course_name);
        $this->assertSame('course_inquiry', $fresh->routing_target);
        $this->assertSame(InquiryRoutingStatus::Routed, $fresh->routing_status);

        $this->assertSame(1, $course->handled);
        $this->assertSame(0, $lead->handled, 'A course inquiry never reaches the CRM target.');

        $record = FakeRoutedRecord::query()->findOrFail($fresh->routed_id);
        $this->assertSame('Flutter Mobile Bootcamp', $record->getAttribute('name'), 'The course name travels with the routed record.');
    }

    private function publishedService(string $name): Service
    {
        $this->actAsSuperAdmin();

        $service = $this->makeService($name, ['status' => ContentStatus::Published->value]);

        $this->becomeGuest();

        return $service;
    }
}
