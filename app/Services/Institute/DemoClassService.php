<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\SlotCandidate;
use App\Enums\CourseInquiryStatus;
use App\Enums\DeliveryMode;
use App\Enums\DemoClassStatus;
use App\Enums\DemoSubjectType;
use App\Models\Institute\CourseInquiry;
use App\Models\Institute\DemoClass;
use App\Models\Institute\Student;
use App\Models\Institute\StudentApplication;
use App\Models\User;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Services\Institute\Exceptions\ScheduleClashException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * §87's trial class (phase-14-17 §6.12, §2.30.6).
 *
 * **A demo holds a slot, so booking one is a scheduling act.** Phase 16's `ScheduleClashDetector`
 * explains an overlap in words; until it ships, this service does the check it can do honestly —
 * the exact-slot lookup, backed by `uq_dc_teacher_slot` and `uq_dc_room_slot`, which bite even under
 * a race. What it does not do is pretend to have detected an overlap it cannot yet see.
 *
 * **The subject is snapshotted, not joined.** `attendee_name` and `attendee_phone` are copied at
 * booking, because the slip a receptionist prints has to name who is coming and the enquiry behind it
 * may be converted or renamed by then.
 *
 * **Every outcome moves the enquiry too.** A demo attended makes the enquiry `interested`; a demo
 * missed does not condemn it, but a demo cancelled by the institute changes nothing about what the
 * person wants. Those moves go through `CourseInquiryService::changeStatus()`, so §2.30.2 applies to
 * them exactly as it does to a counsellor clicking the same thing.
 */
final class DemoClassService
{
    /** §2.30.6, verbatim. */
    private const TRANSITIONS = [
        'scheduled' => ['attended', 'missed', 'cancelled'],
        'attended' => ['converted'],
        'missed' => ['converted'],
        'converted' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CourseInquiryService $inquiries,
        private readonly ScheduleClashDetector $detector,
    ) {}

