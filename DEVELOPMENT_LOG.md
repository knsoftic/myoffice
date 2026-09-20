# DEVELOPMENT LOG — Software House + IT Institute Management System

> **Single source of truth for project progress.** Every session MUST read this file first and
> update it before finishing. Nothing is "done" until it is ticked here with a test note.

| | |
|---|---|
| **Project** | Software House ERP + IT Training Institute Management System |
| **Codebase** | `C:\xampp\htdocs\my office` |
| **Stack** | Laravel 12.69.2 · PHP 8.2.12 · MariaDB 10.4.32 · Tailwind 3.4 · Alpine 3 · Vite 7 |
| **Database** | `my_office` (utf8mb4_unicode_ci) |
| **Created** | 2026-09-12 |
| **Last updated** | 2026-09-19 |
| **Current phase** | PHASE 7 — Employees, departments, attendance, leave, payroll |

---

## 1. How to use this log

**Status legend**

| Icon | Meaning |
|---|---|
| [ ] | Not started |
| [~] | In progress |
| [x] | Done + tested |
| [T] | Code complete, testing pending |
| [!] | Blocked (see Open Questions) |
| [R] | Needs rework |

**Update protocol (mandatory at the end of every working session)**

1. Tick the feature rows you finished in **§5 Phase Tracker**.
2. Add a dated entry in **§6 Change Log** — what was added, which files, which migrations.
3. Record every test you actually ran in **§7 Test Results** (command + outcome). Never tick `[x]` without a test note.
4. Add any new architecture decision to **§4 Decisions** and mirror the rule into `CLAUDE.md`.
5. Log anything broken or deferred in **§8 Known Issues**.

**Hard rules (never violated, ever)**

- Never delete or rewrite financial history — reverse it with a negative entry.
- Never drop a table/column that holds real data; always write additive, safe migrations.
- Never hardcode roles or permissions in code — they live in the database.
- Never use float for money. `decimal(15,2)` + bcmath only.
- Every protected action is verified on the **backend** (policy/permission), not just hidden in the UI.

---

## 2. Environment snapshot

```
PHP        8.2.12 (C:\xampp\php\php.exe)  ZTS VC2019 x64
Composer   2.10.2
MariaDB    10.4.32  (127.0.0.1:3306, user root, no password — dev only)
Node       24.18.0 / npm 11.16.0
Extensions bcmath curl gd mbstring openssl pdo_mysql zip exif fileinfo  -> all required present
Missing    intl (not needed yet), redis (not needed — database queue/cache in use)
```

Installed packages beyond the Laravel skeleton:

| Package | Version | Purpose |
|---|---|---|
| spatie/laravel-permission | ^6.25 | DB-driven roles and permissions |
| spatie/laravel-activitylog | ^4.12 | Activity log + audit trail (old/new values) |
| laravel/breeze | ^2.4 (dev) | Auth scaffolding base (Blade + dark mode) |
| mews/purifier | ^3.4 (3.4.4) | Phase 3: the HTMLPurifier parser pass inside `App\Support\RichText` (D25); `config/purifier.php` only mirrors its two profiles |

Planned for later phases (install only when that phase starts):

| Package | Phase | Purpose |
|---|---|---|
| barryvdh/laravel-dompdf | 13 / 21 | Invoices, salary slips, result cards, certificates, ID cards |
| simplesoftwareio/simple-qrcode | 21 | Certificate + student ID QR codes |
| intervention/image | 3 | Avatar / CMS image resizing |
| maatwebsite/excel (or native CSV) | 23 | Excel export for reports |
| spatie/laravel-backup | 24 | Database + file backups |

---

## 3. Quick commands

```bash
php artisan serve
```

```bash
npm run dev
```

```bash
npm run build
```

```bash
php artisan migrate:fresh --seed
```

```bash
php artisan permission:cache-reset
```

```bash
php artisan test
```

Production-safe migration: `php artisan migrate --force`.
Queue + scheduler: `php artisan queue:work`, `php artisan schedule:work`.

---

## 4. Decisions (ADR summary — full conventions in `CLAUDE.md`)

| # | Decision | Why |
|---|---|---|
| D1 | **One Laravel app, one `users` table, one `web` guard.** Panels (admin / collaborator / student / teacher / client) are route groups gated by role + permission, not separate guards. | Spec demands centralized authentication; avoids five duplicated auth stacks. |
| D2 | **Domain profiles are separate tables** (`employees`, `students`, `teachers`, `clients`, `collaborators`) with a nullable `user_id`. | A student/client record must be able to exist before (or without) a login. |
| D3 | **spatie/laravel-permission** for RBAC, with `permissions` extended by `module`, `ability`, `group`, `label`, `sort_order`, and `roles` extended by `label`, `panel`, `is_system`, `level`. | Proven caching + Gate integration; extra columns give the per-module permission matrix without reinventing it. |
| D4 | Permission naming is **`module.ability`** (e.g. `projects.view`, `collaborator_payouts.approve`), generated from one registry class — never typed by hand twice. | Single source of truth shared by seeders, sidebar and policies. |
| D5 | **`modules` table + `module:<slug>` middleware.** A disabled module is denied for *everyone, including Super Admin* (sidebar hidden, routes 403, API blocked) while its data stays untouched. | Spec §6. |
| D6 | **Money = `decimal(15,2)` columns with bcmath arithmetic** through a `Money` helper. Floats are banned in financial code paths. | Spec §110. |
| D7 | **Commission is never created when a student or project is created — only when a payment is received**, inside a DB transaction, with a unique index on (source_type, source_id, collaborator_id) making it duplicate-proof. | Spec §39, §46, §52. |
| D8 | Reversals are **new negative ledger rows** referencing the original entry. Financial rows are never deleted or edited. | Spec §44, §49, §110. |
| D9 | **PHP enums** (string-backed, with `label()` and `color()`) for every status field instead of magic strings. | Prevents typo bugs across 40+ modules; one place to add a status. |
| D10 | **Settings live in the DB** (`settings` table, cached, `is_encrypted` flag for secrets such as SMTP password and payout details) with a `setting('group.key')` helper. Nothing user-facing is hardcoded. | Spec §100–104. |
| D11 | **Branch-ready from day one**: a `branches` table with one default branch exists now; institute tables carry a nullable `branch_id` as they get created. No branch-switching UI until requested. | Spec §113 — prepare, don't overcomplicate. |
| D12 | **Tailwind 3.4 via PostCSS with `darkMode: 'class'`** + Alpine; theme preference (Light/Dark/System) stored per user and mirrored in `localStorage`. | Spec §2. |
| D13 | Auditing uses the spatie `activity_log` table extended with `ip_address`, `user_agent`, `device`, `module`, `reason` — one store serving both Activity Log (§106) and Audit Trail (§107). | Avoids two parallel, diverging log tables. |
| D14 | Laravel 12 style: route files, middleware aliases and global middleware are registered in `bootstrap/app.php`. | Framework convention. |
| D15 | Public self-registration is **disabled**; accounts are created by staff, or through admission/inquiry flows that create a pending record first. | Closed business system. |
| D16 | **Append-only financial tables carry no `deleted_at`** — payments, reversals, fee discounts, referrals, commission rule versions, entitlements, ledger entries, payouts and payout allocations. This is a deliberate exception to the "every business table has soft deletes" rule in `CLAUDE.md` §3. Correction happens only by posting a reversing row. | A nullable `deleted_at` on an immutable ledger is a loaded gun: a single `->delete()` would hide the row from every aggregate while the wallet cache keeps the money. Raised as Q1 by the financial spine; **approved 2026-09-12**. |
| D17 | **DB CHECK constraints, STORED generated columns and `BEFORE DELETE` triggers are part of the contract.** Developers must expect raw SQL errors on an illegal DELETE; factories and seeders never delete money rows. | The guarantee has to live where a future developer cannot forget it. Spine §2.1, §2.19. |
| D18 | **A payout allocates named ledger entries with partial amounts**, never a running balance; "paid commission" is always derived from `collaborator_payout_allocations`. | Requirement §120.9 needs a 20,000 payout against a single 50,000 entry without a manual split. |
| D19 | **Soft deletes are the default and are deliberately omitted on append-only tables.** Append-only = money, audit, log, snapshot/revision, view-counter, numbering and history-pivot tables. Mutable business tables (documents, profiles, catalogues, configuration) keep `deleted_at`. The full category rule is in `CLAUDE.md` §3. | Eleven contracts deviate from "every business table" for the same reason; one rule beats fifty exceptions. Audit F-9.1. |
| D20 | **`Modules::permissionModuleMap()` returns `null` for a permission whose prefix is not a registered module slug, and `Gate::before` falls through on null.** `client_portal.*`, `student_portal.*`, `teacher_portal.*` and `collaborator_portal.*` are permission namespaces, not modules. | Without it an unknown prefix reads as "disabled" and all four non-admin panels are dead for everyone, Super Admin included. Audit F-12.1. |
| D21 | **No private artefact is ever on the public disk.** CVs, client and employee documents, expense receipts, invoice PDFs, course materials, submissions, certificates, ID cards and exports are streamed by a controller that re-runs the full permission chain; no signed URL, no guessable path. | §111. One rule for every upload field, recorded in `upload-manifest.php`. |
| D22 | **Public website content is published by snapshot** (`content` -> `published_content`) and invalidated by a cache **version stamp**. | The database cache driver has no tag support. Phase 3 [D-W3-7], [D-W3-14]. |
| D23 | **One SEO store**: every public-facing entity gets SEO through the `seo_meta` morph and `SeoService`; no phase adds SEO columns to its own table. | Otherwise the SEO screen, the sitemap and the fallback chain cannot see half the site. Audit F-2.3. |
| D24 | **Two media tiers**: `media_assets` + `MediaService` + `ImageProfile` is mandatory for anything rendered on the public website; a bare `*_path` column is allowed only for a private profile photo or document. | One public image pipeline (derivatives, alt text, usage counting, delete guard); no second uploader. Audit F-2.4. |
| D25 | **One HTML sanitiser**: `App\Support\RichText::sanitize()` over `mews/purifier`, applied on write and again on render. | A duplicated security control is a security defect: SEC-05 must have one answer. Audit F-2.5. |
| D26 | **A content module may gate its own public routes with `site_module`** (404, leaving no trace); disabling `website_sections` can never take the public site down. | Keeps Phase 3's INV-15 guarantee and legalises Phase 4's middleware. Audit F-6.6. |
| D27 | **`App\Services\Finance\DocumentNumberService` is the single numbering implementation, shipped by Phase 5** (the earliest consumer), default pad `'%06d'`, every caller passing its own pad; `reserve()` adds a period reset. | Three phases each claimed to create it and four need it before Phase 10. Audit F-4.1, F-4.12. |
| D28 | **Later-phase data reaches an earlier phase's screens only through a capability contract or a registry**; an unavailable capability renders an empty state or 404s, never a fabricated number. | Phase 5 [D-P5-1]. |
| D29 | **Duplicate contacts are detected and warned, never blocked by a database constraint**; normalisation happens in `ContactNormalizer`, in PHP, into plain indexed columns. | Phase 5 [D-P5-5]. |
| D30 | **`leads.view_any` means the whole pipeline and `leads.view` means own records only**; no new ability and no visibility setting is introduced. | Phase 5 [D-P5-8]. |
| D31 | **Phase 5 owns `routes/client.php` and every `client.*` route name**; later phases append screens through `ClientPortalRegistry` and never redeclare a name, and every client route carries `client.context`. | Laravel's last registration wins silently: two URIs under one name is a live bug. Revocation must also be immediate. Audit F-6.2, F-12.3. |
| D32 | **Assignment of work points at `users.id`; an organisational duty points at `employees.id`; the bridge is `employees.user_id` (nullable, unique).** | Assignment drives login, permissions, notifications and timers; a duty is a post. Phase 6 [D-P6-2] and Phase 7 [D-HR-2] were each right about one half. Audit F-11.1. |
| D33 | **Elapsed time is stored only as append-only `time_entry_segments`**; every duration is a recomputed cache and "one running timer per worker" is a unique index. | Phase 6 INV-P4…P6. |
| D34 | **Progress is derived by `ProjectProgressService`**, the only writer of `progress_percent`; a per-project manual override is reasoned, attributed and revocable. | Phase 6 [D-P6-3]. |
| D35 | **`project_value_revisions` is append-only with a `BEFORE DELETE` trigger**, and the five value/override columns on `projects` are writable only by `ProjectValueService`. | Project value feeds commission. Phase 6 INV-P1, INV-P3. |
| D36 | **A payroll run is immutable from `lock()`**: there is no unlock, and a correction is a new item on a `correction` run referencing the original. | Phase 7 HR-15, HR-17. |
| D37 | **`collaborator_referrals` is the single truth of attribution.** Every `*.collaborator_id` / `*.referral_code` on a subject table is a display snapshot: no engine reads it and **no access scope may use it**. | A stale snapshot would otherwise grant one partner sight of another's project and contract value (§112). Audit F-8.2. |
| D38 | **A referral decision follows a documented six-rank precedence ladder** in which an authenticated staff selection always wins, the losing candidate is preserved as a superseded row with its reason, and nothing the browser posts is trusted as a code. | Phase 9 INV-R2, INV-R3. |
| D39 | **Exactly one calculation site per commission side**, reached only through an `afterCommit` event -> unique job chain; payments and ledger rows may be inserted only through `PaymentService` / `LedgerWriter`. | Phase 10 [D-IMP-2], [D-IMP-3] — the rule a future phase is most likely to break. |
| D40 | **`invoices.paid_amount` / `refunded_amount` / `balance_amount` are a cache of one canonical SQL** over `project_payments`; nothing may `increment()` them. | Phase 13 R-1. |
| D41 | **`finance_reversals` is the append-only expense / other-income counterpart of `payment_reversals`**; the two are never merged and neither is ever deleted. | Phase 13 §2.6. |
| D42 | **An invoice number is assigned once at issue**, never to a draft and never reused; a cancelled invoice keeps its number. | Phase 13 §2.9. |
| D43 | **The single concession to INV-8**: `project_payments.invoice_id` may move NULL -> value -> NULL, by `InvoiceService` alone, reason mandatory, audited, gated by `project_payments.link_invoice` + `invoices.edit`, with zero commission effect. | An advance received before its invoice existed must be attachable or `invoices.paid_amount` can never be right. Nobody may later "tighten" INV-8 back. The gate is a **dedicated narrow ability**, not `project_payments.edit`: the spine's "a received payment is never editable" rule must stay literally true, so `link_invoice` grants this one field move and no other mutation. Audit F-4.10, drift RD-1/RD-2. |
| D44 | **One `approved` expense row per paid payroll run** (`expenses.source_type` / `source_id`, unique), written by a listener on `PayrollRunPaid`. | Without it §99's profit and loss is wrong by the whole payroll. Audit F-3.9. |
| D45 | **The §68 admission pipeline is carried by `student_admissions.stage`**, with `students.status` and `course_inquiries.status` advanced in lockstep by `AdmissionService` only. | Three status columns, one writer. Phase 15 R-10. |
| D46 | **Attendance and syllabus coverage attach to a dated `class_sessions` row**, never to a recurring timetable rule. | Every later phase (exams, certificates, reports) depends on it. Phase 16 [D-IN-10]. |
| D47 | **Schedule overlap is enforced by `ScheduleClashDetector`** (one generic `check(SlotCandidate)`) under parent row locks plus a nightly verifier. | MariaDB cannot express a range constraint, so nobody should "fix" this with a unique index. Phase 16 R-1. |
| D48 | **Capacity is enforced by a locked recount**; `batches.current_students` is a cache with no authority. | Phase 16 INV-I6, INV-I7. |
| D49 | **An installment plan's live lines minus waivers always equal the charge's net fee**, and every discount redistributes the plan in reverse due-date order. | Phase 18 PI-1. |
| D50 | **Installment numbers are never reused and never renumbered**; a rebuild continues from `MAX + 1`. | Fee disputes quote a number. Phase 18 R-5. |
| D51 | **A mark's ceiling is enforced three times** — Form Request, service, and a database CHECK made possible by snapshotting `total_marks` onto the result / submission row. | Phase 19/20 INV-19-6, INV-20-1. |
| D52 | **An issued certificate and an issued ID card are snapshots**; a later rename, template edit or settings change alters neither their content nor their QR target. A wrong certificate is revoked and reissued. | Phase 21 INV-21-1, INV-21-4. |
| D53 | **A print template is sanitised HTML with `{{tokens}}`**, rendered by a token replacer — never Blade, never `eval`. | Phase 21 INV-21-5. |
| D54 | **§94's six role pairs live in `App\Support\MessagingMatrix`** plus one multiselect setting, re-checked on every send. | It is the reason a student cannot message a client. Phase 22 INV-22-4. |
| D55 | **Every notification is declared in `NotificationRegistry`** and delivered by `NotificationService`; a disabled `notifications` module is a logged no-op that never rolls back a business write. | Phase 22 INV-22-7, INV-22-8. |
| D56 | **A report is a declaration in `ReportRegistry` that delegates every figure to the service owning the table**; a `SUM()` in a report class is a review failure, and a withheld column is absent from the query and the file. | Phase 23 INV-23-1, INV-23-2. |
| D57 | **Release deploys are directory swaps with a rename rollback.** | Phase 25. |
| D58 | **Three separated MySQL users**: runtime DML, migration DDL, backup read-only. | Phase 25; closes tech debt T3. |
| D59 | **`Model::shouldBeStrict()` outside production is the N+1 audit.** | Phase 24. |
| D60 | **The four manifests are the authority for every sweep**, and `audit:manifest --check` is part of every phase's definition of done. | Phase 24 §13.2. |
| D61 | **Storage timezone is UTC; `localization.timezone` is display-only.** `config/app.php` stays `'UTC'` and nothing changes it at runtime; `Format` helpers render in the display zone, and `DateRange` takes input in it and queries in UTC boundaries. | The Phase 2 build switched `app.timezone` at boot from the setting, so rows written after it were Asia/Karachi wall-clock while Phase 1 rows were UTC — a silent five-hour break in audit and, later, financial timestamps. `.env`'s `APP_TIMEZONE` was never read (Laravel 12 hardcodes the config value). Contract audit CRITICAL. Rows already written in local time are demo data and are counted, not rewritten. |
| D62 | **Document counters are never admin-editable settings.** Every `*_next_number` key is readonly in `SettingsRegistry`: shown, never posted, refused by `SettingsService`. Only `DocumentNumberService` (Phase 5, D27) advances a counter, under a row lock. | A settings form posts every field: an admin saving `tax_label` with a stale `invoice_next_number=41` after INV-41 and INV-42 were issued would roll the counter back and re-issue a number — breaking D42. Contract audit CRITICAL. |
| D63 | **Disabling a module requires a reason on the server**, not only in the browser (min 5, max 255 characters), and `ModuleService` refuses an empty reason. | `phase-02.md` §5 requires it; the Phase 2 build enforced it only in the Alpine modal, so any direct request skipped the audit reason. Four Phase 1 tests that disabled a module without a reason are updated — a correctly stricter rule, not a loosened test. |
| D64 | **`settings.edit_mail` is a narrow ability only Super Admin holds.** Editing the SMTP group and running the mail test need it in addition to `settings.edit`. | Delivers the Phase 1 §5 promise that the Admin role cannot change outgoing mail credentials — a compromised Admin account must not be able to redirect password-reset mail. Added by the Phase 2 build (788 permissions); recorded here after the contract re-review flagged it as undecided. |
| D65 | **Seeders converge additively on existing roles.** `RoleSeeder` grants registry permissions a system role is missing but never revokes one an administrator granted in the role editor; a fresh install still gets exactly the seeded grants. `DemoUserSeeder` refuses production even with `SEED_DEMO=true`. | `syncPermissions` would silently undo every role-editor change on each deploy that re-runs seeders; and weak demo passwords must be impossible in production, not merely off by default. Raised by the installation-guide review. |
| D66 | **A milestone on hold can be resumed.** phase-06 §2.13.2 lists `pending` / `in_progress` -> `on_hold` but no row back out except `cancelled`. `MilestoneStatus::allowedTransitions()` adds `on_hold` -> `pending` / `in_progress`, and `MilestoneService` checks the held-from stamp on top. | Taken literally the table traps a held milestone forever — the only way out would be to cancel it, which is a different business fact. The project lifecycle §2.13.1 has exactly the missing row ("`on_hold` -> the status it was held from"), so the omission reads as an editing slip rather than a rule. |
| D67 | **The clock columns `time_entries.started_at` / `ended_at` and `time_entry_segments.started_at` / `ended_at` are `DATETIME`, not the `TIMESTAMP` phase-06 §2.10-§2.11 names.** Every other Phase 6 stamp stays `TIMESTAMP`. | This server runs `explicit_defaults_for_timestamp = OFF`, where the first `TIMESTAMP NOT NULL` column in a table silently acquires `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` — verified on `my_office_test` before the migrations were written. On an append-only clock record that would rewrite `started_at` on any UPDATE and change `duration_seconds` underneath every SUM already taken, breaking INV-P5 silently. `DATETIME` carries no such rule, and with the session timezone pinned to `+00:00` (D61) the two types store the same UTC instant. The other stamps are all nullable, which never triggers the rule. |
| D68 | **The three Phase 7 date guards use `CAST(date AS CHAR)`, not the `DATE_FORMAT(col, '%Y-%m-%d')` phase-07 §2.8 / §2.9 / §2.16 spell them with.** | MariaDB refuses `DATE_FORMAT()` inside a generated column outright — `1901 Function or expression 'date_format()' cannot be used in the GENERATED ALWAYS AS clause` — because it classes it as locale-dependent. Casting a DATE to CHAR is deterministic, is accepted, and produces the identical `YYYY-MM-DD` string; both forms were run side by side on this server before the change was made. Without a working guard, `uq_hol_guard`, `uq_att_day` and `uq_lrd_day` could not exist, and HR-1 and HR-8 would be service conventions rather than database facts. |

