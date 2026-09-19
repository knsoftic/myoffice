<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Enums\PanelType;
use App\Http\Controllers\Client\Concerns\ServesClientPortal;
use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\Session;
use App\Models\User;
use App\Services\Crm\ClientPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Client panel landing page — `client.dashboard` (phase-01 §8, phase-05 §6.9, §8.10).
 *
 * "Where do we stand": every card is a registered, permitted section's `badgeCount()` for the signed-in user's own
 * client (ClientPortalService::dashboard()), plus Phase 5's own documents and notifications counts. A card whose
 * section is not registered is absent — never a zero, never a number this screen cannot prove (D28). The client comes
 * from ClientContext, which `client.context` resolved for this request.
 */
final class DashboardController extends Controller
{
    use ServesClientPortal;

    /**
     * Permission namespace for this panel, as declared in App\Support\PermissionRegistry.
     */
    private const PORTAL = 'client_portal';

    public function __construct(
        private readonly ClientPortalService $portal,
    ) {}

    public function __invoke(Request $request): View
    {
        return $this->index($request);
    }

    public function index(Request $request): View
    {
        Gate::authorize(self::PORTAL.'.dashboard');

        $user = $this->portalUser($request);
        $client = $this->client();

        $logins = LoginHistory::query()
            ->forUser($user)
            ->successful()
            ->latestFirst()
            ->limit(2)
            ->get();

        return view('client.dashboard', array_merge($this->portalViewData($request, $client), [
            'panel' => PanelType::Client,
            'user' => $user,
            'greeting' => $this->greeting($user),
            'roleChips' => $this->roleChips($user),
            'currentLogin' => $logins->first(),
            'previousLogin' => $logins->get(1),
            'sessionCount' => Session::query()->forUser($user)->count(),
            'accountLinks' => $this->accountLinks(),
            'dashboard' => $this->portal->dashboard($client),
        ]));
    }

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
     * @return array{profile: string|null, password: string|null, sessions: string|null}
     */
    private function accountLinks(): array
    {
        $prefix = PanelType::Client->routePrefix();

        return [
            'profile' => $this->firstUrl(['client.profile.edit', "{$prefix}.account.profile", 'account.profile', 'profile.edit']),
            'password' => $this->firstUrl(["{$prefix}.account.password", 'account.password']),
            'sessions' => $this->firstUrl(["{$prefix}.account.sessions", 'account.sessions']),
        ];
    }

    /**
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
