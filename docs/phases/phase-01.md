# PHASE 1 CONTRACT — Foundation, Authentication, Roles & Permissions

**Goal.** A secure, production-shaped Laravel foundation: extended user identity, database-driven RBAC
covering every module the system will ever have, module enable/disable gating, login/session/audit
tracking, five panel entry points with proven data isolation, and a premium admin shell to hang the
next 24 phases on.

This file is the **authoritative spec** for Phase 1. Column names, class names, enum cases, permission
strings and file paths below are binding — do not invent alternatives. Conventions live in
[`../../CLAUDE.md`](../../CLAUDE.md); progress is tracked in [`../../DEVELOPMENT_LOG.md`](../../DEVELOPMENT_LOG.md).

---

## 1. Database

All tables: InnoDB, utf8mb4. Business tables get `timestamps`, `softDeletes`, `created_by`,
`updated_by` (nullable FK `users.id`, `nullOnDelete`). `roles`, `permissions`, `modules`, `settings`,
`login_histories` and `activity_log` do **not** use soft deletes.

### 1.1 `users` — extend the existing Laravel table

Added by `database/migrations/*_extend_users_table.php`:

| Column | Type | Notes |
|---|---|---|
| `phone` | string(32) nullable | |
| `whatsapp` | string(32) nullable | |
| `avatar_path` | string(255) nullable | `public` disk |
| `status` | string(32), default `active`, index | cast `UserStatus` |
| `status_reason` | string(255) nullable | why suspended/deactivated |
| `status_changed_at` | timestamp nullable | |
| `theme` | string(16), default `system` | cast `ThemePreference` |
| `locale` | string(8), default `en` | |
| `timezone` | string(64) nullable | falls back to app timezone |
| `last_login_at` | timestamp nullable | |
| `last_login_ip` | string(45) nullable | |
| `password_changed_at` | timestamp nullable | |
| `must_change_password` | boolean, default false | forces the change-password screen |
| `branch_id` | FK `branches.id` nullable, nullOnDelete, index | D11 |
| `created_by`, `updated_by` | FK `users.id` nullable, nullOnDelete | |
| `deleted_at` | softDeletes | |

### 1.2 spatie permission tables

Publish the vendor migration unchanged (`php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"`),
then add columns in `*_extend_permission_tables.php`:

`permissions`: `module` string(64) index · `ability` string(32) · `group` string(64) nullable ·
`label` string(150) nullable · `description` string(255) nullable · `sort_order` int default 0.

`roles`: `label` string(150) nullable · `description` string(255) nullable · `panel` string(32)
default `admin` index · `level` unsignedSmallInteger default 50 (lower = more powerful; Super Admin 1) ·
`is_system` boolean default false (blocks rename/delete) · `is_default` boolean default false ·
`created_by`, `updated_by` nullable.

### 1.3 `modules`

`id` · `slug` string(64) unique · `name` string(150) · `description` string(255) nullable ·
`icon` string(64) nullable · `group` string(32) index (cast `ModuleGroup`) · `is_enabled` boolean
default true index · `is_core` boolean default false (core modules can never be disabled) ·
`sort_order` int default 0 · `settings` json nullable · timestamps.

### 1.4 `settings`

`id` · `group` string(64) · `key` string(128) · `value` longText nullable · `type` string(16) default
`string` (`string|text|boolean|integer|decimal|json|file|select`) · `options` json nullable ·
`is_encrypted` boolean default false · `is_public` boolean default false (readable by the public
website) · `label` string(150) nullable · `description` string(255) nullable · `sort_order` int ·
timestamps. **unique(`group`,`key`)**.

### 1.5 `branches`

`id` · `code` string(32) unique · `name` string(150) · `phone`, `email`, `city` nullable ·
`address` string(255) nullable · `is_default` boolean default false · `is_active` boolean default true ·
`sort_order` int · timestamps · softDeletes · blameable.

### 1.6 `login_histories`

`id` · `user_id` FK nullable nullOnDelete index · `email` string(255) nullable (captured for failed
attempts) · `status` string(16) index (`success|failed|logout|blocked`) · `ip_address` string(45)
nullable · `user_agent` text nullable · `device` string(64) nullable · `platform` string(64) nullable ·
`browser` string(64) nullable · `session_id` string(255) nullable index · `logged_in_at` timestamp
nullable · `logged_out_at` timestamp nullable · timestamps. Composite index (`user_id`,`created_at`).

