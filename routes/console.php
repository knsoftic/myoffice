<?php

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\BlogPostView;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\ContentPublisher;
use App\Services\Cms\MediaService;
use App\Services\Cms\SitemapGenerator;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Website CMS (phase-03 §10.4)
|--------------------------------------------------------------------------
| Times are in app.schedule_timezone, which falls back to app.timezone = UTC (D61). The contract's
| night-time slots are meant in business hours' terms: see integration step H.2.
|
| Not registered yet, because a command that silently does nothing is worse than none:
| cms:warm-cache (needs WarmPublicPageCache), cms:prune-revisions (needs PruneCmsRevisions) and
| cms:check-links (needs BrokenMenuLinksDetected). Add them at 03:00, weekly Sunday 03:30 and 05:00
| once those classes exist.
*/

Artisan::command('cms:publish-scheduled', function (ContentPublisher $publisher) {
    $this->info(count($publisher->publishDue()).' scheduled page(s) published.');
})->purpose('Promote scheduled pages whose publish time has come (phase-03 §10.4)');

Artisan::command('cms:sitemap-generate', function (SitemapGenerator $sitemap) {
    $generation = $sitemap->regenerate('scheduled');
    $this->info(sprintf('Sitemap: %d URLs, status %s.', (int) $generation->url_count, (string) $generation->status));
})->purpose('Rebuild sitemap.xml (phase-03 §10.4)');

Artisan::command('cms:media-recount', function (MediaService $media) {
    $media->recountUsage();
    $this->info('Media usage counts refreshed.');
})->purpose('Recount media_assets.usage_count from every reference (phase-03 §10.4)');

/*
| The page cache's garbage collector (review round 2, phase-03 §6.7 / D22). A publish never deletes the
| pages it invalidates, and Laravel's database store only removes an expired row when that same key is
| read again — which an orphaned page never is. Without this the `cache` table grows forever. Only rows
| that have already expired are removed; the version stamp and anything stored `forever` are untouched,
| and a store that expires entries itself (redis, file, array) has nothing to prune.
*/
Artisan::command('cms:cache-prune {--chunk=1000 : rows deleted per statement}', function () {
    $store = (string) config('cache.default');
    $config = (array) config('cache.stores.'.$store, []);

    if (($config['driver'] ?? null) !== 'database') {
        $this->info(sprintf('The [%s] cache store expires its own entries: nothing to prune.', $store));

        return 0;
    }

    $chunk = max(1, min(10_000, (int) $this->option('chunk')));
    $now = Carbon::now()->getTimestamp();
    $table = DB::connection($config['connection'] ?? null)->table((string) ($config['table'] ?? 'cache'));
    $deleted = 0;

    do {
        $batch = (clone $table)->where('expiration', '<=', $now)->limit($chunk)->delete();
        $deleted += $batch;
    } while ($batch === $chunk);

    $this->info(sprintf('%d expired cache row(s) pruned.', $deleted));

    return 0;
})->purpose('Delete expired rows from the database cache store, orphaned public pages included (phase-03 §6.7)');

Artisan::command('cms:verify-published-snapshots', function (ContentPublisher $publisher) {
    $problems = [];

    WebsiteSection::query()
        ->where('status', ContentStatus::Published->value)
        ->where('is_enabled', true)
        ->each(function (WebsiteSection $section) use ($publisher, &$problems): void {
            foreach ($publisher->verify($section) as $problem) {
                $problems[] = sprintf('Section #%d: %s', (int) $section->getKey(), $problem);
                $this->error(end($problems));
            }
        });

    if ($problems === []) {
        $this->info('Every live section has a valid published snapshot.');

        return 0;
    }

    // Nobody reads a scheduled command's console: the failure must reach the error log and its alerting.
    Log::critical('cms:verify-published-snapshots found live sections that cannot render as published.', [
        'count' => count($problems),
        'problems' => array_slice($problems, 0, 50),
    ]);

    return 1;
})->purpose('Assert every live section has a valid published snapshot and its media files exist (phase-03 §10.4)');

Schedule::command('cms:publish-scheduled')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('cms:sitemap-generate')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('cms:media-recount')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('cms:verify-published-snapshots')->dailyAt('04:30');
Schedule::command('cms:cache-prune')->hourly()->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Services, portfolio, blog, careers, inquiries (phase-04 §10.4)
|--------------------------------------------------------------------------
| The three commands are auto-discovered from app/Console/Commands. Times are UTC (D61).
*/

Schedule::command('blog:publish-scheduled')->everyMinute()->withoutOverlapping(5)->runInBackground();
Schedule::command('inquiries:route-pending')->hourly()->withoutOverlapping(10);
Schedule::command('careers:close-expired')->dailyAt('00:10');
Schedule::command('model:prune', ['--model' => [BlogPostView::class]])->dailyAt('02:30');

