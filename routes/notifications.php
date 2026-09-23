<?php

declare(strict_types=1);

use App\Http\Controllers\Support\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The bell - registered once, included by every panel (phase-19-23 sec 7.7)
|--------------------------------------------------------------------------
|
| Included from admin.php, client.php, student.php, teacher.php and
| collaborator.php inside each one's existing group, so it inherits that
| panel's prefix, name prefix and auth middleware. `$ability` is the panel's
| own permission - `notifications.view_any` for staff, `<panel>_portal.notifications`
| for a portal.
|
| **Five copies would drift, and the one that drifted would be the portal
| nobody opens by hand.** The contract says the bell behaves identically
| everywhere; the only honest way to guarantee that is one file.
|
| `NotificationController::panel()` reads the current panel from the ROUTE
| NAME rather than the URL, which is why every name below is unprefixed here
| and prefixed by the including group.
|
| `bell` is the polling endpoint and is throttled hard: a topbar that polls
| is a request per open tab per interval, and the count it returns is two
| indexed queries.
|
| `go` is a GET that marks a row read and redirects. It changes state on a
| GET, which is normally wrong - but the alternative is a POST behind every
| notification link, and a link in an email cannot be a POST. The state it
| changes is the viewer's own read flag on their own row, which is the one
| case where that trade is right.
|
*/

Route::middleware('module:notifications')->group(static function () use ($ability): void {
    Route::get('notifications', [NotificationController::class, 'index'])
        ->middleware('can:'.$ability)
        ->name('notifications.index');

    Route::get('notifications/bell', [NotificationController::class, 'bell'])
        ->middleware(['can:'.$ability, 'throttle:120,1'])
        ->name('notifications.bell');

    Route::get('notifications/preferences', [NotificationController::class, 'preferences'])
        // Deliberately NOT gated on the panel ability: sec 9.4 says a person's own preference rows
        // need no permission. Somebody whose bell has been switched off at module level still
        // cannot reach this, because `module:notifications` wraps the group.
        ->name('notifications.preferences');

    Route::put('notifications/preferences', [NotificationController::class, 'savePreferences'])
        ->name('notifications.preferences.save');

    Route::post('notifications/preferences/reset', [NotificationController::class, 'resetPreferences'])
        ->name('notifications.preferences.reset');

    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])
        ->middleware('can:'.$ability)
        ->name('notifications.read-all');

    Route::post('notifications/archive-all', [NotificationController::class, 'archiveAll'])
        ->middleware('can:'.$ability)
        ->name('notifications.archive-all');

    // The uuid constraint keeps a probe for `/notifications/1/read` out of the controller entirely.
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
        ->whereUuid('notification')
        ->middleware('can:'.$ability)
        ->name('notifications.read');

    Route::post('notifications/{notification}/archive', [NotificationController::class, 'archive'])
        ->whereUuid('notification')
        ->middleware('can:'.$ability)
        ->name('notifications.archive');

    Route::get('notifications/{notification}/go', [NotificationController::class, 'go'])
        ->whereUuid('notification')
        ->middleware('can:'.$ability)
        ->name('notifications.go');
});
