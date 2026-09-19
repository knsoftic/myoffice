<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Enums\InquiryRoutingStatus;
use App\Enums\InquiryType;
use App\Events\Cms\ContactInquirySubmitted;
use App\Models\Cms\ContactInquiry;
use App\Services\Cms\InquiryRouter;
use App\Services\Cms\SpamGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Cms\Marketing\Behaviour\Fixtures\FakeInquiryTarget;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 tests 50, 51, 53 and 54 — spam without a captcha (§6.9) and sanitisation (D25).
 *
 *  50. a filled honeypot gets the normal success response; the row is stored `is_spam` / `honeypot`, fires no
 *      `ContactInquirySubmitted`, notifies nobody and is never routed;
 *  51. a submission faster than `website.contact_min_submit_seconds`, one with a missing or forged `form_token`,
 *      and one containing a blocklisted word each store the matching `spam_reason`;
 *  53. "Not spam" clears the flags and re-queues routing, which then succeeds;
 *  54. `<script>` in a message is stored without tags and rendered escaped on the admin detail; a rich-text
 *      service description loses `onerror=` and a foreign `<iframe>` on write.
 */
final class SpamAndSanitisationTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        $this->setSetting('maintenance.contact_form_enabled', true);
        $this->setSetting('website.contact_min_submit_seconds', 3);
        $this->setSetting('website.inquiry_auto_route', true);
        $this->app->forgetInstance(InquiryRouter::class);
    }

    public function test_50_a_filled_honeypot_looks_like_success_but_is_stored_as_unrouted_spam(): void
    {
        Event::fake([ContactInquirySubmitted::class]);
        Notification::fake();
        $target = $this->registerFakeTarget();

        $genuine = $this->post(route('site.contact.store'), $this->contactForm(['name' => 'Genuine Fifty Visitor']));
        $genuine->assertSessionHasNoErrors();
        $genuineStatus = $genuine->getStatusCode();
        $genuineToast = session('toast');

        $this->assertFalse((bool) ContactInquiry::query()->where('name', 'Genuine Fifty Visitor')->value('is_spam'), 'The control submission is genuine.');

        $this->flushSession();

        $spam = $this->post(route('site.contact.store'), $this->contactForm([
            'name' => 'Honeypot Fifty Bot',
            SpamGuard::HONEYPOT_FIELD => 'https://cheap-links.example',
        ]));

        $this->assertSame($genuineStatus, $spam->getStatusCode(), 'A bot gets the same status as a person.');
        $this->assertSame($genuineToast, session('toast'), 'A bot gets the same message as a person.');
        $spam->assertSessionHasNoErrors();

        /** @var ContactInquiry $row */
        $row = ContactInquiry::query()->where('name', 'Honeypot Fifty Bot')->firstOrFail();

        $this->assertTrue((bool) $row->is_spam);
        $this->assertSame('honeypot', DB::table('contact_inquiries')->where('id', $row->getKey())->value('spam_reason'));
        $this->assertSame(InquiryRoutingStatus::NotApplicable, $row->routing_status);
        $this->assertNull($row->routing_target);
        $this->assertSame(0, (int) $row->routing_attempts);
        $this->assertNull($row->routed_id);

        Event::assertNotDispatched(ContactInquirySubmitted::class, static fn (ContactInquirySubmitted $event): bool => (int) $event->inquiry->getKey() === (int) $row->getKey());
        Notification::assertNothingSent();

        // Never routed, even when asked to.
        $this->assertSame(InquiryRoutingStatus::NotApplicable, $this->router()->route($row->fresh()));
        $this->router()->routePending();
        $this->assertNull($row->fresh()->routed_id);
        $this->assertSame(0, $target->handled, 'No target was ever called for the spam row.');
        $this->assertSame(0, FakeInquiryTarget::recordCount('crm_lead'));
        $this->assertSame(InquiryRoutingStatus::NotApplicable, $row->fresh()->routing_status);
    }

    public function test_51_timing_token_and_blocklist_signals_store_their_reason(): void
    {
        $this->setSetting('website.spam_blocklist', "casino\nfree-crypto.example");
        $guard = app(SpamGuard::class);

        // Too fast: the token was issued this very second.
        $tooFast = $this->submitInquiry([
            'name' => 'Too Fast Visitor',
            'message' => 'Submitted before a person could have read the form at all.',
            SpamGuard::TIMESTAMP_FIELD => $guard->signedTimestamp(),
        ]);

        // Missing token.
        $missing = $this->submitInquiry([
            'name' => 'Missing Token Visitor',
            'message' => 'This submission carries no render token whatsoever.',
            SpamGuard::TIMESTAMP_FIELD => '',
        ]);

        // Forged token.
        $forged = $this->submitInquiry([
            'name' => 'Forged Token Visitor',
            'message' => 'This submission carries a token that was never issued by us.',
            SpamGuard::TIMESTAMP_FIELD => base64_encode('{"iv":"x","value":"'.time().'","mac":"forged"}'),
        ]);

        // Blocklisted word in the message (a token old enough to pass the timing check).
        $blocked = $this->submitInquiry([
            'name' => 'Blocklist Visitor',
            'message' => 'Best online casino bonuses for your website visitors today.',
        ]);

        $expected = [
            'too_fast' => $tooFast,
            'token' => $missing,
            'blocklist' => $blocked,
        ];

        foreach ($expected as $reason => $inquiry) {
            $this->assertTrue((bool) $inquiry->is_spam, sprintf('%s is spam.', $inquiry->name));
            $this->assertSame($reason, DB::table('contact_inquiries')->where('id', $inquiry->getKey())->value('spam_reason'), $inquiry->name);
            $this->assertSame(InquiryRoutingStatus::NotApplicable, $inquiry->routing_status);
        }

        $this->assertTrue((bool) $forged->is_spam);
        $this->assertSame('token', DB::table('contact_inquiries')->where('id', $forged->getKey())->value('spam_reason'), 'A forged token is a token failure.');

        // A clean control passes every signal.
        $clean = $this->submitInquiry(['name' => 'Clean Visitor', 'message' => 'We need a quotation for an inventory system, thank you.']);
        $this->assertFalse((bool) $clean->is_spam);
        $this->assertNull(DB::table('contact_inquiries')->where('id', $clean->getKey())->value('spam_reason'));
        $this->assertGreaterThanOrEqual(3, (int) DB::table('contact_inquiries')->where('id', $clean->getKey())->value('filled_in_seconds'));
    }

    public function test_53_not_spam_clears_the_flags_and_routing_then_succeeds(): void
    {
        $this->registerFakeTarget();
        $admin = $this->actAsSuperAdmin();

        $inquiry = $this->submitInquiry([
            'inquiry_type' => InquiryType::Service->value,
            'name' => 'False Positive Visitor',
            SpamGuard::HONEYPOT_FIELD => 'autofilled by a password manager',
        ]);

        $this->assertTrue((bool) $inquiry->is_spam);
        $this->assertSame(InquiryRoutingStatus::NotApplicable, $inquiry->routing_status);
        $this->assertNull($inquiry->routed_id);

        $this->actingAs($admin);
        $this->inquiries()->markNotSpam($inquiry->fresh());

        $fresh = $inquiry->fresh();
        $this->assertFalse((bool) $fresh->is_spam);
        $this->assertNull(DB::table('contact_inquiries')->where('id', $inquiry->getKey())->value('spam_reason'));
        $this->assertSame('crm_lead', $fresh->routing_target);
        $this->assertSame(InquiryRoutingStatus::Routed, $fresh->routing_status, 'Routing was re-queued and succeeded.');
        $this->assertNotNull($fresh->routed_id);
        $this->assertSame(1, FakeInquiryTarget::recordCount('crm_lead'));

        $this->assertCount(1, $this->activitiesSince(0, 'contact_inquiries', 'marked_not_spam')->where('subject_id', $inquiry->getKey()));
    }

    public function test_54_script_tags_are_stripped_on_write_and_escaped_on_render(): void
    {
        $admin = $this->createSuperAdmin();

        $inquiry = $this->submitInquiry([
            'name' => 'Script Visitor',
            'subject' => '<b>Urgent</b> request',
            'message' => '<script>alert(1)</script> Hello, we need a booking system for our hotel group.',
        ]);

        $stored = DB::table('contact_inquiries')->where('id', $inquiry->getKey())->first();
        $this->assertStringNotContainsString('<script', (string) $stored->message);
        $this->assertStringNotContainsString('</script>', (string) $stored->message);
        $this->assertStringContainsString('Hello, we need a booking system for our hotel group.', (string) $stored->message);
        $this->assertSame('Urgent request', (string) $stored->subject);

        // The database is not a trust boundary: a hand-written script still renders as text.
        DB::table('contact_inquiries')->where('id', $inquiry->getKey())->update([
            'message' => '<script>alert("t54-raw")</script> Raw row message',
        ]);

        $html = (string) $this->actingAs($admin)
            ->get(route('admin.contact-inquiries.show', $inquiry->getKey()))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert("t54-raw")</script>', $html, 'Visitor input never renders as HTML.');
        $this->assertStringContainsString(e('<script>alert("t54-raw")</script>'), $html, 'It renders escaped.');

        // Rich text loses event handlers and foreign frames on write.
        $this->actingAs($admin);
        $service = $this->makeService('Sanitised Description Service', [
            'full_description' => '<p>Our process</p><img src="https://cdn.example.test/p.png" onerror="alert(\'t54-onerror\')" alt="Process">'
                .'<iframe src="https://evil.example.test/t54-frame"></iframe><p onclick="alert(2)">Step two</p>',
        ]);

        $description = (string) DB::table('services')->where('id', $service->getKey())->value('full_description');

        $this->assertStringContainsString('Our process', $description);
        $this->assertStringNotContainsString('onerror', $description);
        $this->assertStringNotContainsString('t54-onerror', $description);
        $this->assertStringNotContainsString('<iframe', $description);
        $this->assertStringNotContainsString('evil.example.test', $description);
        $this->assertStringNotContainsString('onclick', $description);
    }
}
