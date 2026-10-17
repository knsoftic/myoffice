<?php

declare(strict_types=1);

namespace App\Support\Cms\Sections;

use App\Enums\Cms\ButtonStyle;
use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\SectionPlacement;
use App\Support\Cms\SectionRegistry;

/**
 * Five section types for a product-style landing page, declared into the section registry the same way
 * `MarketingSectionTypes` is (phase-03 §6.1 "Section types declared by later phases"): pure arrays,
 * registered from `AppServiceProvider::register()`, one Blade partial each under `site/sections/`.
 *
 *   solutions   — the two halves of the business side by side as tabs (software house / training
 *                 institute), each with its own feature cards;
 *   pricing     — plans with a monthly / yearly switch, one highlighted plan, a checklist per plan;
 *   signup_cta  — a closing banner with an email box that hands the address to the contact form;
 *   leadership  — the chief executive: photo, name, designation, a message and up to four highlights;
 *   office      — the office: a photo gallery with a lightbox, beside the address, hours and contact
 *                 details read from settings, with a directions link.
 *
 * Every word on them is content: headings, tab names, plan names, prices, features and button labels are
 * fields an editor changes in /admin/website, so nothing here is website text in code (CLAUDE.md §1.8).
 * Prices are plain text fields rather than numbers, because "Custom", "Free" and "Rs 4,999" are all
 * prices an editor may need to type.
 *
 * `leadership` and `office` also feed the home page's Organization JSON-LD (`OrganizationSchema`): the
 * chief executive becomes a `Person` that works for the company, and the office photos become the
 * company's images, so search engines read the same facts a visitor sees.
 */
