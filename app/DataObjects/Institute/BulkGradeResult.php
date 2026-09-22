<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

/**
 * What one pass over the grading grid did (phase-19-23 §6.8).
 *
 * **Either every row was written or none was.** `errors` keyed by row index is what lets the grid put
 * the message back against the field that caused it, rather than showing one failure at the top of a
 * page of thirty students and leaving the teacher to find which one.
 *
 * `graded` is therefore always 0 when `errors` is non-empty — the two are not independent counts, and
 * a result reporting "27 graded, 3 errors" would mean the all-or-nothing rule had been broken.
 */
final readonly class BulkGradeResult
{
    /**
     * @param  array<int, string>  $errors  row index => what was wrong with it
     */
    public function __construct(
        public int $graded = 0,
        public array $errors = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->errors === [];
    }

    /** One sentence for the toast. */
    public function summary(): string
    {
        if (! $this->succeeded()) {
            $count = count($this->errors);

            return sprintf(
                'Nothing was saved — %d %s need%s fixing first.',
                $count,
                $count === 1 ? 'row' : 'rows',
                $count === 1 ? 's' : '',
            );
        }

        return $this->graded === 1 ? 'One submission marked.' : $this->graded.' submissions marked.';
    }
}
