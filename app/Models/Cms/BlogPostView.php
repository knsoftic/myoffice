<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Models\Cms\Concerns\ForbidsDeletion;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Throwable;

/**
 * One de-duplicated, privacy-preserving blog view (phase-04 §2.17) — **append-only**.
 *
 * `visitor_hash` is `hash_hmac('sha256', ip.'|'.userAgent, APP_KEY)`; the raw IP is never stored.
 * `UNIQUE uq_blog_post_view_daily(blog_post_id, visitor_hash, viewed_on)` is the refresh-spam defence:
 * `BlogViewCounter::record()` inserts, treats the 1062 as "already counted", and increments
 * `blog_posts.views_count` only after a real insert (§6.7.1).
 *
 * Log category (decision D19): no `deleted_at`, no `updated_at`, no blameable pair, no activity logging
 * (a view is not an audit event). An Eloquent delete throws ({@see ForbidsDeletion}) and an Eloquent update
 * throws (below). Rows leave only two ways, neither of which fires model events:
 *
 *   · the daily `model:prune --model=BlogPostView` — `MassPrunable`, a single query-builder delete of rows
 *     older than `website.blog_view_prune_days` (default 90). `MassPrunable` rather than per-row
 *     `Prunable` precisely because the per-row variant calls `delete()`, which the guard refuses. Pruning
 *     never decrements `views_count`, which is the lifetime total (§12.1 R6);
 *   · the `cascadeOnDelete` of a force-deleted post (a soft-deleted post keeps its views, §6.7 invariant 7).
 *
 * @property int $id
 * @property int $blog_post_id
 * @property string $visitor_hash
 * @property Carbon $viewed_on
 * @property int|null $user_id
 * @property string|null $referrer_host
 * @property Carbon|null $created_at
 */
class BlogPostView extends Model
{
    use ForbidsDeletion;
    use MassPrunable;

    /** The retention default when `website.blog_view_prune_days` is unset (§5), and its bounds. */
    public const DEFAULT_PRUNE_DAYS = 90;

    public const MIN_PRUNE_DAYS = 7;

    public const MAX_PRUNE_DAYS = 730;

    /** A view is never edited. */
    public const UPDATED_AT = null;

    protected $table = 'blog_post_views';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'blog_post_id',
        'visitor_hash',
        'viewed_on',
        'user_id',
        'referrer_host',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'visitor_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blog_post_id' => 'integer',
            'viewed_on' => 'date',
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (Model $model): never {
            throw new LogicException(sprintf(
                'BlogPostView #%s is an append-only record (decision D19) and cannot be edited.',
                (string) $model->getKey()
            ));
        });
    }

    public function moduleSlug(): string
    {
        return 'blog_posts';
    }

    /**
     * The rows the daily prune removes: older than the configured retention window, measured on the
     * dedupe bucket `viewed_on`.
     *
     * @return Builder<BlogPostView>
     */
    public function prunable(): Builder
    {
        return static::query()->where('viewed_on', '<', now()->subDays(self::retentionDays())->toDateString());
    }

    /**
     * `website.blog_view_prune_days`, clamped to its validated range (7-730) so a bad value can never
     * prune everything.
     */
    public static function retentionDays(): int
    {
        try {
            $days = app(SettingsRepository::class)->get('website.blog_view_prune_days', self::DEFAULT_PRUNE_DAYS);
        } catch (Throwable) {
            $days = self::DEFAULT_PRUNE_DAYS;
        }

        $days = is_numeric($days) ? (int) $days : self::DEFAULT_PRUNE_DAYS;

        return max(self::MIN_PRUNE_DAYS, min(self::MAX_PRUNE_DAYS, $days));
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<BlogPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'blog_post_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<BlogPostView>  $query
     * @return Builder<BlogPostView>
     */
    public function scopeForPost(Builder $query, BlogPost|int $post): Builder
    {
        return $query->where($query->qualifyColumn('blog_post_id'), $post instanceof BlogPost ? $post->getKey() : $post);
    }

    /**
     * Views whose dedupe bucket falls inside an inclusive `Y-m-d` range (the per-day chart).
     *
     * @param  Builder<BlogPostView>  $query
     * @return Builder<BlogPostView>
     */
    public function scopeViewedBetween(Builder $query, string $fromDate, string $toDate): Builder
    {
        return $query->whereBetween($query->qualifyColumn('viewed_on'), [$fromDate, $toDate]);
    }
}
