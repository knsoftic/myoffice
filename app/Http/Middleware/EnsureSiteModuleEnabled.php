<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Alias: `site_module` — decision **D26** (phase-03 INV-15, §9 "Module gating"; phase-04 §7.3).
 *
 *   Route::get('services', …)->middleware('site_module:services');
 *   Route::get('blog/{blogPost:slug}', …)->middleware('site_module:blog_posts');
 *
 * The public twin of Phase 1's `module` middleware. The resolution is identical — `Modules::enabled()`,
 * which answers true for core modules and for slugs the registry does not declare — and only the failure
 * differs: a disabled content module answers a **plain 404**, never a 403. A 403 tells a visitor (and a
 * crawler) that the feature exists and is merely closed to them; a 404 leaves no trace of it at all.
 *
 * What this middleware must never become:
 *
 *   · **a gate on the section-driven site.** It is attached only to a content module's *own* public
 *     routes (`/services`, `/blog`, `/careers`, ...). The home page, `/{slug}` pages, `robots.txt`,
 *     `sitemap.xml` and the preview routes never carry it, so disabling `website_sections` can never
 *     take the public site down (INV-15);
 *   · **an authorisation check.** No public route carries `can:` — the public site is the published
 *     output, not a module's UI;
 *   · **a leak.** The exception carries no message, so neither the error page nor a debug renderer can
 *     name the module that was switched off.
 */
final class EnsureSiteModuleEnabled
{
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        foreach ($modules as $module) {
            $slug = trim($module);

            if ($slug === '') {
                continue;
            }

            if (! Modules::enabled($slug)) {
                // No message, no header naming the module: a disabled feature simply does not exist.
                throw new NotFoundHttpException;
            }
        }

        return $next($request);
    }
}
