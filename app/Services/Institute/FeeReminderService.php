<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\ReminderRunResult;
use App\DataObjects\Support\AudienceInput;
use App\Enums\FeeReminderType;
use App\Enums\InstallmentStatus;
use App\Enums\StudentFeeStatus;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeeInstallment;
use App\Models\Institute\StudentFeeReminder;
use App\Models\User;
use App\Services\Institute\Exceptions\FeeRuleException;
use App\Services\Support\NotificationService;
use App\Support\Format;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Telling students what they owe, at most once per line per day (phase-18 §6.8, requirement §97).
 *
 * **The row is written before the message is sent, and that order is the entire dedupe design.**
 * `uq_sfr_dedupe` covers `(student_fee_id, dedupe_line, type, due_date, offset_days)`. Every path into
 * this service — the nightly job, a retry of that job after a worker died, a second scheduler tick, and
 * a member of staff pressing "Send reminder now" — races for the same row, exactly one INSERT wins, and
 * only the winner sends. A 1062 is counted as `skipped_duplicate` and is the guard doing its job.
 *
 * Sending first and logging after would look identical on a good night and chase the student twice on a
 * bad one, which is the failure this whole table exists to prevent.
 *
 * **[D18-9] `institute.installment_reminder_days` is one number, not a list.** §6.8 reads as though it
 * iterates a set of offsets; Phase 2 declared the key as a single integer lead time (default 3, `0`
 * disables), and that is what is in the database. This service uses the key as it actually exists
 * rather than redefining it — CLAUDE.md §8 is explicit that a settings key is used as defined, not
 * redefined later. One lead-time reminder, one on the day, and one cadence for overdue is three
 * reminders per line, which is what §97 asks for.
 */
final class FeeReminderService
{
    /** Rows per chunk. Large enough to be one query, small enough that a failure re-runs cheaply. */
    private const CHUNK = 500;

    /** How often an already-overdue line is chased again, in days. */
    private const OVERDUE_CADENCE = 7;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * One nightly pass (§6.8).
     *
     * `$limit` bounds the run because this grows with the student body (R-9); a bounded run that
     * self-heals the next night beats an unbounded one that times out halfway and leaves no record of
     * where it got to.
     */
    public function queueDueReminders(?CarbonInterface $asOf = null, ?int $limit = null): ReminderRunResult
    {
        $today = CarbonImmutable::parse(($asOf ?? Carbon::now(Format::timezone()))->toDateString());
        $runUuid = (string) Str::uuid();
        $result = new ReminderRunResult(runUuid: $runUuid);

        foreach ($this->schedule($today) as [$type, $dueDate, $offset]) {
            $result = $this->sweep($type, $dueDate, $offset, $today, $runUuid, $limit, $result);
        }

        return $result;
    }

