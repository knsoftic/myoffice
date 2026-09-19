<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\LeadStatus;
use App\Enums\PanelType;
use App\Models\Crm\Lead;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use App\Services\Crm\Concerns\InteractsWithCrm;
use Illuminate\Support\Collection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Throwable;

/**
 * Picks an owner for a lead that arrives without one (phase-05 §5 `crm.auto_assign_*`, §6.1 `create()`).
 *
 * Applied only when the caller supplied no assignee and `crm.auto_assign_mode` is not `off`:
 *
 *   · `fixed_user`  — `crm.auto_assign_user_id`, when that account is active and holds `leads.view`;
 *   · `round_robin` — the pool member after whoever received the most recent auto-assignable lead;
 *   · `least_open`  — the pool member with the fewest open (neither won nor lost) leads, ties to the lowest id.
 *
 * The pool is the users holding a role named in `crm.auto_assign_roles` (roles of the `admin` panel only), whose
 * status is Active and who hold `leads.view` — resolved from the database, never from a hard-coded role list
 * (CLAUDE.md rule 8). An empty pool leaves the lead unassigned rather than guessing.
 */
final class LeadAutoAssigner
{
    use InteractsWithCrm;

    public const MODE_OFF = 'off';

    public const MODE_ROUND_ROBIN = 'round_robin';

    public const MODE_LEAST_OPEN = 'least_open';

    public const MODE_FIXED_USER = 'fixed_user';

    public function pick(): ?int
    {
        $mode = (string) $this->crmSetting('auto_assign_mode', self::MODE_OFF);

        try {
            return match ($mode) {
                self::MODE_FIXED_USER => $this->fixedUser(),
                self::MODE_ROUND_ROBIN => $this->roundRobin(),
                self::MODE_LEAST_OPEN => $this->leastOpen(),
                default => null,
            };
        } catch (Throwable $exception) {
            // Auto-assignment is a convenience: a failure leaves the lead unassigned, it never fails the lead.
            report($exception);

            return null;
        }
    }

    /**
     * Active users eligible to receive leads, ordered by id.
     *
     * @return Collection<int, User>
     */
    public function pool(): Collection
    {
        $roles = $this->crmList('auto_assign_roles', ['Sales Executive']);

        if ($roles === []) {
            return new Collection;
        }

        try {
            $users = User::query()
                ->active()
                ->permission('leads.view')
                ->whereHas('roles', static fn ($query) => $query
                    ->whereIn('name', $roles)
                    ->where('panel', PanelType::Admin->value))
                ->orderBy('id')
                ->get(['id', 'name']);
        } catch (PermissionDoesNotExist) {
            return new Collection;
        }

        return $users->values();
    }

    private function fixedUser(): ?int
    {
        $id = $this->crmSetting('auto_assign_user_id');

        if (! is_numeric($id) || (int) $id < 1) {
            return null;
        }

        $user = User::query()->active()->whereKey((int) $id)->first();

        return $user instanceof User && $user->can('leads.view') ? (int) $user->getKey() : null;
    }

    private function roundRobin(): ?int
    {
        $ids = $this->pool()->map(static fn (User $user): int => (int) $user->getKey())->all();

        if ($ids === []) {
            return null;
        }

        $last = Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->withTrashed()
            ->whereIn('assigned_to', $ids)
            ->whereNotNull('assigned_at')
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->value('assigned_to');

        if ($last === null) {
            return $ids[0];
        }

        $position = array_search((int) $last, $ids, true);

        return $position === false ? $ids[0] : $ids[($position + 1) % count($ids)];
    }

    private function leastOpen(): ?int
    {
        $ids = $this->pool()->map(static fn (User $user): int => (int) $user->getKey())->all();

        if ($ids === []) {
            return null;
        }

        $closed = [LeadStatus::from('won')->value, LeadStatus::from('lost')->value];

        $counts = Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->whereIn('assigned_to', $ids)
            ->whereNotIn('status', $closed)
            ->groupBy('assigned_to')
            ->selectRaw('assigned_to, COUNT(*) AS open_count')
            ->toBase()
            ->pluck('open_count', 'assigned_to')
            ->all();

        $best = null;
        $bestCount = PHP_INT_MAX;

        foreach ($ids as $id) {
            $count = (int) ($counts[$id] ?? 0);

            if ($count < $bestCount) {
                $best = $id;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
