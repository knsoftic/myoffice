<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Auth\LoginHistoryRecorder;
use Illuminate\Auth\Events\Login;
use Throwable;

/**
 * `Illuminate\Auth\Events\Login` → a `success` row in `login_histories`, plus
 * `users.last_login_at` / `last_login_ip` and an activity entry (phase-01 §7).
 *
 * Registered in App\Providers\EventListenerServiceProvider.
 *
 * A user whose `status` may not log in is deliberately **not** recorded as a success here; the
 * logout that LoginRequest performs immediately afterwards records the `blocked` row instead.
 */
final class RecordSuccessfulLogin
{
    public function __construct(private readonly LoginHistoryRecorder $history) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        try {
            $this->history->success($event->user);
        } catch (Throwable $exception) {
            // Audit bookkeeping must never cost a user their sign-in.
            report($exception);
        }
    }
}
