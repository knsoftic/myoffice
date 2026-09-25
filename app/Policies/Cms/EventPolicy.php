<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\Event;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;

/**
 * Who may write, publish and withdraw events.
 *
 * `events` = `CRUD_FULL` + `STATUS` + `FILES` + `RESTORE`, and that is the whole ability list:
 * `view_any`, `view`, `create`, `edit`, `delete`, `export`, `print`, `change_status`, `upload`,
 * `download`, `restore`. **No `approve` and no `assign`** — a post moves through an editorial workflow
 * and belongs to an author; an event is a date somebody publishes. There is therefore no author/editor
 * row split here, and no `reaches()`: every event is every holder's to work on, which is why
 * `PermissionRegistry` does not declare an ability this policy would have to invent a meaning for.
 *
 * **`change_status` is a separate ability from `edit`, and this policy never lets one stand in for the
 * other.** {@see update()} asks for `events.edit` and nothing else; {@see changeStatus()} asks for
 * `events.change_status` and nothing else. Neither falls back to the other, so:
 *
 *   · `events.edit` alone can rewrite every column of a live event — and cannot publish, schedule,
 *     unpublish, archive or feature one, nor take a published one off the site;
 *   · `events.change_status` alone can publish and withdraw — and cannot change a word of the text.
 *
 * The model backs the same line: `status`, `published_at` and `is_featured` are not in `$fillable`, so a
 * hand-crafted extra field posted at `admin.events.update` cannot reach them even if this policy were
 * bypassed. `publish()`, `schedule()`, `unpublish()`, `archive()` and `toggleFeatured()` all delegate
 * here, because each of them is the same decision: "may this user change what the public sees?"
 *
 * A trashed event is read-only until it is restored: every write ability refuses one, and `restore` is
 * the only ability that requires one.
 */
final class EventPolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'events';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewAny);
    }

    /**
     * No ownership test: an event has no author. The module permission is the whole answer.
     */
    public function view(User $user, Event $event): bool
    {
        return $this->allows($user, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Ability::Create);
    }

    /**
     * Editing the text, the times, the place and the cover — never the publication state.
     *
     * This deliberately does **not** consult `change_status`: an editor who may not publish may still
     * fix a typo on something that is already live, and that is the right answer, not a loophole. What
     * they may not do is put it live in the first place.
     */
    public function update(User $user, Event $event): bool
    {
        return $this->allows($user, Ability::Edit)
            && ! $this->isTrashed($event);
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->allows($user, Ability::Delete)
            && ! $this->isTrashed($event);
    }

    public function restore(User $user, Event $event): bool
    {
        return $this->allows($user, Ability::Restore)
            && $this->isTrashed($event);
    }

    /**
     * An event is never hard-deleted from the UI: deleting soft-deletes and keeps the slug reserved, so
     * a published permalink can never be handed to a different event later.
     */
    public function forceDelete(User $user, Event $event): bool
    {
        return false;
    }

    /**
     * Publish / schedule / unpublish / archive — `admin.events.status`.
     *
     * This deliberately does **not** consult `edit`. Holding `events.edit` grants nothing here; holding
     * `events.change_status` grants this whether or not the user may edit. That is the whole point of
     * the two abilities being separate in `PermissionRegistry`.
     */
    public function changeStatus(User $user, Event $event): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($event);
    }

    public function publish(User $user, Event $event): bool
    {
        return $this->changeStatus($user, $event);
    }

    public function schedule(User $user, Event $event): bool
    {
        return $this->changeStatus($user, $event);
    }

    public function unpublish(User $user, Event $event): bool
    {
        return $this->changeStatus($user, $event);
    }

    public function archive(User $user, Event $event): bool
    {
        return $this->changeStatus($user, $event);
    }

    /**
     * Featuring is a decision about what the public sees first, not about the event's content — so it
     * sits with `change_status` (`admin.events.featured`), exactly as the success stories do it, and
     * never with `edit`.
     */
    public function toggleFeatured(User $user, Event $event): bool
    {
        return $this->changeStatus($user, $event);
    }

    /**
     * The cover image comes from the media library (D24); `upload` is what lets the picker's "upload a
     * new image" path exist at all for this module.
     */
    public function upload(User $user): bool
    {
        return $this->allows($user, Ability::Upload);
    }

    public function download(User $user): bool
    {
        return $this->allows($user, Ability::Download);
    }

    public function export(User $user): bool
    {
        return $this->allows($user, Ability::Export);
    }

    public function print(User $user): bool
    {
        return $this->allows($user, Ability::Print);
    }
}
