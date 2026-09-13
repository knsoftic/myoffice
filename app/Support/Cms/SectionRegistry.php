<?php

declare(strict_types=1);

namespace App\Support\Cms;

use App\Enums\Cms\ButtonStyle;
use App\Enums\Cms\FaqSource;
use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\SectionPlacement;
use App\Enums\Cms\StatisticMetric;
use App\Enums\Cms\StatisticValueMode;
use InvalidArgumentException;

/**
 * The section-type registry (phase-03 §6.1) — section **types in code**, section **content in the
 * database**.
 *
 * The third instance of the Registry pattern, identical in spirit to `App\Support\PermissionRegistry`
 * (phase-01 §4) and `App\Support\SettingsRegistry` (phase-02 §2): **pure arrays, no database, no
 * facades, no container**. One declaration drives the seeder, the "add section" modal, the editor
 * form, the server-side validation, the published snapshot and the public partial — which is what
 * makes requirement §7's promise ("enable, disable, reorder, edit every heading, image, icon, button
 * and URL from the admin panel") true without a code change per section.
 *
 * **No enum exists for `section_key`** ([D-W3-9]): a later phase adds a type by adding one entry
 * here (or calling `register()` from its own service provider) plus one Blade partial under
 * `resources/views/site/sections/`. It adds no table, no permission and no route. A key that is not
 * declared here never renders and never 500s — the renderer skips it and the admin list flags it as
 * orphaned (INV-2), which is why `exists()` is the renderer's first call.
 *
 * ---------------------------------------------------------------------------------------------
 * Naming
 * ---------------------------------------------------------------------------------------------
 *
 * phase-03 §6.1 calls this class `App\Support\WebsiteSectionRegistry`. It is declared here, under
 * `App\Support\Cms`, with the rest of the CMS value objects; the class is intentionally **not
 * final** so the contract name can be carried by a one-line subclass if the integrator prefers it
 * (see `docs-pending/phase-03-handover.md`).
 *
 * ---------------------------------------------------------------------------------------------
 * Type definition
 * ---------------------------------------------------------------------------------------------
 *
 * | key            | meaning                                                                      |
 * |----------------|------------------------------------------------------------------------------|
 * | `key`          | the `website_sections.section_key` value                                     |
 * | `label`        | admin-facing name; `website_sections.name` overrides it per instance          |
 * | `description`  | the one-line description in the add-section modal (§8.4)                     |
 * | `icon`         | an allowlisted Heroicons name (`resources/data/icons.php`, §6.6)             |
 * | `group`        | the add-section modal's grouping (see `groups()`)                            |
 * | `placements`   | placement value => default `sort_order` in that placement                    |
 * | `unique`       | one instance per placement, enforced by `UNIQUE uq_ws_instance` on `instance_key` |
 * | `required`     | may be disabled, never deleted (INV-7)                                       |
 * | `is_live`      | its provider is re-resolved at render time instead of being frozen at publish |
 * | `provider`     | a `SectionDataProvider` class, or null                                        |
 * | `view`         | the public Blade partial (`site.sections.{key}` by default)                   |
 * | `edit_view`    | an optional custom editor panel; null = the generic field loop of §8.5        |
 * | `fields`       | key => field definition                                                       |
 * | `repeaters`    | group => repeater definition (`website_section_items`, §2.3)                  |
 * | `media`        | role => media slot definition (`website_section_media`, §2.4)                 |
 * | `requirement`  | the requirement section this type implements, for the admin help text         |
 *
 * ---------------------------------------------------------------------------------------------
 * Field definition
 * ---------------------------------------------------------------------------------------------
 *
 * The vocabulary of phase-02's `SettingsRegistry`, extended as §6.1 allows: `label`, `type`,
 * `rules`, `default`, `options`, `help`, `placeholder`, `span` (1-12), `sort`, `required`,
 * `max_chars` (drives the editor's character counter), plus `readonly` and `suffix` from phase-02
 * and three keys this phase needs:
 *
 *   · `tab`    — which editor tab the field belongs to (§8.5 groups a type with more than eight
 *                fields into Content / Media / Buttons / Advanced; nothing else can supply that).
 *   · `column` — the **real column** a reference field writes, or null. `INV-3: JSON never holds a
 *                foreign key`, so `cta_ref` writes `website_sections.cta_block_id` and the header's
 *                `menu_ref` writes `menu_id`; `WebsiteSectionService::saveDraft()` moves any field
 *                with a `column` out of the `content` payload and onto that column.
 *   · `ref_by` — how a reference field points at its target: `id` (a FK column), `location` (a
 *                `MenuLocation` value — lossless because `UNIQUE uq_menus_location` allows one menu
 *                per slot, and delete-safe because a missing menu renders an empty slot) or `slug`.
 *
 * `stored` is derived, never declared: `column` for a field with a column, `media` for an
 * `image`/`video` field, `content` for everything else. Only `content` fields appear in
 * `defaults()`, and `saveDraft()` rejects any `content` key that is not one of them.
 *
 * Rules are intentionally **single-field**: no `required_if` across keys, because a Form Request may
 * nest this payload under a prefix the registry does not know. The two cross-field rules this phase
 * needs live in the service, as §6.2 specifies — "requires `metric` when `value_mode = auto`" and
 * "a `background_video` requires a `video_poster`".
 *
 * One rule this registry deliberately does **not** carry: the `in:` list of an `icon` field. The
 * Heroicons allowlist lives in `resources/data/icons.php` (§6.6) and is read by the Form Request,
 * which merges `Rule::in(...)` onto `content.{field}`; a registry that loaded a file would stop
 * being pure arrays. Every icon name this registry itself uses is listed by `icons()`, so a test
 * can assert the allowlist covers them.
 */
class SectionRegistry
{
    /*
    |--------------------------------------------------------------------------
    | Field types (§6.1)
    |--------------------------------------------------------------------------
    */

    public const TYPE_TEXT = 'text';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_RICHTEXT = 'richtext';

    public const TYPE_URL = 'url';

    public const TYPE_EMAIL = 'email';

    public const TYPE_TEL = 'tel';

    public const TYPE_NUMBER = 'number';

    public const TYPE_DECIMAL = 'decimal';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_SELECT = 'select';

    public const TYPE_MULTISELECT = 'multiselect';

    public const TYPE_COLOR = 'color';

    public const TYPE_ICON = 'icon';

    public const TYPE_LINK = 'link';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const TYPE_CTA_REF = 'cta_ref';

    public const TYPE_MENU_REF = 'menu_ref';

    public const TYPE_FAQ_CATEGORY_REF = 'faq_category_ref';

    public const TYPE_PAGE_REF = 'page_ref';

    /**
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_TEXTAREA,
        self::TYPE_RICHTEXT,
        self::TYPE_URL,
        self::TYPE_EMAIL,
        self::TYPE_TEL,
        self::TYPE_NUMBER,
        self::TYPE_DECIMAL,
        self::TYPE_BOOLEAN,
        self::TYPE_SELECT,
        self::TYPE_MULTISELECT,
        self::TYPE_COLOR,
        self::TYPE_ICON,
        self::TYPE_LINK,
        self::TYPE_IMAGE,
        self::TYPE_VIDEO,
        self::TYPE_CTA_REF,
        self::TYPE_MENU_REF,
        self::TYPE_FAQ_CATEGORY_REF,
        self::TYPE_PAGE_REF,
    ];

    /**
     * Fields that resolve to media rather than to a `content` key.
     *
     * @var list<string>
     */
    public const MEDIA_TYPES = [self::TYPE_IMAGE, self::TYPE_VIDEO];

    /**
     * Fields that point at another row.
     *
     * @var list<string>
     */
    public const REF_TYPES = [
        self::TYPE_CTA_REF,
        self::TYPE_MENU_REF,
        self::TYPE_FAQ_CATEGORY_REF,
        self::TYPE_PAGE_REF,
    ];

    public const STORED_CONTENT = 'content';

    public const STORED_COLUMN = 'column';

    public const STORED_MEDIA = 'media';

    public const REF_BY_ID = 'id';

    public const REF_BY_LOCATION = 'location';

    public const REF_BY_SLUG = 'slug';

    /*
    |--------------------------------------------------------------------------
    | Editor tabs (§8.5)
    |--------------------------------------------------------------------------
    */

    public const TAB_CONTENT = 'content';

