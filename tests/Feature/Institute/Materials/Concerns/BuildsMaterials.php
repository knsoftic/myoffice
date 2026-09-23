<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Materials\Concerns;

use App\Models\Institute\Assignment;
use App\Models\Institute\AssignmentSubmission;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\CourseMaterial;
use App\Models\Institute\Student;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\User;
use App\Services\Institute\AssignmentService;
use App\Services\Institute\AssignmentSubmissionService;
use App\Services\Institute\CourseMaterialService;
use App\Services\Institute\MaterialAccessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * The Phase 19 fixtures, built through the real services (phase-19-23 §11).
 *
 * **Nothing here writes a row directly.** A fixture that assembles a state the application cannot
 * produce stops catching the bug it was written for and starts causing different ones — which is
 * exactly what D108 was, and it cost a day. Every helper goes through the service that owns the write.
 */
trait BuildsMaterials
{
    /** A minimal but genuinely valid PDF, so `finfo` sniffs `application/pdf` rather than octet-stream. */
    protected const PDF_BYTES = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    protected function materialService(): CourseMaterialService
    {
        return app(CourseMaterialService::class);
    }

    protected function accessService(): MaterialAccessService
    {
        return app(MaterialAccessService::class);
    }

    protected function assignmentService(): AssignmentService
    {
        return app(AssignmentService::class);
    }

    protected function submissionService(): AssignmentSubmissionService
    {
        return app(AssignmentSubmissionService::class);
    }

    protected function pdf(string $name = 'handout.pdf', string $extra = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF_BYTES.$extra);
    }

    /**
     * A published file material aimed at one batch.
     *
     * @param  list<array{type: string, id: int}>|null  $targets  null aims it at the batch
     * @param  array<string, mixed>  $overrides
     */
    protected function sharedMaterial(
        Batch $batch,
        ?User $actor = null,
        array $overrides = [],
        ?array $targets = null,
        bool $publish = true,
    ): CourseMaterial {
        $actor ??= $this->createSuperAdmin();

        $material = $this->materialService()->create(
            array_merge([
                'course_id' => (int) $batch->getAttribute('course_id'),
                'title' => 'Week 1 handout',
                'type' => 'pdf',
            ], $overrides),
            $this->pdf(),
            $targets ?? [['type' => 'batch', 'id' => (int) $batch->getKey()]],
            $actor,
        );

        return $publish ? $this->materialService()->publish($material, $actor) : $material;
    }

    /** A published link material. */
    protected function sharedLink(Batch $batch, ?User $actor = null, string $url = 'https://example.test/notes'): CourseMaterial
    {
        $actor ??= $this->createSuperAdmin();

        $material = $this->materialService()->create([
            'course_id' => (int) $batch->getAttribute('course_id'),
            'title' => 'Reading list',
            'type' => 'link',
            'external_url' => $url,
        ], null, [['type' => 'batch', 'id' => (int) $batch->getKey()]], $actor);

        return $this->materialService()->publish($material, $actor);
    }

    /**
     * A published assignment on the batch, due in a week unless told otherwise.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function publishedAssignment(Batch $batch, ?User $actor = null, array $overrides = []): Assignment
    {
        $actor ??= $this->createSuperAdmin();

        $assignment = $this->assignmentService()->create(array_merge([
            'batch_id' => (int) $batch->getKey(),
            'title' => 'Lab 1',
            'total_marks' => '50',
            'passing_marks' => '20',
            'submission_type' => 'file_or_text',
            'deadline_at' => Carbon::now()->addDays(7),
        ], $overrides), null, $actor);

        return $this->assignmentService()->publish($assignment, $actor);
    }

    /** A draft assignment, for the cases that must not reach a student. */
    protected function draftAssignment(Batch $batch, ?User $actor = null, array $overrides = []): Assignment
    {
        return $this->assignmentService()->create(array_merge([
            'batch_id' => (int) $batch->getKey(),
            'title' => 'Not published yet',
            'total_marks' => '20',
            'deadline_at' => Carbon::now()->addDays(3),
        ], $overrides), null, $actor ?? $this->createSuperAdmin());
    }

    /** Work handed in, through `draft()` then `submit()` — the path a student actually takes. */
    protected function handedIn(
        Assignment $assignment,
        Student $student,
        ?User $actor = null,
        array $data = ['submission_text' => 'My answer'],
        bool $withFile = false,
    ): AssignmentSubmission {
        $actor ??= $this->createSuperAdmin();

        $draft = $this->submissionService()->draft($assignment, $student, $actor);

        return $this->submissionService()->submit(
            $draft,
            $data,
            $withFile ? [$this->pdf('work.pdf')] : [],
            $actor,
        );
    }

    /**
     * A student on the batch, with a portal login they can actually sign in with.
     *
     * `createLogin()` stamps `must_change_password`, which redirects every panel route — so a test
     * probing a screen would get a 302 and read it as an authorization failure.
     *
     * @return array{0: Student, 1: User, 2: StudentBatchEnrollment}
     */
    protected function studentWithLogin(Batch $batch, ?User $actor = null): array
    {
        $actor ??= $this->createSuperAdmin();

        $enrollment = $this->seat($batch, null, [], $actor);
        $student = $enrollment->student;

        $user = $this->studentService()->createLogin($student, $actor);
        $user?->forceFill(['must_change_password' => false])->saveQuietly();

        return [$student->refresh(), $user, $enrollment];
    }

    /** The batch's teacher, given a login so the teacher panel can be reached. */
    protected function teacherWithLogin(Batch $batch): ?User
    {
        $teacher = $batch->teacher;

        if ($teacher === null) {
            return null;
        }

        $user = User::query()->find($teacher->getAttribute('user_id'));

        if (! $user instanceof User) {
            $user = User::factory()->create(['must_change_password' => false]);
            $user->assignRole('Teacher');
            $teacher->forceFill(['user_id' => $user->getKey()])->saveQuietly();
        } else {
            $user->forceFill(['must_change_password' => false])->saveQuietly();
        }

        return $user;
    }

    /** A second course and batch nobody in the test is enrolled on. */
    protected function unrelatedBatch(?User $actor = null): Batch
    {
        $actor ??= $this->createSuperAdmin();

        return $this->runningBatch($this->courseWithOutline([1], $actor), [], $actor);
    }

    protected function courseOf(Batch $batch): Course
    {
        return Course::query()->findOrFail($batch->getAttribute('course_id'));
    }
}
