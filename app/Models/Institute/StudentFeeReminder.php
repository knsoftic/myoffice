<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\FeeReminderType;
use App\Models\Branch;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * "We told this student, about this line, on this day" (phase-18 §2.2, requirement §97).
 *
 * **A write-once log row.** It is inserted and never touched again: no `updated_at`, no `deleted_at`,
 * and the `updating` hook below refuses an edit outright (D19). A reminder is a claim about something
 * that already happened in the outside world — an email left the building — and there is no version of
 * editing that claim which is honest.
 *
 * **The row is written BEFORE the notification is sent, and that ordering is the whole design.**
 * `uq_sfr_dedupe` is on `(student_fee_id, dedupe_line, type, due_date, offset_days)`, so a retried job,
 * a second scheduler tick and a staff member pressing "Send reminder now" all race for the same row;
 * exactly one wins and only the winner sends. Sending first and logging afterwards would mean a crash
 * between the two chased the student again on the next tick, which is the failure this table exists to
 * prevent.
 *
 * **`dedupe_line` is a STORED generated column, `COALESCE(student_fee_installment_id, 0)`** ([D18-1]).
 * MariaDB permits unlimited NULLs in a unique index, so a charge with no installment plan — where
 * `student_fee_installment_id` is genuinely NULL — would slip the guard on every single run. The
 * generated column closes that without writing a `0` into a foreign key, which would be a dangling
 * reference wearing a disguise.
 *
 * **This deliberately does not use `App\Models\Cms\Concerns\ForbidsUpdates`**, which stamps
 * `created_by` — a column this table does not have. Who sent a reminder is `sent_by` (null when the
 * scheduler did it, which `chk_sfr_origin` pairs with a mandatory `run_uuid`), because "the system sent
 * this at 09:00" and "Ayesha sent this at 14:32" are different facts and a single blameable column
 * would blur them.
 *
 * @property string $amount_due
 * @property FeeReminderType $type
 */
class StudentFeeReminder extends Model
{
    use LogsActivityWithContext;

    /** Written once: there is no update to stamp. */
    public const UPDATED_AT = null;

    protected $table = 'student_fee_reminders';

    /**
     * Nothing. Every column is composed by `FeeReminderService` — the snapshot of the amount, the
     * recipient and the offset all have to agree with the message that was actually sent, and a mass
     * assignment from a request could not know what that was.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_fee_id' => 'integer',
            'student_fee_installment_id' => 'integer',
            'student_id' => 'integer',
            'branch_id' => 'integer',
            'type' => FeeReminderType::class,
            'due_date' => 'immutable_date',
            'offset_days' => 'integer',
            'amount_due' => 'decimal:2',
            'sent_at' => 'immutable_datetime',
            'sent_by' => 'integer',
            'is_manual' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // The model refuses the edit before the absence of `updated_at` can make one look harmless.
        // Without this, `$reminder->update(['channel' => 'mail'])` would succeed silently and rewrite
        // history about a message that had already gone out over a different channel.
        static::updating(static function (self $reminder): never {
            throw new LogicException(sprintf(
                'Reminder #%s records a message that was already sent and cannot be edited. If the '
                .'student needs telling again, send another one — it will carry its own row.',
                (string) $reminder->getKey(),
            ));
        });

        // There is no `deleted_at`, so `delete()` here is a hard DELETE. A reminder is the evidence
        // that a student was chased, which is exactly what somebody disputing being chased needs.
        static::deleting(static function (self $reminder): never {
            throw new LogicException(sprintf(
                'Reminder #%s is an append-only log row (D19) and is never deleted.',
                (string) $reminder->getKey(),
            ));
        });
    }

    public function moduleSlug(): string
    {
        return 'fee_reminders';
    }

    protected function activityModule(): ?string
    {
        return 'fee_reminders';
    }

    /*
    |--------------------------------------------------------------------------
    | Questions the screens ask
    |--------------------------------------------------------------------------
    */

    /**
     * The schedule offset in words: `+3` is three days early, `0` is on the day, `-5` is five late.
     * Rendered rather than the raw number, because a signed integer in a table column is a puzzle.
     */
    public function offsetCaption(): string
    {
        return match (true) {
            $this->offset_days > 0 => sprintf('%d day%s before due', $this->offset_days, $this->offset_days === 1 ? '' : 's'),
            $this->offset_days < 0 => sprintf('%d day%s overdue', abs($this->offset_days), abs($this->offset_days) === 1 ? '' : 's'),
            default => 'On the due date',
        };
    }

    /** "The scheduler" is a real answer to "who sent this" and the screen says so rather than a dash. */
    public function senderCaption(): string
    {
        return $this->is_manual
            ? ($this->sender?->name ?? 'a member of staff')
            : 'the scheduler';
    }

    /**
     * Reminders a student may see on their own panel — their own, newest first.
     */
    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId)->orderByDesc('sent_at');
    }

    public function scopeSentOn(Builder $query, Carbon $date): Builder
    {
        return $query->whereDate('sent_at', $date->toDateString());
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (§2.2)
    |--------------------------------------------------------------------------
    */

    public function fee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class, 'student_fee_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(StudentFeeInstallment::class, 'student_fee_installment_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
