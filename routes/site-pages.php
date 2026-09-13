<?php

declare(strict_types=1);

use App\Http\Controllers\Site\PageController;
use App\Services\Cms\PageService;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The public page catch-all (phase-03 §7.6 `site.page`) — MUST be the last route file loaded
|--------------------------------------------------------------------------
|
| bootstrap/app.php loads this after routes/web.php (and auth.php) and after the five panel files.
|
| The negative lookahead makes a reserved first segment not match at all. Without it GET /register
| would reach PageController and POST /register would answer 405 instead of 404 (a GET route would
| exist for that URI), which SmokeTest refuses. PageController also 404s a reserved slug, so a later
| phase's /courses can never be shadowed even if this pattern is loosened.
|
| `defined()` autoloads PageService: while that class is absent the list is empty (nothing reserved,
| and every /{slug} request fails in the container) instead of the whole application failing to boot.
| Integration step A refuses to start without it.
|
*/

$reserved = implode('|', array_map(
    static fn (string $slug): string => preg_quote($slug, '#'),
    array_values(array_filter(
        defined(PageService::class.'::RESERVED_SLUGS') ? PageService::RESERVED_SLUGS : [],
        static fn (mixed $slug): bool => is_string($slug) && preg_match('/^[a-z0-9-]+$/', $slug) === 1,
    )),
));

Route::get('{slug}', PageController::class)
    ->where('slug', ($reserved === '' ? '' : '(?!(?:'.$reserved.')$)').'[a-z0-9](?:[a-z0-9-]*[a-z0-9])?')
    // phase-03 §7.6 stack.
    ->middleware(['site', 'site.preview', 'site.cache'])
    ->name('site.page');
