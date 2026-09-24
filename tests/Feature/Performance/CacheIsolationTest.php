<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Enums\PanelType;
use App\Models\Collaborator\Collaborator;
use App\Models\User;
use FilesystemIterator;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;
use Throwable;

/**
 * PRF-09 and PRF-13 — what may go into the cache, and whose name has to be on it
 * (phase-24-25 §6.4.1, §11.7; spine INV-13, INV-26).
 *
 * **The cache-key scan is PRF-09, not PRF-14.** The contract says both: §6.4.1's closing paragraph
 * and PRF-09's own row both call this scan "PRF-14", while §11.7's id table gives PRF-14 to
 * `test_slow_queries_are_logged_not_ignored` and gives this scan to PRF-09
 * (`test_cache_keys_never_cross_a_tenant`). **§11.7's id table is the authority**, because it is the
 * table the acceptance suite is built from — one id, one method name, one requirement. Filing this
 * scan under PRF-14 cost the suite an entire row: the slow-query log had no test at all and nobody
 * could see it, because the id it should have carried was already taken. The contradiction is a
 * documentation defect and is recorded as such for the main session; the code follows §11.7.
 *
 * Two rules, and they fail in opposite directions.
 *
 * **PRF-09: a cache key whose value depends on who is asking must contain who was asking.** The
 * failure is silent and total. A sidebar cached under `sidebar.v3` instead of
 * `sidebar.v3.user.{id}.{hash}` serves the first visitor's menu to everyone for an hour — including
 * the modules they may not see. The same key shape over a model with a global scope is worse still:
 * `LeadVisibilityScope` narrows the pipeline to the leads a user owns *inside the query*, so the
 * first caller's own rows are what gets stored, and every later caller reads someone else's
 * pipeline out of the cache with no query to look at and nothing in any log to notice. So the scan
 * pairs the two halves — a closure that touches a globally scoped model, and a key with no owner in
 * it — because either alone is fine and the combination is a tenancy breach.
 *
 * **PRF-13: no money figure is cached at all.** Not per user, not for sixty seconds, not "it is only
 * the total". A balance is the one number in this system that must be re-derivable from the ledger
 * on demand (spine INV-13, INV-26): the wallet row is already a cache, proven every night by
 * `collaborators:reconcile-wallets`, and a second copy in the cache store is a figure no
 * reconciliation looks at, that survives the reversal that corrected it, and that a collaborator can
 * screenshot. `CollaboratorWalletService` reads the wallet row and `derive()` hits the ledger; there
 * is no third path and this test is what keeps it that way.
 *
 * Static scan **and** a runtime pass, because neither is sufficient: source can only see the cache
 * calls written literally in `app/`, and a runtime sweep can only see the keys the screens it
 * visited happened to write. PRF-09 gets the same treatment: the scan proves no key *shape* can
 * cross a tenant, and {@see self::a_second_collaborator_is_never_served_the_first_ones_screens()}
 * proves two real partners' screens do not, which is the failure the scan exists to prevent.
 *
 * The class carries `#[Group('perf')]` because `php artisan test --group=perf` (§11) is otherwise a
 * command that runs nothing and reports success.
 */
#[Group('perf')]
final class CacheIsolationTest extends TestCase
{
    use BuildsFinancialFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * The collaborator-portal abilities the functional half signs in with.
     *
     * The same list `CollaboratorPanelTest` uses, so the two tests are looking at the same panel.
     * B is given this list **minus `collaborator_portal.payouts`**: the sidebar clause needs one
     * link A has and B does not, and a difference in permissions is the only difference a shared
     * cache entry can erase.
     *
     * @var list<string>
     */
    private const PORTAL = [
        'collaborator_portal.dashboard',
        'collaborator_portal.wallet',
        'collaborator_portal.payouts',
        'collaborator_portal.payout_request',
        'collaborator_portal.statement_download',
        'collaborator_portal.student_commission',
        'collaborator_portal.projects',
    ];

    /**
     * Subjects whose figures may never be cached (§6.4.1 "Money and balances — never cached").
     *
     * Matched against a cache key and against the source of a cache call. Deliberately the *nouns of
     * the money system* rather than a number pattern: "0.00" appears in a settings payload, which is
     * a policy parameter somebody typed, not a figure the ledger produced.
     */
    private const MONEY_SUBJECTS = '/wallet|commission|ledger|payout|statement|balance|\bfees?\b|receipt|invoice|CollaboratorPayout|StudentFee|ProjectPayment|Money::/i';

