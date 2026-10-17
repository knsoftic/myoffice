<?php

declare(strict_types=1);

namespace App\Support\Cms;

use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;

/**
 * Whether an internal link on the public site leads to a page that is actually open, so a menu never
 * links to a 404.
 *
 * A public page can be closed two ways, both answered with a plain 404 by design:
 *   - its module is disabled — the route's `site_module:` middleware (`EnsureSiteModuleEnabled`);
 *   - its own website switch is off — the controller's `siteFlag()` check, e.g. `/fee-structure` is a
 *     404 until `website.fee_structure_page_enabled` is on, and that switch defaults to off.
 *
 * Menus are frozen into the header and footer snapshots at publish time, and a menu item is just a URL,
 * so nothing stopped the header saying "Fees" while the page behind it was switched off — which is what
 * the live header did. `x-site.menu` asks this class per request (like it already asks whether a CMS
 * page is still published) and drops the link instead.
 *
 * External links, anchors and paths no route matches are left alone: this only removes links it can
 * prove are dead.
 */
final class PublicPageAvailability
{
    /**
     * Route name => [setting, default]: the switch each controller reads, with the default it reads it
     * with. A page with a switch must be listed here, or its menu link survives the switch going off
     * (`PublicPageAvailabilityTest` turns each one off and expects the page itself to 404).
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    public const SWITCHES = [
        'site.fees.index' => ['website.fee_structure_page_enabled', false],
        'site.quote.index' => ['website.quote_page_enabled', false],
        'site.careers.index' => ['website.careers_enabled', true],
        'site.careers.show' => ['website.careers_enabled', true],
        'site.events.index' => ['website.events_page_enabled', false],
        'site.events.show' => ['website.events_page_enabled', false],
        'site.reviews.index' => ['website.reviews_page_enabled', false],
        'site.team.index' => ['website.team_page_enabled', true],
        'site.timetable.index' => ['website.timetable_page_enabled', false],
        'site.trainers.index' => ['website.trainers_page_enabled', false],
        'site.portfolio.show' => ['website.portfolio_detail_enabled', true],
    ];

    /**
     * False only when the URL is a page of this site that is switched off or whose module is disabled.
     * Memoised per request.
     */
    public static function isOpen(?string $url, ?Request $request = null): bool
    {
        if ($url === null || $url === '' || str_starts_with($url, '#')) {
            return true;
        }

        $request ??= request();

        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && strcasecmp($host, $request->getHost()) !== 0) {
            return true; // somebody else's site
        }

        if (preg_match('~^(https?://|/)~i', $url) !== 1) {
            return true; // mailto:, tel:
        }

        $path = '/'.ltrim((string) (parse_url($url, PHP_URL_PATH) ?? '/'), '/');
        $key = 'site.page_open.'.$path;

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, self::pathIsOpen($path));
        }

        return (bool) $request->attributes->get($key);
    }

    private static function pathIsOpen(string $path): bool
    {
        try {
            $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            return true; // no route: not ours to judge
        }

        if (! $route instanceof Route) {
            return true;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'site_module:')) {
                continue;
            }

            foreach (explode(',', substr($middleware, strlen('site_module:'))) as $module) {
                $module = trim($module);

                if ($module !== '' && ! Modules::enabled($module)) {
                    return false;
                }
            }
        }

        $switch = self::SWITCHES[(string) $route->getName()] ?? null;

        return $switch === null || self::switchedOn($switch[0], $switch[1]);
    }

    /**
     * Read a switch the way `ComposesContentPages::siteFlag()` does.
     */
    private static function switchedOn(string $key, bool $default): bool
    {
        $value = setting($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
