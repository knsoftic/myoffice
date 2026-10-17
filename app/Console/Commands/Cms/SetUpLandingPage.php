<?php

declare(strict_types=1);

namespace App\Console\Commands\Cms;

use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\Menu;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\ContentPublisher;
use App\Services\Cms\MenuService;
use App\Services\Cms\SectionService;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lays out the home page for what KN Softic actually is — an IT training institute and a software house
 * in one — with a "for students / for businesses" block and a closing sign-up banner, and starter copy an
 * editor then rewrites in /admin/website.
 *
 * Everything goes through the CMS services (SectionService, ContentPublisher, MenuService), exactly as an
 * editor's clicks would, so validation, revisions, the audit trail and the public cache all behave the
 * same way. Safe to run again: a section that already exists is left alone, and nothing an editor wrote is
 * overwritten unless an option asks for it.
 *
 *   (no option)  add the solutions block and the sign-up banner where they are missing, and order the page;
 *   --hero       also rewrite the hero's copy and buttons, and clear its rating;
 *   --menu       also add an FAQs anchor link to the header menu, and an "Apply now" header button;
 *   --tidy       also switch off the old closing call-to-action banner (switched off, never deleted).
 *
 * **The copy states nothing the data does not.** Courses, fees and services are not written here: the
 * `courses` and `services` sections read them live. No rating, no client count and no price is invented —
 * the hero's figures are its own statistics, computed from the database. The `pricing` type stays
 * registered for an editor who wants it; this command no longer places one, because course fees differ
 * per course and already live on /fee-structure.
 */
final class SetUpLandingPage extends Command
{
    use ConfirmableTrait;

    protected $signature = 'cms:landing-page
        {--hero : Rewrite the hero copy and buttons, and clear its rating}
        {--menu : Add an FAQs link and an Apply now header button}
        {--tidy : Switch off the old closing call-to-action banner}
        {--force : Run in production without asking}';

    protected $description = 'Add the landing-page sections (students / businesses, sign-up banner) to the home page';

    /** Where each home section goes; anything not listed keeps its place after these. */
    private const ORDER = ['hero', 'solutions', 'about', 'courses', 'services', 'pricing', 'faq', 'signup_cta'];

    public function handle(SectionService $sections, ContentPublisher $publisher, MenuService $menus): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $this->line(sprintf('Database: <info>%s</info>', DB::connection()->getDatabaseName()));

        $this->placeOnce($sections, $publisher, 'solutions', 'solutions', $this->solutions(), ['feature' => $this->features()]);
        $this->placeOnce($sections, $publisher, 'signup_cta', 'get-started', $this->signup(), []);

        $this->anchor($sections, 'faq', 'faq');
        $this->anchor($sections, 'about', 'about');

        if ($this->option('hero')) {
            $this->rewriteHero($sections, $publisher);
        }

        if ($this->option('menu')) {
            $linked = $this->addMenuLinks($menus);

            // The header carries its menu as a snapshot taken when it was published, so a new link shows
            // only once the header is published again — which adding the button already does.
            if (! $this->headerButton($sections, $publisher) && $linked) {
                $this->republishHeader($publisher);
            }
        }

        if ($this->option('tidy')) {
            foreach ($this->homeSections('cta') as $old) {
                if ($old->is_enabled) {
                    $sections->disable($old, 'Replaced by the sign-up banner (cms:landing-page --tidy).');
                    $this->line('  · home: switched off the old call-to-action banner (it can be switched back on).');
                }
            }
        }

        $this->order($sections);

        $this->info('Landing page ready. Read it through in /admin/website before going live.');

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Sections
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, list<array<string, mixed>>>  $items
     */
    private function placeOnce(SectionService $sections, ContentPublisher $publisher, string $key, string $anchor, array $content, array $items): void
    {
        if ($this->homeSections($key)->isNotEmpty()) {
            $this->line(sprintf('  · home: a %s section already exists — left alone.', $key));

            return;
        }

        $section = $sections->place($key, SectionPlacement::Home);
        $section = $sections->saveDraft($section, $content);

        foreach ($items as $group => $rows) {
            foreach ($rows as $row) {
                $sections->upsertItem($section, $group, $row + ['is_enabled' => true]);
            }
        }

        $sections->rename($section, null, $anchor);
        $publisher->publish($section->fresh());

        $this->line(sprintf('  · home: published a %s section (#%s).', $key, $anchor));
    }

