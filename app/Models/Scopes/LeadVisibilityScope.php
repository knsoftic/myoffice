<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Throwable;

/**
 * Pipeline visibility for staff (phase-05 §9.1 [D-P5-8], D30).
 *
 * `leads.view_any` means "the whole pipeline"; `leads.view` alone means "only leads I own". For any authenticated
 * user who does **not** hold `leads.view_any` this global scope adds, verbatim:
 *
 *   where (assigned_to = auth()->id() or created_by = auth()->id())
 *
 * so the index, the board (its counts and sums too), the export, the follow-up worklist and route-model binding
 * all see the same rows — a lead outside the scope binds to nothing and answers **404** (§11 tests 8-9).
 *
 * Holding `leads.view_any` is checked through `$user->can()`, so it re-enters `Gate::before`: Super Admin sees
 * everything while the `leads` module is enabled; with the module disabled nobody reaches a lead route anyway.
 *
 * **No authenticated user, no scope.** Console commands, queued jobs and the public website hand-off
 * (`CrmLeadInquiryTarget` running inside a guest request) are not a person looking at a pipeline. Anything that
 * works *for* a user outside that user's request — a queued export, a bulk operation, a digest — must apply
 * {@see forUser()} (or `Lead::scopeVisibleTo()`) explicitly with that user.
 *
 * Bypass deliberately with `Lead::withoutGlobalScope(LeadVisibilityScope::class)` — only for writes that have
 * already been authorised, the duplicate detector's `restricted` matches (§6.2) and policies resolving a parent.
 */
final class LeadVisibilityScope implements Scope
{
    /** The permission that lifts the scope. */
    public const PIPELINE_PERMISSION = 'leads.view_any';

    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = self::currentUser();

        if ($user === null) {
            return;
        }

        self::forUser($builder, $user);
    }

    /**
     * Apply the §9.1 predicate for a given user (a no-op for a `leads.view_any` holder).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    public static function forUser(Builder $builder, User $user): Builder
    {
        if (self::seesWholePipeline($user)) {
            return $builder;
        }

        $userId = $user->getKey();

        return $builder->where(static function (Builder $query) use ($userId): void {
            $query->where($query->qualifyColumn('assigned_to'), $userId)
                ->orWhere($query->qualifyColumn('created_by'), $userId);
        });
    }

    public static function seesWholePipeline(User $user): bool
    {
        try {
            return $user->can(self::PIPELINE_PERMISSION);
        } catch (Throwable) {
            // Permission tables unavailable (install / mid-migration): fail closed to "own leads only".
            return false;
        }
    }

    /**
     * The authenticated user of the current request, or null outside one.
     */
    private static function currentUser(): ?User
    {
        try {
            /** @var Authenticatable|null $user */
            $user = auth()->user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }
}
