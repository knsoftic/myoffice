<?php

declare(strict_types=1);

use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\PreviewController;
use App\Http\Controllers\Site\RobotsController;
use App\Http\Controllers\Site\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public website (phase-03 §7.6)
|--------------------------------------------------------------------------
|
| No public route carries `module:` or `can:` (INV-15): the site is the published output, not a
| module's UI, and disabling website_sections never takes it down. A later content module gates its
| OWN public routes with `site_module` (D26), never these.
|
| `site` is the one maintenance gate (EnsurePublicSiteAvailable, also aliased `public_site` since
| phase-02). It is on every public GET route except robots.txt, which must stay readable while the
| site is closed ([D-W3-13]).
|
| Preview authorisation (a valid signature, or a session holding pages.view / website_sections.view;
| a bad signature is 403, none is 404) lives in Site\PreviewController, so no `signed` or `auth`
| middleware is attached here.
|
| `site.page` (/{slug}) is NOT declared here: routes/site-pages.php is loaded by bootstrap/app.php
| after every panel file, because routes match in registration order. A later phase's public route
| (/services, /courses, ...) is declared in this file, before that catch-all.
|
| Authentication (login, password reset, email verification) and the /account screens live in
| routes/auth.php, required at the bottom of this file.
|
*/

// The phase-03 §7.6 stacks.
$pageStack = ['site', 'site.preview', 'site.cache'];
$feedStack = ['site', 'site.cache'];
$previewStack = ['site', 'site.preview'];

Route::get('robots.txt', RobotsController::class)->name('site.robots');

Route::get('/', HomeController::class)
    ->middleware($pageStack)
    ->name('site.home');

Route::middleware($feedStack)->group(function (): void {
    Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('site.sitemap');

    Route::get('sitemap-{index}.xml', [SitemapController::class, 'chunk'])
        ->whereNumber('index')
        ->name('site.sitemap.chunk');
});

Route::prefix('preview')
    ->name('site.preview.')
    ->middleware($previewStack)
    ->group(function (): void {
        Route::get('page/{page}', [PreviewController::class, 'page'])->whereNumber('page')->name('page');
        Route::get('section/{section}', [PreviewController::class, 'section'])->whereNumber('section')->name('section');
    });

require __DIR__.'/auth.php';
