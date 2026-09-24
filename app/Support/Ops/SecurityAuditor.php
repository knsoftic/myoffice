<?php

declare(strict_types=1);

namespace App\Support\Ops;

use App\Support\PermissionRegistry;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use SplFileInfo;
use Throwable;

/**
 * The static half of `security:audit` (phase-24-25 §6.6).
 *
 * **Everything here is decidable by reading the code**, which is why it lives apart from the
 * runtime probes: it can run in CI against a checkout with no database and no server, where a
 * finding is a failing pull request rather than a note in a nightly report. A route that lost its
 * permission should be caught before it ships, not after.
 *
 * Each check returns findings in one shape — `{code, severity, subject, expected, actual}` — so the
 * command can print them, the service can store them, and neither needs to know which check
 * produced which.
 *
 * **Severity is not decoration.** `failed` means a door is open; `warning` means something needs a
 * human to look. The distinction is what lets `security:audit` exit 1 on a nightly run without
 * waking somebody, and exit 2 when it must.
 */
final class SecurityAuditor
{
    /**
     * Models whose `$guarded = []` is defensible.
     *
     * Empty, and it should stay that way: mass-assignment protection off on a model that a request
     * ever fills is how `is_admin` gets set by a form post. A model that genuinely needs it names
     * itself here with a reason, and the reason has to survive review.
     *
     * @var array<string, string>
     */
    private const GUARDED_EXEMPT = [];

    /**
     * Directories that must not be writable by the web server in production.
     */
    private const MUST_NOT_BE_WRITABLE = ['app', 'config', 'routes', 'database/migrations'];

    /**
     * A raw echo in a Blade view: `{!! ... !!}` or an Alpine `x-html`.
     */
    private const RAW_ECHO = '/\{!!.*?!!\}|x-html\s*=/s';