---

## 5. Phase Tracker

### [x] PHASE 0 — Project bootstrap (2026-09-12)

| | Item | Note |
|---|---|---|
| [x] | Laravel 12.69.2 installed at project root | `composer create-project` |
| [x] | MariaDB database `my_office` created | utf8mb4_unicode_ci |
| [x] | `.env` + `.env.example` configured (MySQL, Asia/Karachi, `FILESYSTEM_DISK=public`) | sqlite file removed |
| [x] | spatie permission + activitylog installed | ^6.25 / ^4.12 |
| [x] | Breeze (Blade + dark) scaffolded, npm installed, assets built | Tailwind 3.4.19 + Alpine 3 |
| [x] | `DEVELOPMENT_LOG.md`, `CLAUDE.md`, `docs/phases/phase-01.md` written | this file |

### [x] PHASE 1 — Laravel setup, authentication, roles & permissions

Contract: [`docs/phases/phase-01.md`](docs/phases/phase-01.md) · built 2026-09-12 · **remediation in progress**

| | Item |
|---|---|
| [x] | Migrations: 14 total (3 Laravel + 4 vendor + 7 Phase 1). `migrate:fresh` clean, `rollback --step=7` clean, re-`migrate` clean |
| [x] | Enums: `UserStatus`, `ThemePreference`, `PanelType`, `ModuleGroup`, `Ability`, `LoginStatus` + `Concerns\HasOptions` |
| [x] | Models: `User`, `Role`, `Permission`, `Module`, `Setting`, `Branch`, `LoginHistory`, `Activity` (custom models bound in both configs) |
| [x] | Traits: `Blameable`, `LogsActivityWithContext`, `WritesAuditTrail` |
| [x] | `PermissionRegistry` — 79 modules (10 core), **787 permissions**, ability presets, portal prefixes |
| [x] | Seeders (idempotent): 1 branch, 79 modules, 787 permissions, 18 roles, 95 settings in 8 groups, 18 users |
| [x] | Auth: login, logout, forgot, reset, change password, email verification, profile + avatar; `/register` returns 404 (D15) |
| [x] | Account state enforcement at login and on every request (`active` middleware, forced password change) |
| [x] | Login history with IP/device/platform/browser/session id; session list + revoke |
| [x] | Middleware: `EnsureUserIsActive`, `EnsureModuleEnabled`, `EnsurePanelAccess` + spatie aliases |
| [x] | `Gate::before` — module-disabled denial first, then Super Admin bypass |
| [x] | Admin UI shell: sidebar (permission + module aware), topbar, 3-way theme switcher, ~35 `x-ui` components, 60+ icons |
| [x] | Admin CRUD: Users, Roles (permission matrix editor), Permissions viewer, Modules toggle |
| [x] | Activity log + login history viewers, with CSV export behind the export permission |
| [x] | 4 panel dashboards (collaborator / student / teacher / client) with portal-permission gating |
| [x] | Acceptance suite: **587 tests / 18,334 assertions green** on `my_office_test` (371 test methods across 40 files) |
| [x] | Adversarial review remediation: both HIGH findings closed and hand-verified (29/29 checks through the real HTTP kernel), 6 medium fixed, re-reviewed by two independent auditors |
| [ ] | Carryover to Phase 2 (one real medium + small items) — see Known Issues T11–T18 |
| [ ] | Browser pass: light/dark render + console errors (no PHPUnit test can see this — manual or Dusk) |
| [ ] | Rollback of all 14 migrations executed against `my_office_test` (suite only asserts every `down()` is non-empty) |

**Committed** `a22b7d9` — Phase 1 is a rollback point.

### [x] PHASE 2 — Admin dashboard, system settings, module management

Contract: [`docs/phases/phase-02.md`](docs/phases/phase-02.md) — **written 2026-09-12**, build starts the
moment Phase 1 is verified (both phases touch `Admin/DashboardController`, `Admin/ModuleController`,
`routes/admin.php` and `tailwind.config.js`, so they cannot run concurrently).

| | Item |
|---|---|
| [x] | Phase 2 contract written (schema deltas, `SettingsRegistry`, services, routes, UI, 14 acceptance tests) |
| [x] | Migrations: `modules.depends_on` + disable audit, `settings.updated_by`/`is_readonly`, `users.preferences` |
| [x] | `SettingsRegistry` — 13 groups, ~110 typed fields with rules/defaults/help, driving seeder + form + validation |
| [x] | `SettingsService` (upload handling, encryption, per-key audit, group reset) + `ConfigureFromSettings` runtime wiring |
| [x] | Mail settings + test-email service (uses saved SMTP, throttled, password never exposed) |
| [x] | `ModuleService` dependency resolution, impact preview, cascade, disable audit, data-safety guarantee |
| [x] | `DashboardRegistry` + 10 real widgets + `DateRange` + per-user widget layout |
| [x] | Settings UI (tab rail, dirty save bar, live branding preview, reset-to-defaults) |
| [x] | Modules UI (grouped cards, impact modal with reason, bulk toggle) |
| [x] | Dashboard UI (widget grid, date range, customize mode, Chart.js `x-ui.chart`) |
| [x] | Runtime brand colour via CSS variables in `tailwind.config.js` |
| [x] | `Format` helpers (`money()`, `app_date()`) reading localization settings |
| [x] | 14 acceptance tests green |

**Committed** `515465b` (build), `39c49d3` (close-out). Verified on a Phase-2-only tree: **1246 tests / 32,270 assertions**.
### [x] PHASE 3 — Dynamic public website CMS (sections, menus, pages, SEO)

Contract: [`docs/phases/phase-03.md`](docs/phases/phase-03.md) · integration plan `docs-pending/phase-03-integration.md` (steps A–M) ·
verified and committed 2026-09-13

| | Item |
|---|---|
| [x] | Schema: 10 migrations / 14 tables (applied to `my_office` in batch 4 earlier, empty until seeded); 7 CHECK constraints, 2 STORED generated `has_unpublished_changes`, 10 `uq_*` guards — L.7 SQL 7 / 2 / 10 |
| [x] | Registries: PermissionRegistry **82 modules / 812 permissions** (+`website_cta_blocks`, `faq_categories`, `website_media`; +24 permissions); SettingsRegistry **14 groups** (+`website`, 13 keys; +4 `seo` keys); 6 CMS model→module mappings; 9 Website sidebar entries |
| [x] | Services: `PageService` (+ `RESERVED_SLUGS`), `MenuService`, `CtaBlockService`, `FaqService`, `StatisticsProvider` on top of `ContentPublisher` (D22), `SectionService`, `SeoService` (D23), `MediaService` (D24), `SitemapGenerator`, `RichText` (D25) |
| [x] | Middleware: `site` = `EnsurePublicSiteAvailable` (holding / maintenance 503 with `X-Robots-Tag: noindex`, staff bypass on `website_sections.view` with the ribbon), `site_module` (D26), `site.cache`, `site.preview` |
| [x] | Routes: 78 `admin.website.*` + 7 `site.*` (151 routes, no duplicate names); `/{slug}` catch-all loaded last behind a reserved-slug lookahead; skeleton `public/robots.txt` deleted so `site.robots` is reachable |
| [x] | Scheduler: `cms:publish-scheduled` (sends `ScheduledPagePublished`), `cms:sitemap-generate`, `cms:media-recount`, `cms:verify-published-snapshots` |
| [x] | Seeders: `RoleSeeder` converges additively (D65), `DemoUserSeeder` never seeds production, insert-only `WebsiteCmsSeeder` (4 menus, 9 items, 4 system pages, 6 live sections, 15 items, 1 CTA, 3 FAQ categories, 6 FAQs, 5 seo_meta) |
| [x] | Packages / assets: `mews/purifier` 3.4.4 + `config/purifier.php` mirroring the two `RichText` profiles; `resources/data/icons.php` (96 icons); `storage:link`; `npm run build` |
| [x] | Acceptance: every contract row FT-01 … FT-51 + FT-36b maps to a passing test in `tests/Feature/Cms` (103 tests) |
| [x] | Suite **1350 tests / 39,156 assertions** green sequentially and in random order; HTTP smoke 171/171 |
| [ ] | Manual browser pass (L.8: 375 / 768 / 1280 px, light and dark, focus trap) — FT-51 is asserted on the rendered HTML only (T7) |
| [ ] | Deferred §10 pieces: the §10.1 events and `BumpPublicCacheVersion` (the services bump the cache stamp after commit instead, FT-25 asserts +1), the five §10.2 queued jobs (derivatives run synchronously after commit), four of the five §10.3 notifications, `cms:warm-cache` / `cms:prune-revisions` / `cms:check-links`, the §13.2 dashboard widgets |
| [x] | Review round 1 closed (1 critical, 1 high, 4 medium, lows): `is_live` section providers resolved per render behind `App\Contracts\Cms\SectionDataProvider` ([D-W3-11]); CTA blocks and FAQs status-gated and live on the public page (§2.15, §9, FT-12); `pages.edit` alone cannot change a live page's live columns or edit a scheduled page ([D-W3-10]); `SettingsChanged` → `PublicCache` bump (INV-8); `SitemapRegistry` / `SitemapUrlProvider` / `PublicCache` shipped under the contract names |
| [x] | Review-fix verification: suite **1365 tests / 39,349 assertions** green sequentially and in random order; `tests/Feature/Cms` 118 tests; HTTP smoke 212/212 on `my_office` in a rolled-back transaction; seeders converge with 0 changes (D65) |
| [x] | Review round 2 closed (1 high, lows): **the four manifest files created (E4, B9)** — `tests/Support/{screen,route-guard,upload,index}-manifest.php` plus `raw-output-allowlist.php` carry Phase 3's rows (85 routes, 35 screens, the media upload field, every Keys-block index, the two `{!! !!}` echoes) and `CmsManifestTest` keeps them equal to the code; sitemap / robots.txt never built from the request `Host` (`PublicOrigin` + `trustHosts`); page cache bounded (host check, per-route `page` / `category` keys, 500-variant cap, hourly `cms:cache-prune`); a module switch bumps the public cache; a zero live statistic falls back; `menus.change_status` on the item `PUT`; a live section anchor needs `website_sections.change_status`; media regenerate throttled; `<x-site.image :asset>`; readonly settings for the two unshipped jobs; footer map embed; non-Page canonical on the configured base; `layouts.site` alias; no `rescue()` around `site_setting()` (FT-42) |
| [x] | Review-round-2 verification: suite **1389 tests / 41,436 assertions** green sequentially and in random order; `tests/Feature/Cms` 142 tests; HTTP smoke 225/225 on `my_office` in a rolled-back transaction; seeders: 0 setting values, 0 module `is_enabled`, 0 role grants changed (D65), 2 expected `is_readonly` metadata refreshes |

**Committed** — Phase 3 is a rollback point.
### [x] PHASE 4 — Services, portfolio, blog, careers
### [x] PHASE 5 — CRM: leads (Kanban), clients, client panel

Contract: [`docs/phases/phase-05.md`](docs/phases/phase-05.md) · integration plan `docs-pending/phase-05-integration.md` ·
integrated and committed 2026-09-19

