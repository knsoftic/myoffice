<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\Concerns\OrdersBySortOrder;
use App\Models\Cms\Concerns\SearchesContent;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One row of the public events and announcements board (`events`).
 *
 * **Status.** `status` casts the shared {@see ContentStatus} — there is no `EventStatus`, and there must
 * never be one: draft, scheduled, published and archived already mean here exactly what they mean for a
 * blog post, and a second four-case enum spelling the same four words is how two screens end up
 * disagreeing about what "scheduled" allows. `published_at` carries the same double duty as the blog's:
 * the future go-live moment while `scheduled`, the first live moment once `published`, and it is never
 * rewritten by a re-publish.
 *
 * **An announcement is an event with no end.** `ends_at` nullable does the work a `type` column would
 * have done badly, so {@see effectiveEnd()} — `ends_at` if there is one, otherwise `starts_at` — is the
 * single moment every "is it over?" question asks about.
 *
 * **Publishing is not editing.** Nothing here flips `status`, `published_at` or `is_featured` on its own:
 * those three are written only by the `events.change_status` endpoints, never by a save. See
 * {@see \App\Policies\Cms\EventPolicy}.
 *
 * **The cover image is a `media_assets` row** (D24: the CMS media library is the website's only
 * uploader), never a path column — so the only image field is the foreign key `cover_media_id`.
 *
 * Soft-deleted, not append-only: an event is mutable content an editor revises and withdraws, which is
 * the ordinary case D19 leaves with `deleted_at`.
 *
 * Four CHECK constraints stand under this table (`chk_events_ends_after_starts`,
 * `chk_events_online_has_url`, `chk_events_has_a_where`, `chk_events_capacity`). They are the floor, not
 * the validation: {@see \App\Http\Requests\Cms\StoreEventRequest} mirrors all four so the editor gets a
 * message on the field instead of a 500 from the driver.
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $summary
 * @property string|null $description
 * @property int|null $cover_media_id
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_all_day
 * @property string|null $location
 * @property bool $is_online
 * @property string|null $meeting_url
 * @property string|null $registration_url
 * @property int|null $capacity
 * @property ContentStatus $status
 * @property Carbon|null $published_at
 * @property bool $is_featured
 * @property int $sort_order
 * @property Carbon|null $deleted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Event extends Model
{
    use Blameable;
    use HasSlug;
    use LogsActivityWithContext;
    use OrdersBySortOrder;
    use SearchesContent;
    use SoftDeletes;

    /** `events.slug` is `string(200)`, like the blog's. */
    public const SLUG_MAX_LENGTH = 200;

    /** `events.description` is `longText`; the form request stops well short of the column. */
    public const MAX_DESCRIPTION_LENGTH = 1000000;

    /** `events.capacity` is `unsignedInteger` — and the CHECK forbids zero. */
    public const MAX_CAPACITY = 4294967295;

    protected $table = 'events';

    /**
     * `status`, `published_at` and `is_featured` are deliberately absent: they are the
     * `events.change_status` endpoints' to write, and a fillable status is exactly how an
     * `events.edit`-only user publishes something by posting one extra field.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'slug',
        'summary',
        'description',
        'cover_media_id',
        'starts_at',
        'ends_at',
        'is_all_day',
        'location',
        'is_online',
        'meeting_url',
        'registration_url',
        'capacity',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'is_all_day' => false,
        'is_online' => false,
        'is_featured' => false,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cover_media_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_all_day' => 'boolean',
            'is_online' => 'boolean',
            'capacity' => 'integer',
            'status' => ContentStatus::class,
            'published_at' => 'datetime',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'events';
    }

    protected function activityModule(): ?string
    {
        return 'events';
    }

    /**
     * `status`, `published_at` and `is_featured` are not fillable, so the audit trail would never see a
     * publish if the logged set were left to default to the fillable columns.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return array_merge($this->getFillable(), ['status', 'published_at', 'is_featured']);
    }

    public function sluggableSource(): string
    {
        return (string) $this->title;
    }

    public function slugMaxLength(): int
    {
        return self::SLUG_MAX_LENGTH;
    }

    /**
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['title', 'summary', 'location'];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The moment the event is over: `ends_at` when it spans two moments, `starts_at` when it is one
     * (an announcement). Every "upcoming / past" question is asked about this, and nothing else.
     */
    public function effectiveEnd(): ?Carbon
    {
        $end = $this->ends_at ?? $this->starts_at;

        /*
        | **An all-day event is over at the end of its day, not at the start of it.**
        |
        | An all-day row stores midnight, because that is what "no clock" means in a datetime
        | column. Compared against `now()` that makes the institute's own open day "past" at
        | 00:01 on the morning it is happening — the page drops it into the archive hours before
        | anybody arrives. The bug is invisible in testing, because it only shows on the one day
        | the event matters.
        |
        | Fixed here rather than at each call site so `hasEnded()`, `isUpcoming()`,
        | `isInProgress()` and the two scopes cannot end up with different answers.
        */
        if ($end !== null && $this->is_all_day) {
            return $end->copy()->endOfDay();
        }

        return $end;
    }

    /**
     * The moment a row of this shape stops being "upcoming", as a SQL-comparable value.
     *
     * The scopes cannot call {@see effectiveEnd()} — it needs a loaded model and they are building
     * a query — so this is the same rule expressed once for them to share. An all-day row is
     * compared against the start of today; a timed row against this moment.
     */
    private static function windowBoundary(bool $allDay): Carbon
    {
        return $allDay ? Carbon::now()->startOfDay() : Carbon::now();
    }

    /**
     * Would the public site render this event right now? Both halves are required, exactly as for a blog
     * post: a `scheduled` row whose moment has passed stays private until something promotes it, so the
     * two sources of truth can never disagree.
     */
    public function isPubliclyVisible(): bool
    {
        return $this->status === ContentStatus::Published
            && $this->published_at !== null
            && ! $this->published_at->isFuture()
            && ! $this->trashed();
    }

    public function isScheduled(): bool
    {
        return $this->status === ContentStatus::Scheduled;
    }

    /**
     * Has this event ever been live? Drives the permalink warning on the slug field. A scheduled row
     * whose moment has not arrived has never been live.
     */
    public function hasEverBeenPublished(): bool
    {
        return $this->published_at !== null
            && ! $this->published_at->isFuture()
            && $this->status !== ContentStatus::Scheduled;
    }

    /**
     * Still to come (or still running).
     */
    public function isUpcoming(): bool
    {
        $end = $this->effectiveEnd();

        return $end !== null && ! $end->isPast();
    }

    public function hasEnded(): bool
    {
        $end = $this->effectiveEnd();

        return $end !== null && $end->isPast();
    }

    /**
     * Running right now — started, and not yet over.
     */
    public function isInProgress(): bool
    {
        $end = $this->effectiveEnd();

        return $this->starts_at !== null && ! $this->starts_at->isFuture() && $end !== null && ! $end->isPast();
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * The cover image — a media library asset (D24), never a path this table owns.
     *
     * @return BelongsTo<MediaAsset, $this>
     */
    public function coverImage(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'cover_media_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * The only filter the public site may use: published **and** `published_at <= now`.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ContentStatus::Published->value)
            ->whereNotNull($query->qualifyColumn('published_at'))
            ->where($query->qualifyColumn('published_at'), '<=', now());
    }

    /**
     * @param  Builder<Event>  $query
     * @param  ContentStatus|string|array<int, ContentStatus|string>  $status
     * @return Builder<Event>
     */
    public function scopeWithStatus(Builder $query, ContentStatus|string|array $status): Builder
    {
        $values = array_map(
            static fn (ContentStatus|string $value): string => $value instanceof ContentStatus ? $value->value : $value,
            is_array($status) ? $status : [$status]
        );

        return $query->whereIn($query->qualifyColumn('status'), $values);
    }

    /**
     * Scheduled events whose go-live moment has come — what a scheduler promotes, mirroring the blog's
     * `dueForPublishing`. Kept here so that when the command arrives it has nothing to re-derive.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeDueForPublishing(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ContentStatus::Scheduled->value)
            ->whereNotNull($query->qualifyColumn('published_at'))
            ->where($query->qualifyColumn('published_at'), '<=', now());
    }

    /**
     * Not over yet: it ends in the future, or — having no end — it starts in the future.
     *
     * Written as two branches rather than `COALESCE(ends_at, starts_at) >= now` on purpose: the second
     * branch can use `idx_events_starts`, which a function wrapped around the column cannot.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $this->applyWindow($query, upcoming: true);
    }

    /**
     * Over: it ended in the past, or — having no end — it happened in the past. The exact complement of
     * {@see scopeUpcoming()}, so the two tabs of the admin list always add up to the whole table.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopePast(Builder $query): Builder
    {
        return $this->applyWindow($query, upcoming: false);
    }

    /**
     * The one place "has this happened yet?" is expressed in SQL.
     *
     * Four cases, and the all-day pair is the reason this is not two lines. A row is measured
     * against {@see windowBoundary()}: **now** for a timed event, the **start of today** for an
     * all-day one — because an all-day row stores midnight, and comparing that to `now()` files
     * today's open day under "past" before breakfast.
     *
     * Within each of those, the end is `ends_at` when there is one and `starts_at` when there is
     * not. Written as branches rather than `COALESCE(ends_at, starts_at)` so the no-end branch can
     * still use `idx_events_starts`; a function wrapped around a column cannot.
     *
     * `upcoming` and `past` are exact complements — every comparison here is strict on one side
     * and inclusive on the other, and `NULL >= x` and `NULL < x` are both false, which is why the
     * null-end case is handled explicitly rather than left to the optimiser. So the two tabs of the
     * admin list always add up to the whole table.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function applyWindow(Builder $query, bool $upcoming): Builder
    {
        $starts = $query->qualifyColumn('starts_at');
        $ends = $query->qualifyColumn('ends_at');
        $allDayColumn = $query->qualifyColumn('is_all_day');

        $operator = $upcoming ? '>=' : '<';

        $timedBoundary = self::windowBoundary(false);
        $allDayBoundary = self::windowBoundary(true);

        $branch = static function (Builder $inner, bool $allDay, Carbon $boundary) use ($starts, $ends, $allDayColumn, $operator): void {
            $inner->where($allDayColumn, $allDay)
                ->where(static function (Builder $when) use ($starts, $ends, $boundary, $operator): void {
                    $when->where($ends, $operator, $boundary)
                        ->orWhere(static function (Builder $moment) use ($starts, $ends, $boundary, $operator): void {
                            $moment->whereNull($ends)->where($starts, $operator, $boundary);
                        });
                });
        };

        return $query->where(static function (Builder $outer) use ($branch, $timedBoundary, $allDayBoundary): void {
            $outer->where(static fn (Builder $timed): mixed => $branch($timed, false, $timedBoundary))
                ->orWhere(static fn (Builder $allDay): mixed => $branch($allDay, true, $allDayBoundary));
        });
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeFeatured(Builder $query, bool $featured = true): Builder
    {
        return $query->where($query->qualifyColumn('is_featured'), $featured);
    }

    /**
     * Online events, or — with `false` — the ones you have to travel to.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeOnline(Builder $query, bool $online = true): Builder
    {
        return $query->where($query->qualifyColumn('is_online'), $online);
    }

    /**
     * Events whose start falls inside a window — the admin list's date filter and any month view.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeStartingBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->when($from !== null, static fn (Builder $inner) => $inner->where($inner->qualifyColumn('starts_at'), '>=', $from))
            ->when($to !== null, static fn (Builder $inner) => $inner->where($inner->qualifyColumn('starts_at'), '<=', $to));
    }

    /**
     * The order a visitor expects on the board: what happens next, first.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeSoonestFirst(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('starts_at'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * The order an archive expects: what happened most recently, first.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeMostRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc($query->qualifyColumn('starts_at'))
            ->orderByDesc($query->qualifyColumn('id'));
    }
}
