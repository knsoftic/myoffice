<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\Concerns\OrdersBySortOrder;
use App\Models\Cms\Concerns\SearchesContent;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One student success story (phase-04 §2.12, requirement §92) — staff-authored, so it is
 * `ContentStatus`-published with **no** approval queue.
 *
 * No slug and no detail route: the site renders stories in a section plus a modal. `student_id`
 * (→ Phase 15) and `course_id` (→ Phase 14) are **deferred links** (§2.1); the snapshots are what
 * renders. `story` is sanitised rich text (D25). `video_url` is YouTube/Vimeo only (§6.9).
 *
 * @property int $id
 * @property int|null $student_id
 * @property string $student_name
 * @property int|null $photo_media_id
 * @property int|null $course_id
 * @property string|null $course_name
 * @property string|null $headline
 * @property string $story
 * @property string|null $achievement
 * @property string|null $company_name
 * @property string|null $platform
 * @property string|null $video_url
 * @property ContentStatus $status
 * @property bool $is_featured
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class SuccessStory extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use OrdersBySortOrder;
    use SearchesContent;
    use SoftDeletes;

    /** `story` length (§6.11). */
    public const MAX_STORY_LENGTH = 20000;

    /** The video hosts `video_url` may name (§6.9). */
    public const VIDEO_HOSTS = ['youtube.com', 'youtu.be', 'vimeo.com'];

    protected $table = 'success_stories';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_id',
        'student_name',
        'photo_media_id',
        'course_id',
        'course_name',
        'headline',
        'story',
        'achievement',
        'company_name',
        'platform',
        'video_url',
        'status',
        'is_featured',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'is_featured' => false,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'photo_media_id' => 'integer',
            'course_id' => 'integer',
            'status' => ContentStatus::class,
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'success_stories';
    }

    protected function activityModule(): ?string
    {
        return 'success_stories';
    }

    /**
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['student_name', 'headline', 'company_name'];
    }

    public function isPublished(): bool
    {
        return $this->status === ContentStatus::Published;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'photo_media_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * The only filter the public site may use (§9.2): `status = published`.
     *
     * @param  Builder<SuccessStory>  $query
     * @return Builder<SuccessStory>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ContentStatus::Published->value);
    }

    /**
     * @param  Builder<SuccessStory>  $query
     * @param  ContentStatus|string|array<int, ContentStatus|string>  $status
     * @return Builder<SuccessStory>
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
     * @param  Builder<SuccessStory>  $query
     * @return Builder<SuccessStory>
     */
    public function scopeFeatured(Builder $query, bool $featured = true): Builder
    {
        return $query->where($query->qualifyColumn('is_featured'), $featured);
    }

    /**
     * Stories of one course (§13 Phase 14 request) — by the deferred id, never a join.
     *
     * @param  Builder<SuccessStory>  $query
     * @return Builder<SuccessStory>
     */
    public function scopeForCourse(Builder $query, int $courseId): Builder
    {
        return $query->where($query->qualifyColumn('course_id'), $courseId);
    }
}
