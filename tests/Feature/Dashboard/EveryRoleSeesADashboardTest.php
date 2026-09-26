<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\PanelType;
use App\Models\Role;
use App\Models\User;
use App\Support\DashboardRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * **T57**: widget permissions were chosen per widget and never checked against the roles that read
 * them, so a role could be configured perfectly and still sign in to a blank page.
 *
 * It was found the hard way — `Receptionist` held 75 permissions and saw **none** of the 38
 * registered cards, because every one sat behind a `*.view_reports`, a `*.view_financial`, a
 * `login_history.view_logs` or a module the front desk has no grant on. Measuring the rest of the
 * roles afterwards found four more blank dashboards (Project Manager, Support Agent, Developer,
 * Designer) and an HR role with 176 permissions and two widgets.
 *
 * **This is the test that was owed.** Nothing else in the suite would have caught any of it: every
 * widget worked, every permission was correct, and the failure lived in the gap between them. A
 * blank dashboard throws nothing, logs nothing and renders a valid page — the only way to see it is
 * to ask, for each role in turn, "what does this person actually get?"
 *
 * The assertion is deliberately weak — *at least one* — because this is a floor, not a design
 * review. It cannot tell a good dashboard from a poor one; it can only refuse to let a role end up
 * with nothing at all, which is the failure that keeps happening.
 */
final class EveryRoleSeesADashboardTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * Widgets that are Super-Admin-only **on purpose**, and why.
     *
     * The test exists to catch a widget that became unreachable by accident — a gate chosen without
     * checking who holds it. A card that is deliberately restricted is not that, and listing it here
     * keeps the test meaningful instead of teaching people to ignore a failure they have seen before.
     *
     * Adding a key here is a claim that no other role *should* see the card. It is not a way to make
     * the test pass.
     *
     * @var list<string>
     */
    private const SUPER_ADMIN_ONLY = [
        // The module kill switch. `Gate::before` lets a disabled module 403 everyone including Super
        // Admin, so the one person who can turn a module back on is the only one who should be
        // watching which are off.
        'modules_enabled',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        DashboardRegistry::reset();
        DashboardRegistry::flushCache();
    }

    protected function tearDown(): void
    {
        DashboardRegistry::reset();
        DashboardRegistry::flushCache();

        parent::tearDown();
    }

    #[Test]
    public function every_seeded_admin_role_sees_at_least_one_widget(): void
    {
        $roles = Role::query()
            ->where('panel', PanelType::Admin->value)
            ->orderBy('level')
            ->pluck('name')
            ->all();

        $this->assertNotEmpty($roles, 'No admin roles are seeded, so this test is proving nothing.');

        $blank = [];

        foreach ($roles as $name) {
            $user = $this->createUserWithRole($name);

            if (DashboardRegistry::for($user)->isEmpty()) {
                $blank[] = sprintf('%s (%d permissions)', $name, $user->getAllPermissions()->count());
            }
        }

        $this->assertSame(
            [],
            $blank,
            "These roles sign in to an empty dashboard:\n  - ".implode("\n  - ", $blank)
            ."\n\nA role with permissions and no widgets is T57: the widget's gate and the role's "
            ."grant were each chosen correctly and never compared. Either give the role a widget it "
            ."can see, or gate an existing widget on a permission it actually holds — do not widen a "
            ."role to fit a widget.",
        );
    }

    /**
     * The narrower half of the same rule, kept separate so its failure names the cause directly.
     *
     * A widget gated on a permission **no seeded role holds** is dead code that looks alive: it is
     * registered, it renders, it passes its own tests, and the only person who will ever see it is a
     * Super Admin — who sees everything and therefore cannot notice that nobody else does.
     */
    #[Test]
    public function no_widget_is_gated_on_a_permission_only_super_admin_holds(): void
    {
        $roles = Role::query()
            ->where('panel', PanelType::Admin->value)
            ->where('name', '!=', User::SUPER_ADMIN_ROLE)
            ->pluck('name')
            ->all();

        /** @var array<string, User> $actors */
        $actors = [];

        foreach ($roles as $name) {
            $actors[$name] = $this->createUserWithRole($name);
        }

        $unreachable = [];

        foreach (DashboardRegistry::all() as $widget) {
            $permission = $widget->permission();

            if ($permission === null || in_array($widget->key(), self::SUPER_ADMIN_ONLY, true)) {
                continue;
            }

            $seenBy = array_keys(array_filter(
                $actors,
                static fn (User $user): bool => $user->can($permission),
            ));

            if ($seenBy === []) {
                $unreachable[] = sprintf('%s (needs %s)', $widget->key(), $permission);
            }
        }

        $this->assertSame(
            [],
            $unreachable,
            "These widgets are visible to nobody but Super Admin:\n  - ".implode("\n  - ", $unreachable)
            ."\n\nThat is not necessarily wrong — a system-health card belongs to whoever runs the "
            ."system. But it is worth knowing, because a widget built for a role that cannot see it "
            ."is work nobody receives.",
        );
    }
}
