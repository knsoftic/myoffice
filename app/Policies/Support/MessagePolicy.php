<?php

declare(strict_types=1);

namespace App\Policies\Support;

use App\Models\Support\Message;
use App\Models\User;
use App\Policies\Support\Concerns\ChecksSupportPermissions;

/**
 * What may be done to a single message (phase-19-23 §6.18, INV-22-1's shape applied to §94).
 *
 * **Almost nothing, and that is the whole file.** A message is the record of what was said to
 * somebody; a wrong one is corrected by another message, never edited and never removed. `Message`
 * has an `updating` hook with a short allowlist of columns that record what happened *to* it, and a
 * `deleting` hook that throws unconditionally — both below `Gate::before`, so no role walks past
 * them (D124, D140).
 *
 * **This exists so no screen ever renders the button.** `messages.edit`, `.delete` and `.restore`
 * are still held by roles seeded before §9.4 was enforced, and D65 keeps a seeder from revoking what
 * an administrator holds. A view that asked the permission would offer an edit that throws; a view
 * that asks the policy is told no. The permission rows are inert, and this is what makes them look
 * inert from the outside as well.
 */
final class MessagePolicy
{
    use ChecksSupportPermissions;

    public const MODULE = 'messages';

    public function view(User $user, Message $message): bool
    {
        $conversation = $message->conversation;

        return $conversation !== null && $user->can('view', $conversation);
    }

    /** A sent message is never edited. The model refuses it; this stops the button existing. */
    public function update(User $user, Message $message): bool
    {
        return false;
    }

    public function delete(User $user, Message $message): bool
    {
        return false;
    }

    public function restore(User $user, Message $message): bool
    {
        return false;
    }

    public function forceDelete(User $user, Message $message): bool
    {
        return false;
    }

    /** Downloading an attachment is reading the message it hangs on, and nothing more. */
    public function download(User $user, Message $message): bool
    {
        return $this->view($user, $message);
    }
}
