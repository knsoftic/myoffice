<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

/**
 * What one submission of a register actually did (phase-14-17 §6.9).
 *
 * The marking screen needs more back than "saved": it re-renders the live counter strip, and it has
 * to be able to say "three of these were already marked and have been updated" rather than silently
 * overwriting somebody else's work. So the result carries both what was written and what the class
 * now looks like.
 *
 * `promoted` is the list of students whose `present` became `late` because their check-in was past
 * the grace minutes. That is a decision the service made on the user's behalf, and §8.15 says it must
 * be stated on screen rather than discovered later in a report.
 */
final readonly class AttendanceResult
{
    /**
     * @param  array<string, int>  $counts  status value => how many
     * @param  list<string>  $promoted  names of students whose present became late
     */
    public function __construct(
        public int $created,
        public int $updated,
        public array $counts,
        public string $percentage,
        public array $promoted = [],
    ) {}

    public function total(): int
    {
        return $this->created + $this->updated;
    }

    public function touchedAnything(): bool
    {
        return $this->total() > 0;
    }

    public function countOf(string $status): int
    {
        return $this->counts[$status] ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'total' => $this->total(),
            'counts' => $this->counts,
            'percentage' => $this->percentage,
            'promoted' => $this->promoted,
        ];
    }
}