### 1.7 `activity_log`

Publish spatie's migrations, then add in `*_extend_activity_log_table.php`: `ip_address` string(45)
nullable · `user_agent` text nullable · `device` string(64) nullable · `module` string(64) nullable
index · `reason` string(500) nullable.

### 1.8 `sessions`

Already created by Laravel (database session driver). Used read-only by the session-management UI.

---

## 2. Enums — `app/Enums/`

Every enum is `string`-backed and implements `label(): string` plus `color(): string` (a Tailwind
colour token such as `emerald`, `amber`, `rose`, `slate`), and exposes `static options(): array`
(value => label) for select inputs.

| Enum | Cases |
|---|---|
| `UserStatus` | `Active`, `Inactive`, `Suspended`, `Pending` — plus `canLogin(): bool` (true only for `Active`) |
| `ThemePreference` | `Light`, `Dark`, `System` |
| `PanelType` | `Admin`, `Collaborator`, `Student`, `Teacher`, `Client` — plus `homeRoute(): string`, `routePrefix(): string` |
| `ModuleGroup` | `System`, `SoftwareHouse`, `Hr`, `Finance`, `Collaborator`, `Institute`, `Website`, `Shared` |
| `Ability` | `ViewAny`, `View`, `Create`, `Edit`, `Delete`, `Restore`, `Approve`, `Reject`, `Assign`, `Print`, `Export`, `Import`, `Upload`, `Download`, `ChangeStatus`, `ViewFinancial`, `ViewReports`, `ViewLogs` |
| `LoginStatus` | `Success`, `Failed`, `Logout`, `Blocked` |

---

## 3. Models, traits, support classes

| File | Responsibility |
|---|---|
| `app/Models/User.php` | `HasRoles`, `Notifiable`, `SoftDeletes`, `Blameable`. Casts `status`, `theme`, datetimes. `avatar_url` accessor (falls back to a generated initials avatar). `isSuperAdmin()`, `panels(): Collection<PanelType>`, `primaryPanel(): PanelType`, `canAccessPanel(PanelType)`, `scopeActive()`, `scopeSearch($term)`. Hides `password`, `remember_token`. |
| `app/Models/Role.php` | extends `Spatie\Permission\Models\Role`; casts `panel` => `PanelType`, `is_system`/`is_default` bool; `scopeForPanel()`; `isProtected()` (true when `is_system`). |
| `app/Models/Permission.php` | extends spatie `Permission`; casts `ability` => `Ability`; `scopeForModule()`; `moduleModel()` relation on `modules.slug`. |
| `app/Models/Module.php` | casts `group` => `ModuleGroup`, `settings` => array; `scopeEnabled()`; `static enabled(string $slug): bool` (cached, cache key `modules.enabled.map`, flushed on save). |
| `app/Models/Setting.php` | transparent encrypt/decrypt on `value` when `is_encrypted`; `typedValue()` casting by `type`; flushes the settings cache on save/delete. |
| `app/Models/Branch.php` | `SoftDeletes`, `Blameable`, `scopeActive()`, `static default()`. |
| `app/Models/LoginHistory.php` | casts `status` => `LoginStatus`, datetimes; `scopeForUser()`. |
| `app/Models/Activity.php` | extends `Spatie\Activitylog\Models\Activity`; exposes the extra context columns. Registered via `config/activitylog.php` `activity_model`. |
| `app/Models/Concerns/Blameable.php` | `creating` → `created_by`, `saving` → `updated_by` from `auth()->id()` (null-safe for console/seeders). Adds `creator()`/`editor()` relations. |
| `app/Models/Concerns/LogsActivityWithContext.php` | wraps spatie `LogsActivity`; `tapActivity()` fills `ip_address`, `user_agent`, `device`, `module`; `withReason(string)` for audit entries. |
| `app/Support/PermissionRegistry.php` | **the single source of truth** for modules and permissions (§4). Pure arrays, no DB access. |
| `app/Support/Modules.php` | `enabled(slug)`, `all()`, `permissionModuleMap()` (permission name => module slug, cached), `flushCache()`. |
| `app/Support/SettingsRepository.php` | `get($group, $key, $default)`, `set()`, `all($group)`, cached in one payload; bound as a singleton; `setting('company.name', 'x')` helper in `app/Support/helpers.php` (autoloaded via composer `files`). |
| `app/Support/Money.php` | bcmath: `add`, `sub`, `mul`, `div`, `percentage($base, $rate)`, `compare`, `isZero`, `format($amount)` using the currency setting. Scale 2, round half-up. |
| `app/Support/Sidebar.php` | builds the nav tree from a declarative array; an item renders only when its module is enabled, the route exists (`Route::has`) and the user holds the permission. |
| `app/Support/Device.php` | lightweight user-agent parse → `['device' => 'desktop|mobile|tablet', 'platform' => ..., 'browser' => ...]`. No extra package. |

