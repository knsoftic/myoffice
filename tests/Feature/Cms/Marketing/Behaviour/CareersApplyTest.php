<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Enums\JobApplicationStatus;
use App\Enums\JobOpeningStatus;
use App\Events\Cms\JobApplicationReceived;
use App\Models\Cms\JobApplication;
use App\Models\Cms\JobOpening;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\Exceptions\JobClosedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 tests 33-36 and 41 — applying for a job and keeping the CV private (§2.19, §6.8, D21).
 *
 *  33. an application stores the row and the CV on the private `local` disk under a ULID name; the `public` disk
 *      holds no copy; the opening's `applications_count` is bumped;
 *  34. a PHP file renamed `.pdf`, an `.exe` and an over-limit file are refused on `cv` by the service's second
 *      gate, and nothing reaches the disk or the database;
 *  35. a second application from the same address to the same opening is a field error on `email`, not a 500,
 *      and creates no second row;
 *  36. a draft, closed or past-deadline opening refuses applications (422) and stores nothing;
 *      `website.careers_enabled = false` makes `/careers` and the apply POST 404;
 *  41. a soft-deleted application keeps its CV, a force delete removes it, and `purge()` removes every file of
 *      an opening's applications.
 */
final class CareersApplyTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();

        Storage::fake('local');
        Storage::fake('public');

        $this->setSetting('website.careers_enabled', true);
        $this->actAsSuperAdmin();
    }

    public function test_33_an_application_stores_the_cv_privately_under_a_ulid_name(): void
    {
        $job = $this->makeOpening('Senior Laravel Developer');

        $application = $this->apply($job, 'jane.candidate@example.test', $this->pdfUpload('Jane Candidate CV.pdf'));

        $this->assertTrue($application->exists);
        $this->assertSame(JobApplicationStatus::New, $application->fresh()->status);
        $this->assertSame('jane.candidate@example.test', $application->fresh()->email);
        $this->assertSame(1, (int) DB::table('job_openings')->where('id', $job->getKey())->value('applications_count'));

        $path = (string) DB::table('job_applications')->where('id', $application->getKey())->value('cv_path');

        Storage::disk('local')->assertExists($path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'No copy of the CV is ever written to the public disk.');

        $this->assertMatchesRegularExpression('~^careers/applications/\d{4}/'.$job->getKey().'/[0-9a-z]{26}\.pdf$~', $path, 'The stored name is {ulid}.{ext}, under the opening\'s folder.');
        $this->assertStringNotContainsString('Jane', $path, 'The uploaded filename never becomes a path.');
        $this->assertSame('Jane Candidate CV.pdf', (string) DB::table('job_applications')->where('id', $application->getKey())->value('cv_original_name'));
        $this->assertSame('application/pdf', (string) DB::table('job_applications')->where('id', $application->getKey())->value('cv_mime'));
        $this->assertSame(['careers/applications/'.Carbon::now()->format('Y').'/'.$job->getKey().'/'.basename($path)], Storage::disk('local')->allFiles(), 'Exactly one file was stored.');
    }

    public function test_34_disguised_executable_and_oversize_cvs_are_refused_with_nothing_written(): void
    {
        $job = $this->makeOpening('QA Automation Engineer');
        $this->setSetting('website.cv_max_mb', 1);

        $cases = [
            'php renamed pdf' => UploadedFile::fake()->createWithContent('cv.pdf', "<?php echo shell_exec(\$_GET['c']); ?>\n"),
            'windows executable' => UploadedFile::fake()->createWithContent('cv.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00This program cannot be run in DOS mode."),
            'over the effective limit' => $this->pdfUpload('big-cv.pdf', (int) (1.5 * 1024 * 1024)),
        ];

        $index = 0;

        foreach ($cases as $label => $file) {
            try {
                $this->apply($job, 'refused'.(++$index).'@example.test', $file);
                $this->fail(sprintf('The %s must be refused.', $label));
            } catch (ContentRuleException $exception) {
                $this->assertSame(422, $exception->status, $label);
                $this->assertArrayHasKey('cv', $exception->errors(), sprintf('The %s is a field error on cv.', $label));
            }
        }

        $this->assertSame(0, JobApplication::withTrashed()->where('job_opening_id', $job->getKey())->count(), 'No row was written.');
        $this->assertSame([], Storage::disk('local')->allFiles(), 'No file was written to the private disk.');
        $this->assertSame([], Storage::disk('public')->allFiles(), 'No file was written to the public disk.');
        $this->assertSame(0, (int) DB::table('job_openings')->where('id', $job->getKey())->value('applications_count'));
    }

    public function test_35_a_second_application_from_the_same_address_is_an_email_field_error(): void
    {
        $job = $this->makeOpening('DevOps Engineer');

        $this->apply($job, 'repeat@example.test', $this->pdfUpload());

        try {
            $this->apply($job, 'Repeat@Example.test', $this->pdfUpload('second-cv.pdf'));
            $this->fail('A second application to the same opening must be refused.');
        } catch (ContentRuleException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('email', $exception->errors());
            $this->assertStringContainsString('already applied', (string) $exception->validator->errors()->first('email'));
        }

        $this->assertSame(1, JobApplication::withTrashed()->where('job_opening_id', $job->getKey())->count());
        $this->assertCount(1, Storage::disk('local')->allFiles(), 'The refused attempt left no orphan file.');

        // The same address may apply to another opening.
        $other = $this->makeOpening('Site Reliability Engineer');
        $this->assertTrue($this->apply($other, 'repeat@example.test', $this->pdfUpload())->exists);
    }

    public function test_36_closed_openings_refuse_applications_and_nothing_is_stored(): void
    {
        $draft = $this->makeOpening('Draft Opening', ['status' => JobOpeningStatus::Draft->value]);
        $closed = $this->makeOpening('Closed Opening');
        $this->openings()->changeStatus($closed, JobOpeningStatus::Closed, 'Position paused');
        $expired = $this->makeOpening('Expiring Opening', ['deadline' => JobOpening::businessToday()]);

        $this->travel(2)->days();

        Event::fake([JobApplicationReceived::class]);

        foreach (['draft' => $draft, 'closed' => $closed->fresh(), 'past deadline' => $expired->fresh()] as $label => $job) {
            try {
                $this->apply($job, str_replace(' ', '-', $label).'@example.test', $this->pdfUpload());
                $this->fail(sprintf('A %s opening must refuse applications.', $label));
            } catch (JobClosedException $exception) {
                $this->assertSame(422, $exception->status, $label);
            }

            Event::assertNotDispatched(JobApplicationReceived::class);
            $this->assertSame(0, JobApplication::withTrashed()->where('job_opening_id', $job->getKey())->count(), sprintf('The %s opening stored no row.', $label));
        }

        $this->assertSame([], Storage::disk('local')->allFiles(), 'No CV was stored for a closed opening.');
    }

    public function test_36_switching_careers_off_404s_the_board_and_the_apply_post(): void
    {
        $job = $this->makeOpening('Frontend Developer');

        $this->becomeGuest();
        $this->bumpPublicCache('test 36 on');
        $this->get(route('site.careers.index'))->assertOk()->assertSee('Frontend Developer');

        $this->setSetting('website.careers_enabled', false);
        $this->bumpPublicCache('test 36 off');

        $this->get(route('site.careers.index'))->assertNotFound();
        $this->get(route('site.careers.show', ['jobOpening' => $job->slug]))->assertNotFound();

        $this->post(route('site.careers.apply', ['jobOpening' => $job->slug]), [
            'applicant_name' => 'Late Applicant',
            'email' => 'late@example.test',
            'phone' => '+92 300 0000000',
            'cv' => $this->pdfUpload(),
        ])->assertNotFound();

        $this->assertSame(0, JobApplication::withTrashed()->where('job_opening_id', $job->getKey())->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        // The service refuses on its own too, whatever route reaches it.
        $this->expectException(JobClosedException::class);
        $this->apply($job->fresh(), 'service-net@example.test', $this->pdfUpload());
    }

    public function test_41_cv_retention_follows_soft_delete_force_delete_and_purge(): void
    {
        $job = $this->makeOpening('Data Engineer');

        $kept = $this->apply($job, 'kept@example.test', $this->pdfUpload());
        $forced = $this->apply($job, 'forced@example.test', $this->pdfUpload());
        $modelForced = $this->apply($job, 'model-forced@example.test', $this->pdfUpload());

        $keptPath = (string) DB::table('job_applications')->where('id', $kept->getKey())->value('cv_path');
        $forcedPath = (string) DB::table('job_applications')->where('id', $forced->getKey())->value('cv_path');
        $modelForcedPath = (string) DB::table('job_applications')->where('id', $modelForced->getKey())->value('cv_path');

        // Soft delete: the file stays, so a restore is lossless.
        $this->applications()->delete($kept->fresh());
        $this->assertSoftDeleted('job_applications', ['id' => $kept->getKey()]);
        Storage::disk('local')->assertExists($keptPath);

        $kept->fresh()?->restore();
        $this->assertNotNull(JobApplication::query()->find($kept->getKey()));
        Storage::disk('local')->assertExists($keptPath);

        // Force delete through the service (the admin route) removes the file.
        $this->applications()->forceDelete($forced->fresh());
        $this->assertNull(JobApplication::withTrashed()->find($forced->getKey()));
        Storage::disk('local')->assertMissing($forcedPath);

        // A force delete through the model removes it as well (the forceDeleted hook).
        JobApplication::query()->findOrFail($modelForced->getKey())->forceDelete();
        Storage::disk('local')->assertMissing($modelForcedPath);

        Storage::disk('local')->assertExists($keptPath);

        // purge() removes the opening, every application, and every file.
        $other = $this->apply($job, 'purged@example.test', $this->pdfUpload());
        $otherPath = (string) DB::table('job_applications')->where('id', $other->getKey())->value('cv_path');
        $this->applications()->delete($other->fresh());

        $this->openings()->purge($job->fresh());

        $this->assertNull(JobOpening::withTrashed()->find($job->getKey()));
        $this->assertSame(0, DB::table('job_applications')->where('job_opening_id', $job->getKey())->count());
        Storage::disk('local')->assertMissing($keptPath);
        Storage::disk('local')->assertMissing($otherPath);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'No CV of the purged opening is left on disk.');
    }

    private function apply(JobOpening $job, string $email, UploadedFile $cv): JobApplication
    {
        $data = $this->applicationData($email);

        return $this->applications()->apply($job, $data, $cv, $this->publicRequest($data, uri: '/careers/'.$job->slug.'/apply'));
    }
}
