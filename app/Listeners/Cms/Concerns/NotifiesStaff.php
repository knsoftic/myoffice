<?php

declare(strict_types=1);

namespace App\Listeners\Cms\Concerns;

use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Throwable;

/**
 * Recipient resolution and delivery for the phase-04 staff notifications (§10.2).
 *
 *   · Users: active accounts holding the named permission — directly or through a role. Resolved from
 *     the database at send time, never from a role name (CLAUDE.md rule 8). A permission not seeded yet
 *     resolves to nobody instead of throwing.
 *   · Addresses: one e-mail per line (or comma-separated) from a `website.*_notify_emails` textarea;
 *     anything that is not a valid address is ignored, and an address that already belongs to a user
 *     recipient is not mailed twice.
 *   · Delivery never throws into the business flow: a failure is reported and the next recipient is
 *     still tried. The listeners using this run queued, after the write has committed.
 */
trait NotifiesStaff
{
    /**
     * @return Collection<int, User>
     */
    protected function usersHolding(string ...$permissions): Collection
    {
        $users = new Collection;

        foreach ($permissions as $permission) {
            try {
                $users = $users->merge(User::query()->permission($permission)->active()->get());
            } catch (PermissionDoesNotExist) {
                continue;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $users->unique(static fn (User $user): int => (int) $user->getKey())->values();
    }

    /**
     * @return list<string>
     */
    protected function addressesFrom(string $settingKey): array
    {
        try {
            $raw = app(SettingsRepository::class)->get($settingKey, '');
        } catch (Throwable) {
            return [];
        }

        $parts = is_array($raw) ? $raw : preg_split('~[\r\n,;]+~u', (string) $raw);
        $addresses = [];

        foreach ((array) $parts as $part) {
            $address = Str::lower(trim((string) $part));

            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                $addresses[$address] = $address;
            }
        }

        return array_values($addresses);
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  list<string>  $addresses
     */
    protected function deliver(Collection $users, array $addresses, Notification $notification): void
    {
        foreach ($users as $user) {
            try {
                $user->notify(clone $notification);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $known = $users->map(static fn (User $user): string => Str::lower((string) $user->email))->all();

        foreach ($addresses as $address) {
            if (in_array($address, $known, true)) {
                continue;
            }

            try {
                NotificationFacade::route('mail', $address)->notify(clone $notification);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
