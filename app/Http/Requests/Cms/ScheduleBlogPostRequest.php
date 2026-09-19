<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Schedule a blog post — `admin.blog-posts.schedule`, `can:blog_posts.change_status` plus
 * `BlogPostPolicy::changeStatus()` (phase-04 §6.7 invariant 1, §6.11 `ScheduleBlogPostRequest`,
 * acceptance test 28; the calendar's drag-to-reschedule posts here too, §8.7).
 *
 * `published_at` is required, a date, and strictly in the future. The editor enters a wall-clock time in
 * the display timezone (`localization.timezone`); it is compared and stored in UTC (D61) — the same
 * reading as Phase 3's `SchedulePageRequest`. A past moment is a 422 here; the service would otherwise
 * publish immediately.
 */
final class ScheduleBlogPostRequest extends CmsFormRequest
{
    protected function permission(): string
    {
        return 'blog_posts.change_status';
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'published_at' => ['bail', 'required', 'string', 'max:40', 'date'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('published_at')) {
                    return;
                }

                $at = $this->publishedAt();

                if ($at === null || ! $at->isFuture()) {
                    $validator->errors()->add('published_at', 'Choose a moment in the future.');
                }
            },
        ];
    }

    /**
     * The chosen moment, read in the display timezone and returned in UTC.
     */
    public function publishedAt(): ?CarbonImmutable
    {
        $value = $this->input('published_at');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value), Format::displayTimezone())->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
