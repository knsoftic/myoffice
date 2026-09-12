<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmPasswordRequest;
use App\Models\User;
use App\Services\Auth\LoginRedirector;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Re-enter your password to open a protected area (routes `password.confirm`).
 *
 * Breeze's behaviour, except that the fallback destination is the user's own panel home
 * (LoginRedirector) rather than a hardcoded `dashboard` route.
 */
final class ConfirmablePasswordController extends Controller
{
    public function __construct(private readonly LoginRedirector $redirector) {}

    /**
     * Show the confirm password view.
     */
    public function show(): View
    {
        return view('auth.confirm-password');
    }

    /**
     * Confirm the user's password.
     */
    public function store(ConfirmPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        if (! Auth::guard('web')->validate([
            'email' => $user->email,
            'password' => (string) $request->validated('password'),
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended($this->redirector->homeUrl($user));
    }
}
