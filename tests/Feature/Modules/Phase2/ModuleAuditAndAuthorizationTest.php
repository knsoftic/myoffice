<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Phase2;

use App\Events\ModuleStateChanged;
use App\Models\Activity;
use App\Models\Module;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-02 §6 "Module audit" — toggling writes `disabled_at` / `disabled_by` / `disable_reason`
 * and an activity entry carrying the reason — and the "Authorization" matrix for the modules
 * screen: `modules.view_any` reads the list, `modules.view` reads an impact preview,
 * `modules.change_status` flips a switch, and nothing at all is a 403.
 */
final class ModuleAuditAndAuthorizationTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    public function disabling_stamps_when_who_and_why_and_logs_the_reason(): void
    {
        $this->travelTo(now()->setTime(10, 30));

        $admin = $this->createSuperAdmin(['name' => 'Omar Farooq']);
        $module = $this->module('leads');

        $since = (int) (Activity::query()->max('id') ?? 0);

        $this->actingAs($admin)
            ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false, 'reason' => 'Sales team moved to the new CRM'])
            ->assertRedirect();

        $module->refresh();

        $this->assertFalse((bool) $module->is_enabled);
        $this->assertNotNull($module->disabled_at);
        $this->assertTrue($module->disabled_at->equalTo(now()), 'disabled_at is the moment of the switch.');
        $this->assertSame((int) $admin->getKey(), (int) $module->disabled_by);
        $this->assertSame('Sales team moved to the new CRM', $module->disable_reason);

        $entries = Activity::query()
            ->where('id', '>', $since)
            ->where('subject_type', Module::class)
            ->where('subject_id', $module->getKey())
            ->get();

        $this->assertCount(
            1,
            $entries,
            'One switch is exactly one audit entry for the module, never a second copy: '
            .$entries->map(fn (Activity $row): string => (string) $row->description)->implode(' | '),
        );

        $entry = Activity::query()
            ->where('id', '>', $since)
            ->where('module', 'modules')
            ->where('description', 'Module disabled')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'A "Module disabled" activity entry is written.');
        $this->assertSame('Sales team moved to the new CRM', $entry->reason, 'The activity entry carries the reason.');
        $this->assertSame((int) $admin->getKey(), (int) $entry->causer_id);
        $this->assertSame(Module::class, $entry->subject_type);
        $this->assertSame((int) $module->getKey(), (int) $entry->subject_id);
        $this->assertTrue($entry->properties['old']['is_enabled']);
        $this->assertFalse($entry->properties['attributes']['is_enabled']);

        // The card shows who switched it off and why.
        $this->actingAs($admin)
            ->get('/admin/modules')
            ->assertOk()
            ->assertSee('Sales team moved to the new CRM', false);
    }

    #[Test]
    public function re_enabling_clears_the_disable_stamps_and_logs_it(): void
    {
        $admin = $this->createSuperAdmin();
        $module = $this->module('leads');

        $this->actingAs($admin)->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false, 'reason' => 'Paused for the quarter'])->assertRedirect();
        $this->assertFalse((bool) $module->fresh()->is_enabled, 'The disable must have happened before re-enabling means anything.');
        $this->actingAs($admin)->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => true, 'reason' => 'Back in business'])->assertRedirect();

        $module->refresh();

        $this->assertTrue((bool) $module->is_enabled);
        $this->assertNull($module->disabled_at);
        $this->assertNull($module->disabled_by);
        $this->assertNull($module->disable_reason);

        $entry = Activity::query()->where('module', 'modules')->where('description', 'Module enabled')->latest('id')->firstOrFail();
        $this->assertSame('Back in business', $entry->reason);
    }

    /**
     * D63: a module disable requires a reason on the server, not only in the browser's impact dialog
     * (phase-02 §5). This replaces the Phase 2 build's "a generated explanation is written instead",
     * which let a crafted POST switch a module off with no human reason at all.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function missingReasonProvider(): array
    {
        return [
            'no reason key' => [[]],
            'an empty reason' => [['reason' => '']],
            'whitespace only' => [['reason' => '     ']],
            'shorter than five characters' => [['reason' => 'ok']],
            'four characters' => [['reason' => 'test']],
            'over 255 characters' => [['reason' => str_repeat('r', 256)]],
            'not a string' => [['reason' => ['Sales moved']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $reason
     */
    #[DataProvider('missingReasonProvider')]
    #[Test]
    public function a_disable_without_a_usable_reason_is_refused_on_the_server(array $reason): void
    {
        $admin = $this->createSuperAdmin();
        $module = $this->module('leads');
        $since = (int) (Activity::query()->max('id') ?? 0);

        $this->actingAs($admin)
            ->from('/admin/modules')
            ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false] + $reason)
            ->assertRedirect('/admin/modules')
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)
            ->postJson('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false] + $reason)
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $module->refresh();

        $this->assertTrue((bool) $module->is_enabled, 'The module must still be on.');
        $this->assertNull($module->disabled_at);
        $this->assertNull($module->disable_reason);
        $this->assertSame(0, Activity::query()->where('id', '>', $since)->where('module', 'modules')->count(), 'A refused disable writes no audit entry.');
    }

    /**
     * @param  array<string, mixed>  $reason
     */
    #[DataProvider('missingReasonProvider')]
    #[Test]
    public function a_bulk_disable_without_a_usable_reason_is_refused_on_the_server(array $reason): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->from('/admin/modules')
            ->post('/admin/modules/bulk-toggle', ['group' => 'hr', 'enabled' => false] + $reason)
            ->assertRedirect('/admin/modules')
            ->assertSessionHasErrors('reason');

        $this->assertSame(
            0,
            Module::query()->where('group', 'hr')->where('is_enabled', false)->count(),
            'No module in the group may have been switched off.',
        );
    }

    #[Test]
    public function the_service_refuses_an_empty_reason_on_disable(): void
    {
        $module = $this->module('leads');

        foreach ([null, '', '   '] as $reason) {
            try {
                app(ModuleService::class)->toggle($module->fresh(), false, $reason);
                $this->fail('ModuleService disabled a module with reason '.var_export($reason, true).' (D63).');
            } catch (ActionNotAllowedException|\InvalidArgumentException|ValidationException) {
                $this->addToAssertionCount(1);
            }

            $this->assertTrue((bool) $module->fresh()->is_enabled);
        }
    }

    #[Test]
    public function enabling_needs_no_reason(): void
    {
        $admin = $this->createSuperAdmin();
        $module = $this->module('leads');

        $this->actingAs($admin)->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false, 'reason' => 'Paused for the audit'])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $module->fresh()->is_enabled);

        $this->actingAs($admin)->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => true])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $module->fresh()->is_enabled);

        $this->actingAs($admin)
            ->post('/admin/modules/bulk-toggle', ['group' => 'hr', 'enabled' => true])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_state_change_event_carries_the_actor_and_reason(): void
    {
        Event::fake([ModuleStateChanged::class]);

        $admin = $this->createSuperAdmin();
        $module = $this->module('leads');

        $this->actingAs($admin)
            ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false, 'reason' => 'Event check'])
            ->assertRedirect();

        Event::assertDispatched(ModuleStateChanged::class, fn (ModuleStateChanged $event): bool => $event->slug() === 'leads'
            && $event->wasDisabled()
            && $event->reason === 'Event check'
            && $event->actorId === (int) $admin->getKey()
            && $event->cascaded === false);
    }

    #[Test]
    public function a_no_op_toggle_writes_no_audit_row(): void
    {
        $admin = $this->createSuperAdmin();
        $module = $this->module('leads');
        $since = (int) (Activity::query()->max('id') ?? 0);

        $this->actingAs($admin)->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => true])->assertRedirect();

        $this->assertSame(0, Activity::query()->where('id', '>', $since)->where('module', 'modules')->count());
        $this->assertNull($module->fresh()->disabled_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization matrix
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function no_module_permission_is_refused_the_screen_the_preview_and_every_switch(): void
    {
        $nobody = $this->createUserWithPermissions(['dashboard.view']);
        $module = $this->module('leads');

        $this->actingAs($nobody)->get('/admin/modules')->assertForbidden();
        $this->actingAs($nobody)->getJson('/admin/modules/'.$module->getKey().'/impact')->assertForbidden();
        $this->actingAs($nobody)->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false])->assertForbidden();
        $this->actingAs($nobody)->post('/admin/modules/bulk-toggle', ['group' => 'software_house', 'enabled' => false])->assertForbidden();

        $this->assertTrue((bool) $module->fresh()->is_enabled);
    }

    #[Test]
    public function view_any_reads_the_screen_read_only_and_cannot_flip_anything(): void
    {
        $viewer = $this->createUserWithPermissions(['modules.view_any']);
        $module = $this->module('leads');

        $html = (string) $this->actingAs($viewer)->get('/admin/modules')->assertOk()->getContent();

        $this->assertStringNotContainsString('/admin/modules/'.$module->getKey().'/toggle', $html, 'A viewer is offered no switch.');
        $this->assertStringNotContainsString('/admin/modules/bulk-toggle', $html, 'A viewer is offered no bulk action.');

        $this->actingAs($viewer)->getJson('/admin/modules/'.$module->getKey().'/impact')->assertForbidden();
        $this->actingAs($viewer)->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false])->assertForbidden();
        $this->actingAs($viewer)->post('/admin/modules/bulk-toggle', ['group' => 'software_house', 'enabled' => false])->assertForbidden();

        $this->assertTrue((bool) $module->fresh()->is_enabled);
    }

    #[Test]
    public function view_alone_reads_the_impact_preview_only(): void
    {
        $viewer = $this->createUserWithPermissions(['modules.view']);
        $module = $this->module('leads');

        $this->actingAs($viewer)->get('/admin/modules')->assertForbidden();
        $this->actingAs($viewer)->getJson('/admin/modules/'.$module->getKey().'/impact')->assertOk()->assertJsonPath('module.slug', 'leads');
        $this->actingAs($viewer)->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false])->assertForbidden();
    }

    #[Test]
    public function change_status_flips_a_business_module_but_never_a_core_one(): void
    {
        $operator = $this->createUserWithPermissions(['modules.view_any', 'modules.view', 'modules.change_status']);

        $this->actingAs($operator)
            ->post('/admin/modules/'.$this->module('leads')->getKey().'/toggle', ['enabled' => false, 'reason' => 'Sales pipeline paused'])
            ->assertRedirect();

        $this->assertFalse((bool) $this->module('leads')->is_enabled);

        $this->actingAs($operator)
            ->post('/admin/modules/'.$this->module('users')->getKey().'/toggle', ['enabled' => false])
            ->assertForbidden();

        $this->assertTrue((bool) $this->module('users')->is_enabled);
    }

    #[Test]
    public function the_seeded_admin_role_cannot_touch_modules_at_all(): void
    {
        // phase-01 §5: Admin holds everything except modules.*.
        $admin = $this->createUserWithRole('Admin');

        $this->actingAs($admin)->get('/admin/modules')->assertForbidden();
        $this->actingAs($admin)->post('/admin/modules/'.$this->module('leads')->getKey().'/toggle', ['enabled' => false])->assertForbidden();
    }

    private function module(string $slug): Module
    {
        return Module::query()->where('slug', $slug)->firstOrFail();
    }
}
