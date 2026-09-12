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

Contract: [`docs/phases/phase-01.md`](docs/phases/phase-01.md)

| | Item |
|---|---|
| [ ] | Migrations: users extension, spatie tables + extra columns, `modules`, `settings`, `branches`, `login_histories`, `activity_log` context columns |
| [ ] | Enums: `UserStatus`, `ThemePreference`, `PanelType`, `ModuleGroup`, `Ability` |
| [ ] | Models: `User`, `Role`, `Permission`, `Module`, `Setting`, `Branch`, `LoginHistory`, `Activity` |
| [ ] | Traits: `Blameable` (created_by / updated_by), `LogsActivityWithContext` |
| [ ] | `PermissionRegistry` — the module-to-abilities matrix (single source of truth) |
| [ ] | Seeders: modules, permissions, 18 default roles, Super Admin, settings, default branch |
| [ ] | Auth: login, logout, forgot password, reset, change password, email verification, profile + avatar |
| [ ] | Account state enforcement (active / inactive / suspended) at login **and** on every request |
| [ ] | Login history with IP + device logging; active session list and revoke |
| [ ] | Middleware: `EnsureUserIsActive`, `EnsureModuleEnabled`, `RedirectToPanel`, permission/role aliases |
| [ ] | `Gate::before` — module-disabled denial first, then Super Admin bypass |
| [ ] | Admin UI shell: sidebar (permission + module aware), topbar, theme switcher, toasts, confirm dialog, breadcrumbs, empty states, skeleton loaders |
| [ ] | Admin CRUD: Users, Roles (permission matrix editor), Permissions viewer |
| [ ] | Activity log + login history viewers |
| [ ] | Authorization tests (every role against every panel) + auth flow tests |

### [ ] PHASE 2 — Admin dashboard, system settings, module management
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

---

## 8. Known Issues / Tech Debt

| # | Item | Severity | Note |
|---|---|---|---|
| T1 | `@tailwindcss/vite@4` is installed but unused (Breeze switched the project to Tailwind 3 via PostCSS). | low | Remove from `package.json` during Phase 1 UI work to avoid confusion. |
| T2 | Project path contains a space (`my office`) — awkward as an Apache docroot URL. | low | Use `php artisan serve` in dev; document a vhost in Phase 25. |
| T3 | The DB root user has no password (XAMPP default). | med | Fine locally; Phase 25 must document a least-privilege production DB user. |
| T4 | Breeze ships public self-registration at `/register`. | med | Phase 1 removes/guards it per decision D15. |

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
