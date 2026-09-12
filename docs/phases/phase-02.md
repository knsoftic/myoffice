# PHASE 2 CONTRACT — Admin Dashboard, System Settings, Module Management

**Goal.** Make the platform configurable and observable from the UI, so nothing a business user needs
to change ever requires a code change again. Every brand detail, currency, date format, mail
credential, numbering prefix and commission rule becomes admin-editable here; modules become
switchable with dependency awareness and a full audit trail; and the dashboard becomes a real widget
framework that the next 23 phases plug their cards and charts into.

**Depends on Phase 1**: `settings` and `modules` tables, RBAC + `Gate::before` module gating, the admin
shell (`layouts/admin`, `x-ui.*`), activity log. Do not start until Phase 1 is ticked in
[`../../DEVELOPMENT_LOG.md`](../../DEVELOPMENT_LOG.md).

Binding like Phase 1: names below are fixed. Conventions in [`../../CLAUDE.md`](../../CLAUDE.md).

---

## 1. Schema (additive only — no destructive change to Phase 1 tables)

| Migration | Change |
|---|---|
| `add_dependency_and_audit_to_modules_table` | `modules.depends_on` json nullable (array of module slugs) · `disabled_at` timestamp nullable · `disabled_by` FK `users.id` nullable nullOnDelete · `disable_reason` string(255) nullable |
| `add_audit_to_settings_table` | `settings.updated_by` FK `users.id` nullable nullOnDelete · `settings.is_readonly` boolean default false (keys that may only change via console/env) |
| `add_preferences_to_users_table` | `users.preferences` json nullable — per-user UI state (dashboard widget order + hidden widgets, table column choices, sidebar collapsed). Accessed only through `User::preference(key, default)` / `setPreference(key, value)`; never read raw in a view. |

No other schema change. Settings values keep living in `settings.value`; their **definitions** live in code
(§2) exactly as permissions live in `PermissionRegistry`.

---

## 2. `app/Support/SettingsRegistry.php` — the definition of every setting

Same pattern as `PermissionRegistry`: pure arrays, no DB. It drives the seeder, the UI form, and
server-side validation from one place.

```
groups(): [ slug => ['label', 'icon', 'description', 'sort', 'permission' => 'settings.edit'] ]
fields(string $group): [ key => FieldDefinition ]
all(): [ group => fields ]
rulesFor(string $group): array      // Laravel validation rules, used by the Form Request
field(string $key): ?array          // 'group.key' lookup
```

Field definition keys: `label`, `type`, `rules`, `default`, `options` (array or a callable for
timezones/currencies), `help`, `placeholder`, `suffix`, `encrypted` (bool), `public` (bool — readable by
the public website), `readonly` (bool), `span` (1–12 grid columns), `sort`.

Types: `text` `textarea` `email` `tel` `url` `number` `decimal` `boolean` `select` `multiselect`
`color` `image` `file` `json` `time` `password` `richtext`.

### Groups and fields

