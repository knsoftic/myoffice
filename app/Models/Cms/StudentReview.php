<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Contracts\Cms\Moderatable;
use App\Enums\ApprovalStatus;
use App\Enums\ContentSource;
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
 * One institute student review (phase-04 §2.11, requirement §91) — moderated exactly like a
 * testimonial, through the same `ModerationService` code path (`Moderatable`).
 *
 * **Moderation state is not mass assignable**: `status`, `approved_by`, `approved_at`,
 * `rejection_reason` and `is_featured` are written only by `ModerationService` via `forceFill()`.
 *
 * Only `approved` is public (§9.2). `student_id` (→ Phase 15) and `course_id` (→ Phase 14) are
 * **deferred links** (§2.1); Phase 14's course pages will consume `StudentReview::public()->forCourse()`
 * (§13). `video_url` is YouTube/Vimeo only (§6.9) and is rendered as an embed built from the parsed id.
 * `student_panel` / `submitted_by_user_id` exist so self-submission needs no migration later (§9.3).
 *
 * @property int $id
 * @property int|null $student_id
 * @property string $student_name
 * @property int|null $student_photo_media_id
 * @property int|null $course_id
 * @property string|null $course_name
 * @property int|null $rating
 * @property string $review
 * @property string|null $video_url
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
class StudentReview extends Model implements Moderatable
{
    use Blameable;
    use LogsActivityWithContext;
    use OrdersBySortOrder;
    use SearchesContent;
    use SoftDeletes;

    /** Rating bounds (§2.11) and review length (§6.11). */
    public const MIN_RATING = 1;

    public const MAX_RATING = 5;

    public const MAX_REVIEW_LENGTH = 2000;

    /** The video hosts `video_url` may name (§6.9). */
    public const VIDEO_HOSTS = ['youtube.com', 'youtu.be', 'vimeo.com'];

    protected $table = 'student_reviews';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_id',
        'student_name',
        'student_photo_media_id',
        'course_id',
        'course_name',
        'rating',
        'review',
        'video_url',
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
            'student_id' => 'integer',
            'student_photo_media_id' => 'integer',
            'course_id' => 'integer',
            'rating' => 'integer',
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
        return 'student_reviews';
    }

    protected function activityModule(): ?string
    {
        return 'student_reviews';
    }

    /**
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
        return ['student_name', 'course_name', 'review'];
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
        return 'Student review from '.$this->student_name;
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
    public function studentPhoto(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'student_photo_media_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * The only filter the public site may use (§9.2): `status = approved`.
     *
     * @param  Builder<StudentReview>  $query
     * @return Builder<StudentReview>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ApprovalStatus::Approved->value);
    }

    /**
     * @param  Builder<StudentReview>  $query
     * @param  ApprovalStatus|string|array<int, ApprovalStatus|string>  $status
     * @return Builder<StudentReview>
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
     * @param  Builder<StudentReview>  $query
     * @return Builder<StudentReview>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ApprovalStatus::Pending->value);
    }

    /**
     * @param  Builder<StudentReview>  $query
     * @return Builder<StudentReview>
     */
    public function scopeFeatured(Builder $query, bool $featured = true): Builder
    {
        return $query->where($query->qualifyColumn('is_featured'), $featured);
    }

    /**
     * Reviews of one course (§13 Phase 14 request) — by the deferred id, never a join.
     *
     * @param  Builder<StudentReview>  $query
     * @return Builder<StudentReview>
     */
    public function scopeForCourse(Builder $query, int $courseId): Builder
    {
        return $query->where($query->qualifyColumn('course_id'), $courseId);
    }

    /**
     * @param  Builder<StudentReview>  $query
     * @return Builder<StudentReview>
     */
    public function scopeFromSource(Builder $query, ContentSource|string $source): Builder
    {
        return $query->where($query->qualifyColumn('source'), $source instanceof ContentSource ? $source->value : $source);
    }

    /**
     * @param  Builder<StudentReview>  $query
     * @return Builder<StudentReview>
     */
    public function scopeWithRating(Builder $query, int $rating): Builder
    {
        return $query->where($query->qualifyColumn('rating'), $rating);
    }
}
