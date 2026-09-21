<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\BatchStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Institute\Batch;
use App\Models\Institute\Classroom;
use App\Models\Institute\Course;
use App\Models\User;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The batch itself (§70, phase-14-17 §6.11).
 *
 * **`current_students` and `sessions_held_count` are caches, and this class is the only writer**
 * (INV-I7, D48). Both are recounted from the rows that own the truth — never incremented, never
 * decremented — so a cache that drifts is repaired by running the recount rather than by somebody
 * working out what it should have been.
 *
 * **§2.30.7 is a table, not a set of ifs.** Every move a batch can make is declared once, with the
 * guard that has to hold and whether a reason is required; `changeStatus()` is the only door. The
 * guards are the interesting part: a batch cannot open for admission without a teacher and a
 * timetable, cannot be cancelled with students still in it, and cannot be completed while a class is
 * still outstanding — each one is a thing that looks fine on a screen and is wrong in a room.
 */
final class BatchService
{
    /** §2.30.7, verbatim. */
    private const TRANSITIONS = [
        'planned' => ['enrolling', 'running', 'cancelled'],
        'enrolling' => ['planned', 'running', 'on_hold', 'cancelled'],
        'running' => ['on_hold', 'completed'],
        'on_hold' => ['running', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    private const REASON_REQUIRED = ['on_hold', 'cancelled'];

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Batch
    {
        return $this->db->transaction(function () use ($data, $actor): Batch {
            $batch = new Batch;
            $batch->fill($this->columns($data));

            $course = Course::query()->findOrFail($data['course_id']);

            $this->assertDaysAreWorkingDays($batch->days);

            $batch->forceFill([
                'course_id' => $course->getKey(),
                'branch_id' => $data['branch_id'] ?? $actor?->branch_id ?? setting('institute.default_branch_id'),
                'student_capacity' => (int) ($data['student_capacity']
                    ?? setting('institute.batch_default_capacity', 20)),
                'status' => BatchStatus::Planned->value,
                'current_students' => 0,
                'sessions_held_count' => 0,
            ])->save();

            return $batch->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Batch $batch, array $data, ?User $actor = null): Batch
    {
        return $this->db->transaction(function () use ($batch, $data, $actor): Batch {
            $columns = $this->columns($data);

            // Everything is checked BEFORE the model is filled. A refusal that had already written
            // the rejected values onto the object hands the caller a model that disagrees with the
            // row it came from — and the caller usually re-renders it.
            if (isset($columns['student_capacity'])) {
                $this->assertCapacityFitsTheRoster($batch, (int) $columns['student_capacity']);
            }

            if (isset($data['course_id']) && (int) $data['course_id'] !== (int) $batch->course_id) {
                $this->assertNobodyIsEnrolled($batch);
                $columns['course_id'] = (int) $data['course_id'];
            }

            $this->assertDaysAreWorkingDays($columns['days'] ?? $batch->days);

            $batch->fill($columns);
            $batch->updated_by = $actor?->getKey();
            $batch->save();

            return $batch->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public function changeStatus(
        Batch $batch,
        BatchStatus $to,
        ?string $reason = null,
        ?User $actor = null,
    ): Batch {
        $from = $batch->status;

        if ($from === $to) {
            return $batch;
        }

        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw CourseRuleException::refuse('status', sprintf(
                'A batch cannot go from %s to %s. %s',
                $from->label(),
                $to->label(),
                $allowed === []
                    ? sprintf('%s is where a batch ends.', $from->label())
                    : 'From here: '.implode(', ', $allowed).'.',
            ));
        }

        $reason = trim((string) $reason);

        if (in_array($to->value, self::REASON_REQUIRED, true) && $reason === '') {
            throw CourseRuleException::reasonRequired('status', sprintf(
                'Putting a batch %s takes a reason. The roster is told, so somebody has to say what to tell them.',
                $to->label(),
            ));
        }

        $this->assertGuardFor($batch, $to);

        return $this->db->transaction(function () use ($batch, $to, $reason, $actor): Batch {
            $batch->forceFill([
                'status' => $to->value,
                'cancellation_reason' => $to === BatchStatus::Cancelled ? $reason : $batch->cancellation_reason,
                'completed_on' => $to === BatchStatus::Completed ? Carbon::today()->toDateString() : $batch->completed_on,
                'updated_by' => $actor?->getKey(),
            ])->save();

            // A batch that stops running stops holding its hours: the classes it had scheduled are
            // called off with the reason, so nobody turns up to a class that is not happening.
            if (in_array($to, [BatchStatus::OnHold, BatchStatus::Cancelled], true)) {
                $this->cancelFutureSessions($batch, $to, $reason, $actor);
            }

            return $batch->refresh();
        }, 3);
    }

    /**
     * The guards §2.30.7 attaches to particular moves.
     */
    private function assertGuardFor(Batch $batch, BatchStatus $to): void
    {
        if ($to === BatchStatus::Enrolling) {
            if ($batch->teacher_id === null) {
                throw CourseRuleException::refuse('status',
                    'A batch opens for admission once somebody is teaching it. Assign a teacher first.');
            }

            $hasTimetable = DB::table('timetable_entries')
                ->whereNull('deleted_at')
                ->where('batch_id', $batch->getKey())
                ->where('is_active', true)
                ->exists();

            if (! $hasTimetable) {
                throw CourseRuleException::refuse('status',
                    'A batch opens for admission once it has a timetable. Students are being asked to '
                    .'commit to hours nobody has set yet.');
            }

            return;
        }

        if ($to === BatchStatus::Planned) {
            $active = $this->recountStudents($batch);

            if ($active > 0) {
                throw CourseRuleException::refuse('status', sprintf(
                    'This batch has %d %s enrolled. Closing admission back to planned would leave them '
                    .'in a batch that is not open.',
                    $active,
                    $active === 1 ? 'student' : 'students',
                ));
            }

            return;
        }

        if ($to === BatchStatus::Running) {
            $active = $this->recountStudents($batch);

            if ($active === 0) {
                throw CourseRuleException::refuse('status',
                    'A batch cannot start running with nobody in it.');
            }

            return;
        }

        if ($to === BatchStatus::Completed) {
            $outstanding = DB::table('class_sessions')
                ->whereNull('deleted_at')
                ->where('batch_id', $batch->getKey())
                ->where('status', ClassSessionStatus::Scheduled->value)
                ->count();

            if ($outstanding > 0) {
                throw CourseRuleException::refuse('status', sprintf(
                    '%d %s still scheduled for this batch. Hold or cancel %s before closing it — a '
                    .'completed batch with classes still to come is a timetable nobody trusts.',
                    $outstanding,
                    $outstanding === 1 ? 'class is' : 'classes are',
                    $outstanding === 1 ? 'it' : 'them',
                ));
            }

            return;
        }

        if ($to === BatchStatus::Cancelled) {
            $active = $this->recountStudents($batch);

            if ($active > 0) {
                throw CourseRuleException::refuse('status', sprintf(
                    'This batch still has %d %s in it. Move or drop %s first — cancelling the batch '
                    .'underneath them would leave enrolments pointing at nothing.',
                    $active,
                    $active === 1 ? 'student' : 'students',
                    $active === 1 ? 'them' : 'them',
                ));
            }
        }
    }

    private function cancelFutureSessions(Batch $batch, BatchStatus $to, string $reason, ?User $actor): void
    {
        $detail = sprintf('Batch %s: %s', $to->label(), $reason);

        DB::table('class_sessions')
            ->whereNull('deleted_at')
            ->where('batch_id', $batch->getKey())
            ->where('status', ClassSessionStatus::Scheduled->value)
            ->whereDate('session_date', '>=', Carbon::today()->toDateString())
            ->update([
                'status' => ClassSessionStatus::Cancelled->value,
                'cancellation_reason' => 'batch_on_hold',
                'cancellation_detail' => mb_substr($detail, 0, 255),
                'updated_by' => $actor?->getKey(),
                'updated_at' => Carbon::now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The cache authorities (INV-I7)
    |--------------------------------------------------------------------------
    */

    public function recountStudents(Batch $batch): int
    {
        $count = (int) DB::table('student_batch_enrollments')
            ->whereNull('deleted_at')
            ->where('batch_id', $batch->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->count();

        if ((int) $batch->current_students !== $count) {
            DB::table('batches')->where('id', $batch->getKey())->update([
                'current_students' => $count,
                'updated_at' => Carbon::now(),
            ]);

            $batch->setAttribute('current_students', $count);
        }

        return $count;
    }

    public function recountSessions(Batch $batch): int
    {
        $held = (int) DB::table('class_sessions')
            ->whereNull('deleted_at')
            ->where('batch_id', $batch->getKey())
            ->where('status', ClassSessionStatus::Held->value)
            ->count();

        $planned = (int) DB::table('class_sessions')
            ->whereNull('deleted_at')
            ->where('batch_id', $batch->getKey())
            ->whereIn('status', [ClassSessionStatus::Scheduled->value, ClassSessionStatus::Held->value])
            ->count();

        DB::table('batches')->where('id', $batch->getKey())->update([
            'sessions_held_count' => $held,
            'sessions_planned_count' => $planned,
            'updated_at' => Carbon::now(),
        ]);

        $batch->setAttribute('sessions_held_count', $held);
        $batch->setAttribute('sessions_planned_count', $planned);

        return $held;
    }

    /**
     * The one source for the capacity meter, the public "seats left" label and the near-capacity
     * notice — so all three agree, including about which ceiling is actually binding.
     *
     * @return array<string, mixed>
     */
    public function capacitySnapshot(Batch $batch): array
    {
        $active = $this->recountStudents($batch);
        $capacity = (int) $batch->student_capacity;

        $room = $batch->classroom_id !== null ? Classroom::query()->find($batch->classroom_id) : null;
        $roomCapacity = $room instanceof Classroom && $room->capacityLimits() && $batch->delivery_mode->needsClassroom()
            ? (int) $room->capacity
            : null;

        $effective = $roomCapacity !== null ? min($capacity, $roomCapacity) : $capacity;

        return [
            'capacity' => $capacity,
            'active' => $active,
            'free' => max(0, $effective - $active),
            'percentage' => $effective > 0 ? round(($active / $effective) * 100, 1) : 0.0,
            'room_capacity' => $roomCapacity,
            'effective_capacity' => $effective,
            'is_full' => $active >= $effective,
            'is_near' => $effective > 0
                && ($active / $effective) * 100 >= (float) setting('institute.batch_near_capacity_threshold', 90),
        ];
    }

    /**
     * §89's "starting soon" strip and §90's batch table read this and nothing else.
     */
    public function upcomingForPublic(?Course $course = null, int $limit = 6): Collection
    {
        return Batch::query()
            ->where('status', BatchStatus::Enrolling->value)
            ->whereDate('start_date', '>=', Carbon::today()->toDateString())
            ->whereColumn('current_students', '<', 'student_capacity')
            ->when($course !== null, static fn ($q) => $q->where('course_id', $course->getKey()))
            ->orderBy('start_date')
            ->limit($limit)
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function assertCapacityFitsTheRoster(Batch $batch, int $capacity): void
    {
        $active = $this->recountStudents($batch);

        if ($capacity >= $active) {
            return;
        }

        throw CourseRuleException::refuse('student_capacity', sprintf(
            'There are already %d students in this batch, so the capacity cannot be set to %d. '
            .'Nobody would be removed by the number — they would simply be over it.',
            $active,
            $capacity,
        ));
    }

    private function assertNobodyIsEnrolled(Batch $batch): void
    {
        $any = DB::table('student_batch_enrollments')
            ->whereNull('deleted_at')
            ->where('batch_id', $batch->getKey())
            ->exists();

        if ($any) {
            throw CourseRuleException::refuse('course_id',
                'This batch already has enrolments, so its course cannot be changed. The students '
                .'enrolled on the course they were shown; changing it underneath them would rewrite '
                .'what they signed up for.');
        }
    }

    /**
     * A batch that meets on a day the institute does not open is a batch nobody can attend.
     */
    private function assertDaysAreWorkingDays(mixed $days): void
    {
        $working = (array) setting('institute.timetable_working_days', []);

        if ($working === []) {
            return;
        }

        foreach ((array) ($days ?? []) as $day) {
            if (! in_array($day, $working, true)) {
                throw CourseRuleException::refuse('days', sprintf(
                    'The institute does not teach on %s. Change the day, or add it to the working '
                    .'days in Settings.',
                    ucfirst((string) $day),
                ));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'branch_id', 'code', 'name', 'teacher_id', 'start_date', 'end_date', 'days',
            'start_time', 'end_time', 'classroom_id', 'delivery_mode', 'meeting_url',
            'student_capacity', 'notes',
        ]));
    }
}
