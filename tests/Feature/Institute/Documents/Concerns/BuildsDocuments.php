<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Documents\Concerns;

use App\Enums\PrintTemplateType;
use App\Models\Institute\Batch;
use App\Models\Institute\Certificate;
use App\Models\Institute\PrintTemplate;
use App\Models\Institute\Student;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\Institute\StudentIdCard;
use App\Models\User;
use App\Services\Institute\CertificateEligibilityService;
use App\Services\Institute\CertificateService;
use App\Services\Institute\PrintTemplateService;
use App\Services\Institute\StudentIdCardService;
use Database\Seeders\PrintTemplateSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * The Phase 21 fixtures, built through the real services (phase-19-23 §11).
 *
 * **Nothing here writes a certificate or a card row directly.** A fixture that assembled a state the
 * application cannot produce would stop catching the bug it was written for and start causing
 * different ones — the lesson D108 cost a day for, and it matters more here than anywhere: a
 * certificate row written by hand would have no verification code, no eligibility snapshot and no
 * number, and every test built on it would be testing a document that could not exist.
 */
trait BuildsDocuments
{
    /** A 1x1 transparent PNG, base64 — the smallest thing that is genuinely an image file. */
    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
        .'+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function templateService(): PrintTemplateService
    {
        return app(PrintTemplateService::class);
    }

    protected function certificateService(): CertificateService
    {
        return app(CertificateService::class);
    }

    protected function cardService(): StudentIdCardService
    {
        return app(StudentIdCardService::class);
    }

    protected function eligibilityService(): CertificateEligibilityService
    {
        return app(CertificateEligibilityService::class);
    }

    /**
     * The three templates a fresh install ships with.
     *
     * Run through the seeder rather than rebuilt here, so a test that prints something is printing
     * on the same layout a real institute gets on day one. A seeder defect would show up as a
     * failing print test, which is exactly where it should show up.
     */
    protected function seedTemplates(): void
    {
        if (PrintTemplate::query()->where('code', 'DEFAULT-CERTIFICATE')->exists()) {
            return;
        }

        $this->seed(PrintTemplateSeeder::class);
    }

    protected function seededTemplate(PrintTemplateType $type): PrintTemplate
    {
        $this->seedTemplates();

        return PrintTemplate::query()
            ->where('type', $type->value)
            ->where('is_default', true)
            ->firstOrFail();
    }

    /**
     * A template of this type, created through the service.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function printTemplate(
        PrintTemplateType $type = PrintTemplateType::Certificate,
        array $overrides = [],
        ?User $actor = null,
    ): PrintTemplate {
        $actor ??= $this->createSuperAdmin();

        [$template] = $this->templateService()->create(array_merge([
            'type' => $type->value,
            'code' => 'TPL'.mb_substr(uniqid(), -8),
            'name' => 'Test template',
            'body_html' => '<div><p>{student_name}</p><p>{course_name}</p><div>{qr}</div></div>',
            'custom_css' => 'body { text-align: center; }',
        ], $overrides), $actor);

        return $template;
    }

    /** A draft certificate against this enrolment. */
    protected function draftCertificate(
        StudentBatchEnrollment $enrollment,
        array $data = [],
        ?User $actor = null,
    ): Certificate {
        $actor ??= $this->createSuperAdmin();

        $this->seedTemplates();

        return $this->certificateService()->draft($enrollment, $data, $actor);
    }

    /**
     * A certificate that has been issued — numbered, coded and frozen.
     *
     * **`$override` is passed through rather than defaulted to an empty string**, because most
     * fixtures need one: a freshly enrolled student fails the attendance and progress rules, and a
     * test about printing should not have to build a whole term of attendance to get there. Where a
     * test is *about* eligibility it passes '' and asserts the refusal.
     */
    protected function issuedCertificate(
        StudentBatchEnrollment $enrollment,
        array $data = [],
        ?User $actor = null,
        string $override = 'Fixture: issued for a test that is not about eligibility.',
    ): Certificate {
        $actor ??= $this->createSuperAdmin();

        $certificate = $this->draftCertificate($enrollment, $data, $actor);

        return $this->certificateService()->issue($certificate, $actor, $override);
    }

    /**
     * A live student card.
     *
     * **The photograph is put on the student first**, because the institute requires one by default
     * and `issue()` refuses without it. A fixture that turned the setting off to get past that would
     * be building a card under a configuration no real institute uses, and would stop exercising the
     * copy step INV-21-4 exists for.
     */
    protected function issuedCard(
        StudentBatchEnrollment $enrollment,
        array $data = [],
        ?User $actor = null,
    ): StudentIdCard {
        $actor ??= $this->createSuperAdmin();

        $this->seedTemplates();

        $student = $enrollment->student;

        if ($student !== null && blank($student->getAttribute('photo_path'))) {
            $this->givePhoto($student);
        }

        return $this->cardService()->issue($enrollment->refresh(), $data, $actor);
    }

    /**
     * Put a real image file on the student's record, on the private disk.
     *
     * A real file rather than a path string: `copyPhoto()` reads the source and writes its own copy,
     * so a fixture that only set the column would silently produce a card with no photograph and
     * every print test would still pass.
     */
    protected function givePhoto(Student $student): Student
    {
        $path = sprintf('students/photos/%s.png', $student->getKey());

        Storage::disk('private')->put($path, base64_decode(self::ONE_PIXEL_PNG));

        $student->forceFill(['photo_path' => $path])->save();

        return $student->refresh();
    }


    /**
     * A seated student with a login, so the student panel can be reached.
     *
     * The login goes through `StudentService::createLogin()` rather than being stapled on, because
     * that is what the office does and it is the only thing that sets the role and the flags the
     * panel middleware reads.
     *
     * @return array{0: Student, 1: ?User, 2: StudentBatchEnrollment}
     */
    protected function studentWithLogin(Batch $batch, ?User $actor = null): array
    {
        $actor ??= $this->createSuperAdmin();

        $enrollment = $this->seat($batch, null, [], $actor);
        $student = $enrollment->student;

        $user = app(\App\Services\Institute\StudentService::class)->createLogin($student, $actor);
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

    /**
     * A batch with one seated student — the smallest thing a certificate or a card can be made from.
     *
     * @return array{0: Batch, 1: StudentBatchEnrollment}
     */
    protected function batchWithOneStudent(?User $actor = null): array
    {
        $actor ??= $this->createSuperAdmin();

        $batch = $this->runningBatch($this->courseWithOutline([1], $actor), [], $actor);
        $seat = $this->seat($batch, null, [], $actor);

        return [$batch->refresh(), $seat];
    }
}
