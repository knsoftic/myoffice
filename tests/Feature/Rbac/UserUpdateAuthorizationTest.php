<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Enums\Ability;
use App\Enums\LoginStatus;
use App\Enums\ModuleGroup;
use App\Enums\UserStatus;
use App\Models\Activity;
use App\Models\LoginHistory;
use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * `PUT /admin/users/{user}` is not a back door.
 *
 * The edit form carries almost every column of the account, which made it a way around three
 * things that are guarded everywhere else:
 *
 *   1. `users.change_status` — the status machinery (`PATCH users/{user}/status`, a mandatory
 *      reason, the audit row, the anti-lockout rule) was side-steppable by posting `status` to the
 *      plain update route with nothing but `users.edit`. Worst case: the only Super Admin suspends
 *      themselves, is signed out, and no other account outranks them to undo it.
 *   2. the password side effects — setting someone's password from the edit form left their stored
 *      sessions and their "remember me" cookie working, so the one action an administrator reaches
 *      for when an account is compromised did not actually lock the attacker out.
 *   3. the log permissions — the profile screen served the account's IP addresses, devices, failed
 *      sign-ins and full change history to anyone holding `users.view`.
 *
 * Each test below fails on the pre-fix code. The rules are enforced twice on purpose (Form Request
 * **and** service), so the service is exercised directly as well — later phases will call it from
 * places that have no Form Request at all.
 */
final class UserUpdateAuthorizationTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** A password that satisfies App\Services\Auth\PasswordPolicy. */
    private const NEW_PASSWORD = 'Str0ng!Passw0rd';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        $this->withoutCompromisedPasswordCheck();
    }

    /*
    |--------------------------------------------------------------------------
    | 1. users.change_status is not reachable through the update path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function users_edit_without_change_status_cannot_suspend_anyone_through_the_update_route(): void
    {
        $actor = $this->editor();
        $target = $this->weakerUser();

        $this->actingAs($actor)
            ->from('/admin/users/'.$target->getKey().'/edit')
            ->put('/admin/users/'.$target->getKey(), $this->payload($target, [
                'status' => UserStatus::Suspended->value,
                'status_reason' => 'Because I said so',
            ]))
            ->assertSessionHasErrors('status');

        $this->assertSame(
            UserStatus::Active,
            $target->fresh()->status,
            'users.edit alone must never be able to take an account offline.'
        );

        $this->assertNull($target->fresh()->status_reason);
    }

    /**
     * The same request from the same actor succeeds when it leaves the status alone — the guard is
     * about the status, not about the form.
     */
    #[Test]
    public function the_update_route_still_saves_the_profile_while_echoing_the_current_status(): void
    {
        $actor = $this->editor();
        $target = $this->weakerUser();

        $this->actingAs($actor)
            ->from('/admin/users/'.$target->getKey().'/edit')
            ->put('/admin/users/'.$target->getKey(), $this->payload($target, ['name' => 'Renamed Only']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/admin/users/'.$target->getKey());

        $this->assertSame('Renamed Only', $target->fresh()->name);
        $this->assertSame(UserStatus::Active, $target->fresh()->status);
    }

    #[Test]
    public function the_status_endpoint_is_refused_without_users_change_status(): void
    {
        $actor = $this->editor();
        $target = $this->weakerUser();

        $this->actingAs($actor)
            ->patch('/admin/users/'.$target->getKey().'/status', [
                'status' => UserStatus::Suspended->value,
                'reason' => 'Because I said so',
            ])
            ->assertForbidden();

        $this->assertSame(UserStatus::Active, $target->fresh()->status);
    }

    /**
     * The one path that works: the dedicated endpoint, with the permission and with a reason.
     */
    #[Test]
    public function the_status_endpoint_works_with_the_permission_and_records_the_reason(): void
    {
        $actor = $this->editor(['users.change_status']);
        $target = $this->weakerUser();

        $this->actingAs($actor)
            ->from('/admin/users/'.$target->getKey())
            ->patch('/admin/users/'.$target->getKey().'/status', [
                'status' => UserStatus::Suspended->value,
                'reason' => 'Contract ended',
            ])
            ->assertSessionHasNoErrors();

        $target = $target->fresh();

        $this->assertSame(UserStatus::Suspended, $target->status);
        $this->assertSame('Contract ended', $target->status_reason);
        $this->assertNotNull($target->status_changed_at);
    }

    /**
     * Both status controls used to spoof PUT at a route registered with `Route::patch()`, so the one
     * legitimate way to change a status answered 405 and the only thing that *did* work was the
     * update-form back door this class closes.
     */
    #[Test]
    public function the_status_controls_spoof_the_method_the_route_actually_accepts(): void
    {
        $actor = $this->editor(['users.change_status']);
        $target = $this->weakerUser();

        $this->actingAs($actor)
            ->put('/admin/users/'.$target->getKey().'/status', [
                'status' => UserStatus::Suspended->value,
                'reason' => 'Spoofing the wrong verb',
            ])
            ->assertStatus(405);

        $this->actingAs($actor)
            ->get('/admin/users/'.$target->getKey())
            ->assertOk()
            ->assertSee('value="PATCH"', false);

        $this->actingAs($actor)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('value="PATCH"', false);
    }

    #[Test]
    public function taking_access_away_still_demands_a_reason(): void
    {
        $actor = $this->editor(['users.change_status']);
        $target = $this->weakerUser();

        $this->actingAs($actor)
            ->from('/admin/users/'.$target->getKey())
            ->patch('/admin/users/'.$target->getKey().'/status', [
                'status' => UserStatus::Suspended->value,
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(UserStatus::Active, $target->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Nobody takes their own account offline
    |--------------------------------------------------------------------------
    */

    /**
     * The lockout case in full: the only Super Admin, who bypasses every policy through
     * `Gate::before`, editing their own row. Nothing weaker than level 1 could ever undo it.
     */
    #[Test]
    public function a_super_admin_cannot_suspend_themselves_through_the_update_route(): void
    {
        $actor = $this->createSuperAdmin();

        $this->actingAs($actor)
            ->from('/admin/users/'.$actor->getKey().'/edit')
            ->put('/admin/users/'.$actor->getKey(), [
                'name' => (string) $actor->name,
                'email' => (string) $actor->email,
                'status' => UserStatus::Suspended->value,
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(
            UserStatus::Active,
            $actor->fresh()->status,
            'Suspending yourself locks the only administrator out of their own system.'
        );
    }

    #[Test]
    public function an_administrator_holding_change_status_still_cannot_suspend_themselves(): void
    {
        $actor = $this->editor(['users.change_status']);

        // Through the update form…
        $this->actingAs($actor)
            ->from('/admin/users/'.$actor->getKey().'/edit')
            ->put('/admin/users/'.$actor->getKey(), [
                'name' => (string) $actor->name,
                'email' => (string) $actor->email,
                'status' => UserStatus::Suspended->value,
            ])
            ->assertSessionHasErrors('status');

        // …and through the dedicated endpoint, where UserPolicy::changeStatus() refuses your own row.
        $this->actingAs($actor)
            ->from('/admin/users/'.$actor->getKey())
            ->patch('/admin/users/'.$actor->getKey().'/status', [
                'status' => UserStatus::Suspended->value,
                'reason' => 'Locking myself out',
            ])
            ->assertForbidden();

        $this->assertSame(UserStatus::Active, $actor->fresh()->status);
    }

    /**
     * Defence in depth: the same invariant without any HTTP layer, because Phase 2+ will call the
     * service from a console command and an importer.
     */
    #[Test]
    public function the_user_service_refuses_a_status_change_on_the_update_path(): void
    {
        $actor = $this->createSuperAdmin();
        $target = $this->weakerUser();

        $this->expectException(ActionNotAllowedException::class);

        app(UserService::class)->update(
            $target,
            ['name' => (string) $target->name, 'status' => UserStatus::Suspended->value],
            $actor,
        );
    }

    #[Test]
    public function the_user_service_never_writes_a_status_even_when_the_payload_carries_one(): void
    {
        $actor = $this->createSuperAdmin();
        $target = $this->weakerUser();

        // Echoing the current status is accepted — and still not written.
        app(UserService::class)->update(
            $target,
            [
                'name' => 'Echoed Status',
                'status' => $target->status->value,
                'status_reason' => 'Should never be stored',
            ],
            $actor,
        );

        $target = $target->fresh();

        $this->assertSame('Echoed Status', $target->name);
        $this->assertSame(UserStatus::Active, $target->status);
        $this->assertNull(
            $target->status_reason,
            'status_reason belongs to changeStatus(); a profile save must not rewrite the audit note.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3. An administrator-set password really does cut the account off
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function setting_a_password_from_the_edit_form_revokes_sessions_and_rotates_the_remember_token(): void
    {
        $actor = $this->editor();
        $target = $this->weakerUser();

        $target->forceFill(['remember_token' => 'stolen-remember-token'])->save();

        $this->createSessionRow($target, 'attackerSessionA');
        $this->createSessionRow($target, 'attackerSessionB');

        $this->actingAs($actor)
            ->from('/admin/users/'.$target->getKey().'/edit')
            ->put('/admin/users/'.$target->getKey(), $this->payload($target, [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]))
            ->assertSessionHasNoErrors();

        $target = $target->fresh();

        $this->assertSame(
            0,
            DB::table('sessions')->where('user_id', $target->getKey())->count(),
            'A compromised session must not survive the password change meant to kill it.'
        );

        $this->assertNotSame(
            'stolen-remember-token',
            (string) $target->remember_token,
            'A stale remember_me cookie must stop working too.'
        );

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $target->password));
        $this->assertNotNull($target->password_changed_at);
    }

    #[Test]
    public function the_temporary_password_button_revokes_sessions_and_rotates_the_remember_token(): void
    {
        $actor = $this->editor();
        $target = $this->weakerUser();

        $target->forceFill(['remember_token' => 'stolen-remember-token'])->save();
        $this->createSessionRow($target, 'attackerSessionC');

        $this->actingAs($actor)
            ->from('/admin/users/'.$target->getKey())
            ->post('/admin/users/'.$target->getKey().'/reset-password', ['reason' => 'Account compromised'])
            ->assertSessionHasNoErrors();

        $target = $target->fresh();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->getKey())->count());
        $this->assertNotSame('stolen-remember-token', (string) $target->remember_token);
        $this->assertTrue((bool) $target->must_change_password);
    }

    /**
     * Setting your *own* password here is refused, exactly as the Reset-password button already
     * refuses it: /account/password confirms the current password first, and a password write from
     * this form revokes the sessions of the person making it.
     */
    #[Test]
    public function an_administrator_cannot_set_their_own_password_from_the_edit_form(): void
    {
        $actor = $this->editor();

        $this->actingAs($actor)
            ->from('/admin/users/'.$actor->getKey().'/edit')
            ->put('/admin/users/'.$actor->getKey(), [
                'name' => (string) $actor->name,
                'email' => (string) $actor->email,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasErrors('password');

        $this->assertFalse(
            Hash::check(self::NEW_PASSWORD, (string) $actor->fresh()->password),
            'Your own password belongs to /account/password, which confirms the current one.'
        );
    }

    #[Test]
    public function the_user_service_refuses_a_self_password_write(): void
    {
        $actor = $this->createSuperAdmin();

        $this->expectException(ActionNotAllowedException::class);

        app(UserService::class)->update(
            $actor,
            ['name' => (string) $actor->name, 'password' => self::NEW_PASSWORD],
            $actor,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 4. The profile screen is not a log viewer
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function users_view_alone_renders_no_login_history_and_no_audit_rows(): void
    {
        $actor = $this->editor();
        $target = $this->weakerUser();

        $this->recordSignIn($target);
        $this->recordAuditRow($target);

        $response = $this->actingAs($actor)->get('/admin/users/'.$target->getKey());

        $response->assertOk();

        // The whole section is gone, not an empty table.
        $response->assertDontSee('Recent sign-ins');
        $response->assertDontSee('Account audit trail');

        // And none of the data those sections carry leaks another way.
        $response->assertDontSee('203.0.113.44');
        $response->assertDontSee('Suspicious audit description');
    }

    #[Test]
    public function the_log_permissions_are_what_reveal_the_two_sections(): void
    {
        $actor = $this->editor(['login_history.view_logs', 'activity_log.view_logs']);
        $target = $this->weakerUser();

        $this->recordSignIn($target);
        $this->recordAuditRow($target);

        $response = $this->actingAs($actor)->get('/admin/users/'.$target->getKey());

        $response->assertOk();
        $response->assertSee('Recent sign-ins');
        $response->assertSee('Account audit trail');
        $response->assertSee('203.0.113.44');
        $response->assertSee('Suspicious audit description');
    }

    /**
     * Each section is gated by its own ability, not by a shared "logs" flag.
     */
    #[Test]
    public function login_history_and_the_audit_trail_are_gated_separately(): void
    {
        $target = $this->weakerUser();

        $this->recordSignIn($target);
        $this->recordAuditRow($target);

        $loginsOnly = $this->editor(['login_history.view_logs']);

        $this->actingAs($loginsOnly)
            ->get('/admin/users/'.$target->getKey())
            ->assertOk()
            ->assertSee('Recent sign-ins')
            ->assertDontSee('Account audit trail');

        $auditOnly = $this->editor(['activity_log.view_logs']);

        $this->actingAs($auditOnly)
            ->get('/admin/users/'.$target->getKey())
            ->assertOk()
            ->assertSee('Account audit trail')
            ->assertDontSee('Recent sign-ins');
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Hostile query strings are validation errors, never 500s
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileFilterProvider(): array
    {
        return [
            'users: array search' => ['/admin/users?search[]=x'],
            'users: array status' => ['/admin/users?status[]=active'],
            'users: array sort' => ['/admin/users?sort[]=name'],
            'users: array direction' => ['/admin/users?direction[]=asc'],
            'users: array role' => ['/admin/users?role[]=1'],
            'users: array branch' => ['/admin/users?branch[]=1'],
            'users: nested search' => ['/admin/users?search[a][b]=x'],
            'users: unknown status' => ['/admin/users?status=not-a-status'],
            'users: non-numeric role' => ['/admin/users?role=abc'],
            'roles: array search' => ['/admin/roles?search[]=x'],
            'roles: array panel' => ['/admin/roles?panel[]=admin'],
            'roles: array sort' => ['/admin/roles?sort[]=level'],
            'roles: unknown panel' => ['/admin/roles?panel=not-a-panel'],
            'roles: unknown system' => ['/admin/roles?system=neither'],
            // The other two screens read the identical filters off the identical request class, so
            // the same probe has to answer the same way there.
            'modules: array search' => ['/admin/modules?search[]=x'],
            'modules: array group' => ['/admin/modules?group[]=system'],
            'modules: array state' => ['/admin/modules?state[]=core'],
            'modules: unknown group' => ['/admin/modules?group=not-a-group'],
            'modules: unknown state' => ['/admin/modules?state=halfway'],
            'permissions: array search' => ['/admin/permissions?search[]=x'],
            'permissions: array group' => ['/admin/permissions?group[]=system'],
            'permissions: array ability' => ['/admin/permissions?ability[]=view'],
            'permissions: array module' => ['/admin/permissions?module[]=users'],
            'permissions: unknown group' => ['/admin/permissions?group=not-a-group'],
        ];
    }

    #[Test]
    #[DataProvider('hostileFilterProvider')]
    public function a_hostile_filter_is_a_validation_error_not_a_server_error(string $url): void
    {
        $actor = $this->createSuperAdmin();

        $response = $this->actingAs($actor)->get($url);

        $this->assertSame(
            422,
            $response->getStatusCode(),
            sprintf('Expected %s to answer 422, got %d.', $url, $response->getStatusCode())
        );
    }

    #[Test]
    public function an_absurdly_long_search_term_is_refused_rather_than_queried(): void
    {
        $actor = $this->createSuperAdmin();

        $this->actingAs($actor)
            ->get('/admin/users?search='.str_repeat('a', 5000))
            ->assertStatus(422);
    }

    #[Test]
    public function honest_filters_still_work_on_every_list_screen(): void
    {
        $actor = $this->createSuperAdmin();

        $this->actingAs($actor)
            ->get('/admin/users?search=demo&status='.UserStatus::Active->value.'&sort=email&direction=desc&page=1')
            ->assertOk();

        $this->actingAs($actor)
            ->get('/admin/roles?search=admin&panel=admin&system=system&sort=level&direction=asc')
            ->assertOk();

        $this->actingAs($actor)
            ->get('/admin/modules?search=user&group='.ModuleGroup::System->value.'&state=core&page=1')
            ->assertOk();

        $this->actingAs($actor)
            ->get('/admin/permissions?search=users&group='.ModuleGroup::System->value.'&module=users&ability='.Ability::ViewAny->value)
            ->assertOk();

        // An empty select submits '' — "no filter", not an invalid enum.
        $this->actingAs($actor)
            ->get('/admin/users?search=&status=&role=&branch=')
            ->assertOk();

        $this->actingAs($actor)
            ->get('/admin/modules?search=&group=&state=')
            ->assertOk();

        $this->actingAs($actor)
            ->get('/admin/permissions?search=&group=&module=&ability=')
            ->assertOk();
    }

    /**
     * The filters are not cosmetic: the one that used to 500 still narrows the list.
     */
    #[Test]
    public function the_module_and_permission_filters_still_select_rows(): void
    {
        $actor = $this->createSuperAdmin();

        $this->actingAs($actor)
            ->get('/admin/modules?state=core')
            ->assertOk()
            ->assertViewHas('modules', static fn (mixed $page): bool => collect($page->items())
                ->every(static fn (Module $module): bool => (bool) $module->is_core));

        $this->actingAs($actor)
            ->get('/admin/permissions?module=users')
            ->assertOk()
            ->assertViewHas('permissions', static fn (mixed $page): bool => $page->total() > 0
                && collect($page->items())
                    ->every(static fn (Permission $permission): bool => (string) $permission->module === 'users'));
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * An administrator who can reach the user screens and edit accounts, plus anything extra the
     * test needs. Deliberately **without** `users.change_status`.
     *
     * @param  array<int, string>  $extra
     */
    private function editor(array $extra = []): User
    {
        return $this->createUserWithPermissions(
            array_merge(['users.view_any', 'users.view', 'users.edit', 'users.assign'], $extra),
            'admin',
            20,
        );
    }

    /**
     * An account the editor outranks (level 50 against their 20), so `UserPolicy` lets them manage
     * it and the permission under test is the only thing left deciding.
     */
    private function weakerUser(): User
    {
        return $this->createUserWithPermissions([], 'admin', 50);
    }

    /**
     * The payload the edit form posts: every required field, plus the target's current role set so
     * the role delta is empty and the rank rule has nothing to object to.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(User $target, array $overrides = []): array
    {
        return array_merge([
            'name' => (string) $target->name,
            'email' => (string) $target->email,
            'roles' => $target->roles->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        ], $overrides);
    }

    private function recordSignIn(User $user): LoginHistory
    {
        /** @var LoginHistory $row */
        $row = LoginHistory::query()->create([
            'user_id' => $user->getKey(),
            'email' => (string) $user->email,
            'status' => LoginStatus::Failed->value,
            'ip_address' => '203.0.113.44',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
            'device' => 'desktop',
            'platform' => 'Windows',
            'browser' => 'Chrome',
            'logged_in_at' => now(),
        ]);

        return $row;
    }

    private function recordAuditRow(User $user): Activity
    {
        /** @var Activity $row */
        $row = Activity::query()->create([
            'log_name' => 'default',
            'description' => 'Suspicious audit description',
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->getKey(),
            'event' => 'updated',
            'properties' => ['old' => ['name' => 'Before'], 'attributes' => ['name' => 'After']],
            'ip_address' => '203.0.113.44',
            'module' => 'users',
        ]);

        return $row;
    }
}
