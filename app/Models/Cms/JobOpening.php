<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\EmploymentType;
use App\Enums\JobOpeningStatus;
use App\Enums\WorkMode;
use App\Models\Cms\Concerns\OrdersBySortOrder;
use App\Models\Cms\Concerns\SearchesContent;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One advertised role (phase-04 §2.18, requirement §16) — module slug `jobs`, table `job_openings`.
 *
 * **Never the `jobs` table** (§12.1 R1): that is Laravel's database queue.
 *
 * **Money.** `salary_min` / `salary_max` are `decimal(15,2)` cast to decimal strings; the Form Request
 * asserts `Money::compare(min, max) <= 0`. `salary_visible = false` renders "Negotiable" and never emits
 * the numbers. `salary_period` is a short closed list validated in the Form Request (data-model §4.4),
 * published here as `SALARY_PERIODS`.
 *
 * **Public** only while `status = open` **and** the deadline has not passed (§9.2). `deadline` is a
 * calendar date, so "today" is the business's calendar day in the display timezone (D61) —
 * `businessToday()` — not the UTC date.
 *
 * `opened_at`, `closed_at` and the `applications_count` cache are written only by `JobOpeningService` /
 * `JobApplicationService` (stamps and an atomic increment), so they are not fillable. `created_by` is
 * also the hiring-manager "openings I own" scope of §9.1.3. `employment_type` casts
 * `App\Enums\EmploymentType` (phase-07 §3 owns it). `department_id` is a deferred link (§2.1).
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $department
 * @property int|null $department_id
 * @property string|null $location
 * @property WorkMode $work_mode
 * @property EmploymentType $employment_type
 * @property int|null $experience_min_years
 * @property string|null $experience_note
 * @property int $openings_count
 * @property string|null $salary_min
 * @property string|null $salary_max
 * @property string $salary_period
 * @property bool $salary_visible
 * @property string $description
 * @property string|null $requirements
 * @property string|null $responsibilities
 * @property list<string>|null $skills
 * @property Carbon|null $deadline
 * @property JobOpeningStatus $status
 * @property bool $is_featured
 * @property Carbon|null $opened_at
 * @property Carbon|null $closed_at
 * @property int $applications_count
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class JobOpening extends Model
{
    use Blameable;
    use HasSlug;
    use LogsActivityWithContext;
    use OrdersBySortOrder;
    use SearchesContent;
    use SoftDeletes;

    /** `job_openings.salary_period` values (§2.18), validated `in:` by the Form Request. */
    public const SALARY_PERIODS = ['monthly', 'yearly', 'hourly', 'project'];

    /** `skills` ceiling (§2.18) and `experience_min_years` range (§16). */
    public const MAX_SKILLS = 30;

    public const MAX_EXPERIENCE_YEARS = 40;

    protected $table = 'job_openings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'slug',
        'department',
        'department_id',
        'location',
        'work_mode',
        'employment_type',
        'experience_min_years',
        'experience_note',
        'openings_count',
        'salary_min',
        'salary_max',
        'salary_period',
        'salary_visible',
        'description',
        'requirements',
        'responsibilities',
        'skills',
        'deadline',
        'status',
        'is_featured',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'work_mode' => 'onsite',
        'openings_count' => 1,
        'salary_period' => 'monthly',
        'salary_visible' => true,
        'status' => 'draft',
        'is_featured' => false,
        'applications_count' => 0,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'work_mode' => WorkMode::class,
            'employment_type' => EmploymentType::class,
            'experience_min_years' => 'integer',
            'openings_count' => 'integer',
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'salary_visible' => 'boolean',
            'skills' => 'array',
            'deadline' => 'date',
            'status' => JobOpeningStatus::class,
            'is_featured' => 'boolean',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'applications_count' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'jobs';
    }

    protected function activityModule(): ?string
    {
        return 'jobs';
    }

    /**
     * The status stamps are not fillable but belong in the audit trail; the counter does not.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return [...$this->getFillable(), 'opened_at', 'closed_at'];
    }

    public function sluggableSource(): string
    {
        return (string) $this->title;
    }

    /**
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['title', 'department', 'location'];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Today's date (`Y-m-d`) in the display timezone — the calendar day a `deadline` is compared with.
     */
    public static function businessToday(): string
    {
        try {
            return DateRange::today()->start->toDateString();
        } catch (Throwable) {
            return now()->toDateString();
        }
    }

    /**
     * The deadline has passed (a null deadline never expires).
     */
    public function isExpired(): bool
    {
        return $this->deadline !== null && $this->deadline->toDateString() < self::businessToday();
    }

    /**
     * Open **and** not past its deadline (§6.8 `apply()` invariant 1, the model half; the service also
     * checks `website.careers_enabled` and the `jobs` module).
     */
    public function acceptsApplications(): bool
    {
        return $this->status instanceof JobOpeningStatus
            && $this->status->acceptsApplications()
            && ! $this->isExpired()
            && ! $this->trashed();
    }

    public function isOwnedBy(User|int|null $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $id !== null && $this->created_by !== null && (int) $this->created_by === (int) $id;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return HasMany<JobApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class, 'job_opening_id');
    }

    /**
     * @return MorphOne<SeoMeta, $this>
     */
    public function seo(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'seoable');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * The only filter the public site may use (§9.2): `status = open AND (deadline IS NULL OR
     * deadline >= today)`.
     *
     * @param  Builder<JobOpening>  $query
     * @return Builder<JobOpening>
     */
    public function scopePublic(Builder $query): Builder
    {
        $today = self::businessToday();

        return $query->where($query->qualifyColumn('status'), JobOpeningStatus::Open->value)
            ->where(function (Builder $builder) use ($today): void {
                $builder->whereNull($builder->qualifyColumn('deadline'))
                    ->orWhere($builder->qualifyColumn('deadline'), '>=', $today);
            });
    }

    /**
     * `open` openings whose deadline has passed — what `careers:close-expired` closes (§6.8).
     *
     * @param  Builder<JobOpening>  $query
     * @return Builder<JobOpening>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), JobOpeningStatus::Open->value)
            ->whereNotNull($query->qualifyColumn('deadline'))
            ->where($query->qualifyColumn('deadline'), '<', self::businessToday());
    }

    /**
     * Open openings that close within the next `$days` days, today included (`OpenJobsWidget`).
     *
     * @param  Builder<JobOpening>  $query
     * @return Builder<JobOpening>
     */
    public function scopeClosingWithin(Builder $query, int $days): Builder
    {
        $today = self::businessToday();
        $until = Carbon::parse($today)->addDays(max(0, $days))->toDateString();

        return $query->where($query->qualifyColumn('status'), JobOpeningStatus::Open->value)
            ->whereBetween($query->qualifyColumn('deadline'), [$today, $until]);
    }

    /**
     * @param  Builder<JobOpening>  $query
     * @param  JobOpeningStatus|string|array<int, JobOpeningStatus|string>  $status
     * @return Builder<JobOpening>
     */
    public function scopeWithStatus(Builder $query, JobOpeningStatus|string|array $status): Builder
    {
        $values = array_map(
            static fn (JobOpeningStatus|string $value): string => $value instanceof JobOpeningStatus ? $value->value : $value,
            is_array($status) ? $status : [$status]
        );

        return $query->whereIn($query->qualifyColumn('status'), $values);
    }

    /**
     * The hiring-manager "openings I own" filter (§9.1.3): `created_by = user`.
     *
     * @param  Builder<JobOpening>  $query
     * @return Builder<JobOpening>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        return $query->where($query->qualifyColumn('created_by'), $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * @param  Builder<JobOpening>  $query
     * @return Builder<JobOpening>
     */
    public function scopeFeatured(Builder $query, bool $featured = true): Builder
    {
        return $query->where($query->qualifyColumn('is_featured'), $featured);
    }
}