    public const TAB_MEDIA = 'media';

    public const TAB_BUTTONS = 'buttons';

    public const TAB_ADVANCED = 'advanced';

    /**
     * @var array<string, string>
     */
    public const TABS = [
        self::TAB_CONTENT => 'Content',
        self::TAB_MEDIA => 'Media',
        self::TAB_BUTTONS => 'Buttons',
        self::TAB_ADVANCED => 'Advanced',
    ];

    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    /**
     * Section fields are full width unless they say otherwise.
     */
    public const DEFAULT_SPAN = 12;

    /**
     * The link-href scheme allowlist of §6.6, as a Laravel rule.
     *
     * `javascript:`, `data:` and `vbscript:` are rejected here **and** by `RichText::sanitize()`
     * (INV-13). Rules are declared as arrays, never as a `|`-joined string, so the alternation
     * inside this pattern is safe.
     */
    public const LINK_URL_RULE = 'regex:/^(https?:\/\/|mailto:|tel:|\/|#)/i';

    /**
     * The widest `manual_value` a `decimal(15,2)` column holds.
     */
    public const MAX_STATISTIC_VALUE = '9999999999999.99';

    /**
     * Types added at runtime by a later phase's service provider.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $registered = [];

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $normalised = null;

    /*
    |--------------------------------------------------------------------------
    | Placements and groups
    |--------------------------------------------------------------------------
    */

    /**
     * The placement rail of §8.4: placement value => label, plural label, ordering behaviour.
     *
     * `allows_custom_order` is false for the two global slots, which hold one section each and have
     * nothing to order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function placements(): array
    {
        $placements = [
            SectionPlacement::Home->value => [
                'label' => 'Home page',
                'label_plural' => 'Home page sections',
                'allows_custom_order' => true,
            ],
            SectionPlacement::GlobalHeader->value => [
                'label' => 'Header',
                'label_plural' => 'Header',
                'allows_custom_order' => false,
            ],
            SectionPlacement::GlobalFooter->value => [
                'label' => 'Footer',
                'label_plural' => 'Footer',
                'allows_custom_order' => false,
            ],
            SectionPlacement::Page->value => [
                'label' => 'Custom page',
                'label_plural' => 'Page sections',
                'allows_custom_order' => true,
            ],
        ];

        $resolved = [];

        foreach ($placements as $value => $placement) {
            $resolved[$value] = [
                'value' => $value,
                'placement' => SectionPlacement::from($value),
                'label' => $placement['label'],
                'label_plural' => $placement['label_plural'],
                'allows_custom_order' => $placement['allows_custom_order'],
            ];
        }

        return $resolved;
    }

    /**
     * The add-section modal's grouping: group key => label, sort.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function groups(): array
    {
        return [
            'layout' => ['label' => 'Site chrome', 'sort' => 10],
            'content' => ['label' => 'Content', 'sort' => 20],
            'engagement' => ['label' => 'Engagement', 'sort' => 30],
            'business' => ['label' => 'Business data', 'sort' => 40],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Types
    |--------------------------------------------------------------------------
    */