| | Item |
|---|---|
| [x] | Schema: 10 migrations / 9 tables (`clients`, `client_contacts`, `client_documents`, `leads`, `lead_activities`, `lead_follow_ups`, `lead_conversions`, `lead_imports`, `lead_import_rows`) + a deferred-FK migration; applied to `my_office`, 0 pending |
| [x] | Registries: PermissionRegistry **87 modules / 865 permissions** (+`client_documents`, +`clients.view_logs`, +6 `client_portal` abilities appended per D4); SettingsRegistry **15 groups / 192 keys** (+`crm`, 34 keys); 6 CRM model→module mappings in `Modules`; leads + clients sidebar entries, client tree rebuilt to 13 entries |
| [x] | Enums (13): `ClientStatus`, `ClientType`, `ClientDocumentCategory`, `LeadStatus`, `LeadActivityType`, `LeadContactOutcome`, `LeadConversionType`, `LeadDuplicateMatchType`, `LeadFollowUpStatus`, `LeadFollowUpType`, `LeadImportStatus`, `LeadImportRowStatus`, `LeadImportDuplicateStrategy` |
| [x] | Services (23): `LeadService`, `LeadBoardService`, `LeadFollowUpService`, `LeadConversionService`, `LeadImportService`, `LeadDuplicateDetector`, `LeadAutoAssigner`, `LeadTimeline`, `LeadExporter`, `ClientService`, `ClientContactService`, `ClientDocumentService`, `ClientPortalService`, `ClientPortalAccess`, `ClientExporter`, `CapturedReferralService` + 6 CRM exceptions |
| [x] | Numbering: `LeadService` / `ClientService` draw `lead_no` and `client_code` from `App\Services\Finance\DocumentNumberService` (D27); both `*_next_number` keys are readonly in the registry (D62, verified) |
| [x] | Policies (11) + `ClientPortalPolicy` gate; `EnsureClientContext` middleware aliased `client.context`; `ClientContext` / `ClientPortalRegistry` support classes |
| [x] | Routes: 66 `admin.*` CRM routes + **24 `client.*` routes, every one carrying `client.context` (D31, verified)**; 409 routes in total with no duplicate name |
| [x] | Portal extension points: `ClientPortalSection` / `ClientPortalRecordSection` / `ClientPortalDownloadSection` contracts (D28), Documents + Notifications sections registered by `AppServiceProvider::registerPhase05()` |
| [x] | Capability contracts for later phases: `ClientFinancialsProvider`, `ClientReferenceGuard`; `ReferralRecorder` / `ProjectCreator` bound to null implementations until Phases 6 and 9 |
| [x] | Public-site wiring: `CrmLeadInquiryTarget` registered against the Phase 4 inquiry router, so a website inquiry becomes a lead |
| [x] | Scheduler (6 commands): `crm:follow-up-reminders`, `crm:follow-ups-mark-missed`, `crm:record-captured-referrals`, `crm:import-pending-inquiries`, `crm:prune-imports`, `crm:stale-lead-digest`; 4 queued jobs, 11 notifications, 20 events + 8 listeners |
| [x] | `DemoUserSeeder` gives the demo Client login a real `clients` row (`CL-DEMO01`, portal enabled) — the panel guards stayed as written, nothing was loosened |
| [x] | Manifests: Phase 5 index rows appended to `tests/Support/index-manifest.php` |
| [x] | Integration gate `tests/Feature/Crm/CrmSmokeTest.php` — 6 tests: every CRM screen answers for a Super Admin, each is refused without its permission and opens with it, the client panel resolves the signed-in user to their own client, a login with no client row is refused, and both services number their record |
| [ ] | **Phase 5's full acceptance suite (phase-05 §11) is still owed** — the smoke test is the integration gate, not the acceptance run |
| [ ] | Carried deviations: `docs-pending/phase-05-integration.md` C.7 (contract deviations) and W.5 (search not applied on some screens, staff logo upload missing) |

**Committed** — Phase 5 is a rollback point.
### [ ] PHASE 6 — Projects, milestones, tasks, time tracking

Contract: [`docs/phases/phase-06.md`](docs/phases/phase-06.md) · **foundation built 2026-09-19, services and
screens still to come**

| | Item |
|---|---|
| [x] | Enums: all 14 of §3 — `ProjectStatus`, `ProjectType`, `ProgressMode`, `ProgressBasis`, `MilestoneStatus`, `TaskStatus`, `Priority`, `ProjectMemberRole`, `TimeEntrySource`, `TimeEntryStatus`, `TimerStopReason`, `AttachmentVisibility`, `CommentVisibility`, and `CommissionCalculationType` (declared here for the spine, F-5.5). 54 cases; every `label()` / `color()` / weight arm exercised; the four transition tables proved closed over their own enum with no self-transition |
| [x] | Schema: 11 migrations / 11 tables, applied to `my_office_test` **and** `my_office`, `rollback --step=11` clean (0 tables left), re-migrate clean |
| [x] | §2.14 objects verified against `information_schema`, not by eye: **13 STORED generated columns, 27 CHECK constraints, 7 named unique indexes, 1 trigger** — the exact counts the contract names |
| [x] | Every guard proved to bite: **29 database-level assertions** (`net_value` unwritable, `chk_projects_commission` refusing a half-configured override, `trg_pvr_no_delete`, `chk_pvr_change`, `uq_pm_user_active` freeing its slot on soft delete, `chk_tasks_depth` both ways, `uq_te_running`, `uq_tes_open`, an open segment generating 0 seconds and 1500 on close) |
| [x] | Models: 11, with `Blameable` / `LogsActivityWithContext` / `SoftDeletes`, enum casts, the §2.12 relation map, and the six-alias morph map of §2.9 |
| [x] | `GuardsServiceOwnedColumns` — INV-P1 / INV-P8 / INV-P13 as model hooks: the five value columns, the progress columns and the attribution columns each name their one owning service, and the service brackets its own write with `unlock()`. **26 model-level assertions** green, including `ImmutableRevisionException` on a revision update or delete and the append-only segment rules of §2.11 |
| [x] | Phase 5's hand-off now resolves: `Client::projects()` and `LeadConversion::project()` reach `App\Models\Project\Project` instead of throwing |
| [x] | §4 PermissionRegistry: the new `task_comments` slug and the missing abilities on `projects`, `project_milestones`, `tasks` and `time_tracking` — **88 modules / 879 permissions**. Nothing was removed: taking an ability away would revoke a granted permission (D65) |
| [x] | §5 SettingsRegistry: the `projects` group (sort 86) with its 17 keys — **16 groups / 209 keys**; `project_code_next_number` readonly (D62). `RoleSeeder` converges: 20 grants added, none revoked |
| [x] | §6 services (10): `ProjectNumberService` (a thin delegate, D27), `ProjectService`, `ProjectValueService`, `ProjectProgressService`, `MilestoneService`, `TaskService`, `TaskCacheService`, `TimeRollupService`, `TimerService`, `TimeEntryService`; 38 events; `Money::weightedAverage()` / `clamp()` added additively for §6.3's 4-decimal averages |
| [x] | §6.3 progress algorithm, all four levels — proved on 16 cases including the two the contract argues about (`in_review` with 1 of 10 ticks stays 75; `in_progress` with 9 of 10 is 90) and INV-P9 (cancelling the unfinished subtask lifts the parent to 100, not 50) |
| [x] | §6.4 timer and §6.5 fractional Kanban ordering; §7 routes (48) with 9 policies; **money is withheld from the SELECT, not blanked in the view** (§9) |
| [x] | §8 admin screens (13 Blade files): projects index / create / edit / show / value / team, milestones index + show, tasks index / create / show / board, time tracking |
| [x] | Verified in a browser against the running app — the projects list, the project page (derived progress reads the 43 % §6.3 predicts), the Kanban board, and a timer started and stopped through the UI with **0.00 stored hours while it ran** (INV-P5 on screen) |
| [x] | `tests/Feature/Project/ProjectDeliveryTest.php` — 13 tests / 41 assertions, green first run |
| [x] | §6.1 the remaining services: `ProjectReferralService` (INV-P13 — refuses rather than guessing once evidence exists), `TaskCommentService`, `AttachmentService` (private disk, extension **and** sniffed MIME), `TimesheetService` (one GROUP BY per rollup, grouped on `work_date`) |
| [x] | §10.4 scheduler: `projects:auto-stop-timers`, `projects:verify-constraints` (passes against `my_office`), `projects:recalculate-progress`, `attachments:prune-deleted` |
| [x] | §7.7 client panel: `ProjectsSection`, `MilestonesSection`, `TasksSection` registered into Phase 5's registry (D31), each with an explicit column list that never selects money or hours; four client views. Checked in the browser as the demo client — the page shows the project and its 43 %, and contains no money at all |
| [x] | **A Phase 5/6 seam fixed**: `client.projects.show` read `can:viewByClient,project`, but that controller takes the id as a string, so no model reached the gate and it denied with 403 — the opposite of the 404 §9 requires. Ownership is now `ProjectsSection::find()`, as elsewhere in the panel; `ProjectPolicy::viewByClient()` ships and holds wherever a model is in hand |
| [ ] | §7.6 collaborator panel and §8.10 its screens — **blocked until Phase 8 ships `collaborators`** |
| [ ] | §10.1-§10.3 notifications and queued jobs; the dashboard widgets of §8.12 |
| [ ] | §11 acceptance tests P6-01 … P6-55 (the delivery test above is the integration gate, not the acceptance run) |
| [ ] | Phase 6's four manifest files (`tests/Support/*-manifest.php`) and their manifest test. The Phase 4 manifest test walks **its own** table list, so the new tables are not covered by anything today — P6-53 asks for the §2.14 object list to be asserted in CI |
### [ ] PHASE 7 — Employees, departments, attendance, leave, payroll

Contract: [`docs/phases/phase-07.md`](docs/phases/phase-07.md) · **built 2026-09-20; the named acceptance
cases FT-HR-01 … FT-HR-62 and §10's jobs and notifications are still owed**

