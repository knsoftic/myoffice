<?php

declare(strict_types=1);

namespace App\Support\Cms\Sections;

use App\Enums\EmploymentType;
use App\Enums\WorkMode;
use App\Models\Cms\JobOpening;
use App\Support\SettingsRepository;

/**
 * `careers` teaser section: openings that are open with no deadline or a deadline of today or later.
 * Salary figures are included **only** when `salary_visible` is on — otherwise the partial prints
 * "Negotiable" and the numbers never reach the HTML. Nothing while `website.careers_enabled` is off.
 */
final class CareersSectionProvider extends MarketingSectionProvider
{
    public function key(): string
    {
        return 'careers';
    }

    protected function module(): string
    {
        return 'jobs';
    }

    protected function defaultLimit(): int
    {
        return 5;
    }

    protected function build(array $options): array
    {
        if (! filter_var(app(SettingsRepository::class)->get('website.careers_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return ['items' => [], 'index_url' => null];
        }

        $items = JobOpening::query()->public()
            ->when($options['featured_only'], static fn ($builder) => $builder->where('job_openings.is_featured', true))
            ->orderByDesc('job_openings.is_featured')
            ->ordered()
            ->limit($options['limit'])
            ->get()
            ->map(fn (JobOpening $job): array => [
                'id' => (int) $job->getKey(),
                'title' => (string) $job->title,
                'slug' => (string) $job->slug,
                'url' => $this->url('site.careers.show', ['jobOpening' => $job->slug]),
                'department' => $job->department,
                'location' => $job->location,
                'work_mode' => $job->work_mode instanceof WorkMode ? ['value' => $job->work_mode->value, 'label' => $job->work_mode->label()] : null,
                'employment_type' => $job->employment_type instanceof EmploymentType ? ['value' => $job->employment_type->value, 'label' => $job->employment_type->label()] : null,
                'deadline' => $this->date($job->deadline),
                'salary' => (bool) $job->salary_visible && ($job->salary_min !== null || $job->salary_max !== null)
                    ? [
                        'min' => $job->salary_min === null ? null : (string) $job->salary_min,
                        'max' => $job->salary_max === null ? null : (string) $job->salary_max,
                        'period' => (string) $job->salary_period,
                    ]
                    : null,
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'index_url' => $this->url('site.careers.index'),
        ];
    }
}
