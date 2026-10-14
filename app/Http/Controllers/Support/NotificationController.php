<?php

declare(strict_types=1);

namespace App\Http\Controllers\Support;

use App\Enums\PanelType;
use App\DataObjects\Support\BellPayload;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Support\NotificationPreferenceService;
use App\Services\Support\NotificationService;
use App\Support\NotificationRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The bell, on every panel (phase-19-23 §7.7, §6.19).
 *
 * **One controller for all five panels, not five.** §7.7 registers the same routes under each
 * prefix precisely so the bell behaves identically everywhere; five copies would drift, and the
 * one that drifted would be the portal nobody tests by hand.
 *
 * **Every action is scoped to the viewer's own rows and answers 404 otherwise.** A notification is
 * somebody's own record of being told something, and there is no route by which one user reads
 * another's — the admin register shows aggregate counts per event, never another person's payload
 * (§9.4).
 *
 * **`go()` never authorises the target itself, and must not.** A stored `url` was reasonable when
 * the row was written, and that may have been months ago — a person removed from a project still
 * has the old notification in their bell. The re-authorisation that matters happens at the
 * destination: this issues a redirect, the browser follows it, and the target route runs its own
 * `module:` and `can:` middleware exactly as if the link had been typed. Re-implementing that check
 * here would be a second opinion that could disagree with the route's own, and the one that decides
 * is whichever runs last.
 *
 * What this *does* check is that the URL points into this installation. The value comes from a
 * listener rather than from a person, but it is still stored data, and redirecting to whatever a
 * row contains is an open redirect one bad row away.
 */
