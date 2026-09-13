<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 · data migration — close the split settings truth.
 *
 * Phase 2's `SettingsRegistry` relocated the company identity keys into the `branding` and
 * `contact` groups and renamed several more, but the `settings` table still holds the Phase 1
 * rows. While both halves existed, the settings screen edited one row and the rendered site read
 * the other: an administrator uploading a logo under Branding wrote `branding.logo_light` while
 * every page kept rendering the empty `company.logo_path`, so the setting appeared to do nothing.
 *
 * The views have been moved onto the canonical keys (`layouts/guest`, `layouts/partials/brand`,
 * `layouts/partials/footer`, `layouts/partials/head`). This migration moves the **values**, and
 * does it under rule 4 of the build brief — a wrong settings row is superseded, never deleted:
 *
 *   1. for every legacy/canonical pair, the legacy value is copied into the canonical row **only
 *      when the canonical row is empty or null**. A value an administrator has already set on the
 *      canonical key is never overwritten — that is the one thing this migration must not do;
 *   2. the legacy row is then marked `is_readonly = true` and its label is prefixed
 *      "Deprecated - use <canonical key>", so anyone reading the table, or any future screen that
 *      lists stored keys, is told where the concept lives now;
 *   3. nothing is deleted, and no value is cleared.
 *
 * Idempotent: every step is guarded by its own condition, so a second run writes nothing. The
 * whole pass is one transaction. On a fresh install it is a clean no-op — `migrate` runs before
 * `db:seed`, so the table is empty and there are no pairs to reconcile; the legacy rows only exist
 * on a database seeded by Phase 1.
 *
 * `down()` un-deprecates: `is_readonly` back to false and the original label restored. It
 * deliberately does **not** move any value back. A value copied forward may since have been edited
 * by an administrator through the settings screen, and a rollback that overwrote that edit with a
 * stale Phase 1 row would destroy exactly the data this migration exists to preserve. The legacy
 * rows still hold their own values, untouched, so nothing is lost either way.
 */
