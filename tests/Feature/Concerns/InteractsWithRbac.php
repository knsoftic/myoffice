<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Enums\PanelType;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionProperty;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fixture helpers shared by the Phase 1 acceptance tests.
 *
 * Everything here builds on the **real seeded data** (PermissionRegistry → PermissionSeeder →
 * RoleSeeder): a test never invents a permission name or a role, it grants rows that already
 * exist, so a registry/seeder mismatch fails the test instead of hiding behind a fixture.
 *
 * Not named `*Test.php`, so PHPUnit does not try to run it as a test class.
 */
trait InteractsWithRbac
{
    /**
     * Guard against the one ordering hazard of a per-process `migrate:fresh --seed`: whichever
     * test class triggers the migration gets the seed, and a later class inherits it only because
     * the seeded rows predate the transaction. Verifying it (and seeding on demand) keeps every
     * class correct whatever order the suite runs in, and whatever `--filter` is passed.
     */
    protected function ensureSeeded(): void
    {
        $seeded = Role::query()->where('name', User::SUPER_ADMIN_ROLE)->exists()
            && Permission::query()->where('name', 'users.view_any')->exists()
            && Module::query()->where('slug', 'collaborators')->exists();

        if (! $seeded) {
            $this->seed(DatabaseSeeder::class);
        }

        $this->forgetPermissionCache();
    }

    /*
    |--------------------------------------------------------------------------
    | Actors
    |--------------------------------------------------------------------------
    */

    /**
     * A fresh account holding the seeded `Super Admin` role.
     *
     * Deliberately a factory user rather than the seeded first account: SuperAdminSeeder flags
     * that one `must_change_password`, which the `active` middleware enforces on every request.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createSuperAdmin(array $attributes = []): User
    {
        return $this->createUserWithRole(User::SUPER_ADMIN_ROLE, $attributes);
    }

    /**
     * A fresh account holding one of the seeded roles, addressed by name.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createUserWithRole(string $role, array $attributes = []): User
    {
        $model = Role::query()
            ->where('name', $role)
            ->where('guard_name', $this->guardName())
            ->firstOrFail();

        $user = User::factory()->create($attributes);
        $user->assignRole($model);

        $this->forgetPermissionCache();

        return $user->fresh()->load('roles');
    }

    /**
     * A fresh account whose role holds **exactly** the given permissions — the fixture the RBAC
     * matrix needs, where one permission is the only difference between 403 and 200.
     *
     * Passing an empty list creates the "permission-less but panel-eligible" user: their role
     * carries the panel so `panel:` lets them in, and nothing else, so every `can:` fails.
     *
     * @param  array<int, string>  $permissions  permission names that must exist in the table
     * @param  array<string, mixed>  $attributes
     */
    protected function createUserWithPermissions(
        array $permissions = [],
        PanelType|string $panel = PanelType::Admin,
        int $level = 50,
        array $attributes = [],
    ): User {
        $role = $this->createRoleWithPermissions($permissions, $panel, $level);

        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        $this->forgetPermissionCache();

        return $user->fresh()->load('roles');
    }

    /**
     * A throwaway, non-protected role carrying exactly the given permissions.
     *
     * @param  array<int, string>  $permissions
     */
    protected function createRoleWithPermissions(
        array $permissions = [],
        PanelType|string $panel = PanelType::Admin,
        int $level = 50,
        ?string $name = null,
    ): Role {
        /** @var Role $role */
        $role = Role::query()->create([
            'name' => $name ?? 'Test Role '.Str::random(10),
            'guard_name' => $this->guardName(),
            'label' => 'Test role',
            'panel' => $panel instanceof PanelType ? $panel->value : $panel,
            'level' => $level,
            'is_system' => false,
            'is_default' => false,
        ]);

        $role->syncPermissions($this->permissionModels($permissions));

        $this->forgetPermissionCache();

        return $role->fresh();
    }

    /**
     * Add permissions to a user's first role (keeping the "exactly these" contract of
     * createUserWithPermissions intact for the original set).
     */
    protected function grantPermissions(User $user, string ...$permissions): void
    {
        $user->givePermissionTo($this->permissionModels($permissions));

        $this->forgetPermissionCache();
    }

