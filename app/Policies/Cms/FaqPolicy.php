<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\Faq;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * Who may manage FAQs (phase-03 §4.2, §6.13, §7.4): `faqs` = `CRUD` + `STATUS`.
 *
 * An **attached** FAQ (`faqable_*` set — a course FAQ of §90) is owned by its owner's module: it is
 * written by that phase's service under that module's `edit` (e.g. `courses.edit`), never by the CMS
 * FAQ form (§6.13, F-2.2). So every write to an attached FAQ additionally needs `{owner module}.edit`,
 * and an owner whose module cannot be resolved is refused (fail closed). Standalone FAQs follow `faqs.*`.
 */
final class FaqPolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'faqs';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewAny);
    }

    public function view(User $user, Faq $faq): bool
    {
        return $this->allows($user, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Ability::Create);
    }

    public function update(User $user, Faq $faq): bool
    {
        return $this->allows($user, Ability::Edit)
            && ! $this->isTrashed($faq)
            && $this->ownerAllows($user, $faq);
    }

    /**
     * Publish / draft / archive (§6.13 `FaqService::toggle()`).
     */
    public function toggle(User $user, Faq $faq): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($faq)
            && $this->ownerAllows($user, $faq);
    }

    /**
     * Reorder one category (or the uncategorised bucket). Class-level.
     */
    public function reorder(User $user): bool
    {
        return $this->allows($user, Ability::Edit);
    }

    public function delete(User $user, Faq $faq): bool
    {
        return $this->allows($user, Ability::Delete)
            && ! $this->isTrashed($faq)
            && $this->ownerAllows($user, $faq);
    }

    public function restore(User $user, Faq $faq): bool
    {
        return $this->allows($user, Ability::Restore)
            && $this->isTrashed($faq)
            && $this->ownerAllows($user, $faq);
    }

    /**
     * INV-14: never hard-deleted from the UI.
     */
    public function forceDelete(User $user, Faq $faq): bool
    {
        return false;
    }

    /**
     * Standalone: nothing more to check. Attached: the owner module's `edit`, resolved through Phase 1's
     * model => module resolver; unresolvable owner → refused.
     */
    private function ownerAllows(User $user, Faq $faq): bool
    {
        if (! $faq->isAttached()) {
            return true;
        }

        $type = (string) $faq->faqable_type;
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return false;
        }

        try {
            $module = Modules::moduleForSubject(new $class);
        } catch (Throwable) {
            return false;
        }

        return $module !== null && $this->allows($user, Ability::Edit, $module);
    }
}
