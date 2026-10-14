<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| One channel, and it is private: a person's own bell. Nobody subscribes to
| anybody else's — the id in the channel name must be the signed-in user's —
| and a deactivated account or a switched-off notifications module gets no
| socket at all, the same rule the bell's HTTP routes apply.
|
| `/broadcasting/auth` (the route that asks this file) runs behind `web`,
| `auth` and `active`; see `withBroadcasting()` in bootstrap/app.php.
|
*/

// The pattern behind `Realtime::userChannel()`.
Broadcast::channel(
    'App.Models.User.{id}',
    static fn (User $user, string $id): bool => (int) $user->getKey() === (int) $id
        && $user->status === UserStatus::Active
        && Modules::enabled('notifications'),
);
