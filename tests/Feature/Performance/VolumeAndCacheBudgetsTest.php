<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PRF-06, PRF-07, PRF-08 and PRF-10 — the four §11.7 ids that had no method anywhere
 * (phase-24-25 §11.7, §6.7).
 *
 * **A contract id that no method carries is a requirement nothing asserts, and it fails silently
 * from both ends**: the contract reads covered because the row is written down, and the suite reads
 * green because there is nothing to go red. Four of §11.7's fourteen ids were in exactly that state
 * — no method, no skip, no mention — which is why this file exists even though every method in it
 * skips. A skip prints its reason on every run; an absence prints nothing, ever.
 *
 * Three of the four are blocked on the same missing thing. §11.7's preamble measures every budget
 * "on the `PerformanceFixtureSeeder` volume (5,000 students, 20,000 charges, 60,000 receipts,
 * 150,000 ledger rows, 2,000 projects, 500 collaborators)" and
 * `database/seeders/PerformanceFixtureSeeder.php` (§6.7) has never been written. **Measuring them on
 * the demo fixture would be worse than not measuring them**: an `EXPLAIN` over a forty-row table
 * reports a full scan as the optimiser's correct choice, a chunked sweep over forty rows finishes in
 * one chunk and proves nothing about chunking, and a p95 recorded there would be a ceiling the first
 * real regression still fits under. Each skip below therefore names the seeder rather than quietly
 * relaxing the volume.
 *
 * PRF-08 is not blocked on anything and says so.
 */
#[Group('perf')]
final class VolumeAndCacheBudgetsTest extends TestCase
{
    #[Test]
    public function test_explain_plans_use_indexes(): void
    {
        $this->markTestSkipped(
            'PRF-06 needs database/seeders/PerformanceFixtureSeeder.php (§6.7), which does not exist. '
            .'The assertion is "type != ALL and key IS NOT NULL on every table over 1,000 rows, and '
            .'rows examined under 5% of the table" for the 15 heaviest queries §11.7 lists. On the '
            .'demo fixture no table reaches 1,000 rows, so every one of the fifteen would be excluded '
            .'by the test\'s own precondition and the result would be a green row over an empty set — '
            .'which is the exact failure mode this id exists to catch. Write the seeder first.'
        );
    }

    #[Test]
    public function test_scheduled_jobs_stay_bounded(): void
    {
        $this->markTestSkipped(
            'PRF-07 needs database/seeders/PerformanceFixtureSeeder.php (§6.7). It asserts that '
            .'commissions:sweep (500 rows), fees:mark-overdue, fees:installment-reminders, '
            .'collaborators:reconcile-wallets (chunk 200) and backup:prune each stay inside their time '
            .'budget, chunked, with a bounded query count per chunk, and are idempotent when run twice '
            .'in the same minute. On the demo fixture every one of them finishes in a single chunk, so '
            .'the chunking — the only thing that keeps them bounded at volume — would never be '
            .'exercised. The idempotence half is testable today and belongs with the commands\' own '
            .'suites, not here.'
        );
    }

    /**
     * PRF-08 — **not blocked, just not written.** Said plainly so it is not mistaken for the three
     * above.
     */
    #[Test]
    public function test_caches_are_used_and_invalidated(): void
    {
        $this->markTestSkipped(
            'PRF-08 is implementable against the current code and was not written in this round; it is '
            .'debt, not a blocker. Everything it needs is in place: the settings payload is '
            .'Cache::forever under SettingsRepository::CACHE_KEY and forgotten by Setting::saved, the '
            .'module map is Cache::rememberForever under Modules::MAP_KEY (plus ALL_KEY and '
            .'PERMISSION_MAP_KEY) and flushed by Module::saved, and PublicCache::bump() versions the '
            .'public pages. The shape: prime, assert the key is present, assert a second read runs zero '
            .'queries, write the underlying row, assert the key is gone. The sidebar clause needs '
            .'care — App\\Support\\Sidebar performs no Cache:: call at all today, so "a stale sidebar '
            .'never survives a role change" is currently true by construction and must be asserted '
            .'functionally (grant a permission, assert the next Sidebar::forUser carries the item) '
            .'rather than against a cache entry that does not exist. tests/Feature/Modules/'
            .'SidebarVisibilityTest.php already covers the module half of that.'
        );
    }

    #[Test]
    public function test_reconciliation_and_statement_scale(): void
    {
        $this->markTestSkipped(
            'PRF-10 needs database/seeders/PerformanceFixtureSeeder.php (§6.7). It asserts '
            .'collaborators:reconcile-wallets over 500 collaborators and 150,000 ledger rows finishing '
            .'under 120 s with a bounded query count per collaborator, a 200-entry statement under '
            .'900 ms, and a 20,000-entry statement streaming as CSV inside 128 MB. The memory clause is '
            .'the one that cannot be scaled down at all: peak memory over 40 rows is the memory of an '
            .'empty request, and the defect PRF-10 catches is a statement builder that materialises '
            .'the collection before it streams — invisible at any volume this fixture can reach. '
            .'FIN-19 cites the same seeder for the reconciliation half.'
        );
    }
}
