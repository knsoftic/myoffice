<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Concerns;

use App\Models\Institute\Course;
use App\Models\Institute\CourseInquiry;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentApplication;
use App\Models\User;
use App\Services\Institute\AdmissionService;
use App\Services\Institute\CourseInquiryService;
use App\Services\Institute\DemoClassService;
use App\Services\Institute\StudentApplicationService;
use App\Services\Institute\StudentService;
use Illuminate\Support\Str;

/**
 * Fixtures for the Phase 15 suite.
 *
 * Everything goes through the service that owns it, for the same reason Phase 14's concern does: a
 * fixture that inserted a row directly would be testing a shape the application never produces, and
 * the first thing it would stop catching is a service forgetting to issue a document number.
 */
trait BuildsAdmissions
{
    use BuildsCatalogue;

    private int $admissionSequence = 0;

    protected function inquiryService(): CourseInquiryService
    {
        return app(CourseInquiryService::class);
    }

    protected function applicationService(): StudentApplicationService
    {
        return app(StudentApplicationService::class);
    }

    protected function studentService(): StudentService
    {
        return app(StudentService::class);
    }

    protected function admissionService(): AdmissionService
    {
        return app(AdmissionService::class);
    }

    protected function demoService(): DemoClassService
    {
        return app(DemoClassService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function inquiry(array $overrides = [], ?User $actor = null): CourseInquiry
    {
        $this->admissionSequence++;

        return $this->inquiryService()->create(array_merge([
            'name' => 'Inquirer '.$this->admissionSequence,
            'phone' => '0300'.str_pad((string) $this->admissionSequence, 7, '0', STR_PAD_LEFT),
            'source' => 'walk_in',
        ], $overrides), $actor);
    }

    /**
     * A submitted application, through the public path, with the guards a real POST carries.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function publicApplication(?Course $course = null, array $overrides = [], ?User $actor = null): StudentApplication
    {
        $this->admissionSequence++;
        $course ??= $this->publishedCourse(actor: $actor ?? $this->createSuperAdmin());

        return $this->applicationService()->submitFromPublic(array_merge([
            'name' => 'Applicant '.$this->admissionSequence,
            'phone' => '0311'.str_pad((string) $this->admissionSequence, 7, '0', STR_PAD_LEFT),
            'email' => 'applicant'.$this->admissionSequence.'@example.test',
            'course_id' => $course->getKey(),
            'idempotency_key' => (string) Str::ulid(),
            'form_rendered_at' => time() - 30,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function staffApplication(?Course $course = null, array $overrides = [], ?User $actor = null): StudentApplication
    {
        $this->admissionSequence++;
        $actor ??= $this->createSuperAdmin();
        $course ??= $this->publishedCourse(actor: $actor);

        return $this->applicationService()->createForStaff(array_merge([
            'name' => 'Walk-in '.$this->admissionSequence,
            'phone' => '0322'.str_pad((string) $this->admissionSequence, 7, '0', STR_PAD_LEFT),
            'course_id' => $course->getKey(),
        ], $overrides), $actor);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function student(array $overrides = [], ?User $actor = null): Student
    {
        $this->admissionSequence++;

        return $this->studentService()->create(array_merge([
            'name' => 'Student '.$this->admissionSequence,
            'phone' => '0333'.str_pad((string) $this->admissionSequence, 7, '0', STR_PAD_LEFT),
            'email' => 'student'.$this->admissionSequence.'@example.test',
        ], $overrides), $actor);
    }

    /**
     * An admission at step one, from a converted application — the path a real one travels.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function admission(?Course $course = null, array $overrides = [], ?User $actor = null): StudentAdmission
    {
        $actor ??= $this->createSuperAdmin();
        $course ??= $this->publishedCourse(actor: $actor);

        return $this->applicationService()->convert(
            $this->publicApplication($course, [], $actor),
            $overrides,
            $actor,
        );
    }
}
