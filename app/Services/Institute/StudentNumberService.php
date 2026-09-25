<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Models\Branch;
use App\Models\Institute\Course;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Services\Finance\DocumentNumberService;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Support\Carbon;

/**
 * The institute's six document numbers (phase-14-17 §6.5).
 *
 * **This class expands tokens. It does not count.** `App\Services\Finance\DocumentNumberService` is the
 * single numbering implementation in the system (D27, F-4.1) and it has existed since Phase 5, so the
 * locked counter, the period reset and the retry-once-on-1062 all live there. A second `FOR UPDATE`
 * over the same settings row is how a duplicate `student_code` is born — two paths, two locks, one
 * counter, and the duplicate appears on the day two receptionists register a student in the same
 * second. There is no local fallback here and no tech-debt note promising one later.
 *
 * **Two of the six take a format string; four are prefix + counter.** `student_code` and
 * `registration_number` are printed on cards and quoted on the phone, so §6.5 lets an institute shape
 * them — `{PREFIX}{YY}{SEQ:5}` by default. An unknown token is a validation error on the setting rather
 * than a silently empty segment, because a number that quietly loses its year is one nobody notices
 * until two of them collide.
 *
 * **The period key is what makes a yearly reset safe.** `reserve()` takes the counter and the period
 * together and resets the counter inside the same lock when the period moves, so the January student
 * who registers one second after midnight gets `0001` rather than last year's next number — and two of
 * them cannot both get it.
 */
final class StudentNumberService
{
    /**
     * `{SEQ:n}` — the padded sequence, and the only token that takes an argument.
     *
     * Case-insensitive on purpose: §6.5 writes the tokens in capitals and Phase 2 seeded
     * `registration_number_format` as `{prefix}-{year}-{seq}`. Both are the same instruction, and an
     * institute that has already saved one spelling must not have its numbering break because the
     * other one was written down later.
     */
    private const SEQUENCE_PATTERN = '/\{SEQ(?::(\d+))?\}/i';

    /**
     * Every token §6.5 declares, plus the three spellings Phase 2's seeded default uses (`{year}`,
     * `{month}`, `{prefix}`). Anything else in a format string is a mistake worth naming.
     */
    private const TOKENS = ['PREFIX', 'YYYY', 'YY', 'MM', 'BRANCH', 'COURSE', 'SEQ', 'YEAR', 'MONTH'];

