<?php

declare(strict_types=1);

use App\Http\Controllers\Site\AdmissionController as SiteAdmissionController;
use App\Http\Controllers\Site\VerificationController;
use App\Http\Controllers\Site\BlogController;
use App\Http\Controllers\Site\CareerController;
use App\Http\Controllers\Site\ContactController;
use App\Http\Controllers\Site\CourseController as SiteCourseController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\PortfolioController;
use App\Http\Controllers\Site\PreviewController;
use App\Http\Controllers\Site\PublicInvoiceController;
use App\Http\Controllers\Site\ReferralController;
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
//
// phase-08-09 §6.4 puts `capture_referral` **first** on every stack a person can land on, ahead of
// `site.cache`: an anonymous visitor is served a stored copy of the page, and a capture that ran after
// the cache middleware would never run at all for exactly the visitors a referral link brings.
// `$feedStack` is deliberately without it — nobody clicks a partner's flyer into a sitemap.
$pageStack = ['capture_referral', 'site', 'site.preview', 'site.cache'];
$feedStack = ['site', 'site.cache'];
$previewStack = ['capture_referral', 'site', 'site.preview'];

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

Route::middleware(['capture_referral', 'site', 'site_module:services', 'site.cache'])->group(function (): void {
    Route::get('services', [ServiceController::class, 'index'])->name('site.services.index');
    Route::get('services/{service:slug}', [ServiceController::class, 'show'])->name('site.services.show');
});

Route::middleware(['capture_referral', 'site', 'site_module:portfolio', 'site.cache'])->group(function (): void {
    Route::get('portfolio', [PortfolioController::class, 'index'])->name('site.portfolio.index');
    Route::get('portfolio/{portfolioItem:slug}', [PortfolioController::class, 'show'])->name('site.portfolio.show');
});

Route::get('team', [TeamController::class, 'index'])->middleware(['capture_referral', 'site', 'site_module:team', 'site.cache'])->name('site.team.index');

Route::middleware(['capture_referral', 'site', 'site_module:blog_posts'])->group(function (): void {
    Route::get('blog', [BlogController::class, 'index'])->middleware('site.cache')->name('site.blog.index');
    Route::get('blog/category/{blogCategory:slug}', [BlogController::class, 'category'])->middleware('site.cache')->name('site.blog.category');
    Route::get('blog/tag/{blogTag:slug}', [BlogController::class, 'tag'])->middleware('site.cache')->name('site.blog.tag');
    Route::get('blog/{blogPost:slug}', [BlogController::class, 'show'])->name('site.blog.show');
});

Route::get('preview/blog/{blogPost}', [BlogController::class, 'preview'])->whereNumber('blogPost')->middleware(['site', 'auth', 'active'])->name('site.blog.preview');

Route::middleware(['capture_referral', 'site', 'site_module:jobs'])->group(function (): void {
    Route::get('careers', [CareerController::class, 'index'])->middleware('site.cache')->name('site.careers.index');
    Route::get('careers/{jobOpening:slug}', [CareerController::class, 'show'])->name('site.careers.show');
    Route::post('careers/{jobOpening:slug}/apply', [CareerController::class, 'apply'])->middleware('throttle:public-apply')->name('site.careers.apply');
});

Route::get('contact', [ContactController::class, 'index'])->middleware(['capture_referral', 'site'])->name('site.contact.index');
Route::post('contact', [ContactController::class, 'store'])->middleware(['site', 'throttle:public-contact'])->name('site.contact.store');

/*
|--------------------------------------------------------------------------
| phase-08-09 §7.6 — is this referral code real?
|--------------------------------------------------------------------------
| Answers {valid, code, collaborator_name?} and nothing else. A dead code and a suspended partner's
| code answer identically, so the endpoint cannot be used to find out who has been suspended, and the
| throttle is there because a cheap yes/no over a guessable key space is what an enumeration script
| wants. No `site` gate: a form that is still reachable while the site is closed must still be able to
| check a code it is about to submit.
*/
Route::post('referral/validate', [ReferralController::class, 'validateCode'])
    ->middleware('throttle:10,1')
    ->name('site.referral.validate');

/*
|--------------------------------------------------------------------------
| phase-14-17 §7.10 — the public catalogue and course pages (§89–90)
|--------------------------------------------------------------------------
| Phase 3 owns the public shell and its three aliases; this reuses all three.
| `site.cache` is safe here because every one of these pages is the same for
| every visitor — the referral code they carry is rendered from the session by
| the layout, not baked into the cached body.
|
| A draft, an archived course and a course in a switched-off category are all
| 404s rather than 403s: a 403 would confirm that the slug somebody guessed is
| a real course.
*/
Route::middleware(['capture_referral', 'site', 'site_module:courses', 'site.preview', 'site.cache'])
    ->group(function (): void {
        Route::get('courses', [SiteCourseController::class, 'index'])->name('site.courses.index');
        Route::get('courses/category/{category}', [SiteCourseController::class, 'category'])->name('site.courses.category');
        Route::get('courses/{course}', [SiteCourseController::class, 'show'])->name('site.courses.show');
    });