    /**
     * Every declared type, keyed by `section_key`, fully normalised.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function types(): array
    {
        if (self::$normalised !== null) {
            return self::$normalised;
        }

        $normalised = [];

        foreach (array_merge(self::declarations(), self::$registered) as $key => $type) {
            $normalised[(string) $key] = self::normaliseType((string) $key, $type);
        }

        return self::$normalised = $normalised;
    }

    /**
     * One type, or null when the key is not declared. The renderer's guard (INV-2).
     *
     * @return array<string, mixed>|null
     */
    public static function type(string $key): ?array
    {
        return self::types()[$key] ?? null;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::types());
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::types());
    }

    /**
     * The types allowed in one placement, in default sort order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forPlacement(SectionPlacement $placement): array
    {
        $types = [];

        foreach (self::types() as $key => $type) {
            if (! array_key_exists($placement->value, $type['placements'])) {
                continue;
            }

            $types[$key] = $type;
        }

        uasort(
            $types,
            static fn (array $a, array $b): int => [$a['placements'][$placement->value], $a['label']]
                <=> [$b['placements'][$placement->value], $b['label']]
        );

        return $types;
    }

    public static function allowedIn(string $key, SectionPlacement $placement): bool
    {
        return array_key_exists($placement->value, self::definition($key)['placements']);
    }

    /**
     * The `sort_order` a freshly placed section starts at in this placement.
     *
     * `WebsiteSectionService::place()` appends with `max + 10` instead; this value is what the
     * seeder of §6.14 uses so the shipped home page reads in the intended order.
     */
    public static function defaultSort(string $key, SectionPlacement $placement): int
    {
        return (int) (self::definition($key)['placements'][$placement->value] ?? 0);
    }

    /**
     * The `instance_key` of §2.2 — `"{placement}|{page_id or 0}|{section_key}"` for a unique type,
     * **null** for a repeatable one, so `UNIQUE uq_ws_instance` constrains exactly the right rows
     * and the INSERT decides, never a SELECT-then-INSERT.
     */
    public static function instanceKey(string $key, SectionPlacement $placement, ?int $pageId = null): ?string
    {
        if (! self::isUnique($key)) {
            return null;
        }

        return $placement->value.'|'.($pageId ?? 0).'|'.$key;
    }

    public static function isUnique(string $key): bool
    {
        return (bool) self::definition($key)['unique'];
    }

    /**
     * Disable-only, never deletable (INV-7): `header`, `hero`, `footer`.
     */
    public static function isRequired(string $key): bool
    {
        return (bool) self::definition($key)['required'];
    }

    /**
     * Is this type's provider re-resolved at render time instead of frozen into the published
     * snapshot (§6.1)? An `is_live` provider must cache itself.
     */
    public static function isLive(string $key): bool
    {
        return (bool) self::definition($key)['is_live'];
    }

    /**
     * The keys that may be disabled but never deleted.
     *
     * @return list<string>
     */
    public static function requiredKeys(): array
    {
        return array_values(array_keys(array_filter(
            self::types(),
            static fn (array $type): bool => (bool) $type['required']
        )));
    }

    public static function view(string $key): string
    {
        return (string) self::definition($key)['view'];
    }

    public static function editView(string $key): ?string
    {
        $view = self::definition($key)['edit_view'];

        return $view === null ? null : (string) $view;
    }

    public static function provider(string $key): ?string
    {
        $provider = self::definition($key)['provider'];

        return $provider === null ? null : (string) $provider;
    }

    public static function icon(string $key): string
    {
        return (string) self::definition($key)['icon'];
    }

    public static function label(string $key): string
    {
        return (string) self::definition($key)['label'];
    }

    public static function description(string $key): string
    {
        return (string) self::definition($key)['description'];
    }

    public static function group(string $key): string
    {
        return (string) self::definition($key)['group'];
    }

    /**
     * Every public partial this registry expects to exist, keyed by section key. A test asserts the
     * files are there, so an orphaned type is caught before a visitor finds it.
     *
     * @return array<string, string>
     */
    public static function views(): array
    {
        $views = [];

        foreach (self::types() as $key => $type) {
            $views[$key] = (string) $type['view'];
        }

        return $views;
    }

    /**
     * Every icon name the registry uses, so the allowlist in `resources/data/icons.php` can be
     * verified against it instead of by eye.
     *
     * @return list<string>
     */
    public static function icons(): array
    {
        $icons = [];

        foreach (self::types() as $type) {
            $icons[] = (string) $type['icon'];
        }

        return array_values(array_unique($icons));
    }

    /*
    |--------------------------------------------------------------------------
    | Fields
    |--------------------------------------------------------------------------
    */

    /**
     * One type's fields, keyed by field key, in `sort` order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(string $key): array
    {
        /** @var array<string, array<string, mixed>> */
        return self::definition($key)['fields'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function field(string $key, string $field): ?array
    {
        return self::fields($key)[$field] ?? null;
    }

    /**
     * The field keys that live in `website_sections.content`.
     *
     * @return list<string>
     */
    public static function contentKeys(string $key): array
    {
        return array_values(array_keys(array_filter(
            self::fields($key),
            static fn (array $field): bool => $field['stored'] === self::STORED_CONTENT
        )));
    }

    /**
     * Field key => the real column it writes (INV-3).
     *
     * @return array<string, string>
     */
    public static function columns(string $key): array
    {
        $columns = [];

        foreach (self::fields($key) as $field => $definition) {
            if ($definition['stored'] === self::STORED_COLUMN) {
                $columns[$field] = (string) $definition['column'];
            }
        }

        return $columns;
    }

    /**
     * The seeded `content` of a freshly placed section: every `content` field at its default.
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $key): array
    {
        $defaults = [];

        foreach (self::fields($key) as $field => $definition) {
            if ($definition['stored'] !== self::STORED_CONTENT) {
                continue;
            }

            $defaults[$field] = $definition['default'];
        }

        return $defaults;
    }

    /**
     * The starting value of every **column-backed** field (`cta_block_id`, `menu_id`), keyed by
     * column name. `defaults()` covers the `content` half; this covers the other half, so
     * `place()` can seed a whole row from the registry without special-casing the FKs (INV-3).
     *
     * @return array<string, mixed>
     */
    public static function columnDefaults(string $key): array
    {
        $defaults = [];

        foreach (self::fields($key) as $field) {
            if ($field['stored'] !== self::STORED_COLUMN) {
                continue;
            }

            $defaults[(string) $field['column']] = $field['default'];
        }

        return $defaults;
    }

    /**
     * Laravel rules for the draft form (§6.2 `saveDraft()`).
     *
     * Pass `$prefix` when the form nests its inputs: `rulesFor('hero', 'content')` returns
     * `'content.heading' => [...]` and `'content.primary_button.url' => [...]`.
     *
     * @return array<string, list<string>>
     */
    public static function rulesFor(string $key, ?string $prefix = null): array
    {
        return self::rulesFromFields(self::fields($key), $prefix);
    }

    /*
    |--------------------------------------------------------------------------
    | Repeaters (website_section_items, §2.3)
    |--------------------------------------------------------------------------
    */

    /**
     * One type's repeaters, keyed by `website_section_items.group`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function repeaters(string $key): array
    {
        /** @var array<string, array<string, mixed>> */
        return self::definition($key)['repeaters'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function repeater(string $key, string $group): ?array
    {
        return self::repeaters($key)[$group] ?? null;
    }

    public static function hasRepeater(string $key, string $group): bool
    {
        return array_key_exists($group, self::repeaters($key));
    }

    /**
     * One repeater's item fields, in `sort` order.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws InvalidArgumentException when the type has no such repeater
     */
    public static function itemFields(string $key, string $group): array
    {
        $repeater = self::repeater($key, $group);

        if ($repeater === null) {
            throw new InvalidArgumentException(sprintf(
                'Section type [%s] declares no repeater [%s]. Declared repeaters: %s.',
                $key,
                $group,
                implode(', ', array_keys(self::repeaters($key))) ?: 'none'
            ));
        }

        /** @var array<string, array<string, mixed>> */
        return $repeater['fields'];
    }

    /**
     * The `content` of a freshly added repeater item.
     *
     * @return array<string, mixed>
     */
    public static function itemDefaults(string $key, string $group): array
    {
        $defaults = [];

        foreach (self::itemFields($key, $group) as $field => $definition) {
            if ($definition['stored'] !== self::STORED_CONTENT) {
                continue;
            }

            $defaults[$field] = $definition['default'];
        }

        return $defaults;
    }

    /**
     * The starting value of a fresh item's **column-backed** fields, keyed by column name — so a
     * new statistic arrives as `value_mode = manual` with no metric, which is the only combination
     * CHECK `chk_wsi_value` accepts without further input.
     *
     * @return array<string, mixed>
     */
    public static function itemColumnDefaults(string $key, string $group): array
    {
        $defaults = [];

        foreach (self::itemFields($key, $group) as $field) {
            if ($field['stored'] !== self::STORED_COLUMN) {
                continue;
            }

            $defaults[(string) $field['column']] = $field['default'];
        }

        return $defaults;
    }

    /**
     * Laravel rules for one repeater item (§6.2 `upsertItem()`).
     *
     * @return array<string, list<string>>
     */
    public static function itemRulesFor(string $key, string $group, ?string $prefix = null): array
    {
        return self::rulesFromFields(self::itemFields($key, $group), $prefix);
    }

    /**
     * Item field key => the `website_section_items` column it writes (`metric`, `value_mode`,
     * `manual_value`, `media_asset_id`); everything else goes into the item's `content` JSON.
     *
     * @return array<string, string>
     */
    public static function itemColumns(string $key, string $group): array
    {
        $columns = [];

        foreach (self::itemFields($key, $group) as $field => $definition) {
            if ($definition['stored'] === self::STORED_COLUMN) {
                $columns[$field] = (string) $definition['column'];
            }
        }

        return $columns;
    }

    /*
    |--------------------------------------------------------------------------
    | Media slots (website_section_media, §2.4)
    |--------------------------------------------------------------------------
    */

    /**
     * One type's media slots: role => label, profile, multiple, required, kind.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function mediaRoles(string $key): array
    {
        /** @var array<string, array<string, mixed>> */
        return self::definition($key)['media'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function mediaRole(string $key, string $role): ?array
    {
        return self::mediaRoles($key)[$role] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | Runtime registration (later phases)
    |--------------------------------------------------------------------------
    */

    /**
     * Register a section type from outside this file — the seam that keeps phases 4, 5, 14 and 15
     * out of a shared array and therefore out of each other's merge conflicts ([D-W3-9]). Call it
     * from a service provider's `register()`.
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws InvalidArgumentException when the key is already declared
     */
    public static function register(string $key, array $definition): void
    {
        if (array_key_exists($key, self::declarations()) || array_key_exists($key, self::$registered)) {
            throw new InvalidArgumentException(sprintf('Section type [%s] is already registered.', $key));
        }

        self::$registered[$key] = $definition;
        self::$normalised = null;
    }

    /**
     * Forget every runtime registration and the normalisation cache. For tests.
     */
    public static function flush(): void
    {
        self::$registered = [];
        self::$normalised = null;
    }

    /*
    |--------------------------------------------------------------------------
    | Declarations — the types Phase 3 ships (§6.1)
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function declarations(): array
    {
        return [
            'header' => self::headerType(),
            'hero' => self::heroType(),
            'about' => self::aboutType(),
            'rich_content' => self::richContentType(),
            'faq' => self::faqType(),
            'cta' => self::ctaType(),
            'footer' => self::footerType(),
        ];
    }

    /**
     * Requirement §8: logo, company name, menu, login / contact / admission / CTA buttons.
     *
     * @return array<string, mixed>
     */
    private static function headerType(): array
    {
        return [
            'label' => 'Site header',
            'description' => 'The logo, the navigation and the four header buttons, on every page.',
            'icon' => 'bars-3',
            'group' => 'layout',
            'placements' => [SectionPlacement::GlobalHeader->value => 10],
            'unique' => true,
            'required' => true,
            'requirement' => '§8',
            'fields' => [
                'show_company_name' => [
                    'label' => 'Show the company name beside the logo',
                    'type' => self::TYPE_BOOLEAN,
                    'default' => true,
                    'span' => 6,
                    'sort' => 10,
                ],
                'company_name_override' => [
                    'label' => 'Company name override',
                    'type' => self::TYPE_TEXT,
                    'default' => null,
                    'max_chars' => 100,
                    'help' => 'Leave empty to use the company name from Settings → Company.',
                    'span' => 6,
                    'sort' => 20,
                ],
                'menu_ref' => [
                    'label' => 'Navigation menu',
                    'type' => self::TYPE_MENU_REF,
                    'column' => 'menu_id',
                    'ref_by' => self::REF_BY_ID,
                    'required' => true,
                    'help' => 'Manage its items under Website → Menus. A menu item whose target is a draft or deleted page is omitted, never rendered dead.',
                    'sort' => 30,
                ],
                'login_button_enabled' => [
                    'label' => 'Show the Login button',
                    'type' => self::TYPE_BOOLEAN,
                    'default' => true,
                    'span' => 6,
                    'tab' => self::TAB_BUTTONS,
                    'sort' => 40,
                ],
                'login_button' => self::linkField('Login button', 50, [
                    'tab' => self::TAB_BUTTONS,
                    'help' => 'Hidden from signed-in visitors: the header renders it with MenuVisibility::Guest.',
                    'default' => self::linkDefault('Login', '/login', ButtonStyle::Outline),
                ]),
                'contact_button_enabled' => [
                    'label' => 'Show the Contact button',
                    'type' => self::TYPE_BOOLEAN,
                    'default' => true,
                    'span' => 6,
                    'tab' => self::TAB_BUTTONS,
                    'sort' => 60,
                ],
                'contact_button' => self::linkField('Contact button', 70, [
                    'tab' => self::TAB_BUTTONS,
                    'default' => self::linkDefault('Contact', '#contact', ButtonStyle::Ghost),
                ]),
                'admission_button_enabled' => [
                    'label' => 'Show the Admission button',
                    'type' => self::TYPE_BOOLEAN,
                    'default' => false,
                    'span' => 6,
                    'tab' => self::TAB_BUTTONS,
                    'help' => 'Switch it on when the institute admission flow is live.',
                    'sort' => 80,
                ],
                'admission_button' => self::linkField('Admission button', 90, [
                    'tab' => self::TAB_BUTTONS,
                    'default' => self::linkDefault('Admissions', '#admission', ButtonStyle::Secondary),
                ]),
                'cta_button_enabled' => [
                    'label' => 'Show the call-to-action button',
                    'type' => self::TYPE_BOOLEAN,
                    'default' => true,
                    'span' => 6,
                    'tab' => self::TAB_BUTTONS,
                    'sort' => 100,
                ],
                'cta_button' => self::linkField('Call-to-action button', 110, [
                    'tab' => self::TAB_BUTTONS,
                    'default' => self::linkDefault('Get a quote', '#contact', ButtonStyle::Primary),
                ]),
                'sticky' => [
                    'label' => 'Stick to the top of the window on scroll',
                    'type' => self::TYPE_BOOLEAN,
                    'default' => true,
                    'span' => 6,
                    'tab' => self::TAB_ADVANCED,
                    'sort' => 120,
                ],
                'transparent_over_hero' => [
                    'label' => 'Transparent over the hero',
                    'type' => self::TYPE_BOOLEAN,
                    'default' => false,
                    'span' => 6,
                    'tab' => self::TAB_ADVANCED,
                    'help' => 'The header sits over the hero image until the visitor scrolls, then turns opaque.',
                    'sort' => 130,
                ],
            ],
            'repeaters' => [
                'link' => [
                    'label' => 'Top bar links',
                    'item_label' => 'link',
                    'min' => 0,
                    'max' => 3,
                    'item_label_field' => 'label',
                    'help' => 'A thin bar above the navigation — a phone number, a WhatsApp link, a portal shortcut.',
                    'fields' => self::linkItemFields(),
                ],
            ],
            'media' => [
                'logo_override_light' => [
                    'label' => 'Logo (light background)',
                    'profile' => ImageProfile::Logo,
                    'help' => 'Optional. Falls back to Settings → Branding → Logo (light).',
                ],
                'logo_override_dark' => [
                    'label' => 'Logo (dark background)',
                    'profile' => ImageProfile::Logo,
                    'help' => 'Optional. Falls back to Settings → Branding → Logo (dark).',
                ],
            ],
        ];
    }

    /**
     * Requirement §9: heading, subtitle, description, images, video, two buttons, six statistics.
     *
     * @return array<string, mixed>
     */
    private static function heroType(): array
    {
        return [
            'label' => 'Hero',
            'description' => 'The first screen: heading, subtitle, buttons, background image or video, and the statistics strip.',
            'icon' => 'sparkles',
            'group' => 'content',
            'placements' => [SectionPlacement::Home->value => 10],
            'unique' => true,
            'required' => true,
            'requirement' => '§9',
            'fields' => [
                'heading' => [
                    'label' => 'Heading',
                    'type' => self::TYPE_TEXT,
                    'required' => true,
                    'default' => null,
                    'max_chars' => 120,
                    'sort' => 10,
                ],
                'subtitle' => [
                    'label' => 'Subtitle',
                    'type' => self::TYPE_TEXT,
                    'default' => null,
                    'max_chars' => 180,
                    'sort' => 20,
                ],
                'description' => [
                    'label' => 'Description',
                    'type' => self::TYPE_RICHTEXT,
                    'default' => null,
                    'max_chars' => 600,
                    'sort' => 30,
                ],
                'primary_button' => self::linkField('Primary button', 40, [
                    'tab' => self::TAB_BUTTONS,
                    'default' => self::linkDefault('Get started', '#contact', ButtonStyle::Primary),
                ]),
                'secondary_button' => self::linkField('Secondary button', 50, [
                    'tab' => self::TAB_BUTTONS,
                    'default' => self::linkDefault('Explore courses', '#courses', ButtonStyle::Outline),
                ]),
                'show_statistics' => [
                    'label' => 'Show the statistics strip',
                    'type' => self::TYPE_BOOLEAN,
                    'default' => true,
                    'span' => 6,
                    'sort' => 60,
                ],
                'alignment' => [
                    'label' => 'Text alignment',
                    'type' => self::TYPE_SELECT,
                    'options' => ['left' => 'Left', 'center' => 'Centred'],
                    'default' => 'center',
                    'span' => 6,
                    'tab' => self::TAB_ADVANCED,
                    'sort' => 70,
                ],
                'overlay_opacity' => [
                    'label' => 'Background overlay',
                    'type' => self::TYPE_NUMBER,
                    'rules' => ['integer', 'min:0', 'max:80'],
                    'default' => 40,
                    'suffix' => '%',
                    'span' => 6,
                    'tab' => self::TAB_ADVANCED,
                    'help' => 'Darkens the background so the heading stays readable. 0 leaves the image untouched.',
                    'sort' => 80,
                ],
                'video_autoplay' => [
                    'label' => 'Autoplay the background video',
                    'type' => self::TYPE_BOOLEAN,
                    'default' => true,
                    'readonly' => true,
                    'span' => 6,
                    'tab' => self::TAB_ADVANCED,
                    'help' => 'A background video always plays muted and looped — browsers block anything else. Switch video off globally under Settings → Website & Forms.',
                    'sort' => 90,
                ],
            ],
            'repeaters' => [
                'statistic' => self::statisticRepeater(
                    'Statistics',
                    'Requirement §9 names six: projects completed, happy clients, students trained, active courses, team members, years of experience.'
                ),
            ],
            'media' => [
                'hero_image' => [
                    'label' => 'Hero image',
                    'profile' => ImageProfile::Hero,
                    'help' => 'The illustration beside the heading. Rendered eagerly with fetchpriority="high".',
                ],
                'background_image' => [
                    'label' => 'Background image',
                    'profile' => ImageProfile::Hero,
                    'help' => 'Leave every background empty and the hero renders its brand gradient.',
                ],
                'background_video' => [
                    'label' => 'Background video',
                    'kind' => self::KIND_VIDEO,
                    'profile' => null,
                    'help' => 'MP4 or WebM. A video needs a poster image below; the service refuses to publish one without it.',
                ],
                'video_poster' => [
                    'label' => 'Video poster',
                    'profile' => ImageProfile::VideoPoster,
                    'help' => 'Shown before the video plays, and instead of it when background video is switched off.',
                ],
            ],
        ];
    }

    /**
     * Requirement §10: company, software-house and institute introductions, mission, vision,
     * history, why choose us, images, statistics.
     *
     * @return array<string, mixed>
     */
    private static function aboutType(): array
    {
        return [
            'label' => 'About',
            'description' => 'Who the company is: the three introductions, mission and vision, history, why choose us.',
            'icon' => 'information-circle',
            'group' => 'content',
            'placements' => [
                SectionPlacement::Home->value => 20,
                SectionPlacement::Page->value => 20,
            ],
            'unique' => true,
            'required' => false,
            'requirement' => '§10',
            'fields' => [
                'heading' => [
                    'label' => 'Heading',
                    'type' => self::TYPE_TEXT,
                    'required' => true,
                    'default' => 'About us',
                    'max_chars' => 120,
                    'sort' => 10,
                ],
                'tabs_enabled' => [
                    'label' => 'Render the three introductions as tabs',
                    'type' => self::TYPE_BOOLEAN,
                    'default' => true,
                    'span' => 6,
                    'help' => 'Off stacks them one under another.',
                    'sort' => 20,
                ],
                'company_intro' => [
                    'label' => 'Company introduction',
                    'type' => self::TYPE_RICHTEXT,
                    'default' => null,
                    'max_chars' => 1500,
                    'sort' => 30,
                ],
                'software_house_intro' => [
                    'label' => 'Software house introduction',
                    'type' => self::TYPE_RICHTEXT,
                    'default' => null,
                    'max_chars' => 1500,
                    'sort' => 40,
                ],
                'institute_intro' => [
                    'label' => 'Institute introduction',
                    'type' => self::TYPE_RICHTEXT,
                    'default' => null,
                    'max_chars' => 1500,
                    'sort' => 50,
                ],
                'mission' => [
                    'label' => 'Mission',
                    'type' => self::TYPE_RICHTEXT,
                    'default' => null,
                    'max_chars' => 800,
                    'sort' => 60,
                ],
                'vision' => [
                    'label' => 'Vision',
                    'type' => self::TYPE_RICHTEXT,
                    'default' => null,
                    'max_chars' => 800,
                    'sort' => 70,
                ],
                'history_intro' => [
                    'label' => 'History introduction',
                    'type' => self::TYPE_RICHTEXT,
                    'default' => null,
                    'max_chars' => 800,
                    'help' => 'The paragraph above the timeline below.',
                    'sort' => 80,
                ],
                'why_choose_us_heading' => [
                    'label' => 'Why choose us — heading',
                    'type' => self::TYPE_TEXT,
                    'default' => 'Why choose us',
                    'max_chars' => 120,
                    'sort' => 90,
                ],
            ],
            'repeaters' => [
                'why_choose_us' => [
                    'label' => 'Why choose us',
                    'item_label' => 'point',
                    'min' => 0,
                    'max' => 9,
                    'item_label_field' => 'title',
                    'fields' => self::iconTextItemFields(),
                ],
                'history' => [
                    'label' => 'History timeline',
                    'item_label' => 'milestone',
                    'min' => 0,
                    'max' => 12,
                    'item_label_field' => 'title',
                    'fields' => self::historyItemFields(),
                ],
                'statistic' => self::statisticRepeater(
                    'Statistics',
                    'The same statistics engine as the hero: type a number in, or count one live.'
                ),
            ],
            'media' => [
                'image_1' => [
                    'label' => 'Image 1',
                    'profile' => ImageProfile::Card,
                ],
                'image_2' => [
                    'label' => 'Image 2',
                    'profile' => ImageProfile::Card,
                ],
            ],
        ];
    }

    /**
     * Requirement §7 and §101: an arbitrary content block, repeatable, for anything the fixed types
     * do not cover.
     *
     * @return array<string, mixed>
     */
    private static function richContentType(): array
    {
        return [
            'label' => 'Rich content',
            'description' => 'A free heading, body and image block — repeatable, for anything the other types do not cover.',
            'icon' => 'document-text',
            'group' => 'content',
            'placements' => [
                SectionPlacement::Home->value => 95,
                SectionPlacement::Page->value => 30,
            ],
            'unique' => false,
            'required' => false,
            'requirement' => '§7, §101',
            'fields' => [
                'heading' => [
                    'label' => 'Heading',
                    'type' => self::TYPE_TEXT,
                    'default' => null,
                    'max_chars' => 120,
                    'sort' => 10,
                ],
                'subheading' => [
                    'label' => 'Subheading',
                    'type' => self::TYPE_TEXT,
                    'default' => null,
                    'max_chars' => 200,
                    'sort' => 20,
                ],
                'body' => [
                    'label' => 'Body',
                    'type' => self::TYPE_RICHTEXT,
                    'default' => null,
                    'max_chars' => 4000,
                    'sort' => 30,
                ],
                'layout' => [
                    'label' => 'Layout',
                    'type' => self::TYPE_SELECT,
                    'options' => [
                        'full' => 'Full width',
                        'text_left' => 'Text left, image right',
                        'text_right' => 'Image left, text right',
                    ],
                    'default' => 'full',
                    'span' => 6,
                    'tab' => self::TAB_ADVANCED,
                    'sort' => 40,
                ],
                'background' => [
                    'label' => 'Background',
                    'type' => self::TYPE_SELECT,
                    'options' => [
                        'surface' => 'Page surface',
                        'muted' => 'Muted',
                        'brand' => 'Brand',
                    ],
                    'default' => 'surface',
                    'span' => 6,
                    'tab' => self::TAB_ADVANCED,
                    'sort' => 50,
                ],
            ],
            'repeaters' => [
                'highlight' => [
                    'label' => 'Highlights',
                    'item_label' => 'highlight',
                    'min' => 0,
                    'max' => 6,
                    'item_label_field' => 'title',
                    'fields' => self::iconTextItemFields(),
                ],
            ],
            'media' => [
                'image_1' => [
                    'label' => 'Image',
                    'profile' => ImageProfile::Card,
                ],
            ],
        ];
    }

    /**
     * Requirement §100: FAQs on the home page or on a custom page.
     *
     * @return array<string, mixed>
     */
    private static function faqType(): array
    {
        return [
            'label' => 'FAQs',
            'description' => 'Questions and answers from the FAQ library, by category, hand-picked, or featured only.',
            'icon' => 'question-mark-circle',
            'group' => 'engagement',
            'placements' => [
                SectionPlacement::Home->value => 110,
                SectionPlacement::Page->value => 50,
            ],
            'unique' => false,
            'required' => false,
            'requirement' => '§100',
            'fields' => [
                'heading' => [
                    'label' => 'Heading',
                    'type' => self::TYPE_TEXT,
                    'required' => true,
                    'default' => 'Frequently asked questions',
                    'max_chars' => 120,
                    'sort' => 10,
                ],
                'description' => [
                    'label' => 'Description',
                    'type' => self::TYPE_TEXTAREA,
                    'default' => null,
                    'max_chars' => 300,
                    'sort' => 20,
                ],
                'source' => [
                    'label' => 'Which questions',
                    'type' => self::TYPE_SELECT,
                    'options' => FaqSource::options(),
                    'default' => FaqSource::Category->value,
                    'span' => 6,
                    'sort' => 30,
                ],
                'faq_category_ref' => [
                    'label' => 'Category',
                    'type' => self::TYPE_FAQ_CATEGORY_REF,
                    'ref_by' => self::REF_BY_SLUG,
                    'default' => null,
                    'span' => 6,
                    'help' => 'Used when the source is a category. Stored as the category slug, so it is a scalar and not a foreign key in JSON (INV-3); a deleted category simply renders nothing.',
                    'sort' => 40,
                ],
                'columns' => [
                    'label' => 'Columns',
                    'type' => self::TYPE_NUMBER,
                    'rules' => ['integer', 'min:1', 'max:2'],
                    'default' => 2,
                    'span' => 6,
                    'tab' => self::TAB_ADVANCED,
                    'sort' => 50,
                ],
                'show_all_link' => self::linkField('"See all" link', 60, [
                    'tab' => self::TAB_BUTTONS,
                    'default' => self::linkDefault('See all questions', '/faqs', ButtonStyle::Link),
                ]),
            ],
            'repeaters' => [],
            'media' => [],
        ];
    }

    /**
     * Requirement §100: a reusable call to action, referenced and never copied.
     *
     * @return array<string, mixed>
     */
    private static function ctaType(): array
    {
        return [
            'label' => 'Call to action',
            'description' => 'Place one of the reusable CTA blocks. Editing the block changes it everywhere it appears.',
            'icon' => 'megaphone',
            'group' => 'engagement',
            'placements' => [
                SectionPlacement::Home->value => 120,
                SectionPlacement::Page->value => 60,
            ],
            'unique' => false,
            'required' => false,
            'requirement' => '§100',
            'fields' => [
                'cta_ref' => [
                    'label' => 'CTA block',
                    'type' => self::TYPE_CTA_REF,
                    'column' => 'cta_block_id',
                    'ref_by' => self::REF_BY_ID,
                    'required' => true,
                    'help' => 'Manage the blocks under Website → CTA blocks. A block that is still a draft renders nothing.',
                    'sort' => 10,
                ],
            ],
            'repeaters' => [],
            'media' => [],
        ];
    }

    /**
     * Requirement §8 and §100: the footer.
     *
     * @return array<string, mixed>
     */
    private static function footerType(): array
    {
        return [
            'label' => 'Site footer',
            'description' => 'The about line, up to two menu columns, the legal links, contact details, social icons and the copyright.',
            'icon' => 'bars-3-bottom-left',
            'group' => 'layout',
            'placements' => [SectionPlacement::GlobalFooter->value => 10],
            'unique' => true,
            'required' => true,
            'requirement' => '§8, §100',
            'fields' => [
                'about_text' => [
                    'label' => 'About line',
                    'type' => self::TYPE_TEXTAREA,
                    'default' => null,
                    'max_chars' => 400,
                    'sort' => 10,
                ],
                'column_1_heading' => [
                    'label' => 'Column 1 heading',
                    'type' => self::TYPE_TEXT,
                    'default' => 'Company',
                    'max_chars' => 60,
                    'span' => 6,
                    'sort' => 20,
                ],
                'menu_ref' => [
                    'label' => 'Column 1 menu',
                    'type' => self::TYPE_MENU_REF,
                    'column' => 'menu_id',
                    'ref_by' => self::REF_BY_ID,
                    'span' => 6,
                    'help' => 'The footer\'s first menu column, stored on the section\'s menu_id column.',
                    'sort' => 30,
                ],
                'column_2_heading' => [
                    'label' => 'Column 2 heading',
                    'type' => self::TYPE_TEXT,
                    'default' => 'Explore',
                    'max_chars' => 60,
                    'span' => 6,
                    'sort' => 40,
                ],
                'menu_ref_2' => [
                    'label' => 'Column 2 menu',
                    'type' => self::TYPE_MENU_REF,
                    'ref_by' => self::REF_BY_LOCATION,
                    'default' => MenuLocation::FooterSecondary->value,
                    'span' => 6,
                    'help' => 'Referenced by layout slot rather than by id: menus.location is unique, so one slot is one menu, and a deleted menu leaves an empty column instead of a dangling id in JSON (INV-3).',
                    'sort' => 50,
                ],
                'legal_menu_ref' => [
                    'label' => 'Legal links menu',
                    'type' => self::TYPE_MENU_REF,
                    'ref_by' => self::REF_BY_LOCATION,
                    'default' => MenuLocation::FooterLegal->value,
                    'span' => 6,
                    'help' => 'Privacy policy, terms, refund policy, course policy — the four pages the seeder ships.',
                    'sort' => 60,
                ],
                'show_contact' => [
                    'label' => 'Show the contact block',
                    'type' => self::TYPE_BOOLEAN,
                    'default' => true,
                    'span' => 6,
                    'help' => 'Address, phone, WhatsApp, email and business hours, from Settings → Contact.',
                    'sort' => 70,
                ],
                'show_social' => [
                    'label' => 'Show the social icons',
                    'type' => self::TYPE_BOOLEAN,
                    'default' => true,
                    'span' => 6,
                    'help' => 'Only the profiles that are filled in under Settings → Social profiles render.',
                    'sort' => 80,
                ],
                'show_newsletter' => [
                    'label' => 'Show the newsletter form',
                    'type' => self::TYPE_BOOLEAN,
                    'default' => false,
                    'readonly' => true,
                    'span' => 6,
                    'help' => 'Available once a newsletter module ships. Read-only until then, rather than a switch that does nothing.',
                    'sort' => 90,
                ],
                'copyright_override' => [
                    'label' => 'Copyright override',
                    'type' => self::TYPE_TEXT,
                    'default' => null,
                    'max_chars' => 200,
                    'help' => 'Leave empty to use Settings → Company → Copyright text, with the year interpolated.',
                    'sort' => 100,
                ],
            ],
            'repeaters' => [
                'link' => [
                    'label' => 'Badges',
                    'item_label' => 'badge',
                    'min' => 0,
                    'max' => 6,
                    'item_label_field' => 'label',
                    'help' => 'Payment methods, partner or certification badges. An image with an optional link.',
                    'fields' => self::badgeItemFields(),
                ],
            ],
            'media' => [
                'logo_override' => [
                    'label' => 'Footer logo',
                    'profile' => ImageProfile::Logo,
                    'help' => 'Optional. Falls back to the header logo, then to Settings → Branding.',
                ],
                'badge_1' => [
                    'label' => 'Badge 1',
                    'profile' => ImageProfile::Logo,
                ],
                'badge_2' => [
                    'label' => 'Badge 2',
                    'profile' => ImageProfile::Logo,
                ],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Shared field and repeater builders
    |--------------------------------------------------------------------------
    */

    /**
     * A `link` composite: label, url, style, new tab. One declaration for the header's four buttons,
     * the hero's two, the FAQ "see all" link and every later phase that needs a button.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function linkField(string $label, int $sort, array $overrides = []): array
    {
        return array_merge([
            'label' => $label,
            'type' => self::TYPE_LINK,
            'default' => self::linkDefault(null, null, ButtonStyle::Primary),
            'sort' => $sort,
        ], $overrides);
    }

    /**
     * @return array{label: string|null, url: string|null, style: string, new_tab: bool}
     */
    private static function linkDefault(?string $label, ?string $url, ButtonStyle $style): array
    {
        return [
            'label' => $label,
            'url' => $url,
            'style' => $style->value,
            'new_tab' => false,
        ];
    }

    /**
     * The statistics repeater of requirement §9, shared by `hero` and `about`.
     *
     * `value_mode`, `metric` and `manual_value` are **real columns** on `website_section_items`
     * (§2.3) because they are the only repeater values a service resolves, validates and reports on.
     * The conditional "metric is required when the mode is auto" is CHECK `chk_wsi_value` and
     * `WebsiteSectionService::upsertItem()`, not a rule here.
     *
     * @return array<string, mixed>
     */
    private static function statisticRepeater(string $label, string $help): array
    {
        return [
            'label' => $label,
            'item_label' => 'statistic',
            'min' => 0,
            'max' => 8,
            'item_label_field' => 'label',
            'help' => $help,
            'fields' => [
                'label' => [
                    'label' => 'Caption',
                    'type' => self::TYPE_TEXT,
                    'required' => true,
                    'default' => null,
                    'max_chars' => 40,
                    'span' => 6,
                    'sort' => 10,
                ],
                'value_mode' => [
                    'label' => 'Value',
                    'type' => self::TYPE_SELECT,
                    'column' => 'value_mode',
                    'options' => StatisticValueMode::options(),
                    'default' => StatisticValueMode::Manual->value,
                    'required' => true,
                    'span' => 6,
                    'sort' => 20,
                ],
                'metric' => [
                    'label' => 'Live metric',
                    'type' => self::TYPE_SELECT,
                    'column' => 'metric',
                    'options' => StatisticMetric::options(),
                    'default' => null,
                    'span' => 6,
                    'help' => 'Required when the value is counted live. A metric whose module is disabled or whose table does not exist yet renders nothing at all — never a zero.',
                    'sort' => 30,
                ],
                'manual_value' => [
                    'label' => 'Number',
                    'type' => self::TYPE_DECIMAL,
                    'column' => 'manual_value',
                    'rules' => ['numeric', 'min:0', 'max:'.self::MAX_STATISTIC_VALUE],
                    'default' => null,
                    'span' => 6,
                    'help' => 'Used when the value is typed in, and as the fallback when a live metric cannot be resolved.',
                    'sort' => 40,
                ],
                'prefix' => [
                    'label' => 'Prefix',
                    'type' => self::TYPE_TEXT,
                    'default' => null,
                    'max_chars' => 8,
                    'span' => 6,
                    'placeholder' => 'PKR',
                    'sort' => 50,
                ],
                'suffix' => [
                    'label' => 'Suffix',
                    'type' => self::TYPE_TEXT,
                    'default' => null,
                    'max_chars' => 8,
                    'span' => 6,
                    'placeholder' => '+',
                    'sort' => 60,
                ],
                'icon' => [
                    'label' => 'Icon',
                    'type' => self::TYPE_ICON,
                    'default' => null,
                    'span' => 6,
                    'sort' => 70,
                ],
            ],
        ];
    }

    /**
     * Icon + title + text — the `why_choose_us` and `highlight` repeaters.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function iconTextItemFields(): array
    {
        return [
            'icon' => [
                'label' => 'Icon',
                'type' => self::TYPE_ICON,
                'default' => null,
                'span' => 4,
                'sort' => 10,
            ],
            'title' => [
                'label' => 'Title',
                'type' => self::TYPE_TEXT,
                'required' => true,
                'default' => null,
                'max_chars' => 80,
                'span' => 8,
                'sort' => 20,
            ],
            'text' => [
                'label' => 'Text',
                'type' => self::TYPE_TEXTAREA,
                'default' => null,
                'max_chars' => 300,
                'sort' => 30,
            ],
        ];
    }

    /**
     * Year + title + text — the `history` timeline of requirement §10.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function historyItemFields(): array
    {
        return [
            'year' => [
                'label' => 'Year',
                'type' => self::TYPE_NUMBER,
                'required' => true,
                'rules' => ['integer', 'min:1900', 'max:2200'],
                'default' => null,
                'span' => 3,
                'sort' => 10,
            ],
            'title' => [
                'label' => 'Milestone',
                'type' => self::TYPE_TEXT,
                'required' => true,
                'default' => null,
                'max_chars' => 120,
                'span' => 9,
                'sort' => 20,
            ],
            'text' => [
                'label' => 'Text',
                'type' => self::TYPE_TEXTAREA,
                'default' => null,
                'max_chars' => 400,
                'sort' => 30,
            ],
        ];
    }

    /**
     * Label + url + new tab + icon — the header's top-bar links.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function linkItemFields(): array
    {
        return [
            'label' => [
                'label' => 'Label',
                'type' => self::TYPE_TEXT,
                'required' => true,
                'default' => null,
                'max_chars' => 40,
                'span' => 6,
                'sort' => 10,
            ],
            'url' => [
                'label' => 'URL',
                'type' => self::TYPE_URL,
                'required' => true,
                'default' => null,
                'span' => 6,
                'help' => 'https://, mailto:, tel:, a site-relative /path or a #anchor.',
                'sort' => 20,
            ],
            'icon' => [
                'label' => 'Icon',
                'type' => self::TYPE_ICON,
                'default' => null,
                'span' => 6,
                'sort' => 30,
            ],
            'new_tab' => [
                'label' => 'Open in a new tab',
                'type' => self::TYPE_BOOLEAN,
                'default' => false,
                'span' => 6,
                'sort' => 40,
            ],
        ];
    }

    /**
     * An image with an optional link — the footer's payment and partner badges.
     *
     * The image lives on the item's own `media_asset_id` column (§2.3), not in a pivot.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function badgeItemFields(): array
    {
        return [
            'image' => [
                'label' => 'Image',
                'type' => self::TYPE_IMAGE,
                'column' => 'media_asset_id',
                'profile' => ImageProfile::Logo,
                'required' => true,
                'default' => null,
                'span' => 6,
                'sort' => 10,
            ],
            'label' => [
                'label' => 'Label',
                'type' => self::TYPE_TEXT,
                'required' => true,
                'default' => null,
                'max_chars' => 40,
                'span' => 6,
                'help' => 'Also used as the image alt text when the asset has none.',
                'sort' => 20,
            ],
            'url' => [
                'label' => 'URL',
                'type' => self::TYPE_URL,
                'default' => null,
                'span' => 6,
                'sort' => 30,
            ],
            'new_tab' => [
                'label' => 'Open in a new tab',
                'type' => self::TYPE_BOOLEAN,
                'default' => false,
                'span' => 6,
                'sort' => 40,
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Normalisation
    |--------------------------------------------------------------------------
    */

    /**
     * One type, or an exception naming what is declared. `type()` and `exists()` are the null-safe
     * pair the renderer uses (INV-2); everything that reaches this method is a bug, not user input.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private static function definition(string $key): array
    {
        $type = self::type($key);

        if ($type === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown section type [%s]. Declared types: %s.',
                $key,
                implode(', ', self::keys())
            ));
        }

        return $type;
    }

    /**
     * Fill a raw type declaration out to the full definition.
     *
     * @param  array<string, mixed>  $type
     * @return array<string, mixed>
     */
    private static function normaliseType(string $key, array $type): array
    {
        $placements = [];

        foreach ((array) ($type['placements'] ?? []) as $placement => $sort) {
            $placement = (string) $placement;

            if (SectionPlacement::tryFrom($placement) === null) {
                throw new InvalidArgumentException(sprintf(
                    'Section type [%s] declares the unknown placement [%s].',
                    $key,
                    $placement
                ));
            }

            $placements[$placement] = (int) $sort;
        }

        if ($placements === []) {
            throw new InvalidArgumentException(sprintf('Section type [%s] declares no placement.', $key));
        }

        $fields = [];

        foreach ((array) ($type['fields'] ?? []) as $field => $definition) {
            $fields[(string) $field] = self::normaliseField($key, (string) $field, (array) $definition);
        }

        uasort($fields, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $repeaters = [];

        foreach ((array) ($type['repeaters'] ?? []) as $group => $repeater) {
            $repeaters[(string) $group] = self::normaliseRepeater($key, (string) $group, (array) $repeater);
        }

        $media = [];

        foreach ((array) ($type['media'] ?? []) as $role => $slot) {
            $media[(string) $role] = self::normaliseMediaRole($key, (string) $role, (array) $slot);
        }

        $group = (string) ($type['group'] ?? 'content');

        if (! array_key_exists($group, self::groups())) {
            throw new InvalidArgumentException(sprintf(
                'Section type [%s] declares the unknown group [%s].',
                $key,
                $group
            ));
        }

        return [
            'key' => $key,
            'label' => (string) ($type['label'] ?? ucfirst(str_replace('_', ' ', $key))),
            'description' => (string) ($type['description'] ?? ''),
            'icon' => (string) ($type['icon'] ?? 'square-3-stack-3d'),
            'group' => $group,
            'placements' => $placements,
            'unique' => (bool) ($type['unique'] ?? false),
            'required' => (bool) ($type['required'] ?? false),
            'is_live' => (bool) ($type['is_live'] ?? false),
            'provider' => isset($type['provider']) ? (string) $type['provider'] : null,
            'view' => (string) ($type['view'] ?? 'site.sections.'.$key),
            'edit_view' => isset($type['edit_view']) ? (string) $type['edit_view'] : null,
            'requirement' => (string) ($type['requirement'] ?? ''),
            'fields' => $fields,
            'repeaters' => $repeaters,
            'media' => $media,
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private static function normaliseField(string $key, string $name, array $field): array
    {
        $type = (string) ($field['type'] ?? self::TYPE_TEXT);

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Section type [%s] field [%s] declares the unknown field type [%s].',
                $key,
                $name,
                $type
            ));
        }

        $column = isset($field['column']) ? (string) $field['column'] : null;
        $isMedia = in_array($type, self::MEDIA_TYPES, true);

        $stored = match (true) {
            $column !== null => self::STORED_COLUMN,
            $isMedia => self::STORED_MEDIA,
            default => self::STORED_CONTENT,
        };

        $refBy = isset($field['ref_by'])
            ? (string) $field['ref_by']
            : self::defaultRefBy($type);

        $required = (bool) ($field['required'] ?? false);
        $maxChars = isset($field['max_chars']) ? (int) $field['max_chars'] : null;
        $span = (int) ($field['span'] ?? self::DEFAULT_SPAN);

        $options = $field['options'] ?? null;

        if ($options === null && $refBy === self::REF_BY_LOCATION) {
            $options = MenuLocation::options();
        }

        $rules = self::resolveRules(
            declared: isset($field['rules']) ? array_values((array) $field['rules']) : null,
            type: $type,
            refBy: $refBy,
            required: $required,
            maxChars: $maxChars,
            options: is_array($options) ? $options : null,
        );

        return [
            'key' => $name,
            'label' => (string) ($field['label'] ?? ucfirst(str_replace('_', ' ', $name))),
            'type' => $type,
            'stored' => $stored,
            'column' => $column,
            'ref_by' => $refBy,
            'profile' => $field['profile'] ?? null,
            'rules' => $rules,
            'item_rules' => self::itemRulesForField($type, $required),
            'default' => $field['default'] ?? null,
            'options' => $options,
            'help' => isset($field['help']) ? (string) $field['help'] : null,
            'placeholder' => isset($field['placeholder']) ? (string) $field['placeholder'] : null,
            'suffix' => isset($field['suffix']) ? (string) $field['suffix'] : null,
            'required' => $required,
            'readonly' => (bool) ($field['readonly'] ?? false),
            'max_chars' => $maxChars,
            'span' => max(1, min(12, $span)),
            'tab' => self::resolveTab($field, $type),
            'sort' => (int) ($field['sort'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $repeater
     * @return array<string, mixed>
     */
    private static function normaliseRepeater(string $key, string $group, array $repeater): array
    {
        $fields = [];

        foreach ((array) ($repeater['fields'] ?? []) as $field => $definition) {
            $fields[(string) $field] = self::normaliseField($key, (string) $field, (array) $definition);
        }

        uasort($fields, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $min = max(0, (int) ($repeater['min'] ?? 0));
        $max = (int) ($repeater['max'] ?? 20);

        if ($max < $min) {
            throw new InvalidArgumentException(sprintf(
                'Section type [%s] repeater [%s] declares max %d below min %d.',
                $key,
                $group,
                $max,
                $min
            ));
        }

        $labelField = (string) ($repeater['item_label_field'] ?? 'label');

        if ($fields !== [] && ! array_key_exists($labelField, $fields)) {
            throw new InvalidArgumentException(sprintf(
                'Section type [%s] repeater [%s] names [%s] as its item label field, which it does not declare.',
                $key,
                $group,
                $labelField
            ));
        }

        return [
            'group' => $group,
            'label' => (string) ($repeater['label'] ?? ucfirst(str_replace('_', ' ', $group))),
            'item_label' => (string) ($repeater['item_label'] ?? 'item'),
            'min' => $min,
            'max' => $max,
            'item_label_field' => $labelField,
            'help' => isset($repeater['help']) ? (string) $repeater['help'] : null,
            'sort' => (int) ($repeater['sort'] ?? 0),
            'fields' => $fields,
        ];
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    private static function normaliseMediaRole(string $key, string $role, array $slot): array
    {
        $kind = (string) ($slot['kind'] ?? self::KIND_IMAGE);

        if (! in_array($kind, [self::KIND_IMAGE, self::KIND_VIDEO], true)) {
            throw new InvalidArgumentException(sprintf(
                'Section type [%s] media role [%s] declares the unknown kind [%s].',
                $key,
                $role,
                $kind
            ));
        }

        $profile = $slot['profile'] ?? null;

        if ($kind === self::KIND_IMAGE && ! $profile instanceof ImageProfile) {
            throw new InvalidArgumentException(sprintf(
                'Section type [%s] media role [%s] must declare an ImageProfile.',
                $key,
                $role
            ));
        }

        return [
            'role' => $role,
            'label' => (string) ($slot['label'] ?? ucfirst(str_replace('_', ' ', $role))),
            'kind' => $kind,
            'profile' => $profile,
            'multiple' => (bool) ($slot['multiple'] ?? false),
            'required' => (bool) ($slot['required'] ?? false),
            'help' => isset($slot['help']) ? (string) $slot['help'] : null,
        ];
    }

    /**
     * The editor tab a field lands on when it does not name one (§8.5).
     *
     * @param  array<string, mixed>  $field
     */
    private static function resolveTab(array $field, string $type): string
    {
        $tab = isset($field['tab']) ? (string) $field['tab'] : null;

        if ($tab !== null && array_key_exists($tab, self::TABS)) {
            return $tab;
        }

        return match (true) {
            $type === self::TYPE_LINK => self::TAB_BUTTONS,
            in_array($type, self::MEDIA_TYPES, true) => self::TAB_MEDIA,
            default => self::TAB_CONTENT,
        };
    }

    /**
     * How a reference field points at its target when it does not say.
     */
    private static function defaultRefBy(string $type): string
    {
        return match ($type) {
            self::TYPE_FAQ_CATEGORY_REF => self::REF_BY_SLUG,
            self::TYPE_CTA_REF, self::TYPE_MENU_REF, self::TYPE_PAGE_REF => self::REF_BY_ID,
            default => '',
        };
    }

    /**
     * A field's rules: what it declared, or the sane default for its type, with `required` /
     * `nullable`, the `max_chars` ceiling and the `in:` rule for an option-backed input added.
     *
     * The `in:` rule is skipped when the field already constrains itself, and when any option value
     * contains a comma, which `in:` cannot express — the same rule phase-02's `SettingsRegistry`
     * applies.
     *
     * @param  list<string>|null  $declared
     * @param  array<string, mixed>|null  $options
     * @return list<string>
     */
    private static function resolveRules(
        ?array $declared,
        string $type,
        string $refBy,
        bool $required,
        ?int $maxChars,
        ?array $options,
    ): array {
        $rules = $declared ?? self::defaultRules($type, $refBy);

        $hasPresence = false;
        $hasMax = false;
        $constrained = false;

        foreach ($rules as $rule) {
            if ($rule === 'required' || $rule === 'nullable') {
                $hasPresence = true;
            }

            if (str_starts_with($rule, 'max:')) {
                $hasMax = true;
            }

            if (str_starts_with($rule, 'in:') || str_starts_with($rule, 'exists:')) {
                $constrained = true;
            }
        }

        if (! $hasPresence) {
            array_unshift($rules, $required ? 'required' : 'nullable');
        }

        if (! $hasMax && $maxChars !== null) {
            $rules[] = 'max:'.$maxChars;
        }

        if (! $constrained && $type === self::TYPE_SELECT && $options !== null && $options !== []) {
            $values = array_map('strval', array_keys($options));
            $hasComma = false;

            foreach ($values as $value) {
                if (str_contains($value, ',')) {
                    $hasComma = true;
                    break;
                }
            }

            if (! $hasComma) {
                $rules[] = 'in:'.implode(',', $values);
            }
        }

        if (! $constrained && $type === self::TYPE_MENU_REF && $refBy === self::REF_BY_LOCATION) {
            $rules[] = 'in:'.implode(',', MenuLocation::values());
        }

        return array_values($rules);
    }

    /**
     * @return list<string>
     */
    private static function defaultRules(string $type, string $refBy): array
    {
        return match ($type) {
            self::TYPE_TEXT, self::TYPE_COLOR, self::TYPE_ICON => ['string'],
            self::TYPE_TEXTAREA, self::TYPE_RICHTEXT => ['string'],
            self::TYPE_URL => ['string', 'max:500', self::LINK_URL_RULE],
            self::TYPE_EMAIL => ['email', 'max:191'],
            self::TYPE_TEL => ['string', 'max:32'],
            self::TYPE_NUMBER => ['integer'],
            self::TYPE_DECIMAL => ['numeric'],
            self::TYPE_BOOLEAN => ['boolean'],
            self::TYPE_SELECT => ['string'],
            self::TYPE_MULTISELECT, self::TYPE_LINK => ['array'],
            self::TYPE_IMAGE, self::TYPE_VIDEO => ['integer', 'exists:media_assets,id'],
            self::TYPE_CTA_REF => ['integer', 'exists:cta_blocks,id'],
            self::TYPE_MENU_REF => $refBy === self::REF_BY_LOCATION
                ? ['string']
                : ['integer', 'exists:menus,id'],
            self::TYPE_FAQ_CATEGORY_REF => $refBy === self::REF_BY_ID
                ? ['integer', 'exists:faq_categories,id']
                : ['string', 'max:150', 'exists:faq_categories,slug'],
            self::TYPE_PAGE_REF => ['integer', 'exists:pages,id'],
            default => ['string'],
        };
    }

    /**
     * The child rules of a composite field. Only `link` has any: label, url, style, new tab.
     *
     * @return array<string, list<string>>
     */
    private static function itemRulesForField(string $type, bool $required): array
    {
        if ($type !== self::TYPE_LINK) {
            return [];
        }

        return [
            'label' => [$required ? 'required' : 'nullable', 'string', 'max:60'],
            'url' => ['nullable', 'string', 'max:500', self::LINK_URL_RULE],
            'style' => ['nullable', 'string', 'in:'.implode(',', ButtonStyle::values())],
            'new_tab' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Rule keys for a set of normalised fields, media slots excluded (they are synced from the
     * pivot, not validated as content).
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, list<string>>
     */
    private static function rulesFromFields(array $fields, ?string $prefix): array
    {
        $prefix = $prefix === null || $prefix === '' ? '' : rtrim($prefix, '.').'.';
        $rules = [];

        foreach ($fields as $key => $field) {
            if ($field['stored'] === self::STORED_MEDIA) {
                continue;
            }

            $rules[$prefix.$key] = $field['rules'];

            foreach ($field['item_rules'] as $suffix => $childRules) {
                $rules[$prefix.$key.'.'.$suffix] = array_values((array) $childRules);
            }
        }

        return $rules;
    }
}