    /**
     * A seeded demo account, addressed by the role it was created for
     * (`DemoUserSeeder`: "{role-slug}@myoffice.test").
     */
    protected function seededDemoUser(string $role): User
    {
        $email = Str::slug($role).'@'.DemoUserSeeder::DEMO_DOMAIN;

        return User::query()->where('email', $email)->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | Module gate
    |--------------------------------------------------------------------------
    */

    /**
     * Flip a module through the model, which is what flushes the cached gate map in production.
     */
    protected function switchModule(string $slug, bool $enabled): Module
    {
        $module = Module::query()->where('slug', $slug)->firstOrFail();

        $module->is_enabled = $enabled;
        $module->save();

        return $module->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Sessions
    |--------------------------------------------------------------------------
    */

    /**
     * Insert a row into Laravel's `sessions` table, standing in for "this user is also signed in
     * on another device". The id is alphanumeric because `account.sessions.destroy` constrains
     * the parameter to `[A-Za-z0-9]+`.
     *
     * @return string the session id
     */
    protected function createSessionRow(
        User|int|string|null $user,
        ?string $id = null,
        string $ip = '198.51.100.7',
        string $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    ): string {
        $id ??= Str::random(40);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user instanceof User ? $user->getKey() : $user,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'payload' => base64_encode(serialize(['_token' => Str::random(40)])),
            'last_activity' => time(),
        ]);

        return $id;
    }

    /*
    |--------------------------------------------------------------------------
    | Environment shims
    |--------------------------------------------------------------------------
    */

    /**
     * Make the application treat this test as a web request rather than a console run.
     *
     * `LogsActivityWithContext` and `WritesAuditTrail` deliberately record no request context
     * when `app()->runningInConsole()` is true, so a seeder never stamps every row it writes with
     * 127.0.0.1. PHP_SAPI is `cli` under PHPUnit, so that guard also swallows the context of the
     * HTTP requests a feature test makes — which is exactly what the contract's audit test has to
     * observe (phase-01 §10: "an activity row with old and new values plus IP").
     *
     * `Application::$isRunningInConsole` is an instance property, so flipping it here affects only
     * this test's application instance and dies with it at tear-down. The production code path is
     * exercised unchanged.
     *
     * One knock-on effect has to be undone: `ValidateCsrfToken` skips verification while
     * `runningInConsole() && runningUnitTests()`, so flipping the flag would make every POST in the
     * test answer 419 instead of doing the work. CSRF is not what these tests are about — and it is
     * off for the rest of the suite for exactly the same reason — so it is switched off explicitly.
     */
    protected function treatRequestsAsWeb(): void
    {
        $property = new ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setValue($this->app, false);

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /**
     * App\Services\Auth\PasswordPolicy uses `uncompromised()`, which calls the haveibeenpwned
     * range API. Stub the verifier so the suite never depends on the network; every other rule in
     * the policy still runs.
     */
    protected function withoutCompromisedPasswordCheck(): void
    {
        $this->app->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
        {
            public function verify($data): bool
            {
                return true;
            }
        });
    }

    /**
     * spatie caches the permission map per request; every grant made mid-test has to invalidate
     * it or the Gate keeps answering from the snapshot taken before the grant.
     */
    protected function forgetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve permission names to rows, failing loudly when one does not exist — a typo in a test
     * must never look like a missing permission.
     *
     * @param  array<int, string>  $names
     * @return EloquentCollection<int, Permission>
     */
    private function permissionModels(array $names): EloquentCollection
    {
        $names = array_values(array_unique(array_filter($names)));

        if ($names === []) {
            /** @var EloquentCollection<int, Permission> $empty */
            $empty = Permission::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        /** @var EloquentCollection<int, Permission> $models */
        $models = Permission::query()
            ->whereIn('name', $names)
            ->where('guard_name', $this->guardName())
            ->get();

        $missing = array_diff($names, $models->pluck('name')->all());

        if ($missing !== []) {
            $this->fail(sprintf(
                'These permissions are not in the permissions table: %s. '
                .'Either the name is wrong or PermissionRegistry/PermissionSeeder no longer declares it.',
                implode(', ', $missing),
            ));
        }

        return $models;
    }

    private function guardName(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }
}
