<?php

declare(strict_types=1);

namespace App\Reports\Institute;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\CertificateStatus;
use App\Enums\ReportGroup;
use App\Models\Branch;
use App\Models\Institute\Batch;
use App\Models\Institute\Certificate;
use App\Models\Institute\Course;
use App\Models\User;
use App\Reports\Report;
use App\Support\ReportResult;

/**
 * `in.certificates` - the certificate register (requirement 99).
 *
 * **Every name on this report is a snapshot, deliberately.** `student_name_snapshot`,
 * `course_name_snapshot`, `batch_name_snapshot` and `teacher_name_snapshot` are frozen on the
 * certificate when it is issued, and this report reads those rather than joining to the live rows.
 * A certificate is a document that has left the building: if a student later corrects the spelling
 * of their name, the register must still say what the paper in their hand says. Joining to `students`
 * would produce a register that quietly disagrees with every certificate it lists.
 *
 * **Revoked certificates stay on the register.** A revoked certificate is the one somebody most
 * needs to be able to look up - that is the entire purpose of the verification page - and a register
 * that dropped them would be a register you could not trust to be complete.
 */
final class CertificatesReport extends Report
{
    public function key(): string
    {
        return 'in.certificates';
    }

    public function title(): string
    {
        return 'Certificates';
    }

    public function description(): string
    {
        return 'Every certificate issued, with its grade, who trained the student, and how often it has been verified.';
    }

    public function icon(): string
    {
        return 'academic-cap';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Institute;
    }

    public function module(): string
    {
        return 'certificates';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('certificates.issued_on', 'Issued on', [
            'certificates.completion_date' => 'Completed on',
        ]);
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::link('certificate_no', 'Certificate no'),
            ColumnDefinition::text('student', 'Student'),
            ColumnDefinition::text('course', 'Course'),
            ColumnDefinition::text('batch', 'Batch'),
            ColumnDefinition::text('trainer', 'Trainer'),
            ColumnDefinition::date('completion', 'Completed'),
            ColumnDefinition::badge('grade', 'Grade'),
            ColumnDefinition::date('issued', 'Issued'),
            ColumnDefinition::badge('status', 'Status'),
            ColumnDefinition::number('verifications', 'Verifications', 'sum'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('status', 'Status', CertificateStatus::options()),
            FilterDefinition::select('course_id', 'Course', static fn (): array => Course::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::select('batch_id', 'Batch', static fn (): array => Batch::query()->orderByDesc('start_date')->limit(200)->pluck('name', 'id')->all()),
            FilterDefinition::select('branch_id', 'Branch', static fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all()),
            FilterDefinition::text('grade', 'Grade'),
        ];
    }

    public function groupBy(): array
    {
        return ['course' => 'Course', 'batch' => 'Batch', 'grade' => 'Grade', 'status' => 'Status'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $dateColumn = $this->dateFilter()->resolve($request->dateColumn);

        $query = Certificate::query()
            ->whereBetween($dateColumn, [
                $request->range->start()->toDateString(),
                $request->range->end()->toDateString(),
            ]);

        if ($request->hasFilter('status')) {
            $query->whereIn('certificates.status', (array) $request->filter('status'));
        }

        foreach (['course_id', 'batch_id', 'branch_id'] as $filter) {
            if ($request->hasFilter($filter)) {
                $query->where('certificates.'.$filter, (int) $request->filter($filter));
            }
        }

        if ($request->hasFilter('grade')) {
            $query->where('certificates.grade', $request->filter('grade'));
        }

        $rows = [];
        $totals = ['verifications' => 0];

        $query->orderBy('certificates.id')->chunkById(200, function ($certificates) use (&$rows, &$totals, $columns): void {
            foreach ($certificates as $certificate) {
                $rows[] = $this->row($columns, [
                    'certificate_no' => $certificate->certificate_number,
                    // Snapshots, not joins - see the class note.
                    'student' => $certificate->student_name_snapshot,
                    'course' => $certificate->course_name_snapshot,
                    'batch' => $certificate->batch_name_snapshot,
                    'trainer' => $certificate->teacher_name_snapshot,
                    'completion' => app_date($certificate->completion_date),
                    'grade' => $certificate->grade,
                    'issued' => app_date($certificate->issued_on),
                    'status' => $certificate->status?->label(),
                    'verifications' => $certificate->verification_count,
                ]);

                $totals['verifications'] += (int) ($certificate->verification_count ?? 0);
            }
        }, 'certificates.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'certificate register, names as issued'],
        );
    }
}
