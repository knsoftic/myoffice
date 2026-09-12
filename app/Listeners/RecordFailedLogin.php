<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Auth\LoginHistoryRecorder;
use Illuminate\Auth\Events\Failed;
use Throwable;

/**
 * `Illuminate\Auth\Events\Failed` → a `failed` row in `login_histories` (phase-01 §7).
 *
 * Registered in App\Providers\EventListenerServiceProvider.
 *
 * The attempted email is captured from the credentials so an administrator can see what was
 * tried. The listener returns nothing and changes no response: whether the address belongs to an
 * account is never revealed to the visitor — Breeze answers every failure with the same
 * `auth.failed` message.
 */
final class RecordFailedLogin
{
    public function __construct(private readonly LoginHistoryRecorder $history) {}

    public function handle(Failed $event): void
    {
        try {
            $credentials = $event->credentials;

            $email = is_array($credentials) && isset($credentials['email']) && is_string($credentials['email'])
                ? $credentials['email']
                : null;

            $this->history->failed($email, $event->user instanceof User ? $event->user : null);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
