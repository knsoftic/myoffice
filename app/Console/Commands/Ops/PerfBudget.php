<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Models\User;
use App\Support\Ops\QueryBudget;
use App\Support\Ops\QueryProfile;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * `perf:budget` — measure what each screen asks the database (phase-24-25 §6.4, §6.6).
 *
 * **`--write-baseline` is how a budget gets into the manifest, and it is the only way.** A guessed
 * budget is worse than no budget: it passes, so nobody looks at it again, and the number it passes
 * against was never true of anything. So the manifest's `query_budget` starts null, this command
 * measures it, and from then on a regression is a screen that exceeded a number somebody's code
 * actually produced.
 *
 * **A duplicate query is a failure whatever the total says.** A screen with a generous budget can
 * hide an N+1 inside it — and a generous budget is usually what you get when somebody measures a
 * screen that already had one. `QueryBudget::assert()` fails on repetition independently.
 *
 * **It runs through the HTTP kernel, signed in as a seeded Super Admin.** Not by typing a password:
 * the session is opened with `Auth::login()` inside a transaction that is rolled back, so measuring
 * a screen never writes anything and never handles a credential.
 */
final class PerfBudget extends Command
{
    protected $signature = 'perf:budget
                            {--route= : One route name}
                            {--all : Every measurable screen in the manifest}
                            {--write-baseline : Record the measured counts into the screen manifest}
                            {--json : Machine-readable output}';

    protected $description = 'Measure each screen\'s query count against its manifest budget.';

    public function handle(QueryBudget $budget): int
    {
        $rows = $this->manifestRows();

        if ($rows === []) {
            $this->error('tests/Support/screen-manifest.php has no rows to measure.');

            return 2;
        }

        $route = $this->option('route');

        if ($route !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => ($row['route'] ?? null) === $route,
            ));

