<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 · data migration — no Phase 1 settings row is left orphaned.
 *
 * `2026_09_12_060400_supersede_relocated_setting_keys` closed the split truth for the twenty keys
 * phase-02 §2 relocated. Twenty-one more Phase 1 rows were left neither declared by
 * `SettingsRegistry` nor marked: several were unsuperseded twins of a declared key
 * (`institute.certificate_number_prefix` beside `institute.certificate_prefix`, both "CERT"), one
 * was a document counter an older screen could still have posted (`institute.student_id_next_number`,
 * D62), and the rest belong to phases that have not declared them yet. phase-02 §2's amendment is
 * binding: **a relocated key is superseded, never orphaned.** Every one of them is now one of two
 * things, and the label says which:
 *
 *   · **superseded** — "Deprecated - use <canonical key> — <old label>". The concept has a declared
 *     home. The value is carried into the canonical row only when that row is empty (a value an
 *     administrator set is never overwritten), then the legacy row is marked `is_readonly`;
 *   · **reserved** — "Reserved for <owner> — <old label>". The owning phase has not declared the key
 *     yet. The row keeps its value untouched and is marked `is_readonly` until that phase declares
 *     it (and clears the marker, which SettingSeeder insists on — see below).
 *
 * Nothing is deleted and no value is cleared or rewritten except the empty-canonical copy-forward.
 * `updated_at` is never stamped on a marked row (only metadata moves). `is_public` is left as it
 * was, so `down()` can restore every row exactly.
 *
 * SettingSeeder recognises the "Deprecated - use " prefix and refuses to re-seed such a row as an
 * editable field. A reserved row carries no such refusal: when its owning phase declares the key,
 * the seeder's metadata refresh replaces the label and `is_readonly` from the registry, which is
 * exactly the moment the key stops being reserved.
 *
 * Idempotent (every step is guarded) and a clean no-op on a fresh install, where the Phase 1 rows
 * never existed. `down()` restores the original label and `is_readonly = false` on the rows this
 * migration marked; it never moves a value back (a carried value may have been edited since).
 */
