<?php

declare(strict_types=1);

use App\Http\Controllers\Site\BlogController;
use App\Http\Controllers\Site\CareerController;
use App\Http\Controllers\Site\ContactController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\PortfolioController;
use App\Http\Controllers\Site\PreviewController;
use App\Http\Controllers\Site\RobotsController;
use App\Http\Controllers\Site\ServiceController;
use App\Http\Controllers\Site\SitemapController;
use App\Http\Controllers\Site\TeamController;
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

/*
|--------------------------------------------------------------------------
| phase-04 §7.1 — services, portfolio, team, blog, careers, contact
|--------------------------------------------------------------------------
| `site` on every row; `site_module:<slug>` 404s a disabled module (D26) and sits before `site.cache`.
| No `can:` on any site.* route (phase-03 INV-15, CmsRouteContractTest): the blog preview authorises in
| the controller. `site.cache` is off the blog post (view counter), the careers detail + apply form and
| the contact page (per-request CSRF + SpamGuard token).
*/

Route::middleware(['site', 'site_module:services', 'site.cache'])->group(function (): void {
    Route::get('services', [ServiceController::class, 'index'])->name('site.services.index');
    Route::get('services/{service:slug}', [ServiceController::class, 'show'])->name('site.services.show');
});

Route::middleware(['site', 'site_module:portfolio', 'site.cache'])->group(function (): void {
    Route::get('portfolio', [PortfolioController::class, 'index'])->name('site.portfolio.index');
    Route::get('portfolio/{portfolioItem:slug}', [PortfolioController::class, 'show'])->name('site.portfolio.show');
});

Route::get('team', [TeamController::class, 'index'])->middleware(['site', 'site_module:team', 'site.cache'])->name('site.team.index');

Route::middleware(['site', 'site_module:blog_posts'])->group(function (): void {
    Route::get('blog', [BlogController::class, 'index'])->middleware('site.cache')->name('site.blog.index');
    Route::get('blog/category/{blogCategory:slug}', [BlogController::class, 'category'])->middleware('site.cache')->name('site.blog.category');
    Route::get('blog/tag/{blogTag:slug}', [BlogController::class, 'tag'])->middleware('site.cache')->name('site.blog.tag');
    Route::get('blog/{blogPost:slug}', [BlogController::class, 'show'])->name('site.blog.show');
});

Route::get('preview/blog/{blogPost}', [BlogController::class, 'preview'])->whereNumber('blogPost')->middleware(['site', 'auth', 'active'])->name('site.blog.preview');

Route::middleware(['site', 'site_module:jobs'])->group(function (): void {
    Route::get('careers', [CareerController::class, 'index'])->middleware('site.cache')->name('site.careers.index');
    Route::get('careers/{jobOpening:slug}', [CareerController::class, 'show'])->name('site.careers.show');
    Route::post('careers/{jobOpening:slug}/apply', [CareerController::class, 'apply'])->middleware('throttle:public-apply')->name('site.careers.apply');
});

Route::get('contact', [ContactController::class, 'index'])->middleware('site')->name('site.contact.index');
Route::post('contact', [ContactController::class, 'store'])->middleware(['site', 'throttle:public-contact'])->name('site.contact.store');

require __DIR__.'/auth.php';
