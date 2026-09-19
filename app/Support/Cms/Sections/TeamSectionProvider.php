<?php

declare(strict_types=1);

namespace App\Support\Cms\Sections;

use App\Enums\Cms\ImageProfile;
use App\Enums\SocialPlatform;
use App\Models\Cms\TeamMember;
use App\Support\SettingsRepository;

/**
 * `team` section: members that are published **and** public, in `sort_order`, with photo, designation,
 * department (the group heading), skills and the validated social links. The "meet the team" link is
 * omitted while `website.team_page_enabled` is off.
 */
final class TeamSectionProvider extends MarketingSectionProvider
{
    public function key(): string
    {
        return 'team';
    }

    protected function module(): string
    {
        return 'team';
    }

    protected function defaultLimit(): int
    {
        return 8;
    }

    protected function build(array $options): array
    {
        $page = filter_var(app(SettingsRepository::class)->get('website.team_page_enabled', true), FILTER_VALIDATE_BOOLEAN);

        $items = TeamMember::query()->public()
            ->with('photo')
            ->ordered()
            ->limit($options['limit'])
            ->get()
            ->map(fn (TeamMember $member): array => [
                'id' => (int) $member->getKey(),
                'name' => (string) $member->name,
                'slug' => (string) $member->slug,
                'designation' => (string) $member->designation,
                'department' => $member->department,
                'bio' => $member->bio,
                'skills' => array_values((array) ($member->skills ?? [])),
                'experience_years' => $member->experience_years,
                'experience_label' => $member->experience_label,
                'photo' => $this->image($member->photo, ImageProfile::Thumbnail),
                'portfolio_url' => $member->portfolio_url,
                'social_links' => array_map(static fn (array $link): array => [
                    'platform' => $link['platform'] instanceof SocialPlatform ? $link['platform']->value : (string) $link['platform'],
                    'label' => $link['platform'] instanceof SocialPlatform ? $link['platform']->label() : (string) $link['platform'],
                    'icon' => $link['platform'] instanceof SocialPlatform ? $link['platform']->icon() : 'globe-alt',
                    'url' => (string) $link['url'],
                ], $member->socialLinks()),
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'index_url' => $page ? $this->url('site.team.index') : null,
        ];
    }
}
