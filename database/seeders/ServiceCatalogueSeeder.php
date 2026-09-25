<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\Service;
use App\Models\Cms\ServiceCategory;
use App\Services\Cms\CacheVersion;
use App\Services\Cms\ContentPublisher;
use App\Services\Cms\SectionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The seven services this installation offers, grouped into three categories.
 *
 * **The names are the client's own** — they were given as the seven "software house" pages the site
 * needed. The descriptions say what each service *is*, in the general case, and say nothing about
 * who has delivered it, how often, or how well. That line matters: "e-commerce stores with a cart
 * and a payment gateway" describes a category, while "over 200 stores delivered" would be a claim
 * about a company, and a seeder is not in a position to make one.
 *
 * For the same reason **no price is written**. `starting_price` stays null and `price_visible`
 * false, because a rate is a commercial decision this file cannot make and a wrong one published
 * on a live site is worse than none: a visitor who reads it treats it as a quote.
 *
 * Not in `DatabaseSeeder` — this is one company's catalogue, not part of the product. Insert-only,
 * matched on slug: a re-run adds what is missing and never rewrites a description somebody has
 * since improved (**D65**).
 *
 *     php artisan db:seed --force --class=ServiceCatalogueSeeder
 *
 * Unlike courses, a service has no completeness gate — `Service::scopePublic()` asks only for
 * `status = published` — so these are published on creation. There is no outline to owe.
 */
final class ServiceCatalogueSeeder extends Seeder
{
    /**
     * category => [icon, [name, short description]]
     *
     * @var array<string, array{0: string, 1: list<array{0: string, 1: string}>}>
     */
    private const CATALOGUE = [
        'Development' => ['wrench-screwdriver', [
            ['Web Development', 'Websites and web applications built to order — from a business site that loads fast and reads well on a phone, to a system your own team signs in to.'],
            ['Mobile App Development', 'Android and iOS applications, built natively or with Flutter and React Native where one codebase can serve both.'],
            ['Custom Software Development', 'Software shaped around the way a business already works, rather than a product the business has to bend its process around.'],
            ['E-commerce Development', 'Online stores with a catalogue, a cart and a payment gateway — on Shopify or WooCommerce, or built from scratch.'],
        ]],
        'Design & Marketing' => ['sparkles', [
            ['Graphic Designing', 'Logos, brand identity, print and social artwork — the visual side of how a business introduces itself.'],
            ['Digital Marketing', 'Search visibility, social media and paid advertising: being found by the people already looking for what you do.'],
        ]],
        'Infrastructure' => ['server-stack', [
            ['Domain & Hosting', 'Domain registration, hosting, and the upkeep that keeps a site online — certificates, backups and updates.'],
        ]],
    ];

    public function run(SectionService $sections, ContentPublisher $publisher, CacheVersion $cache): void
    {
        $created = 0;
        $skipped = 0;
        $sort = 0;

        DB::transaction(function () use (&$created, &$skipped, &$sort): void {
            foreach (self::CATALOGUE as $categoryName => [$icon, $services]) {
                $sort += 10;
                $categorySlug = Str::slug($categoryName);

                $category = ServiceCategory::query()->where('slug', $categorySlug)->first()
                    ?? ServiceCategory::query()->create([
                        'name' => $categoryName,
                        'slug' => $categorySlug,
                        'icon' => $icon,
                        'is_active' => true,
                        'sort_order' => $sort,
                    ]);

                foreach ($services as $index => [$name, $description]) {
                    $slug = Str::slug($name);

                    if (Service::withTrashed()->where('slug', $slug)->exists()) {
                        $skipped++;

                        continue;
                    }

                    Service::query()->create([
                        'service_category_id' => $category->getKey(),
                        'name' => $name,
                        'slug' => $slug,
                        'short_description' => $description,
                        // No price: a rate is a commercial decision, and a wrong one on a live page
                        // reads to a visitor as a quote.
                        'price_visible' => false,
                        'status' => ContentStatus::Published,
                        'sort_order' => ($index + 1) * 10,
                    ]);

                    $created++;
                }
            }
        }, 3);

        $this->command?->info(sprintf(
            'Services: %d created, %d already present (left untouched).',
            $created,
            $skipped,
        ));

        if (Service::query()->where('status', ContentStatus::Published->value)->exists()) {
            $this->enableServicesLink();
            $this->placeServicesSection($sections, $publisher);
            $this->republishShell($publisher);
            $cache->bump('services wired');
        }
    }

    /**
     * The nav item `WebsiteCmsSeeder` created and left off, for exactly this moment.
     */
    private function enableServicesLink(): void
    {
        $updated = DB::table('menu_items')
            ->where('url', '/services')
            ->where('is_enabled', false)
            ->update(['is_enabled' => true, 'updated_at' => now()]);

        $this->command?->line($updated > 0
            ? '  · header: the Services link is now enabled.'
            : '  · header: the Services link was already on (or removed) — left alone.');
    }

    private function placeServicesSection(SectionService $sections, ContentPublisher $publisher): void
    {
        $exists = DB::table('website_sections')
            ->where('section_key', 'services')
            ->where('placement', SectionPlacement::Home->value)
            ->whereNull('page_id')
            ->exists();

        if ($exists) {
            $this->command?->line('  · home: a services section already exists — left alone.');

            return;
        }

        $section = $sections->place('services', SectionPlacement::Home);
        $section = $sections->saveDraft($section, [
            'heading' => 'What we build',
            'description' => 'Software, stores and the marketing that brings people to them.',
        ]);

        $publisher->publish($section);

        // `place()` appends, which lands this after the closing call to action. Move it up beside
        // the courses block: the two halves of the business belong next to each other.
        $this->sortAfter($sections, 'services', 'courses');

        $this->command?->line('  · home: published a services section.');
    }

    /**
     * Move a home section to sit directly after another.
     *
     * Falls back to leaving it where `place()` put it when the anchor is absent — a section in the
     * wrong order is a presentation problem; throwing here would make it a failed deploy.
     */
    private function sortAfter(SectionService $sections, string $key, string $after): void
    {
        $order = DB::table('website_sections')
            ->where('placement', SectionPlacement::Home->value)
            ->whereNull('page_id')
            ->orderBy('sort_order')
            ->pluck('section_key', 'id')
            ->all();

        $ids = array_keys($order);
        $keys = array_values($order);

        $from = array_search($key, $keys, true);
        $anchor = array_search($after, $keys, true);

        if ($from === false || $anchor === false) {
            return;
        }

        $moving = $ids[$from];
        unset($ids[$from], $keys[$from]);
        $ids = array_values($ids);
        $keys = array_values($keys);

        $at = array_search($after, $keys, true);
        array_splice($ids, (int) $at + 1, 0, [$moving]);

        $sections->reorder(SectionPlacement::Home, null, array_map('intval', $ids));
    }

    /**
     * The menu tree is baked into the header and footer snapshots, so enabling a link reaches
     * nobody until they are republished. See the note in `WebsiteNavigationSeeder`.
     */
    private function republishShell(ContentPublisher $publisher): void
    {
        foreach ([SectionPlacement::GlobalHeader, SectionPlacement::GlobalFooter] as $placement) {
            $section = \App\Models\Cms\WebsiteSection::query()->where('placement', $placement->value)->first();

            if ($section !== null) {
                $publisher->publish($section);
            }
        }
    }
}
