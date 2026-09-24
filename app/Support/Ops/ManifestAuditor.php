<?php

declare(strict_types=1);

namespace App\Support\Ops;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * The drift check behind `audit:manifest` (phase-24-25 §6.1).
 *
 * **A hand-written list of screens goes stale in a week.** That sentence is the whole justification
 * for the four manifests, and it applies just as much to the checker: this class never hardcodes a
 * route, a table or an upload. It reads the live route list, the live schema and the Form Requests
 * on disk, compares them to the manifests, and reports both directions — a thing with no row, and a
 * row whose thing is gone.
 *
 * **Both directions matter, and the second is the one people forget.** A route that vanished leaves
 * a manifest row that still passes every sweep, quietly asserting a guarantee about a screen nobody
 * can open. That is worse than a missing row, because it looks like coverage.
 *
 * **HD-4: authorization is proven by enumeration, not by sampling.** The output of this class is
 * what the acceptance suites use as their data provider, so a route with no row is not merely
 * untested — it fails CI, which is the only way a manifest stays honest across twenty-five phases.
 */
final class ManifestAuditor
{
    /** Where the four live. Relative to the project root. */
    private const MANIFESTS = [
        'screen' => 'tests/Support/screen-manifest.php',
        'route' => 'tests/Support/route-guard-manifest.php',
        'index' => 'tests/Support/index-manifest.php',
        'upload' => 'tests/Support/upload-manifest.php',
    ];

    /**
     * Route name prefixes that are infrastructure rather than screens.
     *
     * Not an excuse list: each of these is a route the framework or a package registered, that no
     * phase owns and that no sweep can meaningfully assert a permission or a query budget about.
     * A route of *ours* never belongs here — that is what the `rationale` column is for.
     *
     * @var list<string>
     */
    private const INFRASTRUCTURE = [
        'sanctum.',
        'ignition.',
        'horizon.',
        'telescope.',
        'livewire.',
        'debugbar.',
        'storage.',
    ];

    /**
     * Every finding, grouped by manifest.
     *
     * @return array<string, array{missing: list<string>, orphaned: list<string>, warnings: list<string>}>
     */
    public function audit(): array
    {
        return [
            'screen' => $this->auditScreens(),
            'route' => $this->auditRouteGuards(),
            'index' => $this->auditIndexes(),
            'upload' => $this->auditUploads(),
        ];
    }

    /**
     * Per-phase coverage, for `--coverage`.
     *
     * @return array<int, array<string, int>>
     */
    public function coverage(): array
    {
        $byPhase = [];

        foreach (['screen' => $this->load('screen'), 'route' => $this->load('route'), 'upload' => $this->load('upload')] as $kind => $rows) {
            foreach ($rows as $row) {
                $phase = (int) ($row['owner_phase'] ?? 0);
                $byPhase[$phase][$kind] = ($byPhase[$phase][$kind] ?? 0) + 1;
            }
        }

        ksort($byPhase);

        return $byPhase;
    }

    /*
    |--------------------------------------------------------------------------
    | The four audits
    |--------------------------------------------------------------------------
    */

    /**
     * Every GET route that renders something should have a screen row.
     *
     * @return array{missing: list<string>, orphaned: list<string>, warnings: list<string>}
     */
    private function auditScreens(): array
    {
        $rows = $this->load('screen');
        $declared = array_column($rows, 'route');

        $missing = [];
        $warnings = [];

        foreach ($this->namedRoutes() as $name => $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if ($this->isInfrastructure($name)) {
                continue;
            }

            // A JSON endpoint is not a screen. It still needs a route-guard row, which the next
            // audit covers, but asserting a query budget or an a11y pass on it is meaningless.
            if ($this->looksLikeEndpoint($name, $route)) {
                continue;
            }

            if (! in_array($name, $declared, true)) {
                $missing[] = $name;
            }
        }

        $orphaned = [];

        foreach ($declared as $name) {
            if (! Route::has($name)) {
                $orphaned[] = $name;
            }
        }

        // A row that claims a budget nobody could meet, or none at all.
        foreach ($rows as $row) {
            $budget = $row['query_budget'] ?? null;

            if ($budget === null) {
                $warnings[] = sprintf('%s has no query_budget', $row['route'] ?? '?');
            } elseif ((int) $budget > 100) {
                $warnings[] = sprintf('%s budgets %d queries — that is a page, not a budget', $row['route'], $budget);
            }
        }

        return $this->finding($missing, $orphaned, $warnings);
    }

