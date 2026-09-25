<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\MenuVisibility;
use App\Enums\CourseStatus;
use App\Models\Institute\Course;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\CacheVersion;
use App\Services\Cms\ContentPublisher;
use App\Support\SettingsRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fills the header and the two empty footer menus with the pages that actually work.
 *
 * **The rule this whole file obeys is `WebsiteCmsSeeder`'s own**: *"disabled until the phase that
 * owns the target ships, so the navigation is never a dead link"*. Every item below is added only
 * when its destination will render something — a nav that lists nine pages of which six are empty
 * is worse than a nav that lists three that work, because the visitor learns the links are not
 * worth following.
 *
 * So the test applied to each is "what does a stranger see when they click this?":
 *
 *   · `/courses` and `/fee-structure` — added **only** when courses are published. Before that they
 *     are empty pages, and `/fee-structure` is a 404 until its toggle is on.
 *   · `/contact` and `/request-a-quote` — added unconditionally. They are **forms**: they need no
 *     catalogue behind them and they work on the first day of an install. The quote page's own
 *     toggle is switched on here for the same reason — it was defaulted off because the other five
 *     pages read live data, and this one does not.
 *   · services, portfolio, blog, careers, team, events, trainers, timetable, student reviews — all
 *     skipped, all empty. They belong in the nav the day they hold something.
 *
 * The header's **Contact** item is repointed rather than merely enabled. `WebsiteCmsSeeder` created
 * it as a section anchor to `#contact`, which assumed a contact section on the home page that was
 * never placed — switching it on as-is would have produced exactly the dead link the original
 * comment was guarding against. It becomes a route link to the real contact page.
 *
 * Idempotent: an item is matched on its destination, never its label, because a label is the first
 * thing an administrator rewrites and matching on it would mint duplicates. Nothing already in a
 * menu is reordered or relabelled.
 *
 *     php artisan db:seed --force --class=WebsiteNavigationSeeder
 */
final class WebsiteNavigationSeeder extends Seeder
{
    public function run(SettingsRepository $settings, CacheVersion $cache, ContentPublisher $publisher): void
    {
        $hasCourses = Course::query()->where('status', CourseStatus::Published->value)->exists();

        $this->command?->line($hasCourses
            ? 'Published courses found — catalogue links will be added.'
            : 'No published course — catalogue links are skipped (run courses:publish first).');

        // A form needs nothing behind it, so this page can be open from day one. The five pages
        // that read live data stay off until somebody has data to show.
        if (! (bool) $settings->get('website.quote_page_enabled', false)) {
            /*
            | `asSystem()` because a declared setting is otherwise refused by design: the repository
            | insists that anything in `SettingsRegistry` goes through `SettingsService`, which
            | validates, authorizes and audits the change. A seeder has no actor to authorize and no
            | form to validate, so it takes the one documented exemption — explicit system context,
            | in the console — rather than routing around the guard or widening it.
            */
            $settings->asSystem(static fn () => $settings->set('website.quote_page_enabled', true));
            $this->command?->line('  · settings: /request-a-quote switched on (it is a form, not a catalogue).');
        }

        $this->repointContactItem();

        /*
        | Each row carries the aliases that mean the same page. `WebsiteCmsSeeder` wrote its
        | Courses item as a plain `/courses` URL while this one would write it as a route, and a
        | check that compared only the column it was about to fill saw no clash and added a
        | second Courses link beside the first. **One destination written two ways is still one
        | destination**, and the existence check has to know that.
        */
        $header = [
            ['url', '/fee-structure', 'Fees', $hasCourses, []],
            ['route', 'site.contact.index', 'Contact', true, []],
            ['route', 'site.quote.index', 'Get a quote', true, []],
        ];

        $footerCompany = [
            ['anchor', 'about', 'About us', true, []],
            ['route', 'site.contact.index', 'Contact', true, []],
            ['route', 'site.quote.index', 'Request a quote', true, []],
        ];

        $footerExplore = [
            ['route', 'site.courses.index', 'All courses', $hasCourses, ['/courses']],
            ['url', '/fee-structure', 'Fee structure', $hasCourses, []],
        ];

        $added = 0;
        $added += $this->fill(MenuLocation::Header, $header);
        $added += $this->fill(MenuLocation::FooterPrimary, $footerCompany);
        $added += $this->fill(MenuLocation::FooterSecondary, $footerExplore);

        /*
        | Republished every run, not only when this one added something.
        |
        | A partial earlier run - or somebody editing menus in the admin - can leave rows in
        | `menu_items` that the published snapshot has never seen. Gating the republish on "did I
        | personally add anything just now" means the second run reports `0 added` and fixes
        | nothing, while the nav stays exactly as wrong as it was. It happened here.
        |
        | Publishing an unchanged section is cheap and idempotent, so the safe condition is none.
        */
        $this->republishShell($publisher);
        $cache->bump('navigation wired');

        $this->command?->info(sprintf('%d navigation item(s) added.', $added));
    }

