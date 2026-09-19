<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Jobs\Cms\RecordBlogPostView;
use App\Models\Cms\BlogPost;
use App\Models\User;
use App\Support\DateRange;
use App\Support\Format;
use App\Support\SettingsRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Honest blog view counts that resist refresh spam (phase-04 §6.7.1).
 *
 * Four layers, in this order — the first that matches stops the count:
 *
 *   | # | layer            | rule                                                                             |
 *   |---|------------------|----------------------------------------------------------------------------------|
 *   | 1 | request shape    | GET only; no prefetch (`Purpose: prefetch`, `Sec-Purpose` containing `prefetch`,  |
 *   |   |                  | `X-Moz: prefetch`); a user agent that is present and not on the bot list          |
 *   | 2 | viewer           | a user who can update the post (its author or an editor) is never counted         |
 *   | 3 | session          | `session("bp_viewed.{id}")` already holds today's bucket → no database query      |
 *   | 4 | unique row       | insert (`blog_post_id`, `visitor_hash`, `viewed_on`); a duplicate counts nothing  |
 *
 * Invariants: `views_count` is incremented atomically **only** when layer 4 actually inserted, inside the
 * same transaction, so `views_count == count(blog_post_views)` holds (until retention pruning, which never
 * decrements the lifetime counter). `visitor_hash` is an HMAC of IP + user agent keyed by the application
 * key — the raw IP is never written. `viewed_on` is the current calendar day in the business timezone,
 * floored to the `website.blog_view_dedupe_minutes` bucket (1440 = one day; the column is a date, so a
 * window below a day behaves as a day and a longer one groups whole days). A duplicate insert is ignored
 * by the unique index, so two concurrent jobs for one reader insert one row and neither throws.
 *
 * The public post page calls `track()`: layers 1-3 run in the request, the session is marked, and the
 * insert happens in `RecordBlogPostView` dispatched after the response, so the reader never waits.
 */
final class BlogViewCounter
{
    /** The request headers layer 1 inspects (also captured by the job). */
    public const INSPECTED_HEADERS = ['Purpose', 'Sec-Purpose', 'X-Purpose', 'X-Moz'];

    public const SESSION_PREFIX = 'bp_viewed.';

