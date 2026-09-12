# CLAUDE.md — Architecture contract

Software House ERP + IT Training Institute Management System. Laravel 12 · PHP 8.2 · MariaDB 10.4 ·
Blade + Tailwind 3 + Alpine. One application, one database, five panels, fully dynamic public website.

**Read [`DEVELOPMENT_LOG.md`](DEVELOPMENT_LOG.md) before doing anything** — it holds the phase tracker,
decisions, test results and open questions. Update it before you finish. The phase contract for the
phase in progress lives in `docs/phases/phase-NN.md`.

---

## 1. Golden rules

1. **Work phase by phase.** Never jump ahead; never regress a completed phase.
2. **Never destroy working functionality or real data.** Migrations are additive and reversible. No
   `dropColumn` on live data, no `migrate:fresh` outside local dev.
3. **Financial history is immutable.** A wrong commission is corrected by inserting a reversing
   negative row that references the original — never by `update` or `delete`.
4. **Money never touches a float.** Columns are `decimal(15,2)`; arithmetic goes through
   `App\Support\Money` (bcmath). No `*`, `/`, `+` on money in PHP.
5. **Commission only follows money actually received** — fee payment or installment received, client
   project payment received. Never on registration, admission or project creation.
6. **Every commission write is inside `DB::transaction()`** and is protected by a unique index on the
   source transaction, so the same payment can never pay twice.
7. **Authorization is decided on the backend.** Hiding a button is not security. Every route has
   permission middleware or a policy check; every list query is scoped to what the user may see.
8. **No hardcoded roles, permissions, menus, website text, or statuses-as-strings.** Roles and
   permissions come from the database, content from the CMS, statuses from PHP enums.
9. **Thin controllers.** Validation in Form Requests, business logic in Services, authorization in
   Policies, side effects in Events/Listeners/Jobs.
10. **Data isolation is a feature, not an afterthought.** A client, student, teacher or collaborator
    sees only their own rows. Always scope by the owning relation, never by a hidden form field.

---

## 2. Directory layout

```
app/
  Enums/                      UserStatus, LeadStatus, InvoiceStatus, CommissionStatus, ...
  Http/
    Controllers/
      Admin/                  admin panel  (/admin)
      Collaborator/           collaborator panel (/collaborator)
      Student/                student panel (/student)
      Teacher/                teacher panel (/teacher)
      Client/                 client panel  (/client)
      Site/                   public website
      Auth/                   Breeze-derived auth
    Middleware/
    Requests/<Domain>/
  Models/
    <Domain>/                 Crm/, Project/, Hr/, Finance/, Collaborator/, Institute/, Cms/, Support/
    User.php Role.php Permission.php Module.php Setting.php Branch.php ...   (core models stay at root)
  Policies/
  Services/<Domain>/          StudentCommissionService, ProjectCommissionService, ...
  Support/                    Money, PermissionRegistry, SettingsRepository, Sidebar, helpers
database/migrations|seeders|factories
docs/phases/phase-NN.md       per-phase contract (tables, columns, files, acceptance tests)
resources/views/
  admin/ collaborator/ student/ teacher/ client/ site/ auth/
  layouts/                    admin.blade.php, panel.blade.php, site.blade.php, guest.blade.php
  components/ui/              button, card, stat, table, modal, badge, toast, empty-state, skeleton
routes/                       web.php (public) admin.php collaborator.php student.php teacher.php client.php auth.php
```

---

## 3. Naming conventions

| Thing | Convention | Example |
|---|---|---|
| Table | snake_case plural | `collaborator_commission_ledger_entries` |
| Pivot | singular_singular alphabetical | `project_user` |
| Model | StudioCase singular | `CollaboratorPayout` |
| Foreign key | `<singular>_id`, indexed, explicit FK | `collaborator_id` |
| Money column | `decimal(15,2)`, default `0.00` | `commission_amount` |
| Percentage column | `decimal(8,4)` | `commission_rate` |
| Status column | `string(32)` + enum cast | `status` |
| Enum | `App\Enums\<Thing>Status`, string-backed, `label()` + `color()` | `CommissionStatus::Available` |
| Permission | `module.ability` | `student_fees.view`, `collaborator_payouts.approve` |
| Module slug | snake_case, matches the `modules.slug` column | `collaborator_commissions` |
| Route name | `panel.resource.action` | `admin.roles.edit`, `collaborator.payouts.index` |
| View | folder per panel, kebab files | `admin/roles/edit.blade.php` |
| Service | `<Domain><Action>Service`, one public entry point | `StudentCommissionService::handlePayment()` |
| Form Request | `Store<Model>Request` / `Update<Model>Request` | `StoreCollaboratorRequest` |
| Test | `tests/Feature/<Phase or Module>/...Test.php` | `Feature/Rbac/PermissionEnforcementTest.php` |

Every business table carries `created_at`, `updated_at`, `created_by`, `updated_by` (nullable FK to
`users`, filled by the `Blameable` trait).

**Soft deletes are the default, and are deliberately omitted on append-only tables.** A table is
append-only — and therefore carries **no `deleted_at`** — when it belongs to one of these categories:

| Category | Examples |
|---|---|
| Money received, returned or paid out | `student_fee_payments`, `project_payments`, `payment_reversals`, `finance_reversals`, `collaborator_payouts`, `collaborator_payout_allocations` |
| Money authorised or promised | `student_fee_discounts`, `collaborator_commission_settings` (rule versions), `collaborator_commission_entitlements`, `collaborator_commission_ledger_entries` |
| Attribution and other evidence | `collaborator_referrals`, `collaborator_referral_visits`, `lead_conversions`, `project_value_revisions` |
| Audit, log and run history | `activity_log`, `login_history`, `sitemap_generations`, `lead_import_rows`, `collaborator_wallet_reconciliations`, `blog_post_views`, `course_material_downloads`, the eleven append-only HR tables |
| Snapshots and revisions | `cms_revisions`, `seo_meta`, certificates, ID cards, payroll run items |
| Append-only children and history pivots | `invoice_items`, `time_entry_segments`, `task_comment_mentions`, `collaborator_skills`, `collaborator_service` |

