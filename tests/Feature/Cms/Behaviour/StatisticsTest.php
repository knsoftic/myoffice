<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\StatisticMetric;
use App\Models\Cms\WebsiteSection;
use App\Models\Cms\WebsiteSectionItem;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use App\Services\Cms\StatisticsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-03 §11.6 — statistics and numeric edge cases (FT-30, FT-31, FT-45).
 *
 * INV-12: a statistic is a decimal string, never a float, and a value that cannot be resolved renders
 * **nothing at all** — never "0 Students Trained" because a later phase's module is not installed. The
 * same money-grade rule as the rest of the system (CLAUDE.md rule 4 applies to every stored number): the
 * typed value round-trips as `decimal(15,2)`, formatting follows the localization separators, and a
 * negative value never gets in.
 *
 * The hero's six seeded statistics are `auto` with no fallback; each test removes them first so the
 * strip holds exactly what the test placed.
 */
final class StatisticsTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();

        $this->travelTo(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
    }

    /** FT-30 */
    public function test_live_statistic_falls_back_then_disappears(): void
    {
        $hero = $this->heroWithoutStatistics();

        // The students module is absent from this site: disabled, and (until Phase 15) without a table.
        $this->switchModule('students', false);

        $item = $this->sections()->upsertItem($hero, 'statistic', [
            'label' => 'FT30 Graduates',
            'value_mode' => 'auto',
            'metric' => StatisticMetric::StudentsTrained->value,
            'manual_value' => '500',
            'suffix' => '+',
        ]);

        $this->publisher()->publish($hero);

        $this->assertNull($this->provider()->resolve(StatisticMetric::StudentsTrained), 'A disabled module resolves to null, never "0.00".');
        $this->assertSame('500.00', $this->provider()->valueFor($item->fresh()), 'An unresolvable auto item falls back to its manual value.');

        $this->assertStringContainsString('500+FT30Graduates', $this->squashedText((string) $this->get('/')->assertOk()->getContent()));

        // No fallback left: the item renders nothing — neither its caption nor a zero.
        $item = $this->sections()->upsertItem($hero, 'statistic', ['manual_value' => null], $item);
        $this->publisher()->publish($hero->fresh());

        $this->assertNull($this->provider()->valueFor($item->fresh()));

        $html = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('FT30 Graduates', $html, 'An unresolvable statistic with no fallback must not render its label.');
        $this->assertStringNotContainsString('0+FT30Graduates', $this->squashedText($html));

        // Module switched back on but its table still absent: still null, still nothing.
        $this->switchModule('students', true);
        $this->bumpPublicCache('FT-30 module switched on');

        if (! Schema::hasTable('students')) {
            $this->assertNull($this->provider()->resolve(StatisticMetric::StudentsTrained), 'A missing table resolves to null, never "0.00".');
            $this->get('/')->assertOk()->assertDontSee('FT30 Graduates');
        }

        // A live metric whose source exists renders the real count, with no manual value at all.
        $this->setSetting('company.founded_year', '2016');

        $item = $this->sections()->upsertItem($hero, 'statistic', ['metric' => StatisticMetric::YearsExperience->value], $item);
        $this->publisher()->publish($hero->fresh());

        $this->assertSame('10.00', $this->provider()->valueFor($item->fresh()));
        $this->assertStringContainsString('10+FT30Graduates', $this->squashedText((string) $this->get('/')->assertOk()->getContent()));
    }

    /** FT-31 */
    public function test_statistic_values_are_decimal_strings_and_format_correctly(): void
    {
        $hero = $this->heroWithoutStatistics();

        $projects = $this->sections()->upsertItem($hero, 'statistic', [
            'label' => 'FT31 Projects',
            'value_mode' => 'manual',
            'manual_value' => '1500',
            'prefix' => 'PKR',
            'suffix' => '+',
        ]);

        $this->assertSame('1500.00', DB::table('website_section_items')->where('id', $projects->getKey())->value('manual_value'), 'The typed number is stored as decimal(15,2).');
        $this->assertSame('1500.00', $projects->fresh()->manual_value, 'The model hands back a decimal string, never a float.');
        $this->assertSame('1500.00', $this->provider()->valueFor($projects->fresh()));

        $this->sections()->upsertItem($hero, 'statistic', [
            'label' => 'FT31 Learners',
            'value_mode' => 'manual',
            'manual_value' => '1250000',
        ]);

        $this->sections()->upsertItem($hero, 'statistic', [
            'label' => 'FT31 Years',
            'value_mode' => 'auto',
            'metric' => StatisticMetric::YearsExperience->value,
            'suffix' => '+',
        ]);

        $this->setSetting('company.founded_year', '2015');
        $this->publisher()->publish($hero->fresh());

        $snapshotValues = array_column($this->snapshotOf($hero)['items']['statistic'] ?? [], 'value');
        $this->assertContains('1500.00', $snapshotValues, 'The snapshot freezes the manual value as a decimal string.');

        $text = $this->squashedText((string) $this->get('/')->assertOk()->getContent());

        $this->assertStringContainsString('PKR1,500+FT31Projects', $text, '"1500.00" renders as 1,500 with its prefix and suffix.');
        $this->assertStringContainsString('1,250,000FT31Learners', $text);
        $this->assertStringContainsString('11+FT31Years', $text, 'founded_year 2015 is 11 years of experience in 2026.');

        // A negative value is rejected by validation.
        $this->assertThrows(
            fn () => $this->sections()->upsertItem($hero, 'statistic', ['label' => 'FT31 Negative', 'value_mode' => 'manual', 'manual_value' => '-5']),
            InvalidSectionContentException::class,
        );
        $this->assertFalse(DB::table('website_section_items')->where('content', 'like', '%FT31 Negative%')->exists());

        // The localization separators decide the grouping.
        $this->setSetting('localization.thousand_separator', '.');
        $this->setSetting('localization.decimal_separator', ',');
        $this->bumpPublicCache('FT-31 separators changed');

        $text = $this->squashedText((string) $this->get('/')->assertOk()->getContent());
        $this->assertStringContainsString('1.250.000FT31Learners', $text);
        $this->assertStringContainsString('PKR1.500+FT31Projects', $text);

        // A future founding year makes the item disappear…
        $this->setSetting('company.founded_year', '2099');
        $this->bumpPublicCache('FT-31 founded in the future');

        $this->get('/')->assertOk()->assertDontSee('FT31 Years')->assertSee('FT31 Learners');

        // …and so does a non-numeric one.
        $this->setSetting('company.founded_year', 'not-a-year');
        $this->bumpPublicCache('FT-31 founded year not numeric');

        $this->get('/')->assertOk()->assertDontSee('FT31 Years')->assertSee('FT31 Learners');
    }

    /**
     * Review round 2 (build-order F2, INV-12): a source that exists but counts **zero** — the empty table a
     * later phase has just migrated — is not a claim the site makes. `resolve()` still reports the true
     * count; the item falls back to its manual value, and with none it renders nothing at all.
     */
    public function test_a_live_count_of_zero_falls_back_like_an_unresolved_metric(): void
    {
        $hero = $this->heroWithoutStatistics();

        // Founded this year: the metric resolves, and its honest value is zero.
        $this->setSetting('company.founded_year', '2026');

        $item = $this->sections()->upsertItem($hero, 'statistic', [
            'label' => 'FTZ Years Running',
            'value_mode' => 'auto',
            'metric' => StatisticMetric::YearsExperience->value,
            'manual_value' => null,
            'suffix' => '+',
        ]);

        $this->publisher()->publish($hero);

        $this->assertSame('0.00', $this->provider()->resolve(StatisticMetric::YearsExperience), 'The live count itself is still reported truthfully.');
        $this->assertNull($this->provider()->valueFor($item->fresh()), 'A zero live count with no fallback resolves to nothing.');

        $html = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('FTZ Years Running', $html, 'A zero statistic must not render its label.');
        $this->assertStringNotContainsString('0+FTZYearsRunning', $this->squashedText($html));

        // With a manual value the zero falls back to it, exactly as an unresolved metric does.
        $item = $this->sections()->upsertItem($hero, 'statistic', ['manual_value' => '12'], $item);
        $this->publisher()->publish($hero->fresh());

        $this->assertSame('12.00', $this->provider()->valueFor($item->fresh()));
        $this->assertStringContainsString('12+FTZYearsRunning', $this->squashedText((string) $this->get('/')->assertOk()->getContent()));

        // A non-zero live count wins over the manual value again.
        $this->setSetting('company.founded_year', '2019');

        $this->assertSame('7.00', $this->provider()->valueFor($item->fresh()));
        $this->assertStringContainsString('7+FTZYearsRunning', $this->squashedText((string) $this->get('/')->assertOk()->getContent()));
    }

    /** FT-45 */
    public function test_disabled_statistic_item_is_excluded_from_the_published_snapshot(): void
    {
        $hero = $this->heroWithoutStatistics();

        $item = $this->sections()->upsertItem($hero, 'statistic', [
            'label' => 'FT45 Toggled Statistic',
            'value_mode' => 'manual',
            'manual_value' => '45',
        ]);

        $this->publisher()->publish($hero);
        $this->get('/')->assertOk()->assertSee('FT45 Toggled Statistic');

        $item = $this->sections()->toggleItem($item, false);
        $this->publisher()->publish($hero->fresh());

        $this->assertNotContains('FT45 Toggled Statistic', $this->snapshotLabels($hero), 'A disabled item is excluded from the published snapshot.');
        $this->get('/')->assertOk()->assertDontSee('FT45 Toggled Statistic');

        // Toggling it back on without publishing changes the draft only.
        $this->sections()->toggleItem($item, true);

        $this->assertNotContains('FT45 Toggled Statistic', $this->snapshotLabels($hero));
        $this->assertSame(1, (int) $this->sectionRow($hero)->has_unpublished_changes, 'Re-enabling is an unpublished change.');

        $this->bumpPublicCache('FT-45 make sure nothing stale is served');
        $this->get('/')->assertOk()->assertDontSee('FT45 Toggled Statistic');
    }

    /**
     * The seeded hero with its six auto statistics removed.
     */
    private function heroWithoutStatistics(): WebsiteSection
    {
        $hero = $this->seededSection('hero');

        foreach (WebsiteSectionItem::query()->where('website_section_id', $hero->getKey())->get() as $item) {
            $this->sections()->deleteItem($item);
        }

        return $hero->fresh();
    }

    /**
     * A fresh provider: its memo lives on the instance and its set under the public cache version.
     */
    private function provider(): StatisticsProvider
    {
        $this->bumpPublicCache('StatisticsProvider re-read');

        return app(StatisticsProvider::class);
    }

    /**
     * @return list<string|null>
     */
    private function snapshotLabels(WebsiteSection $section): array
    {
        return array_map(
            static fn (array $item): ?string => $item['content']['label'] ?? null,
            $this->snapshotOf($section)['items']['statistic'] ?? [],
        );
    }
}