    public function __construct(
        private readonly DocumentNumberService $numbers,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | The two formatted numbers
    |--------------------------------------------------------------------------
    */

    /**
     * `student_code` — issued at creation, never reissued, never editable afterwards.
     */
    public function nextStudentCode(?Branch $branch = null): string
    {
        return $this->expand(
            (string) setting('institute.student_id_format', '{PREFIX}{YY}{SEQ:5}'),
            'institute.student_id_format',
            prefix: (string) setting('institute.student_id_prefix', 'STD-'),
            counterKey: 'institute.student_id_next_number',
            scope: (string) setting('institute.student_id_sequence_scope', 'yearly'),
            periodKey: 'institute.student_id_period',
            branch: $branch,
            course: null,
        );
    }

    /**
     * `registration_number` — issued once, at §68's registration stage, and only there.
     *
     * The admission carries the course, so `{COURSE}` resolves; the student carries the branch. A
     * student who registers for a second course keeps the first number: this is called by
     * `AdmissionService::register()` and only when the column is still null.
     */
    public function nextRegistrationNumber(Student $student, StudentAdmission $admission): string
    {
        return $this->expand(
            (string) setting('institute.registration_number_format', '{PREFIX}{YYYY}{SEQ:4}'),
            'institute.registration_number_format',
            prefix: (string) setting('institute.registration_number_prefix', 'REG-'),
            counterKey: 'institute.registration_number_next_number',
            scope: (string) setting('institute.registration_number_sequence_scope', 'yearly'),
            periodKey: 'institute.registration_number_period',
            branch: $student->branch,
            course: $admission->course,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The four plain ones
    |--------------------------------------------------------------------------
    */

    public function nextInquiryNumber(): string
    {
        return $this->numbers->next('institute.inquiry_prefix', 'institute.inquiry_next_number', '%05d');
    }

    public function nextApplicationNumber(): string
    {
        return $this->numbers->next('institute.application_prefix', 'institute.application_next_number', '%05d');
    }

    public function nextAdmissionNumber(): string
    {
        return $this->numbers->next('institute.admission_prefix', 'institute.admission_next_number', '%05d');
    }

    public function nextTeacherCode(): string
    {
        return $this->numbers->next('institute.teacher_code_prefix', 'institute.teacher_code_next_number', '%04d');
    }

    /**
     * The fallback code for a course saved without one.
     *
     * `%04d` rather than the `%05d` the funnel uses: a catalogue holds courses in the dozens, and a
     * code is read aloud, written on a certificate and typed into a search box. `CRS-0007` is a
     * course; `CRS-00007` is a transaction.
     */
    public function nextCourseCode(): string
    {
        return $this->numbers->next('institute.course_code_prefix', 'institute.course_code_next_number', '%04d');
    }

    /*
    |--------------------------------------------------------------------------
    | The token expander
    |--------------------------------------------------------------------------
    */

    /**
     * Validate a format string without reserving anything — what the settings form calls.
     *
     * @throws CourseRuleException
     */
    public function assertFormatIsValid(string $format, string $settingKey): void
    {
        $unknown = [];

        preg_match_all('/\{([A-Za-z]+)(?::\d+)?\}/', $format, $matches);

        foreach ($matches[1] as $token) {
            if (! in_array(strtoupper($token), self::TOKENS, true)) {
                $unknown[] = '{'.$token.'}';
            }
        }

        if ($unknown !== []) {
            throw CourseRuleException::refuse($settingKey, sprintf(
                '%s is not a token this format understands. The ones it does are %s — an unknown token '
                .'would expand to nothing, and a number quietly missing a segment is one that collides '
                .'later.',
                implode(', ', array_unique($unknown)),
                '{'.implode('}, {', self::TOKENS).'}',
            ));
        }

        if (preg_match(self::SEQUENCE_PATTERN, $format) !== 1) {
            throw CourseRuleException::refuse($settingKey,
                'The format must contain {SEQ} or {SEQ:n}: without a sequence every student would be '
                .'issued the same number.');
        }
    }

    /**
     * Expand one format and reserve one number, inside the caller's transaction.
     */
    private function expand(
        string $format,
        string $settingKey,
        string $prefix,
        string $counterKey,
        string $scope,
        string $periodKey,
        ?Branch $branch,
        ?Course $course,
    ): string {
        $this->assertFormatIsValid($format, $settingKey);

        $now = Carbon::now();
        $branchCode = trim((string) ($branch?->code ?? ''));
        $period = $this->periodFor($scope, $now, $branchCode);

        // The reset marker travels with the counter so both move under one lock. `global` passes no
        // period at all rather than a constant one: a counter that never resets should not be reading
        // a settings row on every reservation to be told so.
        $value = $period === null
            ? $this->numbers->reserve($counterKey)
            : $this->numbers->reserve($counterKey, $periodKey, $period);

        $pad = 6;

        if (preg_match(self::SEQUENCE_PATTERN, $format, $seq) === 1) {
            $pad = isset($seq[1]) && $seq[1] !== '' ? max(1, min(12, (int) $seq[1])) : 6;
        }

        $replacements = [
            'PREFIX' => $prefix,
            'YYYY' => $now->format('Y'),
            'YEAR' => $now->format('Y'),
            'YY' => $now->format('y'),
            'MM' => $now->format('m'),
            'MONTH' => $now->format('m'),
            'BRANCH' => $branchCode,
            'COURSE' => trim((string) ($course?->code ?? '')),
        ];

        // Replaced case-insensitively so `{prefix}` and `{PREFIX}` are one instruction, and by regex
        // rather than strtr() so the two spellings do not need eight entries each.
        $expanded = (string) preg_replace_callback(
            '/\{(PREFIX|YYYY|YEAR|YY|MM|MONTH|BRANCH|COURSE)\}/i',
            static fn (array $m): string => $replacements[strtoupper($m[1])] ?? '',
            $format,
        );

        return (string) preg_replace(
            self::SEQUENCE_PATTERN,
            $this->numbers->format($value, '%0'.$pad.'d'),
            $expanded,
            1,
        );
    }

    /**
     * §6.5 step 1. `null` means "never resets", which is what `global` asks for.
     */
    private function periodFor(string $scope, Carbon $now, string $branchCode): ?string
    {
        return match ($scope) {
            'global' => null,
            'branch_yearly' => $now->format('Y').'-'.($branchCode !== '' ? $branchCode : 'ALL'),
            default => $now->format('Y'),
        };
    }
}