---

## 4. `PermissionRegistry` — module and permission matrix

Ability presets (constants inside the registry):

```
READ      = [view_any, view]
CRUD      = [view_any, view, create, edit, delete]
CRUD_FULL = CRUD + [export, print]
APPROVE   = [approve, reject]
STATUS    = [change_status]
ASSIGN    = [assign]
FILES     = [upload, download]
MONEY     = [view_financial]
REPORTS   = [view_reports, export, print]
LOGS      = [view_logs]
```

Each module entry: `slug`, `name`, `group` (`ModuleGroup`), `icon`, `is_core`, `sort`, `abilities` (a
merged preset list). Permission name = `{slug}.{ability}`; label = `"{Ability label} {Module name}"`.

**Modules to register in Phase 1** (permissions for all of them are seeded now so later phases only add
UI; a module appears in the sidebar only once its routes exist):

- **System** (`is_core = true`, never disableable): `dashboard`, `users`, `roles`, `permissions`, `modules`, `settings`, `activity_log`, `login_history`, `backups`, `global_search`
- **Software house**: `leads`, `clients`, `projects`, `project_milestones`, `tasks`, `time_tracking`
- **HR**: `employees`, `departments`, `attendance`, `leaves`, `payroll`
- **Finance**: `invoices`, `payments`, `expenses`, `income`, `payment_methods`
- **Collaborator**: `collaborators`, `collaborator_commission_settings`, `collaborator_commissions`, `collaborator_wallets`, `collaborator_payouts`, `collaborator_referrals`
- **Institute**: `course_categories`, `courses`, `course_outline`, `course_materials`, `students`, `admissions`, `course_inquiries`, `demo_classes`, `teachers`, `batches`, `timetable`, `student_attendance`, `student_progress`, `student_fees`, `installments`, `fee_discounts`, `assignments`, `exams`, `results`, `certificates`, `student_id_cards`
- **Website**: `website_sections`, `menus`, `pages`, `services`, `portfolio`, `team`, `testimonials`, `student_reviews`, `success_stories`, `faqs`, `blog_categories`, `blog_posts`, `jobs`, `job_applications`, `contact_inquiries`, `seo`
- **Shared**: `support_tickets`, `meetings`, `messages`, `files`, `notifications`, `reports`

Financial modules additionally get `MONEY`; anything with an approval flow gets `APPROVE`; anything
assignable gets `ASSIGN`; report modules get `REPORTS`.

### Collaborator-panel permissions (spec §59)

Registered with the `collaborator_portal` prefix so they can be granted to the Collaborator role
without exposing admin modules: `collaborator_portal.dashboard`, `.students`, `.student_fee_status`,
`.student_commission`, `.projects`, `.project_client`, `.project_value`, `.project_payments`,
`.project_commission`, `.tasks`, `.tasks_update`, `.files_upload`, `.files_download`, `.comments`,
`.meetings`, `.messages`, `.payout_request`, `.statement_download`.

Equivalent portal prefixes exist for the other panels: `student_portal.*`, `teacher_portal.*`,
`client_portal.*` (dashboard, profile, plus the read abilities each panel needs).

---

## 5. Roles seeded (18) — `RoleSeeder`

