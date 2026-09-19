<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\EmploymentType;
use App\Enums\JobOpeningStatus;
use App\Enums\WorkMode;
use App\Models\Cms\JobOpening;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\Support\ContentHelper;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Job openings — the careers board (phase-04 §6.8, requirement §16). Table `job_openings`, module slug
 * `jobs` (never a table called `jobs`: that is Laravel's queue).
 *
 * Invariants:
 *
 *   · Salaries are `decimal(15,2)` strings through `Money`; `Money::compare(min, max) <= 0` or a 422.
 *     `salary_visible = false` keeps the figures and the public page shows "Negotiable".
 *   · An opening can be `open` only while its deadline is null or today or later (business timezone) —
 *     saving or re-opening with a past deadline is a 422 on `deadline`.
 *   · `opened_at` is stamped the first time the status becomes `open`; `closed_at` when it becomes
 *     `closed` or `filled` (and cleared if it is re-opened). Every status move is one activity entry with
 *     the old and new status and the reason.
 *   · `closeExpired()` closes `open` openings whose deadline has passed, one entry each, and returns the
 *     count (`careers:close-expired`, daily at 00:10).
 *   · `purge()` is the only permanent delete: the applications and the opening are removed in one
 *     transaction, then every CV file is deleted from the private disk once it has committed — the DB
 *     cascade is only the last-resort net. A soft delete keeps everything.
 *   · Rich text (`description`, `requirements`, `responsibilities`) through `RichText::sanitize()`; SEO is
 *     `seo_meta` through `SeoService` (D23).
 */
final class JobOpeningService
{
    private const MODULE = 'jobs';

    private const LABEL = 'Job opening';

    public function __construct(
        private readonly ContentHelper $content,
        private readonly ApplicationCvService $cvs,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): JobOpening
    {
        return $this->content->transaction(function () use ($data): JobOpening {
            $job = new JobOpening;
            $manualSlug = $this->content->manualSlug($data);
            $status = $this->status($data['status'] ?? null) ?? JobOpeningStatus::Draft;

            $job->fill($this->attributes($data, null));
            $job->setAttribute('slug', $manualSlug ?? '');
            $job->setAttribute('status', $status);

            if (! array_key_exists('sort_order', $data)) {
                $job->setAttribute('sort_order', (int) JobOpening::query()->withTrashed()->max('sort_order') + 1);
            }

            $this->assertSalaryRange($job);
            $this->assertOpenable($job, $status);
            $this->stamp($job, null, $status);

            $this->content->saveWithSlug($job, $manualSlug !== null);
            $this->content->saveSeo($job, $data);

            if ($status === JobOpeningStatus::Open) {
                $this->content->flushPublicCache(sprintf('Job opening "%s" posted', (string) $job->getAttribute('title')));
            }

            return $job;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(JobOpening $job, array $data): JobOpening
    {
        return $this->content->transaction(function () use ($job, $data): JobOpening {
            $oldSlug = (string) $job->getAttribute('slug');
            $wasPublic = $this->isPublic($job);
            $statusBefore = $job->getAttribute('status');
            $manualSlug = $this->content->manualSlug($data);
            $status = $this->status($data['status'] ?? null);

            $job->fill($this->attributes($data, $job));

            if ($manualSlug !== null) {
                $job->setAttribute('slug', $manualSlug);
            }

            $this->assertSalaryRange($job);

            // Editing the deadline of an opening that stays open is held to the same rule.
            $this->assertOpenable($job, $status ?? ($statusBefore instanceof JobOpeningStatus ? $statusBefore : JobOpeningStatus::Draft));

            $this->content->saveWithSlug($job, $manualSlug !== null);

            if ((string) $job->getAttribute('slug') !== $oldSlug) {
                $this->content->auditSlugChange($job, self::MODULE, $oldSlug, (string) $job->getAttribute('slug'), $job->getAttribute('opened_at') !== null);
            }

            if ($status !== null && $status !== $statusBefore) {
                $this->changeStatusLocked($job, $status, null);
            }

            $this->content->saveSeo($job, $data);

            if ($job->getAttribute('status') === $statusBefore && ($wasPublic || $this->isPublic($job))) {
                $this->content->flushPublicCache(sprintf('Job opening "%s" updated', (string) $job->getAttribute('title')));
            }

            return $job;
        });
    }

    public function changeStatus(JobOpening $job, JobOpeningStatus $status, ?string $reason = null): JobOpening
    {
        return $this->content->transaction(fn (): JobOpening => $this->changeStatusLocked($job, $status, $reason));
    }

    /**
     * Close every `open` opening whose deadline has passed. Returns how many were closed.
     */
    public function closeExpired(): int
    {
        return $this->content->transaction(function (): int {
            $jobs = JobOpening::query()->expired()->orderBy('id')->lockForUpdate()->get();

            foreach ($jobs as $job) {
                $this->changeStatusLocked($job, JobOpeningStatus::Closed, 'Deadline passed', flush: false);
            }

            if ($jobs->isNotEmpty()) {
                $this->content->flushPublicCache(sprintf('%d expired job %s closed', $jobs->count(), $jobs->count() === 1 ? 'opening' : 'openings'));
            }

            return $jobs->count();
        });
    }

    /**
     * Soft delete: the applications and every CV stay, so a restore is lossless.
     */
    public function delete(JobOpening $job): void
    {
        $this->content->transaction(function () use ($job): void {
            $wasPublic = $this->isPublic($job);

            $job->delete();

            if ($wasPublic) {
                $this->content->flushPublicCache(sprintf('Job opening "%s" deleted', (string) $job->getAttribute('title')));
            }
        });
    }

    /**
     * Force delete the opening with every application, then remove their CV files.
     */
    public function purge(JobOpening $job): void
    {
        $paths = $this->content->transaction(function () use ($job): array {
            $connection = $this->content->connection();
            $jobId = (int) $job->getKey();

            $connection->table('job_openings')->where('id', $jobId)->lockForUpdate()->first(['id']);

            $paths = $connection->table('job_applications')->where('job_opening_id', $jobId)->pluck('cv_path')->filter()->values()->all();
            $count = count($paths);
            $wasPublic = $this->isPublic($job);

            // Query-builder delete: the per-row forceDeleted hook would remove files before this commits.
            $connection->table('job_applications')->where('job_opening_id', $jobId)->delete();

            $job->forceDelete();

            $this->content->audit(
                self::MODULE,
                sprintf('Job opening "%s" permanently deleted with %d %s', (string) $job->getAttribute('title'), $count, $count === 1 ? 'application' : 'applications'),
                null,
                ['job_opening_id' => $jobId, 'applications_removed' => $count],
                null,
                'purged',
            );

            if ($wasPublic) {
                $this->content->flushPublicCache(sprintf('Job opening "%s" purged', (string) $job->getAttribute('title')));
            }

            return $paths;
        });

        foreach ($paths as $path) {
            $this->cvs->deletePath((string) $path);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function changeStatusLocked(JobOpening $job, JobOpeningStatus $to, ?string $reason, bool $flush = true): JobOpening
    {
        $current = $job->getAttribute('status');
        $from = $current instanceof JobOpeningStatus ? $current : (JobOpeningStatus::tryFrom((string) $current) ?? JobOpeningStatus::Draft);

        if ($from === $to) {
            return $job;
        }

        $this->assertOpenable($job, $to);

        $wasPublic = $this->isPublic($job);
        $reason = $reason === null ? null : $this->content->plain($reason, 500);

        $this->content->quietly($job, function () use ($job, $from, $to): void {
            $job->setAttribute('status', $to);
            $this->stamp($job, $from, $to);
            $job->save();
        });

        $this->content->audit(
            self::MODULE,
            sprintf('Job opening "%s" moved from %s to %s', (string) $job->getAttribute('title'), $from->label(), $to->label()),
            $job,
            [
                'old' => ['status' => $from->value],
                'attributes' => [
                    'status' => $to->value,
                    'opened_at' => $job->getAttribute('opened_at')?->toDateTimeString(),
                    'closed_at' => $job->getAttribute('closed_at')?->toDateTimeString(),
                ],
            ],
            $reason,
            'status_changed',
        );

        if ($flush && ($wasPublic || $this->isPublic($job))) {
            $this->content->flushPublicCache(sprintf('Job opening "%s" %s', (string) $job->getAttribute('title'), $to->value));
        }

        return $job;
    }

    private function stamp(JobOpening $job, ?JobOpeningStatus $from, JobOpeningStatus $to): void
    {
        if ($to === JobOpeningStatus::Open) {
            if ($job->getAttribute('opened_at') === null) {
                $job->forceFill(['opened_at' => Carbon::now()]);
            }

            $job->forceFill(['closed_at' => null]);
        }

        if ($to->isClosed() && ($from === null || ! $from->isClosed() || $job->getAttribute('closed_at') === null)) {
            $job->forceFill(['closed_at' => Carbon::now()]);
        }
    }

    private function assertOpenable(JobOpening $job, JobOpeningStatus $status): void
    {
        if ($status !== JobOpeningStatus::Open) {
            return;
        }

        $deadline = $job->getAttribute('deadline');

        if ($deadline !== null && $deadline->toDateString() < JobOpening::businessToday()) {
            throw ContentRuleException::refuse('deadline', 'An open position needs a deadline of today or later.');
        }
    }

    private function assertSalaryRange(JobOpening $job): void
    {
        $min = $job->getAttribute('salary_min');
        $max = $job->getAttribute('salary_max');

        if ($min !== null && $max !== null && Money::compare((string) $min, (string) $max) > 0) {
            throw ContentRuleException::salaryRange();
        }
    }

    private function isPublic(JobOpening $job): bool
    {
        try {
            return $job->acceptsApplications();
        } catch (Throwable) {
            return false;
        }
    }

    private function status(mixed $value): ?JobOpeningStatus
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof JobOpeningStatus) {
            return $value;
        }

        return JobOpeningStatus::tryFrom(is_scalar($value) ? (string) $value : '')
            ?? throw ContentRuleException::refuse('status', 'Choose a valid status.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?JobOpening $job): array
    {
        $attributes = [];
        $creating = $job === null;

        if ($creating || array_key_exists('title', $data)) {
            $title = $this->content->plain($data['title'] ?? null, 180);

            if ($title === null) {
                throw ContentRuleException::refuse('title', 'A job title is required.');
            }

            $attributes['title'] = $title;
        }

        foreach (['department' => 100, 'location' => 150, 'experience_note' => 150] as $column => $limit) {
            if (array_key_exists($column, $data)) {
                $attributes[$column] = $this->content->plain($data[$column], $limit);
            }
        }

        if (array_key_exists('department_id', $data)) {
            $attributes['department_id'] = $this->content->id($data['department_id']);
        }

        if ($creating || array_key_exists('work_mode', $data)) {
            $mode = $data['work_mode'] ?? WorkMode::Onsite;
            $attributes['work_mode'] = $mode instanceof WorkMode ? $mode : (WorkMode::tryFrom((string) $mode)
                ?? throw ContentRuleException::refuse('work_mode', 'Choose on-site, remote or hybrid.'));
        }

        if ($creating || array_key_exists('employment_type', $data)) {
            $type = $data['employment_type'] ?? null;
            $attributes['employment_type'] = $type instanceof EmploymentType ? $type : (EmploymentType::tryFrom(is_scalar($type) ? (string) $type : '')
                ?? throw ContentRuleException::refuse('employment_type', 'Choose an employment type.'));
        }

        if (array_key_exists('experience_min_years', $data)) {
            $years = $data['experience_min_years'];

            if ($years !== null && $years !== '' && (! is_numeric($years) || (int) $years < 0 || (int) $years > JobOpening::MAX_EXPERIENCE_YEARS)) {
                throw ContentRuleException::refuse('experience_min_years', sprintf('Experience must be between 0 and %d years.', JobOpening::MAX_EXPERIENCE_YEARS));
            }

            $attributes['experience_min_years'] = $this->content->int($years);
        }

        if ($creating || array_key_exists('openings_count', $data)) {
            $attributes['openings_count'] = $this->content->int($data['openings_count'] ?? null, 1, 1, 255);
        }

        foreach (['salary_min', 'salary_max'] as $column) {
            if (array_key_exists($column, $data)) {
                $attributes[$column] = $this->content->money($data[$column], $column);
            }
        }

        if ($creating || array_key_exists('salary_period', $data)) {
            $period = (string) ($data['salary_period'] ?? 'monthly');

            if (! in_array($period, JobOpening::SALARY_PERIODS, true)) {
                throw ContentRuleException::refuse('salary_period', 'Choose monthly, yearly, hourly or per project.');
            }

            $attributes['salary_period'] = $period;
        }

        if ($creating || array_key_exists('salary_visible', $data)) {
            $attributes['salary_visible'] = $this->content->bool($data['salary_visible'] ?? null, true);
        }

        if ($creating || array_key_exists('description', $data)) {
            $description = $this->content->rich($data['description'] ?? null);

            if ($description === null) {
                throw ContentRuleException::refuse('description', 'A job description is required.');
            }

            $attributes['description'] = $description;
        }

        foreach (['requirements', 'responsibilities'] as $column) {
            if (array_key_exists($column, $data)) {
                $attributes[$column] = $this->content->rich($data[$column]);
            }
        }

        if (array_key_exists('skills', $data)) {
            $attributes['skills'] = $this->content->stringList($data['skills'], JobOpening::MAX_SKILLS, 150, 'skills');
        }

        if (array_key_exists('deadline', $data)) {
            $attributes['deadline'] = $this->date($data['deadline'], 'deadline');
        }

        if ($creating || array_key_exists('is_featured', $data)) {
            $attributes['is_featured'] = $this->content->bool($data['is_featured'] ?? null, false);
        }

        if (array_key_exists('sort_order', $data)) {
            $attributes['sort_order'] = $this->content->int($data['sort_order'], 0, 0);
        }

        return $attributes;
    }

    private function date(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::createFromFormat('!Y-m-d', (string) $value);
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof Carbon) {
            throw ContentRuleException::refuse($field, 'Enter the date as YYYY-MM-DD.');
        }

        return $date->toDateString();
    }
}
