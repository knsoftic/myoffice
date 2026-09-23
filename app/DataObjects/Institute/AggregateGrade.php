<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Models\Institute\GradeScaleBand;

/**
 * One student's overall grade across a course's exams (phase-19-23 §6.10).
 *
 * **This is the figure a certificate prints**, and it is the same object the consolidated result card
 * reads — which is the whole point. §6.10 says `forStudent()` is "the input `CertificateService`
 * uses, so the certificate grade and the result card can never disagree". Two code paths computing an
 * aggregate would eventually produce two different As.
 *
 * **`percentage` is null when nothing counted**, never zero. A student with no published major exam
 * has no aggregate; printing `0.00%` on a certificate would say they scored nothing, which is a
 * different and defamatory claim. Every caller renders null as a dash and `CertificateService`
 * refuses to issue on it unless the grade source is `manual`.
 */
final readonly class AggregateGrade
{
    /**
     * @param  string  $mode  the `institute.certificate_grade_source` that produced it
     * @param  int  $examCount  how many exams actually contributed
     */
    public function __construct(
        public string $mode,
        public ?string $percentage = null,
        public ?string $grade = null,
        public ?string $gradePoint = null,
        public ?int $gradeScaleBandId = null,
        public int $examCount = 0,
    ) {}

    /** Nothing published, nothing to aggregate. */
    public static function none(string $mode): self
    {
        return new self(mode: $mode);
    }

    public static function fromBand(
        string $mode,
        string $percentage,
        ?GradeScaleBand $band,
        int $examCount,
    ): self {
        return new self(
            mode: $mode,
            percentage: $percentage,
            grade: $band?->getAttribute('grade'),
            gradePoint: $band?->getAttribute('grade_point') === null
                ? null
                : (string) $band->getAttribute('grade_point'),
            gradeScaleBandId: $band === null ? null : (int) $band->getKey(),
            examCount: $examCount,
        );
    }

    public function exists(): bool
    {
        return $this->percentage !== null;
    }

    /**
     * The columns a certificate snapshots.
     *
     * Returned as a map rather than written by the caller so the certificate, the result card and any
     * later consumer all copy the same four keys — and so adding a fifth is one edit.
     *
     * @return array<string, mixed>
     */
    public function toColumns(): array
    {
        return [
            'percentage' => $this->percentage,
            'grade' => $this->grade,
            'grade_point' => $this->gradePoint,
        ];
    }
}
