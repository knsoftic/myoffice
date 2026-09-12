<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Services\Auth\LoginRedirector;
use App\Services\Auth\PasswordChangeService;
use App\Services\Auth\PasswordPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Change your own password (routes `account.password*`, phase-01 §7).
 *
 * This is also the screen the `active` middleware pins a user to while
 * `users.must_change_password` is set, so it has to work before anything else in the app is
 * reachable — hence no permission checks beyond `auth`, and a redirect to the panel home once a
 * forced change is done.
 *
 * All the work (hashing, stamping `password_changed_at`, clearing `must_change_password`,
 * revoking other sessions, the audit entry) lives in PasswordChangeService.
 */
final class PasswordController extends AccountController
{
    public function __construct(
        private readonly PasswordChangeService $passwords,
        private readonly LoginRedirector $redirector,
    ) {}

    public function edit(): View
    {
        $user = $this->currentUser();

        return $this->render('account.password', self::TAB_PASSWORD, [
            'user' => $user,
            'forced' => $user->mustChangePassword(),
            'passwordHint' => PasswordPolicy::hint(),
        ]);
    }

    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $this->currentUser();
        $wasForced = $user->mustChangePassword();

        $this->passwords->change($user, $request->newPassword());

        $toast = [
            'type' => 'success',
            'message' => 'Password changed. Any other device signed in as you has been signed out.',
        ];

        return $wasForced
            ? redirect()->to($this->redirector->homeUrl($user))->with('toast', $toast)
            : redirect()->route('account.password')->with('toast', $toast);
    }
}
