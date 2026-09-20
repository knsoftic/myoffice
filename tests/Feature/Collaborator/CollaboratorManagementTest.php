<?php

declare(strict_types=1);

namespace Tests\Feature\Collaborator;

use App\Enums\CollaborationType;
use App\Enums\CollaboratorActivityEvent;
use App\Enums\CollaboratorStatus;
use App\Enums\ReferralVisitOutcome;
use App\Enums\UserStatus;
use App\Models\Activity;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorReferralVisit;
use App\Models\User;
use App\Services\Collaborator\CollaboratorActivityService;
use App\Services\Collaborator\CollaboratorCodeService;
use App\Services\Collaborator\CollaboratorOnboardingService;
use App\Services\Collaborator\CollaboratorService;
use App\Services\Collaborator\Exceptions\InvalidStatusTransition;
use App\Services\Collaborator\Exceptions\ReferralCodeLockedException;
use App\Services\Collaborator\Exceptions\ReferralCodeTakenException;
use App\Support\Collaborator\CollaboratorData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Phase 8 collaborators: the screens answer, the permission behind each one bites, the picker stays
 * narrow, and the invariants that decide whether somebody earns hold through the services.
 *
 * This is the integration gate, not the phase's full acceptance suite (phase-08-09 §11, FT-C01 … FT-C29),
 * which is still owed.
 */
