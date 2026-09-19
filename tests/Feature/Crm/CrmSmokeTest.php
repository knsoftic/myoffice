<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\DataObjects\Crm\ClientData;
use App\DataObjects\Crm\LeadData;
use App\Models\Crm\Client;
use App\Models\User;
use App\Services\Crm\ClientService;
use App\Services\Crm\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Phase 5 integration smoke: the CRM screens answer, the permission behind each one is enforced, records
 * are numbered by their service, and the client panel resolves the signed-in user to their own client and
 * nobody else's.
 *
 * This is the integration gate, not the phase's full acceptance suite (phase-05 §11), which is still owed.
 */
final class CrmSmokeTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** Admin screens, each with the permission its route really checks. */
    private const ADMIN_SCREENS = [
        ['admin.leads.index', 'leads.view'],
        ['admin.leads.create', 'leads.create'],
        ['admin.leads.board', 'leads.view'],
        ['admin.leads.follow-ups.index', 'leads.view'],
        ['admin.leads.import.index', 'leads.import'],
        ['admin.clients.index', 'clients.view_any'],
        ['admin.clients.create', 'clients.create'],
    ];

    #[Test]
    public function every_crm_admin_screen_answers_for_a_super_admin(): void
    {
        $super = $this->createSuperAdmin();

        foreach (self::ADMIN_SCREENS as [$route, $permission]) {
            $this->actingAs($super)
                ->get(route($route))
                ->assertOk(sprintf('%s did not answer for a Super Admin.', $route));
        }
    }

    #[Test]
    public function a_crm_screen_is_refused_without_its_permission_and_opens_with_it(): void
    {
        foreach (self::ADMIN_SCREENS as [$route, $permission]) {
            $this->actingAs($this->createUserWithPermissions([]))
                ->get(route($route))
                ->assertForbidden(sprintf('%s opened without %s.', $route, $permission));

            $this->actingAs($this->createUserWithPermissions([$permission]))
                ->get(route($route))
                ->assertOk(sprintf('%s stayed shut for a holder of %s.', $route, $permission));
        }
    }

    #[Test]
    public function the_client_panel_shows_the_signed_in_clients_own_record(): void
    {
        [$user, $client] = $this->portalClient('Own Company Ltd');
        [, $other] = $this->portalClient('Someone Else Ltd');

        // The dashboard answers, and never leaks another client's name into it.
        $this->actingAs($user)->get(route('client.dashboard'))
            ->assertOk()
            ->assertDontSee('Someone Else Ltd');

        // The profile screen is the one that prints the client's own details.
        $this->actingAs($user)->get(route('client.profile.edit'))
            ->assertOk()
            ->assertSee('Own Company Ltd')
            ->assertDontSee('Someone Else Ltd');

        $this->assertNotSame($client->getKey(), $other->getKey());
    }

    #[Test]
    public function a_login_with_no_client_record_cannot_reach_the_client_panel(): void
    {
        $user = $this->createUserWithRole('Client', ['must_change_password' => false]);

        $this->actingAs($user)->get(route('client.dashboard'))->assertForbidden();
    }

    #[Test]
    public function the_service_numbers_a_lead_and_the_board_shows_it(): void
    {
        $super = $this->createSuperAdmin();
        $this->actingAs($super);

        $lead = app(LeadService::class)->create(new LeadData(
            name: 'Numbered Prospect',
            email: 'numbered@example.test',
        ));

        $this->assertNotNull($lead->fresh()->lead_no, 'The service numbers a lead when it is created.');

        $this->actingAs($super)->get(route('admin.leads.board'))
            ->assertOk()
            ->assertSee('Numbered Prospect');
    }

    #[Test]
    public function the_service_numbers_a_client(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $client = app(ClientService::class)->create(new ClientData(name: 'Numbered Client Ltd'));

        $this->assertNotNull($client->fresh()->client_code, 'The service gives a client its code.');
    }

    /**
     * A user bound to a client with the portal switched on — what the panel middleware requires.
     *
     * @return array{0: User, 1: Client}
     */
    private function portalClient(string $name): array
    {
        $user = $this->createUserWithRole('Client', ['must_change_password' => false]);

        $this->actingAs($this->createSuperAdmin());

        $client = app(ClientService::class)->create(new ClientData(
            name: $name,
            companyName: $name,
            email: $user->email,
        ));

        $client->forceFill([
            'user_id' => $user->getKey(),
            'portal_enabled' => true,
            'status' => 'active',
        ])->save();

        return [$user, $client->fresh()];
    }
}
