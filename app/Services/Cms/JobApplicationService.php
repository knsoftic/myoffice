<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\InquirySource;
use App\Enums\JobApplicationStatus;
use App\Enums\JobOpeningStatus;
use App\Events\Cms\JobApplicationReceived;
use App\Events\Cms\JobApplicationStatusChanged;
use App\Models\Cms\JobApplication;
use App\Models\Cms\JobOpening;
use App\Models\User;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\Exceptions\JobClosedException;
use App\Services\Cms\Support\ContentHelper;
use App\Support\Format;
use App\Support\Modules;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Candidates and the six-stage hiring pipeline (phase-04 §6.8, requirement §16).
 *
 * `apply()` invariants:
 *
 *   1. Refused with `JobClosedException` (422) unless `website.careers_enabled` is on, the `jobs` module is
 *      enabled, the status accepts applications (only `open`) and the deadline is null or today or later —
 *      checked again under a row lock inside the transaction.
 *   2. `SpamGuard` runs before anything is stored; a spam verdict stores **nothing** (no row, no file) and
 *      returns an unsaved application, so the caller answers exactly as for a genuine one.
 *   3. The CV is stored **before** the insert; if the transaction fails, the file is deleted — no orphans.
 *   4. A second application from the same address to the same opening is a 422 on `email` ("You have
 *      already applied for this position."), caught from `uq_job_application_per_job`, never a 500.
 *   5. `job_openings.applications_count` is bumped with an atomic increment in the same transaction.
 *   6. Status `new`, source `website`, IP and user agent recorded.
 *   7. `JobApplicationReceived` fires after commit.
 *
 * `changeStatus()` invariants: the target must be in `allowedNext()` (422 otherwise); `rejected` requires a
 * reason; `interview` requires a future `interview_at` and a mode; `status_changed_at` / `status_changed_by`
 * are stamped; `JobApplicationStatusChanged` fires (its listener writes the one activity entry with old and
 * new stage, reason and slot); the CV is never touched.
 */
final class JobApplicationService
{
    private const MODULE = 'job_applications';

    public function __construct(
        private readonly ContentHelper $content,
        private readonly ApplicationCvService $cvs,
        private readonly SpamGuard $spam,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(JobOpening $job, array $data, UploadedFile $cv, Request $request): JobApplication
    {
        $this->assertAccepting($job);

        $verdict = $this->spam->verdict($data + ['job_opening_id' => (int) $job->getKey()], $request);

        if ($verdict->isSpam) {
            Log::info('Job application dropped as spam', [
                'job_opening_id' => (int) $job->getKey(),
                'reason' => $verdict->reason,
                'score' => $verdict->score,
            ]);

            return (new JobApplication)->fill(['job_opening_id' => (int) $job->getKey()]);
        }

        $attributes = $this->attributes($data);

        if (JobApplication::withTrashed()->where('job_opening_id', $job->getKey())->where('email', $attributes['email'])->exists()) {
            throw ContentRuleException::duplicateApplication();
        }

        $stored = $this->cvs->store($cv, $job);

        try {
            return $this->content->transaction(function () use ($job, $attributes, $stored, $request): JobApplication {
                $locked = JobOpening::query()->whereKey($job->getKey())->lockForUpdate()->first();

                if (! $locked instanceof JobOpening || ! $locked->acceptsApplications()) {
                    throw JobClosedException::forOpening((string) $job->getAttribute('title'));
                }

                $application = new JobApplication;
                $application->fill($attributes + [
                    'job_opening_id' => (int) $job->getKey(),
                    'cv_path' => $stored['path'],
                    'cv_original_name' => $stored['original_name'],
                    'cv_mime' => $stored['mime'],
                    'cv_size' => $stored['size'],
                    'source' => InquirySource::Website,
                    'ip_address' => mb_substr((string) $request->ip(), 0, 45) ?: null,
                    'user_agent' => $this->content->plain($request->userAgent(), 1000),
                ]);
                $application->forceFill(['status' => JobApplicationStatus::New]);

                try {
                    $this->content->transaction(static fn () => $application->save());
                } catch (UniqueConstraintViolationException) {
                    throw ContentRuleException::duplicateApplication();
                }

                $this->content->connection()->table('job_openings')->where('id', $job->getKey())->increment('applications_count');

                event(new JobApplicationReceived($application));

                return $application;
            });
        } catch (Throwable $exception) {
            $this->cvs->deletePath($stored['path']);

            throw $exception;
        }
    }

    /**
     * @param  array{reason?: string|null, interview_at?: mixed, interview_mode?: string|null, interview_location?: string|null}  $context
     */
    public function changeStatus(JobApplication $a, JobApplicationStatus $to, array $context = []): JobApplication
    {
        return $this->content->transaction(function () use ($a, $to, $context): JobApplication {
            $stored = JobApplication::withTrashed()->whereKey($a->getKey())->lockForUpdate()->value('status');

            if ($stored === null) {
                throw ContentRuleException::refuse('status', 'This application no longer exists.');
            }

            // Eloquent's value() returns the cast attribute — the enum itself.
            $from = $stored instanceof JobApplicationStatus ? $stored : (JobApplicationStatus::tryFrom((string) $stored) ?? JobApplicationStatus::New);

            if (! $from->canTransitionTo($to)) {
                throw ContentRuleException::transitionNotAllowed($from->label(), $to->label());
            }

            $reason = $this->content->plain($context['reason'] ?? null, 255);

            if ($to->requiresReason() && $reason === null) {
                throw ContentRuleException::reasonRequired();
            }

            $changes = [
                'status' => $to,
                'status_changed_at' => Carbon::now(),
                'status_changed_by' => $this->content->actorId(),
            ];

            $interview = [];

            if ($to->requiresInterviewSlot()) {
                $at = $this->interviewAt($context['interview_at'] ?? null);
                $mode = is_string($context['interview_mode'] ?? null) ? strtolower(trim($context['interview_mode'])) : '';

                if ($at === null || ! in_array($mode, JobApplication::INTERVIEW_MODES, true)) {
                    throw ContentRuleException::interviewSlotRequired();
                }

                $location = $this->content->plain($context['interview_location'] ?? null, 255);

                $changes += ['interview_at' => $at, 'interview_mode' => $mode, 'interview_location' => $location];
                $interview = [
                    'interview_at' => $at->toDateTimeString(),
                    'interview_mode' => $mode,
                    'interview_location' => $location,
                ];
            }

            if ($to === JobApplicationStatus::Rejected) {
                $changes['rejection_reason'] = $reason;
            } elseif ($from === JobApplicationStatus::Rejected) {
                $changes['rejection_reason'] = null;
            }

            $this->content->quietly($a, function () use ($a, $changes): void {
                $a->forceFill($changes)->save();
            });

            event(new JobApplicationStatusChanged($a, $from, $to, $reason, $interview, $this->content->actorId()));

            return $a;
        });
    }

    public function assign(JobApplication $a, ?User $reviewer): JobApplication
    {
        return $this->content->transaction(function () use ($a, $reviewer): JobApplication {
            if ($reviewer !== null && ! $reviewer->isActive()) {
                throw ContentRuleException::refuse('user_id', 'Choose an active user as the reviewer.');
            }

            $a->forceFill(['assigned_to' => $reviewer?->getKey()])->save();

            return $a;
        });
    }

    public function saveNotes(JobApplication $a, ?string $notes, ?int $rating): JobApplication
    {
        if ($rating !== null && ($rating < JobApplication::MIN_RATING || $rating > JobApplication::MAX_RATING)) {
            throw ContentRuleException::refuse('rating', 'The rating must be between 1 and 5.');
        }

        return $this->content->transaction(function () use ($a, $notes, $rating): JobApplication {
            $a->forceFill([
                'internal_notes' => $this->content->plain($notes),
                'rating' => $rating,
            ])->save();

            return $a;
        });
    }

    /**
     * Soft delete — the CV stays on the private disk so a restore is lossless.
     */
    public function delete(JobApplication $a): void
    {
        $this->content->transaction(static fn () => $a->delete());
    }

    /**
     * Permanent delete: the row, then its CV file once the delete has committed. Frees the address to
     * apply to the same opening again (§12 R4).
     */
    public function forceDelete(JobApplication $a): void
    {
        $path = (string) $a->getAttribute('cv_path');
        $jobId = (int) $a->getAttribute('job_opening_id');

        $this->content->transaction(function () use ($a, $jobId): void {
            $this->content->connection()->table('job_applications')->where('id', $a->getKey())->delete();

            $this->content->connection()->table('job_openings')
                ->where('id', $jobId)
                ->where('applications_count', '>', 0)
                ->decrement('applications_count');

            $this->content->audit(
                self::MODULE,
                sprintf('Application #%d permanently deleted', (int) $a->getKey()),
                null,
                ['job_application_id' => (int) $a->getKey(), 'job_opening_id' => $jobId],
                null,
                'force_deleted',
            );
        });

        $a->exists = false;
        $this->cvs->deletePath($path);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function assertAccepting(JobOpening $job): void
    {
        if (! $this->content->bool($this->content->settings()->get('website.careers_enabled', true), true)
            || ! Modules::enabled('jobs')) {
            throw JobClosedException::careersDisabled();
        }

        $status = $job->getAttribute('status');

        if (! $status instanceof JobOpeningStatus || ! $job->acceptsApplications()) {
            throw JobClosedException::forOpening((string) $job->getAttribute('title'));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $name = $this->content->plain($data['applicant_name'] ?? $data['name'] ?? null, 150);

        if ($name === null) {
            throw ContentRuleException::refuse('applicant_name', 'Your name is required.');
        }

        $email = strtolower(trim((string) (is_scalar($data['email'] ?? null) ? $data['email'] : '')));

        if ($email === '' || mb_strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ContentRuleException::refuse('email', 'Enter a valid email address.');
        }

        $phone = $this->content->plain($data['phone'] ?? null, 32);

        if ($phone === null) {
            throw ContentRuleException::refuse('phone', 'A phone number is required.');
        }

        $experience = $data['experience_years'] ?? null;

        if ($experience !== null && $experience !== '' && (! is_numeric($experience) || (int) $experience < 0 || (int) $experience > JobApplication::MAX_EXPERIENCE_YEARS)) {
            throw ContentRuleException::refuse('experience_years', sprintf('Experience must be between 0 and %d years.', JobApplication::MAX_EXPERIENCE_YEARS));
        }

        $coverLetter = $this->content->plain($data['cover_letter'] ?? null);

        if ($coverLetter !== null && mb_strlen($coverLetter) > JobApplication::MAX_COVER_LETTER_LENGTH) {
            throw ContentRuleException::refuse('cover_letter', sprintf('The cover letter may be at most %d characters.', JobApplication::MAX_COVER_LETTER_LENGTH));
        }

        return [
            'applicant_name' => $name,
            'email' => $email,
            'phone' => $phone,
            'whatsapp' => $this->content->plain($data['whatsapp'] ?? null, 32),
            'city' => $this->content->plain($data['city'] ?? null, 100),
            'experience_years' => $this->content->int($experience),
            'expected_salary' => $this->content->money($data['expected_salary'] ?? null, 'expected_salary'),
            'cover_letter' => $coverLetter,
            'portfolio_url' => $this->content->url($data['portfolio_url'] ?? null, 'portfolio_url'),
            'linkedin_url' => $this->content->url($data['linkedin_url'] ?? null, 'linkedin_url'),
        ];
    }

    /**
     * A future interview moment, read in the business timezone and stored in UTC (D61); null otherwise.
     */
    private function interviewAt(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $at = $value instanceof CarbonInterface ? Carbon::instance($value) : Carbon::parse((string) $value, Format::timezone());
        } catch (Throwable) {
            return null;
        }

        if (! $at->isFuture()) {
            return null;
        }

        return $at->setTimezone((string) config('app.timezone', 'UTC'));
    }
}