| | Item |
|---|---|
| [x] | Enums: all 28 of §3 / 155 cases, including `LedgerEntryType` and `PaymentMethod`, which Phase 7 declares on the finance spine's behalf because it migrates first ([D-HR-14], F-5.4). Every `label()` / `color()` arm exercised, and the two money-adjacent string contracts **checked rather than assumed**: every `AttendanceStatus::defaultPayableFactor()` and every `LeaveDayPortion::fraction()` is a 4-decimal string, never a float (HR-12) |
| [x] | Schema: 27 migrations / 24 tables, applied to `my_office_test` **and** `my_office`; `rollback --step=27` leaves no table and no trigger behind, re-migrate clean. The two circular edges (`departments.head_employee_id`, the payroll lock on attendance) are their own migrations, so `migrate:fresh` works in any order ([D-HR-1]) |
| [x] | §2.27 step 18: one raw-SQL migration carrying **9 STORED generated columns, 7 guard unique indexes, the §2 CHECK constraints and 5 `BEFORE DELETE` triggers**. Each is verified after creation and throws if the server did not keep it |
| [x] | Every guard proved to bite: **19 database assertions** — one attendance row per employee per day (HR-1), one counted leave day per date (HR-8), one open salary version (HR-10), one live regular run per month; a payable factor above 1 refused (HR-4); `paid + unpaid = total`; an advance refusing to be over-recovered (HR-19); an append-only row refusing deletion; and the subtle one — a **draft** run's item deletes while a **locked** run's does not, and a negative net salary is legal only on a correction item (HR-16, HR-17) |
| [x] | Models: 24, 109 relations, verified against the live schema — no stray cast, no stray fillable, no generated column reachable through mass assignment |
| [x] | Model invariants proved on **22 assertions**: HR-10 (a version refuses every money edit and every delete), D19 (the three append-only tables allow a short list and nothing else), HR-15 / HR-16 (draft editable, locked frozen, paid allows only notes), HR-19 (a disbursed advance freezes its amount), and the group deciding a salary component's side |
| [x] | §4 PermissionRegistry (11 new slugs), §5 the `hr` settings group (43 keys), §4.3 `modules.depends_on` (10 edges), §4.4 role grants. `salary_structures` and `employee_advances` deliberately declare **no `edit` and no `delete`**, so "a rate is never updated" is visible on the role screen rather than buried in a service |
| [x] | §6.1 support classes: `WorkCalendarService` (the single answer to "what kind of day is this?"), `ShiftWindow` (what an attendance row snapshots, HR-2), `EmployeeScopeResolver` (§9's four answers, with a depth cap of 5 on the reporting tree), `PayslipDraft` / `PayslipLine` / `PayrollPeriod` / `PayrollInputs` |
| [x] | §6.2 services, all sixteen: `EmployeeService`, `DepartmentService`, `WorkShiftService`, `HolidayService`, `SalaryComponentService`, `AttendanceService`, `AttendanceCorrectionService`, `AttendanceSummaryService`, `LeaveBalanceService`, `LeaveRequestService`, `SalaryStructureService`, `AdvanceService`, `PayrollCalculator`, `PayrollRunService`, `PayslipService`, `HrNumberService` |
| [x] | §6.3's thirteen resolution steps, §6.4's R1-R5 bridge, §6.5's day expansion and approval chain, §6.6's twelve-step algorithm — the contract's worked example (FT-HR-33) comes out to the paisa: 49,000 / 31 stored as 1,580.65, x 2.5 lost days = 3,951.63, net **38,848.37** |
| [x] | §6.7 lock and §6.8 correction: there is no unlock route anywhere, a locked slip refuses every money edit at the model, and a correction is a new item on a correction run that never touches the original |
| [x] | 16 policies in `app/Policies/Hr/`, six sharing one `CataloguePolicy` trait. Missing ability → 403; holding it but not reaching the row → **404** (`denyAsNotFound`), because the ids being probed here are people's salaries |
| [x] | §7 routes: 86 across the six setup catalogues, employees, attendance, leave, structures, advances, payroll, slips and `/admin/my/*` |
| [x] | §8 screens: 33 Blade views — the daily register, the monthly grid, the correction queue, the summaries, the leave statement, the salary timeline, the payroll run, the printable slip and the whole employee self-service panel |
| [x] | Checked in a browser against the dev database with a complete HR month seeded in: three employees, a public holiday, an approved three-day leave, a 20,000 advance, 31 days of attendance, and a payroll run generated, locked and part-paid |
| [x] | Integration gate `tests/Feature/Hr/HrPayrollTest.php` — **30 tests / 140 assertions**: every screen's permission, the money-withheld rule, 404-not-403, the resolution steps, R1/R2, the ledger identity (HR-7), the worked example, determinism, the lock, the correction, and self-service isolation |
| [ ] | §11 acceptance suite FT-HR-01 … FT-HR-62 (the named cases, beyond the integration gate above) |
| [ ] | §10 events, queued jobs, notifications and the scheduler (`hr:close-attendance-day`, leave accrual, the document-expiry reminder) |
| [ ] | §7.1 employee documents (upload / download / expiry screen), §8.2's 5-step create wizard, §8.21 dashboard widgets, the attendance and employee importers |
### [ ] PHASE 8 — Collaborator management (profiles, panel shell, commission settings)

> **Release note** — note: the financial spine's migration set (spine §1.3, 15 tables) is applied in the same release, immediately after Phase 8's own migrations; the spine-dependent screens stay hidden behind their module switches until then, and `collaborators:backfill-wallets` + `collaborators:seed-initial-rules` run once afterwards (phase-08-09 §1.4 [D-P8-1]).

### [ ] PHASE 9 — Referral codes, referral URLs, referral tracking
### [ ] PHASE 10 — Student referral commission engine (`StudentCommissionService`)
### [ ] PHASE 11 — Project referral commission engine (`ProjectCommissionService`)
### [ ] PHASE 12 — Collaborator wallet, commission ledger, payouts, statements
### [ ] PHASE 13 — Software-house finance: invoices, payments, expenses, income
### [ ] PHASE 14 — Institute: course categories, courses, outline (modules / topics / lectures)
### [ ] PHASE 15 — Inquiries, online admission, admission workflow, registration
### [ ] PHASE 16 — Teachers, batches, timetable, demo classes
### [ ] PHASE 17 — Student attendance + course progress
### [ ] PHASE 18 — Student fees, installments, discounts, scholarships (commission triggers)

> **Release note** — note: consumes Phase 10's `PaymentService` and the four fee tables (`student_fees`, `student_fee_installments`, `student_fee_discounts`, `student_fee_payments`); Phase 18 creates no financial table. It ships `student_fee_reminders`, the fee services and all fee screens.

### [ ] PHASE 19 — Course material + assignments
### [ ] PHASE 20 — Exams + results
### [ ] PHASE 21 — Certificates (public QR verification) + student ID cards
### [ ] PHASE 22 — Tickets, meetings, internal messaging, notifications
### [ ] PHASE 23 — Reports, analytics, activity log, audit trail, global search, exports
### [ ] PHASE 24 — Security, financial integrity, responsive and performance testing
### [ ] PHASE 25 — Deployment preparation (install guide, backups, queue/scheduler, production notes)

---

## 6. Change Log

### 2026-09-20 — Phase 7: the sixteen services, the payroll algorithm, and the HR screens

The half of Phase 7 that decides what a day was and what it costs, plus the screens that show it. Built
directly in the session — no workflow, no background agents.

- **The calendar is asked once.** `WorkCalendarService` is the only answer to "what kind of day is this?",
  and it is bound **scoped**: a fresh instance per resolve gave each service its own holiday cache, and a
  holiday edited mid-request was stale in one of them. The test that flips `is_paid` and re-resolves is
  what caught it.
- **`AttendanceService::resolve()` is a pure function of the row** (§6.3): thirteen steps in order,
  stopping at the first that decides. It refuses a locked row (HR-18) and leaves a manual one alone —
  otherwise the nightly pass would undo every correction at 23:50. The eighteen-hour punch-out guard flags
  the row **outside** the transaction it refuses in; flagging and throwing together rolled the flag back
  with the refusal, and the correction queue never saw the day.
- **Leave days are a ledger with the balance as a cache** (HR-7). Additive columns move with an entry's
  sign and subtractive ones against it, which makes `available_days` exactly `SUM(signed_days)` — one
  identity to check instead of eight. `assertConsistent()` runs after every scenario in the tests.
- **`PayrollCalculator::build()` writes nothing, dispatches nothing and never calls `now()`.** The
  contract's worked example comes out exactly: 49,000 / 31 = 1,580.645161 stored as **1,580.65**, x 2.5
  lost days = **3,951.63**, tax 1,200, advance 5,000 inside a 21,924.19 cap, net **38,848.37**. Every step
  that produces money produces a stored line, so a slip that does not add up is impossible rather than
  unlikely (HR-13).
- **One rule corrected along the way.** `Employee::isPayrollEligibleOn()` asked the status alone, so
  somebody became ineligible the moment HR recorded a resignation and their final month would silently
  never have been generated — the opposite of §6.10 #2. The exit date decides now, and the status only
  decides for somebody still employed.
- **Three bugs the browser found that no probe could.** `EmployeeScopeResolver` applied D11's branch rule
  to every table, and `attendance_corrections` has no `branch_id`, so the correction queue 500'd for any
  user pinned to a branch — the resolver checks the column now, once per table per process.
  `PayrollRun::periodLabel()` read `period_start`, so a screen that selected only the columns it needed
  printed "2026-08" instead of "August 2026". And `SalaryComponentService` filled `side` through `fill()`,
  which silently dropped it, because `side` is deliberately outside `$fillable`.
- **Probed in rolled-back transactions on `my_office_test` before any of it reached a screen**: 323
  assertions across five probes — the calendar and the thirteen resolution steps, the monthly figures and
  the correction trail, grants through carry-forward, the whole payroll lifecycle including the
  negative-net ladder and the rounding line, and the setup services.
- **Then checked in a browser** against `my_office` with a whole month seeded in, which is how the three
  bugs above surfaced.

One deliberate addition to the contract: §7.5 describes only employee-scoped salary-structure routes, so a
sidebar entry had nowhere to point. `admin.salary-structures.index` is a new overview of who is on what,
and the employee-scoped screens live under `admin.employees.salary-structures.*` exactly as §7.5 has them.

Still owed in Phase 7: the named acceptance cases FT-HR-01 … FT-HR-62, §10's events, jobs, notifications
and scheduler, employee documents, the importers and the dashboard widgets.

### 2026-09-20 — Phase 7 foundation: enums, the 24-table HR schema, and the models

Built directly in the session — no workflow, no background agents, at the user's instruction.

- **28 enums / 155 cases.** Two of them belong to the finance spine and are declared here because Phase 7
  migrates first: `LedgerEntryType` and `PaymentMethod`. Two declarations of one `App\Enums` name is a
  merge conflict, not a style question, so every later money phase reuses these.
- **27 migrations / 24 tables**, forward and back, on both databases. Eleven tables carry no `deleted_at`
  (D16, D19): a nullable one on an append-only payroll, ledger or audit table is an invitation — one
  `->delete()` and a slip leaves every total while the money stays paid.
- **One raw-SQL migration for everything the schema builder cannot say**, verified object by object. That
  file is also where the phase's hardest guarantees live: at most one attendance row per employee per day,
  one counted leave day per date, one open salary version per employee, one live regular payroll run per
  branch per month.
- **D68, found by running it.** MariaDB refuses `DATE_FORMAT()` inside a generated column, which the
  contract uses for three of the guards. `CAST(date AS CHAR)` is deterministic, accepted, and produces the
  same string — both forms were run side by side before the change was made. Without a working guard,
  HR-1 and HR-8 would have been service conventions rather than database facts.
- **24 models / 109 relations**, each checked against the live schema for a stray cast, a stray fillable or
  a generated column left mass-assignable.
- **Two real bugs the model probe caught, one root cause.** `getOriginal()` applies the model's casts, so a
  status column comes back as an enum and never equals the string it is compared to: the advance guard was
  passing silently on every advance, and the payroll guard was treating every draft slip as locked. Both
  read `getRawOriginal()` now.
- **One test was too narrow, not wrong.** `SchemaTest` asserts every migration's `down()` reverses
  something, but its pattern only recognised schema-builder calls. Phase 7's step-18 migration is the first
  whose `up()` is raw SQL and whose `down()` therefore drops raw objects; the check now recognises
  `DB::statement()` and `DB::unprepared()` too.
- **Suite 1,545 tests / 61,499 assertions green.**

Still to come in Phase 7: the registries, the ten services, routes, screens and the FT-HR acceptance suite.

### 2026-09-20 — Phase 6: registries, services and the admin delivery screens

The delivery side is usable end to end. Built directly in the session — no workflow, no background
agents, at the user's instruction.

- **Registries grew without losing anything.** The new `task_comments` slug plus the abilities §4.2 asks
  for on four existing slugs (88 modules / 879 permissions), and the `projects` settings group with its
  17 keys (16 groups / 209 keys). Extras Phase 1 had already seeded were **kept**: removing an ability
  from the registry would revoke a permission somebody holds.
- **Ten services, 38 events, nine policies, 48 routes, 13 screens.**
- **`Money` gained `weightedAverage()` and `clamp()`,** additively. §6.3 averages at 4 decimals and
  `div()` answers at money scale, so without them the arithmetic would have had to leave the class.
- **The §6.3 progress algorithm was proved before anything was built on it** — 16 cases, including the
  two the contract deliberately argues about and INV-P9's cancelled-row exclusion.
- **Three real bugs the service probe caught**, each fixed at the cause: a STORED generated column is
  absent from the model after its own INSERT, so a revision came back with an empty `delta_amount`;
  `DATETIME` stores whole seconds while `now()` carries microseconds, so a start and a pause inside one
  second looked ordered in PHP and identical to `chk_tes_window`; and Carbon 3 returns a float from
  `diffInSeconds()`.
- **One design fix.** `update()` used to drop `project_value` silently, because the data object never
  carried it — a 200 with no change, which is the quiet no-op that hides a bug for months. It now refuses
  and names the service that owns the column.
- **The browser check earned its place.** It caught what no unit probe could: Laravel 12's base
  controller has no `authorize()`, so every screen 500'd on the first request. It also showed the Phase 1
  sidebar placeholders pointing at route names this phase did not use.
- **Two sidebar tests went red for the right reason** and one of them was hiding a gap: the URL walk only
  ever looked at top-level items, and Phase 6 shipped the first **nested** entry. Fixing it to descend
  took that test from 46 assertions to 102.
- **Suite 1,545 tests / 61,368 assertions green.**

Still to come in Phase 6: the collaborator and client panel contributions, the remaining five services,
§10's notifications / jobs / scheduler, and the P6-01 … P6-55 acceptance suite.

### 2026-09-19 — Phase 6 foundation: enums, schema, models

The first slice of Phase 6 (projects, milestones, tasks, time tracking). Built directly in the session —
no workflow, no background agents, at the user's instruction.

- **14 enums / 54 cases** (§3), including `CommissionCalculationType`, which Phase 6 declares on the
  finance spine's behalf (F-5.5) because `projects.commission_type` casts to it and Phase 6 migrates
  first. Verified by exercising every `label()` / `color()` / weight arm — a missing `match` arm is an
  `UnhandledMatchError` at runtime, not a lint error — and by proving the four transition tables are
  closed over their own enum and list no self-transition.
- **11 migrations / 11 tables**, applied to `my_office_test` and then `my_office`; `rollback --step=11`
  leaves zero Phase 6 tables and re-migrating is clean.
- **The §2.14 object list matched against `information_schema`, not read by eye**: 13 STORED generated
  columns, 27 CHECK constraints, 7 named unique indexes, 1 trigger — the exact counts the contract names.
- **Then every guard was made to bite** — 29 database assertions inside a rolled-back transaction.
  `net_value` refuses a write, `chk_projects_commission` refuses a half-configured override,
  `trg_pvr_no_delete` refuses a DELETE, `uq_pm_user_active` frees its slot on soft delete and keeps the
  history row, `chk_tasks_depth` refuses a parentless subtask *and* a depth-2 one, `uq_te_running` and
  `uq_tes_open` each refuse a second live timer, and a segment generates 0 seconds while open and 1500
  when closed.
- **A real hazard caught before it was written** (D67): this server runs
  `explicit_defaults_for_timestamp = OFF`, where the first `TIMESTAMP NOT NULL` column in a table silently
  acquires `ON UPDATE CURRENT_TIMESTAMP`. On `time_entry_segments.started_at` that would have rewritten
  the clock record on any UPDATE and moved `duration_seconds` underneath every SUM already taken — INV-P5
  broken silently, with nothing to see in the migration. Probed on the test database first; both clock
  tables use `DATETIME`, and a check now asserts that no Phase 6 column carries the attribute.
- **11 models** with enum casts, the §2.12 relation map and the six-alias morph map, plus
  `GuardsServiceOwnedColumns`: INV-P1, INV-P8 and INV-P13 are model hooks that name the one service
  allowed to write each column group, and that service brackets its own write with `unlock()`. 26
  model-level assertions green, including the contract's `ImmutableRevisionException` and the append-only
  segment rules.
- **One contract gap recorded rather than silently patched** (D66): §2.13.2 gives a milestone no way out
  of `on_hold` except `cancelled`. The project lifecycle has exactly that missing row, so the enum adds it
  and the log says why.
- **Suite 1,529 tests / 60,437 assertions green**, 938 s — the same tests as before Phase 6, 55 more
  assertions, no regressions. Phase 5's hand-off now resolves: `Client::projects()` and
  `LeadConversion::project()` reach a real model instead of throwing.

Still to come in Phase 6: the registry additions, the eleven services, routes, screens, events and the
P6-01 … P6-55 acceptance suite.

### 2026-09-19 — Phase 5 integrated and committed

Phase 5 (CRM: leads with a Kanban board, follow-ups, imports, conversions, clients, client documents and
the client panel) was built in an earlier session and integrated here against the live tree:

- **275 new files / 17 modified.** 10 migrations / 9 tables already applied to `my_office`, 0 pending.
  13 enums, 23 services, 11 policies, 6 scheduled commands, 4 queued jobs, 11 notifications, 20 events,
  8 listeners, 5 contracts, 66 `admin.*` CRM routes and 24 `client.*` routes.
- **Registries grew, they did not change shape.** 87 modules / 865 permissions (the six new
  `client_portal` abilities were *appended*, per D4 append-never-rename); a 15th settings group `crm`
  with 34 keys; `Modules::MODEL_MODULES` gained the six CRM models.
- **D31 and D62 verified on the live route table and registry, not by eye**: all 24 `client.*` routes
  carry `client.context`, 409 routes with no duplicate name, and both `*_next_number` counters are
  readonly. D27's `DocumentNumberService` landed one commit early (in Phase 4's tree) but Phase 5 is
  still its first consumer — `lead_no` and `client_code` both come from it.
- **Ten suite failures after integration, every one a legitimate consequence**, fixed at the right end:
  two tests pinned "fourteen settings groups" (now fifteen); two inquiry-routing tests asserted that
  *no* inquiry target is registered, which stopped being true the moment Phase 5 registered
  `CrmLeadInquiryTarget` — the waiting-behaviour test moved to the still-unshipped `course_inquiry`
  target (Phase 15); three 403s on `/client` were fixed by giving the demo Client login a real `clients`
  row in `DemoUserSeeder` **rather than loosening the panel guards**; and the sidebar tests were updated
  for the new Leads / Clients entries and the 13-entry client tree, with the leads sidebar permission
  aligned to the `leads.view` its route actually checks.
- **Integration gate**: `tests/Feature/Crm/CrmSmokeTest.php`, 6 tests. Its client-isolation case now
  asserts on the profile screen — the client dashboard answers but does not print the company name, so
  the original assertion was testing a screen that never claimed to show it.
- **Suite 1,529 tests / 60,382 assertions green**, 927 s.

Still owed: Phase 5's full acceptance suite (phase-05 §11); the smoke test is the integration gate, not
the acceptance run. The C.7 contract deviations and W.5 notes in `docs-pending/phase-05-integration.md`
are carried forward unchanged.

### 2026-09-19 — Phase 4 finished and committed

Phase 4 (services, portfolio, team, testimonials, student reviews, success stories, blog, careers,
contact inquiries) had been built and migrated in an earlier session but never verified. Finished here:

- **1,522 tests / 58,814 assertions green.** The five failures were all in one place — Phase 4 had never
  written its rows into the four manifests decision **D60** requires, so `MarketingManifestTest` could not
  match a single route.
- Generated those rows from the live route table and `information_schema` rather than by hand, so they
  cannot drift from what the app really registers: **168 route-guard rows**, **76 screen rows**,
  **20 index-manifest tables** (plus a single-column row for every foreign key and every deferred id) and
  **23 upload rows** (22 website images through `MediaService`, one private CV on the private disk, D21).
- 22 screen rows declare the status their route really answers: taxonomy `create`/`edit` and the `show`
  routes redirect by design (terms are edited inline on the index screen), and `site.blog.preview` is 404
  without a signed link.
- Fixed one real test bug: it read `portfolio_item_media` ordered by `id`, but that pivot is a history
  pivot with no `id` (phase-04 §2.8, D19) — it now reads in gallery order.

### 2026-09-14 — Phase 3 review round 2 fixed, verified and committed

- **High — the four D60 manifests and the SEC-05 raw-output allowlist did not exist** (build-order E4 / B9, the
  Phase 3 definition of done). `tests/Support/screen-manifest.php`, `route-guard-manifest.php`,
  `upload-manifest.php`, `index-manifest.php` and `raw-output-allowlist.php` now exist in the phase-24-25 §13.2
  row shapes, each under a Phase 3 banner: 85 route-guard rows (a written rationale on every route without a
  `can:`), 35 screen rows with params closures, the one media upload field, every Keys-block and FK index of the
  14 tables, and the two raw echoes (`components/site/prose`, `admin/cms/faqs/index`) with
  `RichText::sanitize()` as the only sanitiser. **Rule for phases 4-23:** append your own rows under your own
  banner as part of your definition of done; never edit another phase's. Until Phase 24's `audit:manifest`
  ships, `Cms/Http/CmsManifestTest` is the drift check for Phase 3's rows.
- **Low fixes** (each with a regression test):
  - §6.5 / §6.7 Host poisoning: `App\Support\Cms\PublicOrigin` builds every cached absolute URL from
    `seo.canonical_base_url`, else `config('app.url')` — never the request `Host`; `SeoService::baseUrl()` uses
    it. `bootstrap/app.php` enables `trustHosts()` (application URL, its subdomains, the canonical host) —
    active outside `local` and the test runner only.
  - §6.7 unbounded page cache: `CachePublicResponse` reads and writes only for the site's own host, keys `page` /
    `category` only on a route that declares them (`site.cache:page,category`), tightens the `page` and `ref`
    patterns, stores at most 500 query-string variants per cache version, and the new hourly `cms:cache-prune`
    deletes expired rows of the database cache store.
  - INV-12 / D26: `PublicCache::moduleChanged()` listens to `ModuleStateChanged` and bumps the version stamp once
    per moved module, after commit.
  - build-order F2: an `auto` statistic whose live count is zero falls back to its manual value, else renders
    nothing (`resolve()` still reports the true count).
  - [D-W3-10]: the menu item `PUT` needs `menus.change_status` when it actually switches `is_enabled` (the
    editor no longer posts the switch for a user without it); a **published** section's anchor change needs
    `website_sections.change_status` (`WebsiteSectionPolicy::changeAnchor`, read-only field with the reason).
  - `admin.website.media.regenerate` is throttled `6,1` (derivatives still run inline, T35).
  - `<x-site.image :asset="$asset" profile="card">` accepts a `MediaAsset` (phase-04 §6.6 signature) through
    `MediaService::toSnapshot()`.
  - §13.4 "an honest setting beats a lying one": `website.cache_warm_enabled` and `website.revision_keep` are
    readonly with a "Not active yet" help until their jobs ship (T36).
  - §8.14: the footer renders `contact.map_embed` only through `RichText::sanitize()`'s iframe allowlist.
  - §6.5: a model with no public path of its own is canonical at the rendered path on the configured base URL.
  - `resources/views/layouts/site.blade.php` exists as the contract-name alias of `site.layouts.public`.
  - INV-10 / FT-42: the `rescue()` wrappers around `site_setting()` are gone from 12 site views. **Behaviour
    change:** a key that stops being public now fails the layout render (and the 503 holding page) loudly, and
    takes a section off the page with a logged warning, instead of printing its default.
