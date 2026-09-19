<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Enums\JobApplicationStatus;
use App\Models\Cms\JobApplication;
use App\Support\Format;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Move a candidate along the six-stage pipeline — `admin.job-applications.status`,
 * `can:job_applications.change_status` (phase-04 §3 `allowedNext()`, §6.8 `changeStatus()`, §6.11,
 * acceptance test 38).
 *
 *   · `status` must be in `JobApplicationStatus::allowedNext()` of the **current** stage
 *     (`new → interview` is a 422);
 *   · `reason` is required when the target `requiresReason()` (rejected), at most 255 characters;
 *   · `interview_at` is required when the target `requiresInterviewSlot()` (interview) and must be in
 *     the future — read in the display timezone, compared and handed over in UTC (D61); `interview_mode`
 *     (`onsite` / `online` / `phone`) is required with it.
 *
 * The service re-checks the transition under its lock; this is the first, user-facing answer.
 */
final class ChangeApplicationStatusRequest extends CmsFormRequest
{
    public const INTERVIEW_MODES = JobApplication::INTERVIEW_MODES;

    protected function permission(): string
    {
        return 'job_applications.change_status';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $target = $this->target();

        return [
            'status' => ['bail', 'required', 'string', Rule::in($this->allowedTargets())],
            'reason' => $target?->requiresReason() === true
                ? ['bail', 'required', 'string', 'max:'.self::REASON_MAX]
                : ['bail', 'nullable', 'string', 'max:'.self::REASON_MAX],
            'interview_at' => $target?->requiresInterviewSlot() === true
                ? ['bail', 'required', 'string', 'max:40', 'date']
                : ['bail', 'nullable', 'string', 'max:40', 'date'],
            'interview_mode' => $target?->requiresInterviewSlot() === true
                ? ['bail', 'required', 'string', Rule::in(self::INTERVIEW_MODES)]
                : ['bail', 'nullable', 'string', Rule::in(self::INTERVIEW_MODES)],
            'interview_location' => ['bail', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'The candidate cannot move to that stage from the current one.',
            'reason.required' => 'Give a reason for rejecting the candidate.',
            'interview_at.required' => 'Choose the interview date and time.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->target()?->requiresInterviewSlot() !== true || $validator->errors()->has('interview_at')) {
                    return;
                }

                $at = $this->interviewAt();

                if ($at === null || ! $at->isFuture()) {
                    $validator->errors()->add('interview_at', 'The interview must be scheduled in the future.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['status', 'reason', 'interview_at', 'interview_mode', 'interview_location']);
    }

    public function targetStatus(): JobApplicationStatus
    {
        return JobApplicationStatus::from((string) $this->validated('status'));
    }

    /**
     * The `$context` of `JobApplicationService::changeStatus()`.
     *
     * @return array{reason: string|null, interview_at: CarbonImmutable|null, interview_mode: string|null, interview_location: string|null}
     */
    public function context(): array
    {
        $mode = $this->validated('interview_mode');
        $location = $this->validated('interview_location');

        return [
            'reason' => $this->reason(),
            'interview_at' => $this->interviewAt(),
            'interview_mode' => is_string($mode) ? $mode : null,
            'interview_location' => is_string($location) ? $location : null,
        ];
    }

    public function interviewAt(): ?CarbonImmutable
    {
        $value = $this->input('interview_at');

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, Format::displayTimezone())->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function application(): ?JobApplication
    {
        $application = $this->route('application');

        return $application instanceof JobApplication ? $application : null;
    }

    private function target(): ?JobApplicationStatus
    {
        $status = $this->input('status');

        return is_string($status) && in_array($status, $this->allowedTargets(), true)
            ? JobApplicationStatus::tryFrom($status)
            : null;
    }

    /**
     * The values the current stage may move to.
     *
     * @return list<string>
     */
    private function allowedTargets(): array
    {
        $current = $this->application()?->status;
        $current = $current instanceof JobApplicationStatus ? $current : JobApplicationStatus::tryFrom((string) $current);

        if ($current === null) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $next): string => $next instanceof BackedEnum ? (string) $next->value : (string) $next,
            $current->allowedNext(),
        ));
    }
}