    /**
     * Every named route needs a guard row, and an unguarded one needs a written reason.
     *
     * @return array{missing: list<string>, orphaned: list<string>, warnings: list<string>}
     */
    private function auditRouteGuards(): array
    {
        $rows = $this->load('route');
        $byRoute = [];

        foreach ($rows as $row) {
            $byRoute[$row['route'] ?? ''] = $row;
        }

        $missing = [];
        $warnings = [];

        foreach ($this->namedRoutes() as $name => $route) {
            if ($this->isInfrastructure($name)) {
                continue;
            }

            if (! isset($byRoute[$name])) {
                $missing[] = $name;

                continue;
            }

            $row = $byRoute[$name];

            // §6.1: "a public route must carry an explicit rationale string, so 'I forgot the
            // permission' can never look like 'this route is deliberately public'."
            //
            // **A policy-gated route is guarded.** `can:viewAny,App\Models\Client` enforces a
            // real decision; its ability is a method name rather than a `module.ability` string,
            // which is why it does not appear in `permission`. Demanding a rationale for it would
            // put two hundred "this route is deliberately public" sentences next to two hundred
            // routes that are nothing of the kind — and a rationale column that is mostly noise is
            // a column nobody reads, which defeats the one thing it is for.
            $permission = $row['permission'] ?? null;
            $rationale = trim((string) ($row['rationale'] ?? ''));

            if ($permission === null && $rationale === '' && ! $this->isGuarded($route)) {
                $missing[] = $name.' (no permission and no rationale — say which it is)';
            }

            // The row has to describe the route as it is now, not as it was when somebody wrote it.
            //
            // A row may name several abilities as `"a + b"`, because a route can stack them - an
            // export that needs both `invoices.export` and `invoices.view_financial`, say. Compared
            // as one opaque string that reads as a mismatch against a route enforcing exactly those
            // two, which is how a checker ends up reporting twelve findings that are all itself.
            $declared = $this->declaredAbilities($permission);
            $live = $this->abilitiesOf($route);

            if ($declared !== [] && $live !== [] && array_diff($declared, $live) !== []) {
                $warnings[] = sprintf(
                    '%s declares [%s] but the route enforces [%s]',
                    $name,
                    implode(', ', $declared),
                    implode(', ', $live),
                );
            }

            $stateChanging = array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) !== [];

            if (($row['state_changing'] ?? null) !== null && (bool) $row['state_changing'] !== $stateChanging) {
                $warnings[] = sprintf('%s is marked state_changing=%s and is not', $name, var_export($row['state_changing'], true));
            }
        }

        $orphaned = [];

        foreach (array_keys($byRoute) as $name) {
            if ($name !== '' && ! Route::has($name)) {
                $orphaned[] = $name;
            }
        }