- **Not fixed, recorded as known issues**: SEO manager and export paginate in PHP (T34); queued derivative
  regeneration (T35); deferred warm-up and revision-pruning jobs (T36). New rule recorded as T37: every foreign
  key into `media_assets` must be read by `MediaService`.
- **Tests added**: `Cms/Http/CmsManifestTest` (6), `Cms/Behaviour/PublicCacheBoundsTest` (7),
  `PublishRightSplitTest` (2), `SiteRenderingContractTest` (4), `DeferredFeatureSettingsTest` (1),
  `MediaUsageSourcesTest` (2), fixture `Fixtures/ModuleGatedSectionProvider`. **Tests changed, all strengthened**:
  `GatesAndAuditTest` (+ FT-42 loud-failure test), `StatisticsTest` (+ zero-count fallback),
  `CmsRouteContractTest` (media regenerate added to the throttled-route rows). None loosened.
- **Dev database** `my_office`: backed up (`mysqldump`), forward `migrate` had nothing to run; Module, Permission,
  Role, Setting and WebsiteCms seeders re-run: 0 created, 0 grants added, none revoked; setting values (178),
  module `is_enabled` (82) and role grants (2,520) identical before and after (D65). The only table difference is
  the expected metadata refresh of `website.cache_warm_enabled` and `website.revision_keep` (`is_readonly` 0 → 1
  and the new help text).

### 2026-09-13 — Phase 3 review round 1 fixed, verified and committed

- **Critical — live section providers never ran** ([D-W3-11]). `SnapshotBuilder` skipped `is_live => true`
  providers and nothing resolved them at render time, so every later-phase feed (services, blog, testimonials,
  team, courses) would have shown its empty state. `ComposesSite::usableSection()` now calls the provider on
  every render, for the published page and the draft preview, through the new
  `App\Contracts\Cms\SectionDataProvider` interface; a provider that throws is reported and the section renders
  its empty state. A live provider must cache itself under the version stamp.
- **High — unpublished, archived or trashed CTA blocks and FAQs kept rendering** (§2.15, §9). **Behaviour
  change:** CTA and FAQ content is now resolved when the page renders — one cached lookup under the version stamp
  that every CTA / FAQ write bumps. The published snapshot keeps only the *choice*: the new `cta_ref` key (id +
  key), the FAQ source and category, and the selected question ids. A draft, archived or trashed block or
  question leaves every page at once, a disabled FAQ category takes its questions with it, and an edit appears
  without re-publishing the section. The cold seeded home page sits at exactly the FT-27 budget of 8 queries.
- **Medium fixes**:
  - [D-W3-10] **Behaviour change:** `pages.edit` without `pages.change_status` gets a 422 on a live page's
    title, layout, excerpt, banner and template, a 403 on its slug (a system page keeps its existing 422), and a
    403 on any edit to a scheduled page. Enforced in `PageService::saveDraft()`, `PagePolicy`
    (`changeLiveAttributes`) and the editor form (lock notice, read-only live fields); the body is still saved
    as a draft.
  - INV-8: `SettingsService` fires the new `App\Events\SettingsChanged` (keys only, never values) once per
    save; `PublicCache::settingsChanged()` bumps the page cache when a group the site shows changed.
  - §6.5: SEO `route:` targets and the sitemap accept only public, parameterless `site.*` GET routes
    (`SeoService::isPublicRouteKey()`), so no admin, auth or preview URL can reach the anonymous sitemap.
  - §7.6 / §9: preview and the maintenance bypass need an active account with no password change owed
    (`EnsureUserIsActive::permits()`, side-effect free); the preview routes load the row only after the
    signature or permission passed, so a missing id answers exactly like an existing one.
- **Low fixes**: `/sitemap-{n}.xml` for a chunk that cannot exist is answered from a cached chunk count and
  never rebuilds the URL set; re-uploading the bytes of a trashed media asset needs `website_media.restore`
  and filling its empty descriptions needs `website_media.edit`; the menu link check reads section anchors in
  one query; `cms:verify-published-snapshots` also checks every published media file exists on disk and writes
  `Log::critical` when it fails.
- **Contract names now in code** (the review found them missing): `App\Support\SitemapRegistry`
  (`register()` requires a `SitemapUrlProvider`; `pages` / `static` keys are reserved),
  `App\Contracts\Cms\SitemapUrlProvider`, `App\Services\Cms\PublicCache` (a front over `CacheVersion`).
  Remaining contract-name → code map: `WebsiteSectionRegistry` = `App\Support\Cms\SectionRegistry`,
  `WebsiteSectionService` = `SectionService`, `SitemapService` = `SitemapGenerator`, `MenuService::tree()` =
  `SnapshotBuilder::menuTree` + `MenuService::resolveUrl`, `PublicPageService` / `SitePayload` =
  `ComposesSite` / `RendersPages` and the `$site` object, `x-cms.*` components = `admin/cms/partials/*`; no
  `BumpPublicCacheVersion` listener exists (services bump `CacheVersion` themselves).
- **Two edits outside the CMS folders**: `SettingsService` dispatches `SettingsChanged`; `EnsureUserIsActive`
  gains the static `permits()`.
- **For the Phase 4 integrator**: phase-04-integration §4.7 (live providers in `ComposesSite`) is already done
  — skip it. Live providers read their options from `published_content['fields']`, not the top level that
  `MarketingSectionProvider::options()` reads.
- **Tests added**: `Cms/Behaviour/LiveReferencesAndProvidersTest` (4), `PageLiveColumnsTest` (2),
  `PublicSiteHardeningTest` (9), with two fixture providers under `Cms/Behaviour/Fixtures`. **Test changed**:
  `PageLifecycleTest::test_deleting_a_referenced_entity_cannot_orphan_json` (FT-12) now also asserts the hard-deleted
  CTA heading is absent from the public page — strengthened, nothing loosened.
- **Dev database** `my_office`: backed up (`mysqldump`), forward `migrate` had nothing to run; Module,
  Permission, Role, Setting and WebsiteCms seeders re-run with 0 created / 0 updated / 0 grants added; settings
  (178 rows), module `is_enabled` (82) and role grants (2,520) byte-identical before and after (D65).

### 2026-09-13 — Phase 3 (public website CMS) verified and committed

- **Integrated** per `docs-pending/phase-03-integration.md`: registries (C, D, E.1–E.4), policies and bindings
  (E.3), middleware aliases (E.5–E.6), routes (F), sidebar (G), scheduler (H), seeders (I), test updates (J) and
  reconciliation fixes (K-1 … K-8, K-10). The step A gate passed (A.1 OK, A.2 OK); A.3's file counts differ only
  because unit 04's untracked files share the same folders.
- **Applied by the verify pass** (the integration had left them open): E.7 — `EnsurePublicSiteAvailable` now
  answers `site.holding` when the site is switched off, puts `X-Robots-Tag: noindex` on both 503s and lets a
  holder of `website_sections.view` browse the closed site with the ribbon; `resources/data/icons.php`; F.3 —
  the skeleton `public/robots.txt` deleted; B.3 `storage:link`; B.4 `npm run build`.
- **Fixed during verification** (each found by a failing acceptance test, none by loosening one):
  - FT-32: `App\Notifications\Cms\ScheduledPagePublished` did not exist. `ContentPublisher::publishDue()` now
    notifies the page's author and every active holder of `pages.change_status` after the promotion commits;
    database channel once Phase 22's `notifications` table exists, mail until then; a failure is reported and
    never undoes a promotion.
  - FT-43: every CMS audit row carrying a subject was filed under the **subject model's** module, not the act's
    (an SEO change on a page landed under `pages`), because spatie re-applies the subject's `tapActivity()` last
    (Known Issue T5). `CmsAuditor` now associates the subject by key only, so the module and reason of the act
    survive.
  - FT-27: a cold anonymous home page issued 11 queries against a contract ceiling of 8 — five
    `information_schema` probes from `StatisticsProvider` and separate header and footer reads. The provider
    now makes one schema probe and one `UNION ALL` count (with per-metric fallback if the union fails), and the
    header and footer load in one query: a bounded budget however many later phases install their tables.
  - FT-14: `sitemap.xml` / `robots.txt` as a page slug were refused as "format is invalid" instead of by name
    as reserved; the reserved/duplicate/trashed check now runs before the slug pattern.
  - FT-51: a rich-text `<table>` did not scroll inside its own container on a phone; `<x-site.prose>` now wraps
    each sanitised table in `overflow-x-auto`.
- **Tests added**: `Cms/Behaviour/RichTextProfilesTest` (FT-36b) and `Cms/Install/InstallAndRollbackTest`
  (FT-50: a real `migrate:fresh --seed`, reverse rollback of every Phase 3+ migration and re-migrate in a child
  process against a scratch schema created and dropped by the test, after proving the child is connected to it).
  Existing tests changed only as integration step J prescribes: `MaintenanceModeTest` (robots.txt exempt from the
  "every public GET is gated" scan), `SettingsRegistryTest` / `SettingsFormRoundTripTest` (fourteen groups),
  `SidebarVisibilityTest` (nine Website labels), `PermissionRegistryTest` (three new Website slugs). None loosened.
- **Dev database** `my_office`: backed up first (`mysqldump`), then ModuleSeeder (+3 modules), PermissionSeeder
  (+24), RoleSeeder (+77 grants: Super Admin 24, Admin 24, Digital Marketer 17, SEO Expert 12; **0 revoked**),
  SettingSeeder (+17 rows), WebsiteCmsSeeder; `cms:verify-published-snapshots` clean. A before/after SQL diff
  proved no setting value, module `is_enabled` or role grant changed or disappeared (D65).
- **Deviations kept on purpose** (integration M-30, B.2, B.5): `Admin\Cms` / `admin/cms` naming,
  `site.layouts.public`, `SectionRegistry`, `CacheVersion`, `SitemapGenerator`; `intervention/image` not installed
  (nothing calls it — `MediaService` uses GD); Trix and sortablejs not installed (textarea + native drag).

### 2026-09-13 — Phase 2 closed; automated build of phases 3-25 launched

- Phase 2 close-out committed (`39c49d3`) after verification on a Phase-2-only tree. The final review's two
  blockers were resolved: the suite's six failures were caused solely by unintegrated Phase 3 files (proved by
  stashing them), and every settings security fix now has behaviour tests.
- Found and fixed on the way: MariaDB was converting UTC strings as Asia/Karachi on every TIMESTAMP (session
  zone SYSTEM) — pinned to +00:00 with a byte-identical re-base; a polyglot image (valid header + PHP) passed
  every upload rule and was stored verbatim — avatars are now re-encoded through GD (`ImageSanitizer`);
  XAMPP's Apache would serve `.env` from the project root — a root `.htaccess` denies it.
- Phase 3 domain code committed as WIP (`f85bd2c`).
- Launched one orchestration for units 03 → 25 in `docs/design/build-order.md` slice order: parallel domain
  code per unit on new paths (migrations staged outside `database/migrations` so the suite never sees an
  unintegrated table), then serially per unit integration → acceptance tests → verify (suite twice, HTTP
  smoke) → **commit** → adversarial security + contract review → fix rounds. A unit that cannot go green stops
  every unit that depends on it.

### 2026-09-13 — Phase 2 finishing pass, and Phase 3 started in parallel

**Where Phase 2 stood** after the finishing workflow (a network outage had killed the original verification,
test and review agents): the split-settings-truth defect was repaired — views now read the canonical registry
keys, the three homeless company keys were adopted into the registry, and one data migration copied 20
relocated values forward and marked the legacy rows read-only instead of deleting them. Verification then
proved **631 tests / 18,789 assertions green** in both orders, 67 routes, all 52 smoke checks through the real
HTTP kernel as Super Admin and plain Admin, a dashboard of 9–13 queries with all ten widgets, and zero data rows
changed across disabling and re-enabling nine modules.

**What the two re-reviews found**, and why Phase 2 is not yet committed:
- *Contract audit* — 2 critical, 4 high. Critical: mixed UTC / local timestamps (now **D61**) and editable
  document counters that a stale form save could roll back (now **D62**). High: the Security group could never be
  saved (a read-only toggle still posted a hidden value); 21 Phase 1 settings rows left un-superseded; money and
  rate settings accepting values `Money` cannot parse (`1e3`, a 250 % rate); most §6 acceptance rows untested.
- *Security review* — **no critical or high, no working privilege escalation.** Four mediums: the Security group
  saved but enforced nowhere; `config:cache` serialising the decrypted SMTP password into
  `bootstrap/cache/config.php`; decimal settings breaking bcmath; the timezone skew above.
- Beyond the contract: the build added a `settings.edit_mail` permission (788 permissions) so the Admin role cannot
  edit SMTP — delivering the carve-out Phase 1 §5 promised but never shipped.
- Process note: the Phase 2 verify agent's forward `migrate` also applied Phase 3's ten CMS migrations to
  `my_office` (batch 4, all tables empty) before Phase 3 was verified. Harmless because additive and empty, but
  recorded.

**Running now** (one workflow, two parallel chains): Phase 2 — five partitioned fix agents (D61, D62, D63,
settings integrity, security-group enforcement, module/dashboard findings, a view-formatting sweep) → complete
the acceptance suite → verify → adversarial re-review; Phase 3 — models + policies, controllers + requests + the
D26 middleware, public site views, admin CMS views, `INSTALL.md` / `README.md` on new paths only → one
integration patch list.


### 2026-09-12 — Phase 1 security remediation (commit `a22b7d9`)

Both HIGH findings closed at the root and each enforced twice (Form Request **and** service), because
`Gate::before` waves a Super Admin past any policy and later phases will call the service from new places:

- **Status** — `status` / `status_reason` removed from `UpdateUserRequest` and from `UserService::PROFILE_FIELDS`;
  a submitted status that differs from the row is a 422, and the service throws on the same payload.
  `changeStatus()` — which demands `users.change_status`, a written reason, and an actor who is not the target —
  is now the only writer, and the edit form renders a read-only badge instead of a select.
- **Roles** — `UserPolicy::assignRoles()` (previously dead code) is the single enforcement point: it requires
  `users.assign`, requires the actor to outrank the **target**, and delegates the role half to
  `RolePolicy::assign()`. A self-grant is rejected in plain PHP before the Gate is consulted, so a Super Admin
  cannot slip through, and the form offers no role inputs on your own edit screen.
- **A defect nobody filed** made the whole status story moot: both status controls posted `@method('PUT')` to a
  route registered as `Route::patch()`, so the only legitimate way to change a status answered **405** — the
  update-form back door was the only thing that worked.

Medium fixes: admin-initiated password writes funnel through one `setPassword()` that revokes sessions and
rotates the remember token (`UserService` now contains no `$user->password =` assignment at all);
`AuthenticateSession` registered in the web group so `Auth::logoutOtherDevices()` actually works; the four
`*_portal` modules are now **core** (one toggle could otherwise close an entire panel — the collaborator sidebar
items were re-keyed to the real `collaborators` business module so module-gating coverage was kept); the user
detail screen no longer runs its login-history or audit queries without the matching `view_logs` ability; role
membership respects the same visibility rule as the users screen; hostile array query parameters no longer 500.

One bug the remediation itself introduced was caught and fixed at the root: with `AuthenticateSession`
registered, a second `actingAs()` in one test was signed out, because PHPUnit shares one session Store per test
and the first actor's `password_hash_web` survived. Fixed in `tests/TestCase::actingAs()` (forgetting the stale
stamp, exactly as a real login does) rather than by patching the single test that tripped over it — five or six
other files act as two users and would have broken later.

Suite: **478 → 587 tests**, 17,727 → 18,334 assertions, green twice (sequential and random order). Six tests
that asserted the pre-fix behaviour were updated to assert the new, stricter rule; none was loosened.

### 2026-09-12 — Phase 1 built, verified, reviewed

**Built** (16 agents, 5 stages, file-ownership partitioned, ~3.7 h wall clock):

- 7 Phase-1 migrations on top of 4 vendor migrations; every `down()` drops FKs in their own `ALTER` before
  the columns that back them (MariaDB requirement), FK names resolved at runtime rather than hardcoded.
- `permissions.module` / `permissions.ability` are **NOT NULL with no default** — deliberately fail loudly,
  because a silently empty `module` would break module gating. Any code creating a permission must supply both.
