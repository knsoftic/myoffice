<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\LoginRedirector;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The "verify your email" screen (route `verification.notice`).
 *
 * An already-verified user is sent on to their own panel home, resolved by LoginRedirector.
 */
final class EmailVerificationPromptController extends Controller
{
    public function __construct(private readonly LoginRedirector $redirector) {}

    public function __invoke(Request $request): RedirectResponse|View
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasVerifiedEmail()) {
            return redirect()->intended($this->redirector->homeUrl($user));
        }

        return view('auth.verify-email');
    }
}
