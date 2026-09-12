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
| **Last updated** | 2026-09-12 |
| **Current phase** | PHASE 1 — Laravel setup, authentication, roles & permissions |

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

### [~] PHASE 1 — Laravel setup, authentication, roles & permissions

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

### [ ] PHASE 2 — Admin dashboard, system settings, module management

Contract: [`docs/phases/phase-02.md`](docs/phases/phase-02.md) — **written 2026-09-12**, build starts the
moment Phase 1 is verified (both phases touch `Admin/DashboardController`, `Admin/ModuleController`,
`routes/admin.php` and `tailwind.config.js`, so they cannot run concurrently).

| | Item |
|---|---|
| [x] | Phase 2 contract written (schema deltas, `SettingsRegistry`, services, routes, UI, 14 acceptance tests) |
| [ ] | Migrations: `modules.depends_on` + disable audit, `settings.updated_by`/`is_readonly`, `users.preferences` |
| [ ] | `SettingsRegistry` — 13 groups, ~110 typed fields with rules/defaults/help, driving seeder + form + validation |
| [ ] | `SettingsService` (upload handling, encryption, per-key audit, group reset) + `ConfigureFromSettings` runtime wiring |
| [ ] | Mail settings + test-email service (uses saved SMTP, throttled, password never exposed) |
| [ ] | `ModuleService` dependency resolution, impact preview, cascade, disable audit, data-safety guarantee |
| [ ] | `DashboardRegistry` + 10 real widgets + `DateRange` + per-user widget layout |
| [ ] | Settings UI (tab rail, dirty save bar, live branding preview, reset-to-defaults) |
| [ ] | Modules UI (grouped cards, impact modal with reason, bulk toggle) |
| [ ] | Dashboard UI (widget grid, date range, customize mode, Chart.js `x-ui.chart`) |
| [ ] | Runtime brand colour via CSS variables in `tailwind.config.js` |
| [ ] | `Format` helpers (`money()`, `app_date()`) reading localization settings |
| [ ] | 14 acceptance tests green |
### [ ] PHASE 3 — Dynamic public website CMS (sections, menus, pages, SEO)
### [ ] PHASE 4 — Services, portfolio, blog, careers
### [ ] PHASE 5 — CRM: leads (Kanban), clients, client panel
### [ ] PHASE 6 — Projects, milestones, tasks, time tracking
### [ ] PHASE 7 — Employees, departments, attendance, leave, payroll
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
