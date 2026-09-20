<?php

declare(strict_types=1);

namespace App\Policies\Project;

use App\Enums\Ability;
use App\Enums\AttachmentVisibility;
use App\Models\Project\Attachment;
use App\Models\User;
use App\Policies\Project\Concerns\ChecksProjectPermissions;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Who may read and remove a file (phase-06 §2.9, §9's "Attachments" row, requirement §96).
 *
 * **The subject is authorised before the attachment** — that is the whole design. A file has no meaning of
 * its own: whether you may read it is whether you may read the thing it is attached to, and only then
 * whether you hold `files.download`. Asking in the other order would let anyone with the files permission
 * read every project's documents.
 *
 * A subject whose model does not exist in this build — `collaborator` before Phase 8, `invoice` before
 * Phase 13 — must read as a **404**, never a 500 (F-13.2).
 *
 * `internal` visibility is invisible to collaborators and clients; `client` is the only visibility a
 * client ever sees. Deleting belongs to the uploader or to `files.delete`.
 */
final class AttachmentPolicy
{
    use ChecksProjectPermissions;

    public const MODULE = 'files';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            || $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Attachment $attachment): bool|Response
    {
        if (! $this->holds($user, self::MODULE, Ability::View)) {
            return false;
        }

        return $this->throughSubject($user, $attachment);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Upload);
    }

    /**
     * §60: a download re-runs the whole chain and writes an activity row naming the actor.
     */
    public function download(User $user, Attachment $attachment): bool|Response
    {
        if (! $this->holds($user, self::MODULE, Ability::Download)) {
            return false;
        }

        return $this->throughSubject($user, $attachment);
    }

    public function delete(User $user, Attachment $attachment): bool|Response
    {
        if ($this->isTrashed($attachment)) {
            return false;
        }

        if ((int) $attachment->uploaded_by === (int) $user->getKey()) {
            return true;
        }

        if (! $this->holds($user, self::MODULE, Ability::Delete)) {
            return false;
        }

        return $this->throughSubject($user, $attachment);
    }

    /**
     * Resolve the subject, authorise **it**, then apply the visibility rule.
     */
    private function throughSubject(User $user, Attachment $attachment): bool|Response
    {
        $subject = $attachment->attachable;

        // Phase 8's collaborator and Phase 13's invoice are declared in the morph map but not shipped:
        // a row pointing at one is unreachable, which has to look like "no such file".
        if (! $subject instanceof Model) {
            return Response::denyAsNotFound();
        }

        $onSubject = Gate::forUser($user)->inspect('view', $subject);

        if (! $onSubject->allowed()) {
            return $onSubject->code() === Response::HTTP_NOT_FOUND
                ? Response::denyAsNotFound()
                : false;
        }

        return $this->passesVisibility($user, $attachment) ? true : Response::denyAsNotFound();
    }

    private function passesVisibility(User $user, Attachment $attachment): bool
    {
        $visibility = $attachment->visibility;

        if ($visibility === AttachmentVisibility::Internal) {
            // Staff only: anybody reaching this through a portal permission is not staff.
            return ! $user->hasRole(['Client', 'Collaborator']);
        }

        if ($visibility === AttachmentVisibility::Team) {
            return ! $user->hasRole('Client');
        }

        return true;
    }
}
