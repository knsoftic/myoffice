<?php

declare(strict_types=1);

namespace App\Policies\Cms\Concerns;

use App\Enums\Ability;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The moderation queue actions shared by `TestimonialPolicy` and `StudentReviewPolicy` (phase-04 §4,
 * §6.5, §7.2). Use together with {@see AuthorizesContentModule}.
 *
 *   approve       → `{module}.approve`               reject → `{module}.reject`
 *   bulkApprove   → `{module}.approve` (class-level)  reset  → `approve` or `reject` (back to pending)
 *   toggleFeatured → `{module}.change_status`
 *
 * The policy never looks at the record's moderation state: an illegal transition (featuring a pending
 * review, approving an approved one) is `ModerationService`'s domain refusal, a 422 — not a 403 (§11
 * tests 17, 19, 20). `testimonials.view_any` without `approve` renders the queue and 403s approve.
 */
trait AuthorizesModeration
{
    public function approve(User $user, Model $model): bool
    {
        return $this->allows($user, Ability::Approve)
            && ! $this->isTrashed($model);
    }

    public function reject(User $user, Model $model): bool
    {
        return $this->allows($user, Ability::Reject)
            && ! $this->isTrashed($model);
    }

    /**
     * Any → pending (`ModerationService::reset()`): part of running the queue, so either queue ability.
     */
    public function reset(User $user, Model $model): bool
    {
        return ($this->allows($user, Ability::Approve) || $this->allows($user, Ability::Reject))
            && ! $this->isTrashed($model);
    }

    public function bulkApprove(User $user): bool
    {
        return $this->allows($user, Ability::Approve);
    }

    public function toggleFeatured(User $user, Model $model): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($model);
    }
}
