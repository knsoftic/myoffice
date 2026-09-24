<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ReportGroup;
use App\Reports\Contracts\ReportDefinition;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * The report registry (phase-19-23 §6.20, [D-23-2]) — the fourth registry in the system, after
 * permissions, settings and dashboard widgets, and deliberately the same shape as the third.
 *
 * ---------------------------------------------------------------------------------------------
 * How a later phase adds a report
 * ---------------------------------------------------------------------------------------------
 *
 * Drop one class into `app/Reports/{Group}/` extending `App\Reports\Report`. That is the whole job:
 * **no route, no controller method, no view, and no edit to this file.** The hub lists it, one
 * screen renders it, the exporter writes it out in every format it declares.
 *
 *   app/Reports/Institute/PendingFeesReport.php   →   /admin/reports/in.pending_fees
 *
 * `register()` exists for reports that live outside that directory — a package, a test double —
 * and is idempotent for the same class.
 *
 * ---------------------------------------------------------------------------------------------
 * What the registry guarantees
 * ---------------------------------------------------------------------------------------------
 *
 *  1. **One key, one owner.** A report key is in routes, in `report_exports.report_key` and in
 *     whatever filters somebody bookmarked, so two classes claiming the same key is an error on the
 *     first request rather than one report silently replacing the other. Keys are validated against
 *     `{group}.{name}` — a key without its group prefix would sort into the wrong section and, worse,
 *     could collide with a later phase's.
 *  2. **Module and permission filtering happen here, before any query.** {@see self::visibleTo()}
 *     returns only the reports whose module is enabled *and* whose full `permissions()` stack the
 *     viewer holds — the same rule `Sidebar` and `DashboardRegistry` apply. A user without
 *     `student_fees.view_reports` never sees the fee report **and its query never runs**.
 *  3. **A half-written report is never fatal.** A discovered class that is not a usable report is
 *     skipped and reported; one broken file in a working tree must not 500 the reports hub and take
 *     the other thirty with it. An explicit `register()` throws instead — there the caller asked for
 *     something specific and deserves the error.
 *  4. **An unavailable report is listed, not hidden.** A report whose source service has not shipped
 *     stays in the hub, greyed, saying which phase brings it ([D-P5-1]). Hiding it would make the
 *     hub look complete; showing it with zeroes would be worse still.
 *
 * **Cost.** One directory walk plus one cache read, exactly as `DashboardRegistry` does it: the
 * class list is cached under a fingerprint of the directory (file count + newest mtime), so dropping
 * a report in is picked up on the next request in development while a warm install never repeats the
 * reflection pass. Within a request everything is memoised in a static.
 */
final class ReportRegistry
{
    /** Cache key holding the discovered report class names plus the directory fingerprint. */
    public const CACHE_KEY = 'reports.definitions.classes';

    /** Directory scanned for reports, relative to `app/`. */
    private const DISCOVERY_PATH = 'Reports';

    /** `{group}.{name}` — the group prefix is not decoration, see guarantee 1. */
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    /** @var array<string, ReportDefinition>|null */
    private static ?array $reports = null;

    /** @var array<string, ReportDefinition> */
    private static array $explicit = [];

    /** key => the class that owns it. The duplicate guard. @var array<string, class-string> */
    private static array $owners = [];

    private static bool $discovery = true;

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    */

