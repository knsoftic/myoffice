<?php

declare(strict_types=1);

namespace App\Support\Cms\Sections;

use App\Enums\Cms\ButtonStyle;
use App\Enums\Cms\SectionPlacement;
use App\Enums\TestimonialType;
use App\Support\Cms\SectionRegistry;

/**
 * The nine public section types phase-04 declares into Phase 3's section registry (phase-03 §6.1
 * "Section types declared by later phases", phase-04 §8.11, §13 Phase 3 row; E19 for `contact`).
 *
 * Pure arrays, like the registry itself. Registered from `AppServiceProvider::register()` through
 * `SectionRegistry::register()` — guarded by `exists()`, because the registry's runtime list is static
 * and survives from one test's application to the next.
 *
 * Every type is `is_live`: its provider is resolved at render time (a newly approved testimonial or a
 * newly published post appears without re-publishing the section) and caches itself under the D22 version
 * stamp. The option fields (`limit`, `featured_only`, `category`, `type`) are exactly the keys
 * `MarketingSectionProvider::options()` reads; `heading`, `description`, `view_all_link` and
 * `submit_label` are exactly what the partials under `site/sections/` read.
 */
final class MarketingSectionTypes
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'services' => self::catalogue('Services', 'The published service catalogue as cards.', 'briefcase', 'business', 30, ServicesSectionProvider::class, 'What we build', 'View all services', '/services', category: true),
            'portfolio' => self::catalogue('Portfolio', 'Published case studies with their cover images.', 'photo', 'business', 50, PortfolioSectionProvider::class, 'Recent work', 'View the portfolio', '/portfolio', category: true),
            'team' => self::catalogue('Team', 'Published, public team members.', 'user-group', 'business', 60, TeamSectionProvider::class, 'Meet the team', 'Meet everyone', '/team', featured: false, limit: 8),
            'testimonials' => self::catalogue('Testimonials', 'Approved client and student testimonials only.', 'chat-bubble-left-right', 'engagement', 70, TestimonialsSectionProvider::class, 'What our clients say', null, null, testimonialType: true),
            'student_reviews' => self::catalogue('Student reviews', 'Approved student reviews only.', 'star', 'engagement', 80, StudentReviewsSectionProvider::class, 'What our students say', null, null),
            'success_stories' => self::catalogue('Success stories', 'Published student success stories.', 'trophy', 'engagement', 90, SuccessStoriesSectionProvider::class, 'Success stories', null, null),
            'blog' => self::catalogue('Blog teaser', 'The latest published blog posts.', 'newspaper', 'content', 100, BlogSectionProvider::class, 'From the blog', 'Read the blog', '/blog', category: true, limit: 3),
            'careers' => self::catalogue('Careers teaser', 'Open job openings whose deadline has not passed.', 'identification', 'business', 105, CareersSectionProvider::class, 'Join our team', 'See all openings', '/careers', limit: 4),
            'contact' => self::contact(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function catalogue(
        string $label,
        string $description,
        string $icon,
        string $group,
        int $homeSort,
        string $provider,
        string $heading,
        ?string $linkLabel,
        ?string $linkUrl,
        bool $featured = true,
        bool $category = false,
        bool $testimonialType = false,
        int $limit = 6,
    ): array {
        $fields = [
            'heading' => [
                'label' => 'Heading',
                'type' => SectionRegistry::TYPE_TEXT,
                'default' => $heading,
                'max_chars' => 120,
                'sort' => 10,
            ],
            'description' => [
                'label' => 'Short introduction',
                'type' => SectionRegistry::TYPE_TEXTAREA,
                'default' => null,
                'max_chars' => 300,
                'sort' => 20,
            ],
            'view_all_link' => [
                'label' => '"View all" button',
                'type' => SectionRegistry::TYPE_LINK,
                'default' => [
                    'label' => $linkLabel,
                    'url' => $linkUrl,
                    'style' => ButtonStyle::Outline->value,
                    'new_tab' => false,
                ],
                'help' => 'Shown only while the section has something to show.',
                'tab' => SectionRegistry::TAB_BUTTONS,
                'sort' => 30,
            ],
            'limit' => [
                'label' => 'How many to show',
                'type' => SectionRegistry::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1', 'max:24'],
                'default' => $limit,
                'span' => 6,
                'tab' => SectionRegistry::TAB_ADVANCED,
                'sort' => 40,
            ],
        ];

        if ($featured) {
            $fields['featured_only'] = [
                'label' => 'Featured items only',
                'type' => SectionRegistry::TYPE_BOOLEAN,
                'default' => false,
                'span' => 6,
                'tab' => SectionRegistry::TAB_ADVANCED,
                'sort' => 50,
            ];
        }

        if ($category) {
            $fields['category'] = [
                'label' => 'Only this category (slug)',
                'type' => SectionRegistry::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                'default' => null,
                'help' => 'Leave empty to show every active category.',
                'span' => 6,
                'tab' => SectionRegistry::TAB_ADVANCED,
                'sort' => 60,
            ];
        }

        if ($testimonialType) {
            $fields['type'] = [
                'label' => 'Only this kind of testimonial',
                'type' => SectionRegistry::TYPE_SELECT,
                'options' => TestimonialType::options(),
                'default' => null,
                'help' => 'Leave empty to mix clients and students.',
                'span' => 6,
                'tab' => SectionRegistry::TAB_ADVANCED,
                'sort' => 60,
            ];
        }

        return [
            'label' => $label,
            'description' => $description,
            'icon' => $icon,
            'group' => $group,
            'placements' => [
                SectionPlacement::Home->value => $homeSort,
                SectionPlacement::Page->value => $homeSort,
            ],
            'unique' => false,
            'required' => false,
            'is_live' => true,
            'provider' => $provider,
            'requirement' => 'phase-04 §8.11',
            'fields' => $fields,
            'repeaters' => [],
            'media' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function contact(): array
    {
        return [
            'label' => 'Contact form',
            'description' => 'The public inquiry form: stored in Contact inquiries and routed by type.',
            'icon' => 'envelope',
            'group' => 'engagement',
            'placements' => [
                SectionPlacement::Home->value => 130,
                SectionPlacement::Page->value => 130,
            ],
            'unique' => false,
            'required' => false,
            'is_live' => true,
            'provider' => ContactSectionProvider::class,
            'requirement' => '§17',
            'fields' => [
                'heading' => [
                    'label' => 'Heading',
                    'type' => SectionRegistry::TYPE_TEXT,
                    'default' => 'Tell us about your project',
                    'max_chars' => 120,
                    'sort' => 10,
                ],
                'description' => [
                    'label' => 'Short introduction',
                    'type' => SectionRegistry::TYPE_TEXTAREA,
                    'default' => null,
                    'max_chars' => 300,
                    'sort' => 20,
                ],
                'submit_label' => [
                    'label' => 'Button label',
                    'type' => SectionRegistry::TYPE_TEXT,
                    'default' => 'Send message',
                    'max_chars' => 40,
                    'sort' => 30,
                ],
            ],
            'repeaters' => [],
            'media' => [],
        ];
    }
}
