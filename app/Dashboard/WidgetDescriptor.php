<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Dashboard\Contracts\DashboardWidget;
use App\Support\DateRange;

/**
 * A normalised, safe read model over one widget.
 *
 * The dashboard grid, the customise panel and the JSON endpoint all consume descriptors and
 * never a raw widget. That is what lets `DashboardWidget` stay a nine-method interface while the
 * UI still gets a subtitle, a sort key, a skeleton variant and a "view all" link: the optional
 * hooks are read here through `method_exists()`, so a class implementing only the interface gets
 * defaults instead of a `BadMethodCallException`.
 *
 * Everything is also clamped here — span into 1–12, group into a slug, skeleton into one of the
 * variants `x-ui.skeleton` actually knows — so no widget can post a value that breaks the grid.
 */
final class WidgetDescriptor
{
    /** Variants `x-ui.skeleton` understands. */
    private const SKELETONS = ['text', 'row', 'stat', 'card'];

    private const SPAN_MIN = 1;

    private const SPAN_MAX = 12;

    private function __construct(
        public readonly DashboardWidget $widget,
        public readonly string $key,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly string $icon,
        public readonly ?string $permission,
        public readonly ?string $module,
        public readonly int $span,
        public readonly string $group,
        public readonly int $groupSort,
        public readonly int $sort,
        public readonly string $view,
        public readonly bool $deferred,
        public readonly ?string $href,
        public readonly string $skeleton,
        public readonly ?int $minHeight,
        public readonly ?string $emptyMessage,
        public readonly bool $padded,
    ) {}

    public static function for(DashboardWidget $widget): self
    {
        $group = WidgetGroup::normalise($widget->group());

        return new self(
            widget: $widget,
            key: trim($widget->key()),
            title: trim($widget->title()),
            subtitle: self::optionalString($widget, 'subtitle'),
            icon: self::icon($widget),
            permission: self::nullableTrim($widget->permission()),
            module: self::nullableTrim($widget->module()),
            span: self::span($widget),
            group: $group,
            groupSort: WidgetGroup::sort($group),
            sort: self::optionalInt($widget, 'sort', 100),
            view: trim($widget->view()),
            deferred: self::optionalBool($widget, 'deferred', false),
            href: self::optionalString($widget, 'href'),
            skeleton: self::skeleton($widget),
            minHeight: self::minHeight($widget),
            emptyMessage: self::optionalString($widget, 'emptyMessage'),
            padded: self::optionalBool($widget, 'padded', true),
        );
    }

    /**
     * Run the widget's query. The registry has already decided the viewer may see this card, so
     * by the time `data()` is reached there is no permission question left open.
     *
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        return $this->widget->data($range);
    }

    /** @return class-string<DashboardWidget> */
    public function className(): string
    {
        return $this->widget::class;
    }

    public function groupLabel(): string
    {
        return WidgetGroup::label($this->group);
    }

    /**
     * The Tailwind column classes for this span.
     *
     * A literal map, never string interpolation, so Tailwind's scanner sees every class. Cards
     * are full width on a phone and only take their declared span on the wide breakpoints.
     */
    public function spanClasses(): string
    {
        return match ($this->span) {
            1 => 'col-span-12 sm:col-span-6 md:col-span-4 xl:col-span-1',
            2 => 'col-span-12 sm:col-span-6 md:col-span-4 xl:col-span-2',
            3 => 'col-span-12 sm:col-span-6 xl:col-span-3',
            4 => 'col-span-12 sm:col-span-6 xl:col-span-4',
            5 => 'col-span-12 lg:col-span-6 xl:col-span-5',
            6 => 'col-span-12 lg:col-span-6',
            7 => 'col-span-12 xl:col-span-7',
            8 => 'col-span-12 xl:col-span-8',
            9 => 'col-span-12 xl:col-span-9',
            10 => 'col-span-12 xl:col-span-10',
            11 => 'col-span-12 xl:col-span-11',
            default => 'col-span-12',
        };
    }

    /**
     * What the customise panel and the JSON response publish about this card. No figures — the
     * data travels separately, because this array is also handed to the browser for a widget the
     * viewer has *hidden*, where no query has run.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'icon' => $this->icon,
            'span' => $this->span,
            'group' => $this->group,
            'group_label' => $this->groupLabel(),
            'deferred' => $this->deferred,
            'href' => $this->href,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Normalising
    |--------------------------------------------------------------------------
    */

    private static function icon(DashboardWidget $widget): string
    {
        $icon = trim($widget->icon());

        return $icon === '' ? 'squares-2x2' : $icon;
    }

    private static function span(DashboardWidget $widget): int
    {
        return max(self::SPAN_MIN, min(self::SPAN_MAX, $widget->span()));
    }

    private static function skeleton(DashboardWidget $widget): string
    {
        $variant = (string) (self::optionalString($widget, 'skeleton') ?? 'text');

        return in_array($variant, self::SKELETONS, true) ? $variant : 'text';
    }

    private static function minHeight(DashboardWidget $widget): ?int
    {
        if (! method_exists($widget, 'minHeight')) {
            return null;
        }

        $height = $widget->minHeight();

        if (! is_int($height) && ! is_float($height) && ! is_string($height)) {
            return null;
        }

        $height = (int) $height;

        // A body taller than a screen is never what a card wants; clamp rather than trust.
        return $height > 0 ? min($height, 800) : null;
    }

    private static function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function optionalString(DashboardWidget $widget, string $method): ?string
    {
        if (! method_exists($widget, $method)) {
            return null;
        }

        $value = $widget->{$method}();

        return is_string($value) ? self::nullableTrim($value) : null;
    }

    private static function optionalInt(DashboardWidget $widget, string $method, int $default): int
    {
        if (! method_exists($widget, $method)) {
            return $default;
        }

        $value = $widget->{$method}();

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function optionalBool(DashboardWidget $widget, string $method, bool $default): bool
    {
        if (! method_exists($widget, $method)) {
            return $default;
        }

        return (bool) $widget->{$method}();
    }
}
