<?php

declare(strict_types=1);

namespace App\Support;

use App\Dashboard\Contracts\DashboardWidget;
use App\Dashboard\Exceptions\DuplicateWidgetKeyException;
use App\Dashboard\Exceptions\InvalidWidgetException;
use App\Dashboard\Layout;
use App\Dashboard\WidgetDescriptor;
use App\Dashboard\WidgetGroup;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Throwable;

/**
 * The dashboard widget registry (phase-02 §3) — the eleventh of the project's registry classes
 * and the extension point the remaining twenty-three phases hang their cards on.
 *
 * ---------------------------------------------------------------------------------------------
 * How a later phase adds a card
 * ---------------------------------------------------------------------------------------------
 *
 * Drop one class into `app/Dashboard/Widgets/` (any sub-directory) implementing
 * `App\Dashboard\Contracts\DashboardWidget`, plus a Blade view at the path its `view()` names.
 * That is the whole job: **no edit to `DashboardController`, to `admin/dashboard.blade.php`, or
 * to this file.**
 *
 *   app/Dashboard/Widgets/Finance/OverdueInvoicesWidget.php
 *   resources/views/admin/dashboard/widgets/overdue-invoices.blade.php
 *
 * Auto-discovery is the primary mechanism. `register()` exists for widgets that live outside
 * that directory (a package, a test double) and is idempotent for the same class.
 *
 * **Cost.** Discovery is one directory walk plus one cache read. The list of class names is
 * cached for ever under `dashboard.widgets.classes`, stamped with a fingerprint of the directory
 * (file count + newest mtime): dropping a class in is picked up on the very next request in
 * development with no cache command, while a warm install never repeats the reflection pass.
 * Within a request everything is memoised in a static, so resolving the registry twice costs
 * nothing — which is also why the state here is static rather than per-instance: the class then
 * behaves like a singleton without needing a service-provider binding.
 *
 * ---------------------------------------------------------------------------------------------
 * What the registry guarantees
 * ---------------------------------------------------------------------------------------------
 *
 *  1. **One key, one owner.** Two *different* classes claiming the same `key()` throw
 *     `DuplicateWidgetKeyException` on the first request, rather than one card silently
 *     replacing the other (F-8.3). Re-registering the same class is a no-op. Keys are also
 *     validated as snake_case, because a key ends up in a URL, in a DOM id and in
 *     `users.preferences`.
 *  2. **Permission and module filtering happen here, before any query.** `for($user)` returns
 *     only the cards whose module is enabled *and* whose permission the viewer holds, so a user
 *     without `activity_log.view_logs` never sees the activity card **and its query never runs**.
 *  3. **A half-written widget is never fatal.** A discovered file that is not a usable widget is
 *     skipped — a broken class in a working tree must not 500 the admin landing page. An
 *     explicit `register()` call throws instead: there the caller asked for something specific
 *     and deserves the error. A duplicate key throws from either path.
 */
final class DashboardRegistry
{
    /** Cache key holding the discovered widget class names plus the directory fingerprint. */
    public const CACHE_KEY = 'dashboard.widgets.classes';

    /** Directory scanned for widgets, relative to `app/`. */
    public const DISCOVERY_PATH = 'Dashboard/Widgets';

    /** A key has to survive a URL, a DOM id and a JSON preference tree. */
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * Explicitly registered instances, key => widget. Kept as instances so constructor state
     * (a test double's canned figures, for example) survives.
     *
     * @var array<string, DashboardWidget>
     */
    private static array $explicit = [];

    /**
     * key => the class that owns it, rebuilt on every resolve. The duplicate guard.
     *
     * @var array<string, class-string<DashboardWidget>>
     */
    private static array $owners = [];

    /**
     * Resolved widget instances for this request, key => widget.
     *
     * @var array<string, DashboardWidget>|null
     */
    private static ?array $widgets = null;

    /** Discovery can be switched off so a test can assert on an exact set. */
    private static bool $discovery = true;

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    */

    /**
     * Register a widget explicitly.
     *
     * @param  DashboardWidget|class-string<DashboardWidget>  $widget
     *
     * @throws DuplicateWidgetKeyException a different class already owns this key
     * @throws InvalidWidgetException not a widget, not instantiable, or a malformed key
     */
    public static function register(DashboardWidget|string $widget): void
    {
        $instance = is_string($widget)
            ? self::instantiate($widget, strict: true)
            : $widget;

        if (! $instance instanceof DashboardWidget) {
            throw InvalidWidgetException::notAWidget(is_string($widget) ? $widget : $widget::class);
        }

        $key = trim($instance->key());

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw InvalidWidgetException::badKey($instance::class, $key);
        }

        // Resolve first, so a key already claimed by a discovered widget is caught right here
        // rather than on some later request.
        self::resolve();

        $existing = self::$owners[$key] ?? null;

        if ($existing !== null && $existing !== $instance::class) {
            throw DuplicateWidgetKeyException::for($key, $instance::class, $existing);
        }

        self::$explicit[$key] = $instance;
        self::$owners[$key] = $instance::class;

        if (self::$widgets !== null) {
            self::$widgets[$key] = $instance;
        }
    }

