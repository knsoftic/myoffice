<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\NewPasswordRequest;
use App\Models\User;
use App\Services\Auth\PasswordChangeService;
use App\Services\Auth\PasswordPolicy;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * Choosing a new password from a reset link (routes `password.reset`, `password.store`).
 *
 * Breeze's flow, with the project's own password handling plugged in: the same strength rules as
 * the account screen (NewPasswordRequest), and PasswordChangeService for the write — so a reset
 * also stamps `password_changed_at`, clears `must_change_password`, rotates the remember token and
 * revokes every session the account had open. A stolen session cannot outlive a password reset.
 */
final class NewPasswordController extends Controller
{
    public function __construct(private readonly PasswordChangeService $passwords) {}

    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', [
            'request' => $request,
            'passwordHint' => PasswordPolicy::hint(),
        ]);
    }

    /**
     * Handle an incoming new password request.
     */
    public function store(NewPasswordRequest $request): RedirectResponse
    {
        $password = (string) $request->validated('password');

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($password): void {
                $this->passwords->change($user, $password, 'Password reset by email link');

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()
                ->route('login')
                ->with('auth_status', __($status));
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => __($status)]);
    }
}
