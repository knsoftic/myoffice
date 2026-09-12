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
| D17+ | Further decision numbers are assigned by the authoritative registry in [`docs/design/resolutions.md`](docs/design/resolutions.md) — nine contracts had independently claimed overlapping numbers (audit finding F-10.1), which would have blocked the first phase-3 migration. D1–D16 above are frozen and never renumbered. | One registry, or the decision log becomes fiction. |

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
| [x] | Acceptance suite: **478 tests / 17,727 assertions green** on `my_office_test` |
| [~] | Adversarial review remediation: 2 HIGH + 6 medium findings being fixed, then re-reviewed |
| [ ] | Browser pass: light/dark render + console errors (no PHPUnit test can see this — manual or Dusk) |
| [ ] | Rollback of all 14 migrations executed against `my_office_test` (suite only asserts every `down()` is non-empty) |

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
### [ ] PHASE 19 — Course material + assignments
### [ ] PHASE 20 — Exams + results
### [ ] PHASE 21 — Certificates (public QR verification) + student ID cards
### [ ] PHASE 22 — Tickets, meetings, internal messaging, notifications
### [ ] PHASE 23 — Reports, analytics, activity log, audit trail, global search, exports
### [ ] PHASE 24 — Security, financial integrity, responsive and performance testing
### [ ] PHASE 25 — Deployment preparation (install guide, backups, queue/scheduler, production notes)

---

## 6. Change Log

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