    /**
     * @param  iterable<DashboardWidget|class-string<DashboardWidget>>  $widgets
     */
    public static function registerMany(iterable $widgets): void
    {
        foreach ($widgets as $widget) {
            self::register($widget);
        }
    }

    /**
     * Stop scanning `app/Dashboard/Widgets` — only explicit registrations count.
     */
    public static function withoutDiscovery(): void
    {
        self::$discovery = false;
        self::$widgets = null;
        self::$owners = [];
    }

    public static function withDiscovery(): void
    {
        self::$discovery = true;
        self::$widgets = null;
        self::$owners = [];
    }

    /**
     * Forget every explicit registration and every memoised instance.
     *
     * The state on this class is static so that it behaves like a singleton without a
     * service-provider binding; that makes an explicit reset the right thing for a test's
     * `tearDown`, and for anything that registered a double.
     */
    public static function reset(): void
    {
        self::$explicit = [];
        self::$owners = [];
        self::$widgets = null;
        self::$discovery = true;
    }

    /**
     * Drop the cached class list. Needed only in a deployed install after a widget class is
     * added or deleted; `optimize:clear` does it too.
     */
    public static function flushCache(): void
    {
        self::$widgets = null;
        self::$owners = [];

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // No cache store configured yet: nothing to forget.
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reading the whole registry
    |--------------------------------------------------------------------------
    */

    /**
     * Every registered widget, keyed by `key()`.
     *
     * Unfiltered — no permission and no module check. Use `for()` for anything a user sees.
     *
     * @return Collection<string, DashboardWidget>
     */
    public static function all(): Collection
    {
        return new Collection(self::resolve());
    }

    /**
     * Every registered key.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::resolve());
    }

    public static function has(string $key): bool
    {
        return array_key_exists(trim($key), self::resolve());
    }

    public static function find(string $key): ?DashboardWidget
    {
        return self::resolve()[trim($key)] ?? null;
    }

    /**
     * Every widget as a descriptor, keyed by key.
     *
     * @return Collection<string, WidgetDescriptor>
     */
    public static function descriptors(): Collection
    {
        return self::all()->map(static fn (DashboardWidget $widget): WidgetDescriptor => WidgetDescriptor::for($widget));
    }

    /**
     * key => owning class. `count()` of this always equals `count(keys())`, which is the
     * uniqueness invariant the acceptance suite asserts.
     *
     * @return array<string, class-string<DashboardWidget>>
     */
    public static function owners(): array
    {
        self::resolve();

        return self::$owners;
    }

    /*
    |--------------------------------------------------------------------------
    | Reading it as a particular user
    |--------------------------------------------------------------------------
    */

    /**
     * The cards this viewer may see: module enabled AND permission held.
     *
     * The only door the dashboard uses. Nothing downstream re-decides visibility, and nothing
     * calls `data()` on a widget that did not come out of here.
     *
     * @return Collection<string, WidgetDescriptor>
     */
    public static function for(?Authenticatable $user): Collection
    {
        return self::descriptors()
            ->filter(static fn (WidgetDescriptor $descriptor): bool => self::allows($user, $descriptor))
            ->values()
            ->keyBy(static fn (WidgetDescriptor $descriptor): string => $descriptor->key);
    }

    /**
     * One card, but only if this viewer may see it.
     *
     * The JSON endpoint's guard: the key arrives from the browser, so it is re-checked here
     * rather than trusted.
     */
    public static function findFor(?Authenticatable $user, string $key): ?WidgetDescriptor
    {
        $widget = self::find($key);

        if ($widget === null) {
            return null;
        }

        $descriptor = WidgetDescriptor::for($widget);

        return self::allows($user, $descriptor) ? $descriptor : null;
    }

    /**
     * May this viewer see this card?
     *
     *  · a disabled module hides it for everyone, Super Admin included (D5) — the same rule
     *    `Gate::before` applies to the permission itself;
     *  · a null permission means "anyone who can reach the dashboard", which the route's
     *    `can:dashboard.*` has already decided;
     *  · no user at all (console, a guest past the middleware) sees nothing.
     */
    public static function allows(?Authenticatable $user, WidgetDescriptor|DashboardWidget $widget): bool
    {
        $descriptor = $widget instanceof WidgetDescriptor ? $widget : WidgetDescriptor::for($widget);

        if ($descriptor->module !== null && ! Modules::enabled($descriptor->module)) {
            return false;
        }

        if ($descriptor->permission === null) {
            return $user !== null;
        }

        if (! $user instanceof User) {
            return false;
        }

        try {
            return $user->can($descriptor->permission);
        } catch (Throwable) {
            // Permission tables not migrated or seeded yet: deny rather than leak.
            return false;
        }
    }

    /**
     * The viewer's dashboard, arranged by their own saved layout and split into the cards that
     * are shown and the cards they have switched off.
     *
     * @return array{
     *     visible: Collection<string, WidgetDescriptor>,
     *     hidden: Collection<string, WidgetDescriptor>,
     *     available: Collection<string, WidgetDescriptor>,
     *     layout: Layout
     * }
     */
    public static function layoutFor(?Authenticatable $user, ?Layout $layout = null): array
    {
        $available = self::for($user);
        $layout ??= Layout::fromUser($user instanceof User ? $user : null);

        return [
            'visible' => $layout->visible($available),
            'hidden' => $layout->hiddenFrom($available),
            'available' => $layout->arrange($available),
            'layout' => $layout,
        ];
    }

    /**
     * The sections present in this viewer's dashboard, in display order: slug => label.
     *
     * @return array<string, string>
     */
    public static function groupsFor(?Authenticatable $user): array
    {
        return self::for($user)
            ->sortBy(static fn (WidgetDescriptor $descriptor): int => $descriptor->groupSort)
            ->mapWithKeys(static fn (WidgetDescriptor $descriptor): array => [
                $descriptor->group => WidgetGroup::label($descriptor->group),
            ])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution and discovery
    |--------------------------------------------------------------------------
    */

    /**
     * key => widget instance, memoised for the request.
     *
     * @return array<string, DashboardWidget>
     *
     * @throws DuplicateWidgetKeyException
     */
    private static function resolve(): array
    {
        if (self::$widgets !== null) {
            return self::$widgets;
        }

        $owners = [];
        $widgets = [];

        // Explicit registrations are placed first: they are the deliberate ones, so they own
        // their key and a discovered class colliding with them is the error, not the other way.
        foreach (self::$explicit as $key => $instance) {
            $owners[$key] = $instance::class;
            $widgets[$key] = $instance;
        }

        foreach (self::$discovery ? self::discover() : [] as $class) {
            try {
                $widget = self::instantiate($class, strict: true);
            } catch (InvalidWidgetException $exception) {
                // F-8.3: "no card can silently disappear". A discovered class that cannot be built
                // is skipped so every other card still renders — but it is reported, never dropped
                // without a trace.
                report($exception);

                continue;
            }

            if ($widget === null) {
                continue;
            }

            $key = trim($widget->key());

            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                // A malformed key cannot be addressed by a URL or stored in preferences. Skip
                // it rather than break every other card on the page — and say so.
                report(InvalidWidgetException::badKey($class, $key));

                continue;
            }

            $owner = $owners[$key] ?? null;

            if ($owner !== null) {
                if ($owner !== $class) {
                    throw DuplicateWidgetKeyException::for($key, $class, $owner);
                }

                continue;
            }

            $owners[$key] = $class;
            $widgets[$key] = $widget;
        }

        self::$owners = $owners;

        return self::$widgets = $widgets;
    }

    /**
     * Widget class names under `app/Dashboard/Widgets`, cached with a directory fingerprint.
     *
     * @return list<class-string<DashboardWidget>>
     */
    private static function discover(): array
    {
        $directory = app_path(self::DISCOVERY_PATH);

        if (! is_dir($directory)) {
            return [];
        }

        $files = self::widgetFiles($directory);
        $fingerprint = self::fingerprint($files);

        try {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached)
                && ($cached['fingerprint'] ?? null) === $fingerprint
                && is_array($cached['classes'] ?? null)) {
                return self::filterWidgetClasses($cached['classes']);
            }
        } catch (Throwable) {
            // No cache store: fall through to a live scan on every request. Correct beats fast.
        }