/*
|--------------------------------------------------------------------------
| CRM (phase-05 §10.5)
|--------------------------------------------------------------------------
| The six commands live in app/Console/Commands/Crm and are auto-discovered. crm:follow-up-reminders stamps
| reminder_sent_at under a row lock, so an overlapping run cannot double-send; withoutOverlapping() is the second net.
*/
Schedule::command('crm:follow-up-reminders')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('crm:follow-ups-mark-missed')->hourly()->withoutOverlapping();
Schedule::command('crm:record-captured-referrals')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('crm:import-pending-inquiries')->everyTenMinutes()->withoutOverlapping();
Schedule::command('crm:prune-imports')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('crm:stale-lead-digest')->dailyAt('09:15')->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Delivery (phase-06 §10.4)
|--------------------------------------------------------------------------
| The commands live in app/Console/Commands/Project and are auto-discovered.
|
| projects:verify-constraints is the one that matters most and does the least: it asserts that every
| generated column, CHECK, unique index and trigger of §2.14 is still there. Three Phase 6 guarantees
| rest on database features Laravel cannot express, and a guarantee nobody checks is one that quietly
| stops holding after a restored dump or a well-meant ALTER (INV-P17).
|
| projects:recalculate-progress is drift repair, and it *reports* what it corrects: every interactive
| change already recalculates inline, so a row it has to fix means some path skipped the cascade.
*/
Schedule::command('projects:auto-stop-timers')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('projects:verify-constraints')->dailyAt('02:10')->withoutOverlapping();
Schedule::command('attachments:prune-deleted')->dailyAt('02:40')->withoutOverlapping();
Schedule::command('projects:recalculate-progress')->dailyAt('01:00')->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| The commission engine (phase-10-12 §10.4, spine §10.4)
|--------------------------------------------------------------------------
| The commands live in app/Console/Commands/Collaborator and are auto-discovered.
|
| commissions:sweep is the recovery path, and it exists because the queue is not a guarantee: a worker
| SIGKILLed between the commit and the stamp leaves a receipt at `queued` with money already in the
| drawer and no commission against it, and nothing else in the system would notice. It **never touches
| a `skipped` row** ([D-IMP-4]) — a skip is a decision the engine recorded with a reason, and undoing
| one is `commissions:evaluate`, which is permissioned, audited, and deliberately not scheduled.
|
| financial:verify-constraints does the least and matters most. Every invariant here is enforced twice,
| once by a service and once by an index, a CHECK, a generated column or a trigger. The second one is
| what holds when somebody writes SQL by hand or restores a dump from a differently configured server —
| and its failure mode is silent, because a dropped index does not raise anything, it simply stops
| refusing. The first symptom would be a duplicate commission six weeks later.
|
| Times are in app.schedule_timezone, which falls back to app.timezone = UTC (D61).
*/
Schedule::command('commissions:sweep')->everyTenMinutes()->withoutOverlapping(10);
Schedule::command('commission-rules:activate')->dailyAt('00:05')->withoutOverlapping();
Schedule::command('commissions:release-held')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('financial:verify-constraints', ['--quiet-when-ok'])->dailyAt('02:00')->withoutOverlapping();

/*
| collaborators:reconcile-wallets is the other half of that argument, and the one §50 actually asks for:
| every wallet figure must be re-derivable by summing the ledger, and this is the dated record that it
| was re-derived and agreed. It writes a row whether the answer was yes or no, because a table holding
| only the bad days proves nothing about the good ones.
|
| It runs **without --repair**. A scheduled auto-repair would erase the evidence of whatever caused the
| drift, and the cause is the interesting part; the screens fall back to the derived figures behind a
| banner and a human presses Recalculate. Structural failures are never repaired by anything.
*/
Schedule::command('collaborators:reconcile-wallets', ['--run-type=scheduled'])
    ->dailyAt('01:30')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Finance (phase-13 §10.5)
|--------------------------------------------------------------------------
| The command lives in app/Console/Commands/Finance and is auto-discovered.
|
| invoices:mark-overdue exists only to let the clock move a status. It computes nothing of its own —
| it calls the same InvoiceService::recomputeStatus() every payment path calls — and it runs over
| `sent`, `partial` **and** `overdue`, so the one call that moves a row into overdue also moves it back
| out when somebody extends the due date. A job that only marked things overdue would leave a corrected
| invoice wearing a red badge until the next payment happened to touch it.
|
| It touches no payment, no reversal, no ledger row and no wallet: the three amount columns are moved
| only by the events that actually move cash.
|
| Times are in app.schedule_timezone, which falls back to app.timezone = UTC (D61).
*/
Schedule::command('invoices:mark-overdue')->dailyAt('01:05')->withoutOverlapping();

/*
| invoices:reconcile-balances is the other half of D40's argument. The three amount columns are a cache
| of one query over `project_payments`, and a cache is only trustworthy while something checks it - the
| failure mode is silent, because nothing raises when a column drifts. It reports and **does not
| repair**: drift is evidence of something that went wrong upstream, and quietly rewriting the column
| erases the evidence while leaving the cause in place.
|
| expenses:flag-stale-approvals decides nothing. An auto-approval after a fortnight would turn the
| approval step into a delay, and an auto-rejection would decide against an employee on a timetable; the
| whole output is a list sent to the people who can actually act on it.
*/
Schedule::command('invoices:reconcile-balances', ['--quiet-when-ok'])->dailyAt('02:10')->withoutOverlapping();
Schedule::command('expenses:flag-stale-approvals')->weeklyOn(1, '08:00')->withoutOverlapping();