// Outside `site.cache`: a cached page is HTML, and a file stream is not something to keep a copy of
// under a page key. The rule that decides whether this file exists for this visitor is the same one
// the landing page uses, and it is re-run per request.
Route::middleware(['capture_referral', 'site', 'site_module:courses', 'site.preview'])
    ->group(static function (): void {
        Route::get('courses/{course}/resource/{resource}', [SiteCourseController::class, 'resource'])
            ->whereNumber('resource')
            ->name('site.courses.resource');
    });

/*
|--------------------------------------------------------------------------
| phase-14-17 §7.10 — the public admission form (§67)
|--------------------------------------------------------------------------
| `site_module:` rather than `module:` — §7.10's table writes the latter, but
| INV-15 is explicit that a disabled content module answers a PLAIN 404 on a
| public route: a 403 tells a visitor and a crawler that the feature exists
| and is merely shut to them. Phase 14's course pages already use it.
|
| Never `site.cache`: the form carries a CSRF token, a one-time idempotency
| ULID and a referral field, and a cached copy of any of the three is a form
| that cannot be submitted or that attributes the wrong partner.
|
| `admission.open` renders the "admissions are closed" page with a 200 and a
| noindex rather than a 404 — the institute exists, it is simply not taking
| admissions today, and a 404 would tell a visitor the page was never real.
| A staff user holding `admissions.create` passes through and sees a ribbon
| naming the state, which is how somebody checks the form before opening it.
|
| The thank-you page is bound on `application_number` and is **signed**, so a
| number that is guessable by design cannot be walked.
*/
Route::middleware(['capture_referral', 'site', 'site_module:students', 'site.preview', 'admission.open'])
    ->group(static function (): void {
        Route::get('admission', [SiteAdmissionController::class, 'create'])->name('site.admission.create');
        Route::post('admission', [SiteAdmissionController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('site.admission.store');
    });

Route::get('admission/submitted/{application:application_number}', [SiteAdmissionController::class, 'submitted'])
    ->middleware(['signed', 'site'])
    ->name('site.admission.submitted');

/*
|--------------------------------------------------------------------------
| phase-14-17 §7.10 — the public course enquiry (§86)
|--------------------------------------------------------------------------
| POST only: the form itself is a block on the course page, and a GET here
| would be a second admission form nobody maintains.
*/
Route::post('course-inquiries', [SiteAdmissionController::class, 'storeInquiry'])
    ->middleware(['capture_referral', 'site', 'site_module:course_inquiries', 'throttle:5,1'])
    ->name('site.course-inquiries.store');

/*
|--------------------------------------------------------------------------
| phase-13 §7.8 — the signed, client-facing invoice link
|--------------------------------------------------------------------------
| Bound on `public_token` (40 random characters), with Laravel's signed-URL check carrying the expiry
| from `finance.invoice_public_link_days`, so a link dies of old age on its own. No `site` gate: an
| invoice is a document a client was sent, and closing the marketing site for maintenance must not
| take their copy of it away.
|
| Everything that could be a refusal is a 404 — a draft, a cancelled invoice, a rotated token, an
| expired signature, the setting switched off. A 403 would confirm to a stranger that something exists
| behind the link they guessed, and the point of the token is that they learn nothing.
|
| It reads one document: no payment, no comment, no upload, no login prompt, no other invoice.
*/
Route::middleware(['signed', 'invoice_link'])->group(function (): void {
    Route::get('invoices/{token}', [PublicInvoiceController::class, 'show'])
        ->middleware('throttle:30,1')
        ->name('site.invoices.view');

    Route::get('invoices/{token}/pdf', [PublicInvoiceController::class, 'pdf'])
        ->middleware('throttle:10,1')
        ->name('site.invoices.pdf');
});


/*
|--------------------------------------------------------------------------
| Public document verification - sec 84, phase-19-23 sec 6.14
|--------------------------------------------------------------------------
|
| The only unauthenticated write path in the institute: every attempt writes a
| certificate_verifications row, which is the rate limiter's evidence and sec 106's
| log of a public endpoint.
|
| The rate limit is INSIDE the service, not on the route, and deliberately: it has
| to log the throttled attempt, and a `throttle:` middleware rejects before any
| controller runs. The route-level throttle below is a second, looser net against
| something pathological - it is not the rate limit sec 84 asks for.
|
| One QR endpoint serves certificates and ID cards; the service tries the second
| when the first misses, so a student scanning their own card is not told their
| card does not exist because it is not a certificate.
|
*/
Route::middleware('throttle:120,1')->group(static function (): void {
    Route::get('verify', [VerificationController::class, 'index'])->name('site.verify.index');
    Route::post('verify', [VerificationController::class, 'submit'])->name('site.verify.submit');
    Route::get('verify/{code}', [VerificationController::class, 'show'])
        ->where('code', '[A-Za-z0-9 _-]{1,64}')
        ->name('site.verify.show');
});

require __DIR__.'/auth.php';
