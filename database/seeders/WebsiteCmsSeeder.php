<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Cms\ButtonStyle;
use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\CtaVariant;
use App\Enums\Cms\FaqSource;
use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\MenuVisibility;
use App\Enums\Cms\PageLayout;
use App\Enums\Cms\SectionPlacement;
use App\Enums\Cms\StatisticMetric;
use App\Enums\Cms\StatisticValueMode;
use App\Models\Cms\Page;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\CacheVersion;
use App\Services\Cms\ContentPublisher;
use App\Services\Cms\SectionService;
use App\Services\Cms\SeoService;
use App\Support\RichText;
use Closure;
use Database\Seeders\Concerns\WritesToConsole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * phase-03 §6.14 — the day-one public site: four menus, the four system pages, the header, hero, about,
 * FAQ, CTA and footer sections, one CTA block, three FAQ categories with six questions, and the home
 * page's SEO record.
 *
 * **Insert-only and idempotent.** Every existence check reads trashed rows too (plain query builder), so
 * an edited heading, a removed section or a deleted menu item is never re-created or overwritten
 * (FT-17, FT-50). Sections and pages are written through SectionService / ContentPublisher, so hashes,
 * snapshots, revisions and seo_meta are exactly what the admin would produce (INV-4).
 *
 * **No invented facts on a real company's website.** Statistics are `auto` with no manual fallback, so
 * each renders nothing until live data or an administrator's number exists (INV-12); the history and
 * about-statistics entries are seeded disabled; buttons that would point at sections later phases own
 * (#contact, #courses) are switched off. See docs-pending/phase-03-integration.md §I.2.
 *
 * **Run after the routes exist** (integration I.2): SnapshotBuilder resolves menu links through
 * `route('site.page')` at publish time and SeoService::ensure('site.home') needs that route.
 */
class WebsiteCmsSeeder extends Seeder
{
    use WritesToConsole;

    /** @var list<array{0: MenuLocation, 1: string, 2: string}> location, slug, name */
    private const MENUS = [
        [MenuLocation::Header, 'header', 'Main navigation'],
        [MenuLocation::FooterPrimary, 'footer-primary', 'Footer: company'],
        [MenuLocation::FooterSecondary, 'footer-secondary', 'Footer: explore'],
        [MenuLocation::FooterLegal, 'footer-legal', 'Footer: legal'],
    ];

    /** @var list<array{0: string, 1: string, 2: int}> slug, title, sort */
    private const SYSTEM_PAGES = [
        ['privacy-policy', 'Privacy Policy', 10],
        ['terms-of-service', 'Terms of Service', 20],
        ['refund-policy', 'Refund Policy', 30],
        ['course-policy', 'Course Policy', 40],
    ];

    public function run(SectionService $sections, ContentPublisher $publisher, SeoService $seo, CacheVersion $cache): void
    {
        // One cache bump for the whole run, however many publishes happen inside it.
        $cache->batch(function () use ($sections, $publisher, $seo): void {
            $menus = $this->menus();
            $pages = $this->systemPages($publisher);
            $this->menuItems($menus, $pages);
            $ctaId = $this->ctaBlock();
            $this->faqs();
            $this->sections($sections, $publisher, $menus, $ctaId);
            $seo->ensure(SeoService::HOME_ROUTE);
        }, 'Website CMS seeded');

        $this->seedInfo('Website CMS: menus, system pages, home sections, CTA block and FAQs ensured (insert-only).');
    }

    /** @return array<string, int> location => menu id */
    private function menus(): array
    {
        $ids = [];
        $now = Carbon::now();

        foreach (self::MENUS as [$location, $slug, $name]) {
            // uq_menus_location is a plain unique index: a trashed menu still owns its slot.
            $id = DB::table('menus')->where('location', $location->value)->value('id');

            $ids[$location->value] = $id !== null ? (int) $id : (int) DB::table('menus')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'location' => $location->value,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    /** @return array<string, int> slug => page id */
    private function systemPages(ContentPublisher $publisher): array
    {
        $ids = [];

        foreach (self::SYSTEM_PAGES as [$slug, $title, $sort]) {
            $existing = DB::table('pages')->where('slug', $slug)->value('id');

            if ($existing !== null) {
                $ids[$slug] = (int) $existing;

                continue;
            }

            $now = Carbon::now();
            $id = (int) DB::table('pages')->insertGetId([
                'title' => $title,
                'slug' => $slug,
                'layout' => PageLayout::Content->value,
                'excerpt' => sprintf('Placeholder %s. Replace it before the website goes live.', mb_strtolower($title)),
                'content' => RichText::sanitize(sprintf(
                    '<h2>%1$s</h2><p>This is placeholder text shipped with the website so that the link is not dead. It is not a legal document. Replace it with your own %2$s before the website goes live.</p>',
                    e($title),
                    e(mb_strtolower($title)),
                )),
                'show_banner' => true,
                'template' => 'site.pages.legal',
                'status' => ContentStatus::Draft->value,
                'is_system' => true,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Hashes, published_content, a `published` revision and the seo_meta row (index_follow).
            $publisher->publish(Page::query()->findOrFail($id));
            $ids[$slug] = $id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $menus
     * @param  array<string, int>  $pages
     */
    private function menuItems(array $menus, array $pages): void
    {
        $rows = [
            MenuLocation::Header->value => [
                ['label' => 'Home', 'link_type' => MenuItemLinkType::Route->value, 'route_name' => 'site.home', 'is_enabled' => true],
                ['label' => 'About', 'link_type' => MenuItemLinkType::SectionAnchor->value, 'anchor' => 'about', 'is_enabled' => true],
                // Disabled until the phase that owns the target ships, so the navigation is never a dead link (§6.14.3).
                ['label' => 'Courses', 'link_type' => MenuItemLinkType::Url->value, 'url' => '/courses', 'is_enabled' => false],
                ['label' => 'Services', 'link_type' => MenuItemLinkType::Url->value, 'url' => '/services', 'is_enabled' => false],
                ['label' => 'Contact', 'link_type' => MenuItemLinkType::SectionAnchor->value, 'anchor' => 'contact', 'is_enabled' => false],
            ],
            MenuLocation::FooterLegal->value => array_map(
                static fn (array $page): array => ['label' => $page[1], 'link_type' => MenuItemLinkType::Page->value, 'page_id' => $pages[$page[0]], 'is_enabled' => true],
                self::SYSTEM_PAGES,
            ),
        ];

        $now = Carbon::now();

        foreach ($rows as $location => $items) {
            $menuId = $menus[$location];

            // Any item ever created in this menu (trashed included) means an administrator owns it now.
            if (DB::table('menu_items')->where('menu_id', $menuId)->exists()) {
                continue;
            }

            foreach (array_values($items) as $index => $item) {
                DB::table('menu_items')->insert($item + [
                    'menu_id' => $menuId,
                    'parent_id' => null,
                    'depth' => 0,
                    'sort_order' => ($index + 1) * 10,
                    'visibility' => MenuVisibility::All->value,
                    'open_new_tab' => false,
                    'rel_nofollow' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function ctaBlock(): int
    {
        $id = DB::table('cta_blocks')->where('key', 'primary')->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        $email = trim((string) setting('contact.email', ''));
        $now = Carbon::now();

        return (int) DB::table('cta_blocks')->insertGetId([
            'key' => 'primary',
            'name' => 'Primary call to action',
            'variant' => CtaVariant::Banner->value,
            'heading' => 'Talk to us about your project or your next course',
            'primary_label' => $email !== '' ? 'Email us' : null,
            'primary_url' => $email !== '' ? 'mailto:'.$email : null,
            'primary_style' => ButtonStyle::Primary->value,
            'secondary_style' => ButtonStyle::Outline->value,
            'status' => ContentStatus::Published->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function faqs(): void
    {
        $now = Carbon::now();
        $catalogue = [
            ['general', 'General', 10, [
                ['How do I contact you?', '<p>Our phone number, email address and office address are listed at the bottom of every page.</p>', true],
                ['Where are you located?', '<p>Our address is shown in the footer of this website.</p>', true],
            ]],
            ['courses', 'Courses', 20, [
                ['Which courses are running?', '<p>Courses are published on this website as batches open. Until then, contact us for the current schedule.</p>', false],
                ['Do courses have fixed start dates?', '<p>Courses run in batches, each with its own start date. Ask us for the next batch of the course you want.</p>', false],
            ]],
            ['admissions', 'Admissions', 30, [
                ['How do I apply for admission?', '<p>Contact us to start your admission. We will explain the steps and the documents required.</p>', false],
                ['What payment options are there?', '<p>Ask our admissions team about the payment options for your course.</p>', false],
            ]],
        ];

        foreach ($catalogue as [$slug, $name, $sort, $questions]) {
            if (DB::table('faq_categories')->where('slug', $slug)->exists()) {
                continue; // the category and its questions belong to the administrator now
            }

            $categoryId = (int) DB::table('faq_categories')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'is_enabled' => true,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($questions as $index => [$question, $answer, $featured]) {
                DB::table('faqs')->insert([
                    'faq_category_id' => $categoryId,
                    'question' => $question,
                    'answer' => RichText::sanitize($answer),
                    'status' => ContentStatus::Published->value,
                    'is_featured' => $featured,
                    'sort_order' => ($index + 1) * 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /** @param  array<string, int>  $menus */
    private function sections(SectionService $sections, ContentPublisher $publisher, array $menus, int $ctaId): void
    {
        $company = trim((string) setting('company.name', config('app.name'))) ?: 'Welcome';
        $tagline = trim((string) setting('company.tagline', ''));
        $about = trim((string) setting('company.short_description', ''));

        $this->placeOnce($sections, $publisher, 'header', SectionPlacement::GlobalHeader, [
            'menu_ref' => $menus[MenuLocation::Header->value],
            // Both default to #contact, which no section provides before Phase 4.
            'contact_button_enabled' => false,
            'cta_button_enabled' => false,
        ]);

        $this->placeOnce($sections, $publisher, 'hero', SectionPlacement::Home, [
            'heading' => $company,
            'subtitle' => $tagline !== '' ? $tagline : null,
            // The registry defaults point at #contact and #courses, which do not exist before Phase 4 / 14.
            'primary_button' => ['label' => 'About us', 'url' => '#about', 'style' => ButtonStyle::Primary->value, 'new_tab' => false],
            'secondary_button' => ['label' => null, 'url' => null, 'style' => ButtonStyle::Outline->value, 'new_tab' => false],
        ], items: function (WebsiteSection $hero) use ($sections): void {
            foreach (StatisticMetric::heroDefaults() as $metric) {
                $sections->upsertItem($hero, 'statistic', [
                    'label' => $metric->defaultLabel(),
                    'value_mode' => StatisticValueMode::Auto->value,
                    'metric' => $metric->value,
                    'manual_value' => null, // no invented number: unresolvable renders nothing (INV-12)
                    'suffix' => $metric->defaultSuffix(),
                    'is_enabled' => true,
                ]);
            }
        });

        $this->placeOnce($sections, $publisher, 'about', SectionPlacement::Home, [
            'company_intro' => $about !== '' ? '<p>'.e($about).'</p>' : null,
        ], anchor: 'about', items: function (WebsiteSection $section) use ($sections): void {
            foreach (['Software development and IT training under one roof', 'Practical, project-based learning', 'One team from the first call to delivery'] as $title) {
                $sections->upsertItem($section, 'why_choose_us', ['title' => $title, 'is_enabled' => true]);
            }

            $founded = setting('company.founded_year');
            $year = is_numeric($founded) && (int) $founded >= 1900 && (int) $founded <= (int) Carbon::now()->year
                ? (int) $founded
                : (int) Carbon::now()->year;

            foreach (range(1, 3) as $n) {
                $sections->upsertItem($section, 'history', ['year' => $year, 'title' => "Milestone {$n}: replace with your own", 'is_enabled' => false]);
            }

            foreach ([StatisticMetric::ProjectsCompleted, StatisticMetric::StudentsTrained, StatisticMetric::YearsExperience] as $metric) {
                $sections->upsertItem($section, 'statistic', [
                    'label' => $metric->defaultLabel(),
                    'value_mode' => StatisticValueMode::Auto->value,
                    'metric' => $metric->value,
                    'manual_value' => null,
                    'suffix' => $metric->defaultSuffix(),
                    'is_enabled' => false,
                ]);
            }
        });

        $this->placeOnce($sections, $publisher, 'faq', SectionPlacement::Home, [
            'source' => FaqSource::Category->value,
            'faq_category_ref' => 'general',
            // The default "See all" link points at /faqs, which no route serves.
            'show_all_link' => ['label' => null, 'url' => null, 'style' => ButtonStyle::Link->value, 'new_tab' => false],
        ]);

        $this->placeOnce($sections, $publisher, 'cta', SectionPlacement::Home, ['cta_ref' => $ctaId]);

        $this->placeOnce($sections, $publisher, 'footer', SectionPlacement::GlobalFooter, [
            'menu_ref' => $menus[MenuLocation::FooterPrimary->value],
        ]);
    }

    /**
     * Place, draft, (anchor), (items), publish — once. A type is keyed by section_key + placement + no page,
     * trashed rows included, so a section an administrator removed stays removed.
     *
     * @param  array<string, mixed>  $content
     * @param  (Closure(WebsiteSection): void)|null  $items
     */
    private function placeOnce(SectionService $sections, ContentPublisher $publisher, string $key, SectionPlacement $placement, array $content, ?string $anchor = null, ?Closure $items = null): void
    {
        if (DB::table('website_sections')->where('section_key', $key)->where('placement', $placement->value)->whereNull('page_id')->exists()) {
            return;
        }

        $section = $sections->place($key, $placement);
        $section = $sections->saveDraft($section, $content);

        if ($anchor !== null) {
            $section = $sections->rename($section, null, $anchor);
        }

        if ($items !== null) {
            $items($section);
        }

        $publisher->publish($section);
    }
}
