<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Enums\JobOpeningStatus;
use Illuminate\Validation\Rule;

/**
 * Open, close, fill or return a job to draft — `admin.jobs.status`, `can:jobs.change_status`
 * (phase-04 §6.8 `changeStatus()`, §6.11 `ChangeJobOpeningStatusRequest`).
 *
 * `status` must be a `JobOpeningStatus`; `reason` is an optional note carried into the activity entry.
 * Whether an opening may be opened with a passed deadline is the service's call, under its lock.
 */
final class ChangeJobOpeningStatusRequest extends CmsFormRequest
{
    protected function permission(): string
    {
        return 'jobs.change_status';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['bail', 'required', 'string', Rule::enum(JobOpeningStatus::class)],
            'reason' => ['bail', 'nullable', 'string', 'max:'.self::REASON_MAX],
        ];
    }

    public function jobStatus(): JobOpeningStatus
    {
        return JobOpeningStatus::from((string) $this->validated('status'));
    }
}
