<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\SocialPlatform;
use App\Models\Cms\Concerns\OrdersBySortOrder;
use App\Models\Cms\Concerns\SearchesContent;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One public team profile (phase-04 §2.9, requirement §13) — website content, **not** an account.
 *
 * There is no `user_id` (D32). `department_id` and `employee_id` are **deferred links** (§2.1) to
 * Phase 7; `employee_id` is a source of defaults only (F-3.13) and never publishes anything. The page
 * groups by the `department` snapshot label.
 *
 * Public only when `status = published AND is_public = true` (§9.2); `is_public = false` hides the member
 * without unpublishing. `social_links` is a map keyed by `SocialPlatform` values — unknown keys are a 422
 * in the Form Request, and `socialLinks()` ignores any that slipped into the column anyway. No
 * `morphOne(SeoMeta)`: the team page has no per-member route (its SEO is the `site.team.index` row).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int|null $photo_media_id
 * @property string $designation
 * @property string|null $department
 * @property int|null $department_id
 * @property int|null $employee_id
 * @property string|null $bio
 * @property list<string>|null $skills
 * @property int|null $experience_years
 * @property string|null $experience_label
 * @property array<string, string>|null $social_links
 * @property string|null $portfolio_url
 * @property bool $is_public
 * @property ContentStatus $status
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class TeamMember extends Model
{
    use Blameable;
    use HasSlug;
    use LogsActivityWithContext;
    use OrdersBySortOrder;
    use SearchesContent;
    use SoftDeletes;

    /** `skills` ceiling (§2.9) and per-entry length (§6.11). */
    public const MAX_SKILLS = 20;

    public const MAX_SKILL_LENGTH = 60;

    /** `experience_years` range (§2.9, §6.11). */
    public const MAX_EXPERIENCE_YEARS = 60;

    protected $table = 'team_members';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'photo_media_id',
        'designation',
        'department',
        'department_id',
        'employee_id',
        'bio',
        'skills',
        'experience_years',
        'experience_label',
        'social_links',
        'portfolio_url',
        'is_public',
        'status',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'is_public' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'photo_media_id' => 'integer',
            'department_id' => 'integer',
            'employee_id' => 'integer',
            'skills' => 'array',
            'experience_years' => 'integer',
            'social_links' => 'array',
            'is_public' => 'boolean',
            'status' => ContentStatus::class,
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'team';
    }

    protected function activityModule(): ?string
    {
        return 'team';
    }

    public function sluggableSource(): string
    {
        return (string) $this->name;
    }

    /**
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['name', 'designation'];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isPubliclyVisible(): bool
    {
        return $this->status === ContentStatus::Published && (bool) $this->is_public && ! $this->trashed();
    }

    /**
     * The stored links in `SocialPlatform` declaration order, keeping only allowlisted keys with an
     * `http(s)` URL — the database is not a trust boundary.
     *
     * @return list<array{platform: SocialPlatform, url: string}>
     */
    public function socialLinks(): array
    {
        $stored = is_array($this->social_links) ? $this->social_links : [];
        $links = [];

        foreach (SocialPlatform::cases() as $platform) {
            $url = trim((string) ($stored[$platform->value] ?? ''));

            if ($url === '' || preg_match('~^https?://[^/\s]+~i', $url) !== 1) {
                continue;
            }

            $links[] = ['platform' => $platform, 'url' => $url];
        }

        return $links;
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
     * The only filter the public site may use (§9.2): published **and** public.
     *
     * @param  Builder<TeamMember>  $query
     * @return Builder<TeamMember>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ContentStatus::Published->value)
            ->where($query->qualifyColumn('is_public'), true);
    }

    /**
     * @param  Builder<TeamMember>  $query
     * @param  ContentStatus|string|array<int, ContentStatus|string>  $status
     * @return Builder<TeamMember>
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
     * The §8.4 public/hidden filter.
     *
     * @param  Builder<TeamMember>  $query
     * @return Builder<TeamMember>
     */
    public function scopeVisibility(Builder $query, bool $public): Builder
    {
        return $query->where($query->qualifyColumn('is_public'), $public);
    }

    /**
     * @param  Builder<TeamMember>  $query
     * @return Builder<TeamMember>
     */
    public function scopeInDepartment(Builder $query, ?string $department): Builder
    {
        $department = trim((string) $department);

        return $department === ''
            ? $query->whereNull($query->qualifyColumn('department'))
            : $query->where($query->qualifyColumn('department'), $department);
    }
}
