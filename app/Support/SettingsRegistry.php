<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ThemePreference;
use App\Models\Branch;
use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * The single source of truth for every setting the system owns (phase-02 §2).
 *
 * Definitions live here, values live in the `settings` table — exactly the relationship
 * PermissionRegistry has with the `permissions` table. Pure arrays: no database, no cache, no
 * facades, no container, nothing that can fail before the app has booted. Three consumers read
 * it and none of them may declare a setting of its own:
 *
 *   · `SettingSeeder`          — creates the rows (value on insert, metadata every run)
 *   · `UpdateSettingsRequest`  — `rulesFor($group)` is the server-side validation
 *   · the settings screen       — `groups()` drives the tab rail, `fields($group)` the form
 *
 * A later phase adds settings by appending a group (or appending keys to an existing group)
 * here; it never writes a second registry and never hardcodes a key anywhere else.
 *
 * ---------------------------------------------------------------------------------------------
 * Field definition
 * ---------------------------------------------------------------------------------------------
 *
 * `fields()` always returns every key below, so a consumer never needs `isset()`:
 *
 * | key           | meaning                                                                      |
 * |---------------|------------------------------------------------------------------------------|
 * | `key`         | the bare key (`name`), repeated inside the definition for convenience        |
 * | `group`       | the owning group slug                                                        |
 * | `label`       | the form label                                                               |
 * | `type`        | the **input** type (§2's vocabulary, see self::TYPES) — what `<x-settings.field>` switches on |
 * | `storage`     | the `settings.type` column value this input persists as (see self::storageType()) |
 * | `rules`       | Laravel rules for this field, as a list of strings                           |
 * | `item_rules`  | rules for array children, suffix => rules (`'*'`, `'*.open'`, …); empty when none |
 * | `default`     | the registry default — what the seeder writes on insert and what `resetGroup()` restores |
 * | `options`     | array (value => label), or a callable returning one (timezones, currencies, branches); null otherwise |
 * | `help`        | help text rendered under the field                                           |
 * | `placeholder` | input placeholder                                                            |
 * | `suffix`      | unit rendered after the input (`%`, `minutes`, `MB`)                         |
 * | `encrypted`   | stored through `Crypt`; never logged, never echoed back (`mail.password`)    |
 * | `public`      | readable by the public website (`settings.is_public`)                        |
 * | `readonly`    | rendered disabled; may only change through the console or the environment    |
 * | `span`        | 1–12 grid columns                                                            |
 * | `sort`        | order inside the group                                                       |
 *
 * ---------------------------------------------------------------------------------------------
 * One key, one home
 * ---------------------------------------------------------------------------------------------
 *
 * **Every key a view reads is declared here.** That is a hard rule, not an aspiration: a key a
 * Blade file reads and this registry does not declare is a setting that renders on no screen and
 * that no administrator can ever change, and — worse — the pair of a legacy key in the view with
 * a canonical key on the settings screen makes the two halves of the app read different rows, so
 * uploading a logo appears to do nothing. Phase 2 relocated the company identity keys into the
 * `branding` and `contact` groups without moving the views; the split is closed by
 * `2026_09_12_060400_supersede_relocated_setting_keys`, which carries each legacy value into its
 * canonical row (never over a value already set there), marks the legacy row readonly and renames
 * its label "Deprecated - use <canonical key>".
 *
 * The superseded keys, and where each one now lives. The first block was the live defect — a view
 * read the legacy row while the settings screen wrote the canonical one; the second is the same
 * relocation in the other groups §2 owns outright, closed at the same time so one concept is
 * never left on two rows again:
 *
 * | legacy row (deprecated, readonly, never deleted) | canonical key               |
 * |--------------------------------------------------|-----------------------------|
 * | `company.logo_path`                              | `branding.logo_light`       |
 * | `company.logo_dark_path`                         | `branding.logo_dark`        |
 * | `company.favicon_path`                           | `branding.favicon`          |
 * | `company.email`                                  | `contact.email`             |
 * | `company.phone`                                  | `contact.phone`             |
 * | `company.support_email`                          | `contact.support_email`     |
 * | `company.whatsapp`                               | `contact.whatsapp`          |
 * | `company.address`                                | `contact.address`           |
 * | `company.city`                                   | `contact.city`              |
 * | `company.country`                                | `contact.country`           |
 * | `company.description`                            | `company.short_description` |
 * | `appearance.brand_color`                         | `branding.brand_color`      |
 * | `appearance.accent_color`                        | `branding.accent_color`     |
 * | `appearance.login_illustration_path`             | `branding.login_background` |
 * | `seo.canonical_url`                              | `seo.canonical_base_url`    |
 * | `seo.og_image_path`                              | `seo.og_image`              |
 * | `seo.robots` *(marked only, never copied)*       | `seo.robots_indexable`      |
 * | `social.twitter`                                 | `social.x_twitter`          |
 * | `social.whatsapp`                                | `social.whatsapp_link`      |
 * | `mail.reply_to_address`                          | `mail.reply_to`             |
 *
 * `company.short_name`, `company.website` and `company.features` had no canonical home at all, so
 * they are declared above **at their Phase 1 names** — adopting a key where it already lives
 * moves no data and loses no value.
 *
 * Re-declaring a superseded key here is refused by `SettingSeeder`, by name: metadata refreshed
 * over a deprecated row would clear its `is_readonly` flag and its label and hand the settings
 * screen a field that writes a row nothing reads, which is the defect all over again.
 *
 * ---------------------------------------------------------------------------------------------
 * Keys that are deliberately NOT here
 * ---------------------------------------------------------------------------------------------
 *
 * Phase 1 seeded further keys that phase-02 §2 does not list (`collaborator.wallet_hold_days`,
 * `institute.student_id_next_number`, `institute.name`, …) and later phases own further groups
 * (`website` → phase-03, `projects` → phase-06, `hr` → phase-07, `crm` → phase-05, `support` →
 * phase-19-23). Those rows stay in the table untouched — the seeder is additive and never
 * deletes — they are simply not maintained from here until their owning phase declares them, and
 * **no view may read one**. `localization.currency_decimals` is intentionally absent too: money
 * columns are `decimal(15,2)` and `Money` works at scale 2, so the number of decimals is not a
 * business choice. The one Phase 1 group carried forward in full is `appearance`, whose four keys
 * have no home among §2's twelve groups and are still read by the shell.
 */
final class SettingsRegistry
{
    /** Every group is edited behind the same permission (phase-02 §4). */
    public const PERMISSION = 'settings.edit';

    /*
    |--------------------------------------------------------------------------
    | Input types (phase-02 §2)
    |--------------------------------------------------------------------------
    */

    public const TYPE_TEXT = 'text';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_EMAIL = 'email';

    public const TYPE_TEL = 'tel';

    public const TYPE_URL = 'url';

    public const TYPE_NUMBER = 'number';

    public const TYPE_DECIMAL = 'decimal';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_SELECT = 'select';

    public const TYPE_MULTISELECT = 'multiselect';

    public const TYPE_COLOR = 'color';

    public const TYPE_IMAGE = 'image';

    public const TYPE_FILE = 'file';

    public const TYPE_JSON = 'json';

    public const TYPE_TIME = 'time';

    public const TYPE_PASSWORD = 'password';

    public const TYPE_RICHTEXT = 'richtext';

    /**
     * Every input type a field may declare.
     *
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_TEXTAREA,
        self::TYPE_EMAIL,
        self::TYPE_TEL,
        self::TYPE_URL,
        self::TYPE_NUMBER,
        self::TYPE_DECIMAL,
        self::TYPE_BOOLEAN,
        self::TYPE_SELECT,
        self::TYPE_MULTISELECT,
        self::TYPE_COLOR,
        self::TYPE_IMAGE,
        self::TYPE_FILE,
        self::TYPE_JSON,
        self::TYPE_TIME,
        self::TYPE_PASSWORD,
        self::TYPE_RICHTEXT,
    ];

    /**
     * Input type => `settings.type` column value (phase-01 §1.4's eight storage types).
     *
     * The column describes how the value is stored and cast; the registry's `type` describes how
     * it is edited. `SettingSeeder` writes `storage`, never `type`.
     *
     * @var array<string, string>
     */
    private const STORAGE_TYPES = [
        self::TYPE_TEXT => 'string',
        self::TYPE_TEXTAREA => 'text',
        self::TYPE_EMAIL => 'string',
        self::TYPE_TEL => 'string',
        self::TYPE_URL => 'string',
        self::TYPE_NUMBER => 'integer',
        self::TYPE_DECIMAL => 'decimal',
        self::TYPE_BOOLEAN => 'boolean',
        self::TYPE_SELECT => 'select',
        self::TYPE_MULTISELECT => 'json',
        self::TYPE_COLOR => 'string',
        self::TYPE_IMAGE => 'file',
        self::TYPE_FILE => 'file',
        self::TYPE_JSON => 'json',
        self::TYPE_TIME => 'string',
        self::TYPE_PASSWORD => 'string',
        self::TYPE_RICHTEXT => 'text',
    ];

    /** Input types whose value is a stored file path rather than a scalar the user typed. */
    public const FILE_TYPES = [
        self::TYPE_IMAGE,
        self::TYPE_FILE,
    ];

    /** Grid columns a field occupies when it does not say otherwise. */
    public const DEFAULT_SPAN = 6;

    /**
     * Rate key => [its type key, the type value that makes the rate a percentage].
     * See crossFieldErrors().
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PERCENTAGE_WHEN = [
        'collaborator.default_student_commission_rate' => ['collaborator.default_student_commission_type', 'percentage'],
        'collaborator.default_project_commission_rate' => ['collaborator.default_project_commission_type', 'percentage'],
    ];

    /** Normalised definitions, built once per process. */
    private static ?array $normalised = null;

    /*
    |--------------------------------------------------------------------------
    | The contract surface (phase-02 §2)
    |--------------------------------------------------------------------------
    */

    /**
     * Every group, keyed by slug, in display order.
     *
     * @return array<string, array{slug: string, label: string, icon: string, description: string, sort: int, permission: string}>
     */
    public static function groups(): array
    {
        $groups = [
            'company' => [
                'label' => 'Company',
                'icon' => 'building-office',
                'description' => 'Legal identity and the wording that appears on documents and the public site.',
                'sort' => 10,
            ],
            'branding' => [
                'label' => 'Branding',
                'icon' => 'photo',
                'description' => 'Logos, favicon, social preview image and the brand colours the whole shell is painted with.',
                'sort' => 20,
            ],
            'appearance' => [
                'label' => 'Appearance',
                'icon' => 'sun',
                'description' => 'Shell defaults applied to accounts that have expressed no preference of their own.',
                'sort' => 25,
            ],
            'localization' => [
                'label' => 'Localization',
                'icon' => 'globe-alt',
                'description' => 'Currency, timezone, date and number presentation. Every amount and date in the system reads these.',
                'sort' => 30,
            ],
            'contact' => [
                'label' => 'Contact',
                'icon' => 'phone',
                'description' => 'Phone numbers, addresses, map position and opening hours.',
                'sort' => 40,
            ],
            'social' => [
                'label' => 'Social profiles',
                'icon' => 'share',
                'description' => 'Profile links rendered in the public footer. An empty field renders no icon.',
                'sort' => 50,
            ],
            'seo' => [
                'label' => 'SEO & analytics',
                'icon' => 'magnifying-glass',
                'description' => 'Default meta tags, indexing, sitemap and the analytics identifiers.',
                'sort' => 60,
            ],
            'mail' => [
                'label' => 'Email',
                'icon' => 'envelope',
                'description' => 'Outgoing mail transport. Saved here and used instead of the environment file.',
                'sort' => 70,
            ],
            'collaborator' => [
                'label' => 'Collaborators & commission',
                'icon' => 'user-group',
                'description' => 'Referral tracking, commission bases and rates, payout policy.',
                'sort' => 80,
            ],
            'institute' => [
                'label' => 'Institute',
                'icon' => 'academic-cap',
                'description' => 'Admissions, numbering, attendance and class defaults.',
                'sort' => 90,
            ],
            'finance' => [
                'label' => 'Finance',
                'icon' => 'banknotes',
                'description' => 'Invoice numbering, tax, payment terms and expense approval.',
                'sort' => 100,
            ],
            'security' => [
                'label' => 'Security',
                'icon' => 'shield-check',
                'description' => 'Password policy, lockout, session lifetime and upload limits.',
                'sort' => 110,
            ],
            'maintenance' => [
                'label' => 'Maintenance',
                'icon' => 'wrench-screwdriver',
                'description' => 'Switch the public site, its forms and maintenance mode on or off. The admin panel is never affected.',
                'sort' => 120,
            ],
        ];

        $resolved = [];

        foreach ($groups as $slug => $group) {
            $resolved[$slug] = [
                'slug' => $slug,
                'label' => $group['label'],
                'icon' => $group['icon'],
                'description' => $group['description'],
                'sort' => $group['sort'],
                'permission' => self::PERMISSION,
            ];
        }

        return $resolved;
    }

    /**
     * One group's fields, keyed by bare key, in `sort` order.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws InvalidArgumentException when the group does not exist
     */
    public static function fields(string $group): array
    {
        $all = self::all();

        if (! array_key_exists($group, $all)) {
            throw new InvalidArgumentException(sprintf('Unknown settings group [%s].', $group));
        }

        return $all[$group];
    }

    /**
     * Every group's fields: [group => [key => definition]].
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function all(): array
    {
        if (self::$normalised !== null) {
            return self::$normalised;
        }

        $normalised = [];

        foreach (self::definitions() as $group => $fields) {
            $resolved = [];

            foreach ($fields as $key => $field) {
                $resolved[$key] = self::normalise((string) $group, (string) $key, $field);
            }

            uasort($resolved, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

            $normalised[$group] = $resolved;
        }

        return self::$normalised = $normalised;
    }

    /**
     * Laravel validation rules for a whole group, ready for a Form Request.
     *
     * Keys are bare field keys by default (`'name' => [...]`). Pass `$prefix` when the form nests
     * its inputs — `rulesFor('company', 'settings')` returns `'settings.name' => [...]` and
     * `'settings.business_hours.*.open' => [...]` — so the rule keys line up with the request
     * payload without anyone rebuilding them by hand.
     *
     * Select and multiselect fields gain an `in:` rule built from their own options, so the form
     * and the validator can never disagree about what is allowed. Rules here are intentionally
     * single-field: no `required_if` across keys, because a Form Request may nest the payload
     * under a prefix this registry does not know about.
     *
     * @return array<string, list<string>>
     */
    public static function rulesFor(string $group, ?string $prefix = null): array
    {
        $rules = [];
        $prefix = $prefix === null || $prefix === '' ? '' : rtrim($prefix, '.').'.';

        foreach (self::fields($group) as $key => $field) {
            $rules[$prefix.$key] = self::rulesForField($field);

            foreach ($field['item_rules'] as $suffix => $itemRules) {
                $rules[$prefix.$key.'.'.$suffix] = array_values((array) $itemRules);
            }
        }

        return $rules;
    }

    /**
     * One field by its dotted `group.key` name, or null when it is not declared.
     *
     * @return array<string, mixed>|null
     */
    public static function field(string $key): ?array
    {
        [$group, $name] = self::split($key);

        if ($group === null || $name === null) {
            return null;
        }

        return self::all()[$group][$name] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | Convenience readers (used by the seeder and SettingsService)
    |--------------------------------------------------------------------------
    */

    /**
     * One group's metadata, or null.
     *
     * @return array{slug: string, label: string, icon: string, description: string, sort: int, permission: string}|null
     */
    public static function group(string $slug): ?array
    {
        return self::groups()[$slug] ?? null;
    }

    /**
     * Is this a declared group?
     */
    public static function hasGroup(string $group): bool
    {
        return array_key_exists($group, self::all());
    }

    /**
     * Is this a declared `group.key`?
     */
    public static function has(string $key): bool
    {
        return self::field($key) !== null;
    }

    /**
     * Every declared key as a flat list of dotted names, in group then field order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];

        foreach (self::all() as $group => $fields) {
            foreach (array_keys($fields) as $key) {
                $keys[] = $group.'.'.$key;
            }
        }

        return $keys;
    }

    /**
     * The registry defaults — what a fresh install holds and what `resetGroup()` restores.
     *
     * Without a group: keyed by dotted name. With one: keyed by bare key.
     *
     * @return array<string, mixed>
     */
    public static function defaults(?string $group = null): array
    {
        $defaults = [];

        if ($group !== null) {
            foreach (self::fields($group) as $key => $field) {
                $defaults[$key] = $field['default'];
            }

            return $defaults;
        }

        foreach (self::all() as $slug => $fields) {
            foreach ($fields as $key => $field) {
                $defaults[$slug.'.'.$key] = $field['default'];
            }
        }

        return $defaults;
    }

    /**
     * Dotted keys whose value is encrypted at rest.
     *
     * @return list<string>
     */
    public static function encryptedKeys(): array
    {
        return self::keysWhere('encrypted');
    }

    /**
     * The request input names of every secret field, as the settings form nests them
     * (`settings.password`) — what must never be flashed back into the session as old input.
     *
     * A field counts when it is encrypted at rest or rendered as a password input.
     *
     * @return list<string>
     */
    public static function secretInputNames(): array
    {
        $names = [];

        foreach (self::all() as $fields) {
            foreach ($fields as $key => $field) {
                if ($field['encrypted'] === true || $field['type'] === self::TYPE_PASSWORD) {
                    $names[] = 'settings.'.$key;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Dotted keys the public website may read.
     *
     * @return list<string>
     */
    public static function publicKeys(): array
    {
        return self::keysWhere('public');
    }

    /**
     * Dotted keys the UI must render disabled.
     *
     * @return list<string>
     */
    public static function readonlyKeys(): array
    {
        return self::keysWhere('readonly');
    }

    /**
     * The one cross-field rule the per-field rules cannot express: a commission rate is a
     * percentage — at most 100 — whenever its sibling `*_type` key says `percentage`, and an amount
     * otherwise.
     *
     * Judged on the **effective** values (submitted where submitted, stored otherwise), and only
     * when one of the pair is being written, so `UpdateSettingsRequest` and `SettingsService`
     * reach the same verdict for a full form post and for a partial programmatic save.
     *
     * @param  callable(string): mixed  $effective  bare key => the value it will hold after the save
     * @param  list<string>  $submitted  bare keys present in this save
     * @return array<string, string> bare key the error belongs to => message
     */
    public static function crossFieldErrors(string $group, callable $effective, array $submitted): array
    {
        $errors = [];

        foreach (self::PERCENTAGE_WHEN as $rateKey => [$typeKey, $percentage]) {
            [$rateGroup, $rate] = self::split($rateKey);
            [, $type] = self::split($typeKey);

            if ($rateGroup !== $group || $rate === null || $type === null) {
                continue;
            }

            if (! in_array($rate, $submitted, true) && ! in_array($type, $submitted, true)) {
                continue;
            }

            $typeValue = $effective($type);
            $rateValue = $effective($rate);

            if (! is_scalar($typeValue) || (string) $typeValue !== $percentage || ! is_scalar($rateValue)) {
                continue;
            }

            $rateValue = trim((string) $rateValue);

            if (preg_match('/^\+?\d*\.?\d+$/', $rateValue) !== 1 || bccomp(ltrim($rateValue, '+'), '100', 4) <= 0) {
                continue;
            }

            $field = self::field($rateKey);

            $errors[in_array($rate, $submitted, true) ? $rate : $type] = sprintf(
                'The %s may not be greater than 100 while the commission type is a percentage.',
                mb_strtolower((string) ($field['label'] ?? $rate)),
            );
        }

        return $errors;
    }

    /**
     * Dotted keys that hold an uploaded file path.
     *
     * @return list<string>
     */
    public static function fileKeys(): array
    {
        $keys = [];

        foreach (self::all() as $group => $fields) {
            foreach ($fields as $key => $field) {
                if (self::isFileType((string) $field['type'])) {
                    $keys[] = $group.'.'.$key;
                }
            }
        }

        return $keys;
    }

    /**
     * The `settings.type` column value for an input type.
     */
    public static function storageType(string $type): string
    {
        return self::STORAGE_TYPES[$type] ?? 'string';
    }

    /**
     * Does this input type store an uploaded file path?
     */
    public static function isFileType(string $type): bool
    {
        return in_array($type, self::FILE_TYPES, true);
    }

    /**
     * Resolve a field's options to a plain value => label array.
     *
     * Accepts a field definition or a dotted key. A callable option provider (timezones,
     * currencies, branches) is invoked here and nowhere else, so a screen never has to know
     * whether a list is static.
     *
     * @param  array<string, mixed>|string  $field
     * @return array<string, string>
     */
    public static function optionsFor(array|string $field): array
    {
        if (is_string($field)) {
            $field = self::field($field) ?? [];
        }

        $options = $field['options'] ?? null;

        if (is_callable($options)) {
            try {
                $options = $options();
            } catch (Throwable) {
                // An option provider that needs the database must never break the form.
                return [];
            }
        }

        if (! is_array($options)) {
            return [];
        }

        $resolved = [];

        foreach ($options as $value => $label) {
            $resolved[(string) $value] = is_scalar($label) ? (string) $label : (string) $value;
        }

        return $resolved;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<string>
     */
    private static function keysWhere(string $flag): array
    {
        $keys = [];

        foreach (self::all() as $group => $fields) {
            foreach ($fields as $key => $field) {
                if ($field[$flag] === true) {
                    $keys[] = $group.'.'.$key;
                }
            }
        }

        return $keys;
    }

    /**
     * 'company.name' => ['company', 'name']; anything else => [null, null].
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function split(string $key): array
    {
        $key = trim($key);

        if (! str_contains($key, '.')) {
            return [null, null];
        }

        [$group, $name] = explode('.', $key, 2);

        return [$group === '' ? null : $group, $name === '' ? null : $name];
    }

    /**
     * Fill a raw declaration out to the full field definition.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private static function normalise(string $group, string $key, array $field): array
    {
        $type = (string) ($field['type'] ?? self::TYPE_TEXT);

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf('Setting [%s.%s] declares the unknown type [%s].', $group, $key, $type)
            );
        }

        $span = (int) ($field['span'] ?? self::DEFAULT_SPAN);

        return [
            'key' => $key,
            'group' => $group,
            'label' => (string) ($field['label'] ?? ucfirst(str_replace('_', ' ', $key))),
            'type' => $type,
            'storage' => self::storageType($type),
            'rules' => array_values((array) ($field['rules'] ?? ['nullable', 'string'])),
            'item_rules' => (array) ($field['item_rules'] ?? []),
            'default' => $field['default'] ?? null,
            'options' => $field['options'] ?? null,
            'help' => isset($field['help']) ? (string) $field['help'] : null,
            'placeholder' => isset($field['placeholder']) ? (string) $field['placeholder'] : null,
            'suffix' => isset($field['suffix']) ? (string) $field['suffix'] : null,
            'encrypted' => (bool) ($field['encrypted'] ?? false),
            'public' => (bool) ($field['public'] ?? false),
            'readonly' => (bool) ($field['readonly'] ?? false),
            'span' => max(1, min(12, $span)),
            'sort' => (int) ($field['sort'] ?? 0),
        ];
    }

    /**
     * A field's own rules, with the `in:` rule added for option-backed inputs.
     *
     * The `in:` rule is skipped when the field already constrains itself (`in:`, `exists:`,
     * `timezone` — 400-odd identifiers do not belong in a rule string), for a multiselect (there
     * the constraint belongs to the children, declared as `item_rules['*']`), and when any option
     * value contains a comma, which `in:` cannot express.
     *
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private static function rulesForField(array $field): array
    {
        /** @var list<string> $rules */
        $rules = array_values((array) $field['rules']);

        if ($field['type'] !== self::TYPE_SELECT || $field['options'] === null) {
            return $rules;
        }

        foreach ($rules as $rule) {
            if ($rule === 'timezone' || str_starts_with($rule, 'in:') || str_starts_with($rule, 'exists:')) {
                return $rules;
            }
        }

        $options = array_keys(self::optionsFor($field));

        if ($options === []) {
            return $rules;
        }

        foreach ($options as $option) {
            if (str_contains((string) $option, ',')) {
                return $rules;
            }
        }

        $rules[] = 'in:'.implode(',', $options);

        return $rules;
    }

    /*
    |--------------------------------------------------------------------------
    | Option providers
    |--------------------------------------------------------------------------
    */

    /**
     * Currencies offered by the localization group.
     *
     * @return array<string, string>
     */
    public static function currencyOptions(): array
    {
        return [
            'PKR' => 'Pakistani Rupee (PKR)',
            'USD' => 'US Dollar (USD)',
            'EUR' => 'Euro (EUR)',
            'GBP' => 'Pound Sterling (GBP)',
            'AED' => 'UAE Dirham (AED)',
            'SAR' => 'Saudi Riyal (SAR)',
            'INR' => 'Indian Rupee (INR)',
            'CAD' => 'Canadian Dollar (CAD)',
            'AUD' => 'Australian Dollar (AUD)',
            'CNY' => 'Chinese Yuan (CNY)',
            'TRY' => 'Turkish Lira (TRY)',
            'MYR' => 'Malaysian Ringgit (MYR)',
            'QAR' => 'Qatari Riyal (QAR)',
            'OMR' => 'Omani Rial (OMR)',
            'KWD' => 'Kuwaiti Dinar (KWD)',
            'BHD' => 'Bahraini Dinar (BHD)',
        ];
    }

    /**
     * Every PHP timezone, labelled with its current UTC offset.
     *
     * @return array<string, string>
     */
    public static function timezoneOptions(): array
    {
        $options = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            try {
                $zone = new DateTimeZone($identifier);
                $offset = $zone->getOffset(new DateTime('now', new DateTimeZone('UTC')));
            } catch (Throwable) {
                continue;
            }

            $sign = $offset < 0 ? '-' : '+';
            $offset = abs($offset);

            $options[$identifier] = sprintf(
                '%s (UTC%s%02d:%02d)',
                str_replace('_', ' ', $identifier),
                $sign,
                intdiv($offset, 3600),
                intdiv($offset % 3600, 60)
            );
        }

        return $options;
    }

    /**
     * Date formats offered by the localization group, labelled with today's date.
     *
     * @return array<string, string>
     */
    public static function dateFormatOptions(): array
    {
        $options = [];

        foreach (['d M Y', 'd/m/Y', 'd-m-Y', 'Y-m-d', 'm/d/Y', 'j F Y'] as $format) {
            $options[$format] = date($format).'  ('.$format.')';
        }

        return $options;
    }

    /**
     * Branches offered as the institute default. Resolved lazily: the registry itself stays
     * database-free, and a failure here degrades to an empty list rather than breaking the form.
     *
     * @return array<int|string, string>
     */
    public static function branchOptions(): array
    {
        try {
            /** @var array<int|string, string> $options */
            $options = Branch::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();

            return $options;
        } catch (Throwable) {
            return [];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The declarations
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function definitions(): array
    {
        return [
            'company' => self::companyFields(),
            'branding' => self::brandingFields(),
            'appearance' => self::appearanceFields(),
            'localization' => self::localizationFields(),
            'contact' => self::contactFields(),
            'social' => self::socialFields(),
            'seo' => self::seoFields(),
            'mail' => self::mailFields(),
            'collaborator' => self::collaboratorFields(),
            'institute' => self::instituteFields(),
            'finance' => self::financeFields(),
            'security' => self::securityFields(),
            'maintenance' => self::maintenanceFields(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function companyFields(): array
    {
        return [
            'name' => [
                'label' => 'Company name',
                'type' => self::TYPE_TEXT,
                'rules' => ['required', 'string', 'max:150'],
                'default' => 'MyOffice ERP',
                'help' => 'Used in the shell, on documents and as the fallback page title.',
                'public' => true,
                'span' => 6,
                'sort' => 10,
            ],
            /*
            | short_name / website / features are the three Phase 1 keys the phase-02 §2 field
            | list has no entry for, and all three are read by a live view: the sidebar rail
            | (`layouts/partials/brand`), the shell footer (`layouts/partials/footer`) and the
            | sign-in panel (`layouts/guest`). A key a view reads and the registry does not
            | declare is a setting nobody can edit, so they are adopted here rather than dropped
            | from the views — at the group and key name Phase 1 already seeded them under, so
            | the adoption moves no data and the stored values survive untouched.
            */
            'short_name' => [
                'label' => 'Short name',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:60'],
                'default' => null,
                'help' => 'Used where the full name will not fit — the collapsed sidebar, narrow headers. Falls back to the company name.',
                'placeholder' => 'MyOffice',
                'public' => true,
                'span' => 6,
                'sort' => 15,
            ],
            'legal_name' => [
                'label' => 'Registered legal name',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:190'],
                'default' => null,
                'help' => 'Printed on invoices and contracts when it differs from the trading name.',
                'placeholder' => 'MyOffice Technologies (Pvt) Ltd',
                'public' => true,
                'span' => 6,
                'sort' => 20,
            ],
            'website' => [
                'label' => 'Website',
                'type' => self::TYPE_URL,
                'rules' => ['nullable', 'url', 'max:190'],
                'default' => null,
                'help' => 'The public address, linked from the shell footer. Not the same thing as the SEO canonical base URL, which may differ and carries no trailing slash.',
                'placeholder' => 'https://www.example.com',
                'public' => true,
                'span' => 6,
                'sort' => 25,
            ],
            'tagline' => [
                'label' => 'Tagline',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:190'],
                'default' => 'One system for your software house and training institute.',
                'public' => true,
                'span' => 12,
                'sort' => 30,
            ],
            'short_description' => [
                'label' => 'Short description',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:500'],
                'default' => 'Custom software development and job-ready IT training under one roof.',
                'help' => 'Two or three sentences, reused as the SEO description fallback.',
                'public' => true,
                'span' => 12,
                'sort' => 40,
            ],
            'features' => [
                'label' => 'Highlights',
                'type' => self::TYPE_JSON,
                'rules' => ['nullable', 'array', 'max:8'],
                'item_rules' => [
                    '*' => ['string', 'max:190'],
                ],
                'default' => [
                    'Projects, tasks and client billing in one place',
                    'Admissions, batches, attendance and fees for the institute',
                    'Role-based access with a full audit trail',
                ],
                'help' => 'A JSON list of short selling points. The first four are shown beside the sign-in form.',
                'public' => true,
                'span' => 12,
                'sort' => 45,
            ],
            'founded_year' => [
                'label' => 'Founded in',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'digits:4', 'min:1900', 'max:2200'],
                'default' => null,
                'placeholder' => '2019',
                'public' => true,
                'span' => 3,
                'sort' => 50,
            ],
            'registration_number' => [
                'label' => 'Registration number',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:100'],
                'default' => null,
                'help' => 'Printed on invoices and certificates when set.',
                'span' => 3,
                'sort' => 60,
            ],
            'ntn_number' => [
                'label' => 'NTN / tax number',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:100'],
                'default' => null,
                'span' => 3,
                'sort' => 70,
            ],
            'copyright_text' => [
                'label' => 'Copyright line',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:190'],
                'default' => null,
                'help' => 'Shown in the footer. Leave empty to fall back to the company name and the current year.',
                'placeholder' => '© {year} MyOffice ERP. All rights reserved.',
                'public' => true,
                'span' => 12,
                'sort' => 80,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function brandingFields(): array
    {
        return [
            /*
            | SVG is deliberately NOT accepted on any of the three fields below, and the help text
            | says so. Two independent reasons, both discovered during Phase 2 integration:
            |
            |  1. Laravel 11+ removed `svg` from what the `image` rule accepts unless it is written
            |     `image:allow_svg`, so `['image', 'mimes:…,svg']` was self-contradictory — a valid
            |     SVG was refused with "must be an image" and the help text promised something the
            |     validator could never accept.
            |  2. These three fields are `public => true`, i.e. they land on the public disk and are
            |     reachable at a plain URL. An SVG is an XML document that may carry <script>, so an
            |     uploaded SVG opened directly is stored XSS in the application's own origin. D25
            |     puts every piece of untrusted markup through App\Support\RichText, which does not
            |     exist until Phase 3 and does not cover uploaded files at all. Raster only.
            |
            | Anyone re-adding SVG here must first put the upload through a sanitiser and serve it
            | from a controller (D21), not from the public disk.
            */
            'logo_light' => [
                'label' => 'Logo (light background)',
                'type' => self::TYPE_IMAGE,
                'rules' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
                'default' => null,
                'help' => 'PNG or WebP, transparent background, about 240×64. Falls back to generated initials.',
                'public' => true,
                'span' => 6,
                'sort' => 10,
            ],
            'logo_dark' => [
                'label' => 'Logo (dark background)',
                'type' => self::TYPE_IMAGE,
                'rules' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
                'default' => null,
                'help' => 'Used by the dark theme. Falls back to the light logo.',
                'public' => true,
                'span' => 6,
                'sort' => 20,
            ],
            'favicon' => [
                'label' => 'Favicon',
                'type' => self::TYPE_IMAGE,
                'rules' => ['nullable', 'file', 'mimes:png,ico,webp', 'max:512'],
                'default' => null,
                'help' => 'Square PNG or ICO, at least 64×64.',
                'public' => true,
                'span' => 4,
                'sort' => 30,
            ],
            'og_image' => [
                'label' => 'Social preview image',
                'type' => self::TYPE_IMAGE,
                'rules' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
                'default' => null,
                'help' => '1200×630 renders best when a page is shared.',
                'public' => true,
                'span' => 4,
                'sort' => 40,
            ],
            'email_logo' => [
                'label' => 'Email logo',
                'type' => self::TYPE_IMAGE,
                'rules' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:1024'],
                'default' => null,
                'help' => 'PNG only — mail clients do not render SVG.',
                'span' => 4,
                'sort' => 50,
            ],
            'login_background' => [
                'label' => 'Sign-in background',
                'type' => self::TYPE_IMAGE,
                'rules' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
                'default' => null,
                'span' => 6,
                'sort' => 60,
            ],
            'brand_color' => [
                'label' => 'Brand colour',
                'type' => self::TYPE_COLOR,
                'rules' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'default' => '#4f46e5',
                'help' => 'The whole shell is repainted from this one value through the --brand-* CSS variables.',
                'placeholder' => '#4f46e5',
                'public' => true,
                'span' => 3,
                'sort' => 70,
            ],
            'accent_color' => [
                'label' => 'Accent colour',
                'type' => self::TYPE_COLOR,
                'rules' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'default' => '#0ea5e9',
                'help' => 'Used for highlights, charts and links.',
                'placeholder' => '#0ea5e9',
                'public' => true,
                'span' => 3,
                'sort' => 80,
            ],
        ];
    }

    /**
     * The one Phase 1 group carried forward: shell defaults with no home among §2's twelve
     * groups, still read by the layout.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function appearanceFields(): array
    {
        return [
            'default_theme' => [
                'label' => 'Default theme',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'default' => ThemePreference::System->value,
                'options' => ThemePreference::options(),
                'help' => 'Applied to accounts that have not picked a theme of their own.',
                'public' => true,
                'span' => 4,
                'sort' => 10,
            ],
            'sidebar_collapsed_by_default' => [
                'label' => 'Collapse the sidebar by default',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'span' => 4,
                'sort' => 20,
            ],
            'table_page_size' => [
                'label' => 'Rows per page',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:5', 'max:200'],
                'default' => 15,
                'help' => 'Default pagination size for list screens.',
                'suffix' => 'rows',
                'span' => 4,
                'sort' => 30,
            ],
            'show_powered_by' => [
                'label' => 'Show the footer credit line',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'public' => true,
                'span' => 4,
                'sort' => 40,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function localizationFields(): array
    {
        return [
            'currency' => [
                'label' => 'Currency',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'size:3'],
                'default' => 'PKR',
                'options' => [self::class, 'currencyOptions'],
                'help' => 'Money columns are decimal(15,2); changing the currency changes presentation, never a stored amount.',
                'public' => true,
                'span' => 4,
                'sort' => 10,
            ],
            'currency_symbol' => [
                'label' => 'Currency symbol',
                'type' => self::TYPE_TEXT,
                'rules' => ['required', 'string', 'max:8'],
                'default' => 'Rs',
                'help' => 'Follows the currency: switching the currency also switches a standard symbol. A symbol you typed yourself is kept.',
                'public' => true,
                'span' => 4,
                'sort' => 20,
            ],
            'currency_position' => [
                'label' => 'Symbol position',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'default' => 'before',
                'options' => [
                    'before' => 'Before the amount (Rs 1,000.00)',
                    'after' => 'After the amount (1,000.00 Rs)',
                ],
                'span' => 4,
                'sort' => 30,
            ],
            'decimal_separator' => [
                'label' => 'Decimal separator',
                'type' => self::TYPE_TEXT,
                'rules' => ['required', 'string', 'size:1'],
                'default' => '.',
                'help' => 'Must differ from the thousand separator.',
                'span' => 3,
                'sort' => 40,
            ],
            'thousand_separator' => [
                'label' => 'Thousand separator',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:1'],
                'default' => ',',
                'help' => 'Leave empty for no grouping.',
                'span' => 3,
                'sort' => 50,
            ],
            'timezone' => [
                'label' => 'Timezone',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'timezone'],
                'default' => 'Asia/Karachi',
                'options' => [self::class, 'timezoneOptions'],
                'help' => 'The timezone dates and times are shown in when a user has no timezone of their own. Display only: everything is stored in UTC.',
                'public' => true,
                'span' => 6,
                'sort' => 60,
            ],
            'date_format' => [
                'label' => 'Date format',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'max:32'],
                'default' => 'd M Y',
                'options' => [self::class, 'dateFormatOptions'],
                'help' => 'Every date rendered through app_date() follows this.',
                'span' => 4,
                'sort' => 70,
            ],
            'time_format' => [
                'label' => 'Time format',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'max:32'],
                'default' => 'h:i A',
                'options' => [
                    'h:i A' => '03:45 PM (12-hour)',
                    'H:i' => '15:45 (24-hour)',
                ],
                'span' => 4,
                'sort' => 80,
            ],
            'week_start' => [
                'label' => 'First day of the week',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'default' => 'monday',
                'options' => [
                    'monday' => 'Monday',
                    'saturday' => 'Saturday',
                    'sunday' => 'Sunday',
                ],
                'help' => 'Drives the "this week" date range and every calendar view.',
                'span' => 4,
                'sort' => 90,
            ],
            'locale' => [
                'label' => 'Default language',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'max:8'],
                'default' => 'en',
                'options' => [
                    'en' => 'English',
                    'ur' => 'Urdu',
                ],
                'public' => true,
                'span' => 4,
                'sort' => 100,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function contactFields(): array
    {
        return [
            'phone' => [
                'label' => 'Phone',
                'type' => self::TYPE_TEL,
                'rules' => ['nullable', 'string', 'max:32'],
                'default' => '+92 42 0000000',
                'public' => true,
                'span' => 4,
                'sort' => 10,
            ],
            'phone_secondary' => [
                'label' => 'Second phone',
                'type' => self::TYPE_TEL,
                'rules' => ['nullable', 'string', 'max:32'],
                'default' => null,
                'public' => true,
                'span' => 4,
                'sort' => 20,
            ],
            'whatsapp' => [
                'label' => 'WhatsApp',
                'type' => self::TYPE_TEL,
                'rules' => ['nullable', 'string', 'max:32'],
                'default' => '+92 300 0000000',
                'public' => true,
                'span' => 4,
                'sort' => 30,
            ],
            'email' => [
                'label' => 'Contact email',
                'type' => self::TYPE_EMAIL,
                'rules' => ['nullable', 'email:rfc', 'max:150'],
                'default' => 'info@myoffice.test',
                'public' => true,
                'span' => 6,
                'sort' => 40,
            ],
            'support_email' => [
                'label' => 'Support email',
                'type' => self::TYPE_EMAIL,
                'rules' => ['nullable', 'email:rfc', 'max:150'],
                'default' => 'support@myoffice.test',
                'help' => 'Shown in the footer and on support screens. Falls back to the contact email.',
                'public' => true,
                'span' => 6,
                'sort' => 50,
            ],
            'address' => [
                'label' => 'Address',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:500'],
                'default' => 'Main Boulevard, Gulberg III',
                'public' => true,
                'span' => 12,
                'sort' => 60,
            ],
            'city' => [
                'label' => 'City',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:100'],
                'default' => 'Lahore',
                'public' => true,
                'span' => 4,
                'sort' => 70,
            ],
            'country' => [
                'label' => 'Country',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:100'],
                'default' => 'Pakistan',
                'public' => true,
                'span' => 4,
                'sort' => 80,
            ],
            'latitude' => [
                'label' => 'Latitude',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['nullable', 'numeric', 'between:-90,90'],
                'default' => null,
                'placeholder' => '31.5204',
                'public' => true,
                'span' => 3,
                'sort' => 90,
            ],
            'longitude' => [
                'label' => 'Longitude',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['nullable', 'numeric', 'between:-180,180'],
                'default' => null,
                'placeholder' => '74.3587',
                'public' => true,
                'span' => 3,
                'sort' => 100,
            ],
            'map_embed' => [
                'label' => 'Map embed',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:2000'],
                'default' => null,
                'help' => 'The map provider’s embed URL or iframe. Rendered on the public contact page only.',
                'public' => true,
                'span' => 12,
                'sort' => 110,
            ],
            'business_hours' => [
                'label' => 'Business hours',
                'type' => self::TYPE_JSON,
                // One row per weekday: an unbounded list would be folded into the single cached
                // settings payload every request loads.
                'rules' => ['nullable', 'array', 'max:7'],
                'item_rules' => [
                    '*' => ['array'],
                    '*.open' => ['nullable', 'date_format:H:i'],
                    '*.close' => ['nullable', 'date_format:H:i'],
                    '*.closed' => ['nullable', 'boolean'],
                ],
                'default' => [
                    'monday' => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
                    'tuesday' => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
                    'wednesday' => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
                    'thursday' => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
                    'friday' => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
                    'saturday' => ['open' => '10:00', 'close' => '14:00', 'closed' => false],
                    'sunday' => ['open' => null, 'close' => null, 'closed' => true],
                ],
                'help' => 'One row per day: opening time, closing time, or closed.',
                'public' => true,
                'span' => 12,
                'sort' => 120,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function socialFields(): array
    {
        $networks = [
            'facebook' => ['Facebook', 'https://facebook.com/yourpage', 10],
            'instagram' => ['Instagram', 'https://instagram.com/yourpage', 20],
            'linkedin' => ['LinkedIn', 'https://linkedin.com/company/yourpage', 30],
            'youtube' => ['YouTube', 'https://youtube.com/@yourchannel', 40],
            'tiktok' => ['TikTok', 'https://tiktok.com/@yourpage', 50],
            'x_twitter' => ['X (Twitter)', 'https://x.com/yourpage', 60],
            'github' => ['GitHub', 'https://github.com/yourorg', 70],
            'whatsapp_link' => ['WhatsApp link', 'https://wa.me/923000000000', 80],
        ];

        $fields = [];

        foreach ($networks as $key => [$label, $placeholder, $sort]) {
            $fields[$key] = [
                'label' => $label,
                'type' => self::TYPE_URL,
                'rules' => ['nullable', 'url', 'max:190'],
                'default' => null,
                'placeholder' => $placeholder,
                'public' => true,
                'span' => 6,
                'sort' => $sort,
            ];
        }

        $fields['whatsapp_link']['help'] = 'A wa.me link, not a bare number — the public site links straight to it.';

        return $fields;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function seoFields(): array
    {
        return [
            'meta_title' => [
                'label' => 'Default meta title',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:70'],
                'default' => null,
                'help' => 'Up to about 60 characters. Used when a page declares none of its own.',
                'public' => true,
                'span' => 12,
                'sort' => 10,
            ],
            'meta_description' => [
                'label' => 'Default meta description',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:180'],
                'default' => null,
                'help' => 'Up to about 160 characters.',
                'public' => true,
                'span' => 12,
                'sort' => 20,
            ],
            'meta_keywords' => [
                'label' => 'Default meta keywords',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:500'],
                'default' => null,
                'help' => 'Comma separated. Carried for completeness; search engines largely ignore it.',
                'public' => true,
                'span' => 12,
                'sort' => 30,
            ],
            'canonical_base_url' => [
                'label' => 'Canonical base URL',
                'type' => self::TYPE_URL,
                'rules' => ['nullable', 'url', 'max:190'],
                'default' => null,
                'help' => 'No trailing slash. Canonical tags and the sitemap are built from it.',
                'placeholder' => 'https://www.example.com',
                'public' => true,
                'span' => 6,
                'sort' => 40,
            ],
            'og_image' => [
                'label' => 'Default social image',
                'type' => self::TYPE_IMAGE,
                'rules' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
                'default' => null,
                'help' => 'Falls back to the branding social preview image.',
                'public' => true,
                'span' => 6,
                'sort' => 50,
            ],
            'robots_indexable' => [
                'label' => 'Allow search engines to index the site',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off sends noindex, nofollow on every public page.',
                'public' => true,
                'span' => 6,
                'sort' => 60,
            ],
            'sitemap_enabled' => [
                'label' => 'Publish sitemap.xml',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'public' => true,
                'span' => 6,
                'sort' => 70,
            ],
            'google_analytics_id' => [
                'label' => 'Google Analytics ID',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:64'],
                'default' => null,
                'placeholder' => 'G-XXXXXXXXXX',
                'public' => true,
                'span' => 4,
                'sort' => 80,
            ],
            'google_tag_manager_id' => [
                'label' => 'Google Tag Manager ID',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:64'],
                'default' => null,
                'placeholder' => 'GTM-XXXXXXX',
                'public' => true,
                'span' => 4,
                'sort' => 90,
            ],
            'facebook_pixel_id' => [
                'label' => 'Facebook Pixel ID',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:64'],
                'default' => null,
                'public' => true,
                'span' => 4,
                'sort' => 100,
            ],
            'google_site_verification' => [
                'label' => 'Google site verification',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:190'],
                'default' => null,
                'help' => 'The content value of the verification meta tag.',
                'public' => true,
                'span' => 6,
                'sort' => 110,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function mailFields(): array
    {
        return [
            'mailer' => [
                'label' => 'Transport',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'default' => 'log',
                'options' => [
                    'smtp' => 'SMTP server',
                    'log' => 'Write to the log (no mail is sent)',
                    'sendmail' => 'Local sendmail',
                ],
                'help' => 'These saved settings are used instead of the environment file.',
                'span' => 4,
                'sort' => 10,
            ],
            'host' => [
                'label' => 'SMTP host',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:190'],
                'default' => null,
                'placeholder' => 'smtp.example.com',
                'help' => 'Required once the transport is SMTP.',
                'span' => 5,
                'sort' => 20,
            ],
            'port' => [
                'label' => 'Port',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'default' => 587,
                'help' => '587 for TLS, 465 for SSL.',
                'span' => 3,
                'sort' => 30,
            ],
            'username' => [
                'label' => 'Username',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:190'],
                'default' => null,
                'span' => 6,
                'sort' => 40,
            ],
            'password' => [
                'label' => 'Password',
                'type' => self::TYPE_PASSWORD,
                'rules' => ['nullable', 'string', 'max:190'],
                'default' => null,
                'help' => 'Encrypted at rest. Never shown again once saved, and never written to the activity log.',
                'encrypted' => true,
                'span' => 6,
                'sort' => 50,
            ],
            'encryption' => [
                'label' => 'Encryption',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'default' => 'tls',
                'options' => [
                    'tls' => 'TLS',
                    'ssl' => 'SSL',
                    'none' => 'None',
                ],
                'span' => 4,
                'sort' => 60,
            ],
            'from_address' => [
                'label' => 'From address',
                'type' => self::TYPE_EMAIL,
                'rules' => ['required', 'email:rfc', 'max:150'],
                'default' => 'no-reply@myoffice.test',
                'span' => 4,
                'sort' => 70,
            ],
            'from_name' => [
                'label' => 'From name',
                'type' => self::TYPE_TEXT,
                'rules' => ['required', 'string', 'max:150'],
                'default' => 'MyOffice ERP',
                'span' => 4,
                'sort' => 80,
            ],
            'reply_to' => [
                'label' => 'Reply-to address',
                'type' => self::TYPE_EMAIL,
                'rules' => ['nullable', 'email:rfc', 'max:150'],
                'default' => null,
                'help' => 'Leave empty to let replies go to the from address.',
                'span' => 6,
                'sort' => 90,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function collaboratorFields(): array
    {
        return [
            'referral_system_enabled' => [
                'label' => 'Referral tracking enabled',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off stops new attribution; existing referrals and commission are untouched.',
                'span' => 4,
                'sort' => 10,
            ],
            'automatic_commission_enabled' => [
                'label' => 'Calculate commission automatically',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Commission is only ever calculated from money actually received.',
                'span' => 4,
                'sort' => 20,
            ],
            'commission_approval_mode' => [
                'label' => 'Commission approval',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'default' => 'manual',
                'options' => [
                    'manual' => 'Manual — a person approves every commission',
                    'automatic' => 'Automatic — approve on calculation',
                ],
                'help' => 'Manual is the safe default: money does not become payable without a human.',
                'span' => 4,
                'sort' => 30,
            ],
            'student_commission_base' => [
                'label' => 'Student commission base',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'default' => 'paid',
                'options' => [
                    'gross' => 'Gross fee',
                    'net_after_discount' => 'Fee after discount',
                    'paid' => 'Amount actually received',
                ],
                'span' => 6,
                'sort' => 40,
            ],
            'project_commission_base' => [
                'label' => 'Project commission base',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'default' => 'paid',
                'options' => [
                    'total_value' => 'Total project value',
                    'net_after_discount' => 'Value after discount',
                    'paid' => 'Amount actually received',
                    'milestone' => 'Per approved milestone',
                ],
                'span' => 6,
                'sort' => 50,
            ],
            'default_student_commission_type' => [
                'label' => 'Default student commission type',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'default' => 'percentage',
                'options' => [
                    'percentage' => 'Percentage of the base',
                    'fixed' => 'Fixed amount',
                ],
                'span' => 3,
                'sort' => 60,
            ],
            'default_student_commission_rate' => [
                'label' => 'Default student commission rate',
                'type' => self::TYPE_DECIMAL,
                // decimal:0,4 — a plain decimal at the decimal(8,4) rate scale. `numeric` alone accepts
                // exponent notation ('1e3'), which bcmath cannot read. The 100 % ceiling while the type
                // is a percentage is SettingsRegistry::crossFieldErrors().
                'rules' => ['required', 'numeric', 'decimal:0,4', 'min:0'],
                'default' => '10.0000',
                'help' => 'A percentage when the type is percentage, an amount when it is fixed.',
                'span' => 3,
                'sort' => 70,
            ],
            'default_project_commission_type' => [
                'label' => 'Default project commission type',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'default' => 'percentage',
                'options' => [
                    'percentage' => 'Percentage of the base',
                    'fixed' => 'Fixed amount',
                ],
                'span' => 3,
                'sort' => 80,
            ],
            'default_project_commission_rate' => [
                'label' => 'Default project commission rate',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['required', 'numeric', 'decimal:0,4', 'min:0'],
                'default' => '5.0000',
                'help' => 'A percentage when the type is percentage, an amount when it is fixed.',
                'span' => 3,
                'sort' => 90,
            ],
            'commission_on_admission_fee' => [
                'label' => 'Pay commission on the admission fee',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'span' => 4,
                'sort' => 100,
            ],
            'commission_on_registration_fee' => [
                'label' => 'Pay commission on the registration fee',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'span' => 4,
                'sort' => 110,
            ],
            'commission_reversal_on_refund' => [
                'label' => 'Reverse commission when a payment is refunded',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'A reversal is always a new negative entry referencing the original; nothing is deleted.',
                'span' => 4,
                'sort' => 120,
            ],
            'payout_request_enabled' => [
                'label' => 'Collaborators may request payouts',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'span' => 4,
                'sort' => 130,
            ],
            'minimum_payout' => [
                'label' => 'Minimum payout',
                'type' => self::TYPE_DECIMAL,
                // decimal:0,2 — a plain amount at money scale that fits decimal(15,2); never '1e3'.
                'rules' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
                'default' => '1000.00',
                'help' => 'A payout request below this amount is refused.',
                'span' => 4,
                'sort' => 140,
            ],
            'payout_methods' => [
                'label' => 'Accepted payout methods',
                'type' => self::TYPE_MULTISELECT,
                'rules' => ['required', 'array', 'min:1'],
                'item_rules' => [
                    '*' => ['string', 'in:bank,easypaisa,jazzcash,cash,other'],
                ],
                'default' => ['bank', 'easypaisa', 'jazzcash'],
                'options' => [
                    'bank' => 'Bank transfer',
                    'easypaisa' => 'Easypaisa',
                    'jazzcash' => 'JazzCash',
                    'cash' => 'Cash',
                    'other' => 'Other',
                ],
                'span' => 12,
                'sort' => 150,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function instituteFields(): array
    {
        return [
            'admission_open' => [
                'label' => 'Admissions are open',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off hides the public admission form and the "apply" calls to action.',
                'public' => true,
                'span' => 4,
                'sort' => 10,
            ],
            'default_branch_id' => [
                'label' => 'Default branch',
                'type' => self::TYPE_SELECT,
                'rules' => ['nullable', 'integer', 'exists:branches,id'],
                'default' => null,
                'options' => [self::class, 'branchOptions'],
                'help' => 'Preselected on new admissions and batches.',
                'span' => 4,
                'sort' => 20,
            ],
            'student_id_prefix' => [
                'label' => 'Student ID prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'STD',
                'help' => 'Letters, digits, hyphen and slash only. Issued numbers are never renumbered.',
                'span' => 4,
                'sort' => 30,
            ],
            'registration_number_format' => [
                'label' => 'Registration number format',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:64'],
                'default' => '{prefix}-{year}-{seq}',
                'help' => 'Tokens: {prefix}, {year}, {month}, {branch}, {seq}.',
                'span' => 6,
                'sort' => 40,
            ],
            'fee_receipt_prefix' => [
                'label' => 'Fee receipt prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'FEE',
                'span' => 3,
                'sort' => 50,
            ],
            'certificate_prefix' => [
                'label' => 'Certificate prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'CERT',
                'span' => 3,
                'sort' => 60,
            ],
            'certificate_verification_url' => [
                'label' => 'Certificate verification URL',
                'type' => self::TYPE_URL,
                'rules' => ['nullable', 'url', 'max:190'],
                'default' => null,
                'help' => 'Printed on certificates and encoded into the QR code. An issued certificate keeps the URL it was issued with.',
                'placeholder' => 'https://www.example.com/verify',
                'public' => true,
                'span' => 6,
                'sort' => 70,
            ],
            'attendance_grace_minutes' => [
                'label' => 'Attendance grace period',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:120'],
                'default' => 10,
                'help' => 'Arriving inside the grace period counts as present rather than late.',
                'suffix' => 'minutes',
                'span' => 4,
                'sort' => 80,
            ],
            'default_class_duration' => [
                'label' => 'Default class duration',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:15', 'max:480'],
                'default' => 90,
                'suffix' => 'minutes',
                'span' => 4,
                'sort' => 90,
            ],
            'installment_reminder_days' => [
                'label' => 'Installment reminder lead time',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:60'],
                'default' => 3,
                'help' => 'How many days before an installment is due the reminder goes out. 0 disables it.',
                'suffix' => 'days',
                'span' => 4,
                'sort' => 100,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function financeFields(): array
    {
        return [
            'invoice_prefix' => [
                'label' => 'Invoice prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'INV',
                'span' => 3,
                'sort' => 10,
            ],
            /*
            | D62 — a document counter is never an admin-editable setting. Shown for information
            | only: readonly renders the input disabled (so the form never posts it), the Form
            | Request refuses it by name, SettingsService refuses any change and resetGroup() skips
            | it. Only DocumentNumberService (Phase 5, D27) advances it, under a row lock. Every
            | `*_next_number` key any later phase declares MUST carry `readonly => true` —
            | SettingsRegistryTest and SettingsDocumentCounterTest assert it for all of them.
            */
            'invoice_next_number' => [
                'label' => 'Next invoice number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1'],
                'default' => 1,
                'help' => 'Advanced automatically when an invoice is issued; a number is assigned once and never reused. Shown for information only.',
                'readonly' => true,
                'span' => 3,
                'sort' => 20,
            ],
            'tax_enabled' => [
                'label' => 'Charge tax on invoices',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'span' => 3,
                'sort' => 30,
            ],
            'tax_label' => [
                'label' => 'Tax label',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:32'],
                'default' => 'GST',
                'help' => 'Printed on the invoice beside the tax amount.',
                'span' => 3,
                'sort' => 40,
            ],
            'default_tax_rate' => [
                'label' => 'Default tax rate',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
                'default' => '0.0000',
                'suffix' => '%',
                'span' => 3,
                'sort' => 50,
            ],
            'payment_terms_days' => [
                'label' => 'Payment terms',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:365'],
                'default' => 14,
                'help' => 'Days added to the issue date to get the due date.',
                'suffix' => 'days',
                'span' => 3,
                'sort' => 60,
            ],
            'bank_details' => [
                'label' => 'Bank details',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:1000'],
                'default' => null,
                'help' => 'Printed on invoices so a client can pay by transfer.',
                'span' => 6,
                'sort' => 70,
            ],
            'invoice_footer_note' => [
                'label' => 'Invoice footer note',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:1000'],
                'default' => null,
                'span' => 6,
                'sort' => 80,
            ],
            'expense_approval_required' => [
                'label' => 'Expenses need approval',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'span' => 6,
                'sort' => 90,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function securityFields(): array
    {
        return [
            'password_min_length' => [
                'label' => 'Minimum password length',
                'type' => self::TYPE_NUMBER,
                // min:10 — PasswordPolicy::MIN_LENGTH is the floor; a smaller value would be saved
                // and silently mean 10.
                'rules' => ['required', 'integer', 'min:10', 'max:64'],
                'default' => 10,
                'help' => 'Applies to every new password a person sets from now on (PasswordPolicy). Never below 10.',
                'suffix' => 'characters',
                'span' => 4,
                'sort' => 10,
            ],
            'force_password_change_days' => [
                'label' => 'Force a password change every',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:365'],
                'default' => 0,
                'help' => 'Not enforced yet: password expiry arrives in a later phase. Read-only until then, so nobody relies on a protection that is not there.',
                'readonly' => true,
                'suffix' => 'days',
                'span' => 4,
                'sort' => 20,
            ],
            'login_max_attempts' => [
                'label' => 'Failed sign-in attempts allowed',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:20'],
                'default' => 5,
                'help' => 'Per email address and IP. Enforced by the sign-in form.',
                'span' => 4,
                'sort' => 30,
            ],
            'lockout_minutes' => [
                'label' => 'Lockout duration',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:1440'],
                'default' => 15,
                'help' => 'How long the sign-in form refuses an email address and IP after the allowed attempts.',
                'suffix' => 'minutes',
                'span' => 4,
                'sort' => 40,
            ],
            'session_lifetime' => [
                'label' => 'Session lifetime',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:5', 'max:43200'],
                'default' => 120,
                'help' => 'Idle minutes before a session expires.',
                'suffix' => 'minutes',
                'span' => 4,
                'sort' => 50,
            ],
            'allowed_file_types' => [
                'label' => 'Allowed upload types',
                'type' => self::TYPE_TEXT,
                'rules' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9]+(,[A-Za-z0-9]+)*$/'],
                'default' => 'pdf,doc,docx,xls,xlsx,csv,png,jpg,jpeg,webp,zip',
                'help' => 'Not enforced yet: every upload field validates its own types (D21). Read-only until a shared upload policy reads it.',
                'readonly' => true,
                'span' => 8,
                'sort' => 60,
            ],
            'max_upload_mb' => [
                'label' => 'Maximum upload size',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:512'],
                'default' => 10,
                'help' => 'The web server’s own limit still applies and wins when it is lower.',
                'suffix' => 'MB',
                'span' => 4,
                'sort' => 70,
            ],
            'two_factor_enabled' => [
                'label' => 'Two-factor authentication',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Declared now, enforced in a later phase. Read-only until the flow exists, so nobody switches on a protection that is not there.',
                'readonly' => true,
                'span' => 6,
                'sort' => 80,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function maintenanceFields(): array
    {
        return [
            'maintenance_mode' => [
                'label' => 'Maintenance mode',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Shows the holding page on the public site. The admin panel and the four portals are never blocked.',
                'public' => true,
                'span' => 4,
                'sort' => 10,
            ],
            'maintenance_message' => [
                'label' => 'Maintenance message',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:1000'],
                'default' => 'We are performing scheduled maintenance. Please check back shortly.',
                'public' => true,
                'span' => 12,
                'sort' => 20,
            ],
            'public_site_enabled' => [
                'label' => 'Public website enabled',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off returns the holding page for every public URL.',
                'public' => true,
                'span' => 4,
                'sort' => 30,
            ],
            'admission_form_enabled' => [
                'label' => 'Online admission form enabled',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'public' => true,
                'span' => 4,
                'sort' => 40,
            ],
            'contact_form_enabled' => [
                'label' => 'Contact form enabled',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'public' => true,
                'span' => 4,
                'sort' => 50,
            ],
        ];
    }
}
