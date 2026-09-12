<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Auth\LoginHistoryRecorder;
use Illuminate\Auth\Events\Logout;
use Throwable;

/**
 * `Illuminate\Auth\Events\Logout` → a `logout` row in `login_histories`, and `logged_out_at`
 * stamped on the `success` row of the session being closed (phase-01 §7).
 *
 * Registered in App\Providers\EventListenerServiceProvider.
 *
 * When the account may no longer log in — LoginRequest refusing a suspended user with valid
 * credentials, or the `active` middleware tearing down a session mid-flight — the row is written
 * with status `blocked` instead. The recorder owns that decision so both paths agree.
 */
final class RecordLogout
{
    public function __construct(private readonly LoginHistoryRecorder $history) {}

    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        try {
            $this->history->logout($event->user);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