| Group | Fields |
|---|---|
| `company` | `name` `legal_name` `tagline` `short_description` `founded_year` `registration_number` `ntn_number` `copyright_text` |
| `branding` | `logo_light` `logo_dark` `favicon` `og_image` `email_logo` `login_background` `brand_color` `accent_color` |
| `localization` | `currency` (select) `currency_symbol` `currency_position` (before/after) `decimal_separator` `thousand_separator` `timezone` (select) `date_format` (select) `time_format` (12/24) `week_start` `locale` |
| `contact` | `phone` `phone_secondary` `whatsapp` `email` `support_email` `address` `city` `country` `latitude` `longitude` `map_embed` `business_hours` (json: per-day open/close/closed) |
| `social` | `facebook` `instagram` `linkedin` `youtube` `tiktok` `x_twitter` `github` `whatsapp_link` |
| `seo` | `meta_title` `meta_description` `meta_keywords` `canonical_base_url` `og_image` `robots_indexable` `sitemap_enabled` `google_analytics_id` `google_tag_manager_id` `facebook_pixel_id` `google_site_verification` |
| `mail` | `mailer` (select smtp/log/sendmail) `host` `port` `username` `password` *(encrypted)* `encryption` (tls/ssl/none) `from_address` `from_name` `reply_to` |
| `collaborator` | `referral_system_enabled` `automatic_commission_enabled` `commission_approval_mode` (automatic/manual) `student_commission_base` (gross/net_after_discount/paid) `project_commission_base` (total_value/net_after_discount/paid/milestone) `default_student_commission_type` (percentage/fixed) `default_student_commission_rate` `default_project_commission_type` `default_project_commission_rate` `commission_on_admission_fee` `commission_on_registration_fee` `commission_reversal_on_refund` `payout_request_enabled` `minimum_payout` `payout_methods` (multiselect bank/easypaisa/jazzcash/cash/other) |
| `institute` | `admission_open` `default_branch_id` `student_id_prefix` `registration_number_format` `fee_receipt_prefix` `certificate_prefix` `certificate_verification_url` `attendance_grace_minutes` `default_class_duration` `installment_reminder_days` |
| `finance` | `invoice_prefix` `invoice_next_number` `tax_enabled` `tax_label` `default_tax_rate` `payment_terms_days` `bank_details` `invoice_footer_note` `expense_approval_required` |
| `security` | `password_min_length` `force_password_change_days` `login_max_attempts` `lockout_minutes` `session_lifetime` `allowed_file_types` `max_upload_mb` `two_factor_enabled` *(declared, implemented later)* |
| `maintenance` | `maintenance_mode` `maintenance_message` `public_site_enabled` `admission_form_enabled` `contact_form_enabled` |

`SettingSeeder` (Phase 1) is rewritten to read this registry, keeping any value an admin already
changed (`firstOrCreate` on value, `update` on metadata only).

---

## 3. Services and runtime wiring

| File | Responsibility |
|---|---|
| `app/Services/Core/SettingsService.php` | The only write path. Validates against the registry, handles image/file uploads (unique hashed name, old file deleted, `public` disk under `settings/`), encrypts `encrypted` fields, writes inside one transaction, stamps `updated_by`, flushes the settings cache, and logs **one activity entry per changed key** with old and new values (an encrypted field logs `[encrypted]`, never the value). `resetGroup(string $group)` restores registry defaults behind a confirm. |
| `app/Support/ConfigureFromSettings.php` | Called from `AppServiceProvider::boot()` inside a try/catch: applies mail settings onto `config('mail')`, `app.timezone`, `app.locale`, and the brand colour CSS variables. Must never break the app when the settings table is missing (install time). |
| `app/Services/Core/TestMailService.php` | Sends a test mail using the **saved** settings (not `.env`), returns a result object `{ok, message, exception}`; never exposes the password in the response; rate-limited to 3 per minute per user. |
| `app/Services/Core/ModuleService.php` | Extends the Phase 1 version: `dependents(string $slug)` and `missingDependencies(string $slug)` from `depends_on`; `toggle(Module, bool, ?string $reason)` inside a transaction — blocks disabling a module that enabled modules depend on unless `cascade` is explicitly passed, blocks core modules, stamps `disabled_at`/`disabled_by`/`disable_reason`, flushes the cache, fires `ModuleStateChanged`, and writes an activity entry with the reason. **Never touches module data rows.** |
| `app/Support/DashboardRegistry.php` | Widget registry. Each widget is a class in `app/Dashboard/Widgets/` implementing `DashboardWidget`: `key()`, `title()`, `icon()`, `permission()`, `module()`, `span()`, `group()`, `data(DateRange $range): array`, `view()`. The registry returns only widgets whose module is enabled and whose permission the user holds. Later phases add widgets by dropping in a class — no edit to the dashboard controller. **`key()` is globally unique and the registry is the guard: registering a second class under an existing key throws `DuplicateWidgetKeyException` rather than silently replacing the first card** (F-8.3), so a later phase claiming a key another phase already owns fails loudly on the first request in local dev. One widget key = one owning phase; the owner is the phase that ships the screens the widget links to. |
| `app/Support/DateRange.php` | `today`, `yesterday`, `week`, `month`, `quarter`, `year`, `custom(from,to)` with `previous()` for delta comparison, applied via a `scopeInRange()` convention. |
| `app/Support/Format.php` + helpers | `app_date()`, `app_time()`, `app_datetime()`, `money()` — all reading the `localization` settings so one setting change restyles every date and amount in the system. |

