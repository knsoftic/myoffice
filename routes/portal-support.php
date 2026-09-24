<?php

declare(strict_types=1);

use App\Http\Controllers\Portal\ConversationController;
use App\Http\Controllers\Portal\MeetingController;
use App\Http\Controllers\Portal\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tickets, meetings and messages on a portal - phase-19-23 sec 7.6, sec 8
|--------------------------------------------------------------------------
|
| Included from client.php, student.php, teacher.php and collaborator.php
| inside each one's existing group, so it inherits that panel's prefix, name
| prefix and middleware. `$portal` is the panel's ability prefix, e.g.
| `client_portal`.
|
| **One file, four panels, for the same reason as the bell.** A client, a
| student, a teacher and a collaborator all raise tickets, sit in meetings and
| send messages; the rules that differ between them are about who the *person*
| is, not which URL they arrived at - sec 9.4 scopes on identity and
| MessagingMatrix on the pair. Twelve controllers would agree about that until
| the day one of them was edited.
|
| **Every row-level check is a policy, not a permission.** A client invited to
| a kick-off holds no `meetings.*` permission at all; being in the room is the
| right. The panel ability below gates reaching the screen; the policy gates
| reaching the row.
|
| There is no destroy anywhere in this file. A ticket is never deleted, a
| message is never edited, and a meeting is cancelled by the person who called
| it - none of which is a portal's to do.
|
*/

Route::middleware('module:support_tickets')->group(static function () use ($portal): void {
    Route::get('tickets', [TicketController::class, 'index'])
        ->middleware('can:'.$portal.'.support_tickets')
        ->name('tickets.index');

    Route::get('tickets/new', [TicketController::class, 'create'])
        ->middleware('can:'.$portal.'.support_tickets')
        ->name('tickets.create');

    Route::post('tickets', [TicketController::class, 'store'])
        ->middleware(['can:'.$portal.'.support_tickets', 'throttle:10,1'])
        ->name('tickets.store');

    Route::get('tickets/{ticket}', [TicketController::class, 'show'])
        ->whereNumber('ticket')
        ->middleware('can:view,ticket')
        ->name('tickets.show');

    Route::post('tickets/{ticket}/replies', [TicketController::class, 'reply'])
        ->whereNumber('ticket')
        ->middleware(['can:reply,ticket', 'throttle:30,1'])
        ->name('tickets.replies.store');

    // The one status move sec 2.28.7 gives a portal.
    Route::post('tickets/{ticket}/reopen', [TicketController::class, 'reopen'])
        ->whereNumber('ticket')
        ->middleware('can:changeStatus,ticket')
        ->name('tickets.reopen');
});

Route::middleware('module:meetings')->group(static function () use ($portal): void {
    Route::get('meetings', [MeetingController::class, 'index'])
        ->middleware('can:'.$portal.'.meetings')
        ->name('meetings.index');

    Route::get('meetings/{meeting}', [MeetingController::class, 'show'])
        ->whereNumber('meeting')
        ->middleware('can:view,meeting')
        ->name('meetings.show');

    // Gated on the policy alone: accepting an invitation is what being in the
    // room means, and no portal role holds a `meetings.*` permission.
    Route::post('meetings/{meeting}/respond', [MeetingController::class, 'respond'])
        ->whereNumber('meeting')
        ->middleware('can:respond,meeting')
        ->name('meetings.respond');

    Route::get('meetings/{meeting}/ics', [MeetingController::class, 'ics'])
        ->whereNumber('meeting')
        ->middleware('can:downloadIcs,meeting')
        ->name('meetings.ics');
});

Route::middleware('module:messages')->group(static function () use ($portal): void {
    Route::get('messages', [ConversationController::class, 'index'])
        ->middleware('can:'.$portal.'.messages')
        ->name('messages.index');

    // Declared before `{conversation}` so the word is never read as an id.
    Route::get('messages/recipients', [ConversationController::class, 'recipients'])
        ->middleware(['can:'.$portal.'.messages', 'throttle:60,1'])
        ->name('messages.recipients');

    Route::post('messages', [ConversationController::class, 'store'])
        ->middleware(['can:'.$portal.'.messages', 'throttle:10,1'])
        ->name('messages.store');

    Route::get('messages/{conversation}', [ConversationController::class, 'show'])
        ->whereNumber('conversation')
        ->middleware('can:view,conversation')
        ->name('messages.show');

    // `can:send` re-asks MessagingMatrix on every request (INV-22-4), so removing
    // a pair from the settings silences an existing thread here and now. The
    // service enforces the rate limit as well as this middleware, because a
    // limit only one layer knows is a limit a queued job skips.
    Route::post('messages/{conversation}/send', [ConversationController::class, 'send'])
        ->whereNumber('conversation')
        ->middleware(['can:send,conversation', 'throttle:30,1'])
        ->name('messages.send');

    Route::post('messages/{conversation}/leave', [ConversationController::class, 'leave'])
        ->whereNumber('conversation')
        ->middleware('can:leave,conversation')
        ->name('messages.leave');
});
