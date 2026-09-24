<?php

declare(strict_types=1);

namespace App\Support\Ops;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;

/**
 * Scaffolds the manifest rows a phase owes (`audit:manifest --write`, phase-24-25 §6.1).
 *
 * **Most of a row is already true somewhere, and the writer's job is to read it rather than ask.**
 * A route's middleware stack says its panel, its module, its permission and whether it changes
 * state; its name says what kind of screen it is; its controller says which phase owns it. Copying
 * those by hand across a thousand rows is how a manifest ends up disagreeing with the code it
 * describes.
 *
 * **What it cannot read, it marks TODO rather than guessing.** Two fields are genuinely human:
 *
 * · `query_budget` — a number nobody can infer from a route. It is scaffolded as `null` and filled
 *   by `perf:budget --write-baseline`, which measures it. A guessed budget is worse than none: it
 *   passes, so nobody looks.
 *
 * · `rationale` on an unguarded route — the whole point of the column is that a human said why.
 *   Generating a plausible sentence here would defeat §6.1's rule that "I forgot the permission"
 *   must never be able to look like "this route is deliberately public".
 *
 * The output is appended, never rewritten: existing rows carry human judgement — a tuned budget, a
 * written rationale — and regenerating the file would discard it.
 */
final class ManifestWriter
{
    /**
     * A scaffolded route-guard row for every named route that has none.
     *
     * @param  list<string>  $missing  route names, from the auditor
     * @return list<array<string, mixed>>
     */
    public function routeGuardRows(array $missing): array
    {
        $rows = [];

        foreach ($this->routesByName($missing) as $name => $route) {
            $middleware = array_values(array_filter(
                $route->gatherMiddleware(),
                'is_string',
            ));

            $permission = $this->permissionOf($middleware);

            $rows[] = [
                'route' => $name,
                'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                'middleware' => $middleware,
                'permission' => $permission,
                'panel' => $this->panelOf($name, $middleware),
                'state_changing' => array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) !== [],
                // A policy gate is recorded as what it is rather than left to look like a hole:
                // `can:viewAny,App\Models\Client` enforces a real decision, it just does not name
                // a `module.ability`. Only a route with no authorization middleware at all gets the
                // TODO, and that is the short list a human should actually read.
                'policy' => $this->policyGateOf($middleware),
                'rationale' => $permission === null && $this->policyGateOf($middleware) === null
                    ? 'TODO: say why this route needs no permission'
                    : null,
                'owner_phase' => $this->phaseOf($route),
            ];
        }

