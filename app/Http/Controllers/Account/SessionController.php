<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Models\LoginHistory;
use App\Models\Session;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * "Where am I signed in?" — the replacement for Breeze's delete-account form
 * (routes `account.sessions*` and `account.login-history`, phase-01 §1.8, §7).
 *
 * Every query is scoped to `auth()->id()`: the route takes a session id, but a row is only ever
 * read or deleted `where user_id = <me>`, so a guessed id from another account resolves to
 * nothing (CLAUDE.md §1.10 — isolation by the owning relation, never by a form field).
 *
 * The `sessions` table is only populated while the database session driver is active, so the
 * screen says so plainly instead of pretending the list is complete.
 */
final class SessionController extends AccountController
{
    /**
     * Own login rows shown on the history tab (phase-01 §7).
     */
    private const HISTORY_LIMIT = 50;

    public function index(Request $request): View
    {
        $user = $this->currentUser();
        $current = $this->currentSessionId($request);

        return $this->render('account.sessions', self::TAB_SESSIONS, [
            'user' => $user,
            'sessions' => $this->sessions($request),
            'currentSessionId' => $current,
            'usesDatabaseSessions' => $this->usesDatabaseSessions(),
        ]);
    }

    /**
     * Revoke one session. The session making this request is never revoked here — signing
     * yourself out is what the logout button is for, and it also writes the history row.
     */
    public function destroy(Request $request, string $session): RedirectResponse
    {
        $user = $this->currentUser();

        if ($session === $this->currentSessionId($request)) {
            return redirect()
                ->route('account.sessions')
                ->with('toast', ['type' => 'info', 'message' => 'Use "Sign out" to end the session you are using right now.']);
        }

        $deleted = Session::query()
            ->where('user_id', $user->getKey())
            ->whereKey($session)
            ->delete();

        return redirect()
            ->route('account.sessions')
            ->with('toast', $deleted > 0
                ? ['type' => 'success', 'message' => 'That device has been signed out.']
                : ['type' => 'info', 'message' => 'That session had already ended.']);
    }

    /**
     * Revoke every session except this one.
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        $user = $this->currentUser();
        $current = $this->currentSessionId($request);

        $deleted = Session::query()
            ->where('user_id', $user->getKey())
            ->when($current !== null, fn ($query) => $query->where('id', '!=', $current))
            ->delete();

        return redirect()
            ->route('account.sessions')
            ->with('toast', $deleted > 0
                ? ['type' => 'success', 'message' => $deleted === 1
                    ? 'One other device has been signed out.'
                    : $deleted.' other devices have been signed out.']
                : ['type' => 'info', 'message' => 'There were no other sessions to sign out.']);
    }

    /**
     * The user's own last 50 authentication events.
     */
    public function history(Request $request): View
    {
        $user = $this->currentUser();

        $history = LoginHistory::query()
            ->forUser($user)
            ->latestFirst()
            ->limit(self::HISTORY_LIMIT)
            ->get();

        return $this->render('account.login-history', self::TAB_HISTORY, [
            'user' => $user,
            'history' => $history,
            'limit' => self::HISTORY_LIMIT,
            'currentSessionId' => $this->currentSessionId($request),
        ]);
    }

    /**
     * The user's rows from the `sessions` table.
     *
     * Read whenever the table is there, not only while the database driver is active: a row that
     * exists is a session that can be revoked, and the screen says plainly (see
     * `usesDatabaseSessions`) when the list may be incomplete because of the driver.
     *
     * @return Collection<int, Session>
     */
    private function sessions(Request $request): Collection
    {
        try {
            if (! Schema::hasTable('sessions')) {
                return new Collection;
            }
        } catch (Throwable) {
            return new Collection;
        }

        return Session::query()
            ->where('user_id', $this->currentUser()->getKey())
            ->orderByDesc('last_activity')
            ->get();
    }

    private function currentSessionId(Request $request): ?string
    {
        return $request->hasSession() ? $request->session()->getId() : null;
    }

    private function usesDatabaseSessions(): bool
    {
        return config('session.driver') === 'database';
    }
}
