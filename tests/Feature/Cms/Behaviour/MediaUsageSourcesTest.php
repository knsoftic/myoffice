<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\MediaAsset;
use App\Services\Cms\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Review round 2 — `MediaService::usage()` and `recountUsage()` list their foreign-key sources by hand
 * (phase-03 §6.8, D24, build-order §4 media row). A later phase that adds a `*_media_id` column and forgets
 * them leaves its images at `usage_count` 0: the D24 delete guard then lets an image still shown on a live
 * service or course be trashed, and the nightly `cms:media-recount` keeps resetting the count.
 *
 * The rule, enforced here for every phase at once: **every foreign key into `media_assets` is a usage
 * source.** The schema is the list — `information_schema.KEY_COLUMN_USAGE` — so a new `*_media_id` column
 * fails this test until MediaService names it, and a reference through any of them (where the seeded
 * fixture holds a row to point) is counted by `usage()`, by the one-asset recount and by the full pass.
 */
final class MediaUsageSourcesTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    /** Phase 3's own sources (§2): each must exist and be exercised, not merely named. */
    private const PHASE_3_SOURCES = [
        'cta_blocks.background_media_id',
        'pages.banner_media_id',
        'seo_meta.og_image_media_id',
        'website_section_items.media_asset_id',
        'website_section_media.media_asset_id',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    public function test_every_foreign_key_into_media_assets_is_named_by_media_service(): void
    {
        $sources = $this->mediaForeignKeys();

        foreach (self::PHASE_3_SOURCES as $source) {
            $this->assertContains($source, $sources, sprintf('Phase 3 declares the foreign key %s.', $source));
        }

        $code = (string) file_get_contents((string) (new ReflectionClass(MediaService::class))->getFileName());

        foreach ($sources as $source) {
            [$table, $column] = explode('.', $source, 2);

            $this->assertMatchesRegularExpression(
                '/[\'"]'.preg_quote($table, '/').'(?:\s+as\s+\w+)?[\'"]/',
                $code,
                sprintf('%s references media_assets, but MediaService never reads the %s table: its images would count as unused (D24).', $source, $table),
            );

            $this->assertMatchesRegularExpression(
                '/[\'"](?:\w+\.)?'.preg_quote($column, '/').'[\'"]/',
                $code,
                sprintf('%s references media_assets, but MediaService never reads the %s column: its images would count as unused (D24).', $source, $column),
            );
        }
    }

    public function test_a_reference_through_each_foreign_key_counts_as_usage(): void
    {
        $exercised = [];

        foreach ($this->mediaForeignKeys() as $source) {
            [$table, $column] = explode('.', $source, 2);

            $asset = $this->makeImageAsset();

            if (! $this->pointAt($table, $column, $asset)) {
                // A later phase's table the seeder leaves empty: the naming check above still applies.
                $this->assertNotContains($source, self::PHASE_3_SOURCES, sprintf('The fixture must provide a row for %s.', $source));

                continue;
            }

            $this->assertCount(1, $this->media()->usage($asset->fresh()), sprintf('A reference through %s is one place the asset is used.', $source));

            $this->media()->recountUsage($asset->fresh());
            $this->assertSame(1, (int) $asset->fresh()->usage_count, sprintf('The one-asset recount counts %s.', $source));

            DB::table('media_assets')->where('id', $asset->getKey())->update(['usage_count' => 0]);

            $this->media()->recountUsage();
            $this->assertSame(1, (int) $asset->fresh()->usage_count, sprintf('The full pass (cms:media-recount) counts %s.', $source));

            $exercised[] = $source;
        }

        $this->assertSame(self::PHASE_3_SOURCES, array_values(array_intersect(self::PHASE_3_SOURCES, $exercised)), 'Every Phase 3 source was exercised.');
    }

    /**
     * Every `table.column` whose foreign key references `media_assets`, sorted.
     *
     * @return list<string>
     */
    private function mediaForeignKeys(): array
    {
        $rows = DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE'
            .' WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? ORDER BY TABLE_NAME, COLUMN_NAME',
            ['media_assets'],
        );

        return array_values(array_unique(array_map(static fn (object $row): string => $row->t.'.'.$row->c, $rows)));
    }

    /**
     * Point one live row of `$table` at the asset. The section media pivot gets a row of its own (the seeder
     * places no image); any other table has its first live row updated. False when there is no row to point.
     */
    private function pointAt(string $table, string $column, MediaAsset $asset): bool
    {
        if ($table === 'website_section_media') {
            DB::table($table)->insert([
                'website_section_id' => (int) $this->seededSection('hero', SectionPlacement::Home)->getKey(),
                'media_asset_id' => (int) $asset->getKey(),
                'role' => 'hero_image',
                'sort_order' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            return true;
        }

        $query = DB::table($table);

        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->limit(1)->update([$column => (int) $asset->getKey()]) === 1;
    }
}