    /**
     * Register a report that does not live under `app/Reports`.
     *
     * @throws InvalidArgumentException a malformed key, or a class that is not a report
     * @throws RuntimeException a different class already owns this key
     */
    public static function register(ReportDefinition|string $report): void
    {
        $instance = is_string($report) ? self::instantiate($report, strict: true) : $report;

        if ($instance === null) {
            throw new InvalidArgumentException(sprintf(
                '[%s] is not a usable %s.',
                is_string($report) ? $report : $report::class,
                ReportDefinition::class,
            ));
        }

        $key = trim($instance->key());

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Report [%s] has the key [%s], which is not `{group}.{name}` in snake_case. A report '
                .'key reaches routes, report_exports.report_key and somebody\'s bookmarked filters, '
                .'so it has to be addressable and it has to be unambiguous.',
                $instance::class,
                $key,
            ));
        }

        $owner = self::$explicit[$key] ?? null;

        if ($owner !== null && $owner::class !== $instance::class) {
            throw self::duplicate($key, $instance::class, $owner::class);
        }

        self::$explicit[$key] = $instance;
        self::$reports = null;
    }

    /**
     * @param  iterable<ReportDefinition|string>  $reports
     */
    public static function registerMany(iterable $reports): void
    {
        foreach ($reports as $report) {
            self::register($report);
        }
    }

    /** Turn auto-discovery off — for a test that wants only what it registered. */
    public static function withoutDiscovery(): void
    {
        self::$discovery = false;
        self::$reports = null;
    }

    public static function withDiscovery(): void
    {
        self::$discovery = true;
        self::$reports = null;
    }

    /** Forget everything, including explicit registrations. */
    public static function reset(): void
    {
        self::$reports = null;
        self::$explicit = [];
        self::$owners = [];
        self::$discovery = true;
    }

    /** Drop the cached class list. Needed in a deployed install after a report class is added. */
    public static function flushCache(): void
    {
        self::$reports = null;

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Nothing cached yet, or no store. Not a failure.
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * Every registered report, ordered by group then title.
     *
     * @return Collection<string, ReportDefinition>
     */
    public static function all(): Collection
    {
        return collect(self::resolve())->sortBy([
            static fn (ReportDefinition $r): int => $r->group()->sort(),
            static fn (ReportDefinition $r): string => $r->title(),
        ]);
    }

    /**
     * @return Collection<string, ReportDefinition>
     */
    public static function group(ReportGroup $group): Collection
    {
        return self::all()->filter(
            static fn (ReportDefinition $r): bool => $r->group() === $group,
        );
    }

    public static function definition(string $key): ?ReportDefinition
    {
        return self::resolve()[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return isset(self::resolve()[$key]);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::resolve());
    }

    /** key => owning class. Used by the duplicate-key test and by `about`-style diagnostics. */
    public static function owners(): array
    {
        self::resolve();

        return self::$owners;
    }

    /*
    |--------------------------------------------------------------------------
    | Authorisation
    |--------------------------------------------------------------------------
    */

    /**
     * The reports this person may open.
     *
     * Module enabled **and** every entry of `permissions()` held — an AND, not an OR. A null user
     * gets nothing: a console run with no actor has no business listing reports on somebody's
     * behalf, and returning everything would be the wrong default for the one caller who forgets.
     *
     * This runs before any query, which is the point: a report a viewer may not open never executes.
     *
     * @return Collection<string, ReportDefinition>
     */
    public static function visibleTo(?Authenticatable $user): Collection
    {
        if ($user === null) {
            return collect();
        }

        return self::all()->filter(
            static fn (ReportDefinition $report): bool => self::allows($user, $report),
        );
    }

    /**
     * @return Collection<string, ReportDefinition>
     */
    public static function visibleGroup(?Authenticatable $user, ReportGroup $group): Collection
    {
        return self::visibleTo($user)->filter(
            static fn (ReportDefinition $r): bool => $r->group() === $group,
        );
    }

    /**
     * The reports this person may open, arranged by group, empty groups dropped.
     *
     * @return array<string, array{group: ReportGroup, reports: list<ReportDefinition>}>
     */
    public static function groupedFor(?Authenticatable $user): array
    {
        $grouped = [];

        foreach (self::visibleTo($user) as $report) {
            $slug = $report->group()->value;

            $grouped[$slug] ??= ['group' => $report->group(), 'reports' => []];
            $grouped[$slug]['reports'][] = $report;
        }

        uasort($grouped, static fn (array $a, array $b): int => $a['group']->sort() <=> $b['group']->sort());

        return $grouped;
    }

    /** May this person open this report? */
    public static function allows(?Authenticatable $user, ReportDefinition|string $report): bool
    {
        if ($user === null) {
            return false;
        }

        $definition = is_string($report) ? self::definition($report) : $report;

        if ($definition === null) {
            return false;
        }

        if (! Modules::enabled($definition->module())) {
            return false;
        }

        $gate = app(Gate::class)->forUser($user);

        foreach ($definition->permissions() as $permission) {
            if (! $gate->allows($permission)) {
                return false;
            }
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution and discovery
    |--------------------------------------------------------------------------
    */

    /**
     * key => report instance, memoised for the request.
     *
     * @return array<string, ReportDefinition>
     */
    private static function resolve(): array
    {
        if (self::$reports !== null) {
            return self::$reports;
        }

        $owners = [];
        $reports = [];

        // Explicit registrations first: they are the deliberate ones, so they own their key and a
        // discovered class colliding with them is the error, not the other way round.
        foreach (self::$explicit as $key => $instance) {
            $owners[$key] = $instance::class;
            $reports[$key] = $instance;
        }

        foreach (self::$discovery ? self::discover() : [] as $class) {
            try {
                $report = self::instantiate($class, strict: false);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            if ($report === null) {
                continue;
            }

            $key = trim($report->key());

            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                // Skip rather than break every other report on the page — and say so, because a
                // report that silently never appears is worse than one that appears broken.
                report(new InvalidArgumentException(sprintf(
                    'Report [%s] has a malformed key [%s] and was skipped.',
                    $class,
                    $key,
                )));

                continue;
            }

            $owner = $owners[$key] ?? null;

            if ($owner !== null) {
                if ($owner !== $class) {
                    throw self::duplicate($key, $class, $owner);
                }

                continue;
            }

            $owners[$key] = $class;
            $reports[$key] = $report;
        }

        self::$owners = $owners;

        return self::$reports = $reports;
    }

    /**
     * Report class names under `app/Reports`, cached with a directory fingerprint.
     *
     * @return list<class-string<ReportDefinition>>
     */
    private static function discover(): array
    {
        $directory = app_path(self::DISCOVERY_PATH);

        if (! is_dir($directory)) {
            return [];
        }

        $files = self::reportFiles($directory);
        $fingerprint = self::fingerprint($files);

        try {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint) {
                return array_values(array_filter($cached['classes'] ?? [], 'class_exists'));
            }
        } catch (Throwable) {
            // No cache store. Fall through and scan.
        }

        $classes = [];

        foreach ($files as $file) {
            $class = self::classFor($file);

            if ($class !== null) {
                $classes[] = $class;
            }
        }

        sort($classes);

        try {
            Cache::forever(self::CACHE_KEY, ['fingerprint' => $fingerprint, 'classes' => $classes]);
        } catch (Throwable) {
            // Not cacheable here. The scan still worked.
        }

        return $classes;
    }

    /**
     * @return list<\SplFileInfo>
     */
    private static function reportFiles(string $directory): array
    {
        try {
            $files = File::allFiles($directory);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(
            $files,
            static fn (\SplFileInfo $file): bool => $file->getExtension() === 'php',
        ));
    }

    /**
     * File count plus newest mtime. Cheap, and enough to notice a file added, removed or edited.
     *
     * @param  list<\SplFileInfo>  $files
     */
    private static function fingerprint(array $files): string
    {
        $newest = 0;

        foreach ($files as $file) {
            $newest = max($newest, (int) $file->getMTime());
        }

        return count($files).':'.$newest;
    }

    /** @return class-string|null */
    private static function classFor(\SplFileInfo $file): ?string
    {
        $relative = str_replace(
            [app_path().DIRECTORY_SEPARATOR, '/', '\\'],
            ['', '\\', '\\'],
            $file->getPathname(),
        );

        $class = 'App\\'.preg_replace('/\.php$/', '', $relative);

        return class_exists($class) ? $class : null;
    }

    /**
     * Build a report, or null when the class is not one.
     *
     * `strict` decides whether an unusable class is an error or a skip. Discovery skips, because a
     * broken file must not take the hub down; an explicit `register()` throws, because the caller
     * named that class on purpose.
     *
     * @param  class-string  $class
     */
    private static function instantiate(string $class, bool $strict): ?ReportDefinition
    {
        if (! class_exists($class)) {
            return $strict
                ? throw new InvalidArgumentException(sprintf('Report class [%s] does not exist.', $class))
                : null;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable() || ! $reflection->implementsInterface(ReportDefinition::class)) {
            return $strict
                ? throw new InvalidArgumentException(sprintf(
                    '[%s] must be an instantiable %s.',
                    $class,
                    ReportDefinition::class,
                ))
                : null;
        }

        // A report takes no constructor arguments by contract; resolving through the container
        // anyway means one that type-hints a service still works.
        return app($class);
    }

    private static function duplicate(string $key, string $class, string $owner): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Two reports claim the key [%s]: [%s] and [%s]. A report key reaches routes, '
            .'report_exports.report_key and bookmarked filters, so one silently replacing the other '
            .'would make an old export point at a different report.',
            $key,
            $owner,
            $class,
        ));
    }
}
