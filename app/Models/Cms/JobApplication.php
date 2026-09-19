<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\InquirySource;
use App\Enums\JobApplicationStatus;
use App\Models\Cms\Concerns\SearchesContent;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One candidate application and its six-stage pipeline state (phase-04 §2.19, requirement §16).
 *
 * **The CV is a private artefact (D21).** `cv_path` lives on the private `local` disk under
 * `careers/applications/{Y}/{job_opening_id}/{ulid}.{ext}` and is streamed only by
 * `admin.job-applications.cv` behind `job_applications.download`. It is hidden from serialisation and kept
 * out of the activity log. A soft delete keeps the file (restore is lossless); the `forceDeleted` hook
 * below removes it — the observer §2.19 names, registered with the model so it can never be left unwired.
 *
 * **Pipeline state is not mass assignable.** `status`, `status_changed_at`, `status_changed_by`,
 * `rating`, `assigned_to`, `employee_id`, `internal_notes`, `interview_*` and `rejection_reason` are
 * written only by `JobApplicationService` (`changeStatus()`, `assign()`, `saveNotes()`) via `forceFill()`;
 * the public apply form can never set them.
 *
 * **Isolation (§9.1.3).** `scopeVisibleTo()`: `job_applications.view_any` sees every application; anyone
 * else sees what is assigned to it **or** belongs to an opening it created.
 *
 * `email` is stored trimmed and lower-cased (the `uq_job_application_per_job` key). `expected_salary` is
 * money (`decimal:2` string). `employee_id` is a deferred link to Phase 7 (§2.1, F-3.12).
 *
 * @property int $id
 * @property int $job_opening_id
 * @property string $applicant_name
 * @property string $email
 * @property string $phone
 * @property string|null $whatsapp
 * @property string|null $city
 * @property int|null $experience_years
 * @property string|null $expected_salary
 * @property string|null $cover_letter
 * @property string|null $portfolio_url
 * @property string|null $linkedin_url
 * @property string $cv_path
 * @property string $cv_original_name
 * @property string $cv_mime
 * @property int $cv_size
 * @property JobApplicationStatus $status
 * @property Carbon|null $status_changed_at
 * @property int|null $status_changed_by
 * @property int|null $rating
 * @property int|null $assigned_to
 * @property int|null $employee_id
 * @property string|null $internal_notes
 * @property Carbon|null $interview_at
 * @property string|null $interview_mode
 * @property string|null $interview_location
 * @property string|null $rejection_reason
 * @property InquirySource $source
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class JobApplication extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SearchesContent;
    use SoftDeletes;

    /** The private disk every CV is written to and read from (§6.8, D21). */
    public const CV_DISK = 'local';

    /** `job_applications.interview_mode` values (§2.19), validated `in:` by the Form Request. */
    public const INTERVIEW_MODES = ['onsite', 'online', 'phone'];

    /** Internal screening score bounds and the public form's limits (§2.19, §6.11). */
    public const MIN_RATING = 1;

    public const MAX_RATING = 5;

    public const MAX_EXPERIENCE_YEARS = 40;

    public const MAX_COVER_LETTER_LENGTH = 5000;

    protected $table = 'job_applications';

    /**
     * What the public apply flow (`JobApplicationService::apply()`) writes. Pipeline state is not here.
     *
     * @var list<string>
     */
    protected $fillable = [
        'job_opening_id',
        'applicant_name',
        'email',
        'phone',
        'whatsapp',
        'city',
        'experience_years',
        'expected_salary',
        'cover_letter',
        'portfolio_url',
        'linkedin_url',
        'cv_path',
        'cv_original_name',
        'cv_mime',
        'cv_size',
        'source',
        'ip_address',
        'user_agent',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'cv_path',
        'ip_address',
        'user_agent',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'new',
        'source' => 'website',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'job_opening_id' => 'integer',
            'experience_years' => 'integer',
            'expected_salary' => 'decimal:2',
            'cv_size' => 'integer',
            'status' => JobApplicationStatus::class,
            'status_changed_at' => 'datetime',
            'status_changed_by' => 'integer',
            'rating' => 'integer',
            'assigned_to' => 'integer',
            'employee_id' => 'integer',
            'interview_at' => 'datetime',
            'source' => InquirySource::class,
        ];
    }

    protected static function booted(): void
    {
        // §2.19: the CV leaves the private disk on a force delete only. Idempotent — a missing file is
        // not an error, and a failure to delete never blocks the database delete that already happened.
        static::forceDeleted(static function (Model $application): void {
            $path = trim((string) $application->getAttribute('cv_path'));

            if ($path === '') {
                return;
            }

            try {
                Storage::disk(self::CV_DISK)->delete($path);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function moduleSlug(): string
    {
        return 'job_applications';
    }

    protected function activityModule(): ?string
    {
        return 'job_applications';
    }

    /**
     * Stage changes, assignment, rating and notes are audited (§10.5). The CV path and the applicant's
     * IP / user agent are not written to the activity log, whose viewer is a wider audience than the CV.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return array_values(array_diff(
            [
                ...$this->getFillable(),
                'status', 'status_changed_at', 'status_changed_by', 'rating', 'assigned_to', 'employee_id',
                'internal_notes', 'interview_at', 'interview_mode', 'interview_location', 'rejection_reason',
            ],
            ['cv_path', 'ip_address', 'user_agent'],
        ));
    }

    /**
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['applicant_name', 'email', 'phone'];
    }

    /**
     * Stored trimmed and lower-cased (§2.19) — the per-opening uniqueness key.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): string => mb_strtolower(trim((string) $value)),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isAssignedTo(User|int|null $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $id !== null && $this->assigned_to !== null && (int) $this->assigned_to === (int) $id;
    }

    /**
     * Does the application belong to an opening `$user` created (the §9.1.3 per-opening scope)?
     * The opening is read even when soft-deleted: trashing an advert does not orphan its pipeline.
     */
    public function belongsToOpeningOwnedBy(User|int|null $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        if ($id === null) {
            return false;
        }

        $opening = $this->relationLoaded('jobOpening')
            ? $this->getRelation('jobOpening')
            : $this->jobOpening()->first();

        return $opening instanceof JobOpening && $opening->isOwnedBy((int) $id);
    }

    public function hasCv(): bool
    {
        return trim((string) $this->cv_path) !== '';
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<JobOpening, $this>
     */
    public function jobOpening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class, 'job_opening_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function statusChanger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * §9.1.3 row scoping. `job_applications.view_any` (HR) sees every application; anyone else sees the
     * rows assigned to it plus every application to an opening it created. Never a role name, never a
     * hidden form field.
     *
     * @param  Builder<JobApplication>  $query
     * @return Builder<JobApplication>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('job_applications.view_any')) {
            return $query;
        }

        $userId = $user->getKey();

        return $query->where(function (Builder $builder) use ($userId): void {
            $builder->where($builder->qualifyColumn('assigned_to'), $userId)
                ->orWhereHas('jobOpening', function (Builder $opening) use ($userId): void {
                    $opening->where($opening->qualifyColumn('created_by'), $userId);
                });
        });
    }

    /**
     * @param  Builder<JobApplication>  $query
     * @param  JobApplicationStatus|string|array<int, JobApplicationStatus|string>  $status
     * @return Builder<JobApplication>
     */
    public function scopeWithStatus(Builder $query, JobApplicationStatus|string|array $status): Builder
    {
        $values = array_map(
            static fn (JobApplicationStatus|string $value): string => $value instanceof JobApplicationStatus ? $value->value : $value,
            is_array($status) ? $status : [$status]
        );

        return $query->whereIn($query->qualifyColumn('status'), $values);
    }

    /**
     * @param  Builder<JobApplication>  $query
     * @return Builder<JobApplication>
     */
    public function scopeForOpening(Builder $query, JobOpening|int $opening): Builder
    {
        return $query->where($query->qualifyColumn('job_opening_id'), $opening instanceof JobOpening ? $opening->getKey() : $opening);
    }

    /**
     * @param  Builder<JobApplication>  $query
     * @return Builder<JobApplication>
     */
    public function scopeAssignedTo(Builder $query, User|int $user): Builder
    {
        return $query->where($query->qualifyColumn('assigned_to'), $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * The §8.9 "unassigned only" filter.
     *
     * @param  Builder<JobApplication>  $query
     * @return Builder<JobApplication>
     */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('assigned_to'));
    }
}
