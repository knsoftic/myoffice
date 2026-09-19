<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Enums\JobApplicationStatus;
use App\Models\Cms\JobApplication;
use App\Models\User;
use App\Services\Cms\Exceptions\ContentRuleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 test 38 — the six-stage hiring pipeline (§3 `JobApplicationStatus::allowedNext()`, §6.8
 * `changeStatus()`).
 *
 * `new → interview` is refused 422 (not in the map); `new → reviewing` is accepted; a rejection without a reason
 * and an interview without a future slot are refused; every accepted move stamps `status_changed_at` /
 * `status_changed_by` and writes exactly one activity entry with the old and new stage (plus the reason or the
 * interview slot). A refused move changes nothing and never touches the CV.
 */
final class ApplicationPipelineTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    private User $recruiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        $this->recruiter = $this->actAsSuperAdmin();
    }

    public function test_38_stage_transitions_follow_the_pipeline_map_and_are_audited(): void
    {
        $application = $this->makeApplication($this->makeOpening('Mobile Developer'), 'Pipeline Candidate');
        $cv = (string) DB::table('job_applications')->where('id', $application->getKey())->value('cv_path');

        // new → interview: not in allowedNext().
        $this->refused($application, JobApplicationStatus::Interview, ['interview_at' => Carbon::now()->addDays(3)->toDateTimeString(), 'interview_mode' => 'online'], 'status');
        $this->assertSame(JobApplicationStatus::New, $application->fresh()->status);

        // new → reviewing: accepted and audited.
        $marker = $this->lastActivityId();
        $this->pipeline($application, JobApplicationStatus::Reviewing);
        $this->assertMove($application, $marker, 'new', 'reviewing');

        $fresh = $application->fresh();
        $this->assertNotNull($fresh->status_changed_at);
        $this->assertSame((int) $this->recruiter->getKey(), (int) $fresh->status_changed_by);

        // → rejected without a reason: refused.
        $this->refused($application, JobApplicationStatus::Rejected, [], 'reason');
        $this->refused($application, JobApplicationStatus::Rejected, ['reason' => '   '], 'reason');
        $this->assertSame(JobApplicationStatus::Reviewing, $application->fresh()->status);

        // → interview without a future slot (none, past, or no mode): refused.
        $this->refused($application, JobApplicationStatus::Interview, [], 'interview_at');
        $this->refused($application, JobApplicationStatus::Interview, ['interview_at' => Carbon::now()->subHour()->toDateTimeString(), 'interview_mode' => 'onsite'], 'interview_at');
        $this->refused($application, JobApplicationStatus::Interview, ['interview_at' => Carbon::now()->addDays(2)->toDateTimeString()], 'interview_at');
        $this->assertSame(JobApplicationStatus::Reviewing, $application->fresh()->status);

        // → interview with a future slot: accepted, the slot is stored and audited.
        $marker = $this->lastActivityId();
        $slot = Carbon::now()->addDays(4)->setTime(11, 0);
        $this->pipeline($application, JobApplicationStatus::Interview, ['interview_at' => $slot, 'interview_mode' => 'online', 'interview_location' => 'https://meet.example.test/room']);
        $properties = $this->assertMove($application, $marker, 'reviewing', 'interview');
        $this->assertSame('online', $properties['attributes']['interview_mode'] ?? null);
        $this->assertNotNull($application->fresh()->interview_at);
        $this->assertSame('online', $application->fresh()->interview_mode);

        // → rejected with a reason: accepted, the reason is stored and audited.
        $marker = $this->lastActivityId();
        $this->pipeline($application, JobApplicationStatus::Rejected, ['reason' => 'Chose a candidate with more Flutter experience']);
        $properties = $this->assertMove($application, $marker, 'interview', 'rejected');
        $this->assertSame('Chose a candidate with more Flutter experience', $application->fresh()->rejection_reason);
        $this->assertSame('Chose a candidate with more Flutter experience', $properties['attributes']['rejection_reason'] ?? null);

        // rejected → reviewing (re-opened by an admin) clears the reason; rejected → selected is not allowed.
        $this->refused($application, JobApplicationStatus::Selected, [], 'status');

        $marker = $this->lastActivityId();
        $this->pipeline($application, JobApplicationStatus::Reviewing);
        $this->assertMove($application, $marker, 'rejected', 'reviewing');
        $this->assertNull($application->fresh()->rejection_reason);

        $this->assertSame($cv, (string) DB::table('job_applications')->where('id', $application->getKey())->value('cv_path'), 'The pipeline never touches the CV.');
    }

    public function test_38_every_stage_follows_exactly_the_binding_map(): void
    {
        $map = [
            'new' => ['reviewing', 'shortlisted', 'rejected'],
            'reviewing' => ['shortlisted', 'interview', 'rejected'],
            'shortlisted' => ['interview', 'selected', 'rejected'],
            'interview' => ['selected', 'rejected', 'shortlisted'],
            'selected' => ['rejected'],
            'rejected' => ['reviewing'],
        ];

        $job = $this->makeOpening('Pipeline Map Opening');
        $context = [
            'reason' => 'Pipeline map check',
            'interview_at' => Carbon::now()->addWeek()->toDateTimeString(),
            'interview_mode' => 'phone',
        ];

        foreach ($map as $from => $allowed) {
            foreach (JobApplicationStatus::cases() as $to) {
                if ($to->value === $from) {
                    continue;
                }

                $application = $this->makeApplication($job, 'Map '.$from.' to '.$to->value, ['status' => $from]);

                try {
                    $this->pipeline($application, $to, $context);
                    $accepted = true;
                } catch (ContentRuleException) {
                    $accepted = false;
                }

                $this->assertSame(
                    in_array($to->value, $allowed, true),
                    $accepted,
                    sprintf('%s → %s must be %s.', $from, $to->value, in_array($to->value, $allowed, true) ? 'allowed' : 'refused'),
                );

                $this->assertSame($accepted ? $to : JobApplicationStatus::from($from), $application->fresh()->status);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function pipeline(JobApplication $application, JobApplicationStatus $to, array $context = []): void
    {
        $this->applications()->changeStatus($application->fresh(), $to, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function refused(JobApplication $application, JobApplicationStatus $to, array $context, string $field): void
    {
        $marker = $this->lastActivityId();
        $before = DB::table('job_applications')->where('id', $application->getKey())->first();

        try {
            $this->pipeline($application, $to, $context);
            $this->fail(sprintf('The move to %s must be refused.', $to->value));
        } catch (ContentRuleException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey($field, $exception->errors());
        }

        $this->assertEquals($before, DB::table('job_applications')->where('id', $application->getKey())->first(), 'A refused move changes nothing.');
        $this->assertCount(0, $this->activitiesSince($marker, 'job_applications', 'status_changed'), 'A refused move writes no stage entry.');
    }

    /**
     * @return array<string, mixed> the entry's properties
     */
    private function assertMove(JobApplication $application, int $marker, string $from, string $to): array
    {
        $entries = $this->activitiesSince($marker, 'job_applications', 'status_changed');

        $this->assertCount(1, $entries, sprintf('Exactly one stage entry for %s → %s.', $from, $to));
        $this->assertSame((int) $application->getKey(), (int) $entries->first()->subject_id);

        $properties = $this->propertiesOf($entries->first());
        $this->assertSame($from, $properties['old']['status'] ?? null);
        $this->assertSame($to, $properties['attributes']['status'] ?? null);

        return $properties;
    }
}