            if ($rows === []) {
                $this->error(sprintf('No manifest row for route [%s].', $route));

                return 2;
            }
        } elseif (! $this->option('all') && ! $this->option('write-baseline')) {
            $this->error('Pass --route=<name>, --all, or --write-baseline.');

            return 2;
        }

        $admin = $this->superAdmin();

        if ($admin === null) {
            $this->error('No seeded Super Admin to measure with.');

            return 2;
        }

        $results = $this->measureAll($budget, $rows, $admin);

        if ($this->option('write-baseline')) {
            return $this->writeBaseline($results);
        }

        return $this->report($results);
    }

    /*
    |--------------------------------------------------------------------------
    | Measuring
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function measureAll(QueryBudget $budget, array $rows, User $admin): array
    {
        $results = [];

        // One transaction around the whole sweep, rolled back at the end: a GET should not write,
        // and "should not" is not a guarantee worth measuring two hundred screens on.
        DB::beginTransaction();

        try {
            $admin->forceFill(['must_change_password' => false])->saveQuietly();
            Auth::login($admin);

            $kernel = app(HttpKernel::class);

            $bar = $this->option('json') ? null : $this->output->createProgressBar(count($rows));
            $bar?->start();

            foreach ($rows as $row) {
                $results[] = $this->measure($budget, $kernel, $admin, $row);
                $bar?->advance();
            }

            $bar?->finish();
            $this->newLine(2);

            Auth::logout();
        } finally {
            DB::rollBack();
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function measure(QueryBudget $budget, HttpKernel $kernel, User $admin, array $row): array
    {
        $name = (string) ($row['route'] ?? '');

        // A route with parameters needs a fixture to point at, and the manifest's `params` closure
        // is the human-written part of that. Skipped rather than guessed: a budget measured against
        // a record that happened to be first in the table is a budget about that record.
        if (($row['params'] ?? null) !== null || ! $this->isMeasurable($name)) {
            return ['route' => $name, 'skipped' => 'needs a fixture'];
        }

        try {
            $uri = route($name, [], false);
        } catch (Throwable) {
            return ['route' => $name, 'skipped' => 'no URL'];
        }

        try {
            [$response, $profile] = $budget->measure(static function () use ($kernel, $uri, $admin) {
                $request = Request::create($uri, 'GET');
                $request->setUserResolver(static fn () => $admin);

                return $kernel->handle($request);
            });
        } catch (Throwable $exception) {
            return ['route' => $name, 'skipped' => get_class($exception)];
        }

        $status = $response->getStatusCode();

        if ($status >= 400) {
            return ['route' => $name, 'skipped' => 'answered '.$status];
        }

        /** @var QueryProfile $profile */
        $breach = null;

        try {
            $budget->assert($name, $profile);
        } catch (Throwable $exception) {
            $breach = $exception->getMessage();
        }

        return [
            'route' => $name,
            'budget' => $budget->for($name),
            'count' => $profile->count,
            'duration_ms' => round($profile->durationMs, 1),
            'duplicates' => $profile->duplicateCount(),
            'breach' => $breach,
        ];
    }

    /**
     * Whether a route is one this command can visit.
     *
     * An export streams a file and a print view opens a document; both are legitimate screens and
     * neither has a query budget that means anything, because what they cost depends on how many
     * rows the operator asked for.
     */
    private function isMeasurable(string $name): bool
    {
        foreach (['.export', '.download', '.print', '.stream', '.pdf'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return false;
            }
        }

        return str_starts_with($name, 'admin.');
    }

    /*
    |--------------------------------------------------------------------------
    | Reporting and writing
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function report(array $results): int
    {
        $measured = array_values(array_filter($results, static fn (array $r): bool => ! isset($r['skipped'])));
        $breaches = array_values(array_filter($measured, static fn (array $r): bool => $r['breach'] !== null));
        $unbudgeted = array_values(array_filter($measured, static fn (array $r): bool => $r['budget'] === null));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'measured' => count($measured),
                'breaches' => $breaches,
                'unbudgeted' => count($unbudgeted),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $breaches === [] ? 0 : 2;
        }

        // The ten most expensive, whether or not they breached — the list somebody actually wants.
        usort($measured, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $this->table(
            ['route', 'queries', 'budget', 'duplicates', 'ms'],
            array_map(static fn (array $r): array => [
                mb_substr((string) $r['route'], 0, 44),
                (string) $r['count'],
                $r['budget'] === null ? '<fg=yellow>unmeasured</>' : (string) $r['budget'],
                $r['duplicates'] > 0 ? '<fg=red>'.$r['duplicates'].'</>' : '0',
                (string) $r['duration_ms'],
            ], array_slice($measured, 0, 10)),
        );

        foreach ($breaches as $breach) {
            $this->newLine();
            $this->error($breach['breach']);
        }

        $this->newLine();
        $this->line(sprintf(
            '  %d screen(s) measured, %d skipped, %d without a budget.',
            count($measured),
            count($results) - count($measured),
            count($unbudgeted),
        ));

        if ($breaches === []) {
            $this->info('perf:budget — no screen exceeded its budget and none repeats itself.');

            return 0;
        }

        $this->error(sprintf('perf:budget — %d screen(s) over budget.', count($breaches)));

        return 2;
    }

    /**
     * Write the measured counts into the manifest as budgets.
     *
     * **With headroom, and the headroom is the point.** A budget set to exactly what one run
     * measured fails the next time a seeded row count changes, and a check that fails for reasons
     * nobody caused is a check people disable. The margin is the larger of 25 % and four queries,
     * which is wide enough to absorb a fixture and narrow enough to catch an N+1.
     *
     * @param  list<array<string, mixed>>  $results
     */
    private function writeBaseline(array $results): int
    {
        $path = base_path('tests/Support/screen-manifest.php');
        $source = (string) File::get($path);

        $written = 0;

        foreach ($results as $result) {
            if (isset($result['skipped']) || $result['budget'] !== null) {
                continue;
            }

            $budget = max(
                (int) ceil($result['count'] * 1.25),
                $result['count'] + 4,
            );

            $pattern = sprintf(
                "/('route' => '%s',(?:(?!'route' =>).)*?)'query_budget' => null,/s",
                preg_quote((string) $result['route'], '/'),
            );

            $replaced = preg_replace(
                $pattern,
                sprintf('$1\'query_budget\' => %d,', $budget),
                $source,
                1,
                $count,
            );

            if ($replaced !== null && $count === 1) {
                $source = $replaced;
                $written++;
            }
        }

        if ($written === 0) {
            $this->info('perf:budget — every measurable screen already has a budget.');

            return 0;
        }

        File::put($path, $source);

        $this->info(sprintf(
            'perf:budget — wrote %d measured budget(s) into the screen manifest, each with headroom.',
            $written,
        ));

        return 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<array<string, mixed>>
     */
    private function manifestRows(): array
    {
        $path = base_path('tests/Support/screen-manifest.php');

        if (! is_file($path)) {
            return [];
        }

        /** @var mixed $rows */
        $rows = require $path;

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function superAdmin(): ?User
    {
        try {
            return User::query()
                ->whereHas('roles', static fn ($query) => $query->where('name', User::SUPER_ADMIN_ROLE))
                ->first();
        } catch (Throwable) {
            return null;
        }
    }
}