    private function anchor(SectionService $sections, string $key, string $anchor): void
    {
        foreach ($this->homeSections($key) as $section) {
            if ($section->anchor === null || $section->anchor === '') {
                $sections->rename($section, $section->name, $anchor);
                $this->line(sprintf('  · home: the %s section now answers to #%s.', $key, $anchor));
            }
        }
    }

    private function rewriteHero(SectionService $sections, ContentPublisher $publisher): void
    {
        $hero = $this->homeSections('hero')->first();

        if ($hero === null) {
            $this->warn('  · home: no hero section to rewrite.');

            return;
        }

        $hero = $sections->saveDraft($hero, [
            'badge_text' => 'IT training & software house',
            'badge_tag' => $this->city(),
            'heading' => 'Learn practical IT skills, or get your website and app built',
            // The hero allows 180 characters.
            'subtitle' => 'On-campus courses in design, video editing, web and app development, digital marketing and '
                .'freelancing — and websites, apps and custom software built for businesses.',
            'primary_button' => ['label' => 'Explore courses', 'url' => '/courses', 'style' => 'primary', 'new_tab' => false],
            'secondary_button' => ['label' => 'Get a quote', 'url' => '/request-a-quote', 'style' => 'outline', 'new_tab' => false],
            // No rating: there is no verified one to show, and the line hides itself while these are empty.
            'rating_value' => null,
            'rating_text' => null,
            'alignment' => 'center',
            'show_statistics' => true,
        ]);

        $publisher->publish($hero->fresh());
        $this->line('  · home: rewrote and published the hero.');
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */

    /**
     * @return bool whether a link was added
     */
    private function addMenuLinks(MenuService $menus): bool
    {
        $menu = Menu::query()->where('location', MenuLocation::Header->value)->first();

        if ($menu === null) {
            $this->warn('  · header: no header menu to add links to.');

            return false;
        }

        $added = false;

        $existing = DB::table('menu_items')->where('menu_id', $menu->getKey())->whereNull('deleted_at')
            ->pluck('label')->map(static fn ($label): string => mb_strtolower((string) $label))->all();

        foreach (['FAQs' => 'faq'] as $label => $anchor) {
            if (in_array(mb_strtolower($label), $existing, true)) {
                continue;
            }

            $menus->storeItem($menu, [
                'label' => $label,
                'link_type' => 'section_anchor',
                'anchor' => $anchor,
                'is_enabled' => true,
            ]);

            $this->line(sprintf('  · header: added the %s link (#%s).', $label, $anchor));
            $added = true;
        }

        return $added;
    }

    /**
     * @return bool whether the header was published
     */
    private function headerButton(SectionService $sections, ContentPublisher $publisher): bool
    {
        $header = $this->header();

        if ($header === null) {
            return false;
        }

        // `WebsiteCoursesSeeder` gives the header a "View courses" button, which only repeats the Courses
        // menu link — and while it sat there this check found a button and left it, so the admission form
        // had no way in from the header at all. That seeded default is replaced; a button an editor chose
        // is still left alone.
        $url = trim((string) data_get($header->content, 'cta_button.url', ''));
        $seededDefault = $url === '/courses' && trim((string) data_get($header->content, 'cta_button.label', '')) === 'View courses';

        if ($url !== '' && ! $seededDefault) {
            return false;
        }

        // Publishing here would push an editor's unfinished header edits live along with the button.
        if ($header->getAttribute(WebsiteSection::UNPUBLISHED_CHANGES_COLUMN)) {
            $this->warn('  · header: it has unpublished edits, so no Apply now button was added — publish it in /admin/website first.');

            return false;
        }

        $header = $sections->saveDraft($header, [
            'cta_button_enabled' => true,
            'cta_button' => ['label' => 'Apply now', 'url' => '/admission', 'style' => 'primary', 'new_tab' => false],
        ]);

        $publisher->publish($header->fresh());
        $this->line($seededDefault ? '  · header: replaced the View courses button with Apply now.' : '  · header: added an Apply now button.');

        return true;
    }

    /**
     * Publish the header again so its menu snapshot picks up the new link — unless an editor has unpublished
     * changes on it, which this command must not push live on their behalf.
     */
    private function republishHeader(ContentPublisher $publisher): void
    {
        $header = $this->header();

        if ($header === null) {
            return;
        }

        if ($header->getAttribute(WebsiteSection::UNPUBLISHED_CHANGES_COLUMN)) {
            $this->warn('  · header: it has unpublished edits, so it was left alone — publish it in /admin/website to show the new link.');

            return;
        }

        $publisher->publish($header);
        $this->line('  · header: published again so the new link shows.');
    }

    private function header(): ?WebsiteSection
    {
        return WebsiteSection::query()
            ->where('section_key', 'header')
            ->where('placement', SectionPlacement::GlobalHeader->value)
            ->whereNull('page_id')
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Order
    |--------------------------------------------------------------------------
    */

    private function order(SectionService $sections): void
    {
        $rows = DB::table('website_sections')
            ->where('placement', SectionPlacement::Home->value)
            ->whereNull('page_id')
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'section_key']);

        $rank = array_flip(self::ORDER);

        $ordered = $rows
            ->sortBy(static fn (object $row, int $position): array => [$rank[$row->section_key] ?? count($rank), $position])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($ordered !== $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all()) {
            $sections->reorder(SectionPlacement::Home, null, $ordered);
            $this->line('  · home: ordered the sections hero → solutions → about → courses → services → FAQ → sign-up.');
        }
    }

