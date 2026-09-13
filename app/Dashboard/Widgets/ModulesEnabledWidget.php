<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\ModuleGroup;
use App\Support\DateRange;
use App\Support\Modules;
use Throwable;

/**
 * What is switched on, per module group (phase-02 §3).
 *
 * Reads `App\Support\Modules::all()` rather than querying `modules` itself. That is not a
 * shortcut — it is the same cached payload (`modules.all`, `modules.enabled.map`) that
 * `Gate::before` and the sidebar consult, flushed by the `Module` model on save, so this card can
 * never disagree with what the application is actually enforcing. On a warm cache it costs **zero
 * queries**; on a cold one, the two reads it warms are shared with the rest of the request.
 *
 * Not range-scoped: a module's state is configuration. The disabled list links straight to the
 * module screen so "why can nobody see projects?" is one click from here.
 */
final class ModulesEnabledWidget extends Widget
{
    /** Disabled modules named before the card summarises the rest. */
    private const NAME_LIMIT = 6;

    public function key(): string
    {
        return 'modules_enabled';
    }

    public function title(): string
    {
        return 'Modules';
    }

    public function icon(): string
    {
        return 'puzzle-piece';
    }

    public function permission(): ?string
    {
        return 'modules.view_any';
    }

    public function module(): ?string
    {
        return 'modules';
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
        return 30;
    }

    public function subtitle(): ?string
    {
        return 'Enabled features, by group';
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.modules.index');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $modules = Modules::all();
        } catch (Throwable) {
            return [
                'available' => false,
                'total' => 0,
                'enabled' => 0,
                'disabled' => 0,
                'core' => 0,
                'share' => 0,
                'groups' => [],
                'disabled_modules' => [],
                'disabled_more' => 0,
            ];
        }

        $groups = [];
        $disabled = [];

        foreach (ModuleGroup::cases() as $case) {
            $groups[$case->value] = [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
                'total' => 0,
                'enabled' => 0,
            ];
        }

        $total = 0;
        $enabled = 0;
        $core = 0;

        foreach ($modules as $module) {
            $total++;

            $groupValue = (string) ($module['group'] ?? '');
            $case = ModuleGroup::tryFrom($groupValue);
            $bucket = $case?->value ?? 'other';

            $groups[$bucket] ??= [
                'value' => $bucket,
                'label' => $case?->label() ?? 'Other',
                'color' => $case?->color() ?? 'slate',
                'total' => 0,
                'enabled' => 0,
            ];

            $groups[$bucket]['total']++;

            if ((bool) ($module['is_core'] ?? false)) {
                $core++;
            }

            if ((bool) ($module['is_enabled'] ?? false)) {
                $enabled++;
                $groups[$bucket]['enabled']++;

                continue;
            }

            $disabled[] = [
                'slug' => (string) ($module['slug'] ?? ''),
                'name' => (string) ($module['name'] ?? ''),
                'group' => $case?->label() ?? 'Other',
                'color' => $case?->color() ?? 'slate',
            ];
        }

        // Groups with no modules at all are noise on a card this size.
        $groups = array_values(array_filter(
            $groups,
            static fn (array $group): bool => $group['total'] > 0,
        ));

        foreach ($groups as $index => $group) {
            $groups[$index]['share'] = $group['total'] > 0
                ? (int) round(($group['enabled'] / $group['total']) * 100)
                : 0;
        }

        return [
            'available' => true,
            'total' => $total,
            'enabled' => $enabled,
            'disabled' => $total - $enabled,
            'core' => $core,
            'share' => $total > 0 ? (int) round(($enabled / $total) * 100) : 0,
            'groups' => $groups,
            'disabled_modules' => array_slice($disabled, 0, self::NAME_LIMIT),
            'disabled_more' => max(0, count($disabled) - self::NAME_LIMIT),
        ];
    }

    public function emptyMessage(): ?string
    {
        return 'No modules are registered — run the module seeder.';
    }
}
