<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authentication and account routes (phase-01 §7, §8)
|--------------------------------------------------------------------------
|
| Required from routes/web.php, so everything here lives in the `web` middleware group.
|
| Two things differ from stock Breeze:
|
|   1. there are no `register` routes — public registration is removed (decision D15), so
|      /register is a 404. Accounts are created by an administrator in the users module;
|   2. the `/account` area (names `account.*`) is registered here rather than per panel,
|      because all five panels share one set of account screens. The shell resolves its links
|      through Route::has(), which is why these names are fixed:
|      `account.profile`, `account.password`, `account.sessions`, `account.theme.update`.
|
*/

use App\Http\Controllers\Account\PasswordController as AccountPasswordController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\SessionController;
use App\Http\Controllers\Account\ThemeController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:20,1');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.store');
});

/*
| `active` belongs on the whole authenticated group, not just on /account.
|
| EnsureUserIsActive is what makes a status change take effect immediately: it only runs on the
| routes that list it, so any authenticated route without it stays open to an account that has
| since been suspended or deactivated. Leaving it off meant a suspended user who still held a
| session could re-set their own password on PUT /password, re-send verification mail and confirm
| their password — the account state would be enforced everywhere except on the endpoints that
| change the credentials.
|
| The forced-password-change flow is unaffected: EnsureUserIsActive::ALWAYS_ALLOWED whitelists
| `logout`, `password.update`, `password.confirm` and `account.theme.update`, and the
| change-password screen plus its own sub-actions (`account.password`, `account.password.update`)
| are always reachable while the change is pending.
*/
Route::middleware(['auth', 'active'])->group(function (): void {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    // Breeze's endpoint, kept by name: the `active` middleware treats `password.update` as
    // always reachable while a forced password change is pending.
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    /*
    |----------------------------------------------------------------------
    | /account — shared by every panel
    |----------------------------------------------------------------------
    | `active` is inherited from the enclosing group above, so it is not
    | repeated here.
    */
    Route::prefix('account')
        ->name('account.')
        ->group(function (): void {
            Route::get('profile', [ProfileController::class, 'edit'])->name('profile');
            Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::post('avatar', [ProfileController::class, 'storeAvatar'])->name('avatar.store');
            Route::delete('avatar', [ProfileController::class, 'destroyAvatar'])->name('avatar.destroy');

            Route::get('password', [AccountPasswordController::class, 'edit'])->name('password');
            Route::put('password', [AccountPasswordController::class, 'update'])->name('password.update');

            // The endpoint resources/js/theme.js posts to (phase-01 §9).
            Route::put('theme', ThemeController::class)->name('theme.update');

            Route::get('sessions', [SessionController::class, 'index'])->name('sessions');
            Route::delete('sessions', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
            Route::delete('sessions/{session}', [SessionController::class, 'destroy'])
                ->where('session', '[A-Za-z0-9]+')
                ->name('sessions.destroy');

            Route::get('login-history', [SessionController::class, 'history'])->name('login-history');
        });
});
