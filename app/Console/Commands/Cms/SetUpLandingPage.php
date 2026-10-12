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
 * Lays out the home page as a product landing page: a solutions-by-audience block, pricing plans and a
 * sign-up banner, with starter copy an editor then rewrites in /admin/website.
 *
 * Everything goes through the CMS services (SectionService, ContentPublisher, MenuService), exactly as an
 * editor's clicks would, so validation, revisions, the audit trail and the public cache all behave the
 * same way. Safe to run again: a section that already exists is left alone, and nothing an editor wrote is
 * overwritten unless an option asks for it.
 *
 *   (no option)  add solutions, pricing and the sign-up banner where they are missing, and order the page;
 *   --hero       also rewrite the hero's copy, buttons, badge and rating;
 *   --menu       also add Solutions / Pricing / FAQs anchor links to the header menu, and a header button;
 *   --tidy       also switch off the old closing call-to-action banner (switched off, never deleted).
 *
 * **The prices and the rating are placeholders**, written here so the layout can be seen. Replace them
 * with real figures in /admin/website before the page goes live.
 */
final class SetUpLandingPage extends Command
{
    use ConfirmableTrait;

    protected $signature = 'cms:landing-page
        {--hero : Rewrite the hero copy, buttons, badge and rating}
        {--menu : Add Solutions / Pricing / FAQs links and a header button}
        {--tidy : Switch off the old closing call-to-action banner}
        {--force : Run in production without asking}';

    protected $description = 'Add the landing-page sections (solutions, pricing, sign-up banner) to the home page';

    /** Where each home section goes; anything not listed keeps its place after these. */
    private const ORDER = ['hero', 'solutions', 'about', 'pricing', 'services', 'courses', 'faq', 'signup_cta'];

    public function handle(SectionService $sections, ContentPublisher $publisher, MenuService $menus): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $this->line(sprintf('Database: <info>%s</info>', DB::connection()->getDatabaseName()));

        $this->placeOnce($sections, $publisher, 'solutions', 'solutions', $this->solutions(), ['feature' => $this->features()]);
        $this->placeOnce($sections, $publisher, 'pricing', 'pricing', $this->pricing(), ['plan' => $this->plans()]);
        $this->placeOnce($sections, $publisher, 'signup_cta', 'get-started', $this->signup(), []);

        $this->anchor($sections, 'faq', 'faq');
        $this->anchor($sections, 'about', 'about');

        if ($this->option('hero')) {
            $this->rewriteHero($sections, $publisher);
        }

