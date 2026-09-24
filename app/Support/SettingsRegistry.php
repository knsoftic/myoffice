<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Cms\SitemapChangeFrequency;
use App\Enums\CourseResourceType;
use App\Enums\FixedCommissionRelease;
use App\Enums\ProgressBasis;
use App\Enums\RemainderPlacement;
use App\Enums\SearchEntityType;
use App\Enums\StudentFeeType;
use App\Enums\ThemePreference;
use App\Enums\Weekday;
use App\Models\Branch;
use App\Models\User;
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
 * | `scale`       | decimals a `decimal` field is stored at; more significant decimals are refused, never rounded (self::scaleFor()); null otherwise |
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

    /*
    |--------------------------------------------------------------------------
    | Security floors (Phase 2 security re-review)
    |--------------------------------------------------------------------------
    |
    | Hard server bounds for the keys that govern the sign-in throttle, the session idle timeout and
    | password expiry. A setting may TIGHTEN a Phase 1 floor, never loosen it: the registry rules
    | refuse a value outside the range, and every reader clamps to the same range again
    | (`LoginRequest`, `ConfigureFromSettings::applySecurity()`, `EnsureUserIsActive`), exactly as
    | `PasswordPolicy::minLength()` clamps `security.password_min_length`. The second lock is what
    | holds when a row is written by raw SQL, an older release or a console command.
    |
    */

    /** Failed sign-in attempts per email + IP: never more than 10, never fewer than 3. */
    public const LOGIN_MAX_ATTEMPTS_MIN = 3;

    public const LOGIN_MAX_ATTEMPTS_MAX = 10;

    /** Lockout after the allowed attempts: at least 5 minutes, at most a day. */
    public const LOCKOUT_MINUTES_MIN = 5;

    public const LOCKOUT_MINUTES_MAX = 1440;

    /** Idle session lifetime: at least 15 minutes, never more than a day. */
    public const SESSION_LIFETIME_MIN = 15;

    public const SESSION_LIFETIME_MAX = 1440;

    /** Password expiry in days; 0 switches expiry off. */
    public const FORCE_PASSWORD_CHANGE_DAYS_MAX = 365;

    /** The ceiling `security.max_upload_mb` itself may be set to. */
    public const MAX_UPLOAD_MB_MAX = 512;

    /**
     * Extensions no upload field ever accepts, whatever `security.allowed_file_types` says and
     * whatever a field's own rules say: server-side script, executables, server configuration and
     * markup a browser would run in the application's own origin. Matched against every segment of
     * an uploaded file name (`logo.php.png`) by `SettingsService`, and stripped from the allowed list
     * by `uploadExtensions()`.
     *
     * `svg` is deliberately absent: no field in this registry accepts it (see brandingFields()).
     *
     * @var list<string>
     */
    public const NEVER_UPLOADABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'pht', 'phar', 'pgif', 'inc',
        'asp', 'aspx', 'jsp', 'jspx', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh',
        'bat', 'cmd', 'com', 'exe', 'dll', 'so', 'jar', 'msi', 'ps1', 'vbs', 'wsf',
        'htaccess', 'htpasswd', 'ini', 'html', 'htm', 'shtml', 'xhtml', 'js', 'mjs', 'cjs',
    ];

    /**
     * The seven day keys `contact.business_hours` holds — exactly these, no more and no fewer.
     *
     * @var list<string>
     */
    public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * Refuses any ASCII control character — CR, LF, NUL, TAB and the rest — in a value that ends up
     * in a mail header. Probed against Laravel 12's real Validator: `email:rfc` already refuses a
     * CRLF fold, but it ACCEPTS a quoted-pair carrying a bare CR or LF (`"ops\<CR>"@example.test`)
     * and a NUL byte (even under `email:rfc,strict`), so the rule is explicit rather than assumed.
     */
    private const NO_CONTROL_CHARACTERS = 'not_regex:/[\x00-\x1F\x7F]/';

    /**
     * A plain decimal Money can read: optional sign, digits with an optional (possibly empty)
     * fraction, or a bare fraction — `5000.`, `.5`, `-0`, `12.50`. Exponents, thousand separators and
     * embedded spaces are deliberately NOT matched: they reach the validator untouched and are
     * refused there, rather than being "helpfully" reinterpreted (`5,5` must never become `55`).
     */
    private const PLAIN_DECIMAL = '/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/';

    /** Exponent magnitude beyond which a numeric string is treated as unboundedly large or small. */
    private const EXPONENT_LIMIT = 40;

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
            // phase-03 §5.1: declared once here; Phase 4 appends its 21 keys to websiteFields() (§5.1b).
            'website' => [
                'label' => 'Website & Forms',
                'icon' => 'globe-alt',
                'description' => 'Public-site caching, preview links, image processing, revision history and what the public pages show.',
                'sort' => 75,
            ],
            'collaborator' => [
                'label' => 'Collaborators & commission',
                'icon' => 'user-group',
                'description' => 'Referral tracking, commission bases and rates, payout policy.',
                'sort' => 80,
            ],
            // phase-06 §5 [D-P6-8]: none of the existing groups is the right home for a timer limit or a
            // project code prefix, and hiding these in `finance` would make the settings screen lie about
            // what they control.
            'projects' => [
                'label' => 'Projects & Time',
                'icon' => 'rectangle-stack',
                'description' => 'Project numbering, progress, and time-tracking rules.',
                'sort' => 86,
            ],
            'institute' => [
                'label' => 'Institute',
                'icon' => 'academic-cap',
                'description' => 'Admissions, numbering, attendance and class defaults.',
                'sort' => 90,
            ],
            // phase-19-23 §5.2, [D-22-4]. Sort 95: directly after `institute` (90) and before
            // `finance` (100), because tickets, meetings and messaging are how the institute and the
            // software house talk to the people they serve — not a financial concern, and not a
            // security one.
            'support' => [
                'label' => 'Support, Meetings & Messaging',
                'icon' => 'lifebuoy',
                'description' => 'Ticket numbering, departments, SLA, meeting defaults, messaging rules and notification delivery.',
                'sort' => 95,
            ],
            'finance' => [
                'label' => 'Finance',
                'icon' => 'banknotes',
                'description' => 'Invoice numbering, tax, payment terms and expense approval.',
                'sort' => 100,
            ],
            // phase-07 §5 — one new group. `institute.attendance_grace_minutes` is the **student**
            // grace period and is deliberately not reused here: a late employee and a late student are
            // different business facts that happen to share a word.
            'hr' => [
                'label' => 'HR & Payroll',
                'icon' => 'users',
                'description' => 'Employee numbering, the working week, attendance tolerances, leave policy and how a salary is worked out.',
                'sort' => 105,
            ],
            'security' => [
                'label' => 'Security',
                'icon' => 'shield-check',
                'description' => 'Password policy, lockout, session lifetime and upload limits.',
                'sort' => 110,
            ],
            'crm' => [
                'label' => 'CRM',
                'icon' => 'briefcase',
                'description' => 'How leads are numbered, assigned, de-duplicated, chased and imported, and what a client may see in their own portal.',
                'sort' => 115,
            ],
            'maintenance' => [
                'label' => 'Maintenance',
                'icon' => 'wrench-screwdriver',
                'description' => 'Switch the public site, its forms and maintenance mode on or off. The admin panel is never affected.',
                'sort' => 120,
            ],
            // phase-19-23 5.3, [D-23-1]. Sort 125 - last, deliberately. Every other group
            // configures a part of the business; this one configures how the business is *read*.
            // It is the tab an administrator opens least often and the one whose defaults are
            // most often right, so it belongs at the end rather than competing with Finance.
            'reports' => [
                'label' => 'Reports & Search',
                'icon' => 'chart-bar',
                'description' => 'Report defaults, export limits and retention, the global search palette, and what the audit trail treats as sensitive.',
                'sort' => 125,
            ],
            // phase-24-25 5.1. Before Operations, because what a backup is worth is decided here
            // and whether anything is alive is decided there.
            'backup' => [
                'label' => 'Backup & Restore',
                'icon' => 'archive',
                'description' => 'Where archives are written, how often, how long they are kept, what is verified, and what an operator must type before a restore.',
                'sort' => 128,
            ],
            // phase-24-25 5.2. Last, after Reports: the tab an operator opens and an administrator
            // never does. Every key in it answers one of two questions - is the system alive, and
            // would anybody find out if it were not.
            'ops' => [
                'label' => 'Operations',
                'icon' => 'gauge',
                'description' => 'Health checks, heartbeats, slow queries, error reporting, log retention and the deployed version.',
                'sort' => 130,
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
     * The cross-field rules the per-field rules cannot express.
     *
     * Three of them, and each is here because a rule string genuinely cannot say it:
     *
     *   · a commission rate is a percentage — at most 100 — whenever its sibling `*_type` key says
     *     `percentage`, and an amount otherwise;
     *
     *   · the environment file may travel only in an encrypted archive (phase-24-25 §5.1, HD-8).
     *     `prohibited_unless` is the rule that looks right and is not: it refuses a field that is
     *     *present*, and an unticked checkbox posts `false`, which is present — so it refused every
     *     save of the backup form, ticked or not. What must be refused is the **value**;
     *
     *   · the restore scratch database may not be the live one. That comparison needs the
     *     connection's configured database name, which no static rule string can reach, and it is
     *     the one mistake in §6.2 that would be unrecoverable: the weekly restore proof drops the
     *     scratch database when it finishes.
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

            if (! is_scalar($typeValue) || (string) $typeValue !== $percentage) {
                continue;
            }

            // ANY numeric shape is compared — '5000.', '.5', '1e3', ' 150 ' — not only the shapes a
            // regex happened to anticipate. A trailing dot used to slip past the old pattern and
            // store a 5000 % rate; a value that is not numeric at all is left to the field's own
            // `numeric` / `decimal` rules.
            $comparable = self::decimalString($rateValue);

            if ($comparable === null || bccomp($comparable, '100', 10) <= 0) {
                continue;
            }

            $field = self::field($rateKey);

            $errors[in_array($rate, $submitted, true) ? $rate : $type] = sprintf(
                'The %s may not be greater than 100 while the commission type is a percentage.',
                mb_strtolower((string) ($field['label'] ?? $rate)),
            );
        }

        if ($group === 'backup') {
            $errors += self::backupCrossFieldErrors($effective, $submitted);
        }

        return $errors;
    }

    /**
     * phase-24-25 §5.1's two conditions. See the note on {@see self::crossFieldErrors()}.
     *
     * @param  callable(string): mixed  $effective
     * @param  list<string>  $submitted
     * @return array<string, string>
     */
    private static function backupCrossFieldErrors(callable $effective, array $submitted): array
    {
        $errors = [];

        $truthy = static fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN);

        // An .env holds the application key, the database password and the mail credentials. An
        // unencrypted archive carrying one turns every copy of that archive - including the offsite
        // copy - into the whole system.
        if (
            (in_array('include_env', $submitted, true) || in_array('encrypt_archives', $submitted, true))
            && $truthy($effective('include_env'))
            && ! $truthy($effective('encrypt_archives'))
        ) {
            $errors['include_env'] = 'The environment file may only be included in an encrypted archive. '
                .'Switch on "Encrypt archives" and set an archive password first.';
        }

        // The weekly restore proof restores into the scratch database and then drops it.
        if (in_array('restore_scratch_database', $submitted, true)) {
            $scratch = $effective('restore_scratch_database');
            $live = config('database.connections.'.config('database.default').'.database');

            if (is_string($scratch) && is_string($live) && strcasecmp(trim($scratch), trim($live)) === 0) {
                $errors['restore_scratch_database'] = 'The scratch database may not be the live database. '
                    .'The restore proof drops it when it finishes.';
            }
        }

        return $errors;
    }

    /*
    |--------------------------------------------------------------------------
    | Value shaping (shared by UpdateSettingsRequest and SettingsService)
    |--------------------------------------------------------------------------
    */

    /**
     * The number of decimals a `decimal` field is stored at.
     *
     * An explicit `scale` in the declaration wins; otherwise the upper bound of the field's own
     * `decimal:min,max` rule; otherwise Money's rate scale (4, matching `decimal(8,4)`).
     *
     * @param  array<string, mixed>  $field
     */
    public static function scaleFor(array $field): int
    {
        if (isset($field['scale']) && is_int($field['scale'])) {
            return max(0, $field['scale']);
        }

        foreach ((array) ($field['rules'] ?? []) as $rule) {
            if (is_string($rule) && preg_match('/^decimal:(\d+)(?:,(\d+))?$/', $rule, $matches) === 1) {
                return (int) ($matches[2] ?? $matches[1]);
            }
        }

        return Money::RATE_SCALE;
    }

    /**
     * Restate a submitted `decimal` value at its field's scale through Money — BEFORE it is
     * validated and before it is stored — so the validator, the 100 % cap and the stored column all
     * judge the one value that will actually be kept.
     *
     * Only an **exact** restatement is made; nothing is ever rounded (Phase 2 review low 1). A value
     * with more significant decimals than the scale would have to be rounded to be stored, and money
     * is never silently rounded, so it is returned exactly as given and refused by exceedsScale():
     *
     *   '5000.'      => '5000.0000'   (the cap now sees 5000 and refuses it)
     *   '100.50'     => '100.5000'
     *   '100.500000' => '100.5000'    (trailing zeros only — the same number)
     *   '-0'         => '0.0000'
     *   '100.00001'  => '100.00001'   (untouched: more decimals than decimal(8,4) holds — refused)
     *   '1e3'        => '1e3'         (untouched: not a plain decimal, so `decimal:` refuses it)
     *
     * Anything that is not a plain decimal (exponent, thousand separator, words, arrays) is returned
     * exactly as given, for the field's own rules to refuse. Non-decimal fields pass through.
     *
     * @param  array<string, mixed>  $field
     */
    public static function normaliseDecimal(array $field, mixed $value): mixed
    {
        if (! self::isDecimalField($field)) {
            return $value;
        }

        $plain = self::plainDecimal($value);

        // Phase 2 review low 1: restate exactly or not at all — never round.
        if ($plain === null || self::significantDecimals($plain) > self::scaleFor($field)) {
            return $value;
        }

        return Money::round($plain, self::scaleFor($field));
    }

    /**
     * Does a `decimal` value carry more significant decimals than its field's scale?
     *
     * True means the value could only be stored by rounding it, and money is never silently rounded
     * (Phase 2 review low 1): `UpdateSettingsRequest` and `SettingsService` both refuse it with a
     * validation error. Trailing zeros are not significant — `'100.500'` at scale 2 is exactly
     * 100.50 and is accepted. A value that is not a plain decimal (`'1e3'`, `'5,5'`, an array)
     * answers false: it is the field's own `numeric` / `decimal:` rules that refuse it.
     *
     * @param  array<string, mixed>  $field
     */
    public static function exceedsScale(array $field, mixed $value): bool
    {
        if (! self::isDecimalField($field)) {
            return false;
        }

        $plain = self::plainDecimal($value);

        return $plain !== null && self::significantDecimals($plain) > self::scaleFor($field);
    }

    /**
     * The one message both write paths give a value exceedsScale() refuses.
     *
     * @param  array<string, mixed>  $field
     */
    public static function scaleErrorMessage(array $field): string
    {
        $scale = self::scaleFor($field);

        return sprintf(
            'The %s may not have more than %d decimal place%s; it is never rounded.',
            mb_strtolower((string) ($field['label'] ?? $field['key'] ?? 'value')),
            $scale,
            $scale === 1 ? '' : 's',
        );
    }

    /**
     * Any numeric value as a plain decimal string bcmath can compare, or null when it is not
     * numeric. Accepts every shape `is_numeric()` does — a trailing or leading dot, surrounding
     * whitespace, a sign, exponent notation — and never routes the value through a float.
     *
     * An exponent beyond ±EXPONENT_LIMIT is folded to an out-of-range magnitude (or to zero), so a
     * crafted `1e999999` cannot make bcpow() spend the request.
     */
    public static function decimalString(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (is_nan($value)) {
                return null;
            }

            if (is_infinite($value)) {
                return ($value < 0 ? '-1' : '1').str_repeat('0', self::EXPONENT_LIMIT + 1);
            }

            return sprintf('%.10F', $value);
        }

        if (! is_string($value) || ! is_numeric($value)) {
            return null;
        }

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', trim($value), $parts) !== 1) {
            return null;
        }

        $sign = $parts[1] === '-' ? '-' : '';
        $integer = ltrim($parts[2], '0');
        $fraction = $parts[3] ?? '';
        $mantissa = $sign.($integer === '' ? '0' : $integer).'.'.($fraction === '' ? '0' : $fraction);

        if (bccomp($mantissa, '0', 50) === 0) {
            return '0';
        }

        $exponentDigits = ltrim(ltrim($parts[4] ?? '', '+-'), '0');
        $exponent = $exponentDigits === '' ? 0 : (strlen($exponentDigits) > 3 ? PHP_INT_MAX : (int) $exponentDigits);
        $exponent = str_starts_with($parts[4] ?? '', '-') ? -$exponent : $exponent;

        if ($exponent > self::EXPONENT_LIMIT) {
            return $sign.'1'.str_repeat('0', self::EXPONENT_LIMIT + 1);
        }

        if ($exponent < -self::EXPONENT_LIMIT) {
            return '0';
        }

        return bcmul($mantissa, bcpow('10', (string) $exponent, self::EXPONENT_LIMIT + 10), self::EXPONENT_LIMIT + 10);
    }

    /**
     * The extensions an upload field may accept: its own list, narrowed to what
     * `security.allowed_file_types` allows, never including a NEVER_UPLOADABLE extension.
     *
     * Pure — the caller reads the setting and passes it in. An unreadable or empty setting leaves
     * the field's own list (minus the never-uploadable ones) in force: the setting narrows, it can
     * never widen.
     *
     * @param  list<string>  $own  the field's own extensions, lower-case, without dots
     * @return list<string>
     */
    public static function uploadExtensions(array $own, mixed $allowedSetting): array
    {
        $never = self::NEVER_UPLOADABLE_EXTENSIONS;

        $own = array_values(array_unique(array_filter(
            array_map(static fn (mixed $extension): string => strtolower(trim((string) $extension)), $own),
            static fn (string $extension): bool => $extension !== '' && ! in_array($extension, $never, true) && preg_match('/^php\d*$/', $extension) !== 1,
        )));

        if (! is_string($allowedSetting) || trim($allowedSetting) === '') {
            return $own;
        }

        $allowed = array_map(
            static fn (string $extension): string => strtolower(trim($extension)),
            explode(',', $allowedSetting),
        );

        return array_values(array_intersect($own, $allowed));
    }

    /**
     * The largest upload a field may accept, in kilobytes: the smallest of the field's own cap,
     * `security.max_upload_mb`, and PHP's own `upload_max_filesize` / `post_max_size` — whatever the
     * setting says, a value above what PHP will accept is never promised.
     */
    public static function uploadKilobytes(int $ownKilobytes, mixed $maxUploadMb): int
    {
        $caps = [max(1, $ownKilobytes)];

        if (is_numeric($maxUploadMb) && (int) $maxUploadMb >= 1) {
            $caps[] = min(self::MAX_UPLOAD_MB_MAX, (int) $maxUploadMb) * 1024;
        }

        $php = self::phpUploadLimitKilobytes();

        if ($php !== null) {
            $caps[] = $php;
        }

        return max(1, min($caps));
    }

    /**
     * PHP's effective per-file upload ceiling in kilobytes, or null when neither directive sets one.
     */
    public static function phpUploadLimitKilobytes(): ?int
    {
        $limits = [];

        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $bytes = self::iniBytes((string) ini_get($directive));

            // post_max_size = 0 disables the limit; a non-positive value sets no ceiling here.
            if ($bytes !== null && $bytes > 0) {
                $limits[] = intdiv($bytes, 1024);
            }
        }

        return $limits === [] ? null : max(1, min($limits));
    }

    /**
     * '2M' => 2097152, '512K' => 524288, '1G' => 1073741824, '8388608' => 8388608.
     */
    private static function iniBytes(string $value): ?int
    {
        $value = strtolower(trim($value));

        if (preg_match('/^(\d+)\s*([kmg]?)$/', $value, $matches) !== 1) {
            return null;
        }

        $number = (int) $matches[1];

        return match ($matches[2]) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
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
     * @param  array<string, mixed>  $field
     */
    private static function isDecimalField(array $field): bool
    {
        return ($field['storage'] ?? self::storageType((string) ($field['type'] ?? ''))) === 'decimal';
    }

    /**
     * A value as a trimmed PLAIN_DECIMAL string, or null when it is not one.
     *
     * A float is read as the shortest string that converts back to it (`1000.01`, not
     * `1000.0099999999`), so a programmatic float is judged on the number its caller wrote rather
     * than on binary noise that would make exceedsScale() refuse it. A float that only prints with an
     * exponent (`1.0E-5`) is not a plain decimal, exactly like the string `'1e3'`: it is left for the
     * field's own `decimal:` rule to refuse, never expanded and rounded.
     */
    private static function plainDecimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            $value = is_finite($value) ? var_export($value, true) : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' && preg_match(self::PLAIN_DECIMAL, $trimmed) === 1 ? $trimmed : null;
    }

    /**
     * Decimals that carry a value: '100.500' => 2, '5000.' => 0, '-0.000' => 0.
     */
    private static function significantDecimals(string $plain): int
    {
        $dot = strpos($plain, '.');

        return $dot === false ? 0 : strlen(rtrim(substr($plain, $dot + 1), '0'));
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
        $storage = self::storageType($type);
        $rules = array_values((array) ($field['rules'] ?? ['nullable', 'string']));

        return [
            'key' => $key,
            'group' => $group,
            'label' => (string) ($field['label'] ?? ucfirst(str_replace('_', ' ', $key))),
            'type' => $type,
            'storage' => $storage,
            'scale' => $storage === 'decimal' ? self::scaleFor(['scale' => $field['scale'] ?? null, 'rules' => $rules]) : null,
            'rules' => $rules,
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
     * The seven weekdays, in the order the configured week start puts them.
     *
     * Reading `Weekday::ordered()` rather than listing them keeps one definition of "the week" —
     * the calendar's columns, the working-days picker and the clash predicates all read it.
     *
     * @return array<string, string>
     */
    public static function weekdayOptions(): array
    {
        $options = [];

        foreach (Weekday::ordered() as $day) {
            $options[$day->value] = $day->label();
        }

        return $options;
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

    /**
     * Active accounts for `website.inquiry_default_assignee_id` (phase-04 §5). Never throws: an install with no
     * users table yet gets an empty list.
     *
     * @return array<int|string, string>
     */
    public static function userOptions(): array
    {
        try {
            /** @var array<int|string, string> $options */
            $options = User::query()
                ->active()
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
            'website' => self::websiteFields(),
            'collaborator' => self::collaboratorFields(),
            'institute' => self::instituteFields(),
            'support' => self::supportFields(),
            'finance' => self::financeFields(),
            'security' => self::securityFields(),
            'projects' => self::projectsFields(),
            'hr' => self::hrFields(),
            'crm' => self::crmFields(),
            'maintenance' => self::maintenanceFields(),
            'reports' => self::reportsFields(),
            'backup' => self::backupFields(),
            'ops' => self::opsFields(),
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
            /*
            | email_logo and login_background are PUBLIC artefacts by nature: a recipient's mail
            | client fetches the email logo from a plain URL, and the sign-in background is painted
            | for a visitor who is, by definition, not signed in. Neither can pass a permission check,
            | so both live on the public disk like the logo and favicon. D21 forbids a PRIVATE
            | artefact on the public disk; it does not forbid a public one. Before this they were
            | stored on the private disk, their previews 404'd and nothing could ever serve them.
            */
            'email_logo' => [
                'label' => 'Email logo',
                'type' => self::TYPE_IMAGE,
                'rules' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:1024'],
                'default' => null,
                'help' => 'PNG or JPG — mail clients do not render SVG. Publicly reachable, so a mail client can load it. Takes effect when email notifications ship; nothing sends email with it yet.',
                'public' => true,
                'span' => 4,
                'sort' => 50,
            ],
            'login_background' => [
                'label' => 'Sign-in background',
                'type' => self::TYPE_IMAGE,
                'rules' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
                'default' => null,
                'help' => 'Shown beside the sign-in form, so it is publicly reachable.',
                'public' => true,
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
                'help' => 'The theme the sign-in and password-reset pages open in, and the starting theme for any browser that has not chosen one. A signed-in user\'s own theme always wins.',
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
                // The same 10..100 bounds per_page() clamps to, so the screen never accepts a size
                // that is silently ignored.
                'rules' => ['required', 'integer', 'min:10', 'max:100'],
                'default' => 15,
                'help' => 'Default pagination size for list screens, between 10 and 100.',
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
                'rules' => ['nullable', 'email:rfc', self::NO_CONTROL_CHARACTERS, 'max:150'],
                'default' => 'info@myoffice.test',
                'public' => true,
                'span' => 6,
                'sort' => 40,
            ],
            'support_email' => [
                'label' => 'Support email',
                'type' => self::TYPE_EMAIL,
                'rules' => ['nullable', 'email:rfc', self::NO_CONTROL_CHARACTERS, 'max:150'],
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
            // scale 7 (about a centimetre): more decimals are refused, never rounded, and
            // `decimal:0,7` refuses what Money cannot read (`1e1`), as on the money fields.
            'latitude' => [
                'label' => 'Latitude',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['nullable', 'numeric', 'decimal:0,7', 'between:-90,90'],
                'scale' => 7,
                'default' => null,
                'placeholder' => '31.5204',
                'public' => true,
                'span' => 3,
                'sort' => 90,
            ],
            'longitude' => [
                'label' => 'Longitude',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['nullable', 'numeric', 'decimal:0,7', 'between:-180,180'],
                'scale' => 7,
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
                // Exactly the seven weekday keys, each row exactly open / close / closed. `max:7`
                // alone capped the row count but not the key names, so seven rows under ~140 KB keys
                // still pushed the one cached settings payload past max_allowed_packet — and when that
                // cache write failed, every setting silently read as its default.
                'rules' => [
                    'nullable',
                    'array:'.implode(',', self::WEEKDAYS),
                    'required_array_keys:'.implode(',', self::WEEKDAYS),
                    'max:7',
                ],
                'item_rules' => [
                    '*' => ['array:open,close,closed', 'required_array_keys:open,close,closed'],
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

            // phase-03 §5.2 — read server-side by SeoService, never by a public view (all non-public).
            'robots_txt_mode' => [
                'label' => 'robots.txt',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'options' => ['auto' => 'Generated automatically', 'custom' => 'Custom text'],
                'default' => 'auto',
                'help' => 'Whichever is chosen, crawlers are told to stay away while the site is closed or not indexable.',
                'span' => 6,
                'sort' => 120,
            ],
            'robots_txt_custom' => [
                'label' => 'Custom robots.txt',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:5000'],
                'default' => null,
                'help' => 'Used only in custom mode. A Sitemap: line pointing at another domain is dropped when the file is served.',
                'span' => 12,
                'sort' => 130,
            ],
            'sitemap_changefreq_default' => [
                'label' => 'Default change frequency',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'options' => SitemapChangeFrequency::options(),
                'default' => SitemapChangeFrequency::Weekly->value,
                'help' => 'Stamped on each new SEO record.',
                'span' => 6,
                'sort' => 140,
            ],
            'sitemap_priority_default' => [
                'label' => 'Default sitemap priority',
                'type' => self::TYPE_DECIMAL,
                // decimal(2,1) in seo_meta.sitemap_priority: one decimal, 0.0 to 1.0, never '1e0'.
                'rules' => ['required', 'numeric', 'decimal:0,1', 'min:0', 'max:1'],
                'scale' => 1,
                'default' => '0.5',
                'help' => 'Stamped on each new SEO record.',
                'span' => 6,
                'sort' => 150,
            ],
        ];
    }

    /**
     * phase-03 §5.1a — the 13 keys Phase 3 declares. Phase 4's 21 keys (§5.1b) follow them; 34 in all.
     * No second `website` group is declared (F-6.3).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function websiteFields(): array
    {
        return [
            'cache_enabled' => [
                'label' => 'Cache public pages',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Anonymous visitors are served a stored copy. Publishing anything invalidates every copy at once.',
                'span' => 6,
                'sort' => 10,
            ],
            'cache_ttl_minutes' => [
                'label' => 'Cached page lifetime',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:10080'],
                'default' => 1440,
                'suffix' => 'minutes',
                'help' => 'Publishing clears the cache immediately, whatever this is set to.',
                'span' => 6,
                'sort' => 20,
            ],
            'cache_warm_enabled' => [
                'label' => 'Re-render pages after a publish',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                // Review round 2 (phase-03 §13.4 "an honest setting beats a lying one"): the warm-up job
                // (§10.2 WarmPublicPageCache) has not shipped, so the switch changes nothing. Readonly
                // until it does; then drop this flag and the "Not active yet" help.
                'help' => 'Not active yet: pages are re-rendered by the first visitor after a publish. Publishing always clears the cache.',
                'readonly' => true,
                'span' => 6,
                'sort' => 30,
            ],
            'preview_ttl_minutes' => [
                'label' => 'Shareable preview link lifetime',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:5', 'max:10080'],
                'default' => 120,
                'suffix' => 'minutes',
                'span' => 6,
                'sort' => 40,
            ],
            'image_quality' => [
                'label' => 'Image quality',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:60', 'max:95'],
                'default' => 82,
                'help' => '60 to 95. Applies to image sizes generated from now on.',
                'span' => 6,
                'sort' => 50,
            ],
            'image_max_width' => [
                'label' => 'Largest stored image width',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:320', 'max:8000'],
                'default' => 2560,
                'suffix' => 'px',
                'help' => 'Wider uploads are scaled down. Nothing is ever scaled up.',
                'span' => 6,
                'sort' => 60,
            ],
            'image_webp_enabled' => [
                'label' => 'Also generate WebP images',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'public' => true,
                'span' => 6,
                'sort' => 70,
            ],
            'image_lazy_loading' => [
                'label' => 'Lazy-load images below the hero',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'public' => true,
                'span' => 6,
                'sort' => 80,
            ],
            'menu_max_depth' => [
                'label' => 'Menu depth',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'in:2'],
                'default' => 2,
                'help' => 'Fixed at two levels by a database constraint (INV-6). Shown for information; it cannot be raised.',
                'readonly' => true,
                'span' => 6,
                'sort' => 90,
            ],
            'revision_keep' => [
                'label' => 'Draft revisions kept per item',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:500'],
                'default' => 20,
                // Review round 2 (phase-03 §13.4): revision pruning (§10.4 PruneCmsRevisions /
                // cms:prune-revisions) has not shipped, so nothing reads this yet. Readonly until it does.
                'help' => 'Not active yet: every revision is kept until revision pruning ships. Published versions are always kept.',
                'readonly' => true,
                'span' => 6,
                'sort' => 100,
            ],
            'hero_video_enabled' => [
                'label' => 'Allow a hero background video',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'public' => true,
                'help' => 'Off shows the poster image everywhere, which saves visitors mobile data.',
                'span' => 6,
                'sort' => 110,
            ],
            'faq_accordion_open_first' => [
                'label' => 'Open the first FAQ answer',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'public' => true,
                'span' => 6,
                'sort' => 120,
            ],
            'show_theme_toggle' => [
                'label' => 'Show the light/dark switch on the public site',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'public' => true,
                'span' => 6,
                'sort' => 130,
            ],
            // phase-04 §5 — the 21 keys Phase 4 contributes into this group (phase-03 §5.1b). 13 + 21 = 34.
            'services_per_page' => [
                'label' => 'Services per page',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:3', 'max:48'],
                'default' => 12,
                'public' => true,
                'span' => 4,
                'sort' => 140,
            ],
            'portfolio_per_page' => [
                'label' => 'Portfolio items per page',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:3', 'max:48'],
                'default' => 12,
                'public' => true,
                'span' => 4,
                'sort' => 150,
            ],
            'blog_per_page' => [
                'label' => 'Blog posts per page',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:3', 'max:48'],
                'default' => 9,
                'public' => true,
                'span' => 4,
                'sort' => 160,
            ],
            'blog_related_count' => [
                'label' => 'Related posts under an article',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:6'],
                'default' => 3,
                'help' => '0 hides the related-posts block.',
                'public' => true,
                'span' => 4,
                'sort' => 170,
            ],
            'blog_view_dedupe_minutes' => [
                'label' => 'Count a repeat view after',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:5', 'max:10080'],
                'default' => 1440,
                'suffix' => 'minutes',
                'help' => 'The same reader inside this window counts once. 1440 is one calendar day.',
                'span' => 4,
                'sort' => 180,
            ],
            'blog_view_prune_days' => [
                'label' => 'Keep daily view rows for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:7', 'max:730'],
                'default' => 90,
                'suffix' => 'days',
                'help' => 'Older rows are pruned nightly. The lifetime view counter is never reduced.',
                'span' => 4,
                'sort' => 190,
            ],
            'reviews_per_page' => [
                'label' => 'Testimonials and reviews per page',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:3', 'max:48'],
                'default' => 12,
                'public' => true,
                'span' => 4,
                'sort' => 200,
            ],
            'team_page_enabled' => [
                'label' => 'Show the team page',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off makes /team a 404.',
                'public' => true,
                'span' => 4,
                'sort' => 210,
            ],
            'portfolio_detail_enabled' => [
                'label' => 'Portfolio detail pages',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off keeps the grid but links nowhere, and /portfolio/{slug} is a 404.',
                'public' => true,
                'span' => 4,
                'sort' => 220,
            ],
            'testimonial_auto_approve' => [
                'label' => 'Approve staff-entered testimonials automatically',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Public and panel submissions always wait for approval, whatever this says.',
                'span' => 4,
                'sort' => 230,
            ],
            'careers_enabled' => [
                'label' => 'Careers page and applications',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off makes /careers a 404 and refuses applications.',
                'public' => true,
                'span' => 4,
                'sort' => 240,
            ],
            'careers_notify_emails' => [
                'label' => 'Mail new applications to',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:2000', 'regex:/^[\s,]*(?:[^@\s,;]+@[^@\s,;]+\.[A-Za-z]{2,}[\s,]*)*$/'],
                'default' => null,
                'help' => 'One address per line. The CV is never attached.',
                'span' => 6,
                'sort' => 250,
            ],
            'cv_max_mb' => [
                'label' => 'Largest CV upload',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:10'],
                'default' => 5,
                'suffix' => 'MB',
                'help' => 'Never above Security → largest upload.',
                'span' => 3,
                'sort' => 260,
            ],
            'cv_allowed_types' => [
                'label' => 'Accepted CV formats',
                'type' => self::TYPE_MULTISELECT,
                'rules' => ['required', 'array', 'min:1'],
                'item_rules' => [
                    '*' => ['string', 'distinct', 'in:pdf,doc,docx'],
                ],
                'default' => ['pdf', 'doc', 'docx'],
                'options' => [
                    'pdf' => 'PDF',
                    'doc' => 'Word (.doc)',
                    'docx' => 'Word (.docx)',
                ],
                'span' => 3,
                'sort' => 270,
            ],
            'contact_notify_emails' => [
                'label' => 'Mail new inquiries to',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:2000', 'regex:/^[\s,]*(?:[^@\s,;]+@[^@\s,;]+\.[A-Za-z]{2,}[\s,]*)*$/'],
                'default' => null,
                'help' => 'One address per line, in addition to staff who can see every inquiry.',
                'span' => 6,
                'sort' => 280,
            ],
            'contact_budget_options' => [
                'label' => 'Budget choices on the contact form',
                'type' => self::TYPE_JSON,
                'rules' => ['nullable', 'array', 'max:12'],
                'item_rules' => [
                    '*' => ['string', 'max:100'],
                ],
                'default' => ['Under 50,000', '50,000 – 150,000', '150,000 – 500,000', '500,000 – 1,000,000', 'Above 1,000,000', 'Not sure yet'],
                'help' => 'A JSON list of labels. The chosen label is stored as typed, so editing the list never rewrites an inquiry.',
                'public' => true,
                'span' => 6,
                'sort' => 290,
            ],
            'contact_min_submit_seconds' => [
                'label' => 'Faster than this is a bot',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:60'],
                'default' => 3,
                'suffix' => 'seconds',
                'span' => 3,
                'sort' => 300,
            ],
            'contact_rate_per_hour' => [
                'label' => 'Contact submissions per hour per visitor',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:200'],
                'default' => 20,
                'span' => 3,
                'sort' => 310,
            ],
            'spam_blocklist' => [
                'label' => 'Spam words and domains',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:5000'],
                'default' => null,
                'help' => 'One word or domain per line, matched without regard to case against the subject and the message.',
                'span' => 6,
                'sort' => 320,
            ],
            'inquiry_auto_route' => [
                'label' => 'Route inquiries automatically',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off leaves every inquiry waiting for a manual "Route now".',
                'span' => 3,
                'sort' => 330,
            ],
            'inquiry_default_assignee_id' => [
                'label' => 'Assign new inquiries to',
                'type' => self::TYPE_SELECT,
                'rules' => ['nullable', 'integer', 'exists:users,id'],
                'default' => null,
                'options' => [self::class, 'userOptions'],
                'help' => 'Optional. Gives every new inquiry an owner, so reviewers without the full queue still see it.',
                'span' => 3,
                'sort' => 340,
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
                'rules' => ['required', 'email:rfc', self::NO_CONTROL_CHARACTERS, 'max:150'],
                'default' => 'no-reply@myoffice.test',
                'span' => 4,
                'sort' => 70,
            ],
            'from_name' => [
                'label' => 'From name',
                'type' => self::TYPE_TEXT,
                // A header value: no CR / LF / NUL, whatever the mail library does with one.
                'rules' => ['required', 'string', self::NO_CONTROL_CHARACTERS, 'max:150'],
                'default' => 'MyOffice ERP',
                'span' => 4,
                'sort' => 80,
            ],
            'reply_to' => [
                'label' => 'Reply-to address',
                'type' => self::TYPE_EMAIL,
                'rules' => ['nullable', 'email:rfc', self::NO_CONTROL_CHARACTERS, 'max:150'],
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

            /*
            |------------------------------------------------------------------
            | phase-08-09 §5 — the sixteen keys the collaborator record and the
            | referral machinery need. No new group, and no Phase 2 or spine key
            | is redefined: the thirteen above and the spine's ten are used as
            | they are defined.
            |------------------------------------------------------------------
            */

            'collaborator_code_prefix' => [
                'label' => 'Collaborator ID prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:12'],
                'default' => 'COL-',
                'help' => 'The first collaborator is COL-1001.',
                'span' => 4,
                'sort' => 160,
            ],
            'collaborator_code_next_number' => [
                'label' => 'Next collaborator number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1'],
                'default' => 1001,
                'help' => 'Advanced only by the numbering service, under a row lock, never by this form (D62).',
                'readonly' => true,
                'span' => 4,
                'sort' => 170,
            ],
            'referral_code_editable' => [
                'label' => 'Allow a vanity referral code',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'A code can still only be changed while nothing references it (INV-C2).',
                'span' => 4,
                'sort' => 180,
            ],
            'referral_query_param' => [
                'label' => 'Referral URL parameter',
                'type' => self::TYPE_TEXT,
                // It goes straight into a query string, so it has to be a bare identifier.
                'rules' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_]{0,15}$/'],
                'default' => 'ref',
                'help' => 'The ?ref= in /admission?ref=COL-1001.',
                'span' => 4,
                'sort' => 190,
            ],
            'referral_landing_path' => [
                'label' => 'Student referral landing page',
                'type' => self::TYPE_TEXT,
                // A path on this site, never an absolute URL: a referral link that leaves the site is
                // an open redirect with the collaborator's name on it.
                'rules' => ['required', 'string', 'max:191', 'regex:/^\\/(?!\\/)[A-Za-z0-9\\-._~\\/]*$/'],
                'default' => '/admission',
                'span' => 4,
                'sort' => 200,
            ],
            'referral_inquiry_landing_path' => [
                'label' => 'Client inquiry landing page',
                'type' => self::TYPE_TEXT,
                'rules' => ['required', 'string', 'max:191', 'regex:/^\\/(?!\\/)[A-Za-z0-9\\-._~\\/]*$/'],
                'default' => '/contact',
                'help' => 'Where a software-project referral link lands.',
                'span' => 4,
                'sort' => 210,
            ],
            'referral_cookie_days' => [
                'label' => 'Attribution window',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:3650'],
                'default' => 30,
                'suffix' => 'days',
                'help' => 'How long a click can still win an attribution. Also sets a visit\'s expiry.',
                'span' => 4,
                'sort' => 220,
            ],
            'referral_attribution_model' => [
                'label' => 'Which visit wins',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:first_touch,last_touch'],
                'default' => 'last_touch',
                'options' => [
                    'first_touch' => 'First touch — the partner who introduced them',
                    'last_touch' => 'Last touch — the partner who closed them',
                ],
                'help' => 'Used only when one visitor arrived through several partners.',
                'span' => 4,
                'sort' => 230,
            ],
            'referral_visit_tracking_enabled' => [
                'label' => 'Record referral clicks',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off keeps attribution working; it only stops the click register filling up.',
                'span' => 4,
                'sort' => 240,
            ],
            'referral_visit_retention_days' => [
                'label' => 'Keep referral clicks for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:30', 'max:3650'],
                'default' => 365,
                'suffix' => 'days',
                'help' => 'A click that converted, or that anything still points at, is never pruned.',
                'span' => 4,
                'sort' => 250,
            ],
            'referral_override_reason_required' => [
                'label' => 'Require a reason to override a captured code',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'When staff pick a different partner than the link named.',
                'span' => 6,
                'sort' => 260,
            ],
            'referral_self_attribution_blocked' => [
                'label' => 'Block self-referral',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'A partner\'s own logged-in visit never earns them commission.',
                'span' => 6,
                'sort' => 270,
            ],
            'referral_public_name_visible' => [
                'label' => 'Show the partner\'s name on the public form',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Shows "Referred by Ali Traders" once the code validates.',
                // Public because a public component asks it what to render, and INV-10 says a public
                // view reads only keys the registry marks public — it decides what a visitor sees, so
                // the visitor's own page is allowed to ask.
                'public' => true,
                'span' => 6,
                'sort' => 280,
            ],
            'pending_application_alert_days' => [
                'label' => 'Flag a waiting application after',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:365'],
                'default' => 3,
                'suffix' => 'days',
                'span' => 6,
                'sort' => 290,
            ],
            'payout_account_verification_required' => [
                'label' => 'Pay only to a verified account',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Phase 8 owns the verification screen; the payout guard itself is the spine\'s.',
                'span' => 6,
                'sort' => 300,
            ],
            'seed_commission_rules_on_approval' => [
                'label' => 'Create the first commission rules at approval',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Uses the default type and rate above. Skipped while the commission module is off.',
                'span' => 6,
                'sort' => 310,
            ],

            /*
            |------------------------------------------------------------------
            | phase-10-12 §5 — the ten keys the commission engine reads.
            |------------------------------------------------------------------
            */

            'commission_hold_days' => [
                'label' => 'Hold commission for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'between:0,365'],
                'default' => 0,
                'suffix' => 'days',
                'help' => 'Earned commission is approved but not payable until this many days after the payment. 0 makes it available at once.',
                'span' => 4,
                'sort' => 320,
            ],
            'commissionable_fee_types' => [
                'label' => 'Fee types that earn commission',
                'type' => self::TYPE_MULTISELECT,
                'rules' => ['required', 'array', 'min:1'],
                'item_rules' => [
                    '*' => ['string', 'in:'.implode(',', array_column(StudentFeeType::cases(), 'value'))],
                ],
                // Exam and certificate fees are absent by default and have no setting that adds them:
                // they are services the institute performs, not business somebody brought in.
                'default' => ['course_fee', 'monthly_fee', 'installment'],
                'options' => StudentFeeType::options(),
                'span' => 12,
                'sort' => 330,
            ],
            'student_commission_document' => [
                'label' => 'Student commission is promised against',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:admission,fee'],
                'default' => 'admission',
                'options' => [
                    'admission' => 'The admission — one promise per enrolment',
                    'fee' => 'Each fee charge separately',
                ],
                'help' => 'Decides the grain of the entitlement: one cap for the whole enrolment, or one per charge.',
                'span' => 6,
                'sort' => 340,
            ],
            'fixed_commission_release' => [
                'label' => 'A fixed commission is released',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:prorated,on_first_payment,per_payment'],
                'default' => 'prorated',
                'options' => FixedCommissionRelease::options(),
                'help' => 'A percentage settles itself; a fixed amount does not, so somebody has to say what a part-payment releases.',
                'span' => 6,
                'sort' => 350,
            ],
            'commission_on_overpayment' => [
                'label' => 'Pay commission on overpayment',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Off means a partner earned the fee, not the rounding a student happened to pay over it.',
                'span' => 6,
                'sort' => 360,
            ],
            'commission_min_entry_amount' => [
                'label' => 'Smallest commission worth recording',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
                'default' => '0.00',
                'help' => 'A commission below this is skipped with a reason rather than written as dust.',
                'span' => 6,
                'sort' => 370,
            ],
            'clawback_on_paid_commission' => [
                'label' => 'When a refund undoes commission already paid out',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:offset_future,write_off'],
                'default' => 'offset_future',
                'options' => [
                    'offset_future' => 'Carry the debt and take it from future earnings',
                    'write_off' => 'Write it off — the business absorbs it',
                ],
                'help' => 'The partner has already been paid, so there is nothing to take back: either they owe it or the business does not ask.',
                'span' => 6,
                'sort' => 380,
            ],
            'payout_single_inflight' => [
                'label' => 'One payout request at a time',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off lets a partner stack requests, which makes "what is still reserved" much harder to read.',
                'span' => 6,
                'sort' => 390,
            ],
            'payout_auto_approve_below' => [
                'label' => 'Approve payouts below',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
                'default' => '0.00',
                'help' => 'A request under this amount skips the approval step. 0 means every payout is approved by hand.',
                'span' => 6,
                'sort' => 400,
            ],
            'statement_show_technical_rows' => [
                'label' => 'Show technical rows on a statement',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Adjustments and write-offs are hidden by default: a partner reads a statement, not a ledger.',
                'span' => 6,
                'sort' => 410,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    /**
     * `support` — §5.2's thirty-seven keys ([D-22-4]).
     *
     * **`sla_enabled` is the one to read first.** With it false the whole SLA feature is inert: no
     * clock is stamped, `tickets:sla-sweep` returns immediately, `TicketSlaService::multiplier()` is
     * never called, and no breach badge, countdown or SLA card renders anywhere. Every SLA column on
     * `support_tickets` is nullable or defaults to zero precisely so that dropping SLA later is this
     * flip rather than a migration (audit F-13.5, needs-human H3).
     *
     * **`notifications_mail_enabled` defaults to false, deliberately** (Q7). §97's database channel
     * works from the first row; the mail channel waits until an installation has real SMTP behind
     * it. A system that starts by failing to send email teaches its users to ignore the mail queue.
     *
     * **`messaging_allowed_pairs` is the §94 matrix, admin-tunable** — and removing a pair silences
     * the *existing* threads of that pair, not merely new ones (INV-22-4), because
     * `MessagingMatrix::mayParticipate()` re-checks on every send.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function supportFields(): array
    {
        return [
            // -------------------------------------------------------------- tickets
            'ticket_prefix' => [
                'label' => 'Ticket prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\\-\\/]*$/'],
                'default' => 'TKT-',
                'span' => 3,
                'sort' => 10,
            ],
            'ticket_next_number' => [
                'label' => 'Next ticket number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1'],
                'default' => 1,
                'help' => 'Advanced automatically when a ticket is raised; a number is assigned once '
                    .'and never reused. Locked inside the transaction that issues it, so two tickets '
                    .'raised at the same moment can never take the same number. Shown for information only.',
                // D62: a document counter is advanced by `DocumentNumberService` under a row lock and
                // by nothing else. Without this the field renders as an editable input, and an
                // administrator who opened the settings screen before a busy hour and saved it
                // afterwards would roll the counter backwards - handing the next few tickets numbers
                // that already exist. The unique index would then refuse them, and raising a ticket
                // would simply fail for reasons nobody could see.
                'readonly' => true,
                'span' => 3,
                'sort' => 20,
            ],
            'ticket_default_department_id' => [
                'label' => 'Default department',
                'type' => self::TYPE_SELECT,
                'rules' => ['nullable', 'integer', 'exists:ticket_departments,id'],
                // Null, not an id: the seeder marks one department `is_default` and the service falls
                // through to that row. A hardcoded id would be wrong on any install whose first
                // department was something else, and a default cannot know its own id.
                'default' => null,
                'options' => [],
                'help' => 'Used when a portal form offers no choice of queue.',
                'span' => 6,
                'sort' => 30,
            ],
            'ticket_default_priority' => [
                'label' => 'Default priority',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:low,medium,high,urgent'],
                'default' => 'medium',
                'options' => [
                    'low' => 'Low',
                    'medium' => 'Medium',
                    'high' => 'High',
                    'urgent' => 'Urgent',
                ],
                // A portal user never picks their own priority (§12.2 Q6): a client who could
                // declare "urgent" would, every time, and the word would stop meaning anything.
                'help' => 'What a ticket raised from a portal starts at. Staff choose per ticket.',
                'span' => 3,
                'sort' => 40,
            ],
            'ticket_auto_assign' => [
                'label' => 'When a department says nothing',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:none,default_assignee,round_robin,least_open'],
                'default' => 'least_open',
                'options' => [
                    'none' => 'Leave it unassigned',
                    'default_assignee' => 'The department’s default person',
                    'round_robin' => 'Take it in turns',
                    'least_open' => 'Whoever has the fewest open',
                ],
                'span' => 3,
                'sort' => 50,
            ],

            // -------------------------------------------------------------- the SLA
            'sla_enabled' => [
                'label' => 'Measure response times',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                'help' => 'Off, no clock is stamped and no target is shown anywhere. Turning it back '
                    .'on starts clocks from that moment and never back-dates a breach.',
                'span' => 6,
                'sort' => 100,
            ],
            'sla_first_response_minutes' => [
                'label' => 'First reply within',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:100000'],
                'default' => 240,
                'suffix' => 'minutes',
                'help' => 'Used when the department sets no target of its own.',
                'span' => 3,
                'sort' => 110,
            ],
            'sla_resolution_minutes' => [
                'label' => 'Resolve within',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:100000'],
                'default' => 2880,
                'suffix' => 'minutes',
                'span' => 3,
                'sort' => 120,
            ],
            'sla_pause_on_waiting' => [
                'label' => 'Pause while waiting on the requester',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                // Without this an SLA report measures how quickly customers answer email.
                'help' => 'Time spent waiting for an answer is not counted against the target.',
                'span' => 6,
                'sort' => 130,
            ],
            'sla_business_hours_only' => [
                'label' => 'Count working hours only',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => false,
                'help' => 'The clock advances only inside the opening hours set under Contact, and '
                    .'skips closed days entirely.',
                'span' => 6,
                'sort' => 140,
            ],

            // -------------------------------------------------------------- closing and reopening
            'ticket_auto_close_resolved_days' => [
                'label' => 'Close a resolved ticket after',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:365'],
                'default' => 7,
                'suffix' => 'days',
                'help' => '0 never closes one automatically.',
                'span' => 3,
                'sort' => 200,
            ],
            'ticket_reopen_window_days' => [
                'label' => 'A reply reopens it for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:365'],
                'default' => 14,
                'suffix' => 'days',
                'help' => 'After this only staff may reopen a ticket. A reply from the requester '
                    .'raises a new one instead, so nothing is lost.',
                'span' => 3,
                'sort' => 210,
            ],

            // -------------------------------------------------------------- who may raise one
            'ticket_allow_client_create' => [
                'label' => 'Clients may raise tickets',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                'span' => 3,
                'sort' => 300,
            ],
            'ticket_allow_student_create' => [
                'label' => 'Students may raise tickets',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                'span' => 3,
                'sort' => 310,
            ],
            'ticket_allow_teacher_create' => [
                'label' => 'Teachers may raise tickets',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                'span' => 3,
                'sort' => 320,
            ],
            'ticket_allow_collaborator_create' => [
                'label' => 'Collaborators may raise tickets',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                'span' => 3,
                'sort' => 330,
            ],
            'ticket_attachment_max_mb' => [
                'label' => 'Largest ticket attachment',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:500'],
                'default' => 10,
                'suffix' => 'MB',
                // The effective limit is min(this, security.max_upload_mb) — a field narrows, never
                // widens, which is the rule the whole upload gate rests on.
                'help' => 'The effective limit is this or the global upload cap under Security, '
                    .'whichever is smaller.',
                'span' => 3,
                'sort' => 340,
            ],
            'ticket_max_attachments' => [
                'label' => 'Most files on one ticket or reply',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:50'],
                'default' => 5,
                'span' => 3,
                'sort' => 350,
            ],

            // -------------------------------------------------------------- meetings
            'meeting_default_duration_minutes' => [
                'label' => 'Meetings default to',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:5', 'max:1440'],
                'default' => 30,
                'suffix' => 'minutes',
                'span' => 3,
                'sort' => 400,
            ],
            'meeting_default_reminder_minutes' => [
                'label' => 'Remind participants',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:10080'],
                'default' => 30,
                'suffix' => 'minutes before',
                'span' => 3,
                'sort' => 410,
            ],
            'meeting_second_reminder_minutes' => [
                'label' => 'And again',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:10080'],
                'default' => 0,
                'suffix' => 'minutes before',
                'help' => '0 sends one reminder only.',
                'span' => 3,
                'sort' => 420,
            ],
            'meeting_allow_external_participants' => [
                'label' => 'Meetings may include outsiders',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                'help' => 'Somebody with no account here, invited by name and email.',
                'span' => 6,
                'sort' => 430,
            ],
            'meeting_ics_enabled' => [
                'label' => 'Offer a calendar download',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                'span' => 3,
                'sort' => 440,
            ],
            'meeting_room_clash_block' => [
                'label' => 'Refuse a double-booked room',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                // A meeting with no room cannot clash with anything physical, so it is only ever a
                // warning there — this setting decides what happens when a room *is* booked.
                'help' => 'Off, a clash is shown as a warning listing what it collides with, and the '
                    .'meeting is saved anyway.',
                'span' => 3,
                'sort' => 450,
            ],

            // -------------------------------------------------------------- messaging
            'messaging_enabled' => [
                'label' => 'Internal messaging',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                'span' => 6,
                'sort' => 500,
            ],
            'messaging_allowed_pairs' => [
                'label' => 'Who may message whom',
                'type' => self::TYPE_MULTISELECT,
                'rules' => ['nullable', 'array'],
                'default' => [
                    'admin_employee', 'employee_employee', 'client_manager',
                    'collaborator_staff', 'student_staff', 'teacher_management',
                ],
                'options' => [
                    'admin_employee' => 'Administrator and employee',
                    'employee_employee' => 'Between employees',
                    'client_manager' => 'Client and project manager',
                    'collaborator_staff' => 'Collaborator and staff',
                    'student_staff' => 'Student and institute staff',
                    'teacher_management' => 'Teacher and management',
                ],
                // The pairs NOT on this list cannot be added to it: a student and a client have no
                // case here at all, so widening the setting cannot reach them.
                'help' => 'Removing a pair silences the threads that already exist between them, not '
                    .'only new ones. No pairing outside this list can be enabled.',
                'span' => 6,
                'sort' => 510,
            ],
            'messaging_student_can_start' => [
                'label' => 'Students may start a thread',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                'help' => 'Off, they may still reply to one somebody else started.',
                'span' => 3,
                'sort' => 520,
            ],
            'messaging_client_can_start' => [
                'label' => 'Clients may start a thread',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                'span' => 3,
                'sort' => 530,
            ],
            'messaging_collaborator_can_start' => [
                'label' => 'Collaborators may start a thread',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                'span' => 3,
                'sort' => 540,
            ],
            'messaging_attachments_enabled' => [
                'label' => 'Files may be sent in messages',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                'span' => 3,
                'sort' => 550,
            ],
            'messaging_attachment_max_mb' => [
                'label' => 'Largest file in a message',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:500'],
                'default' => 10,
                'suffix' => 'MB',
                'help' => 'The effective limit is this or the global upload cap under Security, '
                    .'whichever is smaller.',
                'span' => 3,
                'sort' => 560,
            ],
            'messaging_rate_limit_per_minute' => [
                'label' => 'Messages a person may send',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:600'],
                'default' => 20,
                'suffix' => 'per minute',
                // Enforced in the service as well as in middleware: middleware counts requests, and
                // the service counts sends, which are not the same number when one request retries.
                'help' => 'Enforced both at the route and inside the service.',
                'span' => 3,
                'sort' => 570,
            ],

            // -------------------------------------------------------------- notifications
            'notifications_mail_enabled' => [
                'label' => 'Send notifications by email',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                // False until an installation has real SMTP behind it — see the class note.
                'default' => false,
                'help' => 'The bell always works. This is the master switch for the email copy, and '
                    .'it stays off until the mail settings are real.',
                'span' => 6,
                'sort' => 600,
            ],
            'notification_digest_hour' => [
                'label' => 'Daily summary goes out at',
                'type' => self::TYPE_TIME,
                'rules' => ['required', 'date_format:H:i'],
                'default' => '08:00',
                'span' => 3,
                'sort' => 610,
            ],
            'notification_retention_days' => [
                'label' => 'Keep archived notifications for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:3650'],
                'default' => 180,
                'suffix' => 'days',
                'help' => '0 keeps them for ever. Only archived rows are ever pruned.',
                'span' => 3,
                'sort' => 620,
            ],
            'notification_bell_page_size' => [
                'label' => 'Rows in the bell',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:50'],
                'default' => 10,
                'span' => 3,
                'sort' => 630,
            ],
            'notification_mark_read_on_open' => [
                'label' => 'Opening one marks it read',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                'span' => 3,
                'sort' => 640,
            ],
        ];
    }

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
            /*
            |--------------------------------------------------------------------------
            | phase-14-17 §5 — the six document numbers, and the rules around them
            |--------------------------------------------------------------------------
            | Every counter and every period marker is **readonly** (D62): they are advanced by
            | `DocumentNumberService` under a row lock, and a form that could set "next number" to a
            | value already issued would hand two students the same code. The format strings are
            | editable because an institute really does want its own shape — `StudentNumberService`
            | validates the tokens, so an unknown one is a refusal on the form rather than a number
            | that quietly loses its year.
            */
            'student_id_format' => [
                'label' => 'Student ID format',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:64'],
                'default' => '{PREFIX}{YY}{SEQ:5}',
                'help' => 'Tokens: {PREFIX}, {YYYY}, {YY}, {MM}, {BRANCH}, {COURSE}, {SEQ:n}. {SEQ} is required.',
                'span' => 6,
                'sort' => 22,
            ],
            'student_id_sequence_scope' => [
                'label' => 'Student ID numbering resets',
                'type' => self::TYPE_SELECT,
                'rules' => ['nullable', 'string', 'in:global,yearly,branch_yearly'],
                'options' => [
                    'global' => 'Never — one running series',
                    'yearly' => 'Every year',
                    'branch_yearly' => 'Every year, per branch',
                ],
                'default' => 'yearly',
                'help' => 'The reset happens inside the same lock that issues the number, so the first student of January cannot collide with the last of December.',
                'span' => 6,
                'sort' => 23,
            ],
            'student_id_next_number' => [
                'label' => 'Next student number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1'],
                'default' => 1,
                'help' => 'Advanced only by the numbering service, under a row lock, never by this form (D62).',
                'readonly' => true,
                'span' => 4,
                'sort' => 24,
            ],
            'student_id_period' => [
                'label' => 'Student ID period',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:32'],
                'default' => '',
                'help' => 'The reset marker the counter is compared against. Written by the service; shown for information.',
                'readonly' => true,
                'span' => 4,
                'sort' => 25,
            ],
            'registration_number_prefix' => [
                'label' => 'Registration number prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'REG-',
                'help' => 'What {PREFIX} expands to in the registration number format.',
                'span' => 4,
                'sort' => 41,
            ],
            'registration_number_sequence_scope' => [
                'label' => 'Registration numbering resets',
                'type' => self::TYPE_SELECT,
                'rules' => ['nullable', 'string', 'in:global,yearly,branch_yearly'],
                'options' => [
                    'global' => 'Never — one running series',
                    'yearly' => 'Every year',
                    'branch_yearly' => 'Every year, per branch',
                ],
                'default' => 'yearly',
                'span' => 6,
                'sort' => 42,
            ],
            'registration_number_next_number' => [
                'label' => 'Next registration number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1'],
                'default' => 1,
                'help' => 'Advanced only by the numbering service (D62).',
                'readonly' => true,
                'span' => 4,
                'sort' => 43,
            ],
            'registration_number_period' => [
                'label' => 'Registration number period',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:32'],
                'default' => '',
                'help' => 'The reset marker. Written by the service; shown for information.',
                'readonly' => true,
                'span' => 4,
                'sort' => 44,
            ],
            'inquiry_prefix' => [
                'label' => 'Inquiry number prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'INQ-',
                'help' => 'A walk-in has to be quotable on a slip before anything else about them exists.',
                'span' => 4,
                'sort' => 45,
            ],
            'inquiry_next_number' => [
                'label' => 'Next inquiry number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1'],
                'default' => 1,
                'readonly' => true,
                'span' => 4,
                'sort' => 46,
            ],
            'application_prefix' => [
                'label' => 'Application number prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'APP-',
                'help' => 'Quoted on the thank-you page, so an applicant can ask about their form.',
                'span' => 4,
                'sort' => 47,
            ],
            'application_next_number' => [
                'label' => 'Next application number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1'],
                'default' => 1,
                'readonly' => true,
                'span' => 4,
                'sort' => 48,
            ],
            'admission_prefix' => [
                'label' => 'Admission number prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'ADM-',
                'span' => 4,
                'sort' => 49,
            ],
            'admission_next_number' => [
                'label' => 'Next admission number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1'],
                'default' => 1,
                'readonly' => true,
                'span' => 4,
                'sort' => 50,
            ],
            'teacher_code_prefix' => [
                'label' => 'Teacher code prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'TCH-',
                'span' => 4,
                'sort' => 51,
            ],
            'teacher_code_next_number' => [
                'label' => 'Next teacher number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1'],
                'default' => 1,
                'readonly' => true,
                'span' => 4,
                'sort' => 52,
            ],

            /*
            |--------------------------------------------------------------------------
            | phase-14-17 §5 — how the funnel and the admission pipeline behave
            |--------------------------------------------------------------------------
            */
            'inquiry_followup_days' => [
                'label' => 'Default days to the next follow-up',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1', 'max:90'],
                'default' => 2,
                'help' => 'Every logged follow-up sets the next date from this, so an open enquiry always has one.',
                'span' => 4,
                'sort' => 60,
            ],
            'inquiry_stale_days' => [
                'label' => 'Flag an untouched inquiry after',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1', 'max:365'],
                'default' => 14,
                'help' => 'Days. Flagged with an amber border and never auto-closed: an enquiry that went quiet is somebody who stopped being called, and closing it would hide that.',
                'span' => 4,
                'sort' => 61,
            ],
            'admission_closed_message' => [
                'label' => 'What the closed admission form says',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:500'],
                // null, not '': the form posts an empty field back as null, and a default of ''
                // would make an untouched save move the stored value.
                'default' => null,
                'help' => 'Shown on the public admission page while admissions are shut. Leave it empty for the '
                    .'standard wording, which points the visitor at the contact page rather than a dead end.',
                'span' => 12,
                'sort' => 63,
            ],
            'admission_form_require_batch' => [
                'label' => 'The public form must name a batch',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Off by default: most applicants do not know which batch they want, and a required field they cannot answer is a form they abandon.',
                'span' => 6,
                'sort' => 62,
            ],
            'application_duplicate_window_days' => [
                'label' => 'Duplicate application window',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1', 'max:365'],
                'default' => 7,
                'help' => 'Days. Two applications from the same phone for the same course inside this window are flagged for a human — never refused, because the same person really does re-apply.',
                'span' => 6,
                'sort' => 63,
            ],
            'require_fee_before_activation' => [
                'label' => 'Before a student becomes active',
                'type' => self::TYPE_SELECT,
                'rules' => ['nullable', 'string', 'in:none,any_payment,full_first_installment'],
                'options' => [
                    'none' => 'Nothing — a batch seat is enough',
                    'any_payment' => 'At least one payment received',
                    'full_first_installment' => 'The first installment paid in full',
                ],
                'default' => 'any_payment',
                'help' => 'The ONE place the fee/batch ordering of §68 is decided: both orders are legal, and this is what the move to Active checks.',
                'span' => 6,
                'sort' => 64,
            ],
            'auto_create_student_login' => [
                'label' => 'Create a student login on activation',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Needs an email or a phone. The password is random, delivered by notification, and must be changed on first sign-in.',
                'span' => 6,
                'sort' => 65,
            ],
            'demo_class_duration_minutes' => [
                'label' => 'Default demo class length',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:15', 'max:480'],
                'default' => 60,
                'help' => 'Minutes. Fills the end time when a demo is booked; the booking then holds that slot against clashes.',
                'span' => 4,
                'sort' => 66,
            ],

            /*
            |--------------------------------------------------------------------------
            | phase-14-17 §5 — batches, the timetable and how far ahead classes exist
            |--------------------------------------------------------------------------
            */
            'auto_create_teacher_login' => [
                'label' => 'Create a teacher login on hire',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Needs an email. Same rules as the student login: random password, delivered by notification, changed on first sign-in.',
                'span' => 6,
                'sort' => 70,
            ],
            'batch_default_capacity' => [
                'label' => 'Default batch size',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1', 'max:500'],
                'default' => 20,
                'help' => 'Prefills a new batch. The enrolment check reads the batch, never this.',
                'span' => 4,
                'sort' => 71,
            ],
            'batch_allow_overbooking' => [
                'label' => 'Allow a batch to be overbooked',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'When off, even a user holding Batches → assign cannot exceed capacity. When on, they can, with a reason that is recorded on the enrolment.',
                'span' => 6,
                'sort' => 72,
            ],
            'batch_near_capacity_threshold' => [
                'label' => 'Warn when a batch reaches',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:50', 'max:100'],
                'default' => 90,
                'help' => 'Percent of capacity. Fires the near-capacity notice so a second batch can be opened before the first one closes.',
                'span' => 4,
                'sort' => 73,
            ],
            'timetable_working_days' => [
                'label' => 'Working days',
                'type' => self::TYPE_MULTISELECT,
                'rules' => ['required', 'array', 'min:1'],
                'item_rules' => [
                    '*' => ['string', 'distinct', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
                ],
                'default' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
                'options' => [self::class, 'weekdayOptions'],
                'help' => 'Both the columns the calendar draws and the days a timetable slot may be placed on.',
                'span' => 6,
                'sort' => 74,
            ],
            'timetable_day_start' => [
                'label' => 'Teaching day starts',
                'type' => self::TYPE_TIME,
                'rules' => ['required', 'date_format:H:i'],
                'default' => '08:00',
                'span' => 3,
                'sort' => 75,
            ],
            'timetable_day_end' => [
                'label' => 'Teaching day ends',
                'type' => self::TYPE_TIME,
                'rules' => ['required', 'date_format:H:i'],
                'default' => '22:00',
                'help' => 'A slot outside these bounds is refused by the same rule the calendar draws.',
                'span' => 3,
                'sort' => 76,
            ],
            'timetable_slot_gap_minutes' => [
                'label' => 'Minimum gap between two classes',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:0', 'max:120'],
                'default' => 0,
                'help' => 'Minutes of travel or setup time enforced between two slots of the SAME teacher or room. Zero allows back-to-back classes; a batch is never given a gap.',
                'span' => 4,
                'sort' => 77,
            ],
            /*
            |--------------------------------------------------------------------------
            | phase-14-17 §5 — the register, and what a percentage counts
            |--------------------------------------------------------------------------
            */
            'attendance_lock_hours' => [
                'label' => 'A register can be changed freely for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:0', 'max:720'],
                'default' => 48,
                'help' => 'Hours after it was taken. Afterwards a change needs the attendance edit permission and a '
                    .'reason, and the old value is kept — fixing a typo on the way out of the room is not the same '
                    .'act as revising a register somebody has already reported on.',
                'span' => 4,
                'sort' => 80,
            ],
            'attendance_minimum_percentage' => [
                'label' => 'Minimum attendance',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'default' => 75,
                'help' => 'The pass line shown on every attendance report, and the one Phase 21 reads for '
                    .'certificate eligibility. Nothing is refused by it; people are flagged.',
                'span' => 4,
                'sort' => 81,
            ],
            'attendance_leave_counts_in_denominator' => [
                'label' => 'Approved leave counts against attendance',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Off: an approved leave is neither present nor counted, so it neither helps nor hurts the '
                    .'percentage. On: it stays in the denominator and dilutes it. This is a policy choice, which is '
                    .'why it is a setting and not a line inside a formula.',
                'span' => 6,
                'sort' => 82,
            ],
            'attendance_auto_absent_on_close' => [
                'label' => 'Fill unmarked students as absent when a class is closed',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Off by default, because an absence a person did not decide is still an absence on somebody '
                    .'record. When it is on, those rows are stamped "filled by the system" so the difference stays '
                    .'visible to whoever is challenged about one.',
                'span' => 6,
                'sort' => 83,
            ],
            'progress_weighting' => [
                'label' => 'Syllabus progress counts',
                'type' => self::TYPE_SELECT,
                'rules' => ['nullable', 'string', 'in:topic_count,topic_weight'],
                'options' => [
                    'topic_count' => 'Every topic equally',
                    'topic_weight' => 'By each topic\'s weight',
                ],
                'default' => 'topic_weight',
                'help' => 'Whether a three-week topic counts for more than a one-hour one. Changing it recomputes '
                    .'every progress row overnight; the stored percentages are caches either way.',
                'span' => 6,
                'sort' => 84,
            ],
            'session_generation_weeks_ahead' => [
                'label' => 'Generate classes this far ahead',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1', 'max:52'],
                'default' => 8,
                'help' => 'Weeks. The nightly job materialises dated classes from the weekly rules up to this horizon, and a teacher cannot resign while they still own one.',
                'span' => 4,
                'sort' => 78,
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

            // phase-10-12 §5. The fee-charge document series, and the counter behind the receipt prefix
            // that already exists above. Both counters are readonly (D62): only DocumentNumberService
            // advances one, under a row lock, and a settings form that posted a stale value would
            // re-issue a number already printed on somebody's receipt.
            'fee_record_prefix' => [
                'label' => 'Fee charge prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'FS-',
                'span' => 4,
                'sort' => 110,
            ],
            'fee_record_next_number' => [
                'label' => 'Next fee charge number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1'],
                'default' => 1,
                'help' => 'Advanced only by the numbering service, under a row lock, never by this form (D62).',
                'readonly' => true,
                'span' => 4,
                'sort' => 120,
            ],
            'fee_receipt_next_number' => [
                'label' => 'Next fee receipt number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1'],
                'default' => 1,
                'help' => 'Advanced only by the numbering service, under a row lock, never by this form (D62).',
                'readonly' => true,
                'span' => 4,
                'sort' => 130,
            ],

            // phase-18 §5 — the ten fee keys, all into the existing `institute` group. Nothing here is
            // a new group and nothing redefines a key Phase 2 or the spine already declared
            // (`fee_receipt_prefix`, `fee_record_prefix`, both counters, `installment_reminder_days`,
            // `finance.backdate_limit_days` and every `collaborator.*` commission key are used as they
            // stand). Validation comes from `rulesFor('institute')`, so the settings Form Request needs
            // no duplicate list.
            'fee_due_days' => [
                'label' => 'Default payment window',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'between:1,28'],
                'default' => 7,
                'help' => 'How long after it is raised a charge falls due, when the admission, the batch or '
                    .'the wizard gives no date of its own.',
                'suffix' => 'days',
                'span' => 4,
                'sort' => 140,
            ],
            'monthly_fee_due_day' => [
                'label' => 'Monthly fee falls due on',
                'type' => self::TYPE_NUMBER,
                // Capped at 28 rather than 31 so the due day exists in February as well. The generator
                // clamps to the month end anyway, but a setting that silently means something different
                // in one month of the year is a setting nobody can reason about.
                'rules' => ['required', 'integer', 'between:1,28'],
                'default' => 5,
                'help' => 'Day of the month a generated monthly fee is due.',
                'span' => 4,
                'sort' => 150,
            ],
            'fee_overdue_grace_days' => [
                'label' => 'Grace before a fee counts as overdue',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'between:0,60'],
                'default' => 0,
                'help' => 'Days after the due date before the nightly sweep marks a charge or an installment '
                    .'overdue. 0 marks it the next day.',
                'suffix' => 'days',
                'span' => 4,
                'sort' => 160,
            ],
            'installment_remainder_placement' => [
                'label' => 'Rounding remainder goes to',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:first,last'],
                'default' => RemainderPlacement::Last->value,
                // `Largest` is deliberately not offered: every line of a generated plan is the same size
                // before the remainder is placed, so "largest" and "first" would be the same button.
                'options' => [
                    RemainderPlacement::First->value => 'The first installment',
                    RemainderPlacement::Last->value => 'The last installment',
                ],
                'help' => 'A fee that will not divide evenly leaves a few paisa. 10,000.00 over three is '
                    .'3,333.33 · 3,333.33 · 3,333.34 with the remainder last. The split is exact either way.',
                'span' => 4,
                'sort' => 170,
            ],
            'discount_approval_required' => [
                'label' => 'A discount needs an approver',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'When on, every discount names who approved it, that person must hold the discount '
                    .'approval permission, and nobody may approve their own unless they hold it themselves.',
                'span' => 4,
                'sort' => 180,
            ],
            'discount_max_percentage' => [
                'label' => 'Largest percentage discount',
                'type' => self::TYPE_DECIMAL,
                // `decimal:0,4` is what refuses exponent notation — `numeric` alone accepts `1E1`
                // and stores it, which would put a value no percentage column can parse behind a
                // field that looked validated. Every other decimal setting carries it.
                'rules' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
                'default' => '100.0000',
                'help' => 'Caps a percentage discount before the database CHECK has to. 100 allows a full waiver.',
                'suffix' => '%',
                'span' => 4,
                'sort' => 190,
            ],
            'fee_structure_fee_types' => [
                'label' => 'Heads the fee wizard offers',
                'type' => self::TYPE_MULTISELECT,
                'rules' => ['required', 'array', 'min:1'],
                // Declared in the enum's own case order, which is the order the checkboxes render
                // and therefore the order a save posts back. A set has no meaningful order, and
                // writing the default in a different one makes an untouched save look like an edit.
                'default' => [
                    StudentFeeType::CourseFee->value,
                    StudentFeeType::AdmissionFee->value,
                    StudentFeeType::RegistrationFee->value,
                ],
                'options' => StudentFeeType::options(),
                'help' => 'Which heads the fee-structure wizard ticks by default. A head with a zero amount is '
                    .'dropped rather than raised as a zero charge.',
                'span' => 6,
                'sort' => 200,
            ],
            'auto_generate_fee_structure_on_admission' => [
                'label' => 'Raise fees when an admission is confirmed',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Runs the fee-structure generator as part of confirming an admission (§68). The '
                    .'generator is duplicate-proof, so turning this on cannot double-charge anybody.',
                'span' => 4,
                'sort' => 210,
            ],
            'fee_slip_show_commission' => [
                'label' => 'Print commission on the fee slip',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                // §41 lists commission among the slip's fields; §112 forbids showing it to the student.
                // Both hold: this switch governs the STAFF copy only, and the student's copy is built
                // through FeeSlipOptions::studentCopy(), which no setting and no query parameter can
                // talk out of hiding the figures (§6.7.1).
                'help' => 'Staff copies only, and only for someone who also holds the commission financial '
                    .'permission. A student\'s copy never shows a rate or an amount whatever this says — it '
                    .'names the collaborator and nothing more.',
                'span' => 4,
                'sort' => 220,
            ],
            'fee_slip_footer_note' => [
                'label' => 'Fee slip footer',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:1000'],
                'default' => null,
                'help' => 'Printed at the bottom of every fee slip and receipt — terms, a refund-policy line, '
                    .'bank details. Mirrors the invoice footer on the finance side.',
                'span' => 12,
                'sort' => 230,
            ],

            // phase-14-17 §5. The one Phase 14 key: how many courses a page of the public catalogue
            // shows. Public, because the catalogue is rendered before anybody signs in.
            'public_course_catalogue_per_page' => [
                'label' => 'Courses per catalogue page',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:3', 'max:60'],
                'default' => 12,
                'help' => 'How many courses the public /courses page shows before paginating.',
                'public' => true,
                'span' => 4,
                'sort' => 200,
            ],

            // ---------------------------------------------------------------------------------
            // phase-19-23 §5.1 — materials and assignments.
            // ---------------------------------------------------------------------------------

            'material_max_upload_mb' => [
                'label' => 'Largest material file',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:2048'],
                'default' => 50,
                'help' => 'Per file. The effective limit is the smallest of this, the security '
                    .'group’s upload cap, and whatever PHP itself will accept — raising this alone '
                    .'does not raise the others.',
                'suffix' => 'MB',
                'span' => 4,
                'sort' => 300,
            ],
            'material_allowed_types' => [
                'label' => 'Kinds of material that may be shared',
                'type' => self::TYPE_MULTISELECT,
                'rules' => ['required', 'array', 'min:1'],
                // Declared in the enum's own case order, which is the order the checkboxes render
                // and therefore the order an untouched save posts back. A set written in any other
                // order makes a save that changed nothing look like an edit.
                'default' => [
                    CourseResourceType::Pdf->value,
                    CourseResourceType::Document->value,
                    CourseResourceType::Note->value,
                    CourseResourceType::Slide->value,
                    CourseResourceType::Image->value,
                    CourseResourceType::Video->value,
                    CourseResourceType::Audio->value,
                    CourseResourceType::Zip->value,
                    CourseResourceType::SourceCode->value,
                    CourseResourceType::Link->value,
                ],
                'options' => CourseResourceType::options(),
                'help' => 'Turning a kind off hides it from the upload form. Material already shared '
                    .'under that kind keeps working — this governs what may be added, not what exists.',
                'span' => 12,
                'sort' => 310,
            ],
            'material_extra_extensions' => [
                'label' => 'Extra extensions for design courses',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9,\s]*$/'],
                'default' => 'psd,ai,fig,sketch',
                'help' => 'Comma separated, no dots. Intersected with the security group’s allowed '
                    .'file types — an extension the security setting refuses stays refused however '
                    .'this is written, and the never-uploadable list is absolute.',
                'span' => 8,
                'sort' => 320,
            ],
            'material_visible_after_batch_end_days' => [
                'label' => 'Material access after a batch ends',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:3650'],
                'default' => 90,
                'help' => 'How long a completed batch keeps reaching its material. 0 cuts access the '
                    .'day the batch ends.',
                'suffix' => 'days',
                'span' => 4,
                'sort' => 330,
            ],
            'material_download_log_retention_days' => [
                'label' => 'Keep the material access log for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:3650'],
                'default' => 365,
                'help' => '0 keeps it for ever. The log is who opened which file and when — it is the '
                    .'first thing anybody investigating a leak asks for, so shorten it deliberately.',
                'suffix' => 'days',
                'span' => 4,
                'sort' => 340,
            ],
            'material_notify_on_publish' => [
                'label' => 'Tell students when material is published',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Notifies the targeted audience once, when the material is first published. '
                    .'Re-aiming it later tells only the audience that had not already been told.',
                'span' => 4,
                'sort' => 350,
            ],

            'material_block_on_outstanding_fee' => [
                'label' => 'Withhold material while fees are outstanding',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                // Default off. §4.3 asks for the capability — it is the whole reason
                // `student_portal.material_download` is a separate permission from
                // `student_portal.materials` — but withholding coursework over money is a policy an
                // institute chooses, not one the software should impose on first boot.
                'default' => false,
                'help' => 'The student still sees the library and is told why the files are '
                    .'unavailable. Hiding the list as well would leave them guessing what they were '
                    .'missing, which is a worse version of the same restriction.',
                'span' => 6,
                'sort' => 355,
            ],
            'assignment_submission_max_mb' => [
                'label' => 'Largest submission file',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:512'],
                'default' => 20,
                'help' => 'Per file. An assignment may ask for less; it can never ask for more.',
                'suffix' => 'MB',
                'span' => 4,
                'sort' => 360,
            ],
            'assignment_max_files_default' => [
                'label' => 'Files a submission may carry',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:10'],
                'default' => 3,
                'help' => 'Prefills a new assignment. The database refuses anything outside 1–10.',
                'span' => 4,
                'sort' => 370,
            ],
            'assignment_max_attempts_default' => [
                'label' => 'Attempts a student may make',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:10'],
                'default' => 3,
                'help' => 'Prefills a new assignment. Every attempt keeps its own row and its own '
                    .'files; a resubmission supersedes its predecessor rather than replacing it.',
                'span' => 4,
                'sort' => 380,
            ],
            'assignment_late_submission_default' => [
                'label' => 'Accept late work by default',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Prefills a new assignment. Late work is still marked late — this decides '
                    .'whether it is accepted at all, not whether it counts as on time.',
                'span' => 4,
                'sort' => 390,
            ],
            'assignment_late_penalty_default_percentage' => [
                'label' => 'Default late penalty',
                'type' => self::TYPE_DECIMAL,
                // `decimal:0,4` is what refuses exponent notation — `numeric` alone accepts `1E1`
                // and stores it, which would put a value no percentage column can parse behind a
                // field that looked validated.
                'rules' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
                'default' => '0.0000',
                'help' => 'Percent of the assignment’s total marks, deducted once from a late '
                    .'submission. 0 records the lateness without costing anything.',
                'suffix' => '%',
                'span' => 4,
                'sort' => 400,
            ],
            'assignment_deadline_reminder_hours' => [
                'label' => 'Deadline reminders',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:100', 'regex:/^\s*\d{1,4}(\s*,\s*\d{1,4})*\s*$/'],
                'default' => '48,12',
                'help' => 'Comma-separated hours before the deadline. “48,12” warns two days out and '
                    .'again the evening before. Empty sends none.',
                'suffix' => 'hours before',
                'span' => 8,
                'sort' => 410,
            ],
            'assignment_auto_close_on_deadline' => [
                'label' => 'Close an assignment automatically',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Closes a published assignment once its late cutoff has passed — or its '
                    .'deadline, when late work is not accepted. Closed still shows students the brief '
                    .'and their own marks; it only stops collection.',
                'span' => 4,
                'sort' => 420,
            ],
            'assignment_release_marks_immediately' => [
                'label' => 'Show marks as soon as they are entered',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Prefills a new assignment. Turning it off lets a teacher mark a whole batch '
                    .'privately and release every mark in one go.',
                'span' => 4,
                'sort' => 430,
            ],

            // ---------------------------------------------------------------------------------
            // phase-19-23 §5.1 — exams and results.
            // ---------------------------------------------------------------------------------

            'default_grade_scale_id' => [
                'label' => 'Default grade scale',
                'type' => self::TYPE_SELECT,
                'rules' => ['nullable', 'integer', 'exists:grade_scales,id'],
                // Null rather than an id: the seeder creates the DEFAULT scale and marks it
                // `is_default`, and `GradeScaleService::resolveFor()` falls through to that row. A
                // hardcoded `1` here would be wrong on any install whose first scale was something
                // else, and there is no way for a default to know its own id.
                'default' => null,
                'options' => [],
                'help' => 'Used by an exam that names no scale of its own. An exam with neither is '
                    .'refused rather than graded on an assumption.',
                'span' => 6,
                'sort' => 440,
            ],
            'exam_default_passing_percentage' => [
                'label' => 'Default pass mark',
                'type' => self::TYPE_DECIMAL,
                // `decimal:0,4` is what refuses exponent notation — `numeric` alone accepts `1E1`
                // and stores a value no decimal(8,4) column can parse (D114).
                'rules' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
                'default' => '40.0000',
                'help' => 'Prefills a new exam’s pass mark as a percentage of what it is out of. The '
                    .'exam’s own figure always wins once it is set.',
                'suffix' => '%',
                'span' => 3,
                'sort' => 450,
            ],
            'result_publish_requires_verification' => [
                'label' => 'A second person checks results before they go out',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'The checker must not be somebody who entered any of the marks. A sheet '
                    .'entered by two people therefore needs a third to sign it off.',
                'span' => 6,
                'sort' => 460,
            ],
            'result_card_show_position' => [
                'label' => 'Print the student’s position',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Ties share a position and the next one skips — three students on 88% are '
                    .'all 2nd and the next is 5th.',
                'span' => 4,
                'sort' => 470,
            ],
            'result_card_show_attendance' => [
                'label' => 'Print attendance on the result card',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'span' => 4,
                'sort' => 480,
            ],
            'result_card_show_all_exams' => [
                'label' => 'One card for the whole course',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off prints one exam per card. On consolidates every published result for '
                    .'the enrolment, which is what a parent usually wants to see.',
                'span' => 4,
                'sort' => 490,
            ],
            'progress_from_assessment' => [
                'label' => 'Passing a topic’s exam marks the topic covered',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                // Default off. It writes to the syllabus register on somebody else's behalf, and a
                // system that silently ticks off topics is one nobody trusts the register of.
                'default' => false,
                'help' => 'When an exam names a topic, publishing its results marks that topic covered '
                    .'for every student who passed. Recorded as assessment-sourced progress, so it is '
                    .'always distinguishable from a teacher’s own mark.',
                'span' => 6,
                'sort' => 500,
            ],

            // ---------------------------------------------------------------------------------
            // phase-19-23 §5.1 — certificates and ID cards (Phase 21).
            // ---------------------------------------------------------------------------------

            'certificate_next_number' => [
                'label' => 'Next certificate number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1'],
                'default' => 1,
                // D62: a counter is never admin-editable. It is shown so somebody can see where the
                // sequence has got to, and refused on post — only DocumentNumberService advances it,
                // under a row lock, inside the issuing transaction.
                'readonly' => true,
                'help' => 'Advanced by the system when a certificate is issued. Shown, never edited.',
                'span' => 3,
                'sort' => 510,
            ],
            'certificate_number_format' => [
                'label' => 'Certificate number format',
                'type' => self::TYPE_TEXT,
                'rules' => ['required', 'string', 'max:64'],
                'default' => '{PREFIX}{YY}{SEQ:5}',
                'help' => 'Tokens: {PREFIX} {YY} {YYYY} {MM} {BRANCH} {SEQ:n}. The same convention '
                    .'student numbers use, so one explanation covers both.',
                'span' => 4,
                'sort' => 520,
            ],
            'certificate_number_sequence_scope' => [
                'label' => 'Restart the numbering',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:global,yearly,branch_yearly'],
                'default' => 'yearly',
                'options' => [
                    'global' => 'Never — one running sequence',
                    'yearly' => 'Every year',
                    'branch_yearly' => 'Every year, per branch',
                ],
                'span' => 4,
                'sort' => 530,
            ],
            'certificate_number_period' => [
                'label' => 'Current numbering period',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:32'],
                'default' => null,
                // Written by the service when the period rolls over. D62 again.
                'readonly' => true,
                'span' => 3,
                'sort' => 540,
            ],

            // ------------------------------------------------------------------- eligibility (§84)

            'certificate_require_pass' => [
                'label' => 'They must have passed every major exam',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'A quiz is not a major exam — ExamType::isMajor() decides, so a weekly test '
                    .'never blocks a certificate.',
                'span' => 6,
                'sort' => 550,
            ],
            'certificate_require_min_attendance' => [
                'label' => 'They must have met the attendance minimum',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Checked against Attendance → minimum attendance, so the figure lives in '
                    .'one place and this only says whether to apply it.',
                'span' => 6,
                'sort' => 560,
            ],
            'certificate_require_min_progress' => [
                'label' => 'Minimum syllabus progress',
                // TYPE_DECIMAL, not TYPE_NUMBER: STORAGE_TYPES maps NUMBER to `integer`, so a
                // percentage declared as a number would be stored with its decimals shaved off and
                // `100.0000` would round-trip as `100`. A percentage is decimal(8,4) everywhere else
                // in this system and the setting has to agree with the column it is compared against.
                'type' => self::TYPE_DECIMAL,
                // `decimal:0,4` is what refuses exponent notation — `numeric` alone accepts `1E1`
                // and stores a value no decimal(8,4) column can parse (D114).
                'rules' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
                'default' => '100.0000',
                'suffix' => '%',
                'help' => 'How much of the syllabus must be marked covered. Zero turns the rule off.',
                'span' => 3,
                'sort' => 570,
            ],
            'certificate_require_fee_cleared' => [
                'label' => 'Their fees must be cleared',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Asked of the fee ledger at the moment of issue, and the answer is '
                    .'snapshotted onto the certificate as evidence.',
                'span' => 6,
                'sort' => 580,
            ],
            'certificate_require_enrollment_completed' => [
                'label' => 'Their enrolment must be marked completed',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'span' => 6,
                'sort' => 590,
            ],
            'certificate_grade_source' => [
                'label' => 'Where the certificate’s grade comes from',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:final_exam,best_exam,weighted_average,manual'],
                'default' => 'weighted_average',
                'options' => [
                    'final_exam' => 'The final exam',
                    'best_exam' => 'Their best exam',
                    'weighted_average' => 'A weighted average of every exam',
                    'manual' => 'Typed by whoever issues it',
                ],
                'help' => 'A weighted average reads each exam’s weight; an exam with none is weighted '
                    .'equally with its peers.',
                'span' => 6,
                'sort' => 600,
            ],
            'certificate_issue_requires_approval' => [
                'label' => 'A second person approves before a certificate is issued',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Adds the approve step to issuing, the same shape as checking a result '
                    .'sheet — and for the same reason.',
                'span' => 6,
                'sort' => 610,
            ],

            // -------------------------------------------------- the public verification page (§84)

            'certificate_verification_reveals' => [
                'label' => 'What the public verification page shows',
                'type' => self::TYPE_MULTISELECT,
                'rules' => ['nullable', 'array'],
                // INV-21-3 in one setting. Deliberately narrow by default: a verification page exists
                // to confirm a document, not to publish a student record. A phone, email, CNIC,
                // address, guardian, fee or collaborator field is NOT on the list at all and cannot
                // be added by a query parameter or by widening this.
                'default' => ['student_name', 'course_name', 'completion_date', 'grade'],
                'options' => [
                    'student_name' => 'Student name',
                    'father_name' => 'Father’s name',
                    'course_name' => 'Course',
                    'batch_name' => 'Batch',
                    'completion_date' => 'Completion date',
                    'grade' => 'Grade',
                    'percentage' => 'Percentage',
                    'trainer_name' => 'Trainer',
                    'attendance_percentage' => 'Attendance',
                    'photo' => 'Photograph',
                ],
                'help' => 'Anyone with the code can see these. The status is always shown — a revoked '
                    .'certificate says so, which is the point of the page.',
                'span' => 6,
                'sort' => 620,
            ],
            'certificate_verification_rate_limit_per_minute' => [
                'label' => 'Verification attempts allowed per minute',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:1000'],
                'default' => 20,
                'help' => 'Per address. A visitor scanning their own certificate makes one attempt; '
                    .'a stream of them is somebody walking the code space.',
                'span' => 3,
                'sort' => 630,
            ],
            'certificate_verification_log_retention_days' => [
                'label' => 'Keep the verification log for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:3650'],
                'default' => 365,
                'suffix' => 'days',
                'help' => 'Zero keeps it for ever. The log is the rate limiter’s evidence, so pruning '
                    .'it short also shortens how far back an abuse pattern can be seen.',
                'span' => 3,
                'sort' => 640,
            ],
            'certificate_footer_note' => [
                'label' => 'Certificate footer note',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:500'],
                'default' => null,
                'help' => 'Printed on every certificate through {footer_note}, unless a template '
                    .'leaves that token out.',
                'span' => 6,
                'sort' => 650,
            ],

            // ------------------------------------------------------------------- ID cards (§85)

            'id_card_prefix' => [
                'label' => 'ID card number prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'SIC-',
                'span' => 3,
                'sort' => 660,
            ],
            'id_card_next_number' => [
                'label' => 'Next ID card number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1'],
                'default' => 1,
                // D62.
                'readonly' => true,
                'span' => 3,
                'sort' => 670,
            ],
            'id_card_validity_months' => [
                'label' => 'A card is valid for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:120'],
                'default' => 12,
                'suffix' => 'months',
                'help' => 'Zero issues cards with no expiry date. A dated card that has passed its '
                    .'date is swept to “expired”; an undated one never is.',
                'span' => 3,
                'sort' => 680,
            ],
            'id_card_require_photo' => [
                'label' => 'A card needs a photograph',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'The photograph is copied onto the card at issue time, so changing it later '
                    .'never alters a card already in somebody’s wallet.',
                'span' => 6,
                'sort' => 690,
            ],
            'id_card_batch_print_max' => [
                'label' => 'Most cards in one batch print',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:1000'],
                'default' => 100,
                'help' => 'A ceiling on one request, so a whole institute is never rendered into one '
                    .'PDF by accident.',
                'span' => 3,
                'sort' => 700,
            ],
            'certificate_bulk_issue_max' => [
                'label' => 'Most certificates issued in one go',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:500'],
                'default' => 100,
                // Issuing spends a number from the institute's sequence and freezes a document per
                // row, so one accidental request should not be able to spend five hundred of them.
                // Each certificate is still issued on its own and a refusal stops only that one.
                'help' => 'A ceiling on one request. Each certificate is still checked and issued '
                    .'separately, and one refusal leaves the rest alone.',
                'span' => 3,
                'sort' => 710,
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

            /*
            |------------------------------------------------------------------
            | phase-10-12 §5 — three document series and five money rules.
            |------------------------------------------------------------------
            */

            'project_payment_prefix' => [
                'label' => 'Project payment prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'PP-',
                'span' => 4,
                'sort' => 100,
            ],
            'project_payment_next_number' => [
                'label' => 'Next project payment number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1'],
                'default' => 1,
                'help' => 'Advanced only by the numbering service, under a row lock, never by this form (D62).',
                'readonly' => true,
                'span' => 4,
                'sort' => 110,
            ],
            'payment_reversal_prefix' => [
                'label' => 'Reversal prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'RV-',
                'span' => 4,
                'sort' => 120,
            ],
            'payment_reversal_next_number' => [
                'label' => 'Next reversal number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1'],
                'default' => 1,
                'help' => 'Advanced only by the numbering service, under a row lock, never by this form (D62).',
                'readonly' => true,
                'span' => 4,
                'sort' => 130,
            ],
            'collaborator_payout_prefix' => [
                'label' => 'Payout voucher prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'PO-',
                'span' => 4,
                'sort' => 140,
            ],
            'collaborator_payout_next_number' => [
                'label' => 'Next payout voucher number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1'],
                'default' => 1,
                'help' => 'Advanced only by the numbering service, under a row lock, never by this form (D62).',
                'readonly' => true,
                'span' => 4,
                'sort' => 150,
            ],
            'backdate_limit_days' => [
                'label' => 'How far a payment may be back-dated',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'between:0,365'],
                'default' => 30,
                'suffix' => 'days',
                'help' => 'The value date selects the commission rule and the referral, so a receipt dated last year would earn at last year\'s rate. Never in the future, whatever this is set to.',
                'span' => 6,
                'sort' => 160,
            ],
            'refund_approval_required' => [
                'label' => 'Refunds need approval',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Commission is not clawed back until the reversal is approved — a refund somebody entered and one somebody authorised are different facts.',
                'span' => 6,
                'sort' => 170,
            ],
            'refund_approval_threshold' => [
                'label' => 'Refunds above this always need approval',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
                'default' => '0.00',
                'help' => 'Applies even when the switch above is off. 0 means the switch alone decides.',
                'span' => 6,
                'sort' => 180,
            ],
            'payout_reference_required' => [
                'label' => 'A payout needs a transaction reference',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Marking a payout paid without one leaves "did it actually go out" unanswerable.',
                'span' => 6,
                'sort' => 190,
            ],
            'wallet_reconcile_enabled' => [
                'label' => 'Prove every wallet nightly',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Re-derives each balance from the ledger and records the proof. Switching it off does not make the balances wrong — it stops anybody finding out.',
                'span' => 6,
                'sort' => 200,
            ],

            /*
            |------------------------------------------------------------------
            | phase-13 §5 — two more document series, and the rules for invoices,
            | expenses and the finance reports.
            |------------------------------------------------------------------
            |
            | Every default here is the cautious one. Round-off is **off**, so the
            | arithmetic never changes unless the business asks for it. Reminders
            | are **off**, so the system never emails a client unasked. And
            | self-approval is **off**, because an expense somebody approved for
            | themselves is not an approval.
            */

            'expense_prefix' => [
                'label' => 'Expense voucher prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'EXP-',
                'span' => 4,
                'sort' => 210,
            ],
            'expense_next_number' => [
                'label' => 'Next expense number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1'],
                'default' => 1,
                'help' => 'Locked in the same transaction as the expense, so two people recording at once cannot share a number.',
                // D62: readonly, so the finance form never posts it. A stale save that rolled a counter
                // back would hand the next voucher a number that already exists.
                'readonly' => true,
                'span' => 4,
                'sort' => 220,
            ],
            'income_prefix' => [
                'label' => 'Other income prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\-\/]*$/'],
                'default' => 'INC-',
                'span' => 4,
                'sort' => 230,
            ],
            'income_next_number' => [
                'label' => 'Next other-income number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1'],
                'default' => 1,
                // D62: readonly, so the finance form never posts it. A stale save that rolled a counter
                // back would hand the next voucher a number that already exists.
                'readonly' => true,
                'span' => 4,
                'sort' => 240,
            ],

            'expense_approval_threshold' => [
                'label' => 'Only expenses above this need approval',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
                'default' => '0.00',
                'help' => '0 means every expense needs approval while the switch above is on. The decision is snapshotted onto each expense, so raising this later never re-opens what was already approved.',
                'span' => 6,
                'sort' => 250,
            ],
            'expense_self_approval_allowed' => [
                'label' => 'Somebody may approve their own expense',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Off, because an expense somebody approved for themselves is not an approval. Turn it on only in a one-person finance team.',
                'span' => 6,
                'sort' => 260,
            ],
            'expense_receipt_required' => [
                'label' => 'An expense needs a receipt',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'span' => 6,
                'sort' => 270,
            ],
            'expense_receipt_threshold' => [
                'label' => 'Only above this amount',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
                'default' => '0.00',
                'help' => '0 means every expense needs one while the switch above is on.',
                'span' => 6,
                'sort' => 280,
            ],

            'invoice_round_off_enabled' => [
                'label' => 'Round invoice totals',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Off by default: rounding changes what a client is asked to pay, and the difference is printed as its own signed line so nobody has to work out where it came from.',
                'span' => 6,
                'sort' => 290,
            ],
            'invoice_rounding_precision' => [
                'label' => 'Round to the nearest',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'in:1,5,10'],
                'options' => ['1' => '1', '5' => '5', '10' => '10'],
                'default' => '1',
                'span' => 6,
                'sort' => 300,
            ],
            'invoice_allow_edit_after_issue' => [
                'label' => 'An issued invoice may still be corrected',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Only while nothing has been paid against it, and the change is fully audited. Switch it off and the only correction is cancel and replace, which burns an invoice number for a typo.',
                'span' => 6,
                'sort' => 310,
            ],
            'invoice_public_link_enabled' => [
                'label' => 'Clients can open an invoice by link',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'A client record can exist without a login, so without this some clients have no way to read their own invoice.',
                'span' => 6,
                'sort' => 320,
            ],
            'invoice_public_link_days' => [
                'label' => 'A link stays valid for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:365'],
                'default' => 30,
                'suffix' => 'days',
                'span' => 6,
                'sort' => 330,
            ],
            'invoice_email_subject' => [
                'label' => 'Invoice email subject',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:190'],
                'default' => 'Invoice {invoice_number} from {company_name}',
                'help' => 'Placeholders: {invoice_number}, {company_name}, {client_name}, {total_amount}, {due_date}.',
                'span' => 6,
                'sort' => 340,
            ],
            'invoice_email_body' => [
                'label' => 'Invoice email message',
                'type' => self::TYPE_RICHTEXT,
                'rules' => ['nullable', 'string', 'max:5000'],
                'default' => '<p>Dear {client_name},</p><p>Please find invoice {invoice_number} for {total_amount}, due {due_date}.</p><p>Thank you.</p>',
                'span' => 12,
                'sort' => 350,
            ],
            'invoice_reminders_enabled' => [
                'label' => 'Send payment reminders',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Off, because the system should never email a client unasked. Turning it on is a decision about the relationship, not a setting.',
                'span' => 6,
                'sort' => 360,
            ],
            'invoice_reminder_days_after_due' => [
                'label' => 'Remind this many days after the due date',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:64', 'regex:/^\d+(,\d+)*$/'],
                'default' => '3,7,14',
                'help' => 'A comma-separated list.',
                'span' => 6,
                'sort' => 370,
            ],
            'invoice_show_bank_details' => [
                'label' => 'Print bank details on the invoice',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'span' => 6,
                'sort' => 380,
            ],

            'report_sync_row_limit' => [
                'label' => 'Export rows before queueing',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:100', 'max:100000'],
                'default' => 5000,
                'help' => 'Above this a report export is built in the background and delivered by notification, rather than holding a request open until it times out.',
                'span' => 6,
                'sort' => 390,
            ],
            'reports_include_institute' => [
                'label' => 'Finance reports include the institute',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off shows the software house alone. The reports say which one they are showing either way, so a figure is never ambiguous about what it covers.',
                'span' => 6,
                'sort' => 400,
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
            /*
            | Enforced by EnsureUserIsActive: a password older than this many days (measured from
            | users.password_changed_at, or the account's creation when it was never changed) sends
            | the next request to the existing change-password screen. 0 switches expiry off.
            */
            'force_password_change_days' => [
                'label' => 'Force a password change every',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:'.self::FORCE_PASSWORD_CHANGE_DAYS_MAX],
                'default' => 0,
                'help' => 'Days a password stays valid. After that the person must choose a new one before doing anything else. 0 turns expiry off.',
                'suffix' => 'days',
                'span' => 4,
                'sort' => 20,
            ],
            'login_max_attempts' => [
                'label' => 'Failed sign-in attempts allowed',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:'.self::LOGIN_MAX_ATTEMPTS_MIN, 'max:'.self::LOGIN_MAX_ATTEMPTS_MAX],
                'default' => 5,
                'help' => 'Per email address and IP, between 3 and 10. Enforced by the sign-in form, which never allows more than 10 whatever is stored.',
                'span' => 4,
                'sort' => 30,
            ],
            'lockout_minutes' => [
                'label' => 'Lockout duration',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:'.self::LOCKOUT_MINUTES_MIN, 'max:'.self::LOCKOUT_MINUTES_MAX],
                'default' => 15,
                'help' => 'How long the sign-in form refuses an email address and IP after the allowed attempts. Never shorter than 5 minutes.',
                'suffix' => 'minutes',
                'span' => 4,
                'sort' => 40,
            ],
            'session_lifetime' => [
                'label' => 'Session lifetime',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:'.self::SESSION_LIFETIME_MIN, 'max:'.self::SESSION_LIFETIME_MAX],
                'default' => 120,
                'help' => 'Idle minutes before a session expires, between 15 minutes and 24 hours.',
                'suffix' => 'minutes',
                'span' => 4,
                'sort' => 50,
            ],
            /*
            | Read by UpdateAvatarRequest through uploadExtensions() / uploadKilobytes(): a profile
            | photo accepts only the image types this list also allows, up to the smallest of its own
            | cap, max_upload_mb and PHP's upload limit. Later upload fields read the same two helpers.
            | A never-uploadable extension is refused here and stripped again where it is read.
            */
            'allowed_file_types' => [
                'label' => 'Allowed upload types',
                'type' => self::TYPE_TEXT,
                'rules' => [
                    'required',
                    'string',
                    'max:255',
                    'regex:/^[A-Za-z0-9]+(,[A-Za-z0-9]+)*$/',
                    'not_regex:/(^|,)('.implode('|', array_map(static fn (string $extension): string => preg_quote($extension, '/'), self::NEVER_UPLOADABLE_EXTENSIONS)).'|php[0-9]*)(,|$)/i',
                ],
                // Phase 2 review low 6: gif is allowed by default so a GIF profile photo is accepted
                // (uploads are content-sniffed; php-family extensions stay refused whatever this says).
                //
                // phase-19-23 widened the default rather than the rule. §5.1 defaults
                // `institute.material_allowed_types` to **all ten** of §79's kinds, which is a
                // statement that all ten work out of the box — and with the Phase 1 list they did
                // not: video and audio offered no extension at all, and slides and notes collapsed
                // to PDF. The list below is the union of what the existing upload maps actually
                // name, so widening it **cannot widen any single field**: every uploader passes its
                // own map to `uploadExtensions()`, which intersects. Client documents gain nothing
                // from `mp4` because `ClientDocumentService::TYPES` has never listed it.
                'default' => 'pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,rtf,txt,md,csv,'
                    .'png,jpg,jpeg,gif,webp,mp4,webm,ogv,mov,mp3,ogg,weba,m4a,wav,zip,7z',
                'help' => 'Comma-separated extensions. An upload field accepts only its own types that are also listed here — this narrows every field and widens none. Script and executable types are never accepted, whatever this says.',
                'span' => 8,
                'sort' => 60,
            ],
            'max_upload_mb' => [
                'label' => 'Maximum upload size',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:'.self::MAX_UPLOAD_MB_MAX],
                'default' => 10,
                'help' => 'Caps every upload field that reads it (profile photos today), never above the field’s own limit. PHP’s upload limit still applies and wins when it is lower.',
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

            /*
            |------------------------------------------------------------------------------------
            | phase-24-25 5.3 - transport, headers, rate limits, sessions, uploads, proxies.
            |
            | None of Phase 2 section 5's eight keys is redefined. Two pairs read as duplicates and
            | are not:
            |
            |   - `session_lifetime` is the idle timeout and stays the only authority for it.
            |     `session_absolute_lifetime_hours` is a second, independent ceiling: it ends a
            |     session that has been active all along, which an idle timeout by definition never
            |     does.
            |
            |   - `login_max_attempts` is the lockout count and stays the only authority for it.
            |     `login_throttle_per_minute` is a rate. RateLimitServiceProvider reconciles them so
            |     no two screens can show contradictory numbers.
            |------------------------------------------------------------------------------------
            */

            // ------------------------------------------------------------------- transport
            'force_https' => [
                'label' => 'Require HTTPS',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Redirects http to https and generates https URLs. Ignored on a local '
                    .'machine, which has no certificate and would redirect into nothing.',
                'span' => 4,
                'sort' => 100,
            ],
            'hsts_enabled' => [
                'label' => 'Send HSTS',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                // Readonly on purpose, and it stays readonly until a person edits this line. HSTS
                // tells every browser to refuse http for a year; switched on before the certificate
                // works, it locks users out of a site that cannot yet serve them, and nothing on
                // the server can take it back. That is a deployment decision, so it belongs in
                // version control rather than behind a checkbox.
                'readonly' => true,
                'help' => 'Locked until HTTPS is verified in production. Once sent, browsers refuse '
                    .'plain http for the whole max-age and no server-side change can undo it.',
                'span' => 4,
                'sort' => 110,
            ],
            'hsts_max_age' => [
                'label' => 'HSTS max age',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:300', 'max:63072000'],
                'default' => 31536000,
                'suffix' => 'seconds',
                'help' => 'One year by default. Start low when first enabling HSTS - the value is a '
                    .'promise the browser keeps even after you change your mind.',
                'span' => 4,
                'sort' => 120,
            ],
            'hsts_include_subdomains' => [
                'label' => 'Apply HSTS to subdomains',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Off by default: it covers subdomains that do not exist yet, including '
                    .'ones that may never get a certificate.',
                'span' => 12,
                'sort' => 130,
            ],

            // ------------------------------------------------------------------- content security
            'csp_enabled' => [
                'label' => 'Send a content security policy',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Declares which origins may supply scripts, styles, images and frames. The '
                    .'policy is built per response with a nonce, never from a static string.',
                'span' => 4,
                'sort' => 200,
            ],
            'csp_report_only' => [
                'label' => 'Report violations without blocking',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                // Report-only first is not timidity: a policy that blocks before anybody has read a
                // report breaks a working screen to protect against nothing in particular.
                'help' => 'On by default. Run report-only until the report log is clean, then switch '
                    .'to enforcing - the go-live checklist does exactly that.',
                'span' => 4,
                'sort' => 210,
            ],
            'frame_ancestors' => [
                'label' => 'Who may frame this site',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'options' => [
                    'none' => 'Nobody',
                    'self' => 'This site only',
                ],
                'default' => 'none',
                'help' => 'There is no third option. An admin panel inside somebody else frame is a '
                    .'clickjacking target, and no feature here needs to be embedded.',
                'span' => 4,
                'sort' => 220,
            ],
            'csp_report_uri' => [
                'label' => 'Send violation reports to',
                'type' => self::TYPE_URL,
                'rules' => ['nullable', 'url', 'max:255'],
                'default' => null,
                'help' => 'Left empty, violations are logged locally. The endpoint is rate-limited '
                    .'and the body is capped, because a report endpoint is an unauthenticated one.',
                'span' => 12,
                'sort' => 230,
            ],

            // ------------------------------------------------------------------- rate limits
            'login_throttle_per_minute' => [
                'label' => 'Sign-in attempts per minute',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:30'],
                'default' => 5,
                'help' => 'A rate, not the lockout count above: this slows a guessing run down, '
                    .'while Failed sign-in attempts allowed decides when the account closes.',
                'span' => 3,
                'sort' => 300,
            ],
            'global_write_throttle_per_minute' => [
                'label' => 'Writes per minute, per user',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:30', 'max:6000'],
                'default' => 120,
                'help' => 'Every authenticated POST, PUT, PATCH and DELETE. High enough that no real '
                    .'person reaches it, low enough that a script does.',
                'span' => 3,
                'sort' => 310,
            ],
            'export_throttle_per_minute' => [
                'label' => 'Exports per minute, per user',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:120'],
                'default' => 10,
                'help' => 'An export reads far more rows than a screen does, so it is limited '
                    .'separately from ordinary writes.',
                'span' => 3,
                'sort' => 320,
            ],
            'print_throttle_per_minute' => [
                'label' => 'Print views per minute, per user',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:120'],
                'default' => 20,
                'span' => 3,
                'sort' => 330,
            ],

            // ------------------------------------------------------------------- sessions
            'session_absolute_lifetime_hours' => [
                'label' => 'End any session after',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:720'],
                'default' => 24,
                'suffix' => 'hours',
                'help' => 'Counted from sign-in and enforced however active the session has been - '
                    .'which is the case the idle timeout above can never catch.',
                'span' => 4,
                'sort' => 400,
            ],
            'session_single_device' => [
                'label' => 'One device at a time',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'On, a new sign-in ends every other session for that account. Off, a person '
                    .'may stay signed in on a phone and a desktop at once.',
                'span' => 4,
                'sort' => 410,
            ],
            'password_history_count' => [
                'label' => 'Refuse the last',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:10'],
                'default' => 3,
                'suffix' => 'passwords',
                'help' => 'A forced password change that lets somebody set the same password again '
                    .'has changed nothing. 0 turns the check off.',
                'span' => 4,
                'sort' => 420,
            ],

            // ------------------------------------------------------------------- uploads
            'upload_blocked_extensions' => [
                'label' => 'Never-accepted extensions',
                'type' => self::TYPE_JSON,
                'rules' => ['required', 'array', 'min:1'],
                'item_rules' => ['*' => ['required', 'string', 'max:16', 'regex:/^[a-z0-9]+$/']],
                // The floor is NEVER_UPLOADABLE_EXTENSIONS, a constant no setting can lower. This
                // list adds to it: svg (it carries script), and env (it carries credentials).
                'default' => [...self::NEVER_UPLOADABLE_EXTENSIONS, 'svg', 'env'],
                'help' => 'Checked as well as each endpoint own allowlist, against the whole filename '
                    .'and every dot-segment of it, so archive.php.jpg is refused. Removing an entry '
                    .'here cannot un-block one the code blocks outright.',
                'span' => 12,
                'sort' => 500,
            ],
            'upload_require_mime_match' => [
                'label' => 'Sniff every upload',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                // Readonly, and with no plan to change: an extension is a claim the uploader makes
                // and finfo is the only thing that checks it. There is no legitimate reason to
                // accept a file whose contents disagree with its name.
                'readonly' => true,
                'help' => 'The real content type must match what the endpoint accepts. Not editable - '
                    .'an extension is a claim, and this is the only thing that checks it.',
                'span' => 6,
                'sort' => 510,
            ],

            // ------------------------------------------------------------------- network
            'trusted_proxies' => [
                'label' => 'Trusted proxies',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:255'],
                'default' => null,
                'help' => 'Comma-separated addresses or CIDR ranges, or * behind a load balancer you '
                    .'control. Empty means none - and with none, a forwarded IP header is ignored '
                    .'rather than believed, so a rate limit cannot be evaded by claiming an address.',
                'span' => 6,
                'sort' => 600,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    /**
     * phase-05 section 5 - the CRM group.
     *
     * @return array<string, array<string, mixed>>
     */
    /**
     * phase-06 §5 — the seventeen keys that govern project numbering, progress and time tracking.
     *
     * `default_task_estimate_minutes` lives here rather than as a constant inside a service on purpose:
     * §6.3 needs a weight for a task nobody estimated, and a documented, editable number is honest where a
     * magic 60 buried in a method is not.
     *
     * @return array<string, array<string, mixed>>
     */
    /**
     * phase-07 §5 — the forty-four keys that govern HR numbering, attendance, leave policy and payroll.
     *
     * The five `*_next_number` counters are **readonly** (D62): only `DocumentNumberService` advances one,
     * under a row lock inside the caller\'s transaction. A settings form posts every field, so an admin
     * saving an unrelated key with a stale counter would re-issue a slip number that is already printed.
     *
     * `payroll_day_basis` and `lop_basis` are the two that decide what a day of pay is worth. They are
     * snapshotted onto every payroll run, so changing them next year cannot change what last year\'s slip
     * meant.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function hrFields(): array
    {
        $counter = static fn (string $label, int $sort): array => [
            'label' => $label,
            'type' => self::TYPE_NUMBER,
            'rules' => ['nullable', 'integer', 'min:1'],
            'default' => 1,
            'help' => 'Advanced only by the numbering service, under a row lock, never by this form (D62).',
            'readonly' => true,
            'span' => 4,
            'sort' => $sort,
        ];

        return [
            'employee_code_prefix' => [
                'label' => 'Employee ID prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:12'],
                'default' => 'EMP-',
                'span' => 4,
                'sort' => 10,
            ],
            'employee_code_next_number' => $counter('Next employee number', 20),
            'leave_request_prefix' => [
                'label' => 'Leave request prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:12'],
                'default' => 'LVR-',
                'span' => 4,
                'sort' => 30,
            ],
            'leave_request_next_number' => $counter('Next leave request number', 40),
            'advance_number_prefix' => [
                'label' => 'Advance number prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:12'],
                'default' => 'ADV-',
                'span' => 4,
                'sort' => 50,
            ],
            'advance_next_number' => $counter('Next advance number', 60),
            'payroll_run_prefix' => [
                'label' => 'Payroll run prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:12'],
                'default' => 'PR-',
                'span' => 4,
                'sort' => 70,
            ],
            'payroll_run_next_number' => $counter('Next payroll run number', 80),
            'payslip_prefix' => [
                'label' => 'Salary slip prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:12'],
                'default' => 'SLP-',
                'span' => 4,
                'sort' => 90,
            ],
            'payslip_next_number' => $counter('Next salary slip number', 100),

            'weekend_days' => [
                'label' => 'Weekly off days',
                'type' => self::TYPE_MULTISELECT,
                'rules' => ['nullable', 'array'],
                'default' => ['sunday'],
                'options' => [
                    'monday' => 'Monday',
                    'tuesday' => 'Tuesday',
                    'wednesday' => 'Wednesday',
                    'thursday' => 'Thursday',
                    'friday' => 'Friday',
                    'saturday' => 'Saturday',
                    'sunday' => 'Sunday',
                ],
                'help' => 'The default. A shift, or one employee, may override it.',
                'span' => 6,
                'sort' => 110,
            ],
            'late_grace_minutes' => [
                'label' => 'Late grace (minutes)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:240'],
                'default' => 15,
                'help' => 'The default for a new shift; a shift keeps its own value once created.',
                'span' => 3,
                'sort' => 120,
            ],
            'early_leave_grace_minutes' => [
                'label' => 'Early leave grace (minutes)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:240'],
                'default' => 10,
                'span' => 3,
                'sort' => 130,
            ],
            'full_day_min_minutes' => [
                'label' => 'Minutes that make a full day',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:1440'],
                'default' => 480,
                'span' => 3,
                'sort' => 140,
            ],
            'half_day_min_minutes' => [
                'label' => 'Minutes that make a half day',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:1440'],
                'default' => 240,
                'span' => 3,
                'sort' => 150,
            ],
            'short_day_as_half_day' => [
                'label' => 'Treat a short day as a half day',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'When on, a day between the two thresholds pays half instead of full.',
                'span' => 6,
                'sort' => 160,
            ],
            'auto_absent_enabled' => [
                'label' => 'Mark a working day with no punch absent',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'span' => 6,
                'sort' => 170,
            ],
            'attendance_day_close_time' => [
                'label' => 'Close the attendance day at',
                'type' => self::TYPE_TIME,
                'rules' => ['required', 'date_format:H:i'],
                'default' => '23:50',
                'span' => 4,
                'sort' => 180,
            ],
            'self_check_in_enabled' => [
                'label' => 'Let staff punch in themselves',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Turns the self-service punch off without touching anybody\'s permissions.',
                'span' => 4,
                'sort' => 190,
            ],
            'self_check_in_ip_whitelist' => [
                'label' => 'Allowed punch-in addresses',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:2000'],
                // null, not '': the store keeps no difference between an empty string and "not set", so a
                // '' default would fail its own round-trip the first time anybody saved the group.
                'default' => null,
                'help' => 'One IP or CIDR per line. Empty means anywhere. A refused punch is logged with its address.',
                'span' => 12,
                'sort' => 200,
            ],
            'attendance_correction_window_days' => [
                'label' => 'Staff may ask to correct the last (days)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:365'],
                'default' => 7,
                'span' => 4,
                'sort' => 210,
            ],
            'attendance_correction_requires_approval' => [
                'label' => 'A correction request needs approval',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off lets a holder of attendance.edit apply one directly — the correction row is still written either way (HR-6).',
                'span' => 8,
                'sort' => 220,
            ],
            'overtime_pay_enabled' => [
                'label' => 'Pay overtime',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Overtime minutes are always measured; paying them is opt-in and still a manual component.',
                'span' => 6,
                'sort' => 230,
            ],
            'late_deduction_lates_per_day' => [
                'label' => 'Lates that cost a day of pay',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:30'],
                'default' => 0,
                'help' => 'Zero switches late deductions off. Three means every third late costs one day.',
                'span' => 6,
                'sort' => 240,
            ],
            'document_expiry_reminder_days' => [
                'label' => 'Warn about an expiring document (days ahead)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:365'],
                'default' => 30,
                'span' => 4,
                'sort' => 250,
            ],

            'leave_year_start_month' => [
                'label' => 'Leave year starts in',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'integer', 'between:1,12'],
                'default' => 1,
                'options' => [self::class, 'monthOptions'],
                'help' => 'The leave year need not be the calendar year.',
                'span' => 4,
                'sort' => 260,
            ],
            'leave_accrual_run_day' => [
                'label' => 'Monthly accrual posts on day',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:28'],
                'default' => 1,
                'help' => 'Capped at 28 so every month has the day.',
                'span' => 4,
                'sort' => 270,
            ],
            'leave_carry_forward_enabled' => [
                'label' => 'Allow carry-forward',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'A master switch above each leave type\'s own flag.',
                'span' => 4,
                'sort' => 280,
            ],
            'leave_negative_balance_allowed' => [
                'label' => 'Allow a negative leave balance',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Off means an approval is refused with the exact shortfall named (HR-9).',
                'span' => 6,
                'sort' => 290,
            ],
            'leave_default_approval_levels' => [
                'label' => 'Default approval levels',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'between:1,2'],
                'default' => 1,
                'span' => 6,
                'sort' => 300,
            ],

            'payroll_day_basis' => [
                'label' => 'A day of pay is',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:calendar_days,working_days,fixed_30'],
                'default' => 'calendar_days',
                'options' => [
                    'calendar_days' => 'Gross / days in the month',
                    'working_days' => 'Gross / working days in the month',
                    'fixed_30' => 'Gross / 30',
                ],
                'help' => 'Snapshotted onto every run, so changing it cannot change what a past slip meant.',
                'span' => 6,
                'sort' => 310,
            ],
            'lop_basis' => [
                'label' => 'Loss of pay is deducted from',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:basic,gross'],
                'default' => 'gross',
                'options' => ['basic' => 'Basic salary', 'gross' => 'Gross salary'],
                'span' => 6,
                'sort' => 320,
            ],
            'unpaid_leave_deduction_enabled' => [
                'label' => 'Deduct unpaid leave',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off tracks unpaid leave without ever deducting it.',
                'span' => 6,
                'sort' => 330,
            ],
            'payroll_net_rounding' => [
                'label' => 'Round the net salary',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:none,nearest_1,nearest_10'],
                'default' => 'none',
                'options' => [
                    'none' => 'Not at all',
                    'nearest_1' => 'To the nearest 1',
                    'nearest_10' => 'To the nearest 10',
                ],
                'help' => 'Posted as a visible ROUNDING line, so the slip still adds up.',
                'span' => 6,
                'sort' => 340,
            ],
            'tax_mode' => [
                'label' => 'Tax',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:none,fixed_percentage,manual'],
                'default' => 'manual',
                'options' => [
                    'none' => 'No tax line',
                    'fixed_percentage' => 'A fixed percentage of taxable gross',
                    'manual' => 'Entered per employee',
                ],
                'span' => 6,
                'sort' => 350,
            ],
            'tax_default_rate' => [
                'label' => 'Tax rate (%)',
                'type' => self::TYPE_DECIMAL,
                // decimal:0,4 — `numeric` alone would accept '1e3', which no decimal(8,4) column holds.
                'rules' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
                'scale' => 4,
                'default' => '0.0000',
                'help' => 'Used only by the fixed-percentage mode.',
                'span' => 6,
                'sort' => 360,
            ],

            'advance_max_multiple_of_basic' => [
                'label' => 'An advance may reach this multiple of basic',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:12'],
                'scale' => 4,
                'default' => '1.0000',
                'help' => 'Above it, the request needs an approval and a reason.',
                'span' => 4,
                'sort' => 370,
            ],
            'advance_recovery_default_installments' => [
                'label' => 'Default recovery installments',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:36'],
                'default' => 1,
                'span' => 4,
                'sort' => 380,
            ],
            'advance_recovery_cap_percent' => [
                'label' => 'Recovery may take at most (% of net)',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
                'scale' => 4,
                'default' => '50.0000',
                'help' => 'Whatever is still owed, a slip never gives up more of its net than this.',
                'span' => 4,
                'sort' => 390,
            ],

            'payslip_show_attendance' => [
                'label' => 'Print the attendance block on a slip',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'span' => 6,
                'sort' => 400,
            ],
            'payslip_footer_note' => [
                'label' => 'Slip footer note',
                'type' => self::TYPE_TEXTAREA,
                'rules' => ['nullable', 'string', 'max:500'],
                'default' => null,
                'span' => 12,
                'sort' => 410,
            ],
            'employee_self_service_enabled' => [
                'label' => 'Staff can use their own HR screens',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'A master switch above the module toggle and above anybody\'s permissions.',
                'span' => 6,
                'sort' => 420,
            ],
            'payroll_reminder_day' => [
                'label' => 'Remind HR to run payroll on day',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:28'],
                'default' => 25,
                'span' => 6,
                'sort' => 430,
            ],
        ];
    }

    /**
     * 1-12 => January-December, for the leave-year selector.
     *
     * @return array<int, string>
     */
    public static function monthOptions(): array
    {
        $months = [];

        foreach (range(1, 12) as $month) {
            $months[$month] = date('F', mktime(0, 0, 0, $month, 1));
        }

        return $months;
    }

    private static function projectsFields(): array
    {
        return [
            'project_code_prefix' => [
                'label' => 'Project code prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:12'],
                'default' => 'PRJ-',
                'help' => 'Requirement §20\'s "Project ID"; the counter below is zero-padded to five digits.',
                'span' => 4,
                'sort' => 10,
            ],
            'project_code_next_number' => [
                'label' => 'Next project number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1'],
                'default' => 1,
                'help' => 'Advanced only by the numbering service, under a row lock, never by this form (D62).',
                'readonly' => true,
                'span' => 4,
                'sort' => 20,
            ],
            'default_progress_basis' => [
                'label' => 'Derive progress from',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'default' => ProgressBasis::Milestones->value,
                'options' => ProgressBasis::options(),
                'help' => 'Seeds a new project; each project can then be switched on its own.',
                'span' => 4,
                'sort' => 30,
            ],
            'progress_manual_override_enabled' => [
                'label' => 'Allow a manual progress override',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'When off, progress is purely derived and the manual mode is refused outright.',
                'span' => 6,
                'sort' => 40,
            ],
            'default_task_estimate_minutes' => [
                'label' => 'Assumed estimate for an unestimated task (minutes)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:10080'],
                'default' => 60,
                'help' => 'The weight a task with no estimate carries in the progress average.',
                'span' => 6,
                'sort' => 50,
            ],
            'allow_time_without_task' => [
                'label' => 'Allow time logged against a project with no task',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Meetings, setup and other work that belongs to the project rather than to one task.',
                'span' => 6,
                'sort' => 60,
            ],
            'timer_auto_stop_enabled' => [
                'label' => 'Stop a forgotten timer automatically',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'span' => 6,
                'sort' => 70,
            ],
            'timer_max_hours' => [
                'label' => 'Stop a timer after (hours)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:24'],
                'default' => 12,
                'help' => 'The business counterpart of chk_te_duration, which caps one entry at 24 hours whatever a clock change does.',
                'span' => 4,
                'sort' => 80,
            ],
            'manual_time_backdate_limit_days' => [
                'label' => 'Backdate a manual entry without a reason for (days)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:365'],
                'default' => 7,
                'help' => 'Beyond this a written reason is mandatory and the entry needs time_tracking.change_status.',
                'span' => 4,
                'sort' => 90,
            ],
            'manual_time_max_hours_per_day' => [
                'label' => 'Maximum hours one person may log for a day',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:24'],
                'default' => 16,
                'span' => 4,
                'sort' => 100,
            ],
            'working_hours_per_day' => [
                'label' => 'Working hours per day',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:24'],
                'default' => 8,
                'help' => 'The denominator of the utilisation figure in the weekly rollup.',
                'span' => 6,
                'sort' => 110,
            ],
            'working_days_per_week' => [
                'label' => 'Working days per week',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:7'],
                'default' => 6,
                'help' => 'With the week start in Localization, this defines "this week" in every time report.',
                'span' => 6,
                'sort' => 120,
            ],
            'task_comment_edit_minutes' => [
                'label' => 'A comment author may edit for (minutes)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:1440'],
                'default' => 15,
                'help' => 'After the window the comment is fixed; every edit inside it is logged with the old body.',
                'span' => 4,
                'sort' => 130,
            ],
            'client_can_see_tasks' => [
                'label' => 'Show tasks in the client panel',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Applies on top of each task\'s own visibility flag: a client sees a task only when both are on.',
                'span' => 4,
                'sort' => 140,
            ],
            'client_can_see_attachments' => [
                'label' => 'Show files in the client panel',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Applies on top of each attachment being marked visible to the client.',
                'span' => 4,
                'sort' => 150,
            ],
            'deadline_reminder_days' => [
                'label' => 'Warn about a deadline this many days ahead',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:90'],
                'default' => 3,
                'help' => 'Covers both a project deadline and a task due date.',
                'span' => 6,
                'sort' => 160,
            ],
            'overdue_digest_enabled' => [
                'label' => 'Send the daily overdue digest to project managers',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'span' => 6,
                'sort' => 170,
            ],
        ];
    }

    private static function crmFields(): array
    {
        return [
            'lead_number_prefix' => [
                'label' => 'Lead number prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:12'],
                'default' => 'LD-',
                'span' => 4,
                'sort' => 10,
            ],
            'lead_number_next_number' => [
                'label' => 'Next lead number',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1'],
                'default' => 1,
                'help' => 'Advanced only by the numbering service, never by this form (D62).',
                'readonly' => true,
                'span' => 4,
                'sort' => 20,
            ],
            'client_code_prefix' => [
                'label' => 'Client code prefix',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:12'],
                'default' => 'CL-',
                'span' => 4,
                'sort' => 30,
            ],
            'client_code_next_number' => [
                'label' => 'Next client code',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1'],
                'default' => 1,
                'help' => 'Advanced only by the numbering service, never by this form (D62).',
                'readonly' => true,
                'span' => 4,
                'sort' => 40,
            ],
            'number_padding' => [
                'label' => 'Number padding',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:3', 'max:10'],
                'default' => 6,
                'help' => 'Digits after the prefix, so LD-000001.',
                'span' => 4,
                'sort' => 50,
            ],
            'default_lead_source' => [
                'label' => 'Default lead source',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:32'],
                'default' => 'website',
                'span' => 4,
                'sort' => 60,
            ],
            'auto_assign_mode' => [
                'label' => 'Auto-assign mode',
                'type' => self::TYPE_SELECT,
                'rules' => ['nullable', 'string', 'in:off,round_robin,least_open,fixed'],
                'default' => 'off',
                'help' => 'Who a new lead lands on when nobody is chosen.',
                'options' => ['off' => 'Off', 'round_robin' => 'Round robin', 'least_open' => 'Fewest open leads', 'fixed' => 'One person'],
                'span' => 4,
                'sort' => 70,
            ],
            'auto_assign_user_id' => [
                'label' => 'Auto-assign to',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1'],
                'default' => null,
                'help' => 'Used when the mode is One person.',
                'span' => 4,
                'sort' => 80,
            ],
            'auto_assign_roles' => [
                'label' => 'Auto-assign roles',
                'type' => self::TYPE_JSON,
                'rules' => ['nullable', 'array'],
                'default' => ['Sales Executive'],
                'help' => 'The roles round robin draws from.',
                'span' => 8,
                'sort' => 90,
            ],
            'duplicate_detection_enabled' => [
                'label' => 'Warn about duplicates',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'span' => 4,
                'sort' => 100,
            ],
            'duplicate_block_on_exact' => [
                'label' => 'Block an exact duplicate',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Off by default: a duplicate is warned about, never silently refused.',
                'span' => 4,
                'sort' => 110,
            ],
            'duplicate_match_fields' => [
                'label' => 'Duplicate match fields',
                'type' => self::TYPE_JSON,
                'rules' => ['nullable', 'array'],
                'default' => ['phone', 'whatsapp', 'email'],
                'span' => 8,
                'sort' => 120,
            ],
            'duplicate_cross_field' => [
                'label' => 'Match across fields',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'A phone typed into the WhatsApp box still matches.',
                'span' => 4,
                'sort' => 130,
            ],
            'duplicate_check_clients' => [
                'label' => 'Also check clients',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'span' => 4,
                'sort' => 140,
            ],
            'duplicate_lookback_days' => [
                'label' => 'Duplicate look-back (days)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:0'],
                'default' => 0,
                'help' => '0 checks every lead ever created.',
                'span' => 4,
                'sort' => 150,
            ],
            'follow_up_default_offset_hours' => [
                'label' => 'Default follow-up offset (hours)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1', 'max:8760'],
                'default' => 24,
                'span' => 4,
                'sort' => 160,
            ],
            'follow_up_reminder_minutes' => [
                'label' => 'Remind before (minutes)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:5', 'max:10080'],
                'default' => 60,
                'span' => 4,
                'sort' => 170,
            ],
            'follow_up_reminder_channels' => [
                'label' => 'Reminder channels',
                'type' => self::TYPE_JSON,
                'rules' => ['nullable', 'array'],
                'default' => ['database'],
                'span' => 8,
                'sort' => 180,
            ],
            'follow_up_overdue_grace_minutes' => [
                'label' => 'Overdue grace (minutes)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:0', 'max:10080'],
                'default' => 120,
                'span' => 4,
                'sort' => 190,
            ],
            'require_follow_up_on_contacted' => [
                'label' => 'Require a follow-up when contacted',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'A lead never goes quiet: moving it to Contacted asks for the next step.',
                'span' => 4,
                'sort' => 200,
            ],
            'lost_reasons' => [
                'label' => 'Lost reasons',
                'type' => self::TYPE_JSON,
                'rules' => ['nullable', 'array'],
                'default' => ['Price', 'Timing', 'Chose a competitor', 'No response', 'Out of scope', 'Other'],
                'span' => 8,
                'sort' => 210,
            ],
            'lead_statuses_on_board' => [
                'label' => 'Board columns',
                'type' => self::TYPE_JSON,
                'rules' => ['nullable', 'array'],
                'default' => ['new', 'contacted', 'interested', 'negotiation', 'proposal_sent', 'won', 'lost'],
                'span' => 8,
                'sort' => 220,
            ],
            'kanban_page_size' => [
                'label' => 'Cards per column',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:5', 'max:100'],
                'default' => 25,
                'span' => 4,
                'sort' => 230,
            ],
            'stale_lead_days' => [
                'label' => 'A lead goes stale after (days)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1', 'max:365'],
                'default' => 7,
                'span' => 4,
                'sort' => 240,
            ],
            'stale_digest_enabled' => [
                'label' => 'Send the stale-lead digest',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'span' => 4,
                'sort' => 250,
            ],
            'bulk_max_ids' => [
                'label' => 'Bulk action limit',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:10', 'max:1000'],
                'default' => 200,
                'span' => 4,
                'sort' => 260,
            ],
            'export_max_rows' => [
                'label' => 'Export row limit',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:100', 'max:100000'],
                'default' => 20000,
                'span' => 4,
                'sort' => 270,
            ],
            'import_max_rows' => [
                'label' => 'Import row limit',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:10', 'max:100000'],
                'default' => 5000,
                'span' => 4,
                'sort' => 280,
            ],
            'import_file_retention_days' => [
                'label' => 'Keep import files (days)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1', 'max:365'],
                'default' => 30,
                'span' => 4,
                'sort' => 290,
            ],
            'import_row_retention_days' => [
                'label' => 'Keep import rows (days)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'default' => 90,
                'span' => 4,
                'sort' => 300,
            ],
            'activity_edit_window_minutes' => [
                'label' => 'Author may edit an activity for (minutes)',
                'type' => self::TYPE_NUMBER,
                'rules' => ['nullable', 'integer', 'min:0', 'max:10080'],
                'default' => 1440,
                'span' => 4,
                'sort' => 310,
            ],
            'whatsapp_link_template' => [
                'label' => 'WhatsApp link template',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:255'],
                'default' => 'https://wa.me/{number}',
                'help' => 'The placeholder is replaced with the digits of the lead phone.',
                'span' => 8,
                'sort' => 320,
            ],
            'client_visible_documents_default' => [
                'label' => 'New documents are client-visible',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Off by default: a document is shared with the client deliberately.',
                'span' => 4,
                'sort' => 330,
            ],
            'client_portal_enabled' => [
                'label' => 'Client portal enabled',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off closes every client route with an explanatory page.',
                'span' => 4,
                'sort' => 340,
            ],
        ];
    }

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

    /**
     * phase-19-23 5.3 - the fifteen keys Phase 23 owns.
     *
     * Three of these defaults are arguments rather than numbers, and each is written down where it
     * is set rather than in a design note nobody re-reads:
     *
     * - `activity_log_retention_days` is **0, meaning never**. Every other retention key in this
     *   registry counts down to a deletion; this one does not, because 110 asks for an audit trail
     *   and a financial log that quietly deletes its own oldest rows is not one. An administrator
     *   can still set a number, and the help text says plainly what that costs.
     *
     * - `excel_enabled` is **false**, and turning it on is not enough on its own -
     *   {@see \App\Enums\ExportFormat::isAvailable()} also asks whether a writer package is
     *   installed. A switch that produces a button that 500s is worse than no switch.
     *
     * - `sync_row_limit` does **not** override `finance.report_sync_row_limit` for Phase 13's four
     *   finance reports. Two keys for one idea is a convergence ask 13.2 records, not a thing to
     *   fix by quietly changing which one wins - a finance report that suddenly streamed 5,000 rows
     *   inline because Phase 23 arrived would be a regression nobody asked for.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function reportsFields(): array
    {
        return [
            // -------------------------------------------------------------- defaults
            'default_date_preset' => [
                'label' => 'Reports open on',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:today,yesterday,week,month,year'],
                'default' => 'month',
                'options' => [
                    'today' => 'Today',
                    'yesterday' => 'Yesterday',
                    'week' => 'This week',
                    'month' => 'This month',
                    'year' => 'This year',
                ],
                // `custom` is always offered on the screen and is never a default: a report that
                // opens on an empty date range asks a question instead of answering one.
                'help' => 'The range a report starts on. A custom range is always available.',
                'span' => 4,
                'sort' => 10,
            ],
            'cache_ttl_seconds' => [
                'label' => 'Remember a result for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'between:0,86400'],
                'default' => 300,
                'suffix' => 'seconds',
                // Per user and per filter set, never global: two people with different branch scopes
                // asking the same question must not share an answer.
                'help' => 'Caches a report per person and per filter set. 0 recalculates every time.',
                'span' => 4,
                'sort' => 20,
            ],

            // -------------------------------------------------------------- exports
            'sync_row_limit' => [
                'label' => 'Show inline up to',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'between:100,100000'],
                'default' => 5000,
                'suffix' => 'rows',
                'help' => 'A bigger result is queued as an export instead of being rendered. '
                    .'Finance reports keep their own limit under the Finance tab.',
                'span' => 4,
                'sort' => 100,
            ],
            'export_max_rows' => [
                'label' => 'Refuse an export above',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'between:1000,2000000'],
                'default' => 200000,
                'suffix' => 'rows',
                // Named in the refusal, so the message is "your filters match 412,900 rows" rather
                // than "too many rows" - one of those a person can act on.
                'help' => 'The request is refused with the row count, so the person knows how much '
                    .'to narrow by.',
                'span' => 4,
                'sort' => 110,
            ],
            'export_retention_days' => [
                'label' => 'Keep an exported file for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'between:1,365'],
                'default' => 7,
                'suffix' => 'days',
                // The file goes; the row stays, marked expired. Somebody who bookmarked a link is
                // told the file has gone rather than that it never existed.
                'help' => 'Only the file is deleted. The record of who exported what is kept.',
                'span' => 4,
                'sort' => 120,
            ],
            'excel_enabled' => [
                'label' => 'Offer Excel downloads',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => false,
                'help' => 'Needs a spreadsheet package installed on the server as well. Until one is, '
                    .'this stays off by itself and CSV opens in Excel anyway.',
                'span' => 6,
                'sort' => 130,
            ],
            'pdf_paper_size' => [
                'label' => 'PDF paper',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:a4,letter,legal'],
                'default' => 'a4',
                'options' => [
                    'a4' => 'A4',
                    'letter' => 'Letter',
                    'legal' => 'Legal',
                ],
                'span' => 3,
                'sort' => 140,
            ],
            'pdf_orientation' => [
                'label' => 'PDF orientation',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string', 'in:portrait,landscape'],
                // Landscape, because a report is a wide table and a portrait page truncates columns.
                'default' => 'landscape',
                'options' => [
                    'portrait' => 'Portrait',
                    'landscape' => 'Landscape',
                ],
                'span' => 3,
                'sort' => 150,
            ],

            // -------------------------------------------------------------- the search palette
            'global_search_min_chars' => [
                'label' => 'Search after',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'between:1,10'],
                'default' => 2,
                'suffix' => 'characters',
                'span' => 3,
                'sort' => 200,
            ],
            'global_search_debounce_ms' => [
                'label' => 'Wait before searching',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'between:0,2000'],
                'default' => 250,
                'suffix' => 'ms',
                'help' => 'Stops a query firing on every keystroke.',
                'span' => 3,
                'sort' => 210,
            ],
            'global_search_per_entity_limit' => [
                'label' => 'Results per section',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'between:1,25'],
                'default' => 5,
                'suffix' => 'rows',
                'help' => 'The palette shows this many of each kind, with a link to the full list.',
                'span' => 3,
                'sort' => 220,
            ],
            'global_search_entities' => [
                'label' => 'Search these',
                'type' => self::TYPE_MULTISELECT,
                'rules' => ['required', 'array', 'min:1'],
                'item_rules' => [
                    '*' => ['string', 'in:'.implode(',', SearchEntityType::values())],
                ],
                'default' => SearchEntityType::values(),
                'options' => SearchEntityType::options(),
                // This narrows the palette; it does not grant anything. A person still only ever
                // sees rows their permissions and their scope already allow - unticking a kind here
                // hides it from everyone, and ticking one back on gives nobody new access.
                'help' => 'Narrows what the palette looks through. It never widens what anyone may '
                    .'see: permissions and branch scope still decide that.',
                'span' => 12,
                'sort' => 230,
            ],

            // -------------------------------------------------------------- the audit trail
            'activity_log_retention_days' => [
                'label' => 'Prune the activity log after',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'between:0,3650'],
                'default' => 0,
                'suffix' => 'days',
                'help' => '0 never prunes, and that is the default on purpose: the audit trail is '
                    .'the record of who changed money, and a log that deletes its own history is '
                    .'not evidence of anything.',
                'span' => 6,
                'sort' => 300,
            ],
            'audit_show_financial_values' => [
                'label' => 'Show money values in the audit trail',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['required', 'boolean'],
                'default' => true,
                // Off does not hide the row - somebody without `view_financial` still sees that a
                // commission rate changed, who changed it and when. Only the two numbers are
                // masked. Hiding the event as well would turn a privacy setting into a way to make
                // a change invisible, which is the opposite of what an audit trail is for.
                'help' => 'Off, the old and new amounts need the financial permission. The change '
                    .'itself, and who made it, stays visible either way.',
                'span' => 6,
                'sort' => 310,
            ],
            'audit_sensitive_modules' => [
                'label' => 'Treat these as sensitive',
                'type' => self::TYPE_MULTISELECT,
                'rules' => ['nullable', 'array'],
                'item_rules' => [
                    '*' => ['string', 'in:'.implode(',', array_keys(PermissionRegistry::modules()))],
                ],
                // The twelve 5.3 names, **in registry order** rather than the order the contract
                // lists them. The set is what matters, but the order has to match `options()` or a
                // round trip through the form is not a no-op: the browser submits checked boxes in
                // the order they render, so an administrator who opened the tab and saved it without
                // touching anything would rewrite the row and mark the setting as changed. Filtering
                // the registry keeps the two in step for ever, including when a later phase inserts
                // a module in the middle.
                'default' => array_values(array_intersect(
                    array_keys(PermissionRegistry::modules()),
                    [
                        'collaborator_commission_settings',
                        'collaborator_commissions',
                        'collaborator_payouts',
                        'collaborator_referrals',
                        'student_fees',
                        'fee_discounts',
                        'results',
                        'certificates',
                        'users',
                        'roles',
                        'settings',
                        'modules',
                    ],
                )),
                // From the registry, not the `modules` table: the registry is the source of truth
                // and never needs the database, so this list is right on a fresh install and on a
                // settings screen rendered before the seeder has run.
                'options' => static fn (): array => array_map(
                    static fn (array $module): string => (string) $module['name'],
                    PermissionRegistry::modules(),
                ),
                'help' => 'Changes in these modules appear in the audit trail by default. Every '
                    .'module is still logged; this decides what the trail opens on.',
                'span' => 12,
                'sort' => 320,
            ],
        ];
    }


    /**
     * phase-24-25 5.1 - thirty keys about what a backup is worth.
     *
     * **`retention_min_copies` outranks every date.** The five retention windows below describe
     * what a healthy history looks like; this one describes the floor. A prune job that can be
     * talked by an unusual calendar into leaving two usable database archives is a prune job that
     * can be talked into leaving none, so the floor is checked last and wins.
     *
     * **`max_storage_gb` fails loudly rather than pruning past policy.** The tempting behaviour on
     * a full disk is to delete the oldest archive and carry on. That silently converts a storage
     * problem into a retention problem, and nobody finds out until a restore needs the archive
     * that was quietly dropped. The run fails, the notification goes out, and a person decides.
     *
     * **`include_env` is prohibited unless the archive is encrypted** (HD-8). An `.env` holds the
     * application key, the database password and the mail credentials; an unencrypted archive
     * carrying one turns every copy of that archive - including the offsite one, including the one
     * on somebody laptop - into the whole system.
     *
     * **`jobs` and `failed_jobs` are deliberately NOT excluded.** They look like cache tables and
     * they are not: a queued commission job is money that has been earned and not yet written.
     * Restoring a database without them loses that work silently, which is the one failure mode a
     * restore must not have.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function backupFields(): array
    {
        return [
            // --------------------------------------------------------------- what and where
            'enabled' => [
                'label' => 'Run scheduled backups',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off stops the scheduled jobs only. A manual backup still works, so this '
                    .'cannot be the reason there is no archive at all.',
                'span' => 6,
                'sort' => 10,
            ],
            'disk' => [
                'label' => 'Write archives to',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'options' => [self::class, 'backupDiskOptions'],
                'default' => 'backups',
                'help' => 'The public disk is not offered: a database dump reachable by URL is not a '
                    .'backup, it is a breach with a schedule.',
                'span' => 6,
                'sort' => 20,
            ],

            // --------------------------------------------------------------- when
            'database_schedule' => [
                'label' => 'Database backup',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'options' => [
                    'off' => 'Never',
                    'twice_daily' => 'Twice a day',
                    'daily' => 'Daily',
                    'weekly' => 'Weekly',
                ],
                'default' => 'daily',
                'span' => 3,
                'sort' => 100,
            ],
            'database_time' => [
                'label' => 'at',
                'type' => self::TYPE_TIME,
                'rules' => ['nullable', 'date_format:H:i', 'required_unless:database_schedule,off'],
                'default' => '02:30',
                'span' => 3,
                'sort' => 110,
            ],
            'files_schedule' => [
                'label' => 'File backup',
                'type' => self::TYPE_SELECT,
                'rules' => ['required', 'string'],
                'options' => [
                    'off' => 'Never',
                    'daily' => 'Daily',
                    'weekly' => 'Weekly',
                    'monthly' => 'Monthly',
                ],
                'default' => 'weekly',
                'span' => 3,
                'sort' => 120,
            ],
            'files_time' => [
                'label' => 'at',
                'type' => self::TYPE_TIME,
                'rules' => ['nullable', 'date_format:H:i', 'required_unless:files_schedule,off'],
                'default' => '03:00',
                'span' => 3,
                'sort' => 130,
            ],

            // --------------------------------------------------------------- what goes in
            'include_paths' => [
                'label' => 'Back up these paths',
                'type' => self::TYPE_JSON,
                'rules' => ['required', 'array', 'min:1'],
                'item_rules' => ['*' => ['required', 'string', 'max:255', 'not_regex:/\.\./']],
                'default' => ['storage/app/public', 'storage/app/private'],
                'help' => 'Repo-relative. Each must exist and sit inside the application directory - '
                    .'a path that climbs out of it is refused rather than resolved.',
                'span' => 12,
                'sort' => 200,
            ],
            'exclude_paths' => [
                'label' => 'Skip these paths',
                'type' => self::TYPE_JSON,
                'rules' => ['required', 'array'],
                'item_rules' => ['*' => ['required', 'string', 'max:255']],
                'default' => [
                    'storage/framework',
                    'storage/logs',
                    'storage/app/backups',
                    'node_modules',
                    'vendor',
                    '.git',
                ],
                'help' => 'The backup directory is on this list on purpose: an archive that contains '
                    .'last week archive doubles in size every week and restores nothing extra.',
                'span' => 12,
                'sort' => 210,
            ],
            'excluded_tables' => [
                'label' => 'Skip these tables',
                'type' => self::TYPE_JSON,
                'rules' => ['required', 'array'],
                'item_rules' => ['*' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/']],
                'default' => ['cache', 'cache_locks', 'sessions', 'job_batches', 'telescope_entries'],
                // jobs and failed_jobs look like they belong on this list. See the class note.
                'help' => 'Caches and sessions only. The job tables are NOT skipped - a queued '
                    .'commission is money already earned and not yet written.',
                'span' => 12,
                'sort' => 220,
            ],

            // --------------------------------------------------------------- encryption
            'encrypt_archives' => [
                'label' => 'Encrypt archives',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Required before an archive may carry the environment file.',
                'span' => 4,
                'sort' => 300,
            ],
            'archive_password' => [
                'label' => 'Archive password',
                'type' => self::TYPE_PASSWORD,
                // `true`, not `1`. A boolean field reaches the validator as a real bool (the request
                // casts it), and Laravel compares a dependent value strictly after converting the
                // literals `true`/`false` - so `required_if:...,1` matches nothing and the rule
                // would never have fired. Probed against Laravel 12's own Validator.
                'rules' => ['nullable', 'string', 'min:16', 'max:128', 'required_if:encrypt_archives,true'],
                'default' => null,
                'encrypted' => true,
                // Never echoed, never logged, never in a notification - a password that reaches an
                // inbox has protected the archive from everyone except whoever reads that inbox.
                'help' => 'Stored encrypted and never shown again. Losing it loses every archive '
                    .'written while it was set - keep it where the database is not.',
                'span' => 4,
                'sort' => 310,
            ],
            'include_env' => [
                'label' => 'Include the environment file',
                'type' => self::TYPE_BOOLEAN,
                // Not `prohibited_unless`: that rule refuses a field that is PRESENT, and a
                // checkbox posts `false`, which is present - it refused the form whether the box
                // was ticked or not. The condition is a cross-field one and lives in
                // crossFieldErrors(), which judges the value rather than its presence.
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Only in an encrypted archive. It holds the application key, the database '
                    .'password and the mail credentials, so an unencrypted copy is the whole system.',
                'span' => 4,
                'sort' => 320,
            ],

            // --------------------------------------------------------------- retention
            'retention_keep_all_days' => [
                'label' => 'Keep every archive for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:90'],
                'default' => 7,
                'suffix' => 'days',
                'span' => 4,
                'sort' => 400,
            ],
            'retention_daily_days' => [
                'label' => 'Then one a day for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:7', 'max:365'],
                'default' => 30,
                'suffix' => 'days',
                'span' => 4,
                'sort' => 410,
            ],
            'retention_weekly_weeks' => [
                'label' => 'Then one a week for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:2', 'max:260'],
                'default' => 12,
                'suffix' => 'weeks',
                'span' => 4,
                'sort' => 420,
            ],
            'retention_monthly_months' => [
                'label' => 'Then one a month for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:120'],
                'default' => 12,
                'suffix' => 'months',
                'span' => 4,
                'sort' => 430,
            ],
            'retention_yearly_years' => [
                'label' => 'Then one a year for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:0', 'max:25'],
                'default' => 3,
                'suffix' => 'years',
                'help' => '0 means the monthly window is the end of the history.',
                'span' => 4,
                'sort' => 440,
            ],
            'retention_min_copies' => [
                'label' => 'Never leave fewer than',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:2', 'max:100'],
                'default' => 3,
                'suffix' => 'usable database archives',
                // Checked last, and it wins. See the class note.
                'help' => 'A floor the dates above cannot argue with. Whatever the retention windows '
                    .'work out to, the prune job stops here.',
                'span' => 4,
                'sort' => 450,
            ],
            'max_storage_gb' => [
                'label' => 'Storage ceiling',
                'type' => self::TYPE_DECIMAL,
                'rules' => ['required', 'numeric', 'min:1', 'max:10000'],
                // Two decimals, not Money's rate scale of four: this is disk space, not a rate,
                // and 20.0000 GB reads as a number somebody measured rather than chose.
                'scale' => 2,
                // Declared at its scale, like every other decimal default: a seeded '20' and a
                // saved '20.00' are the same number and different strings, and the round-trip test
                // is right to call that a moved value.
                'default' => '20.00',
                'suffix' => 'GB',
                'help' => 'On breach the backup job fails and says so. It never prunes past the '
                    .'policy above to make room - that turns a disk problem into a lost archive.',
                'span' => 4,
                'sort' => 460,
            ],

            // --------------------------------------------------------------- offsite
            'offsite_disk' => [
                'label' => 'Copy archives offsite to',
                'type' => self::TYPE_SELECT,
                'rules' => ['nullable', 'string'],
                'options' => [self::class, 'offsiteDiskOptions'],
                'default' => null,
                'help' => 'A second disk on different hardware. A backup that lives on the machine '
                    .'it backs up survives everything except the thing you took it for.',
                'span' => 6,
                'sort' => 500,
            ],
            'offsite_required_for_go_live' => [
                'label' => 'Offsite copies are required before go-live',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                // Readonly: turning this off is a written client decision, not a checkbox somebody
                // ticks the evening before a launch to make a red item go green.
                'readonly' => true,
                'help' => 'Locked. Switching it off is a decision the client makes in writing, and '
                    .'the go-live checklist records who made it.',
                'span' => 6,
                'sort' => 510,
            ],

            // --------------------------------------------------------------- notification
            'notify_emails' => [
                'label' => 'Also email',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:500'],
                'default' => null,
                'help' => 'Comma-separated. Empty means the in-app notification is the only one, '
                    .'which is enough only if somebody opens the application every day.',
                'span' => 6,
                'sort' => 600,
            ],
            'notify_on_success' => [
                'label' => 'Notify on success',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Off by default. A daily success message is a message people stop reading, '
                    .'and then they stop reading the failure too.',
                'span' => 3,
                'sort' => 610,
            ],
            'notify_on_failure' => [
                'label' => 'Notify on failure',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                // Not switchable. A backup nobody is told failed is a backup that does not exist,
                // and nobody finds out on a good day.
                'readonly' => true,
                'help' => 'Always on, and not switchable. A failure nobody hears about is discovered '
                    .'on the day it matters most.',
                'span' => 3,
                'sort' => 620,
            ],

            // --------------------------------------------------------------- verification
            'verify_checksum_daily' => [
                'label' => 'Check archive checksums daily',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Catches an archive that was truncated or has rotted on disk, which a file '
                    .'listing cannot tell you.',
                'span' => 6,
                'sort' => 700,
            ],
            'verify_restore_weekly' => [
                'label' => 'Restore into a scratch database weekly',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                // The only proof that matters: an archive that has never been restored is an
                // archive nobody has established can be.
                'help' => 'The only real proof. An archive that has never been restored is an '
                    .'archive nobody has established can be.',
                'span' => 6,
                'sort' => 710,
            ],
            'restore_scratch_database' => [
                'label' => 'Scratch database',
                'type' => self::TYPE_TEXT,
                'rules' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]{1,64}$/'],
                'default' => 'my_office_restore_test',
                'help' => 'The weekly proof restores here and drops it afterwards. It is refused if '
                    .'it names the live database - the one mistake that would be unrecoverable.',
                'span' => 6,
                'sort' => 720,
            ],
            'restore_confirmation_phrase' => [
                'label' => 'Restore confirmation phrase',
                'type' => self::TYPE_TEXT,
                'rules' => ['required', 'string', 'max:120'],
                'default' => 'RESTORE {database} {date}',
                'help' => 'The operator types this, with {database} and {date} filled in, before a '
                    .'restore runs. Compared exactly, case included - a confirmation you can click '
                    .'through without reading is not a confirmation.',
                'span' => 6,
                'sort' => 730,
            ],

            // --------------------------------------------------------------- binaries
            'mysqldump_path' => [
                'label' => 'mysqldump',
                'type' => self::TYPE_TEXT,
                'rules' => ['required', 'string', 'max:255'],
                'default' => 'C:/xampp/mysql/bin/mysqldump.exe',
                'help' => 'Checked to exist and to be executable before a run starts, and always '
                    .'quoted when it is called - the default path contains no space, and the one an '
                    .'operator types might.',
                'span' => 6,
                'sort' => 800,
            ],
            'mysql_path' => [
                'label' => 'mysql',
                'type' => self::TYPE_TEXT,
                'rules' => ['required', 'string', 'max:255'],
                'default' => 'C:/xampp/mysql/bin/mysql.exe',
                'help' => 'Used by the restore path and by the weekly restore proof.',
                'span' => 6,
                'sort' => 810,
            ],
        ];
    }

    /**
     * Disks an archive may be written to: every configured disk except `public`.
     *
     * The exclusion is not a preference. A database dump on the public disk is reachable by URL to
     * anybody who guesses the filename, and the filename contains a date.
     *
     * @return array<string, string>
     */
    public static function backupDiskOptions(): array
    {
        $options = [];

        foreach (array_keys((array) config('filesystems.disks', [])) as $disk) {
            $disk = (string) $disk;

            if ($disk === 'public') {
                continue;
            }

            $options[$disk] = $disk;
        }

        return $options;
    }

    /**
     * The same list for the offsite copy.
     *
     * No explicit "nowhere" option: the field is not required, so the select already renders a
     * blank placeholder, and an empty string stored where the contract says null is the kind of
     * difference that only shows up in a condition somebody wrote as `=== null`.
     *
     * @return array<string, string>
     */
    public static function offsiteDiskOptions(): array
    {
        return self::backupDiskOptions();
    }

    /**
     * phase-24-25 5.2 - sixteen keys about whether the system is alive.
     *
     * **Three are secrets and are stored encrypted** (the health token, the monitoring DSN, the
     * maintenance bypass). `encrypted` also means never echoed back into the form that edits it,
     * which is the one screen a secret must not appear on.
     *
     * **Two default to null although the contract says "generated".** A registry default is a pure
     * literal read on every seed run; one that generated a fresh secret would rewrite the row every
     * time `SettingSeeder` ran, invalidating the token an external monitor already holds. They are
     * generated once, on first use, by the service that needs them - and regenerating is an act
     * that gets logged, which a silent re-seed could never be.
     *
     * **`health_endpoint_enabled` off means 404, not 403.** A closed door that answers 403 confirms
     * there is a door, and tells a scanner exactly where to come back to. 404 says nothing.
     *
     * **`query_budget_enforced` is declared editable here, and production safety does not depend on
     * that.** The contract calls it readonly in production, but `readonly` in this registry is a
     * pure literal that `SettingSeeder` mirrors into `is_readonly` on every run - it cannot vary by
     * environment without making the seeded database environment-dependent, which D65 forbids. So
     * the authority is the guard, not the flag: `QueryBudgetGuard` throws only in local and
     * testing, and logs everywhere else, whatever this key says. A missed `with()` must never 500 a
     * paying client.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function opsFields(): array
    {
        return [
            // ------------------------------------------------------------- the health endpoint
            'health_check_token' => [
                'label' => 'Health check token',
                'type' => self::TYPE_PASSWORD,
                'rules' => ['nullable', 'string', 'min:32', 'max:128'],
                'default' => null,
                'encrypted' => true,
                'help' => 'Required by /health once set. Generated on first use, 48 characters; '
                    .'regenerating it is logged, because it is a credential a monitoring agent holds.',
                'span' => 6,
                'sort' => 10,
            ],
            'health_endpoint_enabled' => [
                'label' => 'Answer health checks',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Off, /health returns "not found" rather than "forbidden" - a closed door '
                    .'that announces itself is a door somebody comes back to.',
                'span' => 6,
                'sort' => 20,
            ],

            // ------------------------------------------------------------- heartbeats
            'scheduler_heartbeat_max_minutes' => [
                'label' => 'Warn if the scheduler is silent for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:2', 'max:1440'],
                'default' => 5,
                'suffix' => 'minutes',
                // The scheduler is what runs the nightly proofs. One that has stopped does not fail
                // - it goes quiet, and a sweep that never runs looks exactly like a sweep that
                // found nothing.
                'help' => 'The scheduler runs the nightly integrity checks. A stopped scheduler does '
                    .'not report a failure; it stops reporting at all, which is harder to notice.',
                'span' => 4,
                'sort' => 100,
            ],
            'queue_heartbeat_max_minutes' => [
                'label' => 'Warn if the queue is silent for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:2', 'max:1440'],
                'default' => 10,
                'suffix' => 'minutes',
                'help' => 'Queued exports, digests and notifications all stop together when the '
                    .'worker dies, and none of them raises an error while it is dead.',
                'span' => 4,
                'sort' => 110,
            ],
            'failed_jobs_alert_threshold' => [
                'label' => 'Warn at this many failed jobs',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:10000'],
                'default' => 10,
                'help' => 'Counted from the failed_jobs table and reported by the health screen and '
                    .'the daily digest.',
                'span' => 4,
                'sort' => 120,
            ],
            'uptime_ping_url' => [
                'label' => 'Ping this after every scheduler run',
                'type' => self::TYPE_URL,
                'rules' => ['nullable', 'url', 'max:255'],
                'default' => null,
                'help' => 'An external watchdog expecting a ping that stops arriving is the only '
                    .'thing that notices a server which has gone away entirely.',
                'span' => 12,
                'sort' => 130,
            ],

            // ------------------------------------------------------------- performance
            'slow_query_ms' => [
                'label' => 'Log a query slower than',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:20', 'max:60000'],
                'default' => 250,
                'suffix' => 'ms',
                'help' => 'Logged once per request with the route name and the SQL. Bindings are '
                    .'never logged - they are the row data the query was about.',
                'span' => 4,
                'sort' => 200,
            ],
            'query_budget_enforced' => [
                'label' => 'Enforce query budgets',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Development only: a screen over its budget throws instead of logging, so '
                    .'an N+1 is found before it ships. In production a breach is always logged and '
                    .'never thrown, whatever this says.',
                'span' => 8,
                'sort' => 210,
            ],

            // ------------------------------------------------------------- retention
            'log_retention_days' => [
                'label' => 'Keep log files for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:1', 'max:365'],
                'default' => 14,
                'suffix' => 'days',
                'help' => 'Mirrored into the daily log channel, so changing it here is what changes '
                    .'how long a log file survives.',
                'span' => 4,
                'sort' => 300,
            ],
            'integrity_run_retention_days' => [
                'label' => 'Keep integrity findings for',
                'type' => self::TYPE_NUMBER,
                'rules' => ['required', 'integer', 'min:7', 'max:3650'],
                'default' => 180,
                'suffix' => 'days',
                // The run row is never deleted (D19). Only the findings blob is released.
                'help' => 'Only the detailed findings are released. The verdict and the counts are '
                    .'kept for ever, and a run that failed keeps its findings for three years.',
                'span' => 8,
                'sort' => 310,
            ],

            // ------------------------------------------------------------- error reporting
            'error_digest_enabled' => [
                'label' => 'Send a daily error digest',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => true,
                'help' => 'Errors by class, failed jobs, backup status, the last reconciliation, '
                    .'lazy-load violations, slow queries and rate-limit hits, in one message.',
                'span' => 6,
                'sort' => 400,
            ],
            'error_digest_time' => [
                'label' => 'Send the digest at',
                'type' => self::TYPE_TIME,
                'rules' => ['required', 'date_format:H:i'],
                'default' => '07:00',
                'span' => 6,
                'sort' => 410,
            ],
            'error_monitoring_enabled' => [
                'label' => 'Send errors to an external monitor',
                'type' => self::TYPE_BOOLEAN,
                'rules' => ['nullable', 'boolean'],
                'default' => false,
                'help' => 'Off, the daily digest is the whole error story. On, exceptions are also '
                    .'reported as they happen.',
                'span' => 6,
                'sort' => 420,
            ],
            'error_monitoring_dsn' => [
                'label' => 'Monitoring DSN',
                'type' => self::TYPE_PASSWORD,
                'rules' => ['nullable', 'url', 'max:255'],
                'default' => null,
                'encrypted' => true,
                'help' => 'A DSN carries a project key, so it is stored encrypted and never echoed '
                    .'back into this form.',
                'span' => 6,
                'sort' => 430,
            ],

            // ------------------------------------------------------------- deployment
            'maintenance_secret' => [
                'label' => 'Maintenance bypass token',
                'type' => self::TYPE_PASSWORD,
                'rules' => ['nullable', 'string', 'min:16', 'max:128'],
                'default' => null,
                'encrypted' => true,
                'help' => 'Lets an operator open the site from behind the maintenance page and check '
                    .'a deploy before letting anybody else in. Generated on first use.',
                'span' => 6,
                'sort' => 500,
            ],
            'app_version' => [
                'label' => 'Deployed version',
                'type' => self::TYPE_TEXT,
                'rules' => ['nullable', 'string', 'max:32'],
                'default' => null,
                // Written by the deploy script, never by a person: a version somebody types is a
                // version that is wrong the first time somebody forgets to.
                'readonly' => true,
                'help' => 'Stamped by the deploy script, shown on the health screen and copied onto '
                    .'every backup, so a restored archive says which release made it.',
                'span' => 6,
                'sort' => 510,
            ],
        ];
    }
}
