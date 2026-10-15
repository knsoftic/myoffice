<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Support\SupportTicket;
use App\Models\Support\TicketDepartment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Saving a support desk through the admin form.
 *
 * Every save used to answer 500: `StoreTicketDepartmentRequest` built its columns in a method called
 * `attributes()`, which FormRequest calls while it is still building the validator, so the method's
 * `validated()` ran against a validator that did not exist yet. No test posted the form, and with no
 * desk no ticket could be raised from any panel.
 */
final class TicketDepartmentSaveTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    public function a_desk_is_created_and_its_slug_is_derived_from_the_name(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->post(route('admin.ticket-departments.store'), $this->payload())
            ->assertRedirect(route('admin.ticket-departments.index'))
            ->assertSessionHasNoErrors();

        $desk = TicketDepartment::query()->where('name', 'General support')->firstOrFail();

        $this->assertSame('general-support', $desk->slug);
        $this->assertSame(['admin', 'student'], array_values((array) $desk->allowed_panels));
        $this->assertSame(60, (int) $desk->sla_first_response_minutes);
    }

    #[Test]
    public function a_desk_is_edited(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)->post(route('admin.ticket-departments.store'), $this->payload());
        $desk = TicketDepartment::query()->where('name', 'General support')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.ticket-departments.update', $desk), $this->payload(['name' => 'Billing', 'slug' => 'billing']))
            ->assertRedirect(route('admin.ticket-departments.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Billing', $desk->fresh()->name);
        $this->assertSame('billing', $desk->fresh()->slug);
    }

    #[Test]
    public function an_invalid_desk_comes_back_with_errors_rather_than_a_server_error(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->from(route('admin.ticket-departments.index'))
            ->post(route('admin.ticket-departments.store'), $this->payload(['allowed_panels' => []]))
            ->assertRedirect(route('admin.ticket-departments.index'))
            ->assertSessionHasErrors('allowed_panels');

        $this->assertSame(0, TicketDepartment::query()->count());
    }

    #[Test]
    public function a_user_without_the_permission_cannot_create_a_desk(): void
    {
        $student = $this->createUserWithRole('Student');

        $this->actingAs($student)
            ->post(route('admin.ticket-departments.store'), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, TicketDepartment::query()->count());
    }

    #[Test]
    public function once_a_desk_exists_a_ticket_can_be_raised(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)->post(route('admin.ticket-departments.store'), $this->payload());
        $desk = TicketDepartment::query()->where('name', 'General support')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.tickets.store'), [
                'ticket_department_id' => $desk->getKey(),
                'priority' => 'medium',
                'subject' => 'The printer in room 2 is jammed',
                'description' => 'It stopped halfway through the fee receipts.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SupportTicket::query()->where('ticket_department_id', $desk->getKey())->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'General support',
            'slug' => '',
            'allowed_panels' => ['admin', 'student'],
            'auto_assign_strategy' => 'none',
            'sla_first_response_minutes' => 60,
            'sla_resolution_minutes' => 1440,
        ], $overrides);
    }
}