Phase 2 widgets (real data only, no invented numbers): `UsersByStatusWidget`,
`RolesOverviewWidget`, `ModulesEnabledWidget`, `LoginsTodayWidget`, `FailedLoginsWidget`,
`LoginTrendChartWidget` (14-day line), `RecentActivityWidget`, `RecentLoginsWidget`,
`SystemHealthWidget` (PHP/Laravel version, DB name+size, queue/cache driver, last migration, storage
link state, failed jobs count), `StorageUsageWidget`. Every later-phase dashboard card in spec §98
registers here.

**These ten keys are Phase 2's for the life of the system** (F-8.3). In particular `SystemHealthWidget`
**stays Phase 2's**: phase-24-25 does not re-register it — it refactors the widget's body to read
`SystemHealthService` and ships its own new keys (`BackupStatusWidget`, `IntegrityStatusWidget`,
`FailedJobsWidget`, `QueueHealthWidget`). Widget keys owned elsewhere that Phase 2 must **not** register:
`FeeCollectedTodayWidget`, `PendingFeesWidget`, `OverdueFeesWidget` (**Phase 18**, which owns the fee
screens), the eight commission/wallet widgets (the financial spine / Phase 10-12), and
`WebsiteContentWidget` / `SeoHealthWidget` (Phase 3).

---

## 4. Routes, controllers, permissions

| Route | Name | Middleware |
|---|---|---|
| `GET /admin` | `admin.dashboard` | `can:dashboard.view` |
| `PUT /admin/dashboard/layout` | `admin.dashboard.layout` | `can:dashboard.view` |
| `GET /admin/dashboard/widget/{key}` | `admin.dashboard.widget` | `can:dashboard.view` + throttle |
| `GET /admin/settings/{group?}` | `admin.settings.index` | `can:settings.view` |
| `PUT /admin/settings/{group}` | `admin.settings.update` | `can:settings.edit` |
| `DELETE /admin/settings/{group}/file/{key}` | `admin.settings.file.destroy` | `can:settings.edit` |
| `POST /admin/settings/{group}/reset` | `admin.settings.reset` | `can:settings.edit` |
| `POST /admin/settings/mail/test` | `admin.settings.mail.test` | `can:settings.edit` + throttle:3,1 |
| `POST /admin/settings/cache/clear` | `admin.settings.cache.clear` | `can:settings.edit` |
| `GET /admin/modules` | `admin.modules.index` | `can:modules.view_any` |
| `POST /admin/modules/{module}/toggle` | `admin.modules.toggle` | `can:modules.change_status` |
| `POST /admin/modules/bulk-toggle` | `admin.modules.bulk-toggle` | `can:modules.change_status` |
| `GET /admin/modules/{module}/impact` | `admin.modules.impact` | `can:modules.view` |

Controllers: `Admin/DashboardController` (rewritten to use the registry), `Admin/SettingsController`,
`Admin/ModuleController` (extended). Form Requests: `UpdateSettingsRequest` (rules come from
`SettingsRegistry::rulesFor`), `ToggleModuleRequest`, `TestMailRequest`.

---

## 5. UI

**Settings** — a vertical tab rail (group icon + label, active state, mobile: a select) beside a form
card. Fields render through one `<x-settings.field>` component switching on `type`. Requirements:
sticky save bar that appears only when the form is dirty (Alpine) and warns on navigate-away;
image fields show a preview with remove; `password` fields render masked with a "change" toggle and
never echo the stored value; every field shows its help text and inline error; each group footer shows
"last updated by X, <time>"; a danger zone per group with "Reset to defaults" behind
`x-ui.confirm`. Branding shows a **live preview**: changing `brand_color` repaints the shell via CSS
variables, logo upload previews in a mock sidebar/topbar. Mail group has an inline "Send test email"
with the result rendered underneath (success or the real exception message).

