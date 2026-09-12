<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ThemePreference;
use App\Models\Setting;
use Database\Seeders\Concerns\WritesToConsole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 · §1.4 / §5 — the settings catalogue.
 *
 * Groups: company · localization · appearance · social · seo · mail · collaborator · institute.
 * Defaults follow DEVELOPMENT_LOG §9: currency PKR, timezone Asia/Karachi, commission bases
 * `paid`, approval mode `manual`, minimum payout 1000, payout requests / referral system /
 * automatic commission all enabled.
 *
 * Idempotent and non-destructive — this is the important part of this seeder:
 *   · rows are matched on the natural key (`group`, `key`);
 *   · the **value is written only when the row is created**, so re-running the seeder can never
 *     reset a company name, an SMTP host or a commission rate an administrator has changed;
 *   · the meta columns (type, options, is_encrypted, is_public, label, description, sort_order)
 *     are refreshed every run, so the settings screen always renders the current catalogue;
 *   · nothing is ever deleted; a key removed from this file simply stops being maintained.
 *
 * `is_encrypted` is reserved for secrets (the SMTP password) — the Setting model encrypts and
 * decrypts that column transparently. `is_public` marks what the public website may read.
 */
class SettingSeeder extends Seeder
{
    use WritesToConsole;

    public function run(): void
    {
        $catalogue = $this->catalogue();

        DB::transaction(function () use ($catalogue): void {
            $created = 0;
            $updated = 0;
            $total = 0;

            foreach ($catalogue as $group => $rows) {
                $sortOrder = 0;

                foreach ($rows as $row) {
                    $sortOrder += 10;
                    $total++;

                    $setting = Setting::query()->firstOrNew([
                        'group' => (string) $group,
                        'key' => (string) $row['key'],
                    ]);

                    $existed = $setting->exists;

                    $setting->fill([
                        'type' => (string) $row['type'],
                        'options' => $row['options'] ?? null,
                        'is_encrypted' => (bool) ($row['is_encrypted'] ?? false),
                        'is_public' => (bool) ($row['is_public'] ?? false),
                        'label' => (string) $row['label'],
                        'description' => $row['description'] ?? null,
                        'sort_order' => $sortOrder,
                    ]);

                    if (! $existed) {
                        // Only a brand new row gets the default value.
                        $setting->value = $this->serialise($row['value'] ?? null, (string) $row['type']);
                    }

                    $isDirty = $setting->isDirty();

                    $setting->save();

                    if (! $existed) {
                        $created++;
                    } elseif ($isDirty) {
                        $updated++;
                    }
                }
            }

            $this->seedInfo(sprintf(
                'Settings: %d keys in %d groups, %d created, %d refreshed (existing values preserved).',
                $total,
                count($catalogue),
                $created,
                $updated,
            ));
        });

        // The Setting model flushes this on save; flush again so a no-op run still leaves the
        // cached payload consistent with the table.
        settings_repo()->flush();
    }

    /**
     * group => list of rows.
     *
     * @return array<string, array<int, array{
     *     key: string,
     *     value: mixed,
     *     type: string,
     *     label: string,
     *     description?: string|null,
     *     options?: array<string, string>|null,
     *     is_encrypted?: bool,
     *     is_public?: bool
     * }>>
     */
    private function catalogue(): array
    {
        return [
            'company' => $this->companySettings(),
            'localization' => $this->localizationSettings(),
            'appearance' => $this->appearanceSettings(),
            'social' => $this->socialSettings(),
            'seo' => $this->seoSettings(),
            'mail' => $this->mailSettings(),
            'collaborator' => $this->collaboratorSettings(),
            'institute' => $this->instituteSettings(),
        ];
    }

