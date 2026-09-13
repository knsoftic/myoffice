<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Schedule a page to publish itself (`admin.website.pages.schedule`, `can:pages.change_status`,
 * §6.4 `schedule()`, FT-32).
 *
 * The editor enters a wall-clock time in the display timezone (`localization.timezone`); it is stored in
 * UTC (D61). The moment must be in the future — the service refuses a past one again.
 */
final class SchedulePageRequest extends CmsFormRequest
{
    protected function permission(): string
    {
        return 'pages.change_status';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'publish_at' => ['bail', 'required', 'string', 'max:40', 'date'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('publish_at')) {
                    return;
                }

                $at = $this->publishAt();

                if ($at === null || ! $at->isFuture()) {
                    $validator->errors()->add('publish_at', 'Choose a moment in the future.');
                }
            },
        ];
    }

    /**
     * The chosen moment, read in the display timezone and returned in UTC (D61).
     */
    public function publishAt(): ?CarbonImmutable
    {
        $value = $this->input('publish_at');

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