final class CollaboratorManagementTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** Every collaborator screen, with the permission its route really checks. */
    private const ADMIN_SCREENS = [
        ['admin.collaborators.index', 'collaborators.view_any'],
        ['admin.collaborators.create', 'collaborators.create'],
        ['admin.collaborators.pending', 'collaborators.approve'],
    ];

    #[Test]
    public function every_collaborator_screen_answers_for_a_super_admin(): void
    {
        $super = $this->createSuperAdmin();

        foreach (self::ADMIN_SCREENS as [$route, $permission]) {
            $this->actingAs($super)
                ->get(route($route))
                ->assertOk(sprintf('%s did not answer for a Super Admin.', $route));
        }
    }

    #[Test]
    public function a_collaborator_screen_is_refused_without_its_permission_and_opens_with_it(): void
    {
        foreach (self::ADMIN_SCREENS as [$route, $permission]) {
            $this->actingAs($this->createUserWithPermissions([]))
                ->get(route($route))
                ->assertForbidden(sprintf('%s opened without %s.', $route, $permission));

            $this->actingAs($this->createUserWithPermissions([$permission, 'collaborators.view_any']))
                ->get(route($route))
                ->assertOk(sprintf('%s stayed shut for a holder of %s.', $route, $permission));
        }
    }

    #[Test]
    public function the_record_screens_answer_and_respect_their_permissions(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $collaborator = $this->collaborator();

        foreach ([
            ['admin.collaborators.show', 'collaborators.view'],
            ['admin.collaborators.edit', 'collaborators.edit'],
            ['admin.collaborators.referral-links', 'collaborators.view'],
            ['admin.collaborators.activity', 'collaborators.view_logs'],
        ] as [$route, $permission]) {
            $this->actingAs($this->createUserWithPermissions([]))
                ->get(route($route, $collaborator))
                ->assertForbidden(sprintf('%s opened without %s.', $route, $permission));

            $this->actingAs($this->createUserWithPermissions([$permission, 'collaborators.view_any']))
                ->get(route($route, $collaborator))
                ->assertOk(sprintf('%s stayed shut for a holder of %s.', $route, $permission));
        }
    }

    #[Test]
    public function disabling_the_module_closes_every_collaborator_screen_for_a_super_admin_too(): void
    {
        $super = $this->createSuperAdmin();
        $this->switchModule('collaborators', false);

        $this->actingAs($super)->get(route('admin.collaborators.index'))->assertForbidden();

        $this->switchModule('collaborators', true);

        $this->actingAs($super)->get(route('admin.collaborators.index'))->assertOk();
    }

    #[Test]
    public function a_record_out_of_reach_answers_404_not_403(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $stranger = $this->collaborator();

        // Holds `collaborators.view` but not `view_any`, and the record is not their own, so the row is
        // out of reach. It must not become findable by watching the status code change.
        $this->actingAs($this->createUserWithPermissions(['collaborators.view']))
            ->get(route('admin.collaborators.show', $stranger))
            ->assertNotFound();
    }

    #[Test]
    public function the_picker_returns_five_columns_and_never_a_contact_detail(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $this->collaborator(['name' => 'Findable Partner', 'email' => 'findable@example.test', 'phone' => '0300-0000000']);

        $response = $this->actingAs($this->createUserWithPermissions(['collaborator_referrals.create', 'collaborators.view_any']))
            ->getJson(route('admin.collaborators.options', ['q' => 'Findable']))
            ->assertOk();

        $row = $response->json('data.0');

        $this->assertSame(
            ['id', 'code', 'name', 'company', 'status', 'label', 'needs_confirmation'],
            array_keys($row),
            'The picker returns exactly §9\'s five columns plus the two labels the screen renders.'
        );

        $response->assertDontSee('findable@example.test')->assertDontSee('0300-0000000');
    }

    #[Test]
    public function the_picker_never_offers_a_suspended_or_removed_partner(): void
    {
        $super = $this->createSuperAdmin();
        $this->actingAs($super);

        $suspended = $this->collaborator(['name' => 'Suspended Partner'], CollaboratorStatus::Active);
        app(CollaboratorOnboardingService::class)->changeStatus(
            $suspended, CollaboratorStatus::Suspended, 'Under investigation.', $super
        );

        $removed = $this->collaborator(['name' => 'Removed Partner'], CollaboratorStatus::Active);
        app(CollaboratorService::class)->delete($removed, 'Relationship ended.');

        $codes = $this->actingAs($super)
            ->getJson(route('admin.collaborators.options'))
            ->assertOk()
            ->json('data.*.code');

        $this->assertNotContains($suspended->collaborator_code, $codes, 'A suspension exists to stop new business.');
        $this->assertNotContains($removed->collaborator_code, $codes, 'A removed partner is not offered either.');
    }

    #[Test]
    public function a_collaborator_is_created_pending_and_approved_into_a_login(): void
    {
        $super = $this->createSuperAdmin();

        $this->actingAs($super)
            ->post(route('admin.collaborators.store'), [
                'name' => 'Ahsan Iqbal',
                'company_name' => 'Nova Digital',
                'collaboration_type' => CollaborationType::Agency->value,
                'email' => 'nova@example.test',
                'skills_text' => 'SEO, Google Ads, seo',
            ])
            ->assertRedirect();

        $collaborator = Collaborator::query()->where('email', 'nova@example.test')->firstOrFail();

        $this->assertSame(CollaboratorStatus::Pending, $collaborator->status);
        $this->assertSame($collaborator->collaborator_code, $collaborator->referral_code,
            'The referral code starts equal to the collaborator code.');
        $this->assertSame(2, $collaborator->skills()->count(), 'A repeated skill is one skill.');
        $this->assertNull($collaborator->user_id, 'A pending application has no login.');

        $this->actingAs($super)
            ->post(route('admin.collaborators.approve', $collaborator), ['comment' => 'References checked.'])
            ->assertRedirect();

        $collaborator->refresh();

        $this->assertSame(CollaboratorStatus::Active, $collaborator->status);
        $this->assertNotNull($collaborator->approved_at);
        $this->assertNotNull($collaborator->user_id, 'Approval provisions the login.');

        $account = User::query()->findOrFail($collaborator->user_id);

        $this->assertSame('nova@example.test', $account->email);
        $this->assertTrue($account->must_change_password, 'Nobody here ever sets their password.');
        $this->assertTrue($account->hasRole('Collaborator'));
    }

    #[Test]
    public function a_rejection_needs_a_reason_and_invents_no_fifth_status(): void
    {
        $super = $this->createSuperAdmin();
        $this->actingAs($super);
        $applicant = $this->collaborator(['name' => 'Walk In']);

        $this->actingAs($super)
            ->post(route('admin.collaborators.reject', $applicant), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($super)
            ->post(route('admin.collaborators.reject', $applicant), ['reason' => 'No verifiable track record.'])
            ->assertRedirect();

        $applicant->refresh();

        $this->assertSame(CollaboratorStatus::Inactive, $applicant->status,
            'A refusal lands on inactive — §34 names four states and this is one of them.');
        $this->assertSame('No verifiable track record.', $applicant->status_reason);
        $this->assertNull($applicant->user_id);
    }

    #[Test]
    public function only_an_active_partner_earns_and_the_status_is_the_only_expression_of_it(): void
    {
        $this->actingAs($this->createSuperAdmin());

        foreach ([
            [CollaboratorStatus::Active, true],
            [CollaboratorStatus::Pending, false],
            [CollaboratorStatus::Inactive, false],
            [CollaboratorStatus::Suspended, false],
        ] as [$status, $earns]) {
            $collaborator = $this->collaborator([], $status);

            $this->assertSame($earns, $collaborator->earnsCommission(),
                sprintf('A %s partner should %searn.', $status->value, $earns ? '' : 'not '));
        }

        $this->assertFalse(
            Schema::hasColumn('collaborators', 'commission_eligible'),
            'There is no commission_eligible column: a second expression of the same fact is a second '
            .'thing that can be wrong (INV-C4).'
        );
    }

    #[Test]
    public function a_suspension_locks_the_login_and_ends_the_live_session(): void
    {
        $super = $this->createSuperAdmin();
        $this->actingAs($super);

        $collaborator = $this->collaborator(['email' => 'suspendme@example.test']);
        $collaborator = app(CollaboratorOnboardingService::class)->approve($collaborator, $super);

        $this->assertNotNull($collaborator->user_id);

        DB::table('sessions')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $collaborator->user_id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => time(),
        ]);

        app(CollaboratorOnboardingService::class)->changeStatus(
            $collaborator->fresh(), CollaboratorStatus::Suspended, 'Repeated disputes.', $super
        );

        $account = User::query()->findOrFail($collaborator->user_id);

        $this->assertSame(UserStatus::Inactive, $account->status, 'The login is locked too.');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $account->getKey())->count(),
            'A live session is ended, not left to expire.');
    }

    #[Test]
    public function the_status_table_is_closed(): void
    {
        $super = $this->createSuperAdmin();
        $this->actingAs($super);

        $pending = $this->collaborator();

        $this->expectException(InvalidStatusTransition::class);

        app(CollaboratorOnboardingService::class)->changeStatus(
            $pending, CollaboratorStatus::Suspended, 'No such route.', $super
        );
    }

    #[Test]
    public function a_referral_code_is_free_until_something_names_it(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $codes = app(CollaboratorCodeService::class);
        $collaborator = $this->collaborator();

        $moved = $codes->changeReferralCode($collaborator, ' acme--traders ', 'They print ACME on the flyer.');

        $this->assertSame('ACME-TRADERS', $moved->referral_code, 'The one normaliser applies on the way in.');
        $this->assertFalse($codes->referralCodeIsLocked($moved), 'Nothing references it yet, so it is still free.');
    }

    #[Test]
    public function a_used_referral_code_never_changes_again(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $codes = app(CollaboratorCodeService::class);
        $collaborator = $this->collaborator();

        $visit = new CollaboratorReferralVisit;
        $visit->forceFill([
            'visit_token' => (string) Str::ulid(),
            'collaborator_id' => $collaborator->getKey(),
            'referral_code' => $collaborator->referral_code,
            'outcome' => ReferralVisitOutcome::Captured->value,
            'landing_url' => 'https://example.test/admission',
            'landing_path' => '/admission',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addDays(30),
        ])->save();

        $this->assertTrue($codes->referralCodeIsLocked($collaborator->fresh()));

        $this->expectException(ReferralCodeLockedException::class);

        $codes->changeReferralCode($collaborator->fresh(), 'TOOLATE', 'Second thoughts.');
    }

    #[Test]
    public function a_taken_code_is_refused_without_naming_its_holder(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $holder = $this->collaborator(['name' => 'Acme Traders']);
        app(CollaboratorCodeService::class)->changeReferralCode($holder, 'ACME', 'Their brand.');

        try {
            app(CollaboratorCodeService::class)->assertAvailable('acme');
            $this->fail('A taken code was offered as available.');
        } catch (ReferralCodeTakenException $e) {
            $message = implode(' ', $e->errors()['referral_code']);

            $this->assertStringContainsString('ACME', $message);
            $this->assertStringNotContainsString('Acme Traders', $message,
                'The refusal must not turn this form into a directory of every partner.');
        }
    }

    #[Test]
    public function the_referral_url_keeps_an_existing_query_string(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $codes = app(CollaboratorCodeService::class);
        $collaborator = $this->collaborator();

        $url = $codes->referralUrl($collaborator, '/courses/php-basics?utm_source=flyer');

        $this->assertStringContainsString('utm_source=flyer', $url);
        $this->assertStringContainsString('&ref='.$collaborator->referral_code, $url,
            'A campaign link gains the referral alongside its own parameters, not instead of them.');
    }

    #[Test]
    public function a_collaborator_is_never_force_deleted(): void
    {
        $super = $this->createSuperAdmin();
        $this->actingAs($super);
        $collaborator = $this->collaborator();

        // The policy refuses anybody it is actually consulted for...
        $this->assertFalse(
            $this->createUserWithPermissions(['collaborators.delete', 'collaborators.view_any'])
                ->can('forceDelete', $collaborator),
            'The policy refuses a hard delete.'
        );

        // ...and the model refuses the Super Admin, whom `Gate::before` never sends to a policy at all.
        // A guarantee with an exception in it is not a guarantee, and the exception here would be the
        // one account whose mistakes nothing else catches.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is never destroyed');

        $collaborator->forceDelete();
    }

    #[Test]
    public function a_delete_is_soft_and_needs_a_reason(): void
    {
        $super = $this->createSuperAdmin();
        $this->actingAs($super);
        $collaborator = $this->collaborator();

        $this->actingAs($super)
            ->delete(route('admin.collaborators.destroy', $collaborator), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($super)
            ->delete(route('admin.collaborators.destroy', $collaborator), ['reason' => 'Relationship ended.'])
            ->assertRedirect();

        $this->assertTrue(Collaborator::withTrashed()->whereKey($collaborator->getKey())->exists(),
            'The record is kept.');
        $this->assertFalse(Collaborator::query()->whereKey($collaborator->getKey())->exists(),
            'It simply leaves the lists.');
    }

    #[Test]
    public function the_activity_feed_renders_only_allowlisted_properties_and_never_a_reason(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $collaborator = $this->collaborator();

        app(CollaboratorActivityService::class)->record(
            CollaboratorActivityEvent::CommissionCreated,
            $collaborator,
            null,
            ['amount' => '1500.00', 'internal_note' => 'never show this'],
            'Because the engine said so.',
        );

        $row = Activity::query()
            ->where('collaborator_id', $collaborator->getKey())
            ->where('event', CollaboratorActivityEvent::CommissionCreated->value)
            ->firstOrFail();

        $properties = $row->properties?->all() ?? [];

        $this->assertArrayHasKey('amount', $properties);
        $this->assertArrayNotHasKey('internal_note', $properties,
            'A key outside the allowlist never reaches the table, let alone the screen.');
        $this->assertArrayNotHasKey('reason', $properties);
        $this->assertSame('Because the engine said so.', $row->reason,
            'The reason lives in its own column, where the audit trail keeps it and the feed cannot read it.');

        $feed = app(CollaboratorActivityService::class)->visibleFeed($collaborator);

        $this->assertCount(1, $feed);
        $this->assertArrayNotHasKey('reason', $feed[0]['properties']);
    }

    #[Test]
    public function a_profile_change_is_stamped_with_the_collaborator_it_is_about(): void
    {
        $super = $this->createSuperAdmin();
        $this->actingAs($super);

        $collaborator = $this->collaborator();

        $collaborator->withReason('Moved office.')->forceFill(['country' => 'United Arab Emirates'])->save();

        $this->assertTrue(
            Activity::query()->where('collaborator_id', $collaborator->getKey())->where('event', 'updated')->exists(),
            'The feed is keyed on collaborator_id, not the causer — the rows that matter most have a null causer.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function collaborator(array $attributes = [], CollaboratorStatus $status = CollaboratorStatus::Pending): Collaborator
    {
        static $n = 0;
        $n++;

        $data = new CollaboratorData(
            name: (string) ($attributes['name'] ?? 'Partner '.$n),
            collaborationType: CollaborationType::Freelancer,
            companyName: $attributes['company_name'] ?? null,
            email: $attributes['email'] ?? sprintf('partner%d@example.test', $n),
            phone: $attributes['phone'] ?? null,
        );

        $collaborator = app(CollaboratorService::class)->create($data, auth()->user(), active: false);

        if ($status !== CollaboratorStatus::Pending) {
            $collaborator->forceFill(['status' => $status->value])->save();
        }

        return $collaborator->fresh();
    }
}
