<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\LoginRedirector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Resend the verification email (route `verification.send`, throttled to 6/minute).
 */
final class EmailVerificationNotificationController extends Controller
{
    public function __construct(private readonly LoginRedirector $redirector) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended($this->redirector->homeUrl($user));
        }

        $user->sendEmailVerificationNotification();

        return back()->with('auth_status', 'verification-link-sent');
    }
}
