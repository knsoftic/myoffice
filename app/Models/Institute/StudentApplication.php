<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\DeliveryMode;
use App\Enums\PreferredTiming;
use App\Enums\StudentApplicationStatus;
use App\Models\Branch;
use App\Models\Collaborator\Collaborator;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A submitted admission form, waiting for a human (`student_applications`, §67, phase-14-17 §2.13).
 *
 * **[D-IN-7] The public form creates this row and nothing else.** No student, no `users` row, no fee.
 * A stranger filling in a form is a request, not an admission, and a system that turned one into a
 * student record would have a student directory the internet could write to. Staff convert; the one
 * transaction that does it is `StudentApplicationService::convert()`.
 *
 * **`idempotency_key` blocks and `duplicate_fingerprint` flags, and that asymmetry is deliberate.**
 * The key is one ULID per rendered form and is unique, so a double-tapped submit is silently the same
 * application. The fingerprint is not unique, because the same person really does apply twice for the
 * same course six months apart — it turns the row amber and puts the two side by side for somebody to
 * judge.
 *
 * **The referral evidence here is captured, not attached ([D-IN-13]).** `collaborator_referrals` needs
 * a subject row and there is none yet, so the code, the resolver's verdict, the visit and the landing
 * URL are written down; `convert()` turns them into the one authoritative attribution. A
 * `collaborator_id` posted by the browser never reaches this table (INV-I4), which is why it is not
 * fillable.
 *
 * @property StudentApplicationStatus $status
 */
class StudentApplication extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'student_applications';

    /**
     * Everything the applicant types, and nothing the server decides: the number, the status, the
     * fingerprint, the idempotency key, the referral verdict and the conversion trio are all written
     * by the service.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id', 'course_inquiry_id',
        'name', 'father_name', 'phone', 'whatsapp', 'email', 'city', 'education',
        'course_id', 'batch_id', 'preferred_timing', 'preferred_delivery_mode', 'message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'course_id' => 'integer',
            'batch_id' => 'integer',
            'course_inquiry_id' => 'integer',
            'collaborator_id' => 'integer',
            'referral_visit_id' => 'integer',
            'duplicate_of_application_id' => 'integer',
            'reviewed_by' => 'integer',
            'converted_student_id' => 'integer',
            'converted_admission_id' => 'integer',
            'status' => StudentApplicationStatus::class,
            'preferred_timing' => PreferredTiming::class,
            'preferred_delivery_mode' => DeliveryMode::class,
            'referral_code_valid' => 'boolean',
            'reviewed_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function moduleSlug(): string
    {
        return 'student_applications';
    }

    protected function activityModule(): ?string
    {
        return 'student_applications';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'status', 'reviewed_by', 'review_notes', 'rejection_reason',
            'duplicate_of_application_id', 'converted_student_id', 'converted_admission_id',
            'course_id', 'batch_id', 'branch_id',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /**
     * The fingerprint §2.13 defines: sha1 of the normalised phone, the course and the trimmed,
     * lowercased name. Computed here so the service, the dedupe job and any test all produce the same
     * string — three implementations of a hash is three sets of duplicates nobody notices.
     */
    public static function fingerprintFor(?string $phone, int|string|null $courseId, ?string $name): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        return sha1(implode('|', [
            $digits,
            (string) ((int) $courseId),
            mb_strtolower(trim((string) $name)),
        ]));
    }

    /**
     * Other applications that look like the same person inside
     * `institute.application_duplicate_window_days`. A flag for a human, never a refusal.
     *
     * @return Builder<self>
     */
    public function possibleDuplicates(): Builder
    {
        $days = max(1, (int) setting('institute.application_duplicate_window_days', 7));
        $from = ($this->created_at ?? Carbon::now())->copy()->subDays($days);

        return static::query()
            ->where('duplicate_fingerprint', $this->duplicate_fingerprint)
            ->whereKeyNot($this->getKey())
            ->where('created_at', '>=', $from);
    }

    public function hasValidReferral(): bool
    {
        return (bool) $this->referral_code_valid && $this->collaborator_id !== null;
    }

    /** Has a human already turned this into a student? Re-running `convert()` must be a no-op. */
    public function isConverted(): bool
    {
        return $this->status === StudentApplicationStatus::Converted
            && $this->converted_admission_id !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /** The inbox: everything still waiting for a decision. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            StudentApplicationStatus::Submitted->value,
            StudentApplicationStatus::UnderReview->value,
        ]);
    }

    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        if ($branchId === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($branchId): void {
            $q->where('branch_id', $branchId)->orWhereNull('branch_id');
        });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('name', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('application_number', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    /**
     * The public thank-you page is bound on the number, not the id — and the URL is signed, so the
     * number cannot be enumerated even though it is guessable.
     */
    public function getRouteKeyName(): string
    {
        return 'application_number';
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(CourseInquiry::class, 'course_inquiry_id');
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_application_id');
    }

    public function convertedStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'converted_student_id');
    }

    public function convertedAdmission(): BelongsTo
    {
        return $this->belongsTo(StudentAdmission::class, 'converted_admission_id');
    }

    public function demoClasses(): HasMany
    {
        return $this->hasMany(DemoClass::class);
    }
}