        $classes = self::filterWidgetClasses(array_map(
            static fn (string $file): string => self::classForFile($file),
            $files,
        ));

        try {
            Cache::forever(self::CACHE_KEY, ['fingerprint' => $fingerprint, 'classes' => $classes]);
        } catch (Throwable) {
            // Not cacheable — the scan above already produced the right answer.
        }

        return $classes;
    }

    /**
     * Absolute paths of every `*.php` under the widget directory, sorted so the order the cards
     * resolve in is stable across filesystems.
     *
     * @return list<string>
     */
    private static function widgetFiles(string $directory): array
    {
        try {
            $paths = [];

            foreach (File::allFiles($directory) as $file) {
                if ($file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }

            sort($paths);

            return $paths;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * File count plus the newest mtime: cheap, and it changes the moment a widget class is
     * added, removed or edited.
     *
     * @param  list<string>  $files
     */
    private static function fingerprint(array $files): string
    {
        $newest = 0;

        foreach ($files as $file) {
            $modified = @filemtime($file);

            if ($modified !== false && $modified > $newest) {
                $newest = $modified;
            }
        }

        return count($files).':'.$newest;
    }

    /**
     * `…/app/Dashboard/Widgets/Finance/OverdueInvoicesWidget.php`
     *   -> `App\Dashboard\Widgets\Finance\OverdueInvoicesWidget`
     */
    private static function classForFile(string $file): string
    {
        $relative = str_replace(
            [app_path().DIRECTORY_SEPARATOR, '/', '\\'],
            ['', '\\', '\\'],
            $file,
        );

        $namespace = trim(app()->getNamespace(), '\\');

        return $namespace.'\\'.(string) preg_replace('/\.php$/', '', $relative);
    }

    /**
     * Keep only the class names that really are concrete widgets.
     *
     * @param  array<int, mixed>  $classes
     * @return list<class-string<DashboardWidget>>
     */
    private static function filterWidgetClasses(array $classes): array
    {
        $usable = [];

        foreach ($classes as $class) {
            if (! is_string($class) || $class === '' || in_array($class, $usable, true)) {
                continue;
            }

            try {
                if (! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if ($reflection->isAbstract() || ! $reflection->implementsInterface(DashboardWidget::class)) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            $usable[] = $class;
        }

        return $usable;
    }

    /**
     * Build a widget. Resolved through the container so a later phase's widget may inject the
     * service that owns its figures (D28: a card reads a capability, it never re-implements the
     * query).
     *
     * @param  class-string  $class
     *
     * @throws InvalidWidgetException when $strict and the class is unusable
     */
    private static function instantiate(string $class, bool $strict): ?DashboardWidget
    {
        try {
            if (! class_exists($class)) {
                throw InvalidWidgetException::notAWidget($class);
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->implementsInterface(DashboardWidget::class)) {
                throw InvalidWidgetException::notAWidget($class);
            }

            if ($reflection->isAbstract()) {
                throw InvalidWidgetException::notInstantiable($class);
            }

            $instance = app($class);

            if (! $instance instanceof DashboardWidget) {
                throw InvalidWidgetException::notAWidget($class);
            }

            return $instance;
        } catch (InvalidWidgetException $exception) {
            if ($strict) {
                throw $exception;
            }

            return null;
        } catch (Throwable) {
            if ($strict) {
                throw InvalidWidgetException::notInstantiable($class);
            }

            return null;
        }
    }
}
