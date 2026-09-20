<?php

declare(strict_types=1);

namespace Tests\Feature\Collaborator;

use App\Enums\CollaborationType;
use App\Enums\CollaboratorStatus;
use App\Enums\ReferralCandidateChannel;
use App\Enums\ReferralConversionSubject;
use App\Enums\ReferralVisitOutcome;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorReferralVisit;
use App\Models\User;
use App\Services\Collaborator\CollaboratorService;
use App\Services\Collaborator\ReferralAttributionResolver;
use App\Services\Collaborator\ReferralLinkService;
use App\Services\Collaborator\ReferralTrackingService;
use App\Support\Collaborator\CollaboratorData;
use App\Support\Collaborator\ReferralResolutionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Phase 9 referral capture: the middleware notices a link, the register keeps every refusal as
 * evidence, the browser never carries a code, and the precedence ladder can be explained afterwards.
 *
 * This is the integration gate, not the phase's full acceptance suite (phase-08-09 §11, FT-R01 … FT-R15),
 * which is still owed.
 */
final class ReferralCaptureTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    /*
    |--------------------------------------------------------------------------
    | The middleware
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function following_a_referral_link_records_a_visit_and_sets_the_carriers(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $partner = $this->partner();
        auth()->logout();

        $this->withHeader('User-Agent', self::BROWSER)
            ->get('/?ref='.$partner->referral_code)
            ->assertOk()
            ->assertCookie(ReferralLinkService::COOKIE);

        $visit = CollaboratorReferralVisit::query()->firstOrFail();

        $this->assertSame(ReferralVisitOutcome::Captured, $visit->outcome);
        $this->assertSame((int) $partner->getKey(), (int) $visit->collaborator_id);
        $this->assertSame($visit->visit_token, session(ReferralLinkService::SESSION_KEY));
    }

    #[Test]
    public function the_browser_never_carries_the_referral_code_itself(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $partner = $this->partner();
        auth()->logout();

        $response = $this->withHeader('User-Agent', self::BROWSER)->get('/?ref='.$partner->referral_code);

        $cookie = collect($response->headers->getCookies())
            ->firstWhere(static fn ($c): bool => $c->getName() === ReferralLinkService::COOKIE);

        $this->assertNotNull($cookie);
        $this->assertStringNotContainsString($partner->referral_code, (string) $cookie->getValue(),
            'The cookie carries an opaque visit token, never the code (INV-R2).');
        $this->assertTrue($cookie->isHttpOnly(), 'Script has no business reading it.');
        $this->assertSame('lax', $cookie->getSameSite());
    }

    #[Test]
    public function a_page_without_a_referral_parameter_records_nothing(): void
    {
        $this->withHeader('User-Agent', self::BROWSER)->get('/')->assertOk();

        $this->assertSame(0, CollaboratorReferralVisit::query()->count());
    }

    #[Test]
    public function an_unknown_code_still_leaves_evidence(): void
    {
        $this->withHeader('User-Agent', self::BROWSER)->get('/?ref=COL-GONE')->assertOk();

        $visit = CollaboratorReferralVisit::query()->firstOrFail();

        $this->assertSame(ReferralVisitOutcome::InvalidCode, $visit->outcome);
        $this->assertNull($visit->collaborator_id);
        $this->assertSame('COL-GONE', $visit->referral_code,
            '"People are still using a dead code" is only reportable if the row exists.');
    }

    #[Test]
    public function a_second_page_is_the_same_visit_and_a_different_partner_is_a_new_one(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $one = $this->partner();
        $two = $this->partner();
        auth()->logout();

        $this->withHeader('User-Agent', self::BROWSER)->get('/?ref='.$one->referral_code)->assertOk();
        $this->withHeader('User-Agent', self::BROWSER)->get('/contact?ref='.$one->referral_code)->assertOk();

        $this->assertSame(1, CollaboratorReferralVisit::query()->count(), 'One row per visitor-code pair.');
        $this->assertSame(2, CollaboratorReferralVisit::query()->firstOrFail()->visits_count);

        $this->withHeader('User-Agent', self::BROWSER)->get('/?ref='.$two->referral_code)->assertOk();

        $this->assertSame(2, CollaboratorReferralVisit::query()->count(),
            'A different partner is a second visit with a token of its own — uq_crv_token is unique.');
    }

    #[Test]
    public function a_crawler_is_recorded_and_never_attributes(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $partner = $this->partner();
        auth()->logout();

        $this->withHeader('User-Agent', 'Googlebot/2.1 (+http://www.google.com/bot.html)')
            ->get('/?ref='.$partner->referral_code)->assertOk();

        $visit = CollaboratorReferralVisit::query()->firstOrFail();

        $this->assertSame(ReferralVisitOutcome::BotFiltered, $visit->outcome);
        $this->assertFalse($visit->isAttributable());
    }

    /*
    |--------------------------------------------------------------------------
    | The public validator
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_public_validator_confirms_a_code_and_names_nothing_else(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $partner = $this->partner(['name' => 'Ali Traders', 'email' => 'ali@traders.test', 'phone' => '0300-1112223']);
        auth()->logout();

        $response = $this->postJson(route('site.referral.validate'), ['code' => strtolower($partner->referral_code)])
            ->assertOk()
            ->assertJson(['valid' => true, 'code' => $partner->referral_code, 'collaborator_name' => 'Ali Traders']);

        $response->assertDontSee('ali@traders.test')->assertDontSee('0300-1112223');

        $this->assertSame(
            ['valid', 'code', 'collaborator_name'],
            array_keys($response->json()),
            'Never an id, a status, a rate or a balance.'
        );
    }

    #[Test]
    public function a_dead_code_and_a_suspended_partner_answer_identically(): void
    {
        $super = $this->createSuperAdmin();
        $this->actingAs($super);
        $suspended = $this->partner([], CollaboratorStatus::Suspended);
        auth()->logout();

        $dead = $this->postJson(route('site.referral.validate'), ['code' => 'COL-GONE'])->assertOk()->json();
        $off = $this->postJson(route('site.referral.validate'), ['code' => $suspended->referral_code])->assertOk()->json();

        $this->assertFalse($dead['valid']);
        $this->assertFalse($off['valid']);
        $this->assertSame($dead['message'], $off['message'],
            'Distinguishing them would turn the endpoint into a way to find out who has been suspended.');
    }

    /*
    |--------------------------------------------------------------------------
    | The ladder
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_staff_pick_outranks_a_captured_code_and_the_loser_is_kept(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $captured = $this->partner();
        $picked = $this->partner();
        $visit = $this->visit($captured);

        $decision = app(ReferralAttributionResolver::class)->resolve(new ReferralResolutionContext(
            request: $this->request(cookies: [ReferralLinkService::COOKIE => $visit->visit_token]),
            staffCollaboratorId: (int) $picked->getKey(),
        ));

        $this->assertSame((int) $picked->getKey(), $decision->winnerId());
        $this->assertSame(ReferralCandidateChannel::StaffSelection, $decision->channel);
        $this->assertCount(1, $decision->losers);
        $this->assertSame((int) $captured->getKey(), $decision->losers[0]->collaboratorId());
        $this->assertTrue($decision->overrideReasonRequired, 'Overruling a captured code needs a reason.');
    }

    #[Test]
    public function staff_choosing_nobody_wins_over_a_captured_code(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $captured = $this->partner();
        $visit = $this->visit($captured);

        $decision = app(ReferralAttributionResolver::class)->resolve(new ReferralResolutionContext(
            request: $this->request(cookies: [ReferralLinkService::COOKIE => $visit->visit_token]),
            staffChoseNone: true,
        ));

        $this->assertFalse($decision->hasWinner(), 'No referral row is created at all.');
        $this->assertCount(1, $decision->losers, 'The captured candidate is kept as evidence.');
        $this->assertStringContainsString('no referring collaborator', (string) $decision->rejectionReason);
    }

    #[Test]
    public function a_suspended_partner_cannot_be_picked_even_by_staff(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $suspended = $this->partner([], CollaboratorStatus::Suspended);

        $decision = app(ReferralAttributionResolver::class)->resolve(new ReferralResolutionContext(
            request: $this->request(),
            staffCollaboratorId: (int) $suspended->getKey(),
        ));

        $this->assertFalse($decision->hasWinner());
        $this->assertStringContainsString('suspension exists', (string) $decision->rejectionReason);
    }

    #[Test]
    public function a_forged_token_names_nobody(): void
    {
        $decision = app(ReferralAttributionResolver::class)->resolve(new ReferralResolutionContext(
            request: $this->request(cookies: [ReferralLinkService::COOKIE => (string) Str::ulid()]),
        ));

        $this->assertFalse($decision->hasWinner(),
            'A forged value can at worst name a visit that does not exist (INV-R2).');
        $this->assertSame([], $decision->losers);
    }

    #[Test]
    public function an_expired_visit_wins_nothing_and_says_so_on_the_register(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $partner = $this->partner();
        $visit = $this->visit($partner);

        $visit->forceFill([
            'first_seen_at' => now()->subDays(60),
            'last_seen_at' => now()->subDays(60),
            'expires_at' => now()->subDay(),
        ])->save();

        $decision = app(ReferralAttributionResolver::class)->resolve(new ReferralResolutionContext(
            request: $this->request(cookies: [ReferralLinkService::COOKIE => $visit->visit_token]),
        ));

        $this->assertFalse($decision->hasWinner());
        $this->assertSame(ReferralVisitOutcome::Expired, $visit->fresh()->outcome);
    }

    #[Test]
    public function the_effective_date_is_never_before_the_click_and_never_in_the_future(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $partner = $this->partner();
        $visit = $this->visit($partner);
        $visit->forceFill(['first_seen_at' => now()->subDays(5), 'last_seen_at' => now()->subDays(5)])->save();

        $resolver = app(ReferralAttributionResolver::class);

        $backdated = $resolver->resolve(new ReferralResolutionContext(
            request: $this->request(cookies: [ReferralLinkService::COOKIE => $visit->visit_token]),
            subjectDate: now()->subDays(30),
        ));

        $this->assertSame(now()->subDays(5)->toDateString(), $backdated->effectiveFrom->toDateString(),
            'A back-dated admission never credits a partner from before their link was used.');

        $future = $resolver->resolve(new ReferralResolutionContext(
            request: $this->request(cookies: [ReferralLinkService::COOKIE => $visit->visit_token]),
            subjectDate: now()->addDays(10),
        ));

        $this->assertSame(now()->toDateString(), $future->effectiveFrom->toDateString());
    }

    #[Test]
    public function the_decision_payload_carries_codes_and_channels_and_no_money(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $captured = $this->partner();
        $picked = $this->partner();
        $visit = $this->visit($captured);

        $payload = app(ReferralAttributionResolver::class)->resolve(new ReferralResolutionContext(
            request: $this->request(cookies: [ReferralLinkService::COOKIE => $visit->visit_token]),
            subject: ReferralConversionSubject::Lead,
            subjectId: 418,
            staffCollaboratorId: (int) $picked->getKey(),
            overrideReason: 'The student named the partner at the desk.',
        ))->toArray();

        $this->assertSame((int) $picked->getKey(), $payload['winner']['collaborator_id']);
        $this->assertSame('manual_selection', $payload['winner']['source']);
        $this->assertSame((int) $captured->getKey(), $payload['losers'][0]['collaborator_id']);
        $this->assertSame(['type' => 'lead', 'id' => 418], $payload['subject']);
        $this->assertSame('The student named the partner at the desk.', $payload['override_reason']);

        $json = strtolower(json_encode($payload));
        $this->assertStringNotContainsString('amount', $json);
        $this->assertStringNotContainsString('"rate"', $json);
    }

    /*
    |--------------------------------------------------------------------------
    | The register
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_register_and_the_report_answer_for_their_permissions(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $partner = $this->partner();
        $this->visit($partner);

        foreach ([
            ['admin.referral-visits.index', 'collaborator_referral_visits.view_any'],
            ['admin.referral-visits.report', 'collaborator_referral_visits.view_reports'],
        ] as [$route, $permission]) {
            $this->actingAs($this->createUserWithPermissions([]))
                ->get(route($route))
                ->assertForbidden();

            $this->actingAs($this->createUserWithPermissions([$permission]))
                ->get(route($route))
                ->assertOk();
        }
    }

    #[Test]
    public function the_ip_is_masked_without_view_logs(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $partner = $this->partner();
        $visit = $this->visit($partner);
        $visit->forceFill(['ip_address' => '203.0.113.77'])->save();

        $this->actingAs($this->createUserWithPermissions(['collaborator_referral_visits.view_any']))
            ->get(route('admin.referral-visits.index'))
            ->assertOk()
            ->assertSee('203.0.113.0/24')
            ->assertDontSee('203.0.113.77');

        $this->actingAs($this->createUserWithPermissions([
            'collaborator_referral_visits.view_any',
            'collaborator_referral_visits.view_logs',
        ]))
            ->get(route('admin.referral-visits.index'))
            ->assertOk()
            ->assertSee('203.0.113.77');
    }

    #[Test]
    public function a_conversion_is_stamped_once(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $partner = $this->partner();
        $visit = $this->visit($partner);

        $tracking = app(ReferralTrackingService::class);

        $tracking->markConverted($visit, ReferralConversionSubject::Lead, 41);
        $first = $visit->fresh()->converted_at->toDateTimeString();

        $tracking->markConverted($visit->fresh(), ReferralConversionSubject::Client, 9);
        $after = $visit->fresh();

        $this->assertSame($first, $after->converted_at->toDateTimeString(),
            'Re-stamping would move a conversion into the wrong month.');
        $this->assertStringContainsString('client #9', (string) $after->outcome_detail);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function partner(array $attributes = [], CollaboratorStatus $status = CollaboratorStatus::Active): Collaborator
    {
        static $n = 0;
        $n++;

        $collaborator = app(CollaboratorService::class)->create(new CollaboratorData(
            name: (string) ($attributes['name'] ?? 'Partner '.$n),
            collaborationType: CollaborationType::Freelancer,
            email: $attributes['email'] ?? sprintf('referral%d@example.test', $n),
            phone: $attributes['phone'] ?? null,
        ), User::query()->first());

        $collaborator->forceFill(['status' => $status->value])->save();

        return $collaborator->fresh();
    }

    private function visit(Collaborator $partner): CollaboratorReferralVisit
    {
        $visit = new CollaboratorReferralVisit;
        $visit->forceFill([
            'visit_token' => (string) Str::ulid(),
            'collaborator_id' => $partner->getKey(),
            'referral_code' => $partner->referral_code,
            'outcome' => ReferralVisitOutcome::Captured->value,
            'landing_url' => 'https://example.test/admission',
            'landing_path' => '/admission',
            'ip_address' => '203.0.113.7',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addDays(30),
        ])->save();

        return $visit->fresh();
    }

    /**
     * @param  array<string, string>  $cookies
     * @param  array<string, mixed>  $post
     */
    private function request(array $cookies = [], array $post = []): Request
    {
        $request = Request::create('/admission', $post === [] ? 'GET' : 'POST', $post, $cookies);
        $request->headers->set('User-Agent', self::BROWSER);

        return $request;
    }
}
