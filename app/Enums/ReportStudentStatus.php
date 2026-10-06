<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a student stands on **one admission**, as Advanced Reports buckets it (D177).
 *
 * **This is a report bucket, not a column.** Nothing stores it, and nothing may: three real status
 * axes already exist and each answers a different question — `student_admissions.stage` (where this
 * course's pipeline is), `students.status` (the person, across every course) and
 * `student_batch_enrollments.status` (the seat). The screen asks a fourth, plainer question — "is this
 * student active, finished, waiting, gone or parked?" — and a stored copy of the answer would be a
 * fifth status that drifts from the three it was derived from.
 *
 * The mapping, evaluated in order, **first match wins** (so every admission lands in exactly one
 * bucket and the cards add up). "Latest enrolment" is the admission's highest-id live
 * `student_batch_enrollments` row — the seat the row's batch is read from:
 *
 *  1. stage `withdrawn` or `cancelled`, OR latest enrolment `dropped` or `cancelled`,
 *     OR `students.status` = `dropped`                                  => Dropped
 *  2. stage `completed` OR latest enrolment `completed`                 => Completed
 *  3. `students.status` = `suspended` OR latest enrolment `suspended`   => Inactive
 *  4. stage `active`                                                    => Active
 *  5. anything else (`application`, `registration`, `fee_collection`,
 *     `batch_assignment`)                                               => Pending
 *
 * Why the seat and the person count, not only the stage: the application ends a student without
 * moving the admission's stage — the Students screen drops the *person* (`StudentService`), and the
 * batch roster drops, cancels or completes the *seat* (`BatchEnrollmentService`); a report that read
 * only the stage would call any of them Active. Dropped is checked first, so a student who left is
 * never counted among the active or the finished. A suspension then outranks "active", because a
 * suspended student on a running course is exactly the one somebody filtering for "inactive" is
 * looking for. `StudentStatus` has no Inactive case; a suspension is the closest thing the data
 * holds, and saying so here stops somebody inventing one.
 *
 * The rule is expressed once, as a SQL CASE built from the `AdmissionStage`, `EnrollmentStatus` and
 * `StudentStatus` enum values (bound, never spelled) by `AdvancedStudentReportService::statusCase()`,
 * and used for the filter, the summary counts, the sort and the table badge alike — the detail page
 * reads the same CASE (the selected admission's row) rather than re-deciding in PHP.
 */
enum ReportStudentStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Completed = 'completed';
    case Pending = 'pending';
    case Dropped = 'dropped';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Pending => 'Pending',
            self::Dropped => 'Dropped',
            self::Inactive => 'Inactive',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'emerald',
            self::Completed => 'sky',
            self::Pending => 'amber',
            self::Dropped => 'rose',
            self::Inactive => 'slate',
        };
    }
}