**Modules** — cards grouped by `ModuleGroup`, each with a toggle, permission count, route count,
dependency chips, and `disabled_at`/`disable_reason` when off. Core modules render locked with a
tooltip. Search + filter by group and state. Turning a module off opens an impact modal listing the
dependent modules, the affected routes and the sidebar items that will disappear, requires a reason,
and states plainly that **no data is deleted**. Bulk enable/disable per group.

**Dashboard** — a responsive widget grid (12-column, each widget declares its span), per-widget
skeleton while its JSON loads, a global date-range selector feeding every widget, a "Customize" mode
to reorder by drag and hide widgets (persisted to `users.preferences`), and charts through a shared
`<x-ui.chart>` component (Chart.js via npm, dark-mode aware, brand palette, `tabular-nums` tooltips,
no chartjunk). Widgets a user lacks permission for never reach the page.

`tailwind.config.js` moves the `brand` scale onto CSS variables
(`rgb(var(--brand-500) / <alpha-value>)`) so `branding.brand_color` can restyle the app at runtime,
with the Phase 1 indigo as the fallback.

---

## 6. Acceptance tests (Phase 2 is not done until these pass)

| Area | Test |
|---|---|
| Settings write | updating a group persists, flushes the cache, and writes one activity row per changed key with old and new values |
| Encryption | `mail.password` is unreadable in the raw DB row, decrypts correctly through the repository, and never appears in the rendered HTML or in the activity log |
| Validation | registry rules reject a bad email, a negative `minimum_payout`, an out-of-range tax rate, an unknown currency, and an invalid timezone |
| Uploads | logo upload stores on the public disk, replaces and deletes the previous file, rejects a non-image and an oversized file, and a disguised `.php` upload is refused |
| Reset | resetting a group restores registry defaults and logs it |
| Authorization | `settings.view` without `settings.edit` renders read-only and 403s the update; no permission at all 403s the page; the same matrix for modules and the dashboard |
| Mail test | uses the saved SMTP settings rather than `.env`, surfaces the failure message on a bad host, and is throttled |
| Module dependency | disabling a module that an enabled module depends on is blocked and names the dependents; cascade works only when explicitly requested; core modules cannot be disabled |
| Module data safety | row counts for a disabled module's tables are identical before and after disable + re-enable |
| Module audit | toggling writes `disabled_at`/`disabled_by`/`disable_reason` and an activity entry carrying the reason |
| Dashboard | widgets are filtered by permission and by module state; the date range changes the numbers; the layout preference persists per user and is not shared between users |
| Widget keys | every registered widget key is unique (assert `count(keys) === count(unique(keys))` across the whole registry) and registering a duplicate key throws `DuplicateWidgetKeyException` — no card can silently disappear (F-8.3) |
| Formatting | changing `currency`, `currency_position` and `date_format` changes `money()` and `app_date()` output everywhere |
| Maintenance | `maintenance_mode` on blocks the public site but never `/admin`; `public_site_enabled` off returns the holding page |
| Performance | the dashboard issues a bounded number of queries (assert with `DB::listen`) — no N+1 across widgets |

---

## Convergence log (2026-09-12)

| Finding | Change made |
|---|---|
| F-8.3 | §3 `DashboardRegistry` row: `key()` declared globally unique, and a duplicate registration now throws `DuplicateWidgetKeyException` instead of silently replacing the first card; one key = one owning phase. |
| F-8.3 | §3 widget list: added the ownership paragraph — the ten Phase 2 keys are Phase 2's, **`SystemHealthWidget` stays Phase 2's** (phase-24-25 refactors its body to read `SystemHealthService` and registers only its own four new keys), and Phase 2 must not register `FeeCollectedTodayWidget` / `PendingFeesWidget` / `OverdueFeesWidget` (Phase 18), the eight commission/wallet widgets (spine / Phase 10-12) or `WebsiteContentWidget` / `SeoHealthWidget` (Phase 3). |
| F-8.3 | §6: added the "Widget keys" acceptance test (all keys unique; duplicate registration throws). |
