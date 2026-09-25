<?php

declare(strict_types=1);

namespace App\Policies\Support;

use App\Enums\Ability;
use App\Models\Support\Conversation;
use App\Models\User;
use App\Policies\Support\Concerns\ChecksSupportPermissions;
use App\Support\MessagingMatrix;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;

/**
 * Who may open, read and write in a thread (phase-19-23 §9.4, §6.18, requirement §94).
 *
 * **A conversation is personal, never corporate.** Every check here is
 * `conversation_participants.user_id = this user AND left_at IS NULL` — never a `client_id`, never a
 * company, never a branch. That is phase-05 §9.2's rule restated, and it is the difference between
 * a colleague at the same firm seeing your thread and not.
 *
 * **`messages.view_any` exists and is granted to nobody.** It is there for a future compliance
 * reader; it makes a thread *readable* and never writable, and every read it permits is logged as
 * an activity row (§9.4). Treating it as a general "staff can see messages" permission would make
 * the §94 matrix decorative.
 *
 * **The matrix is re-checked on every send, not cached on the thread** (INV-22-4). Removing a pair
 * from `support.messaging_allowed_pairs` silences existing threads, which only works if the answer
 * is asked again each time — so `send()` here asks `MessagingMatrix`, not the row.
 */
final class ConversationPolicy
{
    use ChecksSupportPermissions;

    public const MODULE = 'messages';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            || $this->holds($user, self::MODULE, Ability::ViewAny)
            || $this->onAPortal($user);
    }

    /**
     * **404, never 403, for a non-participant.**
     *
     * phase-19-23 §7.11 annotates this very gate — `can:view,conversation` **(404 for a
     * non-participant)** — and PH22-32 states it as a test: "A non-participant gets **404** on a
     * conversation and on a message attachment." A 403 would answer the question the thread is being
     * protected from: it confirms that conversation id exists, which turns a sequential key into a
     * roster of who is talking to whom. That matters most between two portal logins of the *same*
     * company, where the id is one apart from a thread the reader legitimately holds
     * (phase-24-25 section 11.3 ISO-11).
     *
     * `Response::denyAsNotFound()` is how every other scoped gate in this application says it
     * ({@see \App\Policies\Crm\ClientPolicy::viewOwn()}, `CollaboratorPolicy`, `ClientPortalPolicy`),
     * and it answers 404 through the route middleware and `Gate::authorize()` alike.
     *
     * The boolean predicate stays available as {@see canRead()} because {@see close()} composes it with
     * `&&`, and a `Response` object is truthy — a deny expressed only as a Response would silently let
     * a non-participant holding `messages.change_status` close somebody else's thread.
     */
    public function view(User $user, Conversation $conversation): Response|bool
    {
        return $this->canRead($user, $conversation) ? true : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        if (! (bool) setting('support.messaging_enabled', true)) {
            return false;
        }

        return $this->holds($user, self::MODULE, Ability::Create) || $this->onAPortal($user);
    }

    /**
     * Writing in a thread.
     *
     * **Never granted by `view_any`.** The compliance reader reads; a reader who could also write
     * would be a person outside the §94 matrix with a voice inside it, and nothing in the pair
     * rules would have been consulted.
     */
    public function send(User $user, Conversation $conversation): bool
    {
        if ($conversation->getAttribute('closed_at') !== null) {
            return false;
        }

        if (! $this->isLiveParticipant($user, $conversation)) {
            return false;
        }

        return MessagingMatrix::mayParticipate($conversation, $user)->allowed;
    }

    /** Adding somebody to a group. A direct thread's two seats are fixed by its `direct_key`. */
    public function addParticipant(User $user, Conversation $conversation): bool
    {
        return $conversation->getAttribute('type') === 'group' && $this->send($user, $conversation);
    }

    public function removeParticipant(User $user, Conversation $conversation): bool
    {
        return $this->addParticipant($user, $conversation);
    }

    public function leave(User $user, Conversation $conversation): bool
    {
        return $this->isLiveParticipant($user, $conversation);
    }

    /** Closing a thread. Readable for ever afterwards, writable by nobody. */
    public function close(User $user, Conversation $conversation): bool
    {
        return $conversation->getAttribute('closed_at') === null
            && $this->holds($user, self::MODULE, Ability::ChangeStatus)
            // `canRead()`, not `view()`: `view()` now returns a `Response` on a deny, and every object
            // is truthy — `&& $response` would evaluate to true and hand a non-participant the close
            // button.
            && $this->canRead($user, $conversation);
    }

    /**
     * Nothing deletes a thread or a message (INV-22-1's shape, applied to §94).
     *
     * A message is the record of what was said to somebody; a wrong one is corrected by another
     * message. `Message`'s `deleting` hook refuses below the gate.
     */
    public function delete(User $user, Conversation $conversation): bool
    {
        return false;
    }

    public function forceDelete(User $user, Conversation $conversation): bool
    {
        return false;
    }

    // ===============================================================================================

    /**
     * May this user read the thread at all — the boolean behind {@see view()}.
     *
     * Separate from `view()` so callers that compose the answer with `&&` get a boolean rather than the
     * truthy `Response` that carries the 404.
     */
    private function canRead(User $user, Conversation $conversation): bool
    {
        return $this->isLiveParticipant($user, $conversation)
            || $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    /**
     * The one clause every scope in §9.4 shares — and `left_at IS NULL` is half of it.
     *
     * Somebody who left a thread keeps their place in its history and loses their place in it. A
     * check that forgot the second half would let a person who left a group keep reading it for
     * ever, which is the opposite of what leaving means.
     */
    private function isLiveParticipant(User $user, Conversation $conversation): bool
    {
        return DB::table('conversation_participants')
            ->where('conversation_id', $conversation->getKey())
            ->where('user_id', $user->getKey())
            ->whereNull('left_at')
            ->exists();
    }

    private function onAPortal(User $user): bool
    {
        foreach (['client', 'student', 'teacher', 'collaborator'] as $panel) {
            if ($user->can($panel.'_portal.messages')) {
                return true;
            }
        }

        return false;
    }
}