- 6 enums + `HasOptions`; 8 models + 3 concerns; `PermissionRegistry` (79 modules / 787 permissions),
  `Modules`, `SettingsRepository`, `Money` (bcmath, string-only API), `Device`, `Sidebar`, helpers.
- `Gate::before` order: module-disabled denial → Super Admin bypass → spatie. Three custom middleware.
  Policies for User, Role, Module with rank (`roles.level`) comparisons.
- Breeze auth hardened: registration removed, status checked after a successful attempt, three login
  listeners writing `login_histories` + activity, redirect to `primaryPanel()->homeRoute()`, account area
  (profile, avatar, password, theme, sessions, own login history).
- Admin shell: sidebar from `Sidebar::forUser()`, topbar, 3-way theme switcher applied before paint,
  ~35 `x-ui` components, 60+ inline heroicons, light + dark throughout.
- Admin CRUD for Users / Roles (matrix editor) / Permissions / Modules, dashboard, both log viewers with
  streamed CSV export, plus four panel dashboards.

**Seeded state**: 1 branch · 79 modules (10 core) · 787 permissions · 18 roles · 95 settings · 18 users.
Per-role grants: Super Admin 787, Admin 778, Institute Manager 233, Accountant 102, Project Manager 74,
HR 64, Course Coordinator 64, Digital Marketer 50, Sales Executive 48, Receptionist 43, SEO Expert 39,
Support Agent 34, Designer 25, Developer 25, Student 22, Teacher 21, Collaborator 17, Client 16.

**Verified**: `composer dump-autoload`, `optimize:clear`, `migrate:fresh --seed`, `route:list` and
`npm run build` all passed **first try** — the 13 parallel streams composed without contract drift.
All 25 admin routes carry `web, auth, active, panel:admin` plus an exact per-route `can:`.

**Fixed during verification**: (1) `EnsureUserIsActive` used `Str::beforeLast('account.password','.').'.*'`
which resolved to `account.*`, so a user with `must_change_password` could still reach profile/sessions —
now pinned to the change-password screen only; (2) `Money::format()` read currency from `finance.*`/
`company.*`/`general.*` but `SettingSeeder` stores them under `localization.*`, so every admin-editable
currency setting was silently ignored (masked because the fallbacks matched the seeded values);
(3) dead Breeze scaffolding the contract replaced was deleted (`layouts/navigation`, `layouts/app`,
`AppLayout`, `dashboard.blade.php`, `profile/**`, `ProfileController`, `ProfileUpdateRequest`), and
five stale Breeze tests that asserted pre-contract behaviour were repaired or removed.

**Reviewed** (two independent read-only auditors): 14 security findings (2 high, 6 medium, 6 low) and
14 contract findings (1 critical, 2 medium, 11 low). The "critical" one — acceptance suite missing — was
filed in parallel with the agent that wrote the 478-test suite, so it is stale; being confirmed.
The two HIGH findings are both real and are being fixed now:

1. `PUT /admin/users/{user}` accepted `status` and `roles`, so a role holding `users.edit` but **not**
   `users.change_status` could suspend anyone, and a user could change their **own** status — bypassing
   the dedicated endpoint, its mandatory reason and the anti-lockout guard.
2. A user could **grant themselves roles**: the only check compared the *role's* level to the actor's,
   never the target user. `UserPolicy::assignRoles()`, which encodes the correct rule, was dead code.

### 2026-09-12 — Phases 3–25 designed, then audited against each other

- Wrote [`docs/requirements.md`](docs/requirements.md) — the client's full 120-section specification as the
  requirement source of truth, so every contract traces back to a numbered section.
- **Financial commission spine** ([`docs/design/finance-commission-spine.md`](docs/design/finance-commission-spine.md),
  2,296 lines): three architects designed the fee/payment/commission schema independently (accounting purist,
  transaction-safety engineer, operations/reporting) and the strongest was synthesised with the best ideas of
  the others grafted in. 15 tables, 4 module slugs, 24 settings keys, 30 enums, **26 numbered invariants**,
  51 concrete tests, 12 labelled decisions. Key resolutions: money stored as a positive magnitude plus
  `entry_type` plus a STORED generated `signed_amount` (one column to sum); a composite **NOT NULL** unique
  index as the duplicate guard (the requirement's literal column list would have been nullable, and MariaDB
  does not enforce uniqueness across NULLs — every project commission could have duplicated); fixed amounts
  and document-level bases released by a cumulative-target pro-rata formula that sums to the promise exactly
  (2,000 over three installments = 666.67 + 666.66 + 666.67) and never pays ahead of collection; partial
  reversal by cumulative target with `reversed_amount` / `clawed_back_amount` ceilings enforced as DB CHECKs;
  payouts allocate named ledger entries with partial slices via compare-and-swap (whole-entry allocation was
  rejected — it cannot pay 20,000 out of a 50,000 entry); a paid commission is never un-paid, so a
  post-payout refund posts a clawback debit that legitimately drives the wallet negative.
- Twelve phase contracts written (phases 3–25, ~21,000 lines total) covering every requirement section.
- **Cross-contract audit** ([`docs/design/consistency-audit.md`](docs/design/consistency-audit.md)): 98
  findings — **7 critical, 32 high**, 41 medium, 18 low. Caught before a single line of phase-3 code existed:
  `contact_inquiries` claimed by three owners; a client-visibility scope built on `tasks.is_client_visible`,
  a column no contract defines; `DocumentNumberService` claimed by three phases; `ContentStatus` and
  `EmploymentType` each declared twice with different cases; and nine contracts claiming overlapping decision
  numbers (D16+), which would have blocked the first migration.
- Convergence workflow running: one canonical resolution per finding → applied to the contracts they touch →
  master data model (`docs/data-model/`) → re-audit → `docs/design/build-order.md`.
- Two agents failed in the design run and were handled: the reporting architect lost its connection (the
  synthesiser designed that layer itself from the requirement), and the auditor hit the 64k output-token cap
  after writing its findings file — only its data-model half was lost, now being written split by domain.

### 2026-09-12 — Phase 2 design

- Wrote [`docs/phases/phase-02.md`](docs/phases/phase-02.md): three additive migrations, the
  `SettingsRegistry` pattern (settings *definitions* in code, *values* in the DB — mirroring
  `PermissionRegistry`), `SettingsService` with per-key audit and encrypted-field masking,
  `ConfigureFromSettings` runtime override for mail/timezone/locale/brand colour, module dependency +
  impact + cascade rules with a data-safety guarantee, a pluggable `DashboardRegistry` so later phases
  add cards without editing the controller, and 14 acceptance tests.
- Build deliberately **not** started in parallel with Phase 1: both phases own
  `Admin/DashboardController`, `Admin/ModuleController`, `routes/admin.php` and `tailwind.config.js`.

### 2026-09-12 — Phase 1 build launched

- 16-agent contract-driven build running in the background across five stages (Foundation → Core →
  Features → Verify → Harden), partitioned by file ownership so no two agents write the same file.

### 2026-09-12 — Phase 0 bootstrap

- `composer create-project laravel/laravel .` gave Laravel **12.69.2**.
- Created MariaDB database `my_office` (utf8mb4_unicode_ci); deleted the default `database/database.sqlite`.
- `.env` / `.env.example`: `DB_CONNECTION=mysql`, `DB_DATABASE=my_office`, `APP_NAME="MyOffice ERP"`,
  `APP_TIMEZONE=Asia/Karachi`, `APP_URL=http://localhost:8000`, `FILESYSTEM_DISK=public`.
- Installed `spatie/laravel-permission ^6.25`, `spatie/laravel-activitylog ^4.12`, `laravel/breeze ^2.4` (dev).
- `php artisan breeze:install blade --dark` created the auth controllers and views, Tailwind 3.4.19 + Alpine, Vite build passed.
- Wrote `DEVELOPMENT_LOG.md`, `CLAUDE.md`, `docs/phases/phase-01.md`.

---

## 7. Test Results