    protected function setUp(): void
    {
        parent::setUp();

        // The referral system has to be on before `partner()` can put a commission rule in force —
        // without it the fixture builds a partner with no rule, which is a different fixture.
        $this->setting('collaborator.referral_system_enabled', true);
        $this->setting('collaborator.automatic_commission_enabled', true);
        $this->setting('collaborator.commission_approval_mode', 'automatic');
    }

    /**
     * What counts as an owner component in a cache key.
     *
     * A literal `user`/`collaborator`/`student` segment, or an interpolated id — `{$user->id}`,
     * `$user->getKey()`, `auth()->id()`. A key built from a *term* or a *range* alone is not owned,
     * however unique it looks.
     */
    private const OWNER_COMPONENT = '/\buser\b|\bcollaborator\b|\bstudent\b|\bclient\b|\bteacher\b|\bemployee\b|auth\(\)\s*->\s*id|->\s*getKey\(\)|->\s*id\b|getAuthIdentifier/i';

    /*
    |--------------------------------------------------------------------------
    | The scan catches what it claims to
    |--------------------------------------------------------------------------
    */

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function tenancySnippetProvider(): iterable
    {
        $service = static fn (string $body): string => <<<PHP
        <?php
        namespace App\\Services\\Sample;
        use App\\Models\\Crm\\Lead;
        use Illuminate\\Support\\Facades\\Cache;
        class SampleService {
            public function read(\$user) {
                {$body}
            }
        }
        PHP;

        yield 'a scoped model behind a shared key' => [
            $service("return Cache::remember('leads.board', 60, fn () => Lead::query()->get());"),
            true,
        ];

        yield 'a scoped model behind a shared key, for ever' => [
            $service("return Cache::rememberForever('leads.counts', fn () => Lead::query()->count());"),
            true,
        ];

        yield 'the same closure with the owner in the key' => [
            $service("return Cache::remember('leads.board.'.\$user->getKey(), 60, fn () => Lead::query()->get());"),
            false,
        ];

        yield 'an unscoped model may share a key' => [
            $service("return Cache::remember('modules.enabled.map', 60, fn () => \\App\\Models\\Module::query()->get());"),
            false,
        ];
    }