    /**
     * Identity of the business. Read by the auth shell, the admin brand block, the footer and
     * the public website — hence mostly public.
     *
     * @return array<int, array<string, mixed>>
     */
    private function companySettings(): array
    {
        return [
            [
                'key' => 'name',
                'value' => 'MyOffice ERP',
                'type' => Setting::TYPE_STRING,
                'label' => 'Company name',
                'description' => 'Shown in the browser title, the sidebar brand block and on the website.',
                'is_public' => true,
            ],
            [
                'key' => 'short_name',
                'value' => 'MyOffice',
                'type' => Setting::TYPE_STRING,
                'label' => 'Short name',
                'description' => 'Compact brand used when the sidebar is collapsed.',
                'is_public' => true,
            ],
            [
                'key' => 'tagline',
                'value' => 'One system for your software house and training institute.',
                'type' => Setting::TYPE_STRING,
                'label' => 'Tagline',
                'is_public' => true,
            ],
            [
                'key' => 'description',
                'value' => 'Custom software development and job-ready IT training under one roof.',
                'type' => Setting::TYPE_TEXT,
                'label' => 'Description',
                'is_public' => true,
            ],
            [
                'key' => 'features',
                'value' => [
                    'Projects, tasks and client billing in one place',
                    'Admissions, batches, attendance and fees for the institute',
                    'Role-based access with a full audit trail',
                ],
                'type' => Setting::TYPE_JSON,
                'label' => 'Highlights',
                'description' => 'Bullet points shown on the sign-in screen.',
                'is_public' => true,
            ],
            [
                'key' => 'email',
                'value' => 'info@myoffice.test',
                'type' => Setting::TYPE_STRING,
                'label' => 'Contact email',
                'is_public' => true,
            ],
            [
                'key' => 'support_email',
                'value' => 'support@myoffice.test',
                'type' => Setting::TYPE_STRING,
                'label' => 'Support email',
                'is_public' => true,
            ],
            [
                'key' => 'phone',
                'value' => '+92 42 0000000',
                'type' => Setting::TYPE_STRING,
                'label' => 'Phone',
                'is_public' => true,
            ],
            [
                'key' => 'whatsapp',
                'value' => '+92 300 0000000',
                'type' => Setting::TYPE_STRING,
                'label' => 'WhatsApp',
                'is_public' => true,
            ],
            [
                'key' => 'website',
                'value' => 'https://myoffice.test',
                'type' => Setting::TYPE_STRING,
                'label' => 'Website',
                'is_public' => true,
            ],
            [
                'key' => 'address',
                'value' => 'Main Boulevard, Gulberg III',
                'type' => Setting::TYPE_TEXT,
                'label' => 'Address',
                'is_public' => true,
            ],
            [
                'key' => 'city',
                'value' => 'Lahore',
                'type' => Setting::TYPE_STRING,
                'label' => 'City',
                'is_public' => true,
            ],
            [
                'key' => 'country',
                'value' => 'Pakistan',
                'type' => Setting::TYPE_STRING,
                'label' => 'Country',
                'is_public' => true,
            ],
            [
                'key' => 'registration_number',
                'value' => null,
                'type' => Setting::TYPE_STRING,
                'label' => 'Registration number',
                'description' => 'Printed on invoices and certificates when set.',
            ],
            [
                'key' => 'logo_path',
                'value' => null,
                'type' => Setting::TYPE_FILE,
                'label' => 'Logo',
                'description' => 'Stored on the public disk; falls back to generated initials.',
                'is_public' => true,
            ],
            [
                'key' => 'logo_dark_path',
                'value' => null,
                'type' => Setting::TYPE_FILE,
                'label' => 'Logo (dark background)',
                'is_public' => true,
            ],
            [
                'key' => 'favicon_path',
                'value' => null,
                'type' => Setting::TYPE_FILE,
                'label' => 'Favicon',
                'is_public' => true,
            ],
        ];
    }

