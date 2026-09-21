<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\CourseInquiryStatus;
use App\Enums\DeliveryMode;
use App\Enums\InquirySource;
use App\Enums\PreferredTiming;
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
 * Somebody asking about a course (`course_inquiries`, §86, phase-14-17 §2.11).
 *
 * **This is not a CRM lead, and the difference is how it is worked.** A software-house lead moves
 * through a pipeline stage carrying a value (§17); a course enquiry is worked by follow-up calls
 * against a date. One table for both would put a `type` filter in every query in two modules, and the
 * first one to forget it would show a counsellor somebody else's pipeline.
 *
 * **`source` is Phase 4's `InquirySource`, not an enum of this phase's own (F-5.3).** §86's eight
 * channels are a subset of its eleven values as identical strings, so there is nothing to map; a second
 * enum would mean two answers to "where did this come from" on one report.
 *
 * **The three referral columns are capture evidence and never authority (INV-I3).** There is no subject
 * row for `collaborator_referrals` to point at — the spine's CHECK allows student, project, client and
 * lead — so the code is stored verbatim, resolved once for display, and superseded by the real
 * attribution the moment `convert()` creates a student.
 *
 * `last_contacted_at` and `contact_attempts` are **caches** of the follow-up table, rewritten by
 * `CourseInquiryService::logFollowUp()`. The follow-up rows are the record.
 *
 * @property CourseInquiryStatus $status
 */
class CourseInquiry extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'course_inquiries';

    /**
     * The number, the status, the two caches, the conversion trio and the referral snapshot are all
     * absent: each is written by the service that owns the fact.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id', 'name', 'phone', 'whatsapp', 'email', 'city', 'education',
        'course_id', 'batch_id', 'preferred_delivery_mode', 'preferred_timing',
        'source', 'source_url', 'assigned_to', 'follow_up_date', 'message', 'notes',
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
            'assigned_to' => 'integer',
            'collaborator_id' => 'integer',
            'referral_visit_id' => 'integer',
            'contact_inquiry_id' => 'integer',
            'converted_application_id' => 'integer',
            'converted_student_id' => 'integer',
            'status' => CourseInquiryStatus::class,
            'source' => InquirySource::class,
            'preferred_delivery_mode' => DeliveryMode::class,
            'preferred_timing' => PreferredTiming::class,
            'referral_code_valid' => 'boolean',
            'contact_attempts' => 'integer',
            'follow_up_date' => 'date',
            'last_contacted_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function moduleSlug(): string
    {
        return 'course_inquiries';
    }

    protected function activityModule(): ?string
    {
        return 'course_inquiries';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'name', 'phone', 'email', 'course_id', 'batch_id', 'status', 'assigned_to',
            'follow_up_date', 'lost_reason', 'source', 'branch_id',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /**
     * Is the next action overdue? Red in the queue, and the only definition of it.
     */
    public function followUpIsOverdue(?Carbon $on = null): bool
    {
        if ($this->follow_up_date === null || ! $this->status->isOpen()) {
            return false;
        }

        return $this->follow_up_date->lt(($on ?? Carbon::now())->startOfDay());
    }

    /**
     * Untouched for longer than `institute.inquiry_stale_days` — flagged with an amber border and a
     * tooltip, never auto-closed. An enquiry that goes quiet is a person somebody stopped calling, and
     * a system that closed it would hide the omission it exists to reveal.
     */
    public function isStale(?Carbon $on = null): bool
    {
        if (! $this->status->isOpen()) {
            return false;
        }

        $days = max(1, (int) setting('institute.inquiry_stale_days', 14));
        $since = $this->last_contacted_at ?? $this->created_at;

        return $since !== null && $since->diffInDays($on ?? Carbon::now()) >= $days;
    }

    /** Was a partner's code quoted, and did it resolve to somebody active? */
    public function hasValidReferral(): bool
    {
        return (bool) $this->referral_code_valid && $this->collaborator_id !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /** The counsellor's queue: everything still worth a call. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            CourseInquiryStatus::AdmissionConfirmed->value,
            CourseInquiryStatus::NotInterested->value,
        ]);
    }

    public function scopeDueBy(Builder $query, ?Carbon $on = null): Builder
    {
        return $query->open()->whereNotNull('follow_up_date')
            ->whereDate('follow_up_date', '<=', ($on ?? Carbon::now())->toDateString());
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
                ->orWhere('inquiry_number', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }

    public function convertedApplication(): BelongsTo
    {
        return $this->belongsTo(StudentApplication::class, 'converted_application_id');
    }

    public function convertedStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'converted_student_id');
    }

    /** Newest first: the timeline reads downwards from the last thing that happened. */
    public function followUps(): HasMany
    {
        return $this->hasMany(CourseInquiryFollowUp::class)
            ->orderByDesc('contacted_at')
            ->orderByDesc('id');
    }

    public function demoClasses(): HasMany
    {
        return $this->hasMany(DemoClass::class);
    }
}