    #[Test]
    #[DataProvider('tenancySnippetProvider')]
    public function the_tenancy_scan_reports_a_shared_key_over_a_scoped_model(string $source, bool $expected): void
    {
        $findings = $this->tenancyFindings('SampleService.php', $source, ['Lead']);

        $this->assertSame(
            $expected,
            $findings !== [],
            $expected
                ? 'This cache key is shared across tenants over a globally scoped model and the scan said nothing.'
                : 'The scan reported a key that is fine: '.implode(' | ', $findings),
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function moneySnippetProvider(): iterable
    {
        $service = static fn (string $body): string => <<<PHP
        <?php
        namespace App\\Services\\Sample;
        use Illuminate\\Support\\Facades\\Cache;
        class SampleService {
            public function read(\$collaborator) {
                {$body}
            }
        }
        PHP;

        yield 'a wallet balance in the cache' => [
            $service("return Cache::remember('wallet.'.\$collaborator->id, 60, fn () => \$this->wallet->available(\$collaborator));"),
            true,
        ];

        yield 'a commission total in the cache' => [
            $service("Cache::put('commission.total', \$ledger->sum('commission_amount'), 60);"),
            true,
        ];

        yield 'a statement for ever' => [
            $service("Cache::forever('statement.'.\$collaborator->id, \$statement);"),
            true,
        ];

        yield 'a module map is not money' => [
            $service("return Cache::rememberForever('modules.enabled.map', fn () => \$this->map());"),
            false,
        ];
    }

    #[Test]
    #[DataProvider('moneySnippetProvider')]
    public function the_money_scan_reports_a_cached_figure(string $source, bool $expected): void
    {
        $findings = $this->moneyFindings('SampleService.php', $source);

        $this->assertSame(
            $expected,
            $findings !== [],
            $expected
                ? 'A money figure was written to the cache and the scan said nothing.'
                : 'The scan reported a cache write that carries no money: '.implode(' | ', $findings),
        );
    }

    /**
     * Guards the guard, twice over: the file walk has to find files, and the model walk has to find
     * the one model that carries a global scope. If `LeadVisibilityScope` were ever removed this
     * assertion is what says so, rather than PRF-09 quietly having nothing left to check.
     */
    #[Test]
    public function the_scan_actually_reads_the_application(): void
    {
        $calls = 0;

        foreach ($this->phpFiles(app_path()) as $path) {
            $calls += count($this->cacheCalls((string) file_get_contents($path)));
        }

        $this->assertGreaterThan(
            5,
            $calls,
            'The cache scan found almost no Cache:: calls in app/ — the matcher is broken and PRF-09/13 '
            .'are asserting over an empty list.',
        );

        $scoped = $this->globallyScopedModels();

        $this->assertContains(
            'Lead',
            $scoped,
            'No globally scoped model was found. App\Models\Crm\Lead carries LeadVisibilityScope (D30); '
            .'if that is gone, PRF-09 has nothing to pair a cache key against.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRF-09 — a cache key never crosses a tenant
    |--------------------------------------------------------------------------
    */

    /**
     * PRF-09's first half, and the one that can fail on a line nobody ran: the **shape** of every
     * `Cache::remember` in `app/`.
     *
     * It carries the contract's name because it is the clause §11.7 states first and the one that
     * catches the defect before it ships. The functional half below keeps a descriptive name.
     */
    #[Test]
    public function test_cache_keys_never_cross_a_tenant(): void
    {
        $scoped = $this->globallyScopedModels();
        $findings = [];

        foreach ($this->phpFiles(app_path()) as $path) {
            $relative = $this->relative($path);

            foreach ($this->tenancyFindings($relative, (string) file_get_contents($path), $scoped) as $finding) {
                $findings[] = $finding;
            }
        }

        sort($findings);

        $this->assertSame(
            [],
            $findings,
            "These cached values are computed from a model carrying a global scope, under a key that does "
            ."not say whose scope produced them. The first caller's rows are what everyone else reads "
            ."(§6.4.1). Put the owner in the key — sidebar.v{n}.user.{id}, dash.{widget}.{user_id} — or "
            ."do not cache it:\n  ".implode("\n  ", $findings)
        );
    }

    /**
     * PRF-09's functional half: two real partners, A primed first, and **none of A's values in any
     * of B's responses**.
     *
     * The scan above reasons about key shapes in source. This reasons about what a second person
     * actually receives, and it is the half that would still catch the leak if the shared key were
     * written by a package, a view composer or a `Cache` call the matcher cannot see. **A is always
     * requested first**, because a shared entry is written by whoever asks first and read by
     * everyone after: reversing the order would hide exactly the defect this is for.
     *
     * Three surfaces, one per §6.4.1 row that is per-viewer:
     *
     *  - **The dashboard** (`dash.{widget}.{user_id}.{range_hash}`) — B's screen must not carry A's
     *    partner code, referral code or name.
     *  - **The sidebar** (`sidebar.v{n}.user.{id}.{permission_hash}`) — A holds
     *    `collaborator_portal.payouts` and B does not, so B's menu must not contain the Payouts
     *    link. A shared sidebar entry does not merely look wrong; it advertises a module B was not
     *    granted, which is the leak §6.4.1 calls out by name.
     *  - **Search suggestions** (`search.suggest.{user_id}.{sha1(term)}`) — one term, two viewers,
     *    and `CollaboratorSearchProvider` narrows a viewer without `collaborators.view_any` to
     *    their own row. The two admin-panel seats are deliberate rather than sloppy: the palette is
     *    behind `panel:admin`, so **there is no collaborator-panel search surface to prime** and
     *    the clause is asserted where the endpoint actually lives.
     */
    #[Test]
    public function a_second_collaborator_is_never_served_the_first_ones_screens(): void
    {
        [$partnerA, $userA] = $this->partnerWithLogin(self::PORTAL);
        [$partnerB, $userB] = $this->partnerWithLogin(array_values(array_diff(self::PORTAL, ['collaborator_portal.payouts'])));

        $this->assertNotSame((string) $partnerA->collaborator_code, (string) $partnerB->collaborator_code);

        // Prime A. The response is asserted to carry the link B must not get, so that "B saw nothing
        // of A's" cannot be satisfied by A having rendered nothing in the first place.
        $payoutsLink = route('collaborator.payouts.index', [], false);

        $primed = $this->actingAs($userA)->get(route('collaborator.dashboard', [], false));
        $primed->assertOk();
        $primed->assertSee($payoutsLink, escape: false);

        $second = $this->actingAs($userB)->get(route('collaborator.dashboard', [], false));
        $second->assertOk();

        foreach ([$partnerA->collaborator_code, $partnerA->referral_code, $partnerA->name] as $value) {
            $second->assertDontSee((string) $value, escape: false);
        }

        $this->assertStringNotContainsString(
            $payoutsLink,
            $second->getContent() === false ? '' : (string) $second->getContent(),
            'The second partner was served a sidebar carrying a module they were never granted. A '
            .'sidebar cached without the viewer id in the key hands the first visitor’s menu to '
            .'everyone for an hour (§6.4.1).',
        );

        $this->assertSearchSuggestionsAreNotShared($partnerA, $partnerB);
    }

    /**
     * The search-suggestion clause of PRF-09, on the panel the endpoint lives on.
     *
     * The wide viewer runs the term first so their answer is the one a shared key would hold; the
     * narrow viewer then runs the identical term and must get their own row back and nothing else.
     */
    private function assertSearchSuggestionsAreNotShared(Collaborator $partnerA, Collaborator $partnerB): void
    {
        $wide = $this->createUserWithPermissions(
            ['global_search.view_any', 'collaborators.view_any', 'collaborators.view'],
            PanelType::Admin,
        );

        $narrow = $this->createUserWithPermissions(
            ['global_search.view_any', 'collaborators.view'],
            PanelType::Admin,
        );

        DB::table('collaborators')->where('id', $partnerB->getKey())->update(['user_id' => $narrow->getKey()]);

        $term = 'Acceptance Partner';
        $uri = route('admin.search.suggest', ['q' => $term], false);

        $first = $this->actingAs($wide)->getJson($uri);

        // Priming is harness work, not the assertion. If the wide viewer never gets A's row back
        // there is nothing for a shared key to hand on, and reporting that as a tenancy failure
        // would blame the isolation rule for a search index that answered nothing. Said out loud
        // rather than passed quietly: a clause that exercised nothing must not read as one that
        // held.
        if ($first->status() !== 200 || ! str_contains((string) $first->getContent(), (string) $partnerA->collaborator_code)) {
            $this->markTestSkipped(sprintf(
                'admin.search.suggest answered %d and did not return %s to a viewer holding '
                .'global_search.view_any + collaborators.view_any, so the search-suggestions clause '
                .'of PRF-09 had nothing to prime with. The dashboard and sidebar clauses above ran '
                .'and passed.',
                $first->status(),
                (string) $partnerA->collaborator_code,
            ));
        }

        $second = $this->actingAs($narrow)->getJson($uri);
        $second->assertOk();

        $this->assertStringNotContainsString(
            (string) $partnerA->collaborator_code,
            (string) $second->getContent(),
            'A narrower viewer was handed the wider viewer’s suggestions for the same term. Two people '
            .'searching one word run differently scoped queries, and one entry for both gives the '
            .'narrower one the wider answer (phase-19-23 §6.23, §6.4.1).',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRF-13 — no money figure is cached
    |--------------------------------------------------------------------------
    */

    /**
     * PRF-13's own name goes on the static scan: it is the half that fails on a line of source the
     * moment somebody writes it, without needing a screen to have been rendered. The runtime half
     * below keeps a descriptive name and covers what the scan cannot see.
     */
    #[Test]
    public function test_no_money_figure_is_cached(): void
    {
        $findings = [];

        foreach ($this->phpFiles(app_path()) as $path) {
            $relative = $this->relative($path);

            foreach ($this->moneyFindings($relative, (string) file_get_contents($path)) as $finding) {
                $findings[] = $finding;
            }
        }

        sort($findings);

        $this->assertSame(
            [],
            $findings,
            "These cache writes carry a figure from the money system. A balance must always be "
            ."re-derivable by summing the ledger (spine INV-13, INV-26) and a cached copy is not: it "
            ."survives the reversal that corrected it and no reconciliation ever looks at it. The wallet "
            ."row is the only cache money gets:\n  ".implode("\n  ", $findings)
        );
    }

    /**
     * The runtime half: what the money screens actually wrote while they rendered.
     *
     * The static scan sees the calls written in `app/`. This sees the ones a package, a view composer
     * or a listener made on the way past — and it is the only half that would catch a figure cached
     * by something this project did not write.
     */
    #[Test]
    public function exercising_the_money_screens_writes_no_money_into_the_cache(): void
    {
        $routes = $this->moneyScreenRoutes();

        if ($routes === []) {
            $this->markTestSkipped(
                'No param-free money screen in tests/Support/screen-manifest.php answered to a route '
                .'name matching wallet/statement/commission/payout/fee, so the runtime half of PRF-13 '
                .'exercised nothing. The static half above still ran.'
            );
        }

        $admin = $this->superAdmin();

        /** @var list<string> $written */
        $written = [];

        Event::listen(KeyWritten::class, static function (KeyWritten $event) use (&$written): void {
            $written[] = $event->key;
        });

        $visited = 0;

        foreach ($routes as $route) {
            try {
                $uri = route($route, [], false);
            } catch (Throwable) {
                continue;
            }

            if ($this->actingAs($admin)->get($uri)->status() === 200) {
                $visited++;
            }
        }

        $this->assertGreaterThan(
            0,
            $visited,
            sprintf('None of the %d money screens answered, so nothing was exercised.', count($routes)),
        );

        $offending = array_values(array_unique(array_filter(
            $written,
            fn (string $key): bool => preg_match(self::MONEY_SUBJECTS, $key) === 1,
        )));

        sort($offending);

        $this->assertSame(
            [],
            $offending,
            sprintf(
                "Rendering %d money screen(s) put these keys in the cache store. A commission, balance, "
                ."statement, payout or fee figure is never cached (§6.4.1):\n  %s",
                $visited,
                implode("\n  ", $offending),
            ),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The scanners
    |--------------------------------------------------------------------------
    */

    /**
     * Cache calls in one file whose closure touches a globally scoped model under an unowned key.
     *
     * @param  list<string>  $scoped  short class names carrying a global scope
     * @return list<string>
     */
    private function tenancyFindings(string $relative, string $source, array $scoped): array
    {
        if ($scoped === []) {
            return [];
        }

        $pattern = '/\b('.implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $scoped)).')\b/';
        $findings = [];

        foreach ($this->cacheCalls($source) as $call) {
            // Only the remembering calls: put() and forever() are handed a value that was computed
            // somewhere else, so the scope that produced it is not visible here and guessing would
            // report a key that is fine.
            if (! in_array($call['method'], ['remember', 'rememberForever'], true)) {
                continue;
            }

            if (preg_match($pattern, $call['arguments'], $touched) !== 1) {
                continue;
            }

            if (preg_match(self::OWNER_COMPONENT, $call['key']) === 1) {
                continue;
            }

            $findings[] = sprintf(
                '%s:%d  Cache::%s(%s)  — the closure reads %s, which carries a global scope',
                $relative,
                $call['line'],
                $call['method'],
                mb_substr($call['key'], 0, 70),
                $touched[1],
            );
        }

        return $findings;
    }

    /**
     * Cache writes in one file that carry a figure out of the money system.
     *
     * @return list<string>
     */
    private function moneyFindings(string $relative, string $source): array
    {
        $findings = [];

        foreach ($this->cacheCalls($source) as $call) {
            if (preg_match(self::MONEY_SUBJECTS, $call['arguments']) !== 1) {
                continue;
            }

            $findings[] = sprintf(
                '%s:%d  Cache::%s(%s)',
                $relative,
                $call['line'],
                $call['method'],
                mb_substr((string) preg_replace('/\s+/', ' ', $call['arguments']), 0, 100),
            );
        }

        return $findings;
    }

    /**
     * Every `Cache::remember|rememberForever|put|forever` call in a file, with its arguments.
     *
     * The arguments are read by counting brackets rather than by a regex, because the interesting
     * ones are closures full of brackets and a regex stops at the first `)` it meets — which for a
     * `remember()` is somewhere inside the key expression.
     *
     * @return list<array{method: string, key: string, arguments: string, line: int}>
     */
    private function cacheCalls(string $source): array
    {
        $pattern = '/(?:Cache|\$this->cache|\$cache)\s*(?:::|->)\s*(remember|rememberForever|put|forever)\s*\(/';

        if (! preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $calls = [];

        foreach ($matches as $match) {
            $open = (int) $match[0][1] + strlen($match[0][0]) - 1;
            $arguments = $this->balanced($source, $open);

            if ($arguments === null) {
                continue;
            }

            $calls[] = [
                'method' => $match[1][0],
                'key' => $this->firstArgument($arguments),
                'arguments' => $arguments,
                'line' => substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1,
                'model' => null,
            ];
        }

        return $calls;
    }

    /**
     * The text between a `(` and its matching `)`.
     */
    private function balanced(string $source, int $open): ?string
    {
        $depth = 0;
        $length = strlen($source);

        for ($position = $open; $position < $length; $position++) {
            $character = $source[$position];

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $open + 1, $position - $open - 1);
                }
            }
        }

        return null;
    }

    /**
     * The key expression: everything up to the first top-level comma.
     */
    private function firstArgument(string $arguments): string
    {
        $depth = 0;
        $length = strlen($arguments);

        for ($position = 0; $position < $length; $position++) {
            $character = $arguments[$position];

            if ($character === '(' || $character === '[') {
                $depth++;
            } elseif ($character === ')' || $character === ']') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                return trim(substr($arguments, 0, $position));
            }
        }

        return trim($arguments);
    }

    /**
     * Short class names of every model carrying a global scope.
     *
     * Both spellings: the `#[ScopedBy]` attribute and a `addGlobalScope()` call in `booted()`.
     *
     * @return list<string>
     */
    private function globallyScopedModels(): array
    {
        $models = [];

        foreach ($this->phpFiles(app_path('Models')) as $path) {
            $source = (string) file_get_contents($path);

            if (! str_contains($source, 'ScopedBy') && ! str_contains($source, 'addGlobalScope')) {
                continue;
            }

            if (preg_match('/^\s*(?:final\s+)?(?:abstract\s+)?class\s+([A-Za-z0-9_]+)/m', $source, $match) === 1) {
                $models[] = $match[1];
            }
        }

        sort($models);

        return array_values(array_unique($models));
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Param-free money screens from the screen manifest.
     *
     * @return list<string>
     */
    private function moneyScreenRoutes(): array
    {
        $path = base_path('tests/Support/screen-manifest.php');

        if (! is_file($path)) {
            return [];
        }

        /** @var mixed $rows */
        $rows = require $path;

        $routes = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! isset($row['route']) || ! is_string($row['route'])) {
                continue;
            }

            if (($row['params'] ?? null) !== null || ($row['response'] ?? 'html') !== 'html') {
                continue;
            }

            if (! str_starts_with($row['route'], 'admin.') || ! Route::has($row['route'])) {
                continue;
            }

            if (preg_match('/wallet|statement|commission|payout|fee/i', $row['route']) === 1) {
                $routes[] = $row['route'];
            }
        }

        return array_values(array_unique($routes));
    }

    /**
     * A partner bound to a login holding exactly the given portal abilities.
     *
     * No charge and no receipt: this test is about whose *screen* is served, and a ledger row would
     * add a backdating window and four commission settings to a test that asks nothing of them.
     * The binding is written with the query builder because `collaborators.user_id` is deliberately
     * not mass assignable (D2) — a fixture that could set it through the model would be proving the
     * guard is missing.
     *
     * @param  list<string>  $permissions
     * @return array{0: Collaborator, 1: User}
     */
    private function partnerWithLogin(array $permissions): array
    {
        $partner = $this->partner();
        $user = $this->createUserWithPermissions($permissions, PanelType::Collaborator);

        DB::table('collaborators')->where('id', $partner->getKey())->update(['user_id' => $user->getKey()]);

        return [$partner->refresh(), $user];
    }

    private function superAdmin(): User
    {
        $admin = User::query()
            ->whereHas('roles', static fn ($query) => $query->where('name', User::SUPER_ADMIN_ROLE))
            ->firstOrFail();

        $admin->forceFill(['must_change_password' => false])->saveQuietly();

        return $admin;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
    }
}
