<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Enums\DeliveryMode;
use App\Enums\Weekday;
use App\Models\Institute\ClassSession;
use App\Models\Institute\DemoClass;
use App\Models\Institute\TimetableEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * "This teacher, this room, this batch, from here to here" — the one shape every booking is asked in
 * before it is written (phase-14-17 §6.7, F-4.7).
 *
 * **Named arguments, not positions.** Three nullable ids followed by two times is exactly the kind of
 * signature where a caller eventually swaps two and nobody notices for a month, so the constructor is
 * only ever called by name. A null id means "skip that dimension", which is how an online class opts
 * out of room checking without the detector knowing anything about delivery modes.
 *
 * **The four recurring properties are additive.** An exam or a meeting — Phase 19-23's callers —
 * builds this with the seven canonical properties and nothing else, and gets a dated check. A §71
 * weekly rule sets `dayOfWeek` and its window, and the day predicate applies instead of the date one.
 * One DTO, because a second one would mean a second overlap implementation the day someone forgot
 * which to use.
 *
 * `ignoreType` + `ignoreId` are how an edit stops clashing with itself — without them, saving a slot
 * unchanged would report it as its own conflict.
 */
final readonly class SlotCandidate
{
    public const TYPE_TIMETABLE_ENTRY = 'timetable_entry';

    public const TYPE_CLASS_SESSION = 'class_session';

    public const TYPE_DEMO_CLASS = 'demo_class';

    public function __construct(
        public ?int $teacherId = null,
        public ?int $classroomId = null,
        public ?int $batchId = null,
        public ?CarbonInterface $startsAt = null,
        public ?CarbonInterface $endsAt = null,
        public ?string $ignoreType = null,
        public ?int $ignoreId = null,
        // The recurring extension: set together, or not at all.
        public ?Weekday $dayOfWeek = null,
        public ?CarbonInterface $effectiveFrom = null,
        public ?CarbonInterface $effectiveTo = null,
        public ?DeliveryMode $deliveryMode = null,
        /**
         * The batch this booking is JOINING rather than competing with (§2.16's "sit in on this
         * batch"). Its teacher and its room are the ones being sat in on, so its own bookings are
         * not conflicts — without this, the one thing a demo's `batch_id` is for could never be
         * scheduled, because the batch's own class always holds that teacher and that room.
         */
        public ?int $joiningBatchId = null,
        /**
         * Further rows that are not conflicts, as `['type' => 'timetable_entry', 'id' => 12]`.
         *
         * A dated class generated from a weekly rule occupies the same hour as that rule, because it
         * IS that rule's occurrence. Checking one against the other would always clash, so the rule a
         * class came from goes here — one pair of `ignoreType`/`ignoreId` is not enough to say "this
         * booking and its own parent".
         *
         * @var list<array{type: string, id: int}>
         */
        public array $alsoIgnore = [],
        /**
         * Ignore every dated class this weekly rule produced.
         *
         * A rule and its own occurrences hold the same hour, because the occurrences are what
         * holding it looks like. Editing a rule therefore has to be blind to its own classes, and
         * one id is not enough — there are as many as the horizon is long.
         */
        public ?int $ignoreGeneratedBy = null,
    ) {}

    /**
     * A weekly rule rather than a dated slot. The times still matter — a Monday 09:00 rule and a
     * Monday 09:30 rule overlap — but the day and the effective window replace the date.
     */
    public function isRecurring(): bool
    {
        return $this->dayOfWeek !== null;
    }

    /** `HH:MM:SS`, the form both tables store their times in. */
    public function startTime(): string
    {
        return Carbon::parse($this->startsAt)->format('H:i:s');
    }

    public function endTime(): string
    {
        return Carbon::parse($this->endsAt)->format('H:i:s');
    }

    public function date(): string
    {
        return Carbon::parse($this->startsAt)->toDateString();
    }

    public function effectiveFromDate(): string
    {
        return Carbon::parse($this->effectiveFrom ?? $this->startsAt)->toDateString();
    }

    /** An open-ended rule runs until the far future, so every window predicate has two bounds. */
    public function effectiveToDate(): string
    {
        return $this->effectiveTo !== null
            ? Carbon::parse($this->effectiveTo)->toDateString()
            : TimetableEntry::OPEN_ENDED;
    }

    /**
     * Is the classroom dimension in play at all? An online class occupies no room, whatever room it
     * happens to name — the same rule `DeliveryMode::needsClassroom()` states once.
     */
    public function occupiesAClassroom(): bool
    {
        if ($this->classroomId === null) {
            return false;
        }

        return $this->deliveryMode === null || $this->deliveryMode->needsClassroom();
    }

    public function ignoring(string $type, ?int $id): self
    {
        return new self(
            teacherId: $this->teacherId,
            classroomId: $this->classroomId,
            batchId: $this->batchId,
            startsAt: $this->startsAt,
            endsAt: $this->endsAt,
            ignoreType: $type,
            ignoreId: $id,
            dayOfWeek: $this->dayOfWeek,
            effectiveFrom: $this->effectiveFrom,
            effectiveTo: $this->effectiveTo,
            deliveryMode: $this->deliveryMode,
            joiningBatchId: $this->joiningBatchId,
            alsoIgnore: $this->alsoIgnore,
            ignoreGeneratedBy: $this->ignoreGeneratedBy,
        );
    }

    public function alsoIgnoring(string $type, ?int $id): self
    {
        if ($id === null) {
            return $this;
        }

        return new self(
            teacherId: $this->teacherId,
            classroomId: $this->classroomId,
            batchId: $this->batchId,
            startsAt: $this->startsAt,
            endsAt: $this->endsAt,
            ignoreType: $this->ignoreType,
            ignoreId: $this->ignoreId,
            dayOfWeek: $this->dayOfWeek,
            effectiveFrom: $this->effectiveFrom,
            effectiveTo: $this->effectiveTo,
            deliveryMode: $this->deliveryMode,
            joiningBatchId: $this->joiningBatchId,
            alsoIgnore: [...$this->alsoIgnore, ['type' => $type, 'id' => $id]],
            ignoreGeneratedBy: $this->ignoreGeneratedBy,
        );
    }

    /**
     * Every id of one type this candidate must not be told about.
     *
     * @return list<int>
     */
    public function ignoredIdsOf(string $type): array
    {
        $ids = [];

        if ($this->ignoreType === $type && $this->ignoreId !== null) {
            $ids[] = $this->ignoreId;
        }

        foreach ($this->alsoIgnore as $pair) {
            if (($pair['type'] ?? null) === $type && isset($pair['id'])) {
                $ids[] = (int) $pair['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /*
    |--------------------------------------------------------------------------
    | The three subject wrappers (§6.7) — thin by contract
    |--------------------------------------------------------------------------
    |
    | Each one reads its model and hands back a candidate. A wrapper that contained an overlap
    | predicate would be a second implementation of the thing this class exists to have one of.
    */

    public static function forTimetableEntry(TimetableEntry $entry): self
    {
        $anchor = Carbon::parse($entry->effective_from->toDateString());

        return new self(
            teacherId: $entry->teacher_id,
            classroomId: $entry->classroom_id,
            batchId: $entry->batch_id,
            startsAt: Carbon::parse($anchor->toDateString().' '.$entry->start_time),
            endsAt: Carbon::parse($anchor->toDateString().' '.$entry->end_time),
            ignoreType: $entry->exists ? self::TYPE_TIMETABLE_ENTRY : null,
            ignoreId: $entry->exists ? $entry->id : null,
            dayOfWeek: $entry->day_of_week,
            effectiveFrom: $entry->effective_from,
            effectiveTo: $entry->effective_to,
            deliveryMode: $entry->delivery_mode,
            // The classes this rule already produced are this rule, dated. Checking one against the
            // other would make every edit of a live timetable slot a clash with itself.
            ignoreGeneratedBy: $entry->exists ? $entry->id : null,
        );
    }

    public static function forClassSession(ClassSession $session): self
    {
        return new self(
            teacherId: $session->teacher_id,
            classroomId: $session->classroom_id,
            batchId: $session->batch_id,
            startsAt: $session->startsAt(),
            endsAt: $session->endsAt(),
            ignoreType: $session->exists ? self::TYPE_CLASS_SESSION : null,
            ignoreId: $session->exists ? $session->id : null,
            deliveryMode: $session->delivery_mode,
            // The weekly rule this class came from holds the same hour, because this class is what
            // holding it looks like. Without this, every substitution clashes with its own timetable.
            alsoIgnore: $session->timetable_entry_id !== null
                ? [['type' => self::TYPE_TIMETABLE_ENTRY, 'id' => (int) $session->timetable_entry_id]]
                : [],
        );
    }

    public static function forDemoClass(DemoClass $demo): self
    {
        return new self(
            teacherId: $demo->teacher_id,
            classroomId: $demo->classroom_id,
            // A demo is for one person, not a batch: it never blocks a batch's own hour.
            batchId: null,
            startsAt: $demo->startsAt(),
            endsAt: $demo->endsAt(),
            ignoreType: $demo->exists ? self::TYPE_DEMO_CLASS : null,
            ignoreId: $demo->exists ? $demo->id : null,
            deliveryMode: $demo->delivery_mode,
            joiningBatchId: $demo->batch_id,
        );
    }
}
