<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 · data migration — a superseded settings row is no longer published.
 *
 * `2026_09_12_060400_supersede_relocated_setting_keys` and
 * `2026_09_12_060500_supersede_or_reserve_remaining_legacy_setting_keys` marked every relocated
 * Phase 1 key readonly under the label "Deprecated - use <canonical key>", and deliberately left
 * `is_public` as it was so their own `down()` could restore each row exactly. The side effect is that
 * nineteen of those history rows still carry `is_public = 1`, so `SettingsRepository::allPublic()`
 * keeps handing the public website a second, stale copy of the company address, the old logo path,
 * the old canonical URL — the very split truth the supersede migrations closed.
 *
 * `up()` sets `is_public = 0` on every row whose label starts with "Deprecated - use". Nothing is
 * deleted, no value, label, readonly flag or timestamp is touched, and `SettingSeeder` never
 * re-publishes such a row (it refuses to re-declare a deprecated key by name).
 *
 * `down()` restores `is_public = 1` on exactly the rows listed in PREVIOUSLY_PUBLIC — the flags as they
 * stood before this migration, captured from `my_office` on 2026-09-13 and identical to the Phase 1
 * seed — and only where the row still carries the deprecation marker and is currently unpublished.
 * A superseded row that was private before stays private. A migration cannot carry state from its
 * `up()` into its `down()`, which is why the list is captured here rather than computed.
 *
 * Idempotent and a clean no-op on a fresh install, where no superseded row exists.
 */
return new class extends Migration
{
    /** The label prefix the two supersede migrations write. */
    private const DEPRECATED_PREFIX = 'Deprecated - use ';

    /**
     * Superseded rows that were `is_public = 1` before this migration ran (group.key).
     *
     * The five superseded rows that were already private — appearance.login_illustration_path,
     * institute.certificate_number_prefix, institute.fee_invoice_prefix,
     * localization.currency_decimals, mail.reply_to_address — are deliberately absent.
     *
     * @var list<string>
     */
    private const PREVIOUSLY_PUBLIC = [
        'appearance.accent_color',
        'appearance.brand_color',
        'company.address',
        'company.city',
        'company.country',
        'company.description',
        'company.email',
        'company.favicon_path',
        'company.logo_dark_path',
        'company.logo_path',
        'company.phone',
        'company.support_email',
        'company.whatsapp',
        'institute.online_admission_enabled',
        'seo.canonical_url',
        'seo.og_image_path',
        'seo.robots',
        'social.twitter',
        'social.whatsapp',
    ];

    public function up(): void
    {
        if (! $this->hasColumns()) {
            return;
        }

        DB::table('settings')
            ->where('label', 'like', self::DEPRECATED_PREFIX.'%')
            ->where('is_public', true)
            ->update(['is_public' => false]);

        $this->flushSettingsCache();
    }

    public function down(): void
    {
        if (! $this->hasColumns()) {
            return;
        }

        DB::transaction(function (): void {
            foreach (self::PREVIOUSLY_PUBLIC as $dotted) {
                [$group, $key] = explode('.', $dotted, 2);

                DB::table('settings')
                    ->where('group', $group)
                    ->where('key', $key)
                    ->where('label', 'like', self::DEPRECATED_PREFIX.'%')
                    ->where('is_public', false)
                    ->update(['is_public' => true]);
            }
        });

        $this->flushSettingsCache();
    }

    private function hasColumns(): bool
    {
        return Schema::hasTable('settings')
            && Schema::hasColumn('settings', 'label')
            && Schema::hasColumn('settings', 'is_public');
    }

    private function flushSettingsCache(): void
    {
        try {
            Cache::forget('settings.all');
        } catch (Throwable) {
            // Store unavailable — nothing to forget.
        }
    }
};
