<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Enums\EmploymentType;
use App\Enums\JobOpeningStatus;
use App\Enums\WorkMode;
use App\Models\Cms\JobOpening;
use App\Support\Format;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * A job opening (phase-04 §2.18, §6.8, §6.11 `StoreJobOpeningRequest` / `UpdateJobOpeningRequest`,
 * §8.8, acceptance test 39).
 *
 *   · `employment_type` / `work_mode` are enums, `salary_period` one of `monthly/yearly/hourly/project`;
 *   · `salary_min` / `salary_max` — `nullable, decimal:0,2, min:0`, and `Money::compare(min, max) <= 0`
 *     (bcmath, never a float);
 *   · `deadline` — `nullable, date, after_or_equal:today` ("today" in the display timezone). On an update
 *     the rule applies when the deadline is changed or the opening is (or becomes) open, so re-saving
 *     the description of a long-closed opening is not refused over its historic deadline; saving an
 *     **open** opening with a past deadline is always refused (§8.8);
 *   · `status` sits on the form's *Publishing* tab; the controller demands `jobs.change_status` before
 *     it changes and routes it through `JobOpeningService::changeStatus()` (which stamps
 *     `opened_at` / `closed_at`);
 *   · `department_id` is a deferred link (§2.1); `opened_at`, `closed_at`, `applications_count` are
 *     never writable.
 *
 * The using class must extend `CmsFormRequest` and implement `jobOpening()`.
 */
trait ValidatesJobOpening
{
    abstract public function jobOpening(): ?JobOpening;

    /**
     * @return array<string, list<mixed>>
     */
    protected function jobOpeningRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $id = $this->jobOpening()?->getKey();

        return array_merge([
            'title' => array_merge($required, ['bail', 'string', 'max:180']),
            'slug' => $this->slugRules('job_openings', is_numeric($id) ? (int) $id : null),
            'department' => ['sometimes', 'bail', 'nullable', 'string', 'max:100'],
            'department_id' => $this->deferredLinkRules('departments'),
            'location' => ['sometimes', 'bail', 'nullable', 'string', 'max:150'],
            'work_mode' => ['sometimes', 'bail', 'required', 'string', Rule::enum(WorkMode::class)],
            'employment_type' => array_merge($required, ['bail', 'string', Rule::enum(EmploymentType::class)]),
            'experience_min_years' => ['sometimes', 'bail', 'nullable', 'integer', 'between:0,40'],
            'experience_note' => ['sometimes', 'bail', 'nullable', 'string', 'max:150'],
            'openings_count' => ['sometimes', 'bail', 'required', 'integer', 'between:1,255'],
            'salary_min' => ['sometimes', 'bail', 'nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'salary_max' => ['sometimes', 'bail', 'nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'salary_period' => ['sometimes', 'bail', 'required', 'string', Rule::in(['monthly', 'yearly', 'hourly', 'project'])],
            'salary_visible' => ['sometimes', 'boolean'],
            'description' => array_merge($required, ['bail', 'string', 'max:200000']),
            'requirements' => ['sometimes', 'bail', 'nullable', 'string', 'max:200000'],
            'responsibilities' => ['sometimes', 'bail', 'nullable', 'string', 'max:200000'],
            'skills' => ['sometimes', 'bail', 'nullable', 'array', 'max:30'],
            'skills.*' => ['bail', 'string', 'max:150'],
            'deadline' => ['sometimes', 'bail', 'nullable', 'string', 'max:40', 'date'],
            'status' => ['sometimes', 'bail', 'string', Rule::enum(JobOpeningStatus::class)],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'bail', 'nullable', 'integer', 'min:0', 'max:2147483647'],

            'opened_at' => ['prohibited'],
            'closed_at' => ['prohibited'],
            'applications_count' => ['prohibited'],
        ], $this->seoRules());
    }

    protected function prepareJobOpeningInput(): void
    {
        $this->trimInputs(['title', 'department', 'location', 'experience_note', 'description', 'requirements', 'responsibilities', 'deadline']);
        $this->normaliseSlugInput();
        $this->normaliseAmounts(['salary_min', 'salary_max']);
        $this->cleanStringLists(['skills']);
    }

    protected function jobOpeningAfter(Validator $validator, bool $partial): void
    {
        $this->seoAfter($validator);

        $errors = $validator->errors();
        $job = $this->jobOpening();

        // Salary range, compared with bcmath.
        if (! $errors->has('salary_min') && ! $errors->has('salary_max')) {
            $min = $this->has('salary_min') ? $this->input('salary_min') : $job?->salary_min;
            $max = $this->has('salary_max') ? $this->input('salary_max') : $job?->salary_max;

            if (is_string($min) && is_string($max) && $min !== '' && $max !== '' && Money::compare(Money::of($min), Money::of($max)) > 0) {
                $errors->add('salary_max', 'The maximum salary cannot be lower than the minimum salary.');
            }
        }

        if ($errors->has('deadline') || $errors->has('status')) {
            return;
        }

        $deadline = $this->has('deadline') ? $this->input('deadline') : null;

        if (! is_string($deadline) || $deadline === '') {
            return;
        }

        try {
            $date = CarbonImmutable::parse($deadline)->toDateString();
            $today = CarbonImmutable::now(Format::displayTimezone())->toDateString();
        } catch (Throwable) {
            return;
        }

        if ($date >= $today) {
            return;
        }

        $status = $this->requestedStatus() ?? ($job?->status instanceof JobOpeningStatus ? $job->status : JobOpeningStatus::tryFrom((string) $job?->status));
        $storedDeadline = $job?->deadline instanceof DateTimeInterface ? $job->deadline->format('Y-m-d') : (is_string($job?->deadline) ? substr($job->deadline, 0, 10) : null);
        $changed = ! $partial || $storedDeadline !== $date;

        if ($status === JobOpeningStatus::Open) {
            $errors->add('deadline', 'An open position cannot have a deadline in the past.');
        } elseif ($changed) {
            $errors->add('deadline', 'The deadline must be today or later.');
        }
    }

    /**
     * The `job_openings` columns for `JobOpeningService` — without SEO and without `status`, which has
     * its own service call.
     *
     * @return array<string, mixed>
     */
    public function jobOpeningPayload(): array
    {
        $data = $this->safe()->except(['seo', 'status']);

        foreach (['salary_visible', 'is_featured'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $this->boolean($flag);
            }
        }

        foreach (['salary_min', 'salary_max'] as $amount) {
            if (array_key_exists($amount, $data) && $data[$amount] !== null) {
                $data[$amount] = Money::of((string) $data[$amount]);
            }
        }

        if (array_key_exists('skills', $data)) {
            $skills = $this->validatedStrings('skills');
            $data['skills'] = $skills === [] ? null : $skills;
        }

        return $data;
    }

    public function requestedStatus(): ?JobOpeningStatus
    {
        $status = $this->input('status');

        return is_string($status) ? JobOpeningStatus::tryFrom($status) : null;
    }
}