    /** Substrings of a user agent that mark a crawler, monitor or link-preview fetcher. */
    public const DEFAULT_BOT_PATTERNS = [
        'bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'facebookexternalhit', 'facebookcatalog',
        'embedly', 'quora link preview', 'skypeuripreview', 'linkpreview',
        'curl', 'wget', 'httpie', 'python-requests', 'python-urllib', 'go-http-client', 'java/', 'okhttp',
        'libwww', 'headless', 'phantomjs', 'lighthouse', 'pagespeed', 'pingdom', 'uptime', 'monitor',
        'statuscake', 'scrapy', 'axios/', 'node-fetch',
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Run all four layers synchronously. True when a new view was counted.
     */
    public function record(BlogPost $post, Request $request): bool
    {
        if (! $this->isCountable($request->getMethod(), $this->headers($request), (string) $request->userAgent())) {
            return false;
        }

        $user = $request->user();

        if ($user instanceof User && $this->canUpdate($user, $post)) {
            return false;
        }

        $bucket = $this->bucket();

        if ($this->sessionSaw($request, $post, $bucket)) {
            return false;
        }

        $counted = $this->insert($post, $this->hash((string) $request->ip(), (string) $request->userAgent()), $bucket, $user, $request->headers->get('referer'));

        $this->markSession($request, $post, $bucket);

        return $counted;
    }

    /**
     * The public page's entry point: layers 1-3 now, the insert after the response. True when a
     * `RecordBlogPostView` job was dispatched.
     */
    public function track(BlogPost $post, Request $request): bool
    {
        if (! $this->isCountable($request->getMethod(), $this->headers($request), (string) $request->userAgent())) {
            return false;
        }

        $user = $request->user();

        if ($user instanceof User && $this->canUpdate($user, $post)) {
            return false;
        }

        $bucket = $this->bucket();

        if ($this->sessionSaw($request, $post, $bucket)) {
            return false;
        }

        $this->markSession($request, $post, $bucket);

        // The job is already built from the request: hand the instance to the bus. The static
        // `RecordBlogPostView::dispatchAfterResponse()` would pass it to the constructor as `$postId`.
        Bus::dispatchAfterResponse(RecordBlogPostView::fromRequest($post, $request));

        return true;
    }

    /**
     * The job's half: layers 1, 2 and 4 from values captured in the request (there is no session in a
     * queued job — layer 3 already ran in `track()`).
     *
     * @param  array<string, string>  $headers
     */
    public function recordCaptured(
        BlogPost $post,
        string $ip,
        string $userAgent,
        string $method = 'GET',
        array $headers = [],
        ?User $user = null,
        ?string $referrer = null,
    ): bool {
        if (! $this->isCountable($method, $headers, $userAgent)) {
            return false;
        }

        if ($user instanceof User && $this->canUpdate($user, $post)) {
            return false;
        }

        return $this->insert($post, $this->hash($ip, $userAgent), $this->bucket(), $user, $referrer);
    }

    public function visitorHash(Request $request): string
    {
        return $this->hash((string) $request->ip(), (string) $request->userAgent());
    }

    /**
     * Views per calendar day in the range, every day present (zero when nobody read it).
     *
     * @return array<string, int> ['2026-09-01' => 12, ...]
     */
    public function dailyTotals(BlogPost $post, DateRange $range): array
    {
        $totals = array_fill_keys($range->dateKeys(), 0);

        $query = $this->db->connection()->table('blog_post_views')
            ->where('blog_post_id', $post->getKey());

        $rows = $range->applyDates($query, 'viewed_on')
            ->groupBy('viewed_on')
            ->selectRaw('viewed_on, COUNT(*) as views')
            ->get();

        foreach ($rows as $row) {
            $day = substr((string) $row->viewed_on, 0, 10);

            if (array_key_exists($day, $totals)) {
                $totals[$day] = (int) $row->views;
            }
        }

        return $totals;
    }

    /*
    |--------------------------------------------------------------------------
    | Layers
    |--------------------------------------------------------------------------
    */

    /**
     * Layer 1.
     *
     * @param  array<string, string>  $headers
     */
    private function isCountable(string $method, array $headers, string $userAgent): bool
    {
        if (strtoupper($method) !== 'GET') {
            return false;
        }

        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), ['purpose', 'sec-purpose', 'x-purpose', 'x-moz'], true)
                && str_contains(strtolower((string) $value), 'prefetch')) {
                return false;
            }

            if (strtolower((string) $name) === 'sec-purpose' && str_contains(strtolower((string) $value), 'prerender')) {
                return false;
            }
        }

        $agent = strtolower(trim($userAgent));

        if ($agent === '') {
            return false;
        }

        foreach ($this->botPatterns() as $pattern) {
            if ($pattern !== '' && str_contains($agent, $pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Layer 2: the author, an editor, or anyone the policy lets update the post.
     */
    private function canUpdate(User $user, BlogPost $post): bool
    {
        try {
            if ($post->isAuthoredBy($user) || $user->can('blog_posts.approve')) {
                return true;
            }

            return Gate::getPolicyFor($post) !== null && Gate::forUser($user)->allows('update', $post);
        } catch (Throwable) {
            return false;
        }
    }

    private function sessionSaw(Request $request, BlogPost $post, string $bucket): bool
    {
        try {
            return $request->hasSession() && $request->session()->get(self::SESSION_PREFIX.$post->getKey()) === $bucket;
        } catch (Throwable) {
            return false;
        }
    }

    private function markSession(Request $request, BlogPost $post, string $bucket): void
    {
        try {
            if ($request->hasSession()) {
                $request->session()->put(self::SESSION_PREFIX.$post->getKey(), $bucket);
            }
        } catch (Throwable) {
            // A missing session only costs the fast path; layer 4 still de-duplicates.
        }
    }

    /**
     * Layer 4: the unique row, and the counter only when the row was new — one transaction.
     */
    private function insert(BlogPost $post, string $hash, string $bucket, ?User $user, ?string $referrer): bool
    {
        $connection = $this->db->connection();

        try {
            return (bool) $connection->transaction(function () use ($connection, $post, $hash, $bucket, $user, $referrer): bool {
                $inserted = $connection->table('blog_post_views')->insertOrIgnore([
                    'blog_post_id' => (int) $post->getKey(),
                    'visitor_hash' => $hash,
                    'viewed_on' => $bucket,
                    'user_id' => $user instanceof User ? (int) $user->getKey() : null,
                    'referrer_host' => $this->referrerHost($referrer),
                    'created_at' => Carbon::now(),
                ]);

                if ($inserted < 1) {
                    return false;
                }

                $connection->table('blog_posts')->where('id', $post->getKey())->increment('views_count');

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function hash(string $ip, string $userAgent): string
    {
        return hash_hmac('sha256', $ip.'|'.$userAgent, (string) config('app.key'));
    }

    /**
     * Today in the business timezone, floored to the dedupe window (in whole days).
     */
    private function bucket(): string
    {
        $minutes = $this->settings->get('website.blog_view_dedupe_minutes', 1440);
        $minutes = is_numeric($minutes) ? max(5, min(10080, (int) $minutes)) : 1440;
        $days = max(1, intdiv($minutes, 1440));

        $today = Carbon::now(Format::timezone())->startOfDay();

        if ($days === 1) {
            return $today->toDateString();
        }

        // Whole local days since 1970-01-01, floored to a multiple of the window.
        $dayNumber = intdiv(Carbon::createFromFormat('!Y-m-d', $today->toDateString(), 'UTC')->getTimestamp(), 86_400);

        return Carbon::createFromTimestampUTC(($dayNumber - ($dayNumber % $days)) * 86_400)->toDateString();
    }

    private function referrerHost(?string $referrer): ?string
    {
        $host = strtolower((string) parse_url(trim((string) $referrer), PHP_URL_HOST));

        return $host === '' ? null : mb_substr($host, 0, 120);
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach (self::INSPECTED_HEADERS as $name) {
            $value = $request->headers->get($name);

            if (is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    private function botPatterns(): array
    {
        $configured = config('cms.bot_user_agents');
        $patterns = is_array($configured) && $configured !== [] ? $configured : self::DEFAULT_BOT_PATTERNS;

        return array_values(array_filter(array_map(static fn ($pattern): string => strtolower(trim((string) $pattern)), $patterns)));
    }
}