| Role | panel | level | is_system | Permission grant |
|---|---|---|---|---|
| Super Admin | admin | 1 | yes | everything (also bypassed by `Gate::before`) |
| Admin | admin | 5 | yes | everything except `modules.*`, `backups.*`, `roles.delete`, `settings.edit` of SMTP |
| HR | admin | 20 | no | employees, departments, attendance, leaves, payroll, own reports |
| Accountant | admin | 20 | no | invoices, payments, expenses, income, student_fees, installments, collaborator_payouts + `view_financial`, finance reports |
| Project Manager | admin | 20 | no | projects, milestones, tasks, time_tracking, clients (read), leads (read), meetings, files |
| Developer | admin | 40 | no | assigned projects/tasks (read + update), time_tracking, files, meetings |
| Designer | admin | 40 | no | same shape as Developer |
| SEO Expert | admin | 40 | no | blog, seo, website_sections (read/edit), tasks |
| Digital Marketer | admin | 40 | no | leads, blog, website_sections, course_inquiries |
| Sales Executive | admin | 30 | no | leads (full), clients (create/edit), course_inquiries, demo_classes, meetings |
| Receptionist | admin | 35 | no | course_inquiries, admissions, students (create/edit), student_fees (create), demo_classes |
| Support Agent | admin | 35 | no | support_tickets, messages, meetings |
| Institute Manager | admin | 15 | no | every Institute module + institute reports |
| Course Coordinator | admin | 25 | no | courses, batches, timetable, students (read/edit), attendance, materials |
| Teacher | teacher | 50 | no | `teacher_portal.*` |
| Student | student | 60 | no | `student_portal.*` |
| Client | client | 60 | no | `client_portal.*` |
| Collaborator | collaborator | 60 | no | `collaborator_portal.*` minus `payout_request` (admin decides via settings) |

Seeders are **idempotent** (`firstOrCreate` / `syncPermissions`) and never delete data. Permissions no
longer present in the registry are reported in the console, not deleted.

---

## 6. Authorization wiring

`AppServiceProvider::boot()`:

1. `Gate::before`: if the ability maps to a module (via `Modules::permissionModuleMap()`) that is
   disabled **and not core** → return `false` (denies everyone, Super Admin included).
2. Then: if the user has the `Super Admin` role → return `true`.
3. Otherwise return `null` so spatie resolves it.
4. Blade: `@module('slug')`, `@endmodule`, `@canAny` usage documented in the shell views.
5. `Schema::defaultStringLength(191)` is **not** set; MariaDB 10.4 InnoDB DYNAMIC handles the indexes.
   If any unique index fails, shorten that specific column instead.

Middleware aliases registered in `bootstrap/app.php`:

| Alias | Class | Purpose |
|---|---|---|
| `active` | `EnsureUserIsActive` | logs the user out with a flash message when status is not `Active`; also enforces `must_change_password` |
| `module` | `EnsureModuleEnabled` | `module:projects` → 403 when disabled (core modules always pass) |
| `panel` | `EnsurePanelAccess` | `panel:admin` → 403/redirect when the user's roles do not include that panel |
| `role`, `permission`, `role_or_permission` | spatie middleware | |

---

## 7. Authentication behaviour

- Keep Breeze's controllers/requests; **remove the register routes and views** (D15) and remove
  Breeze's "delete account" form — replaced by session management.
- `LoginRequest::authenticate()` additions: throttle by email+IP (keep Breeze), after a successful
  attempt assert `UserStatus::canLogin()` — otherwise log out, record a `blocked` login history row and
  throw a validation error naming the state ("Your account is suspended. Contact the administrator.").
- Listeners in `app/Listeners/`: `RecordSuccessfulLogin`, `RecordFailedLogin`, `RecordLogout`
  (subscribed to `Login`, `Failed`, `Logout`). They write `login_histories`, update
  `users.last_login_at` / `last_login_ip`, and log an activity entry.
- After login, redirect to `primaryPanel()->homeRoute()`.
- Password reset / email verification: Breeze defaults, restyled to the new design.
- Change password: `password_changed_at` stamped, `must_change_password` cleared, other sessions
  invalidated (`Auth::logoutOtherDevices`).
