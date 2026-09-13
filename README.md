# MyOffice ERP

A single Laravel application that runs a **software house** and an **IT training institute** from one
database: CRM, projects, HR, finance, collaborator commissions, courses, admissions, fees, exams and
certificates. It has five signed-in panels and a public website driven entirely by the CMS.

| Area | URL prefix | Who uses it |
|---|---|---|
| Admin panel | `/admin` | Staff: Super Admin, Admin, HR, Accountant, Project Manager, Developer, Designer, SEO Expert, Digital Marketer, Sales Executive, Receptionist, Support Agent, Institute Manager, Course Coordinator |
| Collaborator panel | `/collaborator` | Referral partners who earn commission |
| Student panel | `/student` | Enrolled students |
| Teacher panel | `/teacher` | Teachers |
| Client panel | `/client` | Software-house clients |
| Public website | `/` | Visitors (content comes from the CMS) |
| Sign-in | `/login` | Everyone. There is no public self-registration: staff create accounts |

There is one `users` table and one `web` guard. A panel is a route group gated by role and permission.
Roles, permissions, modules and settings are stored in the database, not in code.

---

## Current state

| Phase | Scope | State |
|---|---|---|
| 0 | Laravel 12 bootstrap | Committed |
| 1 | Authentication, RBAC, module gating, admin shell | Committed |
| 2 | Admin dashboard, system settings, module management | Committed as a checkpoint, with 1020 tests green. A final re-review left some items open |
| 3 | Public website CMS (sections, menus, pages, SEO, media) | In progress. Done so far: 10 migrations, the CMS enums, services and site components. Controllers, policies, requests and page views are not built yet |
| 4–25 | Every remaining module, then hardening and deployment | Designed. The contracts are in `docs/phases/` |

`DEVELOPMENT_LOG.md` §5 is the phase tracker, and it has the final say on what is done.

---

## Stack

| Layer | What | Version |
|---|---|---|
| Language | PHP | ^8.2 (developed on 8.2.12) |
| Framework | Laravel | ^12.0 (12.69.2) |
| Database | MariaDB | 10.4 (developed on 10.4.32, XAMPP) |
| RBAC | spatie/laravel-permission | ^6.25 |
| Audit | spatie/laravel-activitylog | ^4.12 |
| Auth scaffolding | laravel/breeze (dev) | ^2.4 |
| Front end | Blade, Tailwind CSS 3 (`darkMode: 'class'`), Alpine.js 3, Chart.js 4 | see `package.json` |
| Build | Vite 7 with `laravel-vite-plugin` 2 | Node 24.18 / npm 11.16 used in development |
| Tests | PHPUnit 11 on a dedicated MariaDB schema `my_office_test` | see `phpunit.xml` |

---

## Quick start

First create the `my_office` and `my_office_test` databases (see [INSTALL.md](INSTALL.md) §1.4). Then, from
the project folder:

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

Open `http://localhost:8000/login`. The seeder prints the Super Admin email and a random password **only
once**, and that account has to change its password at first sign-in. Demo accounts for every other role
are listed in [INSTALL.md](INSTALL.md) §1.8.

The commands above are for Git Bash. Windows PowerShell 5.1 has no `&&`, so run each half of such a line
as its own command there. In `cmd`, use `copy .env.example .env` in place of `cp`. The folder name contains
a space, so quote it in every command: `cd "C:/xampp/htdocs/my office"`.

---

## Documentation

| File | What it holds |
|---|---|
| [`INSTALL.md`](INSTALL.md) | Local install, production notes and troubleshooting |
| [`DEVELOPMENT_LOG.md`](DEVELOPMENT_LOG.md) | Phase tracker, the binding decisions D1–D63, change log, test results, known issues, open questions. **Read this first** |
| [`CLAUDE.md`](CLAUDE.md) | Architecture contract: golden rules, directory layout, naming, RBAC, commission invariants, UI rules, definition of done |
| [`docs/requirements.md`](docs/requirements.md) | The full requirement specification (numbered sections) |
| [`docs/phases/`](docs/phases/) | One contract per phase (`phase-01.md` … `phase-24-25.md`): tables, services, routes, screens, acceptance tests |
| [`docs/data-model/`](docs/data-model/) | The data model in five parts: platform, website CMS, software house, finance and collaborators, institute |
| [`docs/design/`](docs/design/) | Build order, the finance and commission design, and the cross-contract consistency audits |

---

## Running the tests

The suite runs on MariaDB, not SQLite. `phpunit.xml` points it at `my_office_test` and seeds the real
RBAC fixture once for each test process.

```bash
composer test
```

`composer test` runs `php artisan config:clear` and then `php artisan test`. The config is cleared first
because a cached config would make the suite ignore `phpunit.xml`. The tests would then run against
`my_office` and wipe it. To run a subset:

```bash
php artisan test --filter=SmokeTest
```

Only one test process may run against `my_office_test` at a time (see [INSTALL.md](INSTALL.md) §3).
