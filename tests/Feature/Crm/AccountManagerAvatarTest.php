<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\DataObjects\Crm\ClientData;
use App\DataObjects\Crm\LeadData;
use App\Services\Crm\ClientService;
use App\Services\Crm\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Screens that print a user's avatar must load the columns `User::avatar_url` reads.
 *
 * The client list, the client page and a lead card re-rendered after a board move loaded the account
 * manager or assignee as `id,name[,email]` and then asked for `avatar_url`. Outside production the
 * model is strict, so the page answered 500 as soon as the client had an account manager; in
 * production the avatar silently fell back to nothing.
 */
final class AccountManagerAvatarTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    public function the_client_list_and_page_open_when_the_client_has_an_account_manager(): void
    {
        $super = $this->createSuperAdmin();
        $manager = $this->createUserWithRole('Sales Executive', ['name' => 'Maria Manager']);
        $this->actingAs($super);

        $client = app(ClientService::class)->create(new ClientData(name: 'Managed Client Ltd'));
        $client->forceFill(['account_manager_id' => $manager->getKey()])->save();

        $this->actingAs($super)->get(route('admin.clients.index'))
            ->assertOk()
            ->assertSee('Maria Manager');

        $this->actingAs($super)->get(route('admin.clients.show', $client))
            ->assertOk()
            ->assertSee('Maria Manager');
    }

    #[Test]
    public function a_lead_moved_on_the_board_comes_back_as_a_card_with_its_assignee(): void
    {
        $super = $this->createSuperAdmin();
        $owner = $this->createUserWithRole('Sales Executive', ['name' => 'Omar Owner']);
        $this->actingAs($super);

        $lead = app(LeadService::class)->create(new LeadData(
            name: 'Board Prospect',
            email: 'board@example.test',
            assignedTo: (int) $owner->getKey(),
        ));

        $this->actingAs($super)
            ->patchJson(route('admin.leads.board.move', $lead), [
                'to_status' => 'contacted',
                'expected_from_status' => 'new',
                // Contacted asks for the next follow-up.
                'follow_up' => ['type' => 'call', 'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i')],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Board Prospect moved to Contacted.')
            ->assertJson(fn ($json) => $json->where('card_html', fn ($html) => str_contains((string) $html, 'Omar Owner'))->etc());
    }
}