    /**
     * Locale, timezone and money presentation (DEVELOPMENT_LOG §9 Q3).
     *
     * @return array<int, array<string, mixed>>
     */
    private function localizationSettings(): array
    {
        return [
            [
                'key' => 'locale',
                'value' => 'en',
                'type' => Setting::TYPE_SELECT,
                'options' => ['en' => 'English', 'ur' => 'Urdu'],
                'label' => 'Default language',
                'is_public' => true,
            ],
            [
                'key' => 'timezone',
                'value' => 'Asia/Karachi',
                'type' => Setting::TYPE_STRING,
                'label' => 'Timezone',
                'description' => 'Used when a user has no timezone of their own.',
                'is_public' => true,
            ],
            [
                'key' => 'date_format',
                'value' => 'd M Y',
                'type' => Setting::TYPE_SELECT,
                'options' => [
                    'd M Y' => '12 Sep 2026',
                    'd/m/Y' => '12/09/2026',
                    'Y-m-d' => '2026-09-12',
                    'm/d/Y' => '09/12/2026',
                ],
                'label' => 'Date format',
            ],
            [
                'key' => 'time_format',
                'value' => 'h:i A',
                'type' => Setting::TYPE_SELECT,
                'options' => ['h:i A' => '03:45 PM (12-hour)', 'H:i' => '15:45 (24-hour)'],
                'label' => 'Time format',
            ],
            [
                'key' => 'week_start',
                'value' => 'monday',
                'type' => Setting::TYPE_SELECT,
                'options' => ['monday' => 'Monday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'],
                'label' => 'First day of the week',
            ],
            [
                'key' => 'currency',
                'value' => 'PKR',
                'type' => Setting::TYPE_SELECT,
                'options' => [
                    'PKR' => 'Pakistani Rupee (PKR)',
                    'USD' => 'US Dollar (USD)',
                    'EUR' => 'Euro (EUR)',
                    'GBP' => 'Pound Sterling (GBP)',
                    'AED' => 'UAE Dirham (AED)',
                    'SAR' => 'Saudi Riyal (SAR)',
                    'INR' => 'Indian Rupee (INR)',
                ],
                'label' => 'Currency',
                'is_public' => true,
            ],
            [
                'key' => 'currency_symbol',
                'value' => 'Rs',
                'type' => Setting::TYPE_STRING,
                'label' => 'Currency symbol',
                'is_public' => true,
            ],
            [
                'key' => 'currency_position',
                'value' => 'before',
                'type' => Setting::TYPE_SELECT,
                'options' => ['before' => 'Before the amount (Rs 1,000.00)', 'after' => 'After the amount (1,000.00 Rs)'],
                'label' => 'Symbol position',
            ],
            [
                'key' => 'currency_decimals',
                'value' => 2,
                'type' => Setting::TYPE_INTEGER,
                'label' => 'Decimal places',
            ],
            [
                'key' => 'thousand_separator',
                'value' => ',',
                'type' => Setting::TYPE_STRING,
                'label' => 'Thousand separator',
            ],
            [
                'key' => 'decimal_separator',
                'value' => '.',
                'type' => Setting::TYPE_STRING,
                'label' => 'Decimal separator',
            ],
        ];
    }

    /**
     * Shell and theme defaults.
     *
     * @return array<int, array<string, mixed>>
     */
    private function appearanceSettings(): array
    {
        return [
            [
                'key' => 'default_theme',
                'value' => ThemePreference::System->value,
                'type' => Setting::TYPE_SELECT,
                'options' => ThemePreference::options(),
                'label' => 'Default theme',
                'description' => 'Applied to accounts that have not picked a theme of their own.',
                'is_public' => true,
            ],
            [
                'key' => 'brand_color',
                'value' => '#4f46e5',
                'type' => Setting::TYPE_STRING,
                'label' => 'Brand colour',
                'is_public' => true,
            ],
            [
                'key' => 'accent_color',
                'value' => '#0ea5e9',
                'type' => Setting::TYPE_STRING,
                'label' => 'Accent colour',
                'is_public' => true,
            ],
            [
                'key' => 'sidebar_collapsed_by_default',
                'value' => false,
                'type' => Setting::TYPE_BOOLEAN,
                'label' => 'Collapse the sidebar by default',
            ],
            [
                'key' => 'table_page_size',
                'value' => 15,
                'type' => Setting::TYPE_INTEGER,
                'label' => 'Rows per page',
                'description' => 'Default pagination size for list screens.',
            ],
            [
                'key' => 'login_illustration_path',
                'value' => null,
                'type' => Setting::TYPE_FILE,
                'label' => 'Sign-in illustration',
            ],
            [
                'key' => 'show_powered_by',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
                'label' => 'Show the footer credit line',
                'is_public' => true,
            ],
        ];
    }