final class LandingSectionTypes
{
    /** The two personas a `solutions` feature card can belong to. */
    public const PERSONAS = [
        'software_house' => 'Software house',
        'institute' => 'Training institute',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'solutions' => self::solutions(),
            'pricing' => self::pricing(),
            'signup_cta' => self::signupCta(),
            'leadership' => self::leadership(),
            'office' => self::office(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function solutions(): array
    {
        return [
            'label' => 'Solutions by audience',
            'description' => 'Two tabs of feature cards — one for the software house, one for the training institute.',
            'icon' => 'squares-2x2',
            'group' => 'content',
            'placements' => [
                SectionPlacement::Home->value => 15,
                SectionPlacement::Page->value => 15,
            ],
            'unique' => false,
            'required' => false,
            'requirement' => 'landing page',
            'fields' => [
                'eyebrow' => self::text('Small label above the heading', 10, null, 60, 6),
                'heading' => self::text('Heading', 20, 'Everything both sides of the business run on', 120),
                'description' => self::textarea('Short introduction', 30, null, 300),
                'software_house_label' => self::text('First tab name', 40, self::PERSONAS['software_house'], 40, 6),
                'institute_label' => self::text('Second tab name', 50, self::PERSONAS['institute'], 40, 6),
                'software_house_intro' => self::textarea('First tab introduction', 60, null, 240),
                'institute_intro' => self::textarea('Second tab introduction', 70, null, 240),
            ],
            'repeaters' => [
                'feature' => [
                    'label' => 'Feature cards',
                    'item_label' => 'feature',
                    'min' => 0,
                    'max' => 16,
                    'item_label_field' => 'title',
                    'fields' => [
                        'persona' => [
                            'label' => 'Tab',
                            'type' => SectionRegistry::TYPE_SELECT,
                            'options' => self::PERSONAS,
                            'default' => 'software_house',
                            'required' => true,
                            'span' => 4,
                            'sort' => 10,
                        ],
                        'icon' => [
                            'label' => 'Icon',
                            'type' => SectionRegistry::TYPE_ICON,
                            'default' => null,
                            'span' => 4,
                            'sort' => 20,
                        ],
                        'title' => [
                            'label' => 'Title',
                            'type' => SectionRegistry::TYPE_TEXT,
                            'required' => true,
                            'default' => null,
                            'max_chars' => 80,
                            'span' => 12,
                            'sort' => 30,
                        ],
                        'text' => [
                            'label' => 'Text',
                            'type' => SectionRegistry::TYPE_TEXTAREA,
                            'default' => null,
                            'max_chars' => 240,
                            'span' => 12,
                            'sort' => 40,
                        ],
                    ],
                ],
            ],
            'media' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function pricing(): array
    {
        return [
            'label' => 'Pricing plans',
            'description' => 'Plans side by side with a monthly / yearly switch and one highlighted plan.',
            'icon' => 'banknotes',
            'group' => 'business',
            'placements' => [
                SectionPlacement::Home->value => 45,
                SectionPlacement::Page->value => 45,
            ],
            'unique' => false,
            'required' => false,
            'requirement' => 'landing page',
            'fields' => [
                'eyebrow' => self::text('Small label above the heading', 10, null, 60, 6),
                'heading' => self::text('Heading', 20, 'Simple pricing that grows with you', 120),
                'description' => self::textarea('Short introduction', 30, null, 300),
                'billing_toggle' => [
                    'label' => 'Show the monthly / yearly switch',
                    'type' => SectionRegistry::TYPE_BOOLEAN,
                    'default' => true,
                    'span' => 6,
                    'sort' => 40,
                ],
                'monthly_label' => self::text('Monthly switch label', 50, 'Monthly', 24, 6),
                'yearly_label' => self::text('Yearly switch label', 60, 'Yearly', 24, 6),
                'yearly_badge' => self::text('Badge beside the yearly switch', 70, null, 24, 6, 'e.g. Save 20%. Leave empty for no badge.'),
                'period_label' => self::text('Text after a price', 80, '/mo', 16, 6, 'Shown after every number price, e.g. /mo or per month.'),
                'yearly_note' => self::text('Note under a yearly price', 90, 'billed yearly', 40, 6),
                'footnote' => self::textarea('Note under the plans', 100, null, 300),
            ],
            'repeaters' => [
                'plan' => [
                    'label' => 'Plans',
                    'item_label' => 'plan',
                    'min' => 0,
                    'max' => 4,
                    'item_label_field' => 'name',
                    'fields' => [
                        'name' => [
                            'label' => 'Plan name',
                            'type' => SectionRegistry::TYPE_TEXT,
                            'required' => true,
                            'default' => null,
                            'max_chars' => 40,
                            'span' => 6,
                            'sort' => 10,
                        ],
                        'badge' => [
                            'label' => 'Badge',
                            'type' => SectionRegistry::TYPE_TEXT,
                            'default' => null,
                            'max_chars' => 24,
                            'span' => 6,
                            'help' => 'e.g. Most popular.',
                            'sort' => 20,
                        ],
                        'tagline' => [
                            'label' => 'Who it is for',
                            'type' => SectionRegistry::TYPE_TEXTAREA,
                            'default' => null,
                            'max_chars' => 160,
                            'sort' => 30,
                        ],
                        'monthly_price' => [
                            'label' => 'Monthly price',
                            'type' => SectionRegistry::TYPE_TEXT,
                            'default' => null,
                            'max_chars' => 20,
                            'span' => 4,
                            'help' => 'Typed as shown, e.g. $29 or Custom.',
                            'sort' => 40,
                        ],
                        'yearly_price' => [
                            'label' => 'Monthly price when paid yearly',
                            'type' => SectionRegistry::TYPE_TEXT,
                            'default' => null,
                            'max_chars' => 20,
                            'span' => 4,
                            'help' => 'Leave empty to show the monthly price either way.',
                            'sort' => 50,
                        ],
                        'highlighted' => [
                            'label' => 'Highlight this plan',
                            'type' => SectionRegistry::TYPE_BOOLEAN,
                            'default' => false,
                            'span' => 4,
                            'sort' => 60,
                        ],
                        'features' => [
                            'label' => 'What is included',
                            'type' => SectionRegistry::TYPE_TEXTAREA,
                            'default' => null,
                            'max_chars' => 1200,
                            'help' => 'One feature per line.',
                            'sort' => 70,
                        ],
                        'button' => [
                            'label' => 'Button',
                            'type' => SectionRegistry::TYPE_LINK,
                            'default' => [
                                'label' => 'Get started',
                                'url' => null,
                                'style' => ButtonStyle::Primary->value,
                                'new_tab' => false,
                            ],
                            'sort' => 80,
                        ],
                    ],
                ],
            ],
            'media' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function signupCta(): array
    {
        return [
            'label' => 'Sign-up banner',
            'description' => 'A closing banner with an email box; the address carries over to the contact form.',
            'icon' => 'envelope',
            'group' => 'engagement',
            'placements' => [
                SectionPlacement::Home->value => 140,
                SectionPlacement::Page->value => 140,
            ],
            'unique' => false,
            'required' => false,
            'requirement' => 'landing page',
            'fields' => [
                'eyebrow' => self::text('Small label above the heading', 10, null, 60, 6),
                'heading' => self::text('Heading', 20, 'Ready to get started?', 120),
                'description' => self::textarea('Short introduction', 30, null, 300),
                'email_placeholder' => self::text('Email box placeholder', 40, 'you@company.com', 60, 6),
                'button_label' => self::text('Button label', 50, 'Get started', 40, 6),
                'note' => self::text('Small print under the form', 60, null, 160),
                'secondary_button' => [
                    'label' => 'Second button',
                    'type' => SectionRegistry::TYPE_LINK,
                    'default' => [
                        'label' => null,
                        'url' => null,
                        'style' => ButtonStyle::Ghost->value,
                        'new_tab' => false,
                    ],
                    'help' => 'Optional, e.g. Book a demo.',
                    'sort' => 70,
                ],
            ],
            'repeaters' => [],
            'media' => [],
        ];
    }

    /**
     * The chief executive. The name is the one required field: without it the section has nobody to
     * introduce, so it cannot be published.
     *
     * @return array<string, mixed>
     */
    private static function leadership(): array
    {
        return [
            'label' => 'CEO message',
            'description' => 'The chief executive: photo, name, designation, a short message and up to four highlights.',
            'icon' => 'user-circle',
            'group' => 'content',
            'placements' => [
                SectionPlacement::Home->value => 28,
                SectionPlacement::Page->value => 28,
            ],
            'unique' => false,
            'required' => false,
            'requirement' => 'landing page',
            'fields' => [
                'eyebrow' => self::text('Small label above the heading', 10, 'Leadership', 60, 6),
                'heading' => self::text('Heading', 20, 'A word from our CEO', 120),
                'name' => [
                    'label' => 'Name',
                    'type' => SectionRegistry::TYPE_TEXT,
                    'required' => true,
                    'default' => null,
                    'max_chars' => 80,
                    'span' => 6,
                    'sort' => 30,
                ],
                'designation' => self::text('Designation', 40, 'Chief Executive Officer', 80, 6),
                'quote' => self::text('Pull quote', 50, null, 200, 12, 'One line shown large above the message. Optional.'),
                'message' => [
                    'label' => 'Message',
                    'type' => SectionRegistry::TYPE_TEXTAREA,
                    'default' => null,
                    'max_chars' => 2000,
                    'help' => 'Leave an empty line between paragraphs.',
                    'sort' => 60,
                ],
                'linkedin_url' => [
                    'label' => 'LinkedIn profile',
                    'type' => SectionRegistry::TYPE_URL,
                    'default' => null,
                    'span' => 6,
                    'help' => 'Optional. Also tells search engines which profile is this person.',
                    'sort' => 70,
                ],
                'is_founder' => [
                    'label' => 'Also the founder',
                    'type' => SectionRegistry::TYPE_BOOLEAN,
                    'default' => false,
                    'span' => 6,
                    'help' => 'Search engines are told this person founded the company.',
                    'sort' => 80,
                ],
            ],
            'repeaters' => [
                'fact' => [
                    'label' => 'Highlights',
                    'item_label' => 'highlight',
                    'min' => 0,
                    'max' => 4,
                    'item_label_field' => 'label',
                    'fields' => [
                        'value' => [
                            'label' => 'Value',
                            'type' => SectionRegistry::TYPE_TEXT,
                            'required' => true,
                            'default' => null,
                            'max_chars' => 20,
                            'span' => 4,
                            'help' => 'e.g. 10+ years.',
                            'sort' => 10,
                        ],
                        'label' => [
                            'label' => 'Label',
                            'type' => SectionRegistry::TYPE_TEXT,
                            'required' => true,
                            'default' => null,
                            'max_chars' => 60,
                            'span' => 8,
                            'help' => 'e.g. in software development.',
                            'sort' => 20,
                        ],
                    ],
                ],
            ],
            'media' => [
                // Hero keeps the photo's own shape; a 16:9 card crop would cut a portrait at the chin.
                'photo' => [
                    'label' => 'CEO photo',
                    'profile' => ImageProfile::Hero,
                    'help' => 'A portrait works best. Publishing needs alt text on the photo (media library), e.g. the person\'s name.',
                ],
            ],
        ];
    }

    /**
     * The office: photos, and the address, hours and contact details already kept in settings — typed
     * once in Settings › Contact and shown here, in the footer and in the JSON-LD alike.
     *
     * @return array<string, mixed>
     */
    private static function office(): array
    {
        return [
            'label' => 'Office & gallery',
            'description' => 'Office photos in a gallery with a lightbox, beside the address, hours and contact details from settings.',
            'icon' => 'building-office-2',
            'group' => 'content',
            'placements' => [
                SectionPlacement::Home->value => 110,
                SectionPlacement::Page->value => 110,
            ],
            'unique' => false,
            'required' => false,
            'requirement' => 'landing page',
            'fields' => [
                'eyebrow' => self::text('Small label above the heading', 10, 'Our office', 60, 6),
                'heading' => self::text('Heading', 20, 'Come and see where the work happens', 120),
                'description' => self::textarea('Short introduction', 30, null, 300),
                'show_details' => [
                    'label' => 'Show the address, hours and contact details',
                    'type' => SectionRegistry::TYPE_BOOLEAN,
                    'default' => true,
                    'span' => 6,
                    'help' => 'Read from Settings › Contact.',
                    'sort' => 40,
                ],
                'directions_label' => self::text('Directions button label', 50, 'Get directions', 40, 6, 'Leave empty for no button.'),
            ],
            'repeaters' => [
                'photo' => [
                    'label' => 'Photos',
                    'item_label' => 'photo',
                    'min' => 0,
                    'max' => 24,
                    'item_label_field' => 'caption',
                    'fields' => [
                        'image' => [
                            'label' => 'Photo',
                            'type' => SectionRegistry::TYPE_IMAGE,
                            'column' => 'media_asset_id',
                            // Uncropped, so the lightbox shows the whole photo; the grid crops with CSS.
                            'profile' => ImageProfile::Hero,
                            'required' => true,
                            'default' => null,
                            'span' => 6,
                            'help' => 'Publishing needs alt text on the photo (media library).',
                            'sort' => 10,
                        ],
                        'caption' => [
                            'label' => 'Caption',
                            'type' => SectionRegistry::TYPE_TEXT,
                            'default' => null,
                            'max_chars' => 120,
                            'span' => 6,
                            'help' => 'Shown over the photo and in the lightbox, e.g. The training lab.',
                            'sort' => 20,
                        ],
                    ],
                ],
            ],
            'media' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(string $label, int $sort, ?string $default, int $max, int $span = 12, ?string $help = null): array
    {
        return array_filter([
            'label' => $label,
            'type' => SectionRegistry::TYPE_TEXT,
            'default' => $default,
            'max_chars' => $max,
            'span' => $span,
            'help' => $help,
            'sort' => $sort,
        ], static fn ($value, $key): bool => $key === 'default' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array<string, mixed>
     */
    private static function textarea(string $label, int $sort, ?string $default, int $max): array
    {
        return [
            'label' => $label,
            'type' => SectionRegistry::TYPE_TEXTAREA,
            'default' => $default,
            'max_chars' => $max,
            'sort' => $sort,
        ];
    }
}