    /**
     * @return Collection<int, WebsiteSection>
     */
    private function homeSections(string $key)
    {
        return WebsiteSection::query()
            ->where('section_key', $key)
            ->where('placement', SectionPlacement::Home->value)
            ->whereNull('page_id')
            ->orderBy('sort_order')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Starter copy — every word editable in /admin/website
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function solutions(): array
    {
        return [
            'eyebrow' => 'What we do',
            'heading' => 'Courses for students, software for businesses',
            'description' => 'Join a course to learn a skill you can earn from, or bring us a project and our team will build it.',
            'software_house_label' => 'For businesses',
            'institute_label' => 'For students',
        ];
    }

    /**
     * The course areas are the institute's own categories, and the business cards its own services.
     *
     * @return list<array<string, mixed>>
     */
    private function features(): array
    {
        return [
            ['persona' => 'institute', 'icon' => 'photo', 'title' => 'Graphic designing', 'text' => 'Photoshop, Illustrator, InDesign and UI/UX — from a first logo to a complete brand.'],
            ['persona' => 'institute', 'icon' => 'video-camera', 'title' => 'Video editing', 'text' => 'Premiere Pro, After Effects and CapCut for reels, ads and long-form video.'],
            ['persona' => 'institute', 'icon' => 'computer-desktop', 'title' => 'Web development', 'text' => 'HTML & CSS, JavaScript, PHP & MySQL and WordPress, up to full-stack web development.'],
            ['persona' => 'institute', 'icon' => 'puzzle-piece', 'title' => 'App & game development', 'text' => 'Android, Flutter and React Native apps, and games built with Unity.'],
            ['persona' => 'institute', 'icon' => 'megaphone', 'title' => 'Digital marketing', 'text' => 'SEO, Google Ads, Facebook & Instagram ads and social media marketing.'],
            ['persona' => 'institute', 'icon' => 'globe-alt', 'title' => 'Freelancing & e-commerce', 'text' => 'Fiverr & Upwork, Shopify, Amazon VA, dropshipping and building a portfolio that wins work.'],
            ['persona' => 'software_house', 'icon' => 'computer-desktop', 'title' => 'Websites & web apps', 'text' => 'Fast, mobile-friendly business websites and the web systems your staff log into.'],
            ['persona' => 'software_house', 'icon' => 'squares-2x2', 'title' => 'Mobile apps', 'text' => 'Android and iOS apps, built natively or with Flutter and React Native.'],
            ['persona' => 'software_house', 'icon' => 'cog-6-tooth', 'title' => 'Custom software', 'text' => 'Software designed around the way your business already works.'],
            ['persona' => 'software_house', 'icon' => 'credit-card', 'title' => 'E-commerce stores', 'text' => 'Shopify, WooCommerce or a custom store, with a catalogue, a cart and online payments.'],
            ['persona' => 'software_house', 'icon' => 'chart-bar', 'title' => 'Digital marketing', 'text' => 'Search, social media and paid ads that reach people already looking for what you sell.'],
            ['persona' => 'software_house', 'icon' => 'server-stack', 'title' => 'Branding, domain & hosting', 'text' => 'Logos and brand artwork, and the domain, hosting and SSL that keep your site online.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function signup(): array
    {
        return [
            'eyebrow' => 'Get in touch',
            'heading' => 'Ready to start a course or a project?',
            'description' => 'Leave your email and continue to our contact form — tell us which course you want, or what you need built.',
            'email_placeholder' => 'you@example.com',
            'button_label' => 'Continue',
            'note' => 'Prefer to talk? Our phone and WhatsApp numbers are at the bottom of every page.',
        ];
    }

    private function city(): ?string
    {
        return trim((string) setting('contact.city', '')) ?: null;
    }
}