        return $this->finding($missing, $orphaned, $warnings);
    }

    /**
     * Every index the manifest requires must exist, and every foreign key needs one.
     *
     * @return array{missing: list<string>, orphaned: list<string>, warnings: list<string>}
     */
    private function auditIndexes(): array
    {
        $manifest = $this->load('index');

        $missing = [];
        $orphaned = [];
        $warnings = [];

        $schema = DB::getDatabaseName();

        foreach ($manifest as $table => $required) {
            if (! Schema::hasTable((string) $table)) {
                $orphaned[] = sprintf('%s — the manifest wants indexes on a table that is gone', $table);

                continue;
            }

            $present = $this->indexesOf((string) $table, $schema);

            foreach ($required as $columns) {
                $columns = array_map('strval', (array) $columns);

                if (! $this->covered($present, $columns)) {
                    $missing[] = sprintf('%s (%s)', $table, implode(', ', $columns));
                }
            }

            // §2.5: more than twelve indexes is a warning, never a failure — a wide table may
            // genuinely need them, and the note is there so somebody looks rather than so CI stops.
            if (count($present) > 12) {
                $warnings[] = sprintf('%s carries %d indexes — worth a written note', $table, count($present));
            }
        }

        // The rule the manifest cannot state row by row: every FK column needs a usable index, or
        // every delete on the parent table scans the child.
        foreach ($this->foreignKeyColumnsWithoutIndex($schema) as $entry) {
            $missing[] = $entry.' — a foreign key with no index';
        }

        return $this->finding($missing, $orphaned, $warnings);
    }

    /**
     * Every upload endpoint must be declared, with its disk.
     *
     * @return array{missing: list<string>, orphaned: list<string>, warnings: list<string>}
     */
    private function auditUploads(): array
    {
        $rows = $this->load('upload');
        $declared = array_column($rows, 'route');

        $missing = [];
        $orphaned = [];
        $warnings = [];

        foreach ($declared as $name) {
            if ($name !== null && $name !== '' && ! Route::has($name)) {
                $orphaned[] = $name;
            }
        }

        // §6.1: a row whose disk is `public` while the field is a private artefact fails (D21).
        foreach ($rows as $row) {
            $disk = (string) ($row['disk'] ?? '');
            $field = (string) ($row['field'] ?? '');

            if ($disk === 'public' && $this->isPrivateArtefact($field)) {
                $missing[] = sprintf(
                    '%s stores %s on the PUBLIC disk — D21 says a private artefact never goes there',
                    $row['route'] ?? '?',
                    $field,
                );
            }

            if (($row['allowed_mimes'] ?? []) === []) {
                $warnings[] = sprintf('%s declares no allowed_mimes', $row['route'] ?? '?');
            }

            if (($row['max_mb'] ?? null) === null) {
                $warnings[] = sprintf('%s declares no max_mb', $row['route'] ?? '?');
            }
        }

        // A Form Request that validates a file, whose route has no row.
        foreach ($this->requestsValidatingFiles() as $class => $fields) {
            if ($this->anyRowMentions($rows, $class, $fields)) {
                continue;
            }

            $warnings[] = sprintf(
                '%s validates %s but no upload-manifest row mentions it',
                class_basename($class),
                implode(', ', $fields),
            );
        }

        return $this->finding($missing, $orphaned, $warnings);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading the world
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, RouteInstance>
     */
    private function namedRoutes(): array
    {
        $routes = [];

        /** @var RouteInstance $route */
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if ($name !== '') {
                $routes[$name] = $route;
            }
        }

        ksort($routes);

        return $routes;
    }

    /**
     * The `.`-containing abilities a route's `can:` middleware enforces.
     *
     * A policy-method `can:viewAny,Model` contributes nothing: the ability is a method name, not a
     * permission string, and pretending otherwise would have the manifest compare two different
     * vocabularies.
     *
     * @return list<string>
     */
    private function abilitiesOf(RouteInstance $route): array
    {
        $abilities = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            foreach (['can:', 'permission:'] as $prefix) {
                if (! str_starts_with($middleware, $prefix)) {
                    continue;
                }

                $ability = explode(',', mb_substr($middleware, mb_strlen($prefix)))[0];

                foreach (explode('|', $ability) as $one) {
                    if (str_contains($one, '.')) {
                        $abilities[] = $one;
                    }
                }
            }
        }

        return array_values(array_unique($abilities));
    }

    /**
     * Index name => its columns in order, for one table.
     *
     * @return array<string, list<string>>
     */
    private function indexesOf(string $table, string $schema): array
    {
        $indexes = [];

        foreach (DB::select(
            'SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$schema, $table],
        ) as $row) {
            $indexes[$row->INDEX_NAME][] = $row->COLUMN_NAME;
        }

        return $indexes;
    }

    /**
     * Is a required column list covered by some index?
     *
     * A **prefix** counts (§2.5): an index on `(a, b, c)` satisfies a requirement for `(a, b)`,
     * because MariaDB can use the leftmost part. The reverse is not true and is not accepted.
     *
     * @param  array<string, list<string>>  $present
     * @param  list<string>  $required
     */
    private function covered(array $present, array $required): bool
    {
        foreach ($present as $columns) {
            if (array_slice($columns, 0, count($required)) === $required) {
                return true;
            }
        }

        return false;
    }

    /**
     * Foreign key columns with no index whose leftmost column is that column.
     *
     * @return list<string>
     */
    private function foreignKeyColumnsWithoutIndex(string $schema): array
    {
        $bare = [];

        $keys = DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$schema],
        );

        $cache = [];

        foreach ($keys as $key) {
            $table = (string) $key->TABLE_NAME;
            $column = (string) $key->COLUMN_NAME;

            $cache[$table] ??= $this->indexesOf($table, $schema);

            if (! $this->covered($cache[$table], [$column])) {
                $bare[] = $table.'.'.$column;
            }
        }

        return array_values(array_unique($bare));
    }

    /**
     * Form Request classes whose rules mention a file, and the fields they name.
     *
     * Read as text rather than instantiated: a Form Request's `rules()` may reach for the container,
     * the route or the authenticated user, and none of those exist while a console command is
     * scanning the filesystem.
     *
     * @return array<class-string, list<string>>
     */
    private function requestsValidatingFiles(): array
    {
        $found = [];
        $directory = app_path('Http/Requests');

        if (! is_dir($directory)) {
            return [];
        }

        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // `'file'`, `'image'`, `mimes:`, `mimetypes:` — the four ways a rule says "an upload".
            if (preg_match('/[\'"](?:file|image)[\'"]|mimes:|mimetypes:/', $source) !== 1) {
                continue;
            }

            preg_match_all('/[\'"]([a-z_][a-z0-9_.*]*)[\'"]\s*=>\s*\[[^]]*[\'"](?:file|image)[\'"]/i', $source, $matches);

            $fields = array_values(array_unique($matches[1] ?? []));

            $class = 'App\\Http\\Requests\\'.str_replace(
                ['/', '\\.php'],
                ['\\', ''],
                mb_substr($file->getPathname(), mb_strlen($directory) + 1),
            );

            $class = preg_replace('/\.php$/', '', $class) ?? $class;

            $found[$class] = $fields === [] ? ['(a file rule)'] : $fields;
        }

        return $found;
    }

    /*
    |--------------------------------------------------------------------------
    | Small judgements, each stated once
    |--------------------------------------------------------------------------
    */

    /**
     * The abilities a manifest row declares.
     *
     * One row may name several, joined by `+` - a route that stacks an export permission and a
     * financial one is a real shape, and 12 of the first run's findings were this checker not
     * knowing it.
     *
     * @return list<string>
     */
    private function declaredAbilities(?string $permission): array
    {
        if ($permission === null || trim($permission) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode('+', $permission)),
            static fn (string $one): bool => str_contains($one, '.'),
        ));
    }

    /**
     * Does any authorization middleware run on this route at all?
     *
     * Broader than {@see self::abilitiesOf()} on purpose: that one answers "which named permission",
     * this one answers "is a decision made". A `can:viewAny,Model` satisfies the second and not the
     * first, and conflating them is how a guarded route gets reported as a hole.
     */
    private function isGuarded(RouteInstance $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && (str_starts_with($middleware, 'can:') || str_starts_with($middleware, 'permission:'))) {
                return true;
            }
        }

        return false;
    }

    private function isInfrastructure(string $name): bool
    {
        foreach (self::INFRASTRUCTURE as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this GET route answer with data rather than a page?
     *
     * Matched on the route's own naming rather than on a list, because the convention is already
     * consistent across twenty-three phases: `.suggest`, `.schema`, `.chart`, `.recipients`,
     * `.summary`, `.check`, `.search`, `.options`, `.lookup`, `.download`, `.export`, `.print`,
     * `.stream` and `.pdf` are all endpoints, and none of them is a screen a person navigates to.
     */
    private function looksLikeEndpoint(string $name, RouteInstance $route): bool
    {
        foreach ([
            '.suggest', '.schema', '.chart', '.recipients', '.summary', '.check', '.search',
            '.options', '.lookup', '.download', '.export', '.stream', '.pdf', '.preview',
            '.autocomplete', '.json', '.ping', '.poll', '.unread', '.count',
        ] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return str_contains($route->uri(), 'api/');
    }

    /**
     * Is this field a private artefact that must never reach the public disk (D21)?
     */
    private function isPrivateArtefact(string $field): bool
    {
        foreach ([
            'receipt', 'invoice', 'payslip', 'salary', 'statement', 'cnic', 'passport',
            'contract', 'agreement', 'certificate', 'id_card', 'submission', 'feedback',
            'material', 'document', 'cv', 'resume',
        ] as $needle) {
            if (str_contains(mb_strtolower($field), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields
     */
    private function anyRowMentions(array $rows, string $class, array $fields): bool
    {
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                if (($row['field'] ?? null) === $field) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>|array<string, mixed>
     */
    private function load(string $which): array
    {
        $path = base_path(self::MANIFESTS[$which]);

        if (! file_exists($path)) {
            return [];
        }

        $rows = require $path;

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param  list<string>  $missing
     * @param  list<string>  $orphaned
     * @param  list<string>  $warnings
     * @return array{missing: list<string>, orphaned: list<string>, warnings: list<string>}
     */
    private function finding(array $missing, array $orphaned, array $warnings): array
    {
        sort($missing);
        sort($orphaned);
        sort($warnings);

        return ['missing' => $missing, 'orphaned' => $orphaned, 'warnings' => $warnings];
    }

    /** The manifest paths, for the command's output and for the writer. */
    public static function paths(): array
    {
        return self::MANIFESTS;
    }
}