    /**
     * Social profiles — all public, all optional.
     *
     * @return array<int, array<string, mixed>>
     */
    private function socialSettings(): array
    {
        $networks = [
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'linkedin' => 'LinkedIn',
            'twitter' => 'X / Twitter',
            'youtube' => 'YouTube',
            'tiktok' => 'TikTok',
            'whatsapp' => 'WhatsApp',
            'github' => 'GitHub',
        ];

        $rows = [];

        foreach ($networks as $key => $label) {
            $rows[] = [
                'key' => $key,
                'value' => null,
                'type' => Setting::TYPE_STRING,
                'label' => $label.' URL',
                'is_public' => true,
            ];
        }

        return $rows;
    }

    /**
     * Public-website metadata and tracking ids.
     *
     * @return array<int, array<string, mixed>>
     */
    private function seoSettings(): array
    {
        return [
            [
                'key' => 'meta_title',
                'value' => 'MyOffice ERP — Software House & IT Training Institute',
                'type' => Setting::TYPE_STRING,
                'label' => 'Default meta title',
                'is_public' => true,
            ],
            [
                'key' => 'meta_description',
                'value' => 'Custom software development, web and mobile apps, and job-ready IT training courses.',
                'type' => Setting::TYPE_TEXT,
                'label' => 'Default meta description',
                'is_public' => true,
            ],
            [
                'key' => 'meta_keywords',
                'value' => 'software house, web development, mobile apps, IT training, courses',
                'type' => Setting::TYPE_TEXT,
                'label' => 'Default meta keywords',
                'is_public' => true,
            ],
            [
                'key' => 'canonical_url',
                'value' => 'https://myoffice.test',
                'type' => Setting::TYPE_STRING,
                'label' => 'Canonical base URL',
                'is_public' => true,
            ],
            [
                'key' => 'og_image_path',
                'value' => null,
                'type' => Setting::TYPE_FILE,
                'label' => 'Social share image',
                'is_public' => true,
            ],
            [
                'key' => 'robots',
                'value' => 'index,follow',
                'type' => Setting::TYPE_SELECT,
                'options' => [
                    'index,follow' => 'Index and follow',
                    'noindex,nofollow' => 'Hide from search engines',
                ],
                'label' => 'Robots directive',
                'is_public' => true,
            ],
            [
                'key' => 'sitemap_enabled',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
                'label' => 'Publish sitemap.xml',
                'is_public' => true,
            ],
            [
                'key' => 'google_analytics_id',
                'value' => null,
                'type' => Setting::TYPE_STRING,
                'label' => 'Google Analytics ID',
                'is_public' => true,
            ],
            [
                'key' => 'google_tag_manager_id',
                'value' => null,
                'type' => Setting::TYPE_STRING,
                'label' => 'Google Tag Manager ID',
                'is_public' => true,
            ],
            [
                'key' => 'facebook_pixel_id',
                'value' => null,
                'type' => Setting::TYPE_STRING,
                'label' => 'Facebook Pixel ID',
                'is_public' => true,
            ],
            [
                'key' => 'google_site_verification',
                'value' => null,
                'type' => Setting::TYPE_STRING,
                'label' => 'Google site verification token',
                'is_public' => true,
            ],
        ];
    }

