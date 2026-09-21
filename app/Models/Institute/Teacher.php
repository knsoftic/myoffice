<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\Gender;
use App\Enums\TeacherStatus;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody who takes a class (`teachers`, §72, phase-14-17 §2.17).
 *
 * **A teacher is not an employee row, and the link is optional.** An institute runs on visiting
 * trainers who are paid per course and never appear on a payroll, and on staff who are both. So this
 * table stands on its own and `employee_id` is a nullable one-to-one into Phase 7's `employees`: when
 * it is set, identity and salary are copied across and locked in the form ([D-IN-9]); when it is not,
 * the row is complete by itself. The foreign key is deliberately absent until Phase 7 creates that
 * table — `uq_te_employee` already stops one employee being two teachers.
 *
 * **`status` is what a timetable asks before it books anybody.** `TeacherStatus::canTeach()` is true
 * for `active` alone, and `TeacherService::changeStatus()` refuses to move a teacher off `active`
 * while they still own a scheduled class inside the generation horizon — the check that stops a
 * resigned trainer quietly owning next week's timetable.
 *
 * **`bio` is internal and `public_bio` is the website's.** One column serving both means either the
 * staff note leaks onto the site or the site's copy has to be kept somewhere else; §72 asks for a
 * public profile, so it gets a column, and `is_public` without a `slug` is refused by the CHECK.
 *
 * @property TeacherStatus $status
 */
class Teacher extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'teachers';

    /**
     * `teacher_code` is issued by `TeacherService` through Phase 5's number service and is never
     * writable; `employee_id` moves only through `linkEmployee()` / `unlinkEmployee()`, which audit
     * the reason.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id', 'user_id', 'slug', 'name', 'photo_path', 'phone', 'whatsapp', 'email',
        'gender', 'qualification', 'experience_years', 'experience_note', 'skills',
        'specialization', 'bio', 'public_bio', 'social_links', 'joining_date', 'salary',
        'is_public', 'sort_order', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'user_id' => 'integer',
            'employee_id' => 'integer',
            'gender' => Gender::class,
            'status' => TeacherStatus::class,
            'experience_years' => 'integer',
            'skills' => 'array',
            'social_links' => 'array',
            'joining_date' => 'date',
            'salary' => 'decimal:2',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'teachers';
    }

    protected function activityModule(): ?string
    {
        return 'teachers';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'name', 'status', 'status_reason', 'employee_id', 'user_id', 'branch_id',
            'salary', 'is_public', 'slug', 'email', 'phone',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /** The one question the timetable, the demo booker and the substitution form all ask. */
    public function canTeach(): bool
    {
        return $this->status->canTeach();
    }

    /** Identity and salary come from the employee record, so the form locks them (§6.11). */
    public function isLinkedToEmployee(): bool
    {
        return $this->employee_id !== null;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(static fn (string $p): string => mb_substr($p, 0, 1), array_slice($parts, 0, 2));

        return mb_strtoupper(implode('', $letters)) ?: '?';
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeTeaching(Builder $query): Builder
    {
        return $query->where('status', TeacherStatus::Active->value);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->whereNotNull('slug');
    }

    /**
     * The teachers a `branch_id`-scoped user may see: their own branch, plus everybody who belongs to
     * no branch in particular ([D-IN-5] — null means "everywhere", not "nobody").
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

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_teacher')
            ->withPivot(['is_primary', 'assigned_on'])
            ->withTimestamps();
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function timetableEntries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    public function demoClasses(): HasMany
    {
        return $this->hasMany(DemoClass::class);
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