final class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationPreferenceService $preferences,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $rows = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->getKey())
            ->when($request->string('event')->toString() !== '', fn ($q) => $q->where('event_key', $request->string('event')->toString()))
            ->when($request->string('level')->toString() !== '', fn ($q) => $q->where('level', $request->string('level')->toString()))
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->when($request->boolean('archived'), fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view($this->panel($request).'.notifications.index', [
            'notifications' => $rows,
            'unread' => $this->notifications->unreadCountFor($user),
            'events' => NotificationRegistry::forUser($user),
            'panel' => $this->panel($request),
        ]);
    }

    /** The polling endpoint the topbar hits. Throttled in the route, cached per request here. */
    public function bell(Request $request): JsonResponse
    {
        $payload = $this->notifications->bell($request->user(), fresh: true);

        return response()->json([
            'unread' => $payload->unread,
            'badge' => $payload->badge(),
            'has_more' => $payload->hasMore,
            'rows' => $payload->rows->map(static fn (object $row): array => BellPayload::present($row))->values(),
        ]);
    }

    /**
     * The bell dropdown asks for JSON (it stays open and updates its badge); the notifications page
     * posts a form and goes back, as it always has.
     */
    public function read(Request $request, string $notification): RedirectResponse|JsonResponse
    {
        $marked = $this->notifications->markRead($request->user(), $notification);

        if ($request->expectsJson()) {
            return response()->json(['marked' => $marked, 'unread' => $this->notifications->unreadCountFor($request->user())]);
        }

        return back();
    }

    public function readAll(Request $request): RedirectResponse|JsonResponse
    {
        $count = $this->notifications->markAllRead($request->user());

        if ($request->expectsJson()) {
            return response()->json(['marked' => $count, 'unread' => 0]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => $count === 0 ? 'Nothing was unread.' : $count.' marked as read.',
        ]);
    }

    public function archive(Request $request, string $notification): RedirectResponse
    {
        $this->notifications->archive($request->user(), $notification);

        return back()->with('toast', ['type' => 'success', 'message' => 'Archived. It stays on the record.']);
    }

    public function archiveAll(Request $request): RedirectResponse
    {
        $count = $this->notifications->archiveAll($request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $count.' archived. Archiving never deletes — they stay on the record.',
        ]);
    }

    /**
     * Follow a notification's deep link.
     *
     * **The row is found first, and a row that is not this person's is a 404** — not a 403, so an
     * id cannot be probed for existence.
     */
    public function go(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();

        $row = DB::table('notifications')
            ->where('id', $notification)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->getKey())
            ->first();

        abort_if($row === null, 404);

        if ((bool) setting('support.notification_mark_read_on_open', true)) {
            $this->notifications->markRead($user, $notification);
        }

        $url = (string) ($row->url ?? '');

        if ($url === '' || ! $this->isInternal($request, $url)) {
            // A link to nowhere, or one that points off this installation. The list is the honest
            // fallback: the person still sees what they were told.
            return redirect()->route($this->panel($request).'.notifications.index');
        }

        return redirect()->to($this->targetUrl($request, $url));
    }

    /** The §97 preference screen. */
    public function preferences(Request $request): View
    {
        return view($this->panel($request).'.notifications.preferences', [
            'matrix' => $this->preferences->matrixFor($request->user()),
            'panel' => $this->panel($request),
        ]);
    }

    public function savePreferences(Request $request): RedirectResponse
    {
        $rows = [];

        foreach ((array) $request->input('events', []) as $key => $values) {
            if (! is_array($values)) {
                continue;
            }

            $rows[(string) $key] = [
                'database' => (bool) ($values['database'] ?? false),
                'mail' => (bool) ($values['mail'] ?? false),
                'digest' => (string) ($values['digest'] ?? 'immediate'),
            ];
        }

        $result = $this->preferences->update($request->user(), $rows);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Preferences saved.'.($result['ignored'] === [] ? '' : ' Some rows cannot be switched off and were left on.'),
        ]);
    }

    public function resetPreferences(Request $request): RedirectResponse
    {
        $this->preferences->resetToDefaults($request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Back to the defaults.']);
    }

    // ===============================================================================================

    /**
     * Which panel this request is on, from the route name rather than the URL.
     *
     * The route name is what §7.7 registers per panel; parsing the path would break the first time
     * somebody deployed the application in a subdirectory.
     */
    private function panel(Request $request): string
    {
        $name = (string) ($request->route()?->getName() ?? '');
        $prefix = strtok($name, '.') ?: PanelType::Admin->value;

        return in_array($prefix, array_column(PanelType::cases(), 'value'), true)
            ? $prefix
            : PanelType::Admin->value;
    }

    /**
     * Is this a link into this application?
     *
     * A stored `url` is written by a listener, not by a person — but it is stored data, and
     * redirecting to whatever a row contains is an open redirect one bad row away. Relative paths
     * pass; an absolute one must match this installation's host or the current request host.
     */
    private function isInternal(Request $request, string $url): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        try {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host === null) {
                return false;
            }

            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            $reqHost = $request->getHost();

            if ($appHost !== null && strcasecmp((string) $host, (string) $appHost) === 0) {
                return true;
            }

            if ($reqHost !== '' && strcasecmp((string) $host, (string) $reqHost) === 0) {
                return true;
            }

            return in_array(strtolower($host), ['localhost', '127.0.0.1'], true)
                && in_array(strtolower($reqHost), ['localhost', '127.0.0.1'], true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Normalise an internal stored URL to the current request's scheme, host, port, and base path.
     *
     * Prevents cross-port or cross-host redirect failures when notifications were saved with an absolute
     * APP_URL (e.g., CLI job generated http://localhost:8000/admin/... while the user is browsing on
     * Apache port 80 http://localhost/projects/myoffice/public or vice versa).
     */
    private function targetUrl(Request $request, string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            return $url;
        }

        $path = $parsed['path'] ?? '/';

        // Strip configured APP_URL path prefix if present (e.g. /projects/myoffice/public)
        $appPath = parse_url((string) config('app.url'), PHP_URL_PATH);
        if ($appPath !== null && $appPath !== '' && $appPath !== '/') {
            $appPath = rtrim($appPath, '/');
            if (str_starts_with($path, $appPath)) {
                $path = substr($path, strlen($appPath)) ?: '/';
            }
        }

        // Strip current request base path if already at the start of $path
        $basePath = $request->getBasePath();
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#'.$parsed['fragment'] : '';

        $root = rtrim($request->root(), '/');

        return $root.'/'.ltrim($path, '/').$query.$fragment;
    }
}
