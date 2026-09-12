<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\PanelType;
use App\Enums\UserStatus;
use App\Models\Activity;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-01 §1.7, §10 "Audit": changing a user's role writes an activity row with old and new values
 * plus IP, and a module toggle writes an entry with a reason.
 *
 * Role ↔ user and role ↔ permission are pivot writes, which fire no model event — so without the
 * explicit audit rows in UserService / RoleService the most security-relevant changes in the system
 * would leave no trace at all. These tests are what prove those rows exist, carry both values, and
 * name who did it from where.
 *
 * Every test calls `treatRequestsAsWeb()` first: PHP_SAPI is `cli` under PHPUnit, which the context
 * helpers deliberately read as "console" so a seeder never stamps 127.0.0.1 on every row it writes.
 */
final class AuditTrailTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        $this->treatRequestsAsWeb();
        $this->withHeader('User-Agent', self::BROWSER);
    }

    /*
    |--------------------------------------------------------------------------
    | User roles
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function changing_a_users_roles_records_the_old_and_the_new_set(): void
    {
        $actor = $this->createSuperAdmin();

        $from = Role::query()->where('name', 'Support Agent')->firstOrFail();
        $to = Role::query()->where('name', 'Receptionist')->firstOrFail();

        $target = User::factory()->create();
        $target->assignRole($from);
        $this->forgetPermissionCache();

        $this->actingAs($actor)
            ->from('/admin/users/'.$target->getKey().'/edit')
            ->put('/admin/users/'.$target->getKey(), $this->userPayload($target, [$to->getKey()]))
            ->assertSessionHasNoErrors();

        $entry = $this->latest('User roles updated');

        $this->assertNotNull($entry, 'A role change must write its own activity row.');

        $this->assertSame(['Support Agent'], $entry->oldValues()['roles'] ?? null, 'The old role set is missing.');
        $this->assertSame(['Receptionist'], $entry->newValues()['roles'] ?? null, 'The new role set is missing.');

        $properties = $entry->properties->toArray();
        $this->assertSame(['Receptionist'], $properties['added'] ?? null);
        $this->assertSame(['Support Agent'], $properties['removed'] ?? null);

        // Who, from where, on what.
        $this->assertSame($actor->getKey(), $entry->causer_id);
        $this->assertSame($actor->getMorphClass(), $entry->causer_type);
        $this->assertSame($target->getKey(), $entry->subject_id);
        $this->assertSame('127.0.0.1', $entry->ip_address);
        $this->assertSame('desktop', $entry->device);
        $this->assertSame(self::BROWSER, $entry->user_agent);
        $this->assertSame('users', $entry->module);
        $this->assertStringContainsString('granted Receptionist', (string) $entry->reason);
        $this->assertStringContainsString('revoked Support Agent', (string) $entry->reason);
    }

    #[Test]
    public function granting_a_role_to_a_brand_new_account_records_an_empty_old_set(): void
    {
        $actor = $this->createSuperAdmin();
        $role = Role::query()->where('name', 'Support Agent')->firstOrFail();

        $this->actingAs($actor)
            ->from('/admin/users/create')
            ->post('/admin/users', [
                'name' => 'Brand New',
                'email' => 'brand.new@example.test',
                'password' => 'Str0ng!Passw0rd',
                'password_confirmation' => 'Str0ng!Passw0rd',
                'status' => UserStatus::Active->value,
                'roles' => [$role->getKey()],
            ])
            ->assertSessionHasNoErrors();

        $entry = $this->latest('User roles updated');

        $this->assertNotNull($entry);
        $this->assertSame([], $entry->oldValues()['roles'] ?? null);
        $this->assertSame(['Support Agent'], $entry->newValues()['roles'] ?? null);
        $this->assertSame($actor->getKey(), $entry->causer_id);
        $this->assertSame('127.0.0.1', $entry->ip_address);
    }

    #[Test]
    public function a_save_that_changes_no_role_writes_no_role_audit_row(): void
    {
        $actor = $this->createSuperAdmin();
        $role = Role::query()->where('name', 'Support Agent')->firstOrFail();

        $target = User::factory()->create();
        $target->assignRole($role);
        $this->forgetPermissionCache();

        $before = Activity::query()->where('description', 'User roles updated')->count();

        $this->actingAs($actor)
            ->put('/admin/users/'.$target->getKey(), $this->userPayload($target, [$role->getKey()], ['name' => 'Renamed Only']))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $before,
            Activity::query()->where('description', 'User roles updated')->count(),
            'An unchanged role set must not add noise to the audit trail.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Role permission matrix
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function changing_a_roles_permissions_records_the_old_and_the_new_matrix(): void
    {
        $actor = $this->createSuperAdmin();
        $role = $this->createRoleWithPermissions(['users.view_any'], PanelType::Admin, 60);

        $this->actingAs($actor)
            ->from('/admin/roles/'.$role->getKey().'/edit')
            ->put('/admin/roles/'.$role->getKey(), [
                'name' => $role->name,
                'panel' => PanelType::Admin->value,
                'level' => 60,
                'permissions' => ['users.view_any', 'users.view'],
                'reason' => 'Needs to open a user record',
            ])
            ->assertSessionHasNoErrors();

        $entry = $this->latest('Role permissions updated');

        $this->assertNotNull($entry, 'A permission matrix change must write its own activity row.');

        $this->assertSame(['users.view_any'], $entry->oldValues()['permissions'] ?? null);
        $this->assertSame(['users.view', 'users.view_any'], $entry->newValues()['permissions'] ?? null);
        $this->assertSame(['users.view'], $entry->properties->toArray()['added'] ?? null);

        $this->assertSame($actor->getKey(), $entry->causer_id);
        $this->assertSame('roles', $entry->module);
        $this->assertSame('127.0.0.1', $entry->ip_address);
        $this->assertSame('Needs to open a user record', $entry->reason);
    }

    #[Test]
    public function deleting_a_role_records_what_it_held(): void
    {
        $actor = $this->createSuperAdmin();
        $role = $this->createRoleWithPermissions(['users.view_any'], PanelType::Admin, 60);
        $name = (string) $role->name;

        $this->actingAs($actor)
            ->from('/admin/roles')
            ->delete('/admin/roles/'.$role->getKey())
            ->assertSessionHasNoErrors();

        /*
         * Two rows share this description: the model's own `deleted` event (attribute values) and
         * the explicit row RoleService writes, which is the only one that can know what the role
         * *held* — the pivot fires no model event. The explicit one is the one carrying a reason.
         */
        $this->assertSame(
            2,
            Activity::query()->where('description', 'Role deleted')->count(),
            'Expected both the model event and the service audit row.'
        );

        $entry = Activity::query()
            ->where('description', 'Role deleted')
            ->whereNotNull('reason')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($name, $entry->oldValues()['name'] ?? null);
        $this->assertSame(['users.view_any'], $entry->oldValues()['permissions'] ?? null);
        $this->assertSame('roles', $entry->module);
        $this->assertSame('127.0.0.1', $entry->ip_address);
        $this->assertStringContainsString('deleted with 1 permission(s)', (string) $entry->reason);
    }

    /*
    |--------------------------------------------------------------------------
    | Module toggle
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function disabling_a_module_records_an_entry_with_the_reason(): void
    {
        $actor = $this->createSuperAdmin();
        $module = Module::query()->where('slug', 'projects')->firstOrFail();

        $this->actingAs($actor)
            ->from('/admin/modules')
            ->post('/admin/modules/'.$module->getKey().'/toggle', [
                'enabled' => false,
                'reason' => 'Not selling project work this quarter',
            ])
            ->assertSessionHasNoErrors();

        $entry = $this->latest('Module disabled');

        $this->assertNotNull($entry, 'A module toggle must write an activity row.');
        $this->assertSame('Not selling project work this quarter', $entry->reason);
        $this->assertSame('modules', $entry->module);
        $this->assertSame($actor->getKey(), $entry->causer_id);
        $this->assertSame($module->getKey(), $entry->subject_id);
        $this->assertSame('127.0.0.1', $entry->ip_address);
        $this->assertSame('desktop', $entry->device);

        $this->assertSame(true, $entry->oldValues()['is_enabled'] ?? null);
        $this->assertSame(false, $entry->newValues()['is_enabled'] ?? null);
        $this->assertSame('projects', $entry->properties->toArray()['slug'] ?? null);
    }

    #[Test]
    public function enabling_a_module_records_an_entry_too(): void
    {
        $actor = $this->createSuperAdmin();
        $module = $this->switchModule('projects', false);

        $this->actingAs($actor)
            ->from('/admin/modules')
            ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => true, 'reason' => 'Back in business'])
            ->assertSessionHasNoErrors();

        $entry = $this->latest('Module enabled');

        $this->assertNotNull($entry);
        $this->assertSame('Back in business', $entry->reason);
        $this->assertSame(false, $entry->oldValues()['is_enabled'] ?? null);
        $this->assertSame(true, $entry->newValues()['is_enabled'] ?? null);
    }

    /**
     * Without a reason the service still has to explain itself, so the trail is never blank.
     */
    #[Test]
    public function a_toggle_without_a_reason_records_a_generated_one(): void
    {
        $actor = $this->createSuperAdmin();
        $module = Module::query()->where('slug', 'leads')->firstOrFail();

        $this->actingAs($actor)
            ->from('/admin/modules')
            ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => false]);

        $entry = $this->latest('Module disabled');

        $this->assertNotNull($entry);
        $this->assertNotNull($entry->reason);
        $this->assertStringContainsString('leads', (string) $entry->reason);
    }

    /**
     * Flipping a module to the state it is already in must not write anything (no write, no audit
     * row, no cache churn).
     */
    #[Test]
    public function a_toggle_that_changes_nothing_writes_nothing(): void
    {
        $actor = $this->createSuperAdmin();
        $module = Module::query()->where('slug', 'leads')->firstOrFail();

        $before = Activity::query()->count();

        $this->actingAs($actor)
            ->from('/admin/modules')
            ->post('/admin/modules/'.$module->getKey().'/toggle', ['enabled' => true]);

        $this->assertSame($before, Activity::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Status changes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function suspending_an_account_records_the_reason_and_both_values(): void
    {
        $actor = $this->createSuperAdmin();
        $target = User::factory()->create();

        $this->actingAs($actor)
            ->from('/admin/users/'.$target->getKey())
            ->patch('/admin/users/'.$target->getKey().'/status', [
                'status' => UserStatus::Suspended->value,
                'reason' => 'Repeated policy breaches',
            ])
            ->assertSessionHasNoErrors();

        $entry = Activity::query()
            ->where('subject_type', $target->getMorphClass())
            ->where('subject_id', $target->getKey())
            ->where('reason', 'Repeated policy breaches')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'A status change must be explained in the audit trail.');

        $this->assertSame(UserStatus::Active->value, $entry->oldValues()['status'] ?? null);
        $this->assertSame(UserStatus::Suspended->value, $entry->newValues()['status'] ?? null);
        $this->assertSame($actor->getKey(), $entry->causer_id);
        $this->assertSame('users', $entry->module);
        $this->assertSame('127.0.0.1', $entry->ip_address);
    }

    /*
    |--------------------------------------------------------------------------
    | The log screen
    |--------------------------------------------------------------------------
    */

    /**
     * An entry nobody can read is not an audit trail: the admin screen has to render it.
     */
    #[Test]
    public function the_activity_log_screen_renders_the_recorded_entry(): void
    {
        $actor = $this->createSuperAdmin();
        $module = Module::query()->where('slug', 'projects')->firstOrFail();

        $this->actingAs($actor)->post('/admin/modules/'.$module->getKey().'/toggle', [
            'enabled' => false,
            'reason' => 'Auditable reason for the log screen',
        ]);

        $entry = $this->latest('Module disabled');
        $this->assertNotNull($entry);

        $this->actingAs($actor)->get('/admin/activity-log')->assertOk();
        $this->actingAs($actor)
            ->get('/admin/activity-log/'.$entry->getKey())
            ->assertOk()
            ->assertSee('Auditable reason for the log screen', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function latest(string $description): ?Activity
    {
        return Activity::query()->where('description', $description)->latest('id')->first();
    }

    /**
     * A complete UpdateUserRequest payload for a target, so a test only states what it changes.
     *
     * @param  array<int, int>  $roleIds
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function userPayload(User $target, array $roleIds, array $overrides = []): array
    {
        return array_merge([
            'name' => (string) $target->name,
            'email' => (string) $target->email,
            'status' => $target->status->value,
            'roles' => $roleIds,
        ], $overrides);
    }
}
