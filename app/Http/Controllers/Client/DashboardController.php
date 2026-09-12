<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Enums\PanelType;
use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\Session;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Client panel landing page (phase-01 §8).
 *
 * Phase 1 has no project or finance tables yet, so this screen shows **no numbers it cannot
 * prove**: only the signed-in client's own identity, account state and login bookkeeping, plus
 * empty states naming the phase that fills each section. No query here can reach another
 * client's row — projects, invoices, payments and tickets arrive from phase 5 onwards.
 *
 * Route: `client.dashboard` (`routes/client.php`, owned by the routes agent).
 */
final class DashboardController extends Controller
{
    /**
     * Permission namespace for this panel, as declared in App\Support\PermissionRegistry.
     * Abilities are `{self::PORTAL}.{ability}` — never spelled out anywhere else here.
     */
    private const PORTAL = 'client_portal';

    /**
     * Allows the route to be wired either as a single-action controller
     * (`DashboardController::class`) or as `[DashboardController::class, 'index']`.
     */
    public function __invoke(Request $request): View
    {
        return $this->index($request);
    }

    public function index(Request $request): View
    {
        // Panel entry is already gated by `auth`, `active` and `panel:client`; this is the
        // per-screen permission (phase-01 §4).
        Gate::authorize(self::PORTAL.'.dashboard');

        /** @var User $user */
        $user = $request->user();

        // Only ever this user's own rows — the whole point of the screen.
        $logins = LoginHistory::query()
            ->forUser($user)
            ->successful()
            ->latestFirst()
            ->limit(2)
            ->get();

        return view('client.dashboard', [
            'panel' => PanelType::Client,
            'user' => $user,
            'greeting' => $this->greeting($user),
            'roleChips' => $this->roleChips($user),
            'currentLogin' => $logins->first(),
            'previousLogin' => $logins->get(1),
            'sessionCount' => Session::query()->forUser($user)->count(),
            'accountLinks' => $this->accountLinks(),
        ]);
    }

    /**
     * Time-of-day greeting in the user's own timezone.
     */
    private function greeting(User $user): string
    {
        $hour = (int) now($user->effectiveTimezone())->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    /**
     * Role labels for the chips beside the user's name (the label when the role has one,
     * otherwise the role name). Roles come from the database, never from a hardcoded list.
     *
     * @return Collection<int, string>
     */
    private function roleChips(User $user): Collection
    {
        return $user->roles
            ->map(static fn (object $role): string => (string) (
                filled($role->label ?? null) ? $role->label : ($role->name ?? '')
            ))
            ->filter(static fn (string $label): bool => $label !== '')
            ->values();
    }

    /**
     * Quick links to the shared account screens. The account routes live in the routes file
     * owned by another agent, so each candidate is resolved through Route::has() and a null
     * result simply hides that link instead of breaking the dashboard.
     *
     * @return array{profile: string|null, password: string|null, sessions: string|null}
     */
    private function accountLinks(): array
    {
        $prefix = PanelType::Client->routePrefix();

        return [
            'profile' => $this->firstUrl(["{$prefix}.account.profile", 'account.profile', 'profile.edit']),
            'password' => $this->firstUrl(["{$prefix}.account.password", 'account.password']),
            'sessions' => $this->firstUrl(["{$prefix}.account.sessions", 'account.sessions']),
        ];
    }

    /**
     * URL of the first candidate route name that is actually registered.
     *
     * @param  list<string>  $candidates
     */
    private function firstUrl(array $candidates): ?string
    {
        foreach ($candidates as $name) {
            if (Route::has($name)) {
                return route($name);
            }
        }

        return null;
    }
}