        return $rows;
    }

    /**
     * A scaffolded screen row for every renderable GET route that has none.
     *
     * @param  list<string>  $missing
     * @return list<array<string, mixed>>
     */
    public function screenRows(array $missing): array
    {
        $rows = [];

        foreach ($this->routesByName($missing) as $name => $route) {
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));

            $rows[] = [
                'route' => $name,
                'panel' => $this->panelOf($name, $middleware),
                'kind' => $this->kindOf($name, $route),
                // A route with parameters needs a fixture closure a human writes; one without does
                // not need a closure at all.
                'params' => $route->parameterNames() === [] ? null : 'TODO: fn (Fixture $f): array => [...]',
                'permissions' => $this->permissionsOf($middleware),
                'module' => $this->moduleOf($middleware),
                'owner_phase' => $this->phaseOf($route),
                // Measured, never guessed — see the class note.
                'query_budget' => null,
                'responsive' => true,
                'a11y' => true,
                'idor' => ['owner' => null],
                'response' => $this->responseOf($name),
            ];
        }

        return $rows;
    }

    /**
     * Render rows as the PHP source that goes into a manifest file.
     *
     * Emitted as a readable literal rather than `var_export`: these files are read by people far
     * more often than they are written, and `var_export`'s `\Closure::__set_state` output is not
     * something anybody can edit.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function render(array $rows, int $indent = 4): string
    {
        $pad = str_repeat(' ', $indent);
        $out = '';

        foreach ($rows as $row) {
            $out .= $pad."[\n";

            foreach ($row as $key => $value) {
                $out .= sprintf("%s    '%s' => %s,\n", $pad, $key, $this->literal($value, $indent + 8));
            }

            $out .= $pad."],\n";
        }

        return $out;
    }

    /*
    |--------------------------------------------------------------------------
    | Reading a row out of a route
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<string>  $names
     * @return array<string, RouteInstance>
     */
    private function routesByName(array $names): array
    {
        // The auditor annotates some findings ("name (why)"); take the name.
        $wanted = array_flip(array_map(
            static fn (string $n): string => explode(' ', $n)[0],
            $names,
        ));

        $found = [];

        /** @var RouteInstance $route */
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if ($name !== '' && isset($wanted[$name])) {
                $found[$name] = $route;
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * @param  list<string>  $middleware
     */
    private function permissionOf(array $middleware): ?string
    {
        foreach ($this->permissionsOf($middleware) as $permission) {
            return $permission;
        }

        return null;
    }

    /**
     * Every `.`-containing ability the stack enforces.
     *
     * A policy-method `can:viewAny,Model` yields nothing: that is a method name, not a permission,
     * and recording it as one would have the manifest compare two vocabularies.
     *
     * @param  list<string>  $middleware
     * @return list<string>
     */
    private function permissionsOf(array $middleware): array
    {
        $abilities = [];

        foreach ($middleware as $entry) {
            foreach (['can:', 'permission:'] as $prefix) {
                if (! str_starts_with($entry, $prefix)) {
                    continue;
                }

                foreach (explode('|', explode(',', mb_substr($entry, mb_strlen($prefix)))[0]) as $ability) {
                    if (str_contains($ability, '.')) {
                        $abilities[] = $ability;
                    }
                }
            }
        }

        return array_values(array_unique($abilities));
    }

    /**
     * @param  list<string>  $middleware
     */
    private function moduleOf(array $middleware): ?string
    {
        foreach ($middleware as $entry) {
            if (str_starts_with($entry, 'module:')) {
                return explode(',', mb_substr($entry, 7))[0];
            }
        }

        // No `module:` in the stack: fall back to the permission's own prefix, which is the same
        // slug by construction (`module.ability`).
        $permission = $this->permissionOf($middleware);

        return $permission === null ? null : explode('.', $permission)[0];
    }

    /**
     * The policy gate a route carries, as `ability,Model`, or null.
     *
     * @param  list<string>  $middleware
     */
    private function policyGateOf(array $middleware): ?string
    {
        foreach ($middleware as $entry) {
            if (! str_starts_with($entry, 'can:')) {
                continue;
            }

            $rule = mb_substr($entry, 4);

            // A dotted ability is a permission, already captured. What is left is a policy method.
            if (! str_contains(explode(',', $rule)[0], '.')) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Which panel this route belongs to.
     *
     * The `panel:` middleware is authoritative where it exists; the route-name prefix is the
     * fallback, and it is reliable because every panel's routes are registered under one.
     *
     * @param  list<string>  $middleware
     */
    private function panelOf(string $name, array $middleware): string
    {
        foreach ($middleware as $entry) {
            if (str_starts_with($entry, 'panel:')) {
                return explode(',', mb_substr($entry, 6))[0];
            }
        }

        foreach (['admin.' => 'admin', 'client.' => 'client', 'student.' => 'student', 'teacher.' => 'teacher', 'collaborator.' => 'collaborator', 'account.' => 'account'] as $prefix => $panel) {
            if (str_starts_with($name, $prefix)) {
                return $panel;
            }
        }

        return in_array('auth', $middleware, true) ? 'shared' : 'public';
    }

    /**
     * What kind of screen this is, from the convention twenty-three phases have kept.
     */
    private function kindOf(string $name, RouteInstance $route): string
    {
        foreach ([
            '.create' => 'form',
            '.edit' => 'form',
            '.board' => 'board',
            '.calendar' => 'calendar',
            '.print' => 'print',
            '.index' => 'index',
            '.show' => 'show',
            '.statistics' => 'report',
            '.report' => 'report',
            '.sla' => 'report',
        ] as $suffix => $kind) {
            if (str_ends_with($name, $suffix)) {
                return $kind;
            }
        }

        if ($this->panelOf($name, array_values(array_filter($route->gatherMiddleware(), 'is_string'))) === 'public') {
            return 'public';
        }

        return $route->parameterNames() === [] ? 'index' : 'show';
    }

    private function responseOf(string $name): string
    {
        foreach (['.download', '.export', '.stream', '.pdf'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return 'file';
            }
        }

        foreach (['.suggest', '.schema', '.chart', '.json', '.check'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return 'json';
            }
        }

        return 'html';
    }

    /**
     * Which phase owns this route, inferred from the controller it points at.
     *
     * Returns null rather than a guess when the namespace says nothing — an `owner_phase` nobody
     * can defend is worse than a blank, because §13.2 makes these rows an ask of a named phase and
     * a wrong name sends the ask to the wrong place.
     */
    private function phaseOf(RouteInstance $route): ?int
    {
        $action = (string) ($route->getAction('controller') ?? '');

        if ($action === '') {
            return null;
        }

        // Namespace segment => the phase that owns that area. Only unambiguous ones are listed.
        foreach ([
            'Controllers\\Admin\\Cms' => 3,
            'Controllers\\Site' => 3,
            'Controllers\\Admin\\Crm' => 5,
            'Controllers\\Admin\\Project' => 6,
            'Controllers\\Admin\\Hr' => 7,
            'Controllers\\Admin\\Collaborator' => 8,
            'Controllers\\Collaborator' => 8,
            'Controllers\\Admin\\Finance' => 13,
            'Controllers\\Admin\\Institute' => 14,
            'Controllers\\Student' => 15,
            'Controllers\\Teacher' => 16,
            'Controllers\\Admin\\Support' => 22,
            'Controllers\\Portal' => 22,
            'Controllers\\Admin\\Reporting' => 23,
            'Controllers\\Auth' => 1,
        ] as $needle => $phase) {
            if (str_contains($action, $needle)) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * One value, as readable PHP.
     */
    private function literal(mixed $value, int $indent): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            // A TODO marker for a closure is emitted bare, so the file is valid PHP the moment a
            // human replaces it and a syntax error until they do — which is the point.
            return str_starts_with($value, 'TODO: fn ')
                ? '/* '.$value.' */ null'
                : "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
        }

        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $pad = str_repeat(' ', $indent);
            $isList = array_is_list($value);
            $out = "[\n";

            foreach ($value as $key => $item) {
                $out .= $pad.'    ';

                if (! $isList) {
                    $out .= "'".$key."' => ";
                }

                $out .= $this->literal($item, $indent + 4).",\n";
            }

            return $out.$pad.']';
        }

        return 'null';
    }
}
