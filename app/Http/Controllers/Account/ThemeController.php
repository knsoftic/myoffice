<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateThemeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * PUT /account/theme — route name `account.theme.update` (phase-01 §9).
 *
 * The endpoint the UI shell calls: `resources/js/theme.js` mirrors the switcher onto the user row
 * with a JSON PUT, and `layouts/partials/head.blade.php` only prints the endpoint once this route
 * exists. A plain form POST is answered with a redirect, so the switcher still works without JS.
 *
 * Single action controller: there is no screen, only this preference.
 */
final class ThemeController extends Controller
{
    public function __invoke(UpdateThemeRequest $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $theme = $request->theme();

        /*
         * saveQuietly: a cosmetic preference is not an audit event, and the switcher can be used
         * several times a minute — writing an activity row (and an updated_by stamp) for each
         * would be noise in the trail.
         */
        $user->theme = $theme;
        $user->saveQuietly();

        if ($request->expectsJson()) {
            return response()->json([
                'theme' => $theme->value,
                'label' => $theme->label(),
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Theme set to '.$theme->label().'.',
        ]);
    }
}