    /**
     * Every static check.
     *
     * @return list<array<string, mixed>>
     */
    public function audit(): array
    {
        return array_merge(
            $this->auditUnguardedRoutes(),
            $this->auditCsrfExceptions(),
            $this->auditMassAssignment(),
            $this->auditRawSql(),
            $this->auditRawEchoes(),
            $this->auditInlineScripts(),
            $this->auditEnvironment(),
            $this->auditEnvFile(),
            $this->auditWritableDirectories(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    /**
     * A state-changing route with no authorization at all.
     *
     * The manifest is the authority on which routes are deliberately open (§6.1) — each one carries
     * a written rationale, which is the whole point of that column. This check finds routes the
     * manifest does not cover, because an unlisted open route is the one nobody decided about.
     *
     * @return list<array<string, mixed>>
     */
    public function auditUnguardedRoutes(): array
    {
        $manifest = $this->routeManifest();
        $findings = [];

        /** @var RouteInstance $route */
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if ($name === '' || isset($manifest[$name])) {
                continue;
            }

            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $stateChanging = array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) !== [];

            if ($this->isGuarded($middleware)) {
                continue;
            }

            $findings[] = $this->finding(
                'route.unguarded',
                $stateChanging ? 'failed' : 'warning',
                $name,
                'a permission, a policy, or a manifest row saying why it needs none',
                'middleware: '.(implode(' ', $middleware) ?: 'none'),
            );
        }

        return $findings;
    }

    /**
     * CSRF exemptions.
     *
     * An entry in `VerifyCsrfToken::$except` is a route that accepts a POST from any origin. There
     * are legitimate reasons — a payment gateway callback — and every one of them needs a signature
     * check instead, so each is a finding a human confirms rather than something to allowlist here.
     *
     * @return list<array<string, mixed>>
     */
    public function auditCsrfExceptions(): array
    {
        $except = [];

        foreach ([
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ] as $class) {
            if (! class_exists($class)) {
                continue;
            }

            try {
                $property = (new ReflectionClass($class))->getProperty('except');
                $property->setAccessible(true);

                $value = $property->getDefaultValue();

                if (is_array($value)) {
                    $except = array_merge($except, $value);
                }
            } catch (Throwable) {
                // The framework's own class has no such property in Laravel 12 unless published.
            }
        }

        $except = array_values(array_unique(array_filter($except, 'is_string')));

        if ($except === []) {
            return [];
        }

        return [$this->finding(
            'csrf.exempt',
            'failed',
            implode(', ', $except),
            'no CSRF exemptions',
            sprintf('%d path(s) accept a cross-origin POST', count($except)),
        )];
    }

    /*
    |--------------------------------------------------------------------------
    | Models and queries
    |--------------------------------------------------------------------------
    */

    /**
     * A model with mass assignment switched off.
     *
     * @return list<array<string, mixed>>
     */
    public function auditMassAssignment(): array
    {
        $findings = [];

        foreach ($this->phpFiles(app_path('Models')) as $file) {
            $source = (string) File::get($file->getPathname());
            $relative = $this->relative($file->getPathname());

            if (isset(self::GUARDED_EXEMPT[$relative])) {
                continue;
            }

            if (preg_match('/\$guarded\s*=\s*\[\s*\]/', $this->withoutComments($source)) === 1) {
                $findings[] = $this->finding(
                    'model.unguarded',
                    'failed',
                    $relative,
                    'an explicit $fillable whitelist',
                    '$guarded = [] — every column is mass-assignable',
                );
            }
        }

        return $findings;
    }

    /**
     * A query that interpolates a variable into SQL.
     *
     * `whereRaw("id = $id")` is the shape; `whereRaw('id = ?', [$id])` is the fix. The check looks
     * for a `$` or a `{` inside the string literal of a raw query method, which is the only way a
     * value reaches SQL unparameterised.
     *
     * @return list<array<string, mixed>>
     */
    public function auditRawSql(): array
    {
        $methods = 'whereRaw|havingRaw|orderByRaw|selectRaw|groupByRaw|joinSub|DB::raw|raw';
        $findings = [];

        foreach ([app_path(), base_path('database')] as $root) {
            foreach ($this->phpFiles($root) as $file) {
                $source = (string) File::get($file->getPathname());

                // The auditor's own docblock example is not a finding.
                if (str_contains($file->getPathname(), 'SecurityAuditor')) {
                    continue;
                }

                /*
                | A double-quoted string containing a real PHP variable, passed to a raw method.
                |
                | `\$[A-Za-z_]` and `{\$` rather than a bare `\$`, because `"'\$.old'"` is a MySQL
                | JSON path - `$` is the document root there and PHP leaves it literal. Matching it
                | reported two clean queries in AuditTrailService as injections.
                */
                if (preg_match_all(
                    '/(?:'.$methods.')\s*\(\s*"[^"]*(?:\{\$|\$[A-Za-z_])[^"]*"/',
                    $this->withoutComments($source),
                    $matches,
                ) === 0) {
                    continue;
                }

                foreach ($matches[0] as $match) {
                    $findings[] = $this->finding(
                        'sql.interpolated',
                        'failed',
                        $this->relative($file->getPathname()),
                        'a bound parameter',
                        mb_substr(preg_replace('/\s+/', ' ', $match) ?? '', 0, 120),
                    );
                }
            }
        }

        return $findings;
    }

    /*
    |--------------------------------------------------------------------------
    | Views
    |--------------------------------------------------------------------------
    */

    /**
     * An unescaped echo with no allowlist row.
     *
     * The allowlist (D25) names the sanitiser for every legitimate one. A raw echo that is not on
     * it is either a mistake or a decision nobody wrote down, and both need the same answer.
     *
     * @return list<array<string, mixed>>
     */
    public function auditRawEchoes(): array
    {
        $allowed = [];

        foreach ($this->allowlist() as $row) {
            if (is_array($row) && isset($row['view'])) {
                $allowed[(string) $row['view']] = (int) ($row['occurrences'] ?? 1);
            }
        }

        $findings = [];

        foreach ($this->bladeFiles() as $file) {
            $source = $this->withoutBladeComments((string) File::get($file->getPathname()));
            $relative = $this->viewPath($file->getPathname());

            $count = preg_match_all(self::RAW_ECHO, $source);

            if ($count === 0) {
                continue;
            }

            $permitted = $allowed[$relative] ?? 0;

            if ($count <= $permitted) {
                continue;
            }

            $findings[] = $this->finding(
                'view.raw_echo',
                'failed',
                $relative,
                $permitted === 0
                    ? 'an escaped echo, or an allowlist row naming the sanitiser'
                    : sprintf('%d allowlisted raw echo(es)', $permitted),
                sprintf('%d raw echo(es) found', $count),
            );
        }

        return $findings;
    }

    /**
     * An inline `<script>` with no nonce.
     *
     * The Content-Security-Policy allows an inline script only when it carries this request's
     * nonce. One without is refused by the browser — silently, in production, where nobody has a
     * console open — so the script simply does not run and the page half-works.
     *
     * @return list<array<string, mixed>>
     */
    public function auditInlineScripts(): array
    {
        $findings = [];

        foreach ($this->bladeFiles() as $file) {
            $source = $this->withoutBladeComments((string) File::get($file->getPathname()));

            if (preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/i', $source, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $attributes) {
                // A type that is not JavaScript is data, not code, and the policy does not govern it.
                if (preg_match('/type\s*=\s*["\'](?:application\/(?:ld\+json|json)|text\/template)["\']/i', $attributes) === 1) {
                    continue;
                }

                if (str_contains($attributes, 'nonce')) {
                    continue;
                }

                $findings[] = $this->finding(
                    'view.inline_script',
                    'failed',
                    $this->viewPath($file->getPathname()),
                    'nonce="{{ csp_nonce() }}"',
                    'an inline <script> with no nonce — the browser will refuse it',
                );
            }
        }

        return $findings;
    }

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    */

    /**
     * The settings that turn a production server into a debugging one.
     *
     * @return list<array<string, mixed>>
     */
    public function auditEnvironment(): array
    {
        $findings = [];
        $production = app()->environment('production');

        if ($production && config('app.debug') === true) {
            $findings[] = $this->finding(
                'env.debug',
                'failed',
                'APP_DEBUG',
                'false in production',
                'true — every exception page prints a stack trace, the environment and the database credentials',
            );
        }

        if ($production && config('app.key') === null) {
            $findings[] = $this->finding(
                'env.key',
                'failed',
                'APP_KEY',
                'a generated key',
                'unset — every encrypted column and every signed URL is unverifiable',
            );
        }

        if (! $production && app()->environment('local') === false && app()->environment('testing') === false) {
            $findings[] = $this->finding(
                'env.unknown',
                'warning',
                'APP_ENV',
                'local, testing or production',
                (string) app()->environment(),
            );
        }

        return $findings;
    }

    /**
     * The environment file itself.
     *
     * @return list<array<string, mixed>>
     */
    public function auditEnvFile(): array
    {
        $findings = [];
        $path = base_path('.env');

        if (! is_file($path)) {
            return $findings;
        }

        // Reachable over the web is the one that matters: a `.env` inside the document root is the
        // whole system, handed out on request.
        $public = base_path('public');
        $real = realpath($path);

        if ($real !== false && str_starts_with($real, (string) realpath($public))) {
            $findings[] = $this->finding(
                'env.reachable',
                'failed',
                '.env',
                'outside the document root',
                'inside public/ — it is served on request',
            );
        }

        // Permissions are a POSIX concept; on Windows the mode is not meaningful and reporting it
        // would be noise on every run.
        if (DIRECTORY_SEPARATOR === '/') {
            $mode = @fileperms($path);

            if ($mode !== false && ($mode & 0o044) !== 0) {
                $findings[] = $this->finding(
                    'env.permissions',
                    'warning',
                    '.env',
                    'mode 600 or 640',
                    sprintf('mode %o — readable beyond its owner', $mode & 0o777),
                );
            }
        }

        return $findings;
    }

    /**
     * Directories the web server should not be able to write to.
     *
     * A writable `app/` means an upload that lands in the wrong place is executable code.
     *
     * @return list<array<string, mixed>>
     */
    public function auditWritableDirectories(): array
    {
        if (! app()->environment('production')) {
            // On a development machine the whole checkout is writable and should be.
            return [];
        }

        $findings = [];

        foreach (self::MUST_NOT_BE_WRITABLE as $relative) {
            $path = base_path($relative);

            if (is_dir($path) && is_writable($path)) {
                $findings[] = $this->finding(
                    'fs.writable',
                    'failed',
                    $relative,
                    'read-only to the web server',
                    'writable — an upload that escapes its directory becomes executable code',
                );
            }
        }

        foreach (['storage', 'bootstrap/cache'] as $relative) {
            $path = base_path($relative);

            if (is_dir($path) && ! is_writable($path)) {
                $findings[] = $this->finding(
                    'fs.unwritable',
                    'failed',
                    $relative,
                    'writable',
                    'not writable — the application cannot log, cache or accept an upload',
                );
            }
        }

        return $findings;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Whether a middleware stack enforces anything.
     *
     * A `can:` in either form counts — `can:clients.view` is a permission and
     * `can:viewAny,App\Models\Client` is a policy — and so does `permission:`. `auth` alone does
     * not: being signed in is not authorization.
     *
     * @param  list<string>  $middleware
     */
    public function isGuarded(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            if (str_starts_with($entry, 'can:') || str_starts_with($entry, 'permission:') || str_starts_with($entry, 'role:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function routeManifest(): array
    {
        $path = base_path('tests/Support/route-guard-manifest.php');

        if (! is_file($path)) {
            return [];
        }

        /** @var mixed $rows */
        $rows = require $path;
        $keyed = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['route']) && is_string($row['route'])) {
                $keyed[$row['route']] = $row;
            }
        }

        return $keyed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allowlist(): array
    {
        $path = base_path('tests/Support/raw-output-allowlist.php');

        if (! is_file($path)) {
            return [];
        }

        /** @var mixed $rows */
        $rows = require $path;

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @return list<SplFileInfo>
     */
    private function phpFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        return array_values(array_filter(
            File::allFiles($root),
            static fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
        ));
    }

    /**
     * @return list<SplFileInfo>
     */
    private function bladeFiles(): array
    {
        $root = resource_path('views');

        if (! is_dir($root)) {
            return [];
        }

        return array_values(array_filter(
            File::allFiles($root),
            static fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'),
        ));
    }

    /**
     * Source with its comments removed.
     *
     * Every check here looks for a *pattern in code*, and a docblock explaining why the code does
     * not do the thing matches the pattern for the thing. `Activity.php` was reported for
     * `$guarded = []` by a comment saying spatie ships that and this model does not.
     *
     * `token_get_all()` rather than a regex: a regex that strips comments will eventually strip
     * something inside a string literal, and this is a security checker.
     */
    public function withoutComments(string $source): string
    {
        try {
            $tokens = token_get_all($source);
        } catch (Throwable) {
            return $source;
        }

        $out = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                // Comments are replaced with a newline rather than removed, so line-anchored
                // patterns and any future line reporting stay honest.
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? "\n"
                    : $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    /**
     * A Blade template with its `{{-- --}}` comments removed.
     *
     * Every view header in this codebase explains what the file does, and several explain the very
     * rules these checks enforce — `prose.blade.php` says a `<script>` tag renders sanitised, and
     * `errors/layout.blade.php` says an inline script needs a nonce. Scanning the comment reported
     * both as findings, which is the checker failing the documentation for being accurate.
     */
    public function withoutBladeComments(string $source): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }

    private function relative(string $path): string
    {
        return str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
    }

    private function viewPath(string $path): string
    {
        return str_replace([resource_path('views').DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(string $code, string $severity, string $subject, string $expected, string $actual): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'subject' => $subject,
            'expected' => $expected,
            'actual' => $actual,
        ];
    }

    /**
     * Every module the registry declares, for a caller that wants to cross-check a subject.
     *
     * @return list<string>
     */
    public function knownModules(): array
    {
        return array_keys(PermissionRegistry::modules());
    }
}
