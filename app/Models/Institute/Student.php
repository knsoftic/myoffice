<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\Gender;
use App\Enums\StudentStatus;
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

/**
 * A person the institute teaches (`students`, requirement §66, phase-14-17 §2.14).
 *
 * **A student record exists before a login does (D2).** `user_id` is nullable: the institute enrols
 * people with no email address, and the panel account is created at activation when
 * `institute.auto_create_student_login` says so. Nothing here assumes a `users` row, and the two are
 * never the same object — a student who leaves keeps their record and loses their login.
 *
 * **Two numbers, two moments.** `student_code` is stamped at creation and every student has one;
 * `registration_number` is stamped at §68's registration stage and is null until then. Neither is
 * writable through `fill()` — both are issued by `StudentNumberService` inside the transaction that
 * needs them, and a mass-assignable document number is a duplicate waiting for a form post.
 *
 * **The four referral columns are a display snapshot and are not fillable either (INV-I3, INV-R1).**
 * Phase 9's `SyncReferralSnapshot` listener writes them from the authoritative `collaborator_referrals`
 * row; a screen that could set `collaborator_id` here would be a second way to decide who gets paid.
 *
 * **[D-IN-8] There is no `current_batch_id`.** §63 expects several short courses at once, so
 * `activeEnrollments` is the answer and a single "current" column would be wrong for exactly the
 * students who matter most.
 *
 * @property StudentStatus $status
 * @property Gender|null $gender
 */
class Student extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'students';

    /**
     * `student_code`, `registration_number` and the five referral columns are absent on purpose: the
     * first two are issued by the numbering service, the rest are written by Phase 9's listener.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id', 'name', 'father_name', 'gender', 'date_of_birth', 'cnic',
        'phone', 'whatsapp', 'email', 'address', 'city', 'photo_path',
        'guardian_name', 'guardian_phone', 'guardian_relation',
        'education', 'institution_name', 'joining_date', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'branch_id' => 'integer',
            'collaborator_id' => 'integer',
            'referral_visit_id' => 'integer',
            'referral_source' => \App\Enums\ReferralSource::class,
            'status' => StudentStatus::class,
            'gender' => Gender::class,
            'date_of_birth' => 'date',
            'joining_date' => 'date',
            'referral_date' => 'date',
            'status_changed_at' => 'datetime',
        ];
    }

    public function moduleSlug(): string
    {
        return 'students';
    }

    protected function activityModule(): ?string
    {
        return 'students';
    }

    /**
     * The status and the two numbers are logged because they are the three facts somebody may later
     * have to account for; the contact fields are logged because a changed phone number on a student
     * with an outstanding balance is a question worth being able to answer.
     *
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'name', 'father_name', 'phone', 'whatsapp', 'email', 'cnic', 'city',
            'student_code', 'registration_number', 'status', 'status_reason',
            'branch_id', 'user_id', 'joining_date',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /**
     * The CNIC as a person writes it: 31102-1234567-1. Stored digits-only so that two receptionists
     * typing it differently still collide in the duplicate check.
     */
    public function formattedCnic(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->cnic) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) !== 13) {
            return $digits;
        }

        return substr($digits, 0, 5).'-'.substr($digits, 5, 7).'-'.substr($digits, 12, 1);
    }

    /**
     * Is this student registered? The registration number is the fact, not the status — the status can
     * move on to `active` and the number stays.
     */
    public function isRegistered(): bool
    {
        return $this->registration_number !== null && trim((string) $this->registration_number) !== '';
    }

    /**
     * Phase 18 §13.1 asks for `registration_no`; §66 spells the column out. One column, two spellings,
     * so Phase 18's code compiles against this model unchanged (§13).
     */
    public function getRegistrationNoAttribute(): ?string
    {
        return $this->registration_number;
    }

    /**
     * Does the student have anything that makes them undeletable?
     *
     * The FKs refuse anyway — `student_fees`, `student_fee_payments`, `student_batch_enrollments` and
     * `student_attendances` all point here with `restrictOnDelete`. This exists so the screen can
     * explain instead of showing a button that produces a database error. Each table is asked only if
     * it exists: four of them arrive in later phases.
     */
    public function hasHistory(): bool
    {
        foreach (['student_fees', 'student_fee_payments', 'student_batch_enrollments', 'student_attendances'] as $table) {
            if (! $this->getConnection()->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            if ($this->getConnection()->table($table)->where('student_id', $this->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StudentStatus::Active->value);
    }

    /**
     * The students a `branch_id`-scoped user may see: their own branch, plus every student who belongs
     * to no branch in particular ([D-IN-5] — null means "everywhere", not "nobody").
     */
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
        $digits = preg_replace('/\D/', '', $term) ?? '';

        return $query->where(function (Builder $q) use ($like, $digits): void {
            $q->where('name', 'like', $like)
                ->orWhere('student_code', 'like', $like)
                ->orWhere('registration_number', 'like', $like)
                ->orWhere('phone', 'like', $like);

            // A CNIC typed with dashes still finds the digits-only column.
            if ($digits !== '') {
                $q->orWhere('cnic', 'like', '%'.$digits.'%');
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(StudentAdmission::class);
    }

    /**
     * The admissions that are still running — the chips a student header renders, one per course.
     */
    public function liveAdmissions(): HasMany
    {
        return $this->admissions()->whereNotNull('active_guard');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(StudentApplication::class, 'converted_student_id');
    }

    public function demoClasses(): HasMany
    {
        return $this->hasMany(DemoClass::class);
    }
}