    /**
     * Book one. `$subject` is the enquiry, application or student it is for — exactly one of the three.
     *
     * @param  array<string, mixed>  $data
     */
    public function schedule(Model $subject, array $data, ?User $actor = null): DemoClass
    {
        [$type, $column, $name, $phone] = $this->describe($subject);

        $start = $this->time($data['start_time'] ?? null, 'start_time');
        $end = isset($data['end_time']) && $data['end_time'] !== null
            ? $this->time($data['end_time'], 'end_time')
            : $this->defaultEnd($start);

        if ($end <= $start) {
            throw CourseRuleException::refuse('end_time',
                'A demo cannot end before it starts.');
        }

        $on = Carbon::parse((string) ($data['scheduled_on'] ?? Carbon::now()->toDateString()))->toDateString();

        $this->assertSlotIsFree($data, $on, $start, $end);

        try {
            return $this->db->transaction(function () use ($subject, $type, $column, $name, $phone, $data, $on, $start, $end, $actor): DemoClass {
                $demo = new DemoClass;

                $demo->fill([
                    'branch_id' => $data['branch_id'] ?? null,
                    'course_id' => $data['course_id'] ?? null,
                    'batch_id' => $data['batch_id'] ?? null,
                    'teacher_id' => $data['teacher_id'] ?? null,
                    'classroom_id' => $data['classroom_id'] ?? null,
                    'delivery_mode' => $data['delivery_mode'] ?? 'physical',
                    'meeting_url' => $data['meeting_url'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);

                $demo->forceFill([
                    'subject_type' => $type->value,
                    $column => $subject->getKey(),
                    'attendee_name' => $data['attendee_name'] ?? $name,
                    'attendee_phone' => $data['attendee_phone'] ?? $phone,
                    'scheduled_on' => $on,
                    'start_time' => $start,
                    'end_time' => $end,
                    'status' => DemoClassStatus::Scheduled->value,
                    'created_by' => $actor?->getKey(),
                ])->save();

                // §2.30.2: a booked demo is where the enquiry now is.
                if ($subject instanceof CourseInquiry
                    && in_array($subject->status, [CourseInquiryStatus::New, CourseInquiryStatus::Contacted, CourseInquiryStatus::Interested], true)) {
                    $this->inquiries->changeStatus($subject, CourseInquiryStatus::DemoScheduled, null, $actor, silent: true);
                }

                return $demo->refresh();
            }, 3);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw CourseRuleException::refuse('start_time',
                    'That teacher or that room is already booked for this slot. Two people arriving at '
                    .'one door is the thing this refusal exists to prevent.');
            }

            throw $e;
        }
    }

    /**
     * Move it. The old slot is freed by the update itself: `active_guard` only holds while scheduled,
     * and the row stays scheduled, so the guards are re-evaluated on the new time.
     *
     * @param  array<string, mixed>  $slot
     */
    public function reschedule(DemoClass $demo, array $slot, string $reason, ?User $actor = null): DemoClass
    {
        if ($demo->status !== DemoClassStatus::Scheduled) {
            throw CourseRuleException::refuse('status', sprintf(
                'Only a scheduled demo can be moved; this one is %s.', $demo->status->label(),
            ));
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::reasonRequired('reason',
                'Moving a demo takes a reason — somebody has already been told the old time.');
        }

        $start = $this->time($slot['start_time'] ?? $demo->start_time, 'start_time');
        $end = isset($slot['end_time']) && $slot['end_time'] !== null
            ? $this->time($slot['end_time'], 'end_time')
            : $this->defaultEnd($start);
        $on = Carbon::parse((string) ($slot['scheduled_on'] ?? $demo->scheduled_on))->toDateString();

        if ($end <= $start) {
            throw CourseRuleException::refuse('end_time', 'A demo cannot end before it starts.');
        }

        $this->assertSlotIsFree([
            'teacher_id' => $slot['teacher_id'] ?? $demo->teacher_id,
            'classroom_id' => $slot['classroom_id'] ?? $demo->classroom_id,
            'batch_id' => $slot['batch_id'] ?? $demo->batch_id,
            'delivery_mode' => $slot['delivery_mode'] ?? $demo->delivery_mode,
        ], $on, $start, $end, ignore: $demo);

        return $this->db->transaction(function () use ($demo, $slot, $on, $start, $end, $reason, $actor): DemoClass {
            $demo->withReason($reason);

            $demo->forceFill([
                'scheduled_on' => $on,
                'start_time' => $start,
                'end_time' => $end,
                'teacher_id' => $slot['teacher_id'] ?? $demo->teacher_id,
                'classroom_id' => $slot['classroom_id'] ?? $demo->classroom_id,
                'updated_by' => $actor?->getKey(),
            ])->save();

            return $demo->refresh();
        }, 3);
    }

    public function markAttended(DemoClass $demo, ?string $remarks = null, ?User $actor = null): DemoClass
    {
        return $this->changeStatus($demo, DemoClassStatus::Attended, null, $actor, [
            'attended_at' => Carbon::now(),
            'attendance_remarks' => $remarks,
        ]);
    }

    public function markMissed(DemoClass $demo, ?string $remarks = null, ?User $actor = null): DemoClass
    {
        return $this->changeStatus($demo, DemoClassStatus::Missed, null, $actor, [
            'attendance_remarks' => $remarks,
        ]);
    }

    public function cancel(DemoClass $demo, string $reason, ?User $actor = null): DemoClass
    {
        return $this->changeStatus($demo, DemoClassStatus::Cancelled, $reason, $actor, [
            'cancellation_reason' => trim($reason),
        ]);
    }

    /**
     * §2.30.6, and the enquiry follows.
     *
     * @param  array<string, mixed>  $extra
     */
    public function changeStatus(
        DemoClass $demo,
        DemoClassStatus $to,
        ?string $reason = null,
        ?User $actor = null,
        array $extra = [],
    ): DemoClass {
        $from = $demo->status;
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw CourseRuleException::refuse('status', sprintf(
                'A demo cannot go from %s to %s. %s',
                $from->label(),
                $to->label(),
                $allowed === [] ? sprintf('%s is final.', $from->label()) : 'From here: '.implode(', ', $allowed).'.',
            ));
        }

        $reason = trim((string) $reason);

        if ($to === DemoClassStatus::Cancelled && $reason === '') {
            throw CourseRuleException::reasonRequired('reason',
                'Cancelling a demo takes a reason: the slot it held and why it was dropped are what a '
                .'teacher\'s week and the §88 report are made of.');
        }

        return $this->db->transaction(function () use ($demo, $to, $reason, $actor, $extra): DemoClass {
            if ($reason !== '') {
                $demo->withReason($reason);
            }

            $demo->forceFill(array_merge($extra, [
                'status' => $to->value,
                'updated_by' => $actor?->getKey(),
            ]))->save();

            $this->followUpInquiry($demo->refresh(), $to, $actor);

            return $demo;
        }, 3);
    }

    /**
     * The demo produced an admission. The caller has already created it — this records which demo it
     * came from, which is what the §88 demo-to-admission rate divides by.
     */
    public function markConverted(DemoClass $demo, int $admissionId, ?User $actor = null): DemoClass
    {
        return $this->changeStatus($demo, DemoClassStatus::Converted, null, $actor, [
            'converted_admission_id' => $admissionId,
        ]);
    }

