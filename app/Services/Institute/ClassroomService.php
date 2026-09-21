<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\ClassSessionStatus;
use App\Enums\ClassroomType;
use App\Models\Institute\Classroom;
use App\Models\User;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rooms (phase-14-17 §6.11).
 *
 * **Deactivation and deletion are both refused while the room is still on somebody's timetable**, and
 * they are refused differently. Switching a room off with a live booking would leave a class pointing
 * at a room the institute considers closed; deleting one would, because the foreign key is
 * `nullOnDelete`, quietly blank the room on every class that ever used it — a history that stops being
 * able to answer "where was that held". The key is the last line of defence; this is the first.
 */
final class ClassroomService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Classroom
    {
        return $this->db->transaction(function () use ($data, $actor): Classroom {
            $room = new Classroom;
            $room->fill($this->columns($data));

            $room->forceFill([
                'branch_id' => $data['branch_id'] ?? $actor?->branch_id ?? setting('institute.default_branch_id'),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ])->save();

            return $room->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Classroom $room, array $data, ?User $actor = null): Classroom
    {
        return $this->db->transaction(function () use ($room, $data, $actor): Classroom {
            $columns = $this->columns($data);

            // Shrinking a room below what is already booked into it is the one edit that cannot just
            // be saved: somebody would be standing, and nobody would find out until the class met.
            if (isset($columns['capacity'])) {
                $this->assertCapacityFitsWhatIsBooked($room, (int) $columns['capacity']);
            }

            $room->fill($columns);
            $room->updated_by = $actor?->getKey();
            $room->save();

            return $room->refresh();
        }, 3);
    }

    public function setActive(Classroom $room, bool $active, ?User $actor = null): Classroom
    {
        if (! $active && $room->is_active) {
            $this->assertNothingBooked($room, 'close');
        }

        return $this->db->transaction(function () use ($room, $active, $actor): Classroom {
            $room->forceFill([
                'is_active' => $active,
                'updated_by' => $actor?->getKey(),
            ])->save();

            return $room->refresh();
        }, 3);
    }

    public function delete(Classroom $room): void
    {
        $this->assertNeverUsed($room);

        $this->db->transaction(static function () use ($room): void {
            $room->delete();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | What is standing in the way
    |--------------------------------------------------------------------------
    */

    /**
     * Live bookings — the ones that would break if the room went away today.
     *
     * @return array{entries: int, sessions: int, demos: int, total: int}
     */
    public function liveBookings(Classroom $room): array
    {
        $entries = (int) DB::table('timetable_entries')
            ->whereNull('deleted_at')
            ->where('classroom_id', $room->getKey())
            ->where('is_active', true)
            ->count();

        $sessions = (int) DB::table('class_sessions')
            ->whereNull('deleted_at')
            ->where('classroom_id', $room->getKey())
            ->where('status', ClassSessionStatus::Scheduled->value)
            ->whereDate('session_date', '>=', Carbon::today()->toDateString())
            ->count();

        $demos = (int) DB::table('demo_classes')
            ->whereNull('deleted_at')
            ->where('classroom_id', $room->getKey())
            ->where('status', 'scheduled')
            ->whereDate('scheduled_on', '>=', Carbon::today()->toDateString())
            ->count();

        return [
            'entries' => $entries,
            'sessions' => $sessions,
            'demos' => $demos,
            'total' => $entries + $sessions + $demos,
        ];
    }

    private function assertNothingBooked(Classroom $room, string $verb): void
    {
        $booked = $this->liveBookings($room);

        if ($booked['total'] === 0) {
            return;
        }

        throw CourseRuleException::refuse('is_active', sprintf(
            'You cannot %s %s while it holds %d timetable %s, %d upcoming %s and %d %s. Move them '
            .'first — a class pointing at a closed room is one nobody can find.',
            $verb,
            $room->label(),
            $booked['entries'],
            $booked['entries'] === 1 ? 'slot' : 'slots',
            $booked['sessions'],
            $booked['sessions'] === 1 ? 'class' : 'classes',
            $booked['demos'],
            $booked['demos'] === 1 ? 'demo' : 'demos',
        ));
    }

    /**
     * Deletion is stricter than the foreign key: `nullOnDelete` would take the room off every class
     * that ever used it, and "where was that held" would stop having an answer.
     */
    private function assertNeverUsed(Classroom $room): void
    {
        $everUsed = DB::table('class_sessions')
            ->where('classroom_id', $room->getKey())
            ->exists();

        if ($everUsed) {
            throw CourseRuleException::refuse('id', sprintf(
                '%s has held classes. Close it instead of deleting it — removing the room would take '
                .'it off every class that was ever taught there.',
                $room->label(),
            ));
        }

        $this->assertNothingBooked($room, 'delete');
    }

    private function assertCapacityFitsWhatIsBooked(Classroom $room, int $capacity): void
    {
        if ($capacity < 1) {
            throw CourseRuleException::refuse('capacity', 'A room that seats nobody is not a room.');
        }

        if ($room->type === ClassroomType::Virtual) {
            return;
        }

        $biggest = DB::table('batches')
            ->whereNull('deleted_at')
            ->where('classroom_id', $room->getKey())
            ->whereIn('status', ['planned', 'enrolling', 'running'])
            ->orderByDesc('current_students')
            ->first(['code', 'current_students']);

        if ($biggest === null || (int) $biggest->current_students <= $capacity) {
            return;
        }

        throw CourseRuleException::refuse('capacity', sprintf(
            '%s already has %d students in it and you are setting the room to seat %d. Move the batch '
            .'or raise the number.',
            $biggest->code,
            (int) $biggest->current_students,
            $capacity,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'branch_id', 'code', 'name', 'type', 'capacity', 'location', 'notes',
        ]));
    }
}