        if ($this->option('menu')) {
            $this->addMenuLinks($menus);
            $this->headerButton($sections, $publisher);
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

        $this->info('Landing page ready. Replace the placeholder prices and rating in /admin/website before going live.');

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
            'badge_text' => 'Next-gen management platform',
            'badge_tag' => 'v2.0 live',
            'heading' => 'Scale your software house & IT academy in one unified system',
            'subtitle' => 'Track client projects and student batches, bill both from one ledger, and give clients and students their own portals.',
            'primary_button' => ['label' => 'Start free trial', 'url' => '#pricing', 'style' => 'primary', 'new_tab' => false],
            'secondary_button' => ['label' => 'Book a live demo', 'url' => '/contact', 'style' => 'outline', 'new_tab' => false],
            // Placeholder — replace with a rating the business can back up, or clear it to hide the line.
            'rating_value' => '4.9',
            'rating_text' => 'average rating from our clients',
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

    private function addMenuLinks(MenuService $menus): void
    {
        $menu = Menu::query()->where('location', MenuLocation::Header->value)->first();

        if ($menu === null) {
            $this->warn('  · header: no header menu to add links to.');

            return;
        }

        $existing = DB::table('menu_items')->where('menu_id', $menu->getKey())->whereNull('deleted_at')
            ->pluck('label')->map(static fn ($label): string => mb_strtolower((string) $label))->all();

        foreach (['Solutions' => 'solutions', 'Pricing' => 'pricing', 'FAQs' => 'faq'] as $label => $anchor) {
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
        }
    }

    private function headerButton(SectionService $sections, ContentPublisher $publisher): void
    {
        $header = WebsiteSection::query()
            ->where('section_key', 'header')
            ->where('placement', SectionPlacement::GlobalHeader->value)
            ->whereNull('page_id')
            ->first();

        if ($header === null || filled(data_get($header->content, 'cta_button.url'))) {
            return;
        }

        $header = $sections->saveDraft($header, [
            'cta_button_enabled' => true,
            'cta_button' => ['label' => 'Get started', 'url' => '#pricing', 'style' => 'primary', 'new_tab' => false],
        ]);

        $publisher->publish($header->fresh());
        $this->line('  · header: added a Get started button.');
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
            $this->line('  · home: ordered the sections hero → solutions → about → pricing → FAQ → sign-up.');
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
            'eyebrow' => 'Solutions',
            'heading' => 'One platform for both sides of your business',
            'description' => 'Everything a software house and a training institute run on, sharing one set of clients, staff and accounts.',
            'software_house_label' => 'For software houses',
            'institute_label' => 'For training institutes',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function features(): array
    {
        return [
            ['persona' => 'software_house', 'icon' => 'briefcase', 'title' => 'Project tracking', 'text' => 'Milestones, budgets and status for every client project in one view.'],
            ['persona' => 'software_house', 'icon' => 'clock', 'title' => 'Time logging', 'text' => 'Log hours against tasks and see what each project really costs.'],
            ['persona' => 'software_house', 'icon' => 'view-columns', 'title' => 'Sprint boards', 'text' => 'Plan sprints on a drag-and-drop board the whole team shares.'],
            ['persona' => 'software_house', 'icon' => 'document-text', 'title' => 'Invoicing', 'text' => 'Raise invoices from milestones and follow every payment until it is paid.'],
            ['persona' => 'software_house', 'icon' => 'user-circle', 'title' => 'Client portal', 'text' => 'Clients follow progress, approve work and download invoices themselves.'],
            ['persona' => 'software_house', 'icon' => 'chart-bar', 'title' => 'Reports', 'text' => 'Profit by project, hours by person and cash by month, ready to export.'],
            ['persona' => 'institute', 'icon' => 'users', 'title' => 'Batch management', 'text' => 'Schedule batches, assign teachers and rooms, and keep every timetable in step.'],
            ['persona' => 'institute', 'icon' => 'user-plus', 'title' => 'Student enrollments', 'text' => 'Admissions, enrolments and transfers, with the full history of each student.'],
            ['persona' => 'institute', 'icon' => 'book-open', 'title' => 'Course syllabus', 'text' => 'Modules, topics and materials, with progress tracked for every batch.'],
            ['persona' => 'institute', 'icon' => 'receipt-percent', 'title' => 'Fee vouchers', 'text' => 'Issue vouchers and instalment plans, and record every payment received.'],
            ['persona' => 'institute', 'icon' => 'clipboard-document-check', 'title' => 'Attendance', 'text' => 'Mark a whole register in seconds and see who is falling behind.'],
            ['persona' => 'institute', 'icon' => 'academic-cap', 'title' => 'Certificates', 'text' => 'Results, certificates and ID cards issued from the same student record.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pricing(): array
    {
        return [
            'eyebrow' => 'Pricing',
            'heading' => 'Simple pricing that grows with you',
            'description' => 'Start small and move up as your teams and batches grow. Every plan includes updates and support.',
            'billing_toggle' => true,
            'monthly_label' => 'Monthly',
            'yearly_label' => 'Yearly',
            'yearly_badge' => 'Save 20%',
            'period_label' => '/mo',
            'yearly_note' => 'billed yearly',
            'footnote' => 'Prices are placeholders shown in US dollars. Need something in between? Talk to us.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plans(): array
    {
        return [
            [
                'name' => 'Starter',
                'tagline' => 'For small bootcamps and boutique agencies getting organised.',
                'monthly_price' => '$29',
                'yearly_price' => '$23',
                'highlighted' => false,
                'features' => "Up to 50 students\nUp to 5 active projects\nBasic billing and fee vouchers\nAttendance and timetables\nEmail support",
                'button' => ['label' => 'Start free trial', 'url' => '/contact', 'style' => 'outline', 'new_tab' => false],
            ],
            [
                'name' => 'Professional',
                'badge' => 'Most popular',
                'tagline' => 'Full software house and institute management in one place.',
                'monthly_price' => '$79',
                'yearly_price' => '$63',
                'highlighted' => true,
                'features' => "Unlimited students and projects\nClient and student portals\nAutomated invoices and reminders\nSprint boards and time tracking\nCommissions and payroll\nPriority support",
                'button' => ['label' => 'Start free trial', 'url' => '/contact', 'style' => 'primary', 'new_tab' => false],
            ],
            [
                'name' => 'Enterprise',
                'tagline' => 'For networks and large academies with their own requirements.',
                'monthly_price' => 'Custom',
                'highlighted' => false,
                'features' => "Dedicated server\nCustom workflows\nSLA-backed support\nWhite-label domain\nOnboarding and data migration",
                'button' => ['label' => 'Contact sales', 'url' => '/contact', 'style' => 'secondary', 'new_tab' => false],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function signup(): array
    {
        return [
            'eyebrow' => 'Get started',
            'heading' => 'Ready to streamline your tech business and training academy?',
            'description' => 'Leave your email and we will set up a workspace and walk you through it.',
            'email_placeholder' => 'you@company.com',
            'button_label' => 'Get instant access',
            'note' => 'No credit card needed. We reply within one working day.',
        ];
    }
}
