<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\PanelType;
use App\Models\Role;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Every role, how many accounts hold it and how many permissions it grants (phase-02 §3).
 *
 * The N+1 this card would be if written naively is the point of it: a member count and a
 * permission count per role is the textbook "query inside a loop". Both come back as
 * `withCount()` sub-selects on the single roles query, so the card costs exactly one statement
 * whether the business has 18 roles or 180.
 *
 * Not range-scoped: a role is configuration, not an event. The card says so rather than pretending
 * the selector applies to it.
 */
final class RolesOverviewWidget extends Widget
{
    /** Rows shown before the card collapses into a "+N more" line. */
    private const LIMIT = 8;

    public function key(): string
    {
        return 'roles_overview';
    }

    public function title(): string
    {
        return 'Roles';
    }

    public function icon(): string
    {
        return 'shield-check';
    }

    public function permission(): ?string
    {
        return 'roles.view_any';
    }

    public function module(): ?string
    {
        return 'roles';
    }

    public function span(): int
    {
        return 4;
    }

    public function group(): string
    {
        return WidgetGroup::OVERVIEW;
    }

    public function sort(): int
    {
        return 20;
    }

    public function subtitle(): ?string
    {
        return 'Accounts and permissions per role';
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.roles.index');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            /** @var Collection<int, Role> $roles */
            $roles = Role::query()
                ->withCount(['users', 'permissions'])
                ->orderBy('level')
                ->orderBy('name')
                ->get();
        } catch (Throwable) {
            return [
                'available' => false,
                'total' => 0,
                'roles' => [],
                'assigned_accounts' => 0,
                'unassigned' => 0,
                'system_roles' => 0,
                'panels' => [],
                'more' => 0,
            ];
        }

        $rows = [];

        foreach ($roles->take(self::LIMIT) as $role) {
            $panel = $role->panel instanceof PanelType ? $role->panel : PanelType::tryFrom((string) $role->panel);

            $rows[] = [
                'name' => (string) $role->name,
                'label' => (string) ($role->label ?? $role->name),
                'panel' => $panel?->value,
                'panel_label' => $panel?->label() ?? '—',
                'level' => (int) $role->level,
                'is_system' => (bool) $role->is_system,
                'users_count' => (int) $role->users_count,
                'permissions_count' => (int) $role->permissions_count,
                'href' => $this->routeUrl('admin.roles.show', $role->getKey()),
            ];
        }

        $panels = [];

        foreach ($roles as $role) {
            $panel = $role->panel instanceof PanelType ? $role->panel : PanelType::tryFrom((string) $role->panel);
            $key = $panel?->value ?? 'other';

            $panels[$key] ??= ['label' => $panel?->label() ?? 'Other', 'count' => 0];
            $panels[$key]['count']++;
        }

        return [
            'available' => true,
            'total' => $roles->count(),
            'roles' => $rows,
            'more' => max(0, $roles->count() - self::LIMIT),
            'assigned_accounts' => (int) $roles->sum('users_count'),
            'unassigned' => $roles->where('users_count', 0)->count(),
            'system_roles' => $roles->where('is_system', true)->count(),
            'panels' => array_values($panels),
        ];
    }

    public function emptyMessage(): ?string
    {
        return 'No roles are defined yet — run the role seeder.';
    }
}
