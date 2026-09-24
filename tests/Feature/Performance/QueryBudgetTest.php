<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\User;
use App\Support\Ops\QueryBudget;
use App\Support\Ops\QueryProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * PRF-01..PRF-03 — what a screen costs the database (phase-24-25 §6.4, §11.7).
 *
 * **A budget is a number somebody measured.** `screen-manifest.php` starts every row's
 * `query_budget` at null and `php artisan perf:budget --write-baseline` fills it in by running the
 * screen. That is why PRF-01 **skips** an unmeasured row instead of failing it, and why it says out
 * loud how many it skipped: a guessed ceiling passes for ever and hides the regression it was
 * supposed to catch, and a sweep that quietly covers nothing reads exactly like a sweep that found
 * nothing wrong. An unmeasured screen is a gap in the proof, not a defect in the screen, and the two
 * must not look the same in the test output.
 *
 * **PRF-02 is the one that can fail today**, because it needs no baseline. A screen that runs the
 * same statement forty times with forty different ids is an N+1 whatever its total is, and the total
 * is exactly where an N+1 hides: a generous budget usually *is* a measurement of a screen that
 * already had one. {@see QueryBudget::duplicateTolerance()} allows three repeats — a paginator's
 * `count(*)`, a polymorphic load, two panels of the same shape — and a loop shows up well past that.
 *
 * Eloquent strict mode is the other half of the same audit (§6.4): `Model::shouldBeStrict()` is on
 * outside production, so every lazy load in the suite throws. Here it is redirected into a list
 * instead of thrown, so one sweep reports **every** violation across every screen rather than
 * stopping at the first — a hundred-screen audit that reports one finding per run is a hundred runs.
 *
 * The sweep signs in as the seeded Super Admin rather than typing a password: it is measuring
 * screens, not exercising the login form, and a permission-limited actor would measure 403s.
 *
 * **The `perf` group is the suite name §11 publishes** (`php artisan test --group=perf`). A file that
 * does not carry it is a file that command does not run, which fails in the same silent way a
 * contract id nobody named does: the suite reports success over an empty set.
 */