    /**
     * "Send reminder now" (§6.8) — the manual path, under the same unique guard.
     *
     * Pressing it twice in a minute sends once and says so, because the second press collides with the
     * first press's row rather than with a check that might not have committed yet.
     */
    public function sendNow(
        StudentFee|StudentFeeInstallment $subject,
        FeeReminderType $type,
        User $actor,
    ): ?StudentFeeReminder {
        $fee = $subject instanceof StudentFeeInstallment ? $subject->fee : $subject;
        $line = $subject instanceof StudentFeeInstallment ? $subject : null;

        if ($fee === null) {
            throw FeeRuleException::refuse('student_fee_id', 'That installment has no charge behind it.');
        }

        if ($fee->status === StudentFeeStatus::Cancelled) {
            throw FeeRuleException::chargeIsClosed($fee->status->label());
        }

        $dueDate = $line?->due_date ?? $fee->due_date;

        if ($dueDate === null) {
            throw FeeRuleException::refuse('due_date',
                'This charge has no due date, so there is nothing to remind anybody about yet.');
        }

        $today = CarbonImmutable::parse(Carbon::now(Format::timezone())->toDateString());
        $due = CarbonImmutable::parse($dueDate->toDateString());

        return $this->write(
            fee: $fee,
            line: $line,
            type: $type,
            dueDate: $due,
            // The real distance to the due date, so a manual send and the scheduled send for the same
            // day land on the same dedupe key instead of quietly both going out.
            offset: (int) $today->diffInDays($due, false),
            runUuid: null,
            actor: $actor,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The three questions asked each night, as (type, the due date to look for, the offset to record).
     *
     * @return list<array{0: FeeReminderType, 1: CarbonImmutable|null, 2: int}>
     */
    private function schedule(CarbonImmutable $today): array
    {
        $lead = max(0, (int) setting('institute.installment_reminder_days', 3));

        $schedule = [
            [FeeReminderType::DueToday, $today, 0],
            // `null` due date = "anything before today", handled by `sweep()`; the offset is filled in
            // per row, because a line five days late and one fifty days late are different reminders.
            [FeeReminderType::Overdue, null, 0],
        ];

        // 0 disables the lead-time reminder, exactly as the setting's own help text says.
        if ($lead > 0) {
            array_unshift($schedule, [FeeReminderType::UpcomingDue, $today->addDays($lead), $lead]);
        }

        return $schedule;
    }

    private function sweep(
        FeeReminderType $type,
        ?CarbonImmutable $dueDate,
        int $offset,
        CarbonImmutable $today,
        string $runUuid,
        ?int $limit,
        ReminderRunResult $result,
    ): ReminderRunResult {
        foreach ($this->dueLines($type, $dueDate, $today, $limit) as $row) {
            [$fee, $line, $due] = $row;

            $result = $result->with(examined: 1);

            // An overdue line's offset is how late it actually is, so each week's chase is its own row
            // rather than colliding with last week's.
            $rowOffset = $type === FeeReminderType::Overdue
                ? -1 * (int) $due->diffInDays($today)
                : $offset;

            try {
                $written = $this->write($fee, $line, $type, $due, $rowOffset, $runUuid, null);
            } catch (UniqueConstraintViolationException) {
                $result = $result->with(skippedDuplicate: 1);

                continue;
            } catch (Throwable) {
                $result = $result->with(failed: 1);

                continue;
            }

            $result = $written === null
                ? $result->with(skippedNoRecipient: 1)
                : $result->with(sent: 1);
        }

        return $result;
    }

    /**
     * The lines (or plan-less charges) a reminder is owed for.
     *
     * Both queries lead with `(due_date, status)`, which is the index the contract requires them to
     * use — a sweeper that table-scans is one that gets switched off the first busy month.
     *
     * @return list<array{0: StudentFee, 1: StudentFeeInstallment|null, 2: CarbonImmutable}>
     */
    private function dueLines(
        FeeReminderType $type,
        ?CarbonImmutable $dueDate,
        CarbonImmutable $today,
        ?int $limit,
    ): array {
        $openLine = [
            InstallmentStatus::Pending->value,
            InstallmentStatus::Partial->value,
            InstallmentStatus::Overdue->value,
        ];

        $openCharge = [
            StudentFeeStatus::Pending->value,
            StudentFeeStatus::Partial->value,
            StudentFeeStatus::Overdue->value,
        ];

        $lines = StudentFeeInstallment::query()
            ->whereIn('status', $openLine)
            ->when($dueDate !== null, fn ($q) => $q->whereDate('due_date', $dueDate->toDateString()))
            ->when($dueDate === null, fn ($q) => $q
                ->whereDate('due_date', '<', $today->toDateString())
                // Chase weekly rather than nightly: a student who owes money on the 3rd does not need
                // telling again on the 4th, and a system that does it anyway gets filtered to spam.
                ->whereRaw('DATEDIFF(?, `due_date`) % ? = 0', [$today->toDateString(), self::OVERDUE_CADENCE]))
            ->whereRaw('(`amount` - `paid_amount` - `waived_amount`) > 0')
            ->with('fee')
            ->orderBy('due_date')
            ->limit($limit ?? self::CHUNK)
            ->get();

        $out = [];

        foreach ($lines as $line) {
            $fee = $line->fee;

            if ($fee === null || $fee->status === StudentFeeStatus::Cancelled) {
                continue;
            }

            $out[] = [$fee, $line, CarbonImmutable::parse($line->due_date->toDateString())];
        }

        // A charge with no plan is reminded about itself. `dedupe_line` is what makes that safe: the
        // generated column is 0 for these rows, so they sit under the same unique index instead of
        // slipping past it on a NULL ([D18-1]).
        $charges = StudentFee::query()
            ->whereIn('status', $openCharge)
            ->where('has_installment_plan', false)
            ->where('balance_amount', '>', 0)
            ->when($dueDate !== null, fn ($q) => $q->whereDate('due_date', $dueDate->toDateString()))
            ->when($dueDate === null, fn ($q) => $q
                ->whereDate('due_date', '<', $today->toDateString())
                ->whereRaw('DATEDIFF(?, `due_date`) % ? = 0', [$today->toDateString(), self::OVERDUE_CADENCE]))
            ->orderBy('due_date')
            ->limit($limit ?? self::CHUNK)
            ->get();

        foreach ($charges as $charge) {
            if ($charge->due_date === null) {
                continue;
            }

            $out[] = [$charge, null, CarbonImmutable::parse($charge->due_date->toDateString())];
        }

        return $out;
    }

    /**
     * Insert the row, then send. Returns null when there is nobody to write to.
     *
     * The `UniqueConstraintViolationException` is deliberately **not** caught here — the callers treat
     * it differently. The sweep counts it as a duplicate and moves on; `sendNow()` lets it surface so
     * the screen can say "already sent today" rather than pretending it sent a second one.
     */
    private function write(
        StudentFee $fee,
        ?StudentFeeInstallment $line,
        FeeReminderType $type,
        CarbonImmutable $dueDate,
        int $offset,
        ?string $runUuid,
        ?User $actor,
    ): ?StudentFeeReminder {
        $student = $fee->student;
        $recipient = $student?->user;

        if ($student === null) {
            return null;
        }

        $outstanding = $line !== null
            ? Money::sub(Money::sub((string) $line->amount, (string) $line->paid_amount), (string) $line->waived_amount)
            : (string) $fee->balance_amount;

        $saved = $this->db->transaction(function () use (
            $fee, $line, $student, $type, $dueDate, $offset, $runUuid, $actor, $outstanding
        ): StudentFeeReminder {
            $reminder = new StudentFeeReminder;

            $reminder->forceFill([
                'student_fee_id' => $fee->getKey(),
                'student_fee_installment_id' => $line?->getKey(),
                'student_id' => $student->getKey(),
                'branch_id' => $fee->branch_id,
                'type' => $type->value,
                'channel' => 'database',
                'due_date' => $dueDate->toDateString(),
                'offset_days' => $offset,
                'amount_due' => Money::of($outstanding),
                'recipient_email' => $student->email,
                'recipient_phone' => $student->phone ?? $student->guardian_phone,
                'sent_at' => Carbon::now(),
                // `chk_sfr_origin`: a manual send names a person, a scheduled one names a run. Neither
                // may be anonymous, because "who chased me" is where a complaint starts.
                'sent_by' => $actor?->getKey(),
                'is_manual' => $actor !== null,
                'run_uuid' => $actor !== null ? null : $runUuid,
                'created_at' => Carbon::now(),
            ])->save();

            // The row is written first and unconditionally, because "we decided to chase them" is
            // worth recording on a night nobody could be reached — and the dedupe guard has to hold
            // across that gap, or the next run chases them twice. The notification goes out after
            // this transaction returns; see the note below the closure.
            return $reminder->refresh();
        });

        // **Dispatched after the transaction returns, not inside it** (INV-22-7, INV-22-8).
        //
        // `NotificationService` marks every notification `afterCommit`, and a notification queued
        // inside a *nested* transaction loses that callback entirely when the savepoint commits —
        // it is neither run nor handed up to the parent. In production this path is the outermost
        // transaction and it would have worked; under any caller that already had one open — a
        // command wrapping a batch, a test, a probe — the student would simply never have been
        // told, with nothing logged and the reminder row sitting there saying they had been.
        //
        // Sending from out here removes the nesting question altogether, and matches what
        // `TicketService` and `MeetingService` already do.
        if ($recipient !== null) {
            $this->notifications->dispatch(
                $type->notificationEventKey(),
                AudienceInput::of($recipient),
                [
                    'title' => $type->isLate() ? 'A fee payment is overdue' : 'A fee payment is due',
                    'body' => sprintf(
                        '%s is outstanding, due %s.',
                        Format::money($outstanding),
                        $dueDate->format('j M Y'),
                    ),
                    'student_fee_id' => (int) $fee->getKey(),
                    'installment_id' => $line?->getKey(),
                    'reminder_id' => (int) $saved->getKey(),
                    'amount_due' => (string) $outstanding,
                    'due_date' => $dueDate->toDateString(),
                ],
                $actor,
            );
        }

        return $saved;
    }
}
