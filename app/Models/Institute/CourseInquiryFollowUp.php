<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\CourseInquiryStatus;
use App\Enums\FollowUpChannel;
use App\Enums\FollowUpOutcome;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One recorded contact attempt on an enquiry (`course_inquiry_follow_ups`, §68).
 *
 * **Append-only ([D-IN-2]): no `deleted_at`, and the model refuses a delete.** "We called four times"
 * is a claim somebody makes to a manager or to the person on the other end of the phone, and a log
 * whose rows can be removed cannot support it. A mistaken entry is corrected by another entry.
 *
 * **`user_name` sits beside `user_id` on purpose.** The FK is `nullOnDelete`, so a counsellor who
 * leaves takes their name out of every row that joined to them — and "contacted by (deleted user)" is
 * not a contact log. The id is the link; the name is the record.
 *
 * `status_before` / `status_after` are snapshots of the enquiry's status around this contact, so the
 * timeline can show the move that the outcome caused without re-deriving it from the activity log.
 */
class CourseInquiryFollowUp extends Model
{
    use Blameable;
    use LogsActivityWithContext;

    protected $table = 'course_inquiry_follow_ups';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel', 'outcome', 'contacted_at', 'notes', 'next_follow_up_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_inquiry_id' => 'integer',
            'user_id' => 'integer',
            'channel' => FollowUpChannel::class,
            'outcome' => FollowUpOutcome::class,
            'status_before' => CourseInquiryStatus::class,
            'status_after' => CourseInquiryStatus::class,
            'contacted_at' => 'datetime',
            'next_follow_up_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // The table has no `deleted_at` by contract; this is what stops a `->delete()` from becoming a
        // hard delete that quietly lowers `contact_attempts` below what really happened. The test suite
        // may still tear down its fixtures.
        static::deleting(static function (CourseInquiryFollowUp $row): void {
            if (app()->runningUnitTests()) {
                return;
            }

            throw new LogicException(sprintf(
                'Follow-up #%d may not be deleted: the contact log is append-only. Record another '
                .'follow-up describing the correction.',
                (int) $row->getKey(),
            ));
        });
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
        return ['channel', 'outcome', 'contacted_at', 'next_follow_up_at', 'status_after'];
    }

    /** Who made contact — the snapshot, falling back to the live row only when there is no snapshot. */
    public function contactedBy(): string
    {
        $name = trim((string) $this->user_name);

        if ($name !== '') {
            return $name;
        }

        return trim((string) $this->user?->name) ?: 'System';
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(CourseInquiry::class, 'course_inquiry_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