- Profile: name, phone, whatsapp, avatar upload (image MIME validated, max 2 MB, stored on `public`
  disk, old file deleted), theme preference, locale, timezone.
- Session management: list rows from `sessions` for the current user (device, IP, last active,
  current flag), revoke one or all others.

---

## 8. Routes

| File | Prefix / name | Middleware |
|---|---|---|
| `routes/web.php` | `/` | placeholder public home (real CMS in Phase 3) + `require auth.php` |
| `routes/admin.php` | `/admin`, `admin.` | `auth`, `active`, `panel:admin` |
| `routes/collaborator.php` | `/collaborator`, `collaborator.` | `auth`, `active`, `panel:collaborator`, `module:collaborators` |
| `routes/student.php` | `/student`, `student.` | `auth`, `active`, `panel:student` |
| `routes/teacher.php` | `/teacher`, `teacher.` | `auth`, `active`, `panel:teacher` |
| `routes/client.php` | `/client`, `client.` | `auth`, `active`, `panel:client` |

Registered in `bootstrap/app.php` via `then:` so all five load inside the `web` group.

Phase 1 admin routes: `dashboard`, `users` (resource + `status`, `reset-password`), `roles` (resource),
`permissions.index`, `modules.index` + `modules.toggle`, `activity-log.index|show`,
`login-history.index`, `account/profile`, `account/password`, `account/sessions`.
Each panel gets a placeholder `dashboard` route so isolation is testable immediately.

---

## 9. UI shell

- `resources/views/layouts/admin.blade.php` — fixed sidebar (collapsible, off-canvas under `lg`),
  topbar (global search placeholder, module-aware quick actions, notification bell placeholder, theme
  switcher, profile dropdown), breadcrumb bar, flash/toast region, footer.
- `resources/views/layouts/panel.blade.php` — the same shell with a slimmer nav for
  client/student/teacher/collaborator.
- `resources/views/components/ui/` — `button`, `icon-button`, `card`, `stat-card`, `badge`, `table`,
  `th-sortable`, `pagination-summary`, `modal`, `confirm`, `toast`, `empty-state`, `skeleton`,
  `form/input`, `form/select`, `form/textarea`, `form/checkbox`, `form/toggle`, `form/file`,
  `page-header`, `breadcrumbs`, `filter-bar`, `tabs`, `avatar`.
- Theme: `tailwind.config.js` → `darkMode: 'class'`, a `brand` colour scale (indigo-based), `slate`
  greys, Inter font; `resources/js/theme.js` applies Light/Dark/System from `localStorage` before
  paint (no flash) and syncs the user preference to the server.
- Toasts: `session()->flash('toast', ['type' => 'success', 'message' => '...'])`, rendered by
  `components/ui/toast` with Alpine; confirm dialogs wrap destructive forms.
- Tables: responsive wrapper with `overflow-x-auto`, sticky header, row actions dropdown, bulk-select
  placeholder, skeleton rows while loading, and an empty state with a primary action.

---

## 10. Acceptance tests (`tests/Feature/...`) — Phase 1 is not done until these pass

| Area | Test |
|---|---|
| Install | `migrate:fresh --seed` runs clean; rollback of every Phase-1 migration succeeds |
| Auth | login success, wrong password, inactive user blocked, suspended user blocked, logout, password reset flow, email verification flow |
| Login history | success / failed / logout rows created with IP and device populated |
| RBAC | a permission-less user gets 403 on each admin route; granting the exact permission makes it 200 |
| Super Admin | bypasses every permission check |
| Module gating | disabling `projects` makes its routes 403 for Super Admin too, hides the sidebar item, and leaves the data intact; core modules cannot be disabled |
| Role protection | `is_system` roles cannot be renamed or deleted; a role cannot grant a permission that does not exist |
| Panel isolation | a Student hitting `/admin`, `/collaborator`, `/teacher`, `/client` gets 403; the same for every other panel role |
| Profile | avatar upload rejects a non-image and a 5 MB file; change password invalidates other sessions |
| Registration | `/register` returns 404 |
| Audit | changing a user's role writes an activity row with old and new values plus IP |
| UI | admin dashboard, users index, roles index render in light and dark without console errors; sidebar shows only permitted items |
