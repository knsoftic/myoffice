<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Enums\Ability;
use App\Enums\ModuleGroup;
use App\Models\Permission;
use App\Support\Modules;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns a flat list of permissions into the grid the role editor and the permissions index
 * render: ModuleGroup → module → one cell per ability.
 *
 * Why the ability columns are computed **per group** instead of once for the whole grid: the
 * Ability enum has 18 cases and the portal prefixes (`student_portal.*`, `collaborator_portal.*`)
 * declare free-form abilities of their own, so one global header would be ~40 mostly-empty
 * columns. Each group gets exactly the columns its modules use, ordered by the Ability enum's
 * declaration order with the free-form ones appended.
 *
 * Pure presentation: it reads the permissions it is handed plus the cached module metadata, and
 * writes nothing.
 */
final class PermissionMatrix
{
    /**
     * @param  Collection<int, Permission>  $permissions
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     color: string,
     *     total: int,
     *     abilities: array<int, array{value: string, label: string}>,
     *     modules: array<int, array{
     *         slug: string,
     *         name: string,
     *         icon: string|null,
     *         is_core: bool,
     *         is_enabled: bool,
     *         total: int,
     *         names: array<int, string>,
     *         cells: array<string, Permission>
     *     }>
     * }>
     */
    public function build(Collection $permissions): array
    {
        $meta = Modules::all();

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];

        foreach ($permissions as $permission) {
            $slug = (string) $permission->module;

            if ($slug === '') {
                // A permission with no module is a seeding bug, not something to render.
                continue;
            }

            $row = $meta->get($slug);
            $groupKey = $this->groupKey($permission, is_array($row) ? $row : null);
            $ability = $this->abilityValue($permission);

            $groups[$groupKey] ??= [
                'key' => $groupKey,
                'label' => $this->groupLabel($groupKey),
                'color' => ModuleGroup::tryFrom($groupKey)?->color() ?? 'slate',
                'abilities' => [],
                'modules' => [],
                'total' => 0,
            ];

            $groups[$groupKey]['modules'][$slug] ??= [
                'slug' => $slug,
                'name' => is_array($row) ? (string) $row['name'] : Str::headline($slug),
                'icon' => is_array($row) ? ($row['icon'] ?? null) : null,
                'is_core' => is_array($row) ? (bool) $row['is_core'] : false,
                'is_enabled' => is_array($row) ? (bool) $row['is_enabled'] : true,
                'sort' => is_array($row) ? (int) $row['sort_order'] : 9999,
                'total' => 0,
                'names' => [],
                'cells' => [],
            ];

            $groups[$groupKey]['modules'][$slug]['cells'][$ability] = $permission;
            $groups[$groupKey]['modules'][$slug]['names'][] = (string) $permission->name;
            $groups[$groupKey]['modules'][$slug]['total']++;
            $groups[$groupKey]['total']++;

            if (! in_array($ability, $groups[$groupKey]['abilities'], true)) {
                $groups[$groupKey]['abilities'][] = $ability;
            }
        }

        return $this->finalise($groups);
    }

    /**
     * Every permission name in the grid, flat — what the "select all" counter counts against.
     *
     * @param  array<int, array<string, mixed>>  $groups  output of build()
     * @return array<int, string>
     */
    public function names(array $groups): array
    {
        $names = [];

        foreach ($groups as $group) {
            foreach ($group['modules'] as $module) {
                foreach ($module['names'] as $name) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Human label for an ability value, whether or not it is an Ability case.
     */
    public static function abilityLabel(string $ability): string
    {
        return Ability::tryFrom($ability)?->label() ?? Str::headline($ability);
    }

    /**
     * Tailwind colour token for an ability value (free-form portal abilities read as neutral).
     */
    public static function abilityColor(string $ability): string
    {
        return Ability::tryFrom($ability)?->color() ?? 'slate';
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Sort the groups, their modules and their ability columns into render order.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function finalise(array $groups): array
    {
        $groupOrder = array_values(array_map(
            static fn (ModuleGroup $case): string => $case->value,
            ModuleGroup::cases(),
        ));

        uksort($groups, static function (string $a, string $b) use ($groupOrder): int {
            $left = array_search($a, $groupOrder, true);
            $right = array_search($b, $groupOrder, true);

            return [$left === false ? PHP_INT_MAX : $left, $a] <=> [$right === false ? PHP_INT_MAX : $right, $b];
        });

        $result = [];

        foreach ($groups as $group) {
            uasort(
                $group['modules'],
                static fn (array $a, array $b): int => [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']],
            );

            $group['modules'] = array_values(array_map(static function (array $module): array {
                unset($module['sort']);
                $module['names'] = array_values(array_unique($module['names']));

                return $module;
            }, $group['modules']));

            $group['abilities'] = array_map(
                static fn (string $ability): array => [
                    'value' => $ability,
                    'label' => self::abilityLabel($ability),
                ],
                $this->sortAbilities($group['abilities']),
            );

            $result[] = $group;
        }

        return $result;
    }

    /**
     * Ability enum order first (view_any, view, create, …), free-form abilities after it
     * in alphabetical order so the header is stable between requests.
     *
     * @param  array<int, string>  $abilities
     * @return array<int, string>
     */
    private function sortAbilities(array $abilities): array
    {
        $canonical = Ability::values();

        $known = [];
        $extra = [];

        foreach ($abilities as $ability) {
            $position = array_search($ability, $canonical, true);

            if ($position === false) {
                $extra[] = $ability;
            } else {
                $known[$position] = $ability;
            }
        }

        ksort($known);
        sort($extra);

        return array_values(array_merge(array_values($known), $extra));
    }

    /**
     * @param  array<string, mixed>|null  $moduleRow
     */
    private function groupKey(Permission $permission, ?array $moduleRow): string
    {
        if ($moduleRow !== null && filled($moduleRow['group'] ?? null)) {
            return (string) $moduleRow['group'];
        }

        $group = trim((string) $permission->group);

        return $group === '' ? ModuleGroup::Shared->value : $group;
    }

    private function groupLabel(string $key): string
    {
        return ModuleGroup::tryFrom($key)?->label() ?? Str::headline($key);
    }

    /**
     * The ability half of the permission name, as a plain string.
     */
    private function abilityValue(Permission $permission): string
    {
        $ability = $permission->ability;

        if ($ability instanceof Ability) {
            return $ability->value;
        }

        $ability = trim((string) $ability);

        if ($ability !== '') {
            return $ability;
        }

        // Last resort: derive it from the name so a half-seeded row still renders.
        return (string) Str::after((string) $permission->name, (string) $permission->module.'.');
    }
}