    /**
     * Outgoing mail. `password` is the only encrypted setting in the catalogue and is never
     * public (DEVELOPMENT_LOG §9 Q7: MAIL_MAILER=log in development).
     *
     * @return array<int, array<string, mixed>>
     */
    private function mailSettings(): array
    {
        return [
            [
                'key' => 'mailer',
                'value' => 'log',
                'type' => Setting::TYPE_SELECT,
                'options' => [
                    'log' => 'Write to the log (development)',
                    'smtp' => 'SMTP',
                    'sendmail' => 'Sendmail',
                    'array' => 'Discard (testing)',
                ],
                'label' => 'Mail transport',
            ],
            [
                'key' => 'host',
                'value' => '127.0.0.1',
                'type' => Setting::TYPE_STRING,
                'label' => 'SMTP host',
            ],
            [
                'key' => 'port',
                'value' => 2525,
                'type' => Setting::TYPE_INTEGER,
                'label' => 'SMTP port',
            ],
            [
                'key' => 'encryption',
                'value' => 'tls',
                'type' => Setting::TYPE_SELECT,
                'options' => ['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None'],
                'label' => 'Encryption',
            ],
            [
                'key' => 'username',
                'value' => null,
                'type' => Setting::TYPE_STRING,
                'label' => 'SMTP username',
            ],
            [
                'key' => 'password',
                'value' => null,
                'type' => Setting::TYPE_STRING,
                'label' => 'SMTP password',
                'description' => 'Stored encrypted and never rendered back into the form.',
                'is_encrypted' => true,
            ],
            [
                'key' => 'from_address',
                'value' => 'hello@myoffice.test',
                'type' => Setting::TYPE_STRING,
                'label' => 'From address',
            ],
            [
                'key' => 'from_name',
                'value' => 'MyOffice ERP',
                'type' => Setting::TYPE_STRING,
                'label' => 'From name',
            ],
            [
                'key' => 'reply_to_address',
                'value' => null,
                'type' => Setting::TYPE_STRING,
                'label' => 'Reply-to address',
            ],
        ];
    }

