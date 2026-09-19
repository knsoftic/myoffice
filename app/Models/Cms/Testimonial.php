<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Contracts\Cms\Moderatable;
use App\Enums\ApprovalStatus;
use App\Enums\ContentSource;
use App\Enums\TestimonialType;
use App\Models\Cms\Concerns\OrdersBySortOrder;
use App\Models\Cms\Concerns\SearchesContent;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One client or student testimonial (phase-04 §2.10, requirement §14) — moderated.
 *
 * **Moderation state is not mass assignable.** `status`, `approved_by`, `approved_at` and
 * `rejection_reason` are written only by `ModerationService` (approve / reject / reset, §6.5), and
 * `is_featured` only by `ModerationService::toggleFeatured()`, which refuses anything not `approved`.
 * Services set them with `forceFill()`; a request payload can never smuggle an approval in. The review
 * body is never modified by moderation — editing it is the audited `edit` action.
 *
 * Only `approved` is public (§9.2, `scopePublic()`), with no exceptions. `client_id` / `student_id` are
 * **deferred links** (§2.1); the site renders the `author_*` / `course_name` snapshots. `ip_address` is
 * captured only for non-admin sources, is hidden from serialisation and is kept out of the activity log.
 *
 * @property int $id
 * @property TestimonialType $type
 * @property string $author_name
 * @property int|null $author_photo_media_id
 * @property string|null $author_designation
 * @property string|null $author_company
 * @property string|null $course_name
 * @property int|null $rating
 * @property string $review
 * @property Carbon|null $review_date
 * @property int|null $client_id
 * @property int|null $student_id
 * @property ApprovalStatus $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejection_reason
 * @property bool $is_featured
 * @property int $sort_order
 * @property ContentSource $source
 * @property int|null $submitted_by_user_id
 * @property string|null $ip_address
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Testimonial extends Model implements Moderatable
{
    use Blameable;
    use LogsActivityWithContext;
    use OrdersBySortOrder;
    use SearchesContent;
    use SoftDeletes;

    /** Rating bounds (§2.10) and review length (§6.11). */
    public const MIN_RATING = 1;

    public const MAX_RATING = 5;

    public const MAX_REVIEW_LENGTH = 2000;

    protected $table = 'testimonials';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'author_name',
        'author_photo_media_id',
        'author_designation',
        'author_company',
        'course_name',
        'rating',
        'review',
        'review_date',
        'client_id',
        'student_id',
        'sort_order',
        'source',
        'submitted_by_user_id',
        'ip_address',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'ip_address',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'client',
        'status' => 'pending',
        'is_featured' => false,
        'sort_order' => 0,
        'source' => 'admin',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TestimonialType::class,
            'author_photo_media_id' => 'integer',
            'rating' => 'integer',
            'review_date' => 'date',
            'client_id' => 'integer',
            'student_id' => 'integer',
            'status' => ApprovalStatus::class,
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'source' => ContentSource::class,
            'submitted_by_user_id' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'testimonials';
    }

    protected function activityModule(): ?string
    {
        return 'testimonials';
    }

    /**
     * Every moderation move and every review-text edit is audited with old and new values (§10.5);
     * the submitter's IP is not.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return array_values(array_diff(
            [...$this->getFillable(), 'status', 'approved_by', 'approved_at', 'rejection_reason', 'is_featured'],
            ['ip_address'],
        ));
    }

    /**
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['author_name', 'author_company', 'course_name', 'review'];
    }

    /*
    |--------------------------------------------------------------------------
    | Moderatable
    |--------------------------------------------------------------------------
    */

    public function moderationStatus(): ApprovalStatus
    {
        return $this->status instanceof ApprovalStatus ? $this->status : ApprovalStatus::Pending;
    }

    public function isPubliclyVisible(): bool
    {
        return $this->moderationStatus()->isPublic() && ! $this->trashed();
    }

    public function moderationLabel(): string
    {
        return 'Testimonial from '.$this->author_name;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function authorPhoto(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'author_photo_media_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * The only filter the public site may use (§9.2): `status = approved`. Pending and rejected rows
     * are invisible, no exceptions.
     *
     * @param  Builder<Testimonial>  $query
     * @return Builder<Testimonial>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ApprovalStatus::Approved->value);
    }

    /**
     * @param  Builder<Testimonial>  $query
     * @param  ApprovalStatus|string|array<int, ApprovalStatus|string>  $status
     * @return Builder<Testimonial>
     */
    public function scopeWithStatus(Builder $query, ApprovalStatus|string|array $status): Builder
    {
        $values = array_map(
            static fn (ApprovalStatus|string $value): string => $value instanceof ApprovalStatus ? $value->value : $value,
            is_array($status) ? $status : [$status]
        );

        return $query->whereIn($query->qualifyColumn('status'), $values);
    }

    /**
     * @param  Builder<Testimonial>  $query
     * @return Builder<Testimonial>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ApprovalStatus::Pending->value);
    }

    /**
     * @param  Builder<Testimonial>  $query
     * @return Builder<Testimonial>
     */
    public function scopeFeatured(Builder $query, bool $featured = true): Builder
    {
        return $query->where($query->qualifyColumn('is_featured'), $featured);
    }

    /**
     * @param  Builder<Testimonial>  $query
     * @return Builder<Testimonial>
     */
    public function scopeOfType(Builder $query, TestimonialType|string $type): Builder
    {
        return $query->where($query->qualifyColumn('type'), $type instanceof TestimonialType ? $type->value : $type);
    }

    /**
     * @param  Builder<Testimonial>  $query
     * @return Builder<Testimonial>
     */
    public function scopeFromSource(Builder $query, ContentSource|string $source): Builder
    {
        return $query->where($query->qualifyColumn('source'), $source instanceof ContentSource ? $source->value : $source);
    }

    /**
     * @param  Builder<Testimonial>  $query
     * @return Builder<Testimonial>
     */
    public function scopeWithRating(Builder $query, int $rating): Builder
    {
        return $query->where($query->qualifyColumn('rating'), $rating);
    }
}
