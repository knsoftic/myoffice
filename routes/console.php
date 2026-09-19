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
