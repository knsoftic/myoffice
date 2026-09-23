<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

/**
 * What one pass over a result sheet did (phase-19-23 §6.10, INV-20-6).
 *
 * **Either every row was written or none was**, so `saved` and `updated` are both zero whenever
 * `errors` is non-empty. A result reporting "27 saved, 3 errors" would mean the all-or-nothing rule
 * had been broken, which is the one thing this object exists to make visible.
 *
 * `errors` is keyed by row index so the grid can put each message back against the student who caused
 * it — a single error at the top of a sheet of thirty leaves the marker hunting.
 */
final readonly class SheetResult
{
    /**
     * @param  array<int, string>  $errors  row index => what was wrong with it
     */
    public function __construct(
        public int $saved = 0,
        public int $updated = 0,
        public array $errors = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->errors === [];
    }

    public function total(): int
    {
        return $this->saved + $this->updated;
    }

    /** One sentence for the toast. */
    public function summary(): string
    {
        if (! $this->succeeded()) {
            $count = count($this->errors);

            return sprintf(
                'Nothing was saved — %d row%s need%s fixing first.',
                $count,
                $count === 1 ? '' : 's',
                $count === 1 ? 's' : '',
            );
        }

        if ($this->total() === 0) {
            return 'There was nothing to save.';
        }

        $parts = [];

        if ($this->saved > 0) {
            $parts[] = $this->saved.' entered';
        }

        if ($this->updated > 0) {
            $parts[] = $this->updated.' updated';
        }

        return 'Sheet saved — '.implode(', ', $parts).'.';
    }
}
