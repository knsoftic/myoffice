<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Models\Cms\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The SEO manager addresses a target as a string (phase-03 §7.5): `page:{id}` for a `Page` row, or
 * `route:{name}` for a named route with no row of its own (`route:site.home`). The one store is
 * `seo_meta` (D23), addressed by morph or by `route_key`.
 *
 * A page target must be a live page; a route target must be a registered route or already have a
 * `seo_meta` row. Later-phase model targets are edited from their own entity forms, not from here.
 */
trait ResolvesSeoTarget
{
    /** The accepted target string. */
    public const TARGET_PATTERN = '/^(page:[1-9]\d{0,18}|route:[A-Za-z0-9_.\-]{1,100})$/';

    /**
     * The target a string names: the `Page`, the route key, or null when it resolves to nothing.
     */
    protected function resolveSeoTarget(mixed $target): Page|string|null
    {
        if (! is_string($target) || preg_match(self::TARGET_PATTERN, $target) !== 1) {
            return null;
        }

        [$type, $value] = explode(':', $target, 2);

        if ($type === 'page') {
            return Page::query()->find((int) $value);
        }

        if (Route::has($value) || DB::table('seo_meta')->where('route_key', $value)->exists()) {
            return $value;
        }

        return null;
    }
}
