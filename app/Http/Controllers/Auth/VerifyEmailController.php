<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\LoginRedirector;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

/**
 * The signed link from the verification email (route `verification.verify`).
 *
 * Destination is the user's own panel home with `?verified=1`, resolved by LoginRedirector so no
 * panel is hardcoded here.
 */
final class VerifyEmailController extends Controller
{
    public function __construct(private readonly LoginRedirector $redirector) {}

    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->intended($this->verifiedUrl($user));
    }

    /**
     * Panel home with the `verified=1` marker the shell can greet the user with.
     */
    private function verifiedUrl(User $user): string
    {
        $home = $this->redirector->homeUrl($user);

        return $home.(str_contains($home, '?') ? '&' : '?').'verified=1';
    }
}
