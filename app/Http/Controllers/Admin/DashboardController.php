<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\LoginStatus;
use App\Enums\ModuleGroup;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\LoginHistory;
use App\Models\Role;
use App\Models\User;
use App\Support\Modules;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Throwable;

/**
 * The admin landing page (route `admin.dashboard`, phase-01 §8).
 *
 * Every number on this screen is a real query — nothing is mocked. Each block is gated on the
 * permission that owns the data it shows, so the same view degrades to exactly what the signed
 * in role may see: a user without `activity_log.view_logs` never gets the activity table, and a
 * user without `settings.view_any` never sees the infrastructure card.
 *
 * Phase 2 replaces the fixed layout with a user-arrangeable widget framework; until then the
 * dashboards that belong to later phases are advertised as empty states rather than faked with
 * placeholder charts.
 */
final class DashboardController extends Controller
{
    /** Rows shown in each of the two recent-events tables. */
    private const RECENT_LIMIT = 10;

    /**
     * Supports both `Route::get('/', DashboardController::class)` and
     * `[DashboardController::class, 'index']` — the routes file is owned elsewhere.
     */
    public function __invoke(Request $request): View
    {
        return $this->index($request);
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        $permissions = [
            'users' => $this->allows($user, 'users.view_any'),
            'roles' => $this->allows($user, 'roles.view_any'),
            'modules' => $this->allows($user, 'modules.view_any'),
            'activity' => $this->allows($user, 'activity_log.view_logs'),
            'logins' => $this->allows($user, 'login_history.view_logs'),
            'health' => $this->allows($user, 'settings.view_any'),
        ];

        $timezone = $user instanceof User
            ? $user->effectiveTimezone()
            : (string) config('app.timezone', 'UTC');

        $today = CarbonImmutable::now($timezone);
        $appTimezone = (string) config('app.timezone', 'UTC');
        $dayStart = $today->startOfDay()->setTimezone($appTimezone);
        $dayEnd = $today->endOfDay()->setTimezone($appTimezone);

        $userCounts = $permissions['users'] ? $this->userCounts() : [];
        $loginCounts = $permissions['logins'] ? $this->loginCounts($dayStart, $dayEnd) : [];
        $moduleCounts = $permissions['modules'] ? $this->moduleCounts() : [];

        return view('admin.dashboard', [
            'permissions' => $permissions,
            'timezone' => $timezone,
            'today' => $today,
            'stats' => $this->statCards($permissions, $userCounts, $loginCounts, $moduleCounts),
            'health' => $permissions['health'] ? $this->systemHealth() : [],
            'recentActivity' => $permissions['activity'] ? $this->recentActivity() : null,
            'recentLogins' => $permissions['logins'] ? $this->recentLogins() : null,
            'moduleNames' => Modules::names(),
            'upcoming' => $this->upcomingDashboards(),
            'activityIndexUrl' => $this->urlFor('admin.activity-log.index'),
            'loginIndexUrl' => $this->urlFor('admin.login-history.index'),
            'hasAnyBlock' => in_array(true, $permissions, true),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Stat cards
    |--------------------------------------------------------------------------
    */

    /**
     * One flat list of KPI cards, already filtered by permission so the view only loops.
     *
     * @param  array<string, bool>  $permissions
     * @param  array<string, int>  $userCounts
     * @param  array<string, int>  $loginCounts
     * @param  array{enabled: int, total: int}|array{}  $moduleCounts
     * @return array<int, array<string, mixed>>
     */
    private function statCards(array $permissions, array $userCounts, array $loginCounts, array $moduleCounts): array
    {
        $cards = [];

        if ($permissions['users']) {
            $usersUrl = $this->urlFor('admin.users.index');

            $cards[] = [
                'label' => 'Total users',
                'value' => number_format((float) ($userCounts['total'] ?? 0)),
                'icon' => 'users',
                'color' => 'brand',
                'href' => $usersUrl,
                'deltaLabel' => number_format((float) ($userCounts[UserStatus::Pending->value] ?? 0)).' pending',
            ];

            $cards[] = [
                'label' => 'Active users',
                'value' => number_format((float) ($userCounts[UserStatus::Active->value] ?? 0)),
                'icon' => 'check-circle',
                'color' => UserStatus::Active->color(),
                'href' => $usersUrl === null ? null : $usersUrl.'?status='.UserStatus::Active->value,
                'deltaLabel' => 'may sign in',
            ];

            $cards[] = [
                'label' => 'Suspended users',
                'value' => number_format((float) ($userCounts[UserStatus::Suspended->value] ?? 0)),
                'icon' => 'lock-closed',
                'color' => UserStatus::Suspended->color(),
                'href' => $usersUrl === null ? null : $usersUrl.'?status='.UserStatus::Suspended->value,
                'deltaLabel' => number_format((float) ($userCounts[UserStatus::Inactive->value] ?? 0)).' inactive',
            ];
        }

        if ($permissions['roles']) {
            $cards[] = [
                'label' => 'Roles',
                'value' => number_format((float) $this->roleCount()),
                'icon' => 'shield-check',
                'color' => 'violet',
                'href' => $this->urlFor('admin.roles.index'),
                'deltaLabel' => 'permission sets',
            ];
        }

        if ($permissions['modules'] && $moduleCounts !== []) {
            $cards[] = [
                'label' => 'Enabled modules',
                'value' => number_format((float) $moduleCounts['enabled']).' / '.number_format((float) $moduleCounts['total']),
                'icon' => 'puzzle-piece',
                'color' => 'indigo',
                'href' => $this->urlFor('admin.modules.index'),
                'deltaLabel' => $moduleCounts['total'] - $moduleCounts['enabled'] === 0
                    ? 'all switched on'
                    : number_format((float) ($moduleCounts['total'] - $moduleCounts['enabled'])).' disabled',
            ];
        }

        if ($permissions['logins']) {
            $loginUrl = $this->urlFor('admin.login-history.index');

            $cards[] = [
                'label' => 'Logins today',
                'value' => number_format((float) ($loginCounts[LoginStatus::Success->value] ?? 0)),
                'icon' => 'arrow-left-on-rectangle',
                'color' => LoginStatus::Success->color(),
                'href' => $loginUrl === null ? null : $loginUrl.'?status='.LoginStatus::Success->value,
                'deltaLabel' => 'successful sign-ins',
            ];

            $failed = (int) ($loginCounts[LoginStatus::Failed->value] ?? 0);
            $blocked = (int) ($loginCounts[LoginStatus::Blocked->value] ?? 0);

            $cards[] = [
                'label' => 'Failed logins today',
                'value' => number_format((float) $failed),
                'icon' => 'exclamation-triangle',
                'color' => $failed > 0 ? LoginStatus::Failed->color() : 'slate',
                'href' => $loginUrl === null ? null : $loginUrl.'?status='.LoginStatus::Failed->value,
                'deltaLabel' => $blocked > 0
                    ? number_format((float) $blocked).' blocked'
                    : 'no blocked attempts',
            ];
        }

        return $cards;
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    /**
     * status => count, plus `total`. One grouped query; soft-deleted users excluded.
     *
     * The raw alias keeps the enum cast off the grouping key, so the map is keyed by the
     * stored string and lines up with UserStatus::*->value.
     *
     * @return array<string, int>
     */
    private function userCounts(): array
    {
        $counts = ['total' => 0];

        foreach (UserStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }

        try {
            $rows = User::query()
                ->selectRaw('status as status_value, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status_value');
        } catch (Throwable) {
            return $counts;
        }

        foreach ($rows as $status => $aggregate) {
            $counts[(string) $status] = (int) $aggregate;
            $counts['total'] += (int) $aggregate;
        }

        return $counts;
    }

    /**
     * Login outcomes recorded inside the viewer's "today".
     *
     * @return array<string, int>
     */
    private function loginCounts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = [];

        foreach (LoginStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }

        try {
            $rows = LoginHistory::query()
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('status as status_value, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status_value');
        } catch (Throwable) {
            return $counts;
        }

        foreach ($rows as $status => $aggregate) {
            $counts[(string) $status] = (int) $aggregate;
        }

        return $counts;
    }

    /**
     * @return array{enabled: int, total: int}
     */
    private function moduleCounts(): array
    {
        $modules = Modules::all();

        return [
            'enabled' => $modules->filter(static fn (array $module): bool => (bool) ($module['is_enabled'] ?? false))->count(),
            'total' => $modules->count(),
        ];
    }

    private function roleCount(): int
    {
        try {
            return Role::query()->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return EloquentCollection<int, Activity>
     */
    private function recentActivity(): EloquentCollection
    {
        return Activity::query()
            ->with('causer')
            ->latestFirst()
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * @return EloquentCollection<int, LoginHistory>
     */
    private function recentLogins(): EloquentCollection
    {
        return LoginHistory::query()
            ->with('user')
            ->latestFirst()
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | System health
    |--------------------------------------------------------------------------
    */

    /**
     * Facts about the running installation — read from the framework and the `migrations`
     * table, never hardcoded.
     *
     * @return array<int, array{label: string, value: string, icon: string, meta: string|null}>
     */
    private function systemHealth(): array
    {
        $database = $this->databaseInfo();
        $migration = $this->lastMigration();

        return [
            [
                'label' => 'App version',
                'value' => $this->appVersion(),
                'icon' => 'sparkles',
                'meta' => config('app.name') === null ? null : (string) config('app.name'),
            ],
            [
                'label' => 'PHP version',
                'value' => PHP_VERSION,
                'icon' => 'server-stack',
                'meta' => PHP_OS_FAMILY,
            ],
            [
                'label' => 'Database',
                'value' => $database['name'],
                'icon' => 'table-cells',
                'meta' => $database['driver'],
            ],
            [
                'label' => 'Queue driver',
                'value' => (string) config('queue.default', 'sync'),
                'icon' => 'queue-list',
                'meta' => null,
            ],
            [
                'label' => 'Cache driver',
                'value' => (string) config('cache.default', 'file'),
                'icon' => 'rectangle-stack',
                'meta' => 'sessions: '.(string) config('session.driver', 'file'),
            ],
            [
                'label' => 'Last migration',
                'value' => $migration['value'],
                'icon' => 'clock',
                'meta' => $migration['meta'],
            ],
            [
                'label' => 'Environment',
                'value' => (string) config('app.env', 'production'),
                'icon' => 'cog-6-tooth',
                'meta' => config('app.debug') ? 'debug on' : 'debug off',
            ],
            [
                'label' => 'Timezone',
                'value' => (string) config('app.timezone', 'UTC'),
                'icon' => 'globe-alt',
                'meta' => 'locale: '.(string) config('app.locale', 'en'),
            ],
        ];
    }

    private function appVersion(): string
    {
        $version = config('app.version');

        if (is_string($version) && trim($version) !== '') {
            return trim($version);
        }

        return 'Laravel '.app()->version();
    }

    /**
     * @return array{name: string, driver: string}
     */
    private function databaseInfo(): array
    {
        try {
            $connection = DB::connection();

            return [
                'name' => (string) $connection->getDatabaseName(),
                'driver' => (string) $connection->getDriverName(),
            ];
        } catch (Throwable) {
            return ['name' => 'unavailable', 'driver' => 'unknown'];
        }
    }

    /**
     * The newest row in `migrations`. The table stores no timestamp, so the stamp comes from
     * the filename prefix — which is exactly the migration's own version.
     *
     * @return array{value: string, meta: string|null}
     */
    private function lastMigration(): array
    {
        try {
            $row = DB::table('migrations')->orderByDesc('id')->first();
        } catch (Throwable) {
            return ['value' => 'unavailable', 'meta' => null];
        }

        if ($row === null) {
            return ['value' => 'none yet', 'meta' => null];
        }

        $name = (string) ($row->migration ?? '');
        $batch = isset($row->batch) ? (int) $row->batch : null;
        $stamp = 'unknown';

        if (preg_match('/^(\d{4})_(\d{2})_(\d{2})_(\d{6})_/', $name, $matches) === 1) {
            try {
                $stamp = CarbonImmutable::createFromFormat(
                    'Y_m_d_His',
                    $matches[1].'_'.$matches[2].'_'.$matches[3].'_'.$matches[4],
                    (string) config('app.timezone', 'UTC'),
                )->format('d M Y H:i');
            } catch (Throwable) {
                $stamp = 'unknown';
            }
        }

        return [
            'value' => $stamp,
            'meta' => $batch === null ? $name : 'batch '.$batch.' · '.$name,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Later-phase placeholders
    |--------------------------------------------------------------------------
    */

    /**
     * What each module group's dashboard will contain once the phase that owns it is built.
     * The module counts are real (they come from the registry + `modules` table), so the card
     * says something true instead of drawing a fake chart.
     *
     * @return array<int, array{title: string, message: string, icon: string, color: string, badge: string|null}>
     */
    private function upcomingDashboards(): array
    {
        $blurbs = [
            ModuleGroup::SoftwareHouse->value => ['briefcase', 'Lead pipeline, live projects, milestone burndown and task load per developer.'],
            ModuleGroup::Institute->value => ['academic-cap', 'Admissions, batch occupancy, attendance and fee collection against what is due.'],
            ModuleGroup::Finance->value => ['banknotes', 'Invoiced versus received, expenses by category and outstanding balances.'],
            ModuleGroup::Collaborator->value => ['user-plus', 'Referrals, commission earned against money actually received, wallet and payout queue.'],
            ModuleGroup::Hr->value => ['user-group', 'Headcount, attendance, leave balances and the payroll run.'],
            ModuleGroup::Website->value => ['globe-alt', 'Content freshness, contact and course inquiries, job applications and SEO coverage.'],
            ModuleGroup::Shared->value => ['lifebuoy', 'Support tickets, meetings, messages and the cross-module report builder.'],
        ];

        $grouped = Modules::all()->groupBy(static fn (array $module): string => (string) ($module['group'] ?? ''));

        $cards = [[
            'title' => 'Widget framework',
            'message' => 'Phase 2 turns these fixed cards into widgets you can reorder, hide and scope per role, alongside system settings and module management.',
            'icon' => 'squares-2x2',
            'color' => 'brand',
            'badge' => 'Phase 2',
        ]];

        foreach ($blurbs as $group => [$icon, $message]) {
            $case = ModuleGroup::tryFrom($group);

            if ($case === null) {
                continue;
            }

            $count = $grouped->has($group) ? $grouped->get($group)->count() : 0;

            $cards[] = [
                'title' => $case->label().' dashboard',
                'message' => $message,
                'icon' => $icon,
                'color' => $case->color(),
                'badge' => $count === 0 ? null : $count.' modules ready',
            ];
        }

        return $cards;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function allows(?Authenticatable $user, string $permission): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        try {
            return $user->can($permission);
        } catch (Throwable) {
            // Permission tables not seeded yet: deny rather than leak.
            return false;
        }
    }

    /**
     * A route URL only when that route has been registered (later phases add some of them).
     */
    private function urlFor(string $name): ?string
    {
        if (! Route::has($name)) {
            return null;
        }

        try {
            return route($name);
        } catch (Throwable) {
            return null;
        }
    }
}