#[Group('perf')]
final class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Route-name suffixes the sweep does not visit.
     *
     * An export streams a file and a print view opens a document. Both are legitimate screens and
     * neither has a query count that means anything, because what they cost depends on how many rows
     * the operator asked for — which is PRF-03's and PRF-10's business, not PRF-01's.
     */
    private const UNMEASURABLE_SUFFIXES = ['.export', '.download', '.print', '.stream', '.pdf', '.csv'];

    /**
     * The route the sweep is currently inside.
     *
     * A property rather than a local, because two independent listeners need to agree on it: the
     * query listener that buckets statements by screen, and the lazy-loading handler that has to name
     * the screen a violation happened on. Passing it between them any other way is a reference
     * threaded through a closure into a method signature, which is harder to read than one field.
     */
    private string $currentScreen = '';

    /*
    |--------------------------------------------------------------------------
    | PRF-01 — the measured budgets
    |--------------------------------------------------------------------------
    */

    /**
     * PRF-01, §11.7.
     *
     * **An unmeasured screen is skipped and counted, never failed and never hidden.** A row whose
     * `query_budget` is null has no ceiling to be under, so failing it would report a gap in the
     * proof as a defect in the screen; passing it silently would report the same gap as coverage.
     * The rows are filtered out here and the number of them is carried in every message this test
     * can print, so a run that compared four screens out of a hundred and sixty says so. The full
     * list, with names, is {@see self::unmeasured_screens_are_counted_rather_than_hidden()}.
     */
    #[Test]
    public function test_query_budgets_per_page(): void
    {
        $rows = $this->measurableRows();
        $measured = array_values(array_filter($rows, static fn (array $row): bool => is_int($row['query_budget'] ?? null)));
        $skipped = count($rows) - count($measured);

        if ($measured === []) {
            $this->markTestSkipped(sprintf(
                'PRF-01 is UNMEASURED, not green: 0 of %d visitable screen-manifest rows carry a '
                .'query_budget. Run "php artisan perf:budget --write-baseline" to record one per screen; '
                .'until then nothing here is being compared against anything.',
                count($rows),
            ));
        }

        $budget = app(QueryBudget::class);
        $profiles = $this->sweep(array_column($measured, 'route'));
        $breaches = [];

        foreach ($profiles as $route => $profile) {
            try {
                $budget->assert($route, $profile);
            } catch (Throwable $exception) {
                $breaches[] = $exception->getMessage();
            }
        }

        $this->assertNotSame(
            [],
            $profiles,
            sprintf(
                'Every measured screen refused to answer, so this test compared nothing (%d screen(s) '
                .'skipped as unmeasured). The fixture or the actor is wrong, not the budgets.',
                $skipped,
            ),
        );

        $this->assertSame(
            [],
            $breaches,
            sprintf(
                "%d of %d measured screen(s) exceeded the budget somebody recorded for them. "
                ."%d further screen(s) were skipped as unmeasured and are not covered by this "
                ."result:\n\n%s",
                count($breaches),
                count($profiles),
                $skipped,
                implode("\n\n", $breaches),
            ),
        );
    }

    /**
     * The gap, stated as a number.
     *
     * Skipping is the right answer for an unmeasured screen and a silent skip is not: PHPUnit prints
     * a skip reason, so the count of screens nobody has measured stays in front of whoever runs the
     * suite instead of being a thing they would have to go and count.
     */
    #[Test]
    public function unmeasured_screens_are_counted_rather_than_hidden(): void
    {
        $rows = $this->measurableRows();

        $this->assertNotSame([], $rows, 'The screen manifest yielded no visitable rows — the sweep has no data provider.');

        $unmeasured = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ! is_int($row['query_budget'] ?? null),
        ));

        if ($unmeasured !== []) {
            $this->markTestSkipped(sprintf(
                '%d of %d visitable screens have no measured query budget (first ten: %s). '
                .'Run "php artisan perf:budget --write-baseline".',
                count($unmeasured),
                count($rows),
                implode(', ', array_slice(array_column($unmeasured, 'route'), 0, 10)),
            ));
        }

        $this->assertCount(count($rows), $rows);
    }

    /*
    |--------------------------------------------------------------------------
    | PRF-02 — no N+1 anywhere
    |--------------------------------------------------------------------------
    */

    /**
     * Strict mode is the N+1 audit, and it only audits anything if it is on.
     */
    #[Test]
    public function eloquent_strict_mode_is_active_in_the_test_environment(): void
    {
        $this->assertTrue(
            Model::preventsLazyLoading(),
            'Model::shouldBeStrict() is off in testing, so every lazy load in the suite passes silently '
            .'and PRF-02 proves nothing. It is set in AppServiceProvider::boot() (phase-24-25 §6.4).',
        );

        $this->assertTrue(
            Model::preventsAccessingMissingAttributes(),
            'preventAccessingMissingAttributes is off: a view reading a column a select() left out '
            .'would render an empty string instead of failing.',
        );
    }

    /**
     * PRF-02, §11.7 — the id's own assertion: no lazy load on any manifest screen, and no statement
     * repeated past {@see QueryBudget::duplicateTolerance()} in one request.
     *
     * {@see self::eloquent_strict_mode_is_active_in_the_test_environment()} is the second half and
     * keeps a descriptive name: it proves the *instrument* is switched on, which is a precondition
     * for this test rather than a clause of PRF-02.
     */
    #[Test]
    public function test_no_n_plus_one_anywhere(): void
    {
        $routes = array_column($this->measurableRows(), 'route');

        /** @var list<string> $lazyLoads */
        $lazyLoads = [];

        // Collected rather than thrown: one sweep must report every violation on every screen. A
        // handler that throws stops at the first, which turns a hundred-screen audit into a hundred
        // runs of a one-finding audit.
        Model::handleLazyLoadingViolationUsing(
            function (Model $model, string $relation) use (&$lazyLoads): void {
                $lazyLoads[] = sprintf('%s  %s::$%s', $this->currentScreen, $model::class, $relation);
            }
        );

        try {
            $profiles = $this->sweep($routes);
        } finally {
            Model::handleLazyLoadingViolationUsing(null);
        }

        $this->assertGreaterThan(
            20,
            count($profiles),
            sprintf(
                'Only %d of %d screen(s) answered. A sweep that visits nothing cannot find an N+1.',
                count($profiles),
                count($routes),
            ),
        );

        $duplicates = [];

        foreach ($profiles as $route => $profile) {
            if ($profile->duplicateCount() >= QueryBudget::duplicateTolerance()) {
                $duplicates[] = sprintf("[%s]\n%s", $route, $profile->describe(3));
            }
        }

        $lazyLoads = array_values(array_unique($lazyLoads));

        $this->assertSame(
            [],
            $lazyLoads,
            sprintf(
                "These screens lazy-load a relation (%d finding(s) over %d screens). Add the relation to "
                ."with() on the controller's query — in production this is one query per row:\n  %s",
                count($lazyLoads),
                count($profiles),
                implode("\n  ", $lazyLoads),
            ),
        );

        $this->assertSame(
            [],
            $duplicates,
            sprintf(
                "These screens run the same statement more than %d times in one request (%d of %d "
                ."visited). That is an N+1 whatever the total says:\n\n%s",
                QueryBudget::duplicateTolerance(),
                count($duplicates),
                count($profiles),
                implode("\n\n", $duplicates),
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRF-03 — wall time
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function test_wall_time_budgets(): void
    {
        $this->markTestSkipped(
            'PRF-03 is not implementable yet. §11.7 measures wall time "on the PerformanceFixtureSeeder '
            .'volume (5,000 students, 20,000 charges, 60,000 receipts, 150,000 ledger rows)" and '
            .'database/seeders/PerformanceFixtureSeeder.php does not exist (§6.7). A p95 taken on the '
            .'demo fixture is a number about a forty-row table, and recording it as a baseline would be '
            .'worse than having none: the first real regression would still fit inside it.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Visit each route and profile what it asked the database.
     *
     * **One listener for the whole sweep**, not one per screen. `DB::listen` has no counterpart that
     * removes a listener, so calling {@see QueryBudget::measure()} once per route would leave a
     * hundred and fifty live closures on the connection, each still being invoked for every statement
     * of every later screen. The statements are bucketed by `$screen` instead and handed to
     * {@see QueryBudget::profile()}, which is the same code `measure()` would have run.
     *
     * A non-200 answer is dropped rather than failed. A screen the fixture cannot reach, a module this
     * installation has switched off and a route that 404s without its parameter are all somebody
     * else's assertion — SEC-20's, ISO-01's, `audit:manifest --check`'s — and failing them here would
     * report an authorization matrix as an N+1.
     *
     * @param  list<string>  $routes
     * @return array<string, QueryProfile>
     */
    private function sweep(array $routes): array
    {
        $admin = $this->superAdmin();
        $budget = app(QueryBudget::class);

        /** @var array<string, list<array{sql: string, ms: float}>> $collected */
        $collected = [];

        DB::listen(function (QueryExecuted $event) use (&$collected): void {
            if ($this->currentScreen !== '') {
                $collected[$this->currentScreen][] = ['sql' => $event->sql, 'ms' => (float) $event->time];
            }
        });

        $profiles = [];

        foreach ($routes as $route) {
            if (! is_string($route) || ! Route::has($route)) {
                continue;
            }

            try {
                $uri = route($route, [], false);
            } catch (Throwable) {
                continue;
            }

            $this->currentScreen = $route;
            $collected[$route] = [];

            try {
                $status = $this->actingAs($admin)->get($uri)->status();
            } catch (Throwable) {
                $status = 500;
            } finally {
                $this->currentScreen = '';
            }

            if ($status === 200) {
                $profiles[$route] = $budget->profile($collected[$route]);
            }

            unset($collected[$route]);
        }

        return $profiles;
    }

    /**
     * Manifest rows the sweep can visit without a fixture.
     *
     * `params` being set means the row needs a real record to point at, and the manifest's closure is
     * the human-written part of that. Skipped rather than guessed, exactly as `perf:budget` skips it:
     * a budget measured against whichever row happened to be first in the table is a budget about
     * that row.
     *
     * @return list<array<string, mixed>>
     */
    private function measurableRows(): array
    {
        $path = base_path('tests/Support/screen-manifest.php');

        $this->assertFileExists($path, 'The screen manifest is the data provider for every sweep in §11.');

        /** @var mixed $rows */
        $rows = require $path;

        $measurable = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! isset($row['route']) || ! is_string($row['route'])) {
                continue;
            }

            $route = $row['route'];

            if (($row['params'] ?? null) !== null) {
                continue;
            }

            if (! str_starts_with($route, 'admin.')) {
                continue;
            }

            if (($row['response'] ?? 'html') !== 'html') {
                continue;
            }

            if ((int) ($row['expect'] ?? 200) !== 200) {
                continue;
            }

            foreach (self::UNMEASURABLE_SUFFIXES as $suffix) {
                if (str_ends_with($route, $suffix)) {
                    continue 2;
                }
            }

            $measurable[] = $row;
        }

        return $measurable;
    }

    /**
     * The seeded Super Admin, with the password-change gate cleared.
     *
     * `must_change_password` is set on the seeded first account on purpose (phase-01 §6.8 step 8) and
     * `EnsureUserIsActive` redirects every request while it stands, which would measure a redirect on
     * every screen. Cleared quietly: this is the harness getting out of its own way, not a change to
     * the account.
     */
    private function superAdmin(): User
    {
        $admin = User::query()
            ->whereHas('roles', static fn ($query) => $query->where('name', User::SUPER_ADMIN_ROLE))
            ->firstOrFail();

        $admin->forceFill(['must_change_password' => false])->saveQuietly();

        return $admin;
    }
}