    /**
     * Republish the header and footer, because a menu change alone does not reach the page.
     *
     * **The resolved menu tree is baked into each section's published snapshot**, not read live on
     * every request — that is what makes a public page one indexed read instead of a walk down a
     * menu table. The cost is that inserting a `menu_items` row changes nothing a visitor sees:
     * the snapshot still holds the tree as it was when somebody last pressed Publish.
     *
     * Bumping the cache version is not enough either. That only discards the stored HTML; the next
     * render rebuilds it from the same stale snapshot, so the nav comes back exactly as it was and
     * the change looks like it silently failed.
     *
     * Eight items were added on the first run here and not one appeared, which is how this was
     * found.
     */
    private function republishShell(ContentPublisher $publisher): void
    {
        foreach ([SectionPlacement::GlobalHeader, SectionPlacement::GlobalFooter] as $placement) {
            $section = WebsiteSection::query()->where('placement', $placement->value)->first();

            if ($section !== null) {
                $publisher->publish($section);
            }
        }

        $this->command?->line('  · republished the header and footer so the new menu tree is in their snapshots.');
    }

    /**
     * The header's Contact item, created as an anchor to a section that was never placed.
     *
     * Only touched while it is still disabled and still an anchor: once somebody has enabled it, or
     * changed what it points at, it is theirs.
     */
    private function repointContactItem(): void
    {
        $updated = DB::table('menu_items')
            ->where('link_type', MenuItemLinkType::SectionAnchor->value)
            ->where('anchor', 'contact')
            ->where('is_enabled', false)
            ->update([
                'link_type' => MenuItemLinkType::Route->value,
                'route_name' => 'site.contact.index',
                'anchor' => null,
                'is_enabled' => true,
                'updated_at' => Carbon::now(),
            ]);

        if ($updated > 0) {
            $this->command?->line('  · header: the Contact item pointed at a #contact section that was never placed — repointed at the contact page.');
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: bool, 4: list<string>}>  $items  kind, target, label, include, aliases
     */
    private function fill(MenuLocation $location, array $items): int
    {
        $menuId = DB::table('menus')->where('location', $location->value)->value('id');

        if ($menuId === null) {
            return 0;
        }

        $now = Carbon::now();
        $added = 0;

        $sort = (int) DB::table('menu_items')->where('menu_id', $menuId)->max('sort_order');

        foreach ($items as [$kind, $target, $label, $include, $aliases]) {
            if (! $include) {
                continue;
            }

            $row = match ($kind) {
                'route' => ['link_type' => MenuItemLinkType::Route->value, 'route_name' => $target],
                'url' => ['link_type' => MenuItemLinkType::Url->value, 'url' => $target],
                default => ['link_type' => MenuItemLinkType::SectionAnchor->value, 'anchor' => $target],
            };

            // Matched on where it goes, never on what it is called — across every column a
            // destination can be written in, not only the one this row would fill.
            $taken = DB::table('menu_items')
                ->where('menu_id', $menuId)
                ->where(static function ($query) use ($target, $aliases): void {
                    foreach (array_merge([$target], $aliases) as $candidate) {
                        $query->orWhere('route_name', $candidate)
                            ->orWhere('url', $candidate)
                            ->orWhere('anchor', $candidate);
                    }
                })
                ->exists();

            if ($taken) {
                continue;
            }

            $sort += 10;

            DB::table('menu_items')->insert($row + [
                'menu_id' => $menuId,
                'label' => $label,
                'parent_id' => null,
                'depth' => 0,
                'sort_order' => $sort,
                'visibility' => MenuVisibility::All->value,
                'is_enabled' => true,
                'open_new_tab' => false,
                'rel_nofollow' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $added++;
        }

        return $added;
    }
}