return new class extends Migration
{
    /**
     * legacy dotted key => the canonical key that now owns the concept.
     *
     * Every pair here satisfies three tests, because a wrong pairing would move a value into a
     * row that means something else: both keys hold the **same concept** under the same wording,
     * both store the **same type**, and the canonical key is **declared by `SettingsRegistry`**
     * (so the settings screen really does edit it).
     *
     * The first block is the reported defect — a view read the legacy row while the settings
     * screen wrote the canonical one. The rest are the same relocation in the other groups
     * phase-02 §2 owns outright; no code reads either half of those yet, but leaving one concept
     * on two rows is how the reported defect happened in the first place.
     */
    private const PAIRS = [
        // ---- The reported defect: read by resources/views/layouts/**. -------------------------
        // Relocated into the `branding` group (phase-02 §2).
        'company.logo_path' => 'branding.logo_light',
        'company.logo_dark_path' => 'branding.logo_dark',
        'company.favicon_path' => 'branding.favicon',

        // Relocated into the `contact` group (phase-02 §2).
        'company.email' => 'contact.email',
        'company.phone' => 'contact.phone',
        'company.support_email' => 'contact.support_email',
        'company.whatsapp' => 'contact.whatsapp',
        'company.address' => 'contact.address',
        'company.city' => 'contact.city',
        'company.country' => 'contact.country',

        // Renamed inside the `company` group.
        'company.description' => 'company.short_description',

        // ---- The same split in the other groups phase-02 §2 owns. ----------------------------
        // Phase 1 kept the brand colours and the sign-in image under `appearance`; phase-02 §2
        // gives the whole visual identity to `branding`, which is what the Branding screen and
        // layouts/partials/brand-theme now use.
        'appearance.brand_color' => 'branding.brand_color',
        'appearance.accent_color' => 'branding.accent_color',
        'appearance.login_illustration_path' => 'branding.login_background',

        // Renamed inside their own groups.
        'seo.canonical_url' => 'seo.canonical_base_url',
        'seo.og_image_path' => 'seo.og_image',
        'social.twitter' => 'social.x_twitter',
        'social.whatsapp' => 'social.whatsapp_link',
        'mail.reply_to_address' => 'mail.reply_to',
    ];

    /**
     * legacy dotted key => canonical key, where the concept moved but the **representation
     * changed**, so the value must be re-entered rather than copied.
     *
     * `seo.robots` is a `select` holding a robots directive ("index,follow"); `seo.robots_indexable`
     * is a boolean. Copying the string into the boolean row would read back as `false` — the site
     * would quietly go noindex because a migration moved a value it did not understand. The legacy
     * row keeps its value untouched and is only marked superseded, naming the key that replaced it.
     */
    private const DEPRECATE_ONLY = [
        'seo.robots' => 'seo.robots_indexable',
    ];

    /** The label prefix that marks a superseded row. Also how `down()` recognises its own work. */
    private const PREFIX = 'Deprecated - use ';

    /** Separator between the prefix and the label the row had before. */
    private const SEPARATOR = ' — ';

    /** `settings.label` is varchar(150). */
    private const LABEL_LENGTH = 150;

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $hasReadonly = Schema::hasColumn('settings', 'is_readonly');

        DB::transaction(function () use ($hasReadonly): void {
            foreach (self::PAIRS as $legacyKey => $canonicalKey) {
                $legacy = $this->row($legacyKey);

                if ($legacy === null) {
                    // Never seeded on this install (or already retired): nothing to supersede.
                    continue;
                }

                $this->carryValueForward($legacy, $canonicalKey);
                $this->deprecate($legacy, $canonicalKey, $hasReadonly);
            }

            foreach (self::DEPRECATE_ONLY as $legacyKey => $canonicalKey) {
                $legacy = $this->row($legacyKey);

                if ($legacy === null) {
                    continue;
                }

                // Marked, never copied — see the DEPRECATE_ONLY docblock.
                $this->deprecate($legacy, $canonicalKey, $hasReadonly);
            }
        });

        $this->flushSettingsCache();
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $hasReadonly = Schema::hasColumn('settings', 'is_readonly');

        DB::transaction(function () use ($hasReadonly): void {
            foreach (self::PAIRS + self::DEPRECATE_ONLY as $legacyKey => $canonicalKey) {
                $legacy = $this->row($legacyKey);

                if ($legacy === null) {
                    continue;
                }

                $update = [];

                $restored = $this->originalLabel((string) ($legacy->label ?? ''), $canonicalKey);

                if ($restored !== false) {
                    $update['label'] = $restored;
                }

                if ($hasReadonly && (bool) $legacy->is_readonly === true) {
                    $update['is_readonly'] = false;
                }

                if ($update !== []) {
                    $this->query($legacyKey)->update($update);
                }
            }
        });

        $this->flushSettingsCache();
    }

    /**
     * Copy the legacy value onto the canonical key, but only into an empty canonical row.
     *
     * Three cases, in order of how much they are allowed to do:
     *
     *   · the canonical row holds a value  → nothing happens, ever. This is the guarantee;
     *   · the canonical row exists and is empty → its value is filled from the legacy row;
     *   · the canonical row does not exist yet (a database migrated before `SettingSeeder` has
     *     declared the key) → it is created carrying the legacy value **and the legacy row's
     *     storage metadata**, so the value is not stranded. The seeder refreshes `label`,
     *     `description`, `sort_order` and the flags from the registry on its next run; it never
     *     touches `value`, so the carried value survives that refresh. The copied label has any
     *     deprecation marker stripped off it first: a canonical row is never born deprecated, and
     *     `SettingSeeder` refuses to seed a declared key whose row carries the marker.
     */
    private function carryValueForward(object $legacy, string $canonicalKey): void
    {
        if ($this->isEmpty($legacy->value)) {
            return;
        }

        [$group, $key] = $this->split($canonicalKey);

        $canonical = $this->row($canonicalKey);
        $now = Carbon::now();

        if ($canonical === null) {
            DB::table('settings')->insert([
                'group' => $group,
                'key' => $key,
                'value' => $legacy->value,
                'type' => (string) ($legacy->type ?: 'string'),
                'options' => $legacy->options,
                'is_encrypted' => (bool) $legacy->is_encrypted,
                'is_public' => (bool) $legacy->is_public,
                'label' => $this->undeprecatedLabel((string) ($legacy->label ?? ''), $canonicalKey),
                'description' => $legacy->description,
                'sort_order' => (int) $legacy->sort_order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        if (! $this->isEmpty($canonical->value)) {
            // An administrator already set this. Leave it alone.
            return;
        }

        // `updated_at` is stamped because the value really did change; `updated_by` stays null
        // because no human made this edit, and the group footer reads exactly that pair.
        $this->query($canonicalKey)->update([
            'value' => $legacy->value,
            'updated_at' => $now,
        ]);
    }

    /**
     * Mark the legacy row superseded: readonly, and labelled with the key that replaced it.
     *
     * `updated_at` is deliberately left alone. Only metadata moved here — no value did — and the
     * settings screen's "last updated" footer takes the newest `updated_at` across every row in
     * the group, so stamping these rows would make the Company group claim it had just been
     * edited, by nobody.
     */
    private function deprecate(object $legacy, string $canonicalKey, bool $hasReadonly): void
    {
        $update = [];

        $label = (string) ($legacy->label ?? '');

        if (! str_starts_with($label, self::PREFIX)) {
            $update['label'] = $this->deprecatedLabel($label, $canonicalKey);
        }

        if ($hasReadonly && (bool) $legacy->is_readonly !== true) {
            $update['is_readonly'] = true;
        }

        if ($update === []) {
            return;
        }

        $this->query($legacy->group.'.'.$legacy->key)->update($update);
    }

    /**
     * "Deprecated - use contact.email — Contact email", clamped to the column width.
     */
    private function deprecatedLabel(string $label, string $canonicalKey): string
    {
        $marker = self::PREFIX.$canonicalKey;

        $label = trim($label);

        return mb_substr(
            $label === '' ? $marker : $marker.self::SEPARATOR.$label,
            0,
            self::LABEL_LENGTH
        );
    }

    /**
     * A label safe to give a freshly created canonical row: the deprecation marker stripped when
     * one is present, the label as-is otherwise, null when there is none.
     */
    private function undeprecatedLabel(string $label, string $canonicalKey): ?string
    {
        $restored = $this->originalLabel($label, $canonicalKey);

        if ($restored !== false) {
            return $restored;
        }

        return $label === '' ? null : $label;
    }

    /**
     * The label this row carried before it was deprecated, or `false` when the current label is
     * not one this migration wrote (in which case `down()` leaves it alone).
     */
    private function originalLabel(string $label, string $canonicalKey): string|null|false
    {
        $marker = self::PREFIX.$canonicalKey;

        if ($label === $marker) {
            // The row had no label before.
            return null;
        }

        $prefix = $marker.self::SEPARATOR;

        if (str_starts_with($label, $prefix)) {
            return mb_substr($label, mb_strlen($prefix));
        }

        return false;
    }

    /**
     * One row by its dotted key, or null.
     */
    private function row(string $key): ?object
    {
        return $this->query($key)->first();
    }

    /**
     * A query already scoped to one dotted key.
     */
    private function query(string $key): Builder
    {
        [$group, $name] = $this->split($key);

        return DB::table('settings')->where('group', $group)->where('key', $name);
    }

    /**
     * 'contact.support_email' => ['contact', 'support_email'] (only the first dot splits).
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $key): array
    {
        [$group, $name] = explode('.', $key, 2);

        return [$group, $name];
    }

    /**
     * "Empty or null" — what makes a canonical row safe to fill.
     */
    private function isEmpty(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    /**
     * The cached settings payload is a single blob keyed `settings.all`; rewriting rows behind the
     * repository's back has to invalidate it or the app keeps serving the pre-migration values.
     * Guarded: during an install the cache store may not exist yet.
     */
    private function flushSettingsCache(): void
    {
        try {
            Cache::forget('settings.all');
        } catch (Throwable) {
            // Store unavailable — nothing to forget.
        }
    }
};
