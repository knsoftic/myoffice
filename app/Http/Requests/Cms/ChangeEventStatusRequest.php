<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Enums\Cms\ContentStatus;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Publish, schedule, unpublish or archive an event — `admin.events.status`,
 * `can:events.change_status`.
 *
 * **Why this is not `UpdateContentStatusRequest`.** That class serves services, portfolio, team and
 * success stories through a hard-coded module allowlist that does not include `events`, so its
 * `permission()` would return null and refuse everyone, Super Admin included — and it refuses
 * `scheduled` outright, because none of those four schedule. An event does: `published_at` in the
 * future plus `scheduled` is how something goes live by itself, exactly as a blog post does it. Rather
 * than widen a shared class that four other modules depend on, this module states its own rule, the way
 * `ChangeJobOpeningStatusRequest` and `ScheduleBlogPostRequest` already do.
 *
 * `status` is explicit rather than a verb, so a double submit is idempotent. `published_at` is required
 * for — and only meaningful to — `scheduled`, is read in the display timezone and returned in UTC
 * (D61), and must be in the future: a schedule in the past is either a publish somebody mistyped or a
 * row that never goes live, and neither is what was meant.
 */
final class ChangeEventStatusRequest extends CmsFormRequest
{
    /** A schedule this close to now is a publish; the form's picker uses the same floor. */
    public const MIN_SCHEDULE_MINUTES = 5;

    protected function permission(): string
    {
        return 'events.change_status';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['bail', 'required', 'string', Rule::in([
                ContentStatus::Draft->value,
                ContentStatus::Scheduled->value,
                ContentStatus::Published->value,
                ContentStatus::Archived->value,
            ])],
            'published_at' => [
                'bail',
                'nullable',
                'required_if:status,'.ContentStatus::Scheduled->value,
                'string',
                'max:40',
                'date',
            ],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('status') !== ContentStatus::Scheduled->value || $validator->errors()->has('published_at')) {
                return;
            }

            $at = $this->publishedAt();

            if ($at === null || $at->lessThan(CarbonImmutable::now()->addMinutes(self::MIN_SCHEDULE_MINUTES))) {
                $validator->errors()->add('published_at', sprintf(
                    'Choose a moment at least %d minutes from now. A schedule in the past never fires.',
                    self::MIN_SCHEDULE_MINUTES,
                ));
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        foreach (['status', 'published_at'] as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $value = trim((string) $this->input($key));
                $this->merge([$key => $value === '' ? null : $value]);
            }
        }
    }

    public function contentStatus(): ContentStatus
    {
        return ContentStatus::from((string) $this->validated('status'));
    }

    /**
     * The requested go-live moment in UTC (D61), or null when the status is not `scheduled`.
     */
    public function publishedAt(): ?CarbonImmutable
    {
        $value = $this->input('published_at');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, Format::displayTimezone())->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