Everything else — documents, profiles, catalogues, configuration rows, mutable pivots — keeps
`deleted_at`. A table without `deleted_at` is protected by a model `deleting` hook and, where its
contract says so, a `BEFORE DELETE` trigger. **Never add `deleted_at` back to one of these tables:** a
nullable `deleted_at` on an immutable ledger lets one `->delete()` hide a row from every aggregate while
the wallet cache keeps the money, and on a NULL-tolerant unique guard it silently permits a duplicate.
See `DEVELOPMENT_LOG.md` §4 **D16** (the nine financial tables, approved) and **D19** (the general rule).

**The `files` module slug governs the `attachments` table.** There is no `files` table. `attachments` is
the general store (projects, tasks, comments, milestones, tickets, replies, messages, meetings,
assignments, collaborators, invoices); five specialised stores are named exceptions because each carries
behaviour a generic table cannot — `client_documents`, `employee_documents`, `course_materials` (+
`course_material_targets`), `assignment_submission_files`, and `media_assets` for CMS images. Client
visibility is `attachments.visibility` (`internal` / `team` / `client`), never a boolean on another table.

Every `*_rate` and `*_percentage` column is `decimal(8,4)` — there is no "reported percentage" exception
(marks are `decimal(8,2)` and are not percentages). No private artefact is ever written to the `public`
disk: uploads land on a private disk and are served by a controller that re-runs the permission chain
(`DEVELOPMENT_LOG.md` §4 D21).

---

## 4. RBAC in practice

- Roles and permissions live in the DB (spatie). `App\Support\PermissionRegistry` declares the
  module-to-ability matrix and is the **only** place permission names are defined; seeders, the
  sidebar builder and the role editor all read it.
- Abilities used across modules: `view`, `view_any`, `create`, `edit`, `delete`, `restore`, `approve`,
  `reject`, `assign`, `print`, `export`, `import`, `upload`, `download`, `change_status`,
  `view_financial`, `view_reports`, `view_logs`. A module declares only the abilities it needs.
- `Gate::before` order: **(1)** deny if the ability's module is disabled (everyone, Super Admin
  included, except core modules), **(2)** allow everything for the `Super Admin` role, **(3)** fall
  through to spatie.
- Route protection: `->middleware(['auth', 'active', 'module:projects', 'can:projects.view'])`.
- Adding a module: add it to `PermissionRegistry` + `ModuleSeeder`, run the seeders (idempotent,
  never destructive), add the sidebar entry, write the policy, write the tests.

---

## 5. Commission engine invariants (phases 10–12)

- Triggered only by a **received payment** event, never by creation of a student/admission/project.
- Guard sequence: student/project has `collaborator_id` → collaborator is active → that commission
  type is enabled for them → a rule is effective on the payment date → commission base resolved from
  settings (`gross` / `net_after_discount` / `paid`, default **paid**) → no existing ledger row for
  this exact source transaction → create ledger row + wallet movement, all in one transaction.
- No collaborator reference means **no ledger row at all** (not a zero row).
- Installments generate one commission per received installment.
- Refund / reversal / cancellation inserts a negative entry referencing the original, plus an audit
  record with the reason and actor. Nothing is deleted.
- Wallet balances are a cache: they must always be re-derivable by summing the ledger. A test asserts
  `wallet.available == SUM(ledger)` after every scenario.

---

## 6. UI rules

- `layouts/admin.blade.php` (staff) and `layouts/panel.blade.php` (client/student/teacher/
  collaborator) share the same shell components; the public site uses `layouts/site.blade.php`.
- Sidebar items render only when the module is enabled **and** the user holds the permission.
- Light / Dark / System theme: `darkMode: 'class'`, preference stored on the user row and mirrored to
  `localStorage`; every colour utility must have a `dark:` counterpart.
- Every list view: search, filters, sortable headers, pagination, empty state, skeleton loader.
- Every destructive action: confirm dialog, and a `can` check on the backend.
- Feedback is a toast (`session()->flash('toast', ...)`); never a bare redirect with no message.
- Mobile first: sidebar collapses to an off-canvas drawer, tables scroll inside their own container.

---

## 7. Commands

```bash
php artisan serve
```

```bash
npm run dev
```

```bash
php artisan migrate:fresh --seed
```

```bash
php artisan test
```

After touching roles/permissions/modules/settings: `php artisan permission:cache-reset && php artisan optimize:clear`.

---

## 8. Definition of done for any feature

1. Migration written, runs forward **and** rolls back cleanly.
2. Model, relations, casts, enum casts, soft deletes, `Blameable`.
3. Form Request validation (server side) for every writable field.
4. Service holds the business logic; controller only orchestrates.
5. Permission registered in `PermissionRegistry`, checked in route middleware and/or policy.
6. Data isolation verified for every non-admin role that can reach it.
7. Blade views wired with search/filter/pagination/empty state/toast/confirm, responsive, dark mode.
8. Activity logged (and audit old/new values for sensitive or financial changes).
9. Feature test for the happy path, for the authorization failure, and — for money — for the duplicate,
   partial and reversal cases.
10. `DEVELOPMENT_LOG.md` updated (tracker, change log, test results).