return new class extends Migration
{
    /**
     * legacy dotted key => canonical declared key, for concepts that already have a home.
     *
     * Values are copied forward only for same-type pairs; see COPY.
     */
    private const SUPERSEDED = [
        // Same concept, same text type, same seeded value ("CERT" / "FEE").
        'institute.certificate_number_prefix' => 'institute.certificate_prefix',
        'institute.fee_invoice_prefix' => 'institute.fee_receipt_prefix',

        // "Accept online admissions" is the maintenance group's admission form switch
        // (phase-14-17 §2150 reads `maintenance.admission_form_enabled` and never redefines it).
        'institute.online_admission_enabled' => 'maintenance.admission_form_enabled',
    ];

    /**
     * Superseded keys whose value may be carried into an empty canonical row (same type, same
     * meaning). A boolean is never "empty", so the switch above is marked only.
     */
    private const COPY = [
        'institute.certificate_number_prefix',
        'institute.fee_invoice_prefix',
    ];

    /**
     * A key that no longer means anything and has no canonical key: marked superseded by an
     * explanation rather than by another key.
     */
    private const RETIRED = [
        // Money columns are decimal(15,2) and Money works at scale 2; amounts are never shown
        // rounded away from the paisa they are stored with.
        'localization.currency_decimals' => 'money scale 2 (decimal(15,2))',
    ];

    /**
     * legacy dotted key => the owner that will declare it.
     */
    private const RESERVED = [
        'collaborator.wallet_hold_days' => 'phase 10-12 (as collaborator.commission_hold_days)',
        'collaborator.payout_approval_required' => 'phase 12',
        'collaborator.referral_code_prefix' => 'phase 9',
        'collaborator.statement_download_enabled' => 'phase 12',
        'institute.academic_year_start_month' => 'phase 14-17',
        'institute.admission_number_prefix' => 'phase 15',
        'institute.default_installment_count' => 'phase 18',
        'institute.demo_class_enabled' => 'phase 16',
        'institute.fee_due_day' => 'phase 18 (as institute.monthly_fee_due_day)',
        'institute.late_fee_amount' => 'phase 18',
        'institute.late_fee_enabled' => 'phase 18',
        'institute.minimum_attendance_percentage' => 'phase 17',
        'institute.name' => 'phase 14',
        'institute.tagline' => 'phase 14',
        'institute.passing_percentage' => 'phase 20 (as institute.exam_default_passing_percentage)',
        // D62: a document counter — readonly for ever; only DocumentNumberService advances it.
        'institute.student_id_next_number' => 'phase 15 (DocumentNumberService counter, D62)',
        'institute.student_review_requires_approval' => 'phase 4',
    ];

    private const DEPRECATED_PREFIX = 'Deprecated - use ';

    private const RESERVED_PREFIX = 'Reserved for ';

    private const SEPARATOR = ' — ';

    /** `settings.label` is varchar(150). */
    private const LABEL_LENGTH = 150;

    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasColumn('settings', 'is_readonly')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (self::SUPERSEDED as $legacyKey => $canonicalKey) {
                $legacy = $this->row($legacyKey);

                if ($legacy === null) {
                    continue;
                }

                if (in_array($legacyKey, self::COPY, true)) {
                    $this->carryValueForward($legacy, $canonicalKey);
                }

                $this->mark($legacy, self::DEPRECATED_PREFIX.$canonicalKey);
            }

            foreach (self::RETIRED as $legacyKey => $explanation) {
                $legacy = $this->row($legacyKey);

                if ($legacy !== null) {
                    $this->mark($legacy, self::DEPRECATED_PREFIX.$explanation);
                }
            }

            foreach (self::RESERVED as $legacyKey => $owner) {
                $legacy = $this->row($legacyKey);

                if ($legacy !== null) {
                    $this->mark($legacy, self::RESERVED_PREFIX.$owner);
                }
            }
        });

        $this->flushSettingsCache();
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasColumn('settings', 'is_readonly')) {
            return;
        }

        $markers = [];

        foreach (self::SUPERSEDED as $legacyKey => $canonicalKey) {
            $markers[$legacyKey] = self::DEPRECATED_PREFIX.$canonicalKey;
        }

        foreach (self::RETIRED as $legacyKey => $explanation) {
            $markers[$legacyKey] = self::DEPRECATED_PREFIX.$explanation;
        }

        foreach (self::RESERVED as $legacyKey => $owner) {
            $markers[$legacyKey] = self::RESERVED_PREFIX.$owner;
        }

        DB::transaction(function () use ($markers): void {
            foreach ($markers as $legacyKey => $marker) {
                $legacy = $this->row($legacyKey);

                if ($legacy === null) {
                    continue;
                }

                $label = (string) ($legacy->label ?? '');

                if ($label === $marker) {
                    $this->query($legacyKey)->update(['label' => null, 'is_readonly' => false]);

                    continue;
                }

                if (str_starts_with($label, $marker.self::SEPARATOR)) {
                    $this->query($legacyKey)->update([
                        'label' => mb_substr($label, mb_strlen($marker.self::SEPARATOR)),
                        'is_readonly' => false,
                    ]);
                }

                // Any other label was not written by this migration: leave the row alone.
            }
        });

        $this->flushSettingsCache();
    }

    /**
     * Copy a legacy value into its canonical row, only when that row exists and is empty.
     */
    private function carryValueForward(object $legacy, string $canonicalKey): void
    {
        if ($this->isEmpty($legacy->value)) {
            return;
        }

        $canonical = $this->row($canonicalKey);

        if ($canonical === null || ! $this->isEmpty($canonical->value)) {
            // Not seeded yet (the seeder writes the registry default), or an administrator has
            // already set it — in both cases the canonical row is not this migration's to fill.
            return;
        }

        $this->query($canonicalKey)->update([
            'value' => $legacy->value,
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Mark a row readonly under a label marker, leaving its value and timestamps alone.
     */
    private function mark(object $legacy, string $marker): void
    {
        $update = [];
        $label = trim((string) ($legacy->label ?? ''));

        if (! str_starts_with($label, self::DEPRECATED_PREFIX) && ! str_starts_with($label, self::RESERVED_PREFIX)) {
            $update['label'] = mb_substr($label === '' ? $marker : $marker.self::SEPARATOR.$label, 0, self::LABEL_LENGTH);
        }

        if ((bool) $legacy->is_readonly !== true) {
            $update['is_readonly'] = true;
        }

        if ($update !== []) {
            $this->query($legacy->group.'.'.$legacy->key)->update($update);
        }
    }

    private function row(string $key): ?object
    {
        return $this->query($key)->first();
    }

    private function query(string $key): Builder
    {
        [$group, $name] = explode('.', $key, 2);

        return DB::table('settings')->where('group', $group)->where('key', $name);
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
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