    /**
     * The demos still sitting unmarked after their end time — what the daily job flags for somebody to
     * answer. It marks nothing itself: whether an attendee turned up is a fact only a person in the
     * room has, and a job that guessed would put a no-show on somebody's record.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, DemoClass>
     */
    public function unmarkedPast(?Carbon $now = null): \Illuminate\Database\Eloquent\Collection
    {
        $now ??= Carbon::now();

        return DemoClass::query()
            ->scheduled()
            ->where(function ($q) use ($now): void {
                $q->where('scheduled_on', '<', $now->toDateString())
                    ->orWhere(function ($inner) use ($now): void {
                        $inner->where('scheduled_on', $now->toDateString())
                            ->where('end_time', '<', $now->format('H:i:s'));
                    });
            })
            ->orderBy('scheduled_on')
            ->orderBy('start_time')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Which of the three subjects this is, which column it fills, and the two snapshot fields.
     *
     * @return array{0: DemoSubjectType, 1: string, 2: string, 3: string|null}
     */
    private function describe(Model $subject): array
    {
        return match (true) {
            $subject instanceof CourseInquiry => [
                DemoSubjectType::Inquiry, 'course_inquiry_id', (string) $subject->name, $subject->phone,
            ],
            $subject instanceof StudentApplication => [
                DemoSubjectType::Application, 'student_application_id', (string) $subject->name, $subject->phone,
            ],
            $subject instanceof Student => [
                DemoSubjectType::Student, 'student_id', (string) $subject->name, $subject->phone,
            ],
            default => throw CourseRuleException::refuse('subject', sprintf(
                'A demo is booked for an enquiry, an applicant or a student; %s is none of those.',
                $subject::class,
            )),
        };
    }

    /**
     * The real overlap check (phase-16 §6.7) — teacher and classroom, across every table that can
     * hold a slot.
     *
     * Phase 15 shipped this as an exact-start-time test because `timetable_entries` and
     * `class_sessions` did not exist yet; Phase 16 built them and the detector that reads them, so
     * this now asks the one authority. The two unique indexes underneath stay as the backstop for an
     * identical form submitted twice.
     *
     * **A demo naming a batch is sitting in on it (§2.16)**, so that batch's own class is not a
     * conflict — otherwise the one thing `batch_id` is for could never be booked.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertSlotIsFree(
        array $data,
        string $on,
        string $start,
        string $end,
        ?DemoClass $ignore = null,
    ): void {
        $mode = $data['delivery_mode'] ?? null;
        $mode = $mode instanceof DeliveryMode ? $mode : DeliveryMode::tryFrom((string) $mode);

        $candidate = new SlotCandidate(
            teacherId: isset($data['teacher_id']) ? (int) $data['teacher_id'] : null,
            classroomId: isset($data['classroom_id']) ? (int) $data['classroom_id'] : null,
            // A demo is for one person: it never claims a batch's hour (§6.7 dimension 3).
            batchId: null,
            startsAt: Carbon::parse($on.' '.$start),
            endsAt: Carbon::parse($on.' '.$end),
            ignoreType: $ignore !== null ? SlotCandidate::TYPE_DEMO_CLASS : null,
            ignoreId: $ignore?->getKey(),
            deliveryMode: $mode,
            joiningBatchId: isset($data['batch_id']) ? (int) $data['batch_id'] : null,
        );

        $report = $this->detector->check($candidate);

        if (! $report->clean) {
            throw ScheduleClashException::from($report, 'start_time');
        }
    }

    private function followUpInquiry(DemoClass $demo, DemoClassStatus $to, ?User $actor): void
    {
        $inquiry = $demo->inquiry;

        if (! $inquiry instanceof CourseInquiry || $inquiry->status !== CourseInquiryStatus::DemoScheduled) {
            return;
        }

        // Attending says they are still interested. Missing or cancelling says nothing about what they
        // want, so the enquiry goes back to `interested` for a counsellor to work rather than being
        // written off by an absence.
        $this->inquiries->changeStatus($inquiry, CourseInquiryStatus::Interested, null, $actor, silent: true);
    }

    private function time(mixed $value, string $field): string
    {
        $raw = trim((string) ($value ?? ''));

        if ($raw === '') {
            throw CourseRuleException::refuse($field, 'A demo needs a time.');
        }

        try {
            return Carbon::parse($raw)->format('H:i:s');
        } catch (\Throwable) {
            throw CourseRuleException::refuse($field, sprintf('"%s" is not a time.', $raw));
        }
    }

    private function defaultEnd(string $start): string
    {
        $minutes = max(15, (int) setting('institute.demo_class_duration_minutes', 60));

        return Carbon::parse($start)->addMinutes($minutes)->format('H:i:s');
    }
}
