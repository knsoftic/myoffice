<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Cms\ButtonStyle;
use App\Enums\Cms\SectionPlacement;
use App\Enums\CourseStatus;
use App\Models\Cms\WebsiteSection;
use App\Models\Institute\Course;
use App\Services\Cms\CacheVersion;
use App\Services\Cms\ContentPublisher;
use App\Services\Cms\SectionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Wires a populated course catalogue into the public site.
 *
 * `WebsiteCmsSeeder` already creates a **Courses** item in the header menu and deliberately leaves
 * it switched off — *"disabled until the phase that owns the target ships, so the navigation is
 * never a dead link"*. That was right when `/courses` had nothing on it. Once the catalogue is
 * published the same rule points the other way: a nav that hides a page full of courses is now the
 * thing that is wrong.
 *
 * So this seeder does three small things, and **every one of them is guarded by "is there anything
 * to show?"** — it counts published courses first and returns without touching anything when the
 * answer is zero. Run before `courses:publish` it is a no-op, which is the correct behaviour rather
 * than an inconvenience: switching on a link to an empty page is the exact mistake the original
 * `is_enabled => false` existed to prevent.
 *
 *   1. enables the header's **Courses** link;
 *   2. gives the header a primary **View courses** button — the header supports four configurable
 *      buttons and only `login_button` was on, so a marketing site's front door offered a visitor
 *      nothing to do but sign in to an account they do not have;
 *   3. places a published **courses** section on the home page.
 *
 * Idempotent throughout. An item somebody has since switched off by hand is left off — this runs
 * once to open a door, and does not keep re-opening one an administrator closed.
 *
 *     php artisan db:seed --force --class=WebsiteCoursesSeeder
 */
final class WebsiteCoursesSeeder extends Seeder
{
    public function run(SectionService $sections, ContentPublisher $publisher, CacheVersion $cache): void
    {
        $published = Course::query()->where('status', CourseStatus::Published->value)->count();

        if ($published === 0) {
            $this->command?->warn(
                'No published course, so nothing was switched on. Publish the catalogue first '
                .'(php artisan courses:publish --without-outline) and run this again.'
            );

            return;
        }

        $this->command?->info(sprintf('%d published course(s) found.', $published));

        $this->enableCoursesLink();
        $this->addHeaderButton($sections, $publisher);
        $this->placeCoursesSection($sections, $publisher);

        // The public pages are cached under this stamp, and none of the above is visible until it
        // moves (T59). Bumping it here means the operator does not have to remember.
        $cache->bump('courses wired into the public site');

        $this->command?->info('Cache version bumped — the new navigation and section are live.');
    }

    /**
     * The nav item the CMS seeder created and left off.
     *
     * Matched on the URL rather than the label, because a label is the first thing an administrator
     * renames and renaming it must not make this create a second one.
     */
    private function enableCoursesLink(): void
    {
        $updated = DB::table('menu_items')
            ->where('url', '/courses')
            ->where('is_enabled', false)
            ->update(['is_enabled' => true, 'updated_at' => now()]);

        $this->command?->line($updated > 0
            ? '  · header: the Courses link is now enabled.'
            : '  · header: the Courses link was already on (or has been removed) — left alone.');
    }

    /**
     * A primary button in the header, which had none.
     *
     * Only written when `cta_button_enabled` is still false: an administrator who has configured
     * their own CTA owns that field, and a seeder that overwrote it would replace their wording
     * with ours every time anybody ran it (**D65**).
     */
    private function addHeaderButton(SectionService $sections, ContentPublisher $publisher): void
    {
        $header = WebsiteSection::query()
            ->where('section_key', 'header')
            ->where('placement', SectionPlacement::GlobalHeader->value)
            ->first();

        if ($header === null) {
            $this->command?->line('  · header: no header section published — skipped.');

            return;
        }

        // The draft is the flat field map the validator accepts; `published_content` is the rendered
        // snapshot and has a different shape entirely.
        $fields = (array) $header->content;

        if ((bool) ($fields['cta_button_enabled'] ?? false)) {
            $this->command?->line('  · header: a CTA button is already configured — left alone.');

            return;
        }

        $fields['cta_button_enabled'] = true;
        $fields['cta_button'] = [
            'label' => 'View courses',
            'url' => '/courses',
            'style' => ButtonStyle::Primary->value,
            'new_tab' => false,
        ];

        $header = $sections->saveDraft($header, $fields);
        $publisher->publish($header);

        $this->command?->line('  · header: added a primary "View courses" button.');
    }

    /**
     * The courses block on the home page.
     *
     * Sorted after About and before the FAQ: somebody who has just read what the institute does is
     * ready to see what it teaches, and a visitor who reaches the FAQ has already decided.
     */
    private function placeCoursesSection(SectionService $sections, ContentPublisher $publisher): void
    {
        $exists = DB::table('website_sections')
            ->where('section_key', 'courses')
            ->where('placement', SectionPlacement::Home->value)
            ->whereNull('page_id')
            ->exists();

        if ($exists) {
            $this->command?->line('  · home: a courses section already exists — left alone.');

            return;
        }

        $section = $sections->place('courses', SectionPlacement::Home);
        $section = $sections->saveDraft($section, [
            'heading' => 'What we teach',
            'description' => 'Short, practical courses in web, design, marketing and IT — with the fee and the length on every one.',
        ]);

        $publisher->publish($section);

        /*
        | **Placed, then moved — because `place()` appends.**
        |
        | A new section lands at the end of the page, which for this one means after the closing
        | call to action. That is the one position it must not hold: the CTA is the page's last
        | word, and content after it reads as an afterthought somebody forgot to move.
        |
        | It belongs after About and before the FAQ. Somebody who has just read what the institute
        | does is ready to see what it teaches; somebody who has reached the FAQ has already
        | decided and is checking details.
        */
        $order = DB::table('website_sections')
            ->where('placement', SectionPlacement::Home->value)
            ->whereNull('page_id')
            ->orderBy('sort_order')
            ->pluck('section_key', 'id')
            ->all();

        $ids = array_keys($order);
        $keys = array_values($order);

        $courses = array_search('courses', $keys, true);
        $about = array_search('about', $keys, true);

        if ($courses !== false && $about !== false) {
            $courseId = $ids[$courses];

            unset($ids[$courses], $keys[$courses]);
            $ids = array_values($ids);
            $keys = array_values($keys);

            // Re-find About: removing courses may have shifted it.
            $at = array_search('about', $keys, true);
            array_splice($ids, (int) $at + 1, 0, [$courseId]);

            $sections->reorder(SectionPlacement::Home, null, array_map('intval', $ids));

            $this->command?->line('  · home: published a courses section, placed after About.');

            return;
        }

        $this->command?->line('  · home: published a courses section.');
    }
}
