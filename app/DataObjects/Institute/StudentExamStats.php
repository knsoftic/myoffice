<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Models\Institute\ExamResult;
use Illuminate\Support\Collection;

/**
 * Everything one enrolment's exams add up to (phase-19-23 §6.10).
 *
 * **The single input `CertificateService` reads**, so the grade printed on a certificate and the grade
 * on the consolidated result card come from the same arithmetic. §6.10 is explicit about that, and the
 * reason is the obvious one: two code paths computing an aggregate eventually produce two different
 * As, and the one on the paper is the one that cannot be corrected.
 *
 * **`majorsPassed` and `majorsTotal` count only `isMajor()` exams**, which is midterms and finals.
 * A student who missed a weekly test in March is not refused a certificate for it, and one who failed
 * the final is — that narrowness is `ExamType::isMajor()`'s decision and this only reports it.
 *
 * **Published results only.** Anything else would let an unchecked sheet decide whether somebody
 * graduates.
 */
final readonly class StudentExamStats
{
    /**
     * @param  Collection<int, ExamResult>  $results  published results, oldest exam first
     */
    public function __construct(
        public Collection $results,
        public AggregateGrade $aggregate,
        public int $majorsTotal = 0,
        public int $majorsPassed = 0,
        public int $majorsMissing = 0,
    ) {}

    /**
     * Has this student passed every major exam the course has published?
     *
     * **A course with no major exams answers `true`**, and that is deliberate: a short practical
     * course may legitimately have none, and refusing every certificate on it would make the rule
     * impossible to satisfy rather than merely strict. `majorsMissing` is what distinguishes "passed
     * them all" from "there were none" for a screen that wants to say which.
     */
    public function passedEveryMajor(): bool
    {
        return $this->majorsMissing === 0 && $this->majorsPassed === $this->majorsTotal;
    }

    public function hasResults(): bool
    {
        return $this->results->isNotEmpty();
    }

    /** One sentence for the eligibility screen, naming the numbers rather than stating a rule. */
    public function describe(): string
    {
        if ($this->majorsTotal === 0) {
            return 'This course has no midterm or final exam published.';
        }

        if ($this->majorsMissing > 0) {
            return sprintf(
                '%d of %d major exams have no result for this student yet.',
                $this->majorsMissing,
                $this->majorsTotal,
            );
        }

        return sprintf('%d of %d major exams passed.', $this->majorsPassed, $this->majorsTotal);
    }
}