| Date | What was tested | Command / method | Result |
|---|---|---|---|
| 2026-09-12 | PHP / Composer / MariaDB / Node availability | `php -v`, `composer --version`, `mysql -e "SELECT VERSION()"`, `node -v` | PASS — all present, MariaDB reachable |
| 2026-09-12 | Front-end build | `npm run build` (during breeze install) | PASS — 59 modules, CSS 38.8 kB, JS 106.7 kB |
| 2026-09-12 | Phase 1 install | `migrate:fresh --seed` | PASS first try — 14 migrations; 1 branch, 79 modules, 787 permissions, 18 roles, 95 settings, 18 users |
| 2026-09-12 | Migration reversibility | `migrate:rollback --step=7` then `migrate` | PASS — created tables gone, extended tables back to pre-Phase-1 columns, re-migrate clean |
| 2026-09-12 | Route integrity | `php artisan route:list` | PASS — 25 admin routes, each with `auth, active, panel:admin` + exact `can:`; 4 panel routes with `panel:<type>` + portal permission |
| 2026-09-12 | Smoke suite | `php artisan test --filter=SmokeTest` | PASS — 36 tests / 128 assertions, green on both SQLite and MariaDB |
| 2026-09-12 | Acceptance suite | `php artisan test` on `my_office_test` | PASS — **478 tests / 17,727 assertions**, ~69 s, stable under `--order-by=random` |
| 2026-09-12 | Suite breakdown | — | Unit 191 · Auth 60 · Rbac 64 · Modules 30 · Panels 16 (full 5×5 matrix) · Account 61 · Audit 11 · Install 20 · Support 9 |
| 2026-09-12 | Front-end build (post-cleanup) | `npm run build` | PASS — CSS 113.72 kB (down from 116.58 kB after dead views were removed) |
| 2026-09-12 | Adversarial security review | read-only audit of routes, gates, middleware, policies, requests, 89 views | 14 findings (2 high, 6 medium, 6 low) — high ones under remediation |
| 2026-09-12 | Contract compliance audit | phase-01.md walked section by section against DB + code | 14 findings (1 stale critical, 2 medium, 11 low) |
| 2026-09-12 | Post-remediation suite | `php artisan test` (run by me, not an agent) | PASS — **587 tests / 18,334 assertions**, 111 s; also green under `--order-by=random` |
| 2026-09-12 | HIGH fixes hand-verified | temporary probe through the real HTTP kernel against `my_office` inside a rolled-back transaction | PASS — 29/29: `users.edit` without `users.change_status` gets a 422 on `status`, 403 on the status endpoint, and a 422 on any self role grant, while still being able to manage weaker accounts |
| 2026-09-12 | Seeder convergence on live data | `db:seed --class=ModuleSeeder` on `my_office` | PASS — 79 registered, 0 created, 4 updated; the only diff in the table is `is_core` 0→1 on the four portal modules; `is_enabled` byte-identical for all 79 rows |
| 2026-09-12 | Re-review of all 28 findings | two independent read-only auditors | 29 FIXED, 4 PARTIAL, 8 STALE/NOT_FIXED (low), **1 new medium** carried to Phase 2 |
| 2026-09-19 | Phase 4 full suite | `php artisan test` (run by me) | PASS — **1,522 tests / 58,814 assertions**, 873 s |
| 2026-09-19 | Phase 4 manifests | `--filter=MarketingManifestTest` | PASS — 5 tests / 4,372 assertions; 168 routes and 76 GET screens matched against the live route table |
| 2026-09-13 | Phase 2 checkpoint suite | `php artisan test` (run by me) | PASS — 1020 tests / 30,851 assertions, 227 s |
| 2026-09-13 | DB timezone pin proof | before/after read of all 67 TIMESTAMP columns under the old SYSTEM and new +00:00 session | PASS — 3,927 values byte-identical; new row UNIX_TIMESTAMP = PHP time() with 0 s drift; backup taken first |
| 2026-09-13 | Phase 2 security tests | 4 new classes (floors, write guards, input hardening, avatar policy) | PASS — 154 tests |
| 2026-09-13 | Avatar polyglot | GIF and PNG with an appended PHP payload uploaded through /account/avatar | PASS — stored file is re-encoded pixels, no payload; `tests/Feature/Account` 76 passed |
| 2026-09-13 | Phase 2 isolated suite | Phase 3 domain files stashed, `php artisan test` (run by me) | PASS — **1246 tests / 32,270 assertions**, 253 s |
| 2026-09-13 | Phase 3 forward migrate on `my_office` | `php artisan migrate` | PASS — nothing to migrate (the ten CMS migrations were already in batch 4) |
| 2026-09-13 | Phase 3 seed convergence (D65) | backup, then Module / Permission / Role / Setting / WebsiteCms seeders on `my_office`; before/after SQL diff | PASS — +3 modules, +24 permissions, +77 grants, +17 settings; 0 setting values, 0 module `is_enabled` and 0 role grants changed or removed; `cms:verify-published-snapshots` clean |
| 2026-09-13 | Phase 3 database guarantees + seeded site | integration L.7 read-only SQL | PASS — 7 CHECK / 2 generated / 10 unique; 3 modules / 24 permissions / 13 + 4 settings; 6 live sections, 15 items, 4 system pages, 4 menus, 9 items, 1 CTA, 3 FAQ categories, 6 FAQs, 5 seo_meta; 0 drift; 0 absolute URLs |
| 2026-09-13 | Phase 3 routes, gate, scans, build | A.3 gate, L.3–L.5, L.6 scripts; `route:list`; `npm run build`; `php -l` + `pint --test` on 72 PHP files | PASS — A.1 OK / A.2 OK; 85 Phase 3 routes, 151 total, no duplicate names; L.6 OK; build OK |
| 2026-09-13 | Phase 3 first full run (before the fixes above) | `php artisan test` | 5 failed / 1343 passed — FT-43, FT-27, FT-14, FT-51 (fixed) and T23 (unit 04's files) |
| 2026-09-13 | Phase 3 acceptance suite | `php artisan test tests/Feature/Cms` | PASS — 103 tests; FT-01 … FT-51 + FT-36b each mapped to a passing test |
| 2026-09-13 | Phase 3 full suite, sequential | `php artisan test` on the Phase 3 commit tree (a copy without unit 04's untracked files) | PASS — **1350 tests / 39,156 assertions**, 978 s |
| 2026-09-13 | Phase 3 full suite, random order | `php artisan test --order-by=random` on the same tree | PASS — **1350 tests / 39,156 assertions**, 799 s |
| 2026-09-13 | Phase 3 full suite in the shared working tree | `php artisan test --order-by=random` with unit 04's files present | 1 failed / 1349 passed — only T23 (unit 04 reads two undeclared `website.*` keys) |
| 2026-09-13 | Phase 3 HTTP smoke | probe through the real HTTP kernel on `my_office` inside a rolled-back transaction | PASS — 171/171: 24 admin CMS screens 200 for Super Admin, 403 for Accountant, Student and Client, 302 for a guest; public pages, sitemap and generated robots.txt 200; `/register` 404; unsigned preview 404, forged 403; a draft heading never reaches a guest, Student, Client or Accountant (live, `?preview=1`, preview route) but does reach a Super Admin preview (`no-store`); every panel still reaches only its own dashboard; row counts identical after rollback |
| 2026-09-13 | Phase 3 review-fix forward migrate + seed convergence (D65) | backup; `php artisan migrate`; Module / Permission / Role / Setting / WebsiteCms seeders on `my_office`; before/after SQL snapshot compared byte for byte | PASS — nothing to migrate; 82 modules / 812 permissions / 18 roles, 0 created, 0 updated, 0 grants added, none revoked; settings (178), module `is_enabled` (82) and role grants (2,520) identical |
| 2026-09-13 | Phase 3 review-fix routes, build, lint | `route:list`; `npm run build`; `php -l` + `pint --test` on the 29 changed PHP files | PASS — 151 routes (78 `admin.website.*`, 7 `site.*`), no duplicate names; build OK; lint and pint clean |
| 2026-09-13 | Phase 3 review-fix acceptance | `tests/Feature/Cms` inside the full run | PASS — 118 tests / 5,808 assertions; FT-01 … FT-51 + FT-36b each mapped to a passing test; 15 new review-regression tests |
| 2026-09-13 | Phase 3 review-fix full suite, sequential | `php artisan test` on the commit tree (a copy without unit 04's untracked files, T23) | PASS — **1365 tests / 39,349 assertions**, 973 s |
| 2026-09-13 | Phase 3 review-fix full suite, random order | `php artisan test --order-by=random` on the same tree | PASS — **1365 tests / 39,349 assertions**, 1109 s, seed 1789315968 |
| 2026-09-13 | T23 / T33 in the shared working tree | `php artisan test --filter=SettingsSplitTruthRegressionTest` with unit 04's files present | 1 failed / 7 passed — only unit 04's undeclared `website.contact_budget_options` / `website.testimonial_auto_approve` reads |
| 2026-09-13 | Phase 3 review-fix HTTP smoke | probe through the real HTTP kernel on `my_office` inside a rolled-back transaction (commit tree) | PASS — 212/212: 24 admin CMS screens 200 for Super Admin, 403 for Accountant / Student / Client, 302 for a guest; public pages, sitemap and robots.txt 200; impossible sitemap chunks 404; preview answers 404 / 403 identically for an existing and a missing id; a draft never reaches a guest, Student, Client, Accountant, a suspended Super Admin or one owing a password change; a CTA set to draft and a FAQ set to draft leave `/`, a CTA edit reaches `/` without re-publishing; the SEO Expert gets 422 on a live page's title / excerpt, 403 on a non-system live slug, and saves the body as a draft the guest never sees; SEO targets `route:admin.dashboard` / `route:login` / `route:site.page` 422; panels reach only their own dashboards; row counts and CMS row fingerprints identical after rollback |
| 2026-09-14 | Phase 3 review-round-2 forward migrate + seed convergence (D65) | backup; `php artisan migrate`; Module / Permission / Role / Setting / WebsiteCms seeders on `my_office`; before/after SQL snapshot diff | PASS — nothing to migrate; 82 modules / 812 permissions / 18 roles, 0 created, 0 grants added, none revoked; setting values (178), module `is_enabled` (82) and role grants (2,520) identical; 2 expected metadata refreshes (`website.cache_warm_enabled`, `website.revision_keep` → readonly) |
| 2026-09-14 | Phase 3 review-round-2 routes, build, lint | `route:list`; `npm run build`; `php -l` + `pint --test` on the 29 changed PHP files | PASS — 151 routes (78 `admin.website.*`, 7 `site.*`), no duplicate names; build OK; lint and pint clean |
| 2026-09-14 | Phase 3 review-round-2 acceptance | `tests/Feature/Cms` inside the full run | PASS — 142 tests; FT-01 … FT-51 + FT-36b each mapped to a passing test; 24 new review-regression tests |
| 2026-09-14 | Phase 3 review-round-2 full suite, sequential | `php artisan test` on the commit tree (a copy without unit 04's untracked files, T23) | PASS — **1389 tests / 41,436 assertions**, 1061 s |
| 2026-09-14 | Phase 3 review-round-2 full suite, random order | `php artisan test --order-by=random` on the same tree | PASS — **1389 tests / 41,436 assertions**, 856 s, seed 1789357805 |
| 2026-09-14 | T23 / T33 in the shared working tree | `php artisan test --filter=SettingsSplitTruthRegressionTest` with unit 04's files present | 1 failed / 7 passed — only unit 04's undeclared `website.contact_budget_options` / `website.testimonial_auto_approve` reads |
| 2026-09-14 | Phase 3 review-round-2 HTTP smoke | probe through the real HTTP kernel on `my_office` inside a rolled-back transaction (commit tree) | PASS — 225/225: 24 admin CMS screens 200 for Super Admin, 403 for Accountant / Student / Client, 302 for a guest; public pages, sitemap, robots.txt 200; a `Host: evil.test` sitemap / robots.txt never names the foreign host and matches what the next visitor gets; preview 404 / 403 identical for existing and missing ids; drafts never reach a guest, Student, Client, Accountant, a suspended Super Admin or one owing a password change; CTA / FAQ set to draft leave `/`; a `menus.edit`-only role gets 403 on toggle and on an `is_enabled` change through `PUT` (nothing written) but saves a label; the SEO Expert gets 403 moving the live `about` anchor, saves a label with the same anchor, sees the lock reason; `SettingsService` refuses both readonly deferred-job settings; an allowlisted Google map embed renders an iframe, a foreign one and a `<script>` render nothing; `layouts.site` exists; `ModuleStateChanged` has a listener; regenerate throttled; SEO targets on non-public routes 422; panels reach only their own dashboards; row counts and CMS / menu / settings / module fingerprints identical after rollback |
| 2026-09-19 | Phase 5 integration gate | `php artisan test tests/Feature/Crm/CrmSmokeTest.php` | PASS — 6 tests / 32 assertions |
| 2026-09-19 | Phase 5 full suite | `php artisan test` (run by me) | PASS — **1,529 tests / 60,382 assertions**, 927 s |
| 2026-09-19 | Phase 5 route guards (D31) | `route:list --json` walked in PHP | PASS — 24/24 `client.*` routes carry `client.context`; 409 routes, 0 duplicate names |
| 2026-09-19 | Phase 5 counters readonly (D62) | `SettingsRegistry::all()['crm']` read | PASS — `lead_number_next_number` and `client_code_next_number` both readonly |
| 2026-09-19 | Phase 5 migration state | `php artisan migrate:status` on `my_office` | PASS — all 10 CRM migrations Ran, 0 pending |
| 2026-09-19 | Phase 6 enums | scripted walk of all 14 enums | PASS — 54 cases, every label/color/weight arm exercised; 4 transition tables closed over their own enum, no self-transition |
| 2026-09-19 | Phase 6 migrations | `migrate` on `my_office_test`, `rollback --step=11`, `migrate` again, then `migrate` on `my_office` | PASS — 11/11 forward, 0 Phase 6 tables left after rollback, clean re-migrate |
| 2026-09-19 | Phase 6 §2.14 object list | `information_schema` counted in PHP | PASS — 13 STORED generated columns, 27 CHECKs, 7 named unique indexes, `trg_pvr_no_delete`, and **0** columns silently carrying `ON UPDATE CURRENT_TIMESTAMP` (D67) |
| 2026-09-19 | Phase 6 database guards | probe through Eloquent and raw DML in a rolled-back transaction | PASS — **29/29**: generated columns unwritable, all six `projects` CHECKs, the append-only trigger, both `uq_pm_*`, `chk_tasks_depth` both ways, `uq_te_running`, `uq_tes_open`, open segment = 0 s and 1500 s on close |
| 2026-09-19 | Phase 6 model invariants | probe through Eloquent in a rolled-back transaction | PASS — **26/26**: INV-P1 / INV-P8 / INV-P13 each name their owning service and re-lock after `unlock()`, `ImmutableRevisionException` on update and delete, INV-P10 / INV-P12, the append-only segment rules |
| 2026-09-19 | Phase 6 foundation full suite | `php artisan test` (run by me) | PASS — **1,529 tests / 60,437 assertions**, 938 s; no regressions from the new models resolving Phase 5's later-phase relations |
| 2026-09-19 | Phase 6 §6.3 progress algorithm | scripted probe in a rolled-back transaction | PASS — 16/16 across all four levels, including the two `max()` cases and INV-P9 |
| 2026-09-20 | Phase 6 service layer | scripted probe in a rolled-back transaction | PASS — **51/51** end to end: numbering, value revisions, transitions, assignment, Kanban ordering, the timer guards, manual time and the day cap. Found 3 real bugs, all fixed |
| 2026-09-20 | Phase 6 admin UI | browser session against `php artisan serve` as the Project Manager demo login | PASS — projects list (empty and populated), project page (derived progress 43 %, matching the §6.3 arithmetic), Kanban board, and a timer started and stopped through the UI showing 0.00 stored hours while running. Caught the missing `AuthorizesRequests` |
| 2026-09-20 | Phase 6 delivery tests | `php artisan test tests/Feature/Project/ProjectDeliveryTest.php` | PASS — 13 tests / 41 assertions, green on the first run |
| 2026-09-20 | Phase 6 full suite | `php artisan test` (run by me) | PASS — **1,543 tests / 61,359 assertions**, 1,008 s |
| 2026-09-20 | Phase 6 client panel | browser session as the demo client login | PASS — the project, its status and 43 % render; the page body carries no contract value, no budget and no hours, because the section never selects those columns |
| 2026-09-20 | Phase 6 constraint sentinel | `php artisan projects:verify-constraints` on `my_office` | PASS — every generated column, CHECK, unique index and the append-only trigger still present |
| 2026-09-20 | Phase 6 full suite, with the client sections | `php artisan test` (run by me) | PASS — **1,545 tests / 61,368 assertions**, 1,250 s |
| 2026-09-20 | Phase 7 enums | scripted walk of all 28 enums | PASS — 155 cases, every arm exercised; every payable factor and leave fraction is a 4-decimal string, never a float (HR-12); every component group reports into a real slip column |
| 2026-09-20 | Phase 7 migrations | `migrate` on `my_office_test`, `rollback --step=27`, `migrate` again, then `migrate` on `my_office` | PASS — 27/27 forward, 0 tables and 0 triggers left after rollback, clean re-migrate |
| 2026-09-20 | Phase 7 §2 object list | `information_schema` counted in PHP | PASS — 24 tables, 9 STORED generated columns, 7 guard unique indexes, 5 BEFORE DELETE triggers, and **0** columns silently carrying `ON UPDATE CURRENT_TIMESTAMP` (D67) |
| 2026-09-20 | Phase 7 database guards | probe in a rolled-back transaction | PASS — **19/19**: all seven guards, the signed generated columns, five CHECKs including the advance ceiling, the append-only triggers, and the draft-deletes / locked-refuses pair (HR-16) |
| 2026-09-20 | Phase 7 models | scripted walk against the live schema | PASS — 24 models, 109 relations, no stray cast, no stray fillable, no generated column fillable |
| 2026-09-20 | Phase 7 model invariants | probe in a rolled-back transaction | PASS — **22/22**: HR-10, D19's three append-only tables, HR-15 / HR-16's two-step payroll freeze, HR-19, and the component group deciding its side. Found 2 real bugs (`getOriginal()` applying casts), both fixed |
| 2026-09-20 | Phase 7 foundation full suite | `php artisan test` (run by me) | PASS — **1,545 tests / 61,499 assertions**, 1,035 s |
| 2026-09-20 | Phase 7 attendance and the calendar | probe in a rolled-back transaction | PASS — **66/66**: the thirteen §6.3 steps, the night shift keeping its start row, the exempt employee, the unpaid holiday, half-day leave plus a worked half, the eighteen-hour guard, close-day idempotency, and the shiftless employee judged on hours alone |
| 2026-09-20 | Phase 7 summaries and corrections | probe in a rolled-back transaction | PASS — **45/45**: R1 and R2 on a full month and on a month with 2.5 lost days, the idempotent rebuild, both correction doors leaving identical evidence, the write whitelist refusing `locked_at`, and a locked summary refusing to be rebuilt |
| 2026-09-20 | Phase 7 leave | probe in a rolled-back transaction | PASS — **62/62**: grants and accruals idempotent, four calendar days costing three quota days, the HR-8 overlap guard naming the existing request, self-approval refused, reservation → consumption → release, cancellation re-resolving the calendar, carry-forward capped and lapsed, and the balance equal to its ledger at every step |
| 2026-09-20 | Phase 7 payroll | probe in a rolled-back transaction | PASS — **93/93**: the contract's worked example to the paisa, the version timeline, the recovery cap, the lock freezing slips and attendance, payment posting the advance recovery once, over-recovery refused naming the remainder, corrections positive and negative, the hold with its reason, and the rounding line |
| 2026-09-20 | Phase 7 setup services | probe in a rolled-back transaction | PASS — **57/57**: derived shift minutes, the single default shift, the employee code issued once, the full exit sequence settling encashable leave, the scope resolver's three modes, a holiday declared late re-resolving an absence, and a component's side overruling a wrong one |
| 2026-09-20 | Phase 7 integration gate | `php artisan test tests/Feature/Hr` | PASS — **30 tests / 140 assertions**, 57 s |

---

## 8. Known Issues / Tech Debt

| # | Item | Severity | Note |
|---|---|---|---|
| T1 | `@tailwindcss/vite@4` is installed but unused (Breeze switched the project to Tailwind 3 via PostCSS). | low | Remove from `package.json` during Phase 1 UI work to avoid confusion. |
| T2 | Project path contains a space (`my office`) — awkward as an Apache docroot URL. | low | Use `php artisan serve` in dev; document a vhost in Phase 25. |
| T3 | The DB root user has no password (XAMPP default). | med | Fine locally; Phase 25 must document a least-privilege production DB user. |
| T4 | Breeze shipped public self-registration at `/register`. | — | **Resolved** in Phase 1: routes, view and controller removed; a test asserts 404 on GET and POST. |
| T5 | Authentication activity entries are filed under module `users`, not `login_history`. | low | spatie applies the *subject* model's `tapActivity()` last, so `User::activityModule()` overwrites the logger's value. Cosmetic — only feeds the Activity Log screen's module filter; `Gate::before` never reads it. Fix needs working around vendor call ordering. |
| T6 | The test suite uses one fixed database name (`my_office_test`). | med | Two concurrent `php artisan test` runs drop tables out from under each other (seen once during the build). Phase 24 should derive the name per process. |
| T7 | Light/dark rendering and browser console errors are unverified. | med | No PHPUnit test can see them — every screen is asserted to return 200 server-side. Needs a manual pass or Laravel Dusk. |
| T8 | `profile.edit` remains as a dead fallback route name in `EnsureUserIsActive`, the four panel dashboards and the topbar partial. | low | Every use is guarded by `Route::has()`, so it is inert. Clean up in Phase 2. |
| T9 | `SUPERADMIN_PASSWORD` is unset, so every `migrate:fresh --seed` mints a new random Super Admin password. | low | Printed once by the seeder. Set the env var before a production install (Phase 25). |
| T10 | Unused Breeze view components remain (`text-input`, `primary-button`, `dropdown`, `modal`, …) plus `welcome.blade.php`. | low | Inert and unreferenced since the `x-ui` set replaced them. Delete in Phase 2 to keep the component namespace clean. |

**Carried into Phase 2** — found by the post-remediation re-review, none of them exploitable with the seeded
data, all with a named fix:

| # | Item | Severity | Note |
|---|---|---|---|
| T11 | **A role grant is bounded only by `roles.level`, never by the permissions the actor holds.** | med | The role *editor* enforces "you may not grant a permission you do not hold"; a role *grant* does not. Today no assignable role holds a permission the Admin lacks, so it is latent — but the first custom role with `level > 5` holding, say, `modules.*` would let an Admin escalate through a puppet account. Fix: `UserPolicy::assignRoles()` must also require that the actor holds every permission in the role being granted. |
| T12 | `roles` is `required` when updating someone else's account, but an actor without `users.assign` is offered no roles — so they can never save that account at all. | low | Functional block. Make `roles` required only when the actor may assign roles, and preserve the existing set otherwise. |
| T13 | Admin-set passwords validate with `Password::defaults()` (min 8, no complexity) while `/account/password` uses `PasswordPolicy` (min 10, mixed case, number, symbol, uncompromised). | low | An administrator can set a weaker password than the owner could. One policy everywhere. |
| T14 | `SettingsRepository::get()` two-argument form: a single-segment key that collides with a group name is parsed as (group, key), so `setting('localization', 'en')` returns null instead of the default. | low | Phase 2's settings UI leans on this API — fix before building on it. |
| T15 | N+1 in `RoleController::show()` — the new member-visibility filter lazy-loads `roles` per listed member. | low | Add `with('roles')` to the member query. |
| T16 | `user_agent` is stored unclamped, and a failed `login_histories` insert is swallowed by `catch (Throwable)`. | low | Clamp like its siblings and log the swallowed failure. |
| T17 | `avatar_url` does a `Storage::exists()` stat on every read — 25 stats per users index. | low | Memoise per instance or cache the attribute. |
| T18 | UI items both auditors said belong in Phase 2: `x-ui.skeleton` rendered nowhere, no bulk-select in the table component, the roles index uses inline links where users uses a row-actions dropdown, `@module` registered but never used or documented. | low | Phase 1 has no async list or bulk action for them to serve, so they were premature rather than wrong. |
| T19 | Two accounts holding the **same** role cannot manage each other (`outranks()` is strict, 5 > 5 is false), and the users index still lists accounts whose row actions are all empty. | low | Invisible with one Admin; confusing the moment a business creates a second. Decide the peer rule and make the UI say "outranks you" instead of showing an empty menu. |
| T20 | `phase-01.md`'s D20 text says the four portal prefixes are "permission namespaces, not modules" and that `permissionModuleMap()` returns null for them. The implemented fix kept them as registered modules with `is_core = true`. | low | The guarantee is delivered by a different mechanism, so the contract text is now wrong. Correct the text (code is fine) once the contract convergence workflow releases `docs/`. |
| T21 | `TestCase::actingAs()` clears `password_hash_web`, so no `actingAs()` test can observe `AuthenticateSession` signing a stale session out — the very mechanism the remediation added. | low | Necessary (one session Store per test) but it narrows coverage; add one test that logs in for real instead. |
| T22 | The modules screen's locked-card tooltip gives the "dashboard, users, roles…" reason for the four portal cards too. | low | Wrong explanation on a correct lock. |

**Recorded by the Phase 3 verification (2026-09-13)**:

| # | Item | Severity | Note |
|---|---|---|---|
| T23 | `SettingsSplitTruthRegressionTest::no_view_route_or_middleware_reads_a_key_the_registry_does_not_declare` is red in the shared working tree while unit 04's unintegrated files are on disk: `Site\ContactController`, `PublicContactRequest` and `ModeratedContentController` read `website.contact_budget_options` and `website.testimonial_auto_approve`. | med | Not a Phase 3 defect — the committed Phase 3 tree (verified on a copy without unit 04's files) is green twice. Clears when Phase 4 declares its §5.1b `website.*` keys. |
| T24 | `CtaBlockPolicy`, `FaqCategoryPolicy` and `MediaPolicy` check `{module}.restore`, which the registry does not declare for those modules (integration K-11). | low | No route restores them, so nothing reachable; declare `restore` or drop the method when a restore screen is added. |
| T25 | Every anonymous public GET starts a `sessions` row (the public routes sit in the `web` group), cached page or not (M-2). | med | A crawler-heavy site writes a row per visitor. Needs a lighter middleware group for public GETs — Phase 25. |
| T26 | `CacheVersion::STAMP_TTL_SECONDS` is ten years and `cache.expiration` is a signed INT: from **2028-01-21** a fresh stamp overflows and `bump()` fails (reported, not thrown) (M-27). | med | Shorten the TTL or widen the column before then. |
| T27 | Media URLs inside published snapshots are absolute (`APP_URL`); menu links are root-relative (K-8, M-10). | low | Set `APP_URL` correctly before the first image is published on a real host. The seeded snapshots carry no absolute URL (L.7 check = 0). |
| T28 | Contract gaps shipped as known omissions: no `menus.store` (the `mobile` slot cannot be created, G-1); FAQ categories toggle through `PUT` (G-2); robots.txt text is edited on the SEO settings tab under `settings.edit` (G-3); a signed preview link answers 503 while the site is closed because preview routes carry `site` (M-26). | low | Integration F.6 / M-22. |
| T29 | "Live" hero statistics are frozen inside the cached page for `website.cache_ttl_minutes` (1440) (M-17). | low | A number can be a day old; a publish flushes it. |

**Recorded by the Phase 3 review-fix verification (2026-09-13)**:

| # | Item | Severity | Note |
|---|---|---|---|
| T30 | **A public page whose body contains the session CSRF token is never cached** (`CachePublicResponse`, R-4). The seeded home page is cacheable only because it holds no form; FT-24 fails if that stops being true. | med | Rule for later phases: a form on a cached public page (Phase 4 contact, Phase 15 admission) must fetch its token after load or post to a route outside `site.cache` — never render `@csrf` into a page meant to be cached, or that page silently stops caching. Not yet mirrored into `CLAUDE.md`. |
| T31 | `website_media.restore` is not declared in `PermissionRegistry`, so only a Super Admin can bring a trashed asset back by re-uploading its bytes (the same gate as `MediaPolicy::restore`, T24). | low | Declare `restore` for `website_media` when a restore screen is added. |
| T32 | The cold seeded home page is at exactly the FT-27 budget of 8 queries after CTA / FAQ references became live. | low | No headroom: the next query a Phase 3 render path adds fails FT-27. Later phases' live providers must cache under the version stamp. |
| T33 | T23 still applies to the shared working tree: unit 04's untracked `ContactController`, `PublicContactRequest` and `ModeratedContentController` read undeclared `website.*` keys, so `SettingsSplitTruthRegressionTest` is red there. | med | The review-fix suite was therefore run on a copy of the commit tree without unit 04's files, as for the Phase 3 commit. |

**Recorded by the Phase 3 review-round-2 verification (2026-09-14)**:

| # | Item | Severity | Note |
|---|---|---|---|
| T34 | The SEO manager (`admin.website.seo.index`) and its export load every `seo_meta` row and paginate in PHP (`SeoService::auditRows`). | low | Fine at Phase 3 volumes; move filtering and pagination into SQL before later phases add thousands of SEO targets. |
| T35 | The queued `GenerateImageDerivatives` job (phase-03 §6.8, §10.2) has not shipped: upload and `media.regenerate` decode and re-encode inline (GD). | low | `media.regenerate` is now throttled `6,1` per user; ship the job with a queue worker (Phase 25). |
| T36 | `WarmPublicPageCache` and `PruneCmsRevisions` / `cms:prune-revisions` (§10.2, §10.4) are deferred, so `website.cache_warm_enabled` and `website.revision_keep` are **readonly** with a "Not active yet" help. | low | `Cms/Behaviour/DeferredFeatureSettingsTest` fails the day either class exists, asking for the flag and help to be lifted. Re-running `SettingSeeder` on an older database flips exactly these two rows' `is_readonly` (seen on `my_office`, expected). |
| T37 | **Rule:** every foreign key into `media_assets` is a usage source and must be read by `MediaService` (`usage()`, the one-asset recount and the full `cms:media-recount` pass). `MediaService` still lists its sources by hand. | rule | `Cms/Behaviour/MediaUsageSourcesTest` is schema-driven (`information_schema.KEY_COLUMN_USAGE`): a later phase's `*_media_id` column fails it until `MediaService` names the table and column, otherwise the D24 delete guard would let a live image be trashed. phase-04-integration §4.6 patches those exact spots; no registry was added. |
| T38 | Contract-name map additions (phase-03 names → code): enums live in `app/Enums/Cms` (contract: `app/Enums`); `App\Services\Cms\Data\SitemapEntry` is the contract's `SitemapUrl`; there is no `PreviewService` (preview lives in `Site\PreviewController` + `ResolvePreviewMode`); `layouts/site.blade.php` is an alias that `@extends('site.layouts.public')`. | low | Naming only; extends the round-1 map in §6. Later phases may use either layout name. |
| T39 | `trustHosts()` is on the global middleware stack outside `local` and the test runner: a host that is neither `APP_URL` (or a subdomain) nor `seo.canonical_base_url` gets a 400 before routing. | med | Deployment note for Phase 25: set `APP_URL` (and the canonical base URL when the site has a separate domain) to the real public host before switching `APP_ENV` to production, or every request is refused. |
| T40 | **Phase 5's full acceptance suite (phase-05 §11) is not written.** `tests/Feature/Crm/CrmSmokeTest.php` (6 tests) is the integration gate only: it proves the screens answer, the permissions bite, the services number their records and the client panel is isolated — it does not walk the contract's FT rows. | med | Owed before Phase 5 can be called acceptance-complete. Every other phase closed with its FT map ticked; this one closed on the gate because the build predates this session. |
| T41 | `docs-pending/phase-05-integration.md` C.7 (contract deviations) and W.5 (search not applied on some CRM screens, staff logo upload missing) are carried forward as written. | low | Fold into the Phase 5 acceptance work (T40). |
| T42 | `App\Services\Finance\DocumentNumberService` landed in Phase 4's commit although D27 assigns it to Phase 5. | low | Harmless: Phase 5 is still its first and only consumer (`lead_no`, `client_code`). Noted so the D27 attribution is not read as a contradiction later. |

**Build-time items from the contract audit (BT-1 … BT-10)** — the documentation convergence is **closed** after
three rounds ([`docs/design/consistency-audit-final.md`](docs/design/consistency-audit-final.md): all RD items
closed, verdict "phases 3–25 are buildable"). These ten are deliberately left for the phase that owns them,
because the code will make the answer obvious; they are **not** a reason to run another documentation round.

| # | Owner | Item |
|---|---|---|
| BT-1 | Phase 10 | `app/Enums/Ability.php` needs the `LinkInvoice` case (one line) before phase-10-12 can register `project_payments.link_invoice` and phase-13's two D43 routes can work. The contract is amended ([`phase-01.md`](docs/phases/phase-01.md) §2); the code lands with Phase 10 deliberately, because an unused case now would fail the `PermissionRegistry` integrity test. |
| BT-2 | Phase 21 | `certificates` and `student_id_cards` ship `deleted_at` in phase-19-23 while `CLAUDE.md` §3 lists them as append-only. **Recommendation: append-only wins** — D52 already makes an issued certificate a snapshot whose correction is revocation plus reissue, and a soft-deleted row would make a public QR verification URL fail ambiguously. Decide once, with the code. |
| BT-3 | Phase 14-17 | `contact_inquiries.course_id` is the only deferred FK with no named promoter. Fold it into `add_institute_fks_to_fee_tables`. |
| BT-4 | Phase 7 | `build-order.md` §10.2 names `job_applications.department_id`, which no contract declares; the real columns are `team_members.department_id` and `job_openings.department_id`. |
| BT-5 | Phase 13 | Phase 6 asked for `invoices.project_milestone_id`; Phase 13 ships the link on `invoice_items.project_milestone_id` instead. Satisfied at the line-item grain — which is also the only grain that lets one invoice cover two milestones. |
| BT-6 | Phase 19-23 | Phase 14-17 asked for `course_materials.batch_id`; batch reach is delivered through `course_material_targets` by design, so no such column exists. |
| BT-7 … BT-10 | various | Cosmetic: a test-range label, `login_history` (the module slug) written where `login_histories` (the table) was meant, two stale quotations of a now-corrected count, and two request rows that read as open although they are already satisfied. |

**Accepted deviations from the Phase 1 contract** (deliberate, documented so no later session "fixes" them):

| Item | Rationale |
|---|---|
| Account routes live at `/account/*` (name `account.*`) rather than under `/admin`. | They are shared by all five panels — a student must reach their own profile without an admin prefix. |
| `admin.activity-log.export` and `admin.login-history.export` exist beyond the contracted route list. | Requirement §99 demands export; both re-check the `*.export` permission and stream via a generator. |
| `Permission::ability` is cast through a small `AbilityCast` rather than `Ability::class` directly. | Legacy rows with an unknown ability string must not throw on read; the cast degrades instead. |
| The literal "disable `projects` → its routes 403" test is replaced by two equivalent tests. | No `projects` routes exist in Phase 1 (a module gets routes in its own phase). Covered via the `module:collaborators` route group and a direct `Gate::forUser()` assertion on `projects.view_any`. |
| `User::canAccessPanel()` gives Super Admin **no** free pass into the student/teacher/client/collaborator panels. | Panel access follows a role that owns that panel; Super Admin bypasses *permissions*, not *identity*. |

---

## 9. Open Questions (need your input — not blocking, defaults assumed)

| # | Question | Default assumed until answered |
|---|---|---|
| Q1 | Company / brand name, logo, favicon, brand colour? | `MyOffice ERP`, neutral indigo/slate palette, settings-driven so it swaps with no code change. |
| Q2 | Institute name — same brand as the software house, or separate? | Same brand, separate website sections. |
| Q3 | Currency and symbol? | **PKR**, `decimal(15,2)`, configurable in settings. |
| Q4 | Default commission base for students and projects? | **Actual amount received** (the spec's recommended default), changeable in settings. |
| Q5 | Commission approval mode? | **Manual approval** for the first release (safer), switchable to automatic. |
| Q6 | May collaborators request their own payouts? Minimum payout? | Enabled, minimum PKR 1,000 — both settings-driven. |
| Q7 | Real SMTP credentials? | `MAIL_MAILER=log` in dev; SMTP configurable (encrypted) from the Settings UI in Phase 2. |
| Q8 | Super Admin real name + email for the first account? | Seeded as `superadmin@myoffice.test`, password printed once during install, must be changed on first login. |

**Commission-policy questions raised by the financial spine** (not blocking — each is a per-collaborator or
global **setting**, so the schema already supports every option and the answer can change without a migration):

| # | Question | Default implemented |
|---|---|---|
| Q9 | A **fixed-amount** commission (say PKR 2,000, not a percentage): is it earned per admission or per installment, and paid up front or in step with collection? | Per admission, released pro-rata to money actually collected, the rounding residual on the final receipt (2,000 over three 10,000 installments → 666.67 + 666.66 + 666.67). The other two behaviours are a per-collaborator `fixed_release` dropdown. |
| Q10 | For the gross / net-after-discount bases, is commission released in proportion to collection rather than in full on the first receipt? | Yes — requirement §42 says "never pay full commission before payment is received". The applied rule is snapshotted onto every ledger row. |
| Q11 | Should a **hold period** sit between Approved and Available, so commission on a fee refunded the next day was never withdrawable? | `commission_hold_days = 0` (reproduces §51/§53 literally). Raising it is the only structural defence against paying out money that gets refunded; the clawback path is otherwise the remedy. |
| Q12 | If a fee is refunded **after** the commission was already paid out in cash, do we claw it back or write it off? | Claw back — a negative available balance offsets future earnings. A permissioned, reasoned `write_off` action exists for genuine cases. |
| Q13 | Should closed months be **lockable**, so a back-dated receipt cannot rewrite an already-issued statement? | No hard period lock. A 30-day back-date window, the module's `approve` ability beyond it, and both `paid_on` and `posted_at` stored so the audit is honest. |

**Product decisions raised by the cross-contract convergence** (each already implemented in a way that keeps
both options open, so answering later costs no migration — source: [`docs/design/resolutions.md`](docs/design/resolutions.md) §6):

| # | Question | Recommended default | What the applying agents implement **now** (keeps both options open) |
|---|---|---|---|
| H1 | **REST API** (§1 "REST API where required", §6 "blocks API access") — in this release or not? (F-13.1) | **No REST API in this release.** | No `routes/api.php`, no token guard. The idempotency key is generated server-side per submission; the spine's sentence becomes "if an API is ever added it supplies the key through an `Idempotency-Key` header". `ReferralSource::api` stays as a reserved case. Adding an API later needs no schema change. |
| H2 | **§29 "collaborator payments" as tracked income** — money *to* collaborators (a cost) or *from* them? (F-13.3) | **To** collaborators: a cost. | P&L block C is labelled "Collaborator commission (cost)" with a legend line. If the client means money *from* collaborators, it becomes an `incomes` category row later — `incomes` already has categories, so no schema change. |
| H3 | **Ticket SLA** — §93 never asks for it; it is four settings, two clock columns, a pause rule, a sweeper and five tests. (F-13.5) | **Build it.** | Ship behind `support.sla_enabled` (boolean, default **true**); every clock column nullable. Off = no sweeper, no breach badge, no multiplier call. Dropping it later is a settings flip, not a migration. |
| H4 | **Commission approval mode** — §120.2 says "one commission of PKR 1,000" without a status; FIN-03 asserts `pending`. Should a new commission be immediately `available`? | **Keep `manual`.** Money should not become payable without a human. | `collaborator.commission_approval_mode` already exists (`manual` default); FIN-03 asserts `pending`. Switching to `auto` is a settings change and FIN-03 gains one branch. |
| H5 | **Referral-visit retention** — 365 days of IP and user-agent per click. (F-13.12) | **365 days**, shortenable. | Expose `collaborator.referral_visit_retention_days` (default 365) so the client can shorten it without a migration; the prune command reads the setting and never prunes a visit a `collaborator_referrals.referral_visit_id` or `leads.referral_visit_id` points at. |
| H6 | **Public signed invoice link** (`invoices.public_token`) for clients with no login. (F-13.9) | **Build it**, off by default. | `finance.invoice_public_link_enabled` default **false**, `finance.invoice_public_link_ttl_days` default 14; the guest route 404s when disabled. |
| H7 | **CV / applicant visibility** — who may see every CV in the system? (F-12.4) | HR full; hiring managers per opening; Digital Marketer none. | Implemented as stated in F-12.4 — no blanket `view_any` outside HR, an optional `job_opening_id` scope, the technical/PII block behind `contact_inquiries.view_logs`. Widening later is a role grant, not a code change. |

**Features built beyond the literal requirement** — each one the contracts judged operationally necessary.
Recorded here so nobody later calls them scope creep, and so you can cut any of them with one word:

| Finding | Feature | Contract | Assumed default | Note |
|---|---|---|---|---|
| F-13.5 | Ticket SLA clocks and breach sweeps | phase-19-23 | **build** (behind `support.sla_enabled`) | H3 |
| F-13.6 | `notification_preferences` per user/event | phase-19-23 | build | mandatory events stay locked |
| F-13.7 | `cms_revisions` + revert, signed preview links, `sitemap_generations` | phase-03 | build | operationally necessary |
| F-13.8 | CSV lead import (wizard, chunked jobs, error CSV) | phase-05 | build | `leads.import` gates it |
| F-13.9 | Public signed invoice link | phase-13 | build, **disabled by default** | H6 |
| F-13.10 | `technologies` table + two pivots | phase-04 | build | §11/§12 call it a field; a table gives filters |
| F-13.11 | Backup restore wizard + scratch verification | phase-24-25 | build | "a backup is not a backup until it has been restored" |
| F-13.12 | `collaborator_referral_visits` funnel report + retention | phase-08-09 | build | H5 |
| F-13.13 | `grade_scales` / `grade_scale_bands` | phase-19-23 | build | §82 needs a grade; a scale makes it configurable |
