<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\LoginHistoryRecorder;
use App\Services\Auth\LoginRedirector;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Sign in / sign out (phase-01 §7).
 *
 * Account state is asserted inside LoginRequest, the history rows are written by the listeners,
 * and where the user lands is decided by LoginRedirector — `primaryPanel()->homeRoute()`, or the
 * change-password screen when one is pending. This controller only orchestrates.
 */
final class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly LoginRedirector $redirector,
        private readonly LoginHistoryRecorder $history,
    ) {}

    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login', [
            'canResetPassword' => Route::has('password.request'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        /*
         * Laravel fires `Login` before the id above changes, so the history row written by
         * RecordSuccessfulLogin still carries the old session id. Re-stamping it keeps
         * login_histories.session_id join-able with the `sessions` table, which is how the logout
         * listener closes the right row.
         */
        $this->history->rebindSession($request->session()->getId());

        $user = $request->user();

        if ($user instanceof User && $user->mustChangePassword()) {
            $url = $this->redirector->passwordChangeUrl($user);

            if ($url !== null) {
                return redirect()->to($url)->with('toast', [
                    'type' => 'warning',
                    'message' => 'Please choose a new password before continuing.',
                ]);
            }
        }

        $home = $user instanceof User ? $this->redirector->homeUrl($user) : '/';

        return redirect()->intended($home)->with('toast', [
            'type' => 'success',
            'message' => 'Welcome back'.($user instanceof User ? ', '.$user->name : '').'.',
        ]);
    }

    /**
     * Destroy an authenticated session.
     *
     * The `logout` row in `login_histories` is written by RecordLogout, which the guard's Logout
     * event triggers — nothing to do here but tear the session down.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login')->with('toast', [
            'type' => 'success',
            'message' => 'You have been signed out.',
        ]);
    }
}
