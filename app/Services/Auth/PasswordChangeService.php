<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sets a new password and cleans up everything that has to follow (phase-01 §7).
 *
 * One public entry point, used by the account change-password screen and by the reset-link
 * flow:
 *
 *   $service->change($user, $plainPassword);
 *
 * What it guarantees:
 *   · the hash is replaced (the model's `hashed` cast does the hashing);
 *   · `password_changed_at` is stamped and `must_change_password` cleared, so the `active`
 *     middleware stops forcing the change screen;
 *   · `remember_token` is rotated, which kills every "remember me" cookie issued before;
 *   · other sessions are invalidated — `Auth::logoutOtherDevices()` for the session guard, and
 *     the other `sessions` rows of that user are deleted outright on the database driver, which
 *     is what actually revokes them;
 *   · an audit entry is written.
 */
final class PasswordChangeService
{
    public function __construct(private readonly AuthActivityLogger $activity) {}

    /**
     * @param  string  $plainPassword  already validated by a Form Request
     * @param  string|null  $reason  why the password changed, for the audit entry
     */
    public function change(User $user, string $plainPassword, ?string $reason = null): void
    {
        $user->forceFill([
            'password' => $plainPassword,
            'password_changed_at' => now(),
            'must_change_password' => false,
            'remember_token' => Str::random(60),
        ])->save();

        $this->invalidateOtherSessions($user, $plainPassword);

        $this->activity->record(
            'Password changed',
            $user,
            'password_changed',
            'users',
            array_filter(['reason' => $reason]),
        );
    }

    /**
     * Revoke every session except the one making this request.
     */
    private function invalidateOtherSessions(User $user, string $plainPassword): void
    {
        try {
            // Re-stamps the session's password hash and re-issues this device's recaller
            // cookie, so the current session survives and the others stop validating.
            if (Auth::check() && (string) Auth::id() === (string) $user->getKey()) {
                Auth::logoutOtherDevices($plainPassword);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->purgeStoredSessions($user);
    }

    /**
     * Delete the user's other rows from the `sessions` table.
     *
     * With the driver this project runs on (`SESSION_DRIVER=database`) this is the verifiable
     * part of "other sessions are gone": the row is no longer there to restore. The only guard is
     * whether the table exists — the driver is not consulted, so a stored session is revoked even
     * if the driver is switched later.
     */
    private function purgeStoredSessions(User $user): void
    {
        try {
            if (! Schema::hasTable('sessions')) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        try {
            $current = request()->hasSession() ? request()->session()->getId() : null;

            Session::query()
                ->where('user_id', $user->getKey())
                ->when($current !== null, fn ($query) => $query->where('id', '!=', $current))
                ->delete();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