    /**
     * Commission engine and payout policy (DEVELOPMENT_LOG §9 Q4–Q6).
     *
     * Commission only ever follows money actually received (CLAUDE.md §1.5), so the default
     * base is `paid` for both students and projects.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collaboratorSettings(): array
    {
        $bases = [
            'gross' => 'Gross amount invoiced',
            'net_after_discount' => 'Net amount after discount',
            'paid' => 'Amount actually received',
        ];

        $types = ['percentage' => 'Percentage of the base', 'fixed' => 'Fixed amount'];

        return [
            [
                'key' => 'referral_system_enabled',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
                'label' => 'Referral system enabled',
            ],
            [
                'key' => 'automatic_commission_enabled',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
                'label' => 'Create commissions automatically',
                'description' => 'Commission rows are generated when a payment is received.',
            ],
            [
                'key' => 'commission_approval_mode',
                'value' => 'manual',
                'type' => Setting::TYPE_SELECT,
                'options' => ['manual' => 'Manual approval', 'automatic' => 'Approve automatically'],
                'label' => 'Commission approval mode',
            ],
            [
                'key' => 'student_commission_base',
                'value' => 'paid',
                'type' => Setting::TYPE_SELECT,
                'options' => $bases,
                'label' => 'Student commission base',
            ],
            [
                'key' => 'project_commission_base',
                'value' => 'paid',
                'type' => Setting::TYPE_SELECT,
                'options' => $bases,
                'label' => 'Project commission base',
            ],
            [
                'key' => 'default_student_commission_type',
                'value' => 'percentage',
                'type' => Setting::TYPE_SELECT,
                'options' => $types,
                'label' => 'Default student commission type',
            ],
            [
                'key' => 'default_student_commission_rate',
                'value' => '10.00',
                'type' => Setting::TYPE_DECIMAL,
                'label' => 'Default student commission rate',
            ],
            [
                'key' => 'default_project_commission_type',
                'value' => 'percentage',
                'type' => Setting::TYPE_SELECT,
                'options' => $types,
                'label' => 'Default project commission type',
            ],
            [
                'key' => 'default_project_commission_rate',
                'value' => '5.00',
                'type' => Setting::TYPE_DECIMAL,
                'label' => 'Default project commission rate',
            ],
            [
                'key' => 'payout_request_enabled',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
                'label' => 'Collaborators may request payouts',
            ],
            [
                'key' => 'minimum_payout',
                'value' => '1000.00',
                'type' => Setting::TYPE_DECIMAL,
                'label' => 'Minimum payout amount',
            ],
            [
                'key' => 'payout_approval_required',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
                'label' => 'Payouts require approval',
            ],
            [
                'key' => 'wallet_hold_days',
                'value' => 0,
                'type' => Setting::TYPE_INTEGER,
                'label' => 'Hold period before a commission becomes available (days)',
            ],
            [
                'key' => 'statement_download_enabled',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
                'label' => 'Collaborators may download statements',
            ],
            [
                'key' => 'referral_code_prefix',
                'value' => 'CLB',
                'type' => Setting::TYPE_STRING,
                'label' => 'Referral code prefix',
            ],
        ];
    }

    /**
     * Training institute policy and numbering.
     *
     * @return array<int, array<string, mixed>>
     */
    private function instituteSettings(): array
    {
        return [
            [
                'key' => 'name',
                'value' => 'MyOffice Institute',
                'type' => Setting::TYPE_STRING,
                'label' => 'Institute name',
                'is_public' => true,
            ],
            [
                'key' => 'tagline',
                'value' => 'Job-ready IT training with real project experience.',
                'type' => Setting::TYPE_STRING,
                'label' => 'Institute tagline',
                'is_public' => true,
            ],
            [
                'key' => 'student_id_prefix',
                'value' => 'STD',
                'type' => Setting::TYPE_STRING,
                'label' => 'Student ID prefix',
            ],
            [
                'key' => 'student_id_next_number',
                'value' => 1000,
                'type' => Setting::TYPE_INTEGER,
                'label' => 'Next student number',
            ],
            [
                'key' => 'admission_number_prefix',
                'value' => 'ADM',
                'type' => Setting::TYPE_STRING,
                'label' => 'Admission number prefix',
            ],
            [
                'key' => 'fee_invoice_prefix',
                'value' => 'FEE',
                'type' => Setting::TYPE_STRING,
                'label' => 'Fee invoice prefix',
            ],
            [
                'key' => 'certificate_number_prefix',
                'value' => 'CERT',
                'type' => Setting::TYPE_STRING,
                'label' => 'Certificate number prefix',
            ],
            [
                'key' => 'academic_year_start_month',
                'value' => 1,
                'type' => Setting::TYPE_INTEGER,
                'label' => 'Academic year starts in month',
            ],
            [
                'key' => 'default_installment_count',
                'value' => 3,
                'type' => Setting::TYPE_INTEGER,
                'label' => 'Default number of fee installments',
            ],
            [
                'key' => 'fee_due_day',
                'value' => 5,
                'type' => Setting::TYPE_INTEGER,
                'label' => 'Monthly fee due day',
            ],
            [
                'key' => 'late_fee_enabled',
                'value' => false,
                'type' => Setting::TYPE_BOOLEAN,
                'label' => 'Charge a late fee',
            ],
            [
                'key' => 'late_fee_amount',
                'value' => '0.00',
                'type' => Setting::TYPE_DECIMAL,
                'label' => 'Late fee amount',
            ],
            [
                'key' => 'minimum_attendance_percentage',
                'value' => 75,
                'type' => Setting::TYPE_INTEGER,
                'label' => 'Minimum attendance percentage',
            ],
            [
                'key' => 'passing_percentage',
                'value' => 50,
                'type' => Setting::TYPE_INTEGER,
                'label' => 'Passing percentage',
            ],
            [
                'key' => 'demo_class_enabled',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
                'label' => 'Offer demo classes',
                'is_public' => true,
            ],
            [
                'key' => 'online_admission_enabled',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
                'label' => 'Accept online admissions',
                'is_public' => true,
            ],
            [
                'key' => 'student_review_requires_approval',
                'value' => true,
                'type' => Setting::TYPE_BOOLEAN,
                'label' => 'Student reviews need approval before publishing',
            ],
        ];
    }

    /**
     * Turn a PHP default into the string stored in `settings.value`.
     *
     * Decimals stay strings so money never meets a float (CLAUDE.md §1.4).
     */
    private function serialise(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            Setting::TYPE_BOOLEAN => $value ? '1' : '0',
            Setting::TYPE_INTEGER => (string) (int) $value,
            Setting::TYPE_JSON => is_string($value)
                ? $value
                : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => is_array($value)
                ? (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string) $value,
        };
    }
}
