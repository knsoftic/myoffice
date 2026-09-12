<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Models\User;
use App\Services\Auth\PasswordChangeService;
use Illuminate\Http\RedirectResponse;

/**
 * Breeze's `PUT /password` endpoint (route name `password.update`), kept as the contract requires
 * (phase-01 §7: keep Breeze's routes) and because the `active` middleware treats that route name
 * as always reachable while a password change is pending.
 *
 * The screen a user actually sees is `account.password`; both endpoints share one Form Request and
 * one service, so the rules and the side effects can never drift apart.
 */
final class PasswordController extends Controller
{
    public function __construct(private readonly PasswordChangeService $passwords) {}

    /**
     * Update the user's password.
     */
    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $this->passwords->change($user, $request->newPassword());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Password changed. Any other device signed in as you has been signed out.',
        ]);
    }
}
