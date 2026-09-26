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
| **Last updated** | 2026-09-23 |
| **Current phase** | PHASE 23 done — 33 reports, analytics, the audit trail, global search and exports all shipped and probed. PHASE 24 next |

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
| D69 | **A work shift's `start_time` / `end_time` are business wall clock; the window built from them is a UTC instant.** `ShiftWindow::fromShift()` builds the day in `Format::timezone()` and then calls `->utc()`, and `AttendanceService` files a punch under the business calendar date rather than the UTC one. | D61 stores every stamp UTC and displays it in `localization.timezone`, but `work_shifts` holds a **local** clock — "09:00" means nine in the morning in Karachi. The window was being built on a UTC clock and then compared against a punch taken with `now()`, so in Asia/Karachi every arrival was five hours late, every day was an early leave, and the register printed 14:02 for a 09:02 punch. Found by opening the attendance register in a browser after the `->format()` calls in the Blade views were replaced with `app_time()`; no unit probe had caught it, because every probe built its expectation the same wrong way. |
| D70 | **A `create_*` migration guards only the CREATE; its CHECK constraints are ensured on every run.** `up()` calls `create()` when the table is absent and `constraints()` always, each CHECK behind a `checkExists()` test. | MariaDB DDL is not transactional. An index name overflowed mid-`CREATE TABLE`, the server kept the table, the migration was recorded as failed — and the re-run's `if (Schema::hasTable(...)) return;` saw a table and skipped every CHECK. The schema then *looked* complete and was not, which is the exact failure `RawSchema`'s verify-after-write exists to prevent. Found by the §2 object verification, not by the migration. |
| D71 | **Constraint and index names on the long financial tables are explicit, and foreign keys go through `RawSchema::foreignKeyName()`** — Laravel's generated name where it fits, an abbreviated `fk_<abbrev>_<column>` where it does not. | MariaDB caps an identifier at 64 characters and Laravel composes `table_column_foreign`, which overflows on `collaborator_commission_entitlements` and `collaborator_commission_ledger_entries`. The map is explicit rather than hashed or truncated because the name has to be **stable**: `down()` must find the constraint `up()` created, and a name that shifts when a column is renamed leaves a key nobody can drop. |
| D72 | **`CollaboratorWalletService` is built in two instalments, in one class.** Phase 10 ships `lockFor()` and `applyDelta()`; Phase 12 adds `derive()`, `recalculate()`, `freeze()`, `assertConsistent()` and `payoutsPaidTotal()` to the same file. | phase-10-12 §1.3 assigns the class to Phase 12, while §6.1's wiring has `LedgerWriter` — a Phase 10 class — applying the wallet delta **inside the ledger insert's own transaction**. It has to: a committed entry whose cache write lands separately is a window in which `wallet != SUM(ledger)`, which is the one thing INV-26 and the reconciler exist to make impossible. Two classes with similar names would be the other way to resolve it, and that is the mistake the "reused, must not be re-created" rule exists to prevent. |
| D73 | **A narrow ability is not the only way a guard can be too narrow: `uq_cle_reversal_pair` forbade the case the reversal algorithm is built around.** It shipped as `(payment_reversal_id, reverses_entry_id)`; migration 22 adds `purpose`. | Spine §2.19 says two paragraphs after the index definition that one reversal legitimately posts **two** debits against one original — a `reversal` for the unpaid part and a `clawback` for the part already paid out. That is §6.6's headline row, "refund after the commission was paid out". With the narrow index the second debit is a 1062 inside the reversal transaction, the whole refund rolls back, and a refunded receipt keeps its commission. The two statements in the design document contradicted each other; the behaviour was right and the index was wrong. |
| D74 | **`uq_cle_source` carries a generated `source_guard`** — `1` for every purpose with a causing row, NULL for `manual_adjustment` and `write_off` — so the manual purposes leave the index (migration 23). | The four-column guard is NOT NULL by design ([D-FS-8]) and is what makes a second commission for one receipt impossible. A manual adjustment has no causing row, so `source_id` falls back to the collaborator and the tuple is identical for every adjustment that partner will ever receive: the first succeeds, the second is a 1062 that surfaces as a failed goodwill credit somebody has to explain. Spine §2.19 already says several are legitimate — it gives them a fresh `manual:{ulid}` key each time. The guard column is this schema's own idiom (`current_guard`, `open_guard`, `active_guard`, `default_guard`), and it exempts exactly those two purposes while every receipt and reversal keeps the guarantee unchanged. |
| D76 | **`Blameable::updatedByColumn()` may return null, and an append-only model returns it.** `collaborator_payout_allocations` is the one such table today. | The trait stamps `updated_by` on every save, and an append-only table has no such column because it has no updates to attribute (D16, D19). It never fired in the service tests, which pass an actor without signing anyone in — so the first allocation released by a **signed-in user** died on an unknown column, inside a money transaction. The allocation's one mutation is its release, already recorded by `released_by` / `released_at` / `release_reason`: three columns that say *what* changed, where a generic `updated_by` beside them would be the weaker record of the same fact. |
| D77 | **A rejected commission gives its promise back, through `unclaim()` — deliberately not `unrelease()`.** | R7 of the new reconciler found it: rejecting a commission cancelled the entry and left `released_amount` standing, so a PKR 2,000 fixed commission whose first instalment was rejected could afterwards only ever reach PKR 1,333 — money quietly lost to an act that was supposed to cost nothing but that instalment. `unrelease()` is the reversal side and raises `reversed_amount` to mirror a debit row; a rejection writes no debit, so reusing it would claim an undoing with no evidence anywhere in the ledger, which R7 would then report as a broken promise. |
| D78 | **The dashboard widget endpoint's rate limit scales with the number of cards** (60/min → 240/min). | One dashboard load is one request per visible widget. Sixty a minute was three loads at twenty cards, and §8.12 added eight more — a Super Admin who reloaded and then changed the date range would have been rate-limited out of their own dashboard. The per-widget query budget (`DashboardQueryBudgetTest`) is what bounds the cost here; the limiter exists to stop a loop, not to ration normal use. |
| D75 | **G3 asks `ReceivedPaymentStatus::earnsCommission()`, not `countsAsReceived()`.** The new method adds `refunded` to the two the old one allows. | The two questions differ by exactly one case and the difference is load-bearing. "Is this money the business has?" excludes a fully refunded receipt, correctly. "Does this receipt reach the commission engine?" includes it, because the receipt earns and its reversal posts the offsetting debit — which is what makes the order of the two jobs irrelevant. Sharing one method would mean a refund that overtook its own earning silently cancelled it, and the partner's statement would then show neither side of a transaction that really happened. |
| D79 | **`InvoiceService::issue()` guards on `invoice_number`, not on the status.** | The contract says "refused when `status <> draft`", and that is not the same rule: `statusFor()` returns `draft` while `sent_at` is null, so an invoice issued a minute ago is still a draft. Issuing it again therefore succeeded — it reserved a second number, **overwrote the first**, and left a gap in the series. The demo data made it visible (INV000002, INV000004, INV000006 for three invoices) because `markSent()` re-issues anything it sees as a draft. The thing the rule is actually about is the number, so that is what is tested; D42's "assigned once at issue, never reused" is now literally true rather than nearly true. |
| D80 | **There is no `App\Support\AgingCalculator`; `AgingBucket::forDays()` is the one implementation.** | §6.9 names a calculator class whose whole content would be a `match` the enum already owns. Two classes answering "which bucket is 47 days" is exactly the duplication the rest of the phase argues against — the SQL, the CSV and the screen all call the enum, so there is one definition, which is what the contract line wanted. |
| D82 | **A sidebar item may advertise a *list* of permissions, and-ed, and the consistency test compares the whole rule.** | `PermissionStringConsistencyTest` found it the moment the payments register shipped: the route carries `can:payments.view_any` **and** `can:payments.view_financial` — the register's whole content is amounts, so a version of it without them would be a list of reference numbers — while the item advertised only the first. That is a visible link that 403s, which is the exact failure the test exists to catch, and the test was comparing `[$permission]` against the route's full list so it could only ever pass for single-permission routes. Widening the rule is the fix; narrowing the route would have been the bug. |
| D81 | **dompdf is not installed, so `pdf` renders the print HTML and every PDF route answers 404.** | §6.8 specifies `InvoicePdfService` over dompdf. Installing a rendering engine is a dependency decision, not a Phase 13 one, and a service that returned an empty file or an HTML blob named `.pdf` would be worse than an honest refusal: somebody would attach it to an email. The print layout is shipped and is the document — `layouts/print.blade.php` is deliberately self-contained (inline CSS, an inlined logo, no Vite and no CDN) precisely so that dompdf can render it unchanged the day it arrives. |
| D83 | **The outline's cache invalidation is its own step (`CourseOutlineService::announce()`), never something riding on a recount.** | §7.10 asks that publishing, unpublishing, archiving or editing a course — and any outline change — take Phase 3's cached copy of that page out of circulation, and nothing did: three catalogue tests failed as stale `200`s, a switched-off category still serving its courses, a deactivated topic still listed, "Apply now" still offered after admissions closed. The first fix put the bump inside `recountCourse()`, which reads well and is wrong — `recountTopic()` never reaches the course, so a resource added to a published syllabus recounted perfectly and told the site nothing, and `reorder()` recounts nothing at all while changing the order a visitor reads. One named step, called by every write, is the only version of this that stays true when the fifteenth write is added. |
| D84 | **`SettingsRepository::set()`, `setMany()` and `forget()` announce `SettingsChanged`; `flush()` still announces nothing.** | The settings screen writes through `SettingsService`, which dispatches the event `PublicCache::settingsChanged()` listens for. The low-level door — console and tests, held shut for everything else by `assertLowLevelWriteAllowed()` — wrote in silence, so switching `institute.admission_open` off left every cached course page still offering "Apply now" for up to `website.cache_ttl_minutes`. `flush()` keeps its meaning deliberately: clearing a cache is not a change, and a bump there would fire on `SettingSeeder`'s closing flush and on every test that drops the payload. |
| D85 | **A syllabus resource file lands on the private disk, against this phase's own contract line.** | §2.8 says `file_path` is "`public` disk under `courses/{course}/resources/`" and, four lines earlier, that a resource which is not `is_public` needs `course_outline.view`. Both cannot be true: a file on the public disk is served by the web server with no application code in the path, so the permission is decoration, and `Storage::url()` hands out an address that stays valid after the resource is hidden, after it is deleted, and after the person shown it leaves. CLAUDE.md §3 / D21 decides it. Two controllers serve these files now — the admin one re-runs `course_outline.download`, the public one answers only for an `is_public`, `is_downloadable` resource of a course the catalogue would show and 404s on every other case. Done now because nothing linked these files yet, so it migrates nobody's data. |
| D86 | **A faked upload cannot test a content-sniffing rule.** | `UploadedFile::fake()->createWithContent()` reports a MIME guessed from the filename — precisely the value §111 refuses to trust — so FT-09 passed while proving nothing about the check it was named after. The test writes a real temporary file and hands it over with `$test = true`, so `getMimeType()` runs `finfo` over actual bytes and a `.php` renamed to `.pdf` is refused by the thing that is supposed to refuse it. |
| D87 | **A policy-guarded route's manifest row names both the permission and the policy method.** | Every earlier phase guards with `can:<module.ability>`, so a route-guard row records the literal text after `can:` and `PermissionRegistry` must declare it. Phase 14 is the first with `can:update,course`, where that literal is an ability on a model and not a permission name at all. Recording `null` would file a policy-guarded route beside a deliberately public one — the exact confusion the `rationale` column exists to prevent — so the row carries `permission` (the ability the policy requires: `courses.edit`) and `policy` (`CoursePolicy::update`, which also weighs the course's own state). |
| D88 | **The Super Admin is excluded from the inquiry round-robin.** | Spatie's `permission()` scope matches a permission held directly *or through a role*, and the Super Admin role holds every one — so the first version handed every website enquiry to the break-glass account, which is where nobody looks. It is not a counsellor with a queue. If it is the only candidate the enquiry stays unassigned, which is visible on the screen as "Unassigned" rather than quietly parked on somebody who will never open it. |
| D89 | **A follow-up on a `new` enquiry makes two moves, not one.** | §2.30.2 has no `new -> interested`, and that gap is deliberate: an enquiry that jumped straight there would have no record of ever being called. The first version picked one status and hit the transition table; the service now records what actually happened — you reached them (`contacted`), and then they said something (`interested`) — with both moves going through `changeStatus()` so the table still governs each. The outcome wins over the first-contact default, because somebody who says "not interested" on the first call is not merely "contacted". |
| D90 | **`StudentNumberService` reads its format tokens case-insensitively.** | §6.5 writes them in capitals (`{PREFIX}{YY}{SEQ:5}`) and Phase 2 seeded `registration_number_format` as `{prefix}-{year}-{seq}`. §5 says that key is "used exactly as defined, not redefined", so the two spellings have to be one instruction — an installation that saved one of them must not have its numbering break because the other was written down later. `{year}` and `{month}` are accepted as aliases of `{YYYY}` and `{MM}` for the same reason. |
| D91 | **The public admission form validates an email with `rfc` and not `dns`.** | A DNS lookup on every submit makes the form as fast and as available as somebody else's nameserver, and it rejects real addresses at domains with no MX record — on the one form where a refusal costs the institute a student. The address is confirmed by somebody ringing the applicant, which is what the phone number is for. |
| D92 | **`chk_sap_not_self_duplicate` is not a CHECK; the service refuses it.** | MariaDB rejects any CHECK that reads an `AUTO_INCREMENT` column (error 1901), so "an application is not its own duplicate" cannot be expressed there. `StudentApplicationService::markDuplicate()` refuses it instead and a test pins that — a self-reference would render one row twice on the review screen and loop anything that walks to the original. The other five CHECKs on that table stand. |
| D94 | **The room unique indexes are guarded on `room_guard`, not `active_guard`.** | `ScheduleClashDetector` skips the classroom dimension for an online class and for a virtual room, but `uq_tte_room` and `uq_cs_room_slot` knew only whether the booking was live — so two online batches naming one meeting link were allowed by the rule and then refused by a 1062 nobody could explain. A backstop that catches what the rule permits is not a backstop. `room_guard` is 1 only while the booking is live **and** its mode needs a room, and the services now refuse a virtual room for anything but an online class, so index and detector exempt exactly the same rows. Migration `120007`. |
| D95 | **A booking never clashes with its own parent.** `SlotCandidate` carries `alsoIgnore` and `ignoreGeneratedBy`. | A dated class generated from a weekly rule occupies the same hour as that rule, because it *is* that rule's occurrence — so editing a live timetable slot clashed with its own classes, and substituting a teacher clashed with its own slot. One `ignoreType`/`ignoreId` pair cannot say "this booking and its parent", and the generated classes are as many as the horizon is long, so they cannot be listed by id. Both were found by tests, not by reading. |
| D96 | **A demo that names a batch is sitting in on it, so that batch's own class is not a conflict.** | §2.16 defines `demo_classes.batch_id` as "sit in on this batch" — and a sit-in is held by the same teacher in the same room at the same hour, which every dimension would otherwise report as a clash. `SlotCandidate::$joiningBatchId` drops conflicts belonging to the batch being joined, which is the only way the one thing that column is for can ever be booked. |
| D97 | **F-9.2 is satisfied by an index the foreign key LEADS, not by a single-column one.** | The index manifest's own header says a prefix of a longer index counts as present, but Phase 15's check asked for an exact `['column']` row. Phase 16 attached three keys to `demo_classes`, whose `(classroom_id, scheduled_on, start_time, active_guard)` serves `classroom_id` perfectly — and the stricter reading would have had every future phase add manifest rows for indexes that do not exist. The assertion now matches the rule the file states. |
| D93 | **A cancelled or lost record loses its next-action date.** | A `follow_up_date` left behind on a closed enquiry puts it back into tomorrow's "due" count, where somebody works it again and rings a person who has already said no. `changeStatus()` clears it whenever the status stops being open — found by a test asserting that a finished enquiry needs no next action. |
| D98 | **Two phases claimed `admin.attendance.*`, and the route table reported both answers at once.** The institute's are `admin.student-attendance.*` and `admin.student-progress.*`. | §7.7 asks for `admin/attendance`, which Phase 7 has owned for employee attendance since long before the institute existed, and seven names matched exactly — `index`, `store`, `update`, `export`, `import`, `bulk`, `mark`. Laravel keeps the **last** registration in the name lookup and the **first** match in the URI dispatcher, so the two halves disagreed: `route('admin.attendance.index')` built the institute's URL while a request to `/admin/attendance` reached Phase 7's controller. Nothing failed — Phase 7's screen is also a `200`, so the render probe passed while probing the wrong screen. Phase 1's sidebar had already reserved `admin.student-attendance.index`, which is the name the phase now registers. The rename needed **both** halves; renaming only the names would have left the same disagreement pointing the other way. |
| D99 | **A rename by search-and-replace over a route file is a bad idea twice over, and `no_route_of_this_phase_collides_with_phase_7` is the test that says so.** | The script that fixed D98 rewrote every `admin.attendance.` it found — including `@include('admin.attendance.…')` and `view('admin.attendance.…')`, which are directories and not route names — and then, because `routes/admin.php` now imported the institute controller as `StudentAttendanceController`, it rewrote **Phase 7's own** `AttendanceController::class` references in the same file. Eight of Phase 7's employee-attendance routes silently began dispatching to the institute controller, and no test in the repository noticed: the route-guard manifest records middleware, not the action, and Phase 7 has no HTTP test over those routes. The new test asserts the action of `admin.attendance.index` still contains `Hr` — one line that would have caught both the original collision and the damage done fixing it. |
| D100 | **A TIME column is a wall clock, not an instant: `Format::clock()` / `app_clock()`.** | `NoHardcodedFormatsTest` caught the views formatting `start_time` with `Carbon::parse($t)->format('H:i')`. The obvious repair — `app_time()` — is wrong, and quietly so: it treats the value as a UTC instant and converts it to the display timezone, which moves a 09:00 class into the afternoon and, for a class at 23:30, onto the wrong day. A batch's start time is the time written on the timetable in every timezone. `app_clock()` reads the two or three fields and never converts, and every attendance and progress view uses it. |
| D101 | **`student_attendances` and `student_course_progress` each carry a `deleted_at` their unique guard does not include, so each closes the hole a different way.** | §2.24 and §2.26 both ask for the column to honour `CLAUDE.md` §3, and `CLAUDE.md` §3's own D19 warning says why that is a hazard: the guard — `uq_sa_session_student`, `uq_scp(student_batch_enrollment_id)` — does not mention `deleted_at`, so a soft-deleted row is invisible to a default query and still occupies the index. For the register the answer is refusal: `StudentAttendance::deleting` throws, so the column can never be written at all, and that lives in the **model** rather than the policy because `Gate::before` hands a Super Admin `student_attendance.delete` before any policy is consulted (INV-I10). For progress, refusing would be theatre — every row is derived and `progress:recompute` rebuilds it — so `CourseProgressService::openFor()` looks through the scope with `withTrashed()` and **restores** the row instead. Before that, `openFor()` answered `null` for a trashed row and then inserted into a 1062: the seat could never have progress again. Found by the phase's own manifest test, fixed with a behavioural test that soft-deletes a row and re-opens the seat. |
| D102 | **The attendance CSV importer is not built, and the route says so in a sentence.** | §7.7 lists an import. A register is the one import where a partial success is worse than a refusal — rows silently dropped because a name did not match the roster leave a class that looks marked and is not, and the percentage that follows is used to bar a student from an exam. `admin.student-attendance.import` is registered, guarded by `student_attendance.import`, and answers with a message pointing at the marking screen, which checks every student against the roster **for that date** (INV-I9). It takes no file, so it has no upload-manifest row; when the importer ships it brings its row with it. |
| D103 | **`InstallAndRollbackTest`'s child-process timeout is 1800 seconds, not 600.** | That test spawns a real `artisan migrate` over the whole schema three times, so its cost grows with every phase the project gains. Three runs of the same test on the same tree took **282s, 570s and over 4,800s** depending on what else the machine was doing — the first two pass, the third was killed by the old ceiling. A gate that fails because the project got bigger, or because a laptop was busy, teaches nobody anything: it trains people to re-run the suite until it goes green, which is the habit that lets a real failure through. 156 migrations take ~126s in isolation; 1800 still catches a genuine hang and no longer catches a slow afternoon. |
| D104 | **Five month captions in the monthly attendance report were formatting a date by hand, and only the cross-phase run found them.** | `NoHardcodedFormatsTest` lives in `tests/Feature/Views`, so Phase 17's own 204-test Institute run never covered it — `->format('F Y')` went in green and stayed green through every targeted run. `Format::date()` already takes a format and, unlike `instantDate()`, reads a calendar date as a calendar date rather than an instant, so `app_date($from, 'F Y')` is the whole fix and no new helper was needed. This is **not** the D100 case: there the semantics genuinely differed (a TIME column is a wall clock), here only the call site was wrong. The lesson is about the gate rather than the code — a phase's own suite is not a substitute for the cross-phase one, and "the Institute tests pass" was never the same claim as "the suite passes". |
| D105 | **`SettingsMailTestTest` pinned one of Symfony Mailer's two failure sentences, which is the network's decision and not the application's.** | The test saves `192.0.2.1:2525` — TEST-NET-1, RFC 5737, meant to be unroutable — and asserted the message contained "Connection could not be established". On this network something answers: `stream_socket_client('tcp://192.0.2.1:2525')` returns an **open socket in 0.2s**, so the SMTP handshake stalls and the transport reports `Connection to "192.0.2.1:2525" timed out` instead. Plenty of ISPs run a middlebox that accepts any TCP connection. Both sentences are the transport's own reason, which is the thing the test exists to prove — so it now asserts the message names the **saved host** and is neither of the two generic fallbacks (`TestMailService`'s "the transport gave no reason" and `SettingsController`'s "because of an unexpected error"). That holds whether TEST-NET-1 is dark or answered. The password assertions are untouched. |
| D106 | **PI-1 is a statement about a schedule that can still be paid off, so an overpaid charge is exempt.** | §6.2 and §6.3 pull against each other in exactly one case, and a test found it: a discount larger than the remaining unpaid lines. §6.2 says the unconsumed remainder is **not** forced onto a paid line — money already received is evidence, not a slot — while §6.3 states PI-1 as a flat equality over every live line. Both cannot hold. A student who paid 10,000 against a fee later cut to 5,000 leaves one live line of 10,000 that no longer sums to the net fee, and *the line is right*: "installment 1: 10,000, paid" is a true historical statement, and the 5,000 they are owed back is an advance rather than something the schedule should pretend to contain. The alternative — shrinking the paid line to 5,000 and leaving it over-allocated — would rewrite what somebody was asked to pay after they had paid it. PI-1 exists so that a plan which does not sum to the fee cannot leave a charge unable to reach `paid`; an overpaid charge has already gone past `paid`, so the risk it guards is absent. `StudentFeeService::assertPlanIntegrity()`, `fees:verify-plan-integrity` and the suite's own helper all skip the same case, and each says why. |
| D107 | **`PaymentService::recomputeCharge()` delegates to `StudentFeeService`, and its private `chargeStatus()` is deleted.** | §6.4.1 says `deriveStatus()` is *the* definition of a fee's status, called by the payment path, the discount path and the nightly sweeper alike. The spine had carried a private second copy since Phase 10, and by the time Phase 18 shipped the two had drifted in three ways — none of them a typo, all of them what happens when one question has two answers in two files. A charge covered entirely by a scholarship (`net 0.00`, nothing received) never reached `paid`: it fell past every branch to the due-date one and read `pending` or `overdue`, so a fully-funded student sat on the collection desk for ever. A part-paid charge past its due date always read `partial` and never `overdue`, so somebody who paid a tenth and then stopped never appeared on an overdue report. And `refunded_amount` was summed from `payment_reversals` **including reversals of voided receipts**, counting money that never counted. |
| D108 | **The spine's own `charge()` fixture was writing a state the application cannot produce, and D107 is what exposed it.** | `BuildsFinancialFixtures::charge()` set `discount_amount` and `net_amount` directly with no `student_fee_discounts` row behind them. That survived only because the old `recomputeCharge()` deliberately left those two columns alone; the moment one definition of the caches existed, the recompute correctly reset `discount_amount` to zero, which moved the collectible and changed a commission by 500.00. `StudentCommissionEngineTest` failed on the number, not on the shape — which is the point: a fixture that builds an impossible state stops catching the bug it was written for and starts causing different ones. It now writes a real discount row through the escape hatch and lets the caches derive. |
| D109 | **The sidebar was offering two links to routes this phase does not ship.** | Phase 1 reserved `admin.installments.index` and `admin.fee-discounts.index` long before the shape of the phase was known, and §7 ships neither: an installment and a discount are only ever read in the context of the charge they belong to, and a flat list of every installment in the institute answers no question anybody asks. They were visible menu entries that 404 — the same class of defect as **D98** pointing the other way, and found the same way, by asking whether every route a menu names actually exists. Replaced by the two screens the phase does ship (the collection desk and the reminder log), and a walk over all five panels now proves every sidebar route resolves. |
| D110 | **Phase 10's separate admin receipt template is deleted; both panels print one document.** | §8.7 asks for the receipt on Phase 13's print layout, and Phase 10's predated both that layout and §6.7.2's rules — both dates labelled, a balance carrying its own print timestamp, a VOID watermark with the reversal number, a REPRINT stamp. Two templates for one receipt is exactly where those get remembered in one copy and forgotten in the other, and the forgotten one is the copy the student is holding. `resources/views/fees/receipt.blade.php` is the document; the staff and student routes differ only in the `FeeSlipOptions` they construct, so `studentCopy()` cannot be talked out of hiding a commission figure by any setting or query parameter. |
| D111 | **`fee_reminders` and `installments` both sorted at 650, and the collision was invisible until the whole suite ran.** | A module's `sort` is also the base of every permission's `sort_order`, so one duplicated module number produced *four* duplicated permission numbers and two failing tests rather than one. The fix is one digit — `fee_reminders` moved to 665, after `fee_discounts` — but the lesson is about where it was found: `PermissionRegistryTest` lives in `tests/Unit`, and every targeted run I did during the phase filtered to `tests/Feature/Institute`. A registry is global state; a phase that adds to one has to run the registry's own tests, not only its own. |
| D112 | **A comment justifying a missing index is what broke the migration's rollback.** | `student_fee_reminders` had no plain index on `student_fee_id`, and the migration said why: `uq_sfr_dedupe` already leads with that column, so a second copy would only cost writes. That reasoning is correct about *query* performance and wrong about *constraints*. InnoDB requires an index on a foreign key column and will happily satisfy that requirement with the unique one — after which `DROP INDEX uq_sfr_dedupe` is error 1553, `down()` fails, and the migration is no longer reversible. Both install-and-rollback tests failed on it, 137s and 143s in, which is also why neither targeted run had caught it. `idx_sfr_fee` now exists, is ensured on every `up()` so an already-migrated database heals, and the comment says what it is actually for. **A composite index is not a substitute for the foreign key's own index when the composite is `UNIQUE` and you will ever want to drop it.** |
| D113 | **A multiselect default written in a different order from its options makes an untouched save look like an edit.** | `institute.fee_structure_fee_types` defaulted to `[admission_fee, registration_fee, course_fee]`, but the checkboxes render in `StudentFeeType`'s case order and therefore post back `[course_fee, admission_fee, registration_fee]`. `SettingsFormRoundTripTest` asserts that saving a group exactly as rendered moves nothing, and it correctly refused. Nothing was broken in production terms — the set is the same set — but the round-trip property is worth more than the ordering preference: it is what catches a save that silently normalises a value. **A set has no order, so it is declared in the one order the system renders.** |
| D114 | **`numeric` accepts exponent notation; `decimal:0,N` is what refuses it.** | `institute.discount_max_percentage` was declared `['required','numeric','between:0,100']` — the only decimal setting in the registry without a `decimal:` rule — so `1E1` and `2.5e1` validated, stored, and left a value no `decimal(8,4)` column can parse behind a field that looked checked. `SettingsInputHardeningTest` sweeps *every* decimal field, which is why a one-field omission surfaced as a failing test rather than as a support ticket a year later. Every decimal setting added from here carries `decimal:0,4`, and Phase 19's `assignment_late_penalty_default_percentage` was written with it from the start. |
| D115 | **The `private` disk is declared rather than aliased away.** | §6.3 binds "disk `private` is `storage/app/private`" and every `storage_disk` column in phases 19–23 defaults to it — a column defaulting to a disk that does not exist is a contract nothing can satisfy. Laravel 12 already roots `local` there, so this is the same directory under the name five contracts use, not a second location. Declaring it separately is what lets it carry `serve => false`, so no framework route can hand out a private file (§6.2 [D-19-4] puts the authorisation decision at the moment the bytes are served, never at the moment a link was minted), and `throw => true`, so a failed write is a loud 500 rather than a silent `false` that surfaces to the user as a validation error. Phases 4, 5 and 14 keep writing `local`; both names resolve to the same bytes, and `storage_disk` records which one wrote each row. |
| D116 | **Video and audio materials were impossible to upload, and the fix was the default rather than the rule.** | §5.1 defaults `institute.material_allowed_types` to all ten of §79's kinds, which is a statement that all ten work. With Phase 1's `security.allowed_file_types` they did not: video and audio offered **no extension at all**, and slides and notes collapsed to PDF — so the headline feature of `course_materials`, distributing a recorded lecture, could not be used at all. The rule "a field narrows, never widens" is what the whole gate rests on and was not touched; the Phase 1 *default* was widened to the union of what the existing upload maps already name. Every uploader passes its own map to `uploadExtensions()`, which intersects, so widening the global list **cannot widen any single field** — client documents gain nothing from `mp4` because `ClientDocumentService::TYPES` has never listed it. The size cap is deliberately left alone: `security.max_upload_mb` is 10, so a material caps at 10 MB whatever §5.1 says, which is correct per "the effective limit is `min(this, security.max_upload_mb)`" and is an administrator's decision to raise. |
| D117 | **`TeacherScope` exists because three controllers already answered "which batches are this teacher's?" three different ways.** | `BatchController` counted three paths, `ProgressController` two, `AttendanceController` a fourth the others lacked — so a substitute who covered a class could see it on the attendance screen and not on progress. Phase 19 needed the same query for materials and assignments and would have become a fourth copy. The union of all four paths — named teacher, timetable entry, taught a session, or was the teacher a session was moved *off* — now lives in `App\Support\Institute\TeacherScope`, and this phase uses it exclusively. The three existing copies are flagged for a follow-up rather than converged mid-phase: doing so widens what `ProgressController` shows, which is a behaviour change that deserves its own commit and its own line here. |
| D118 | **A 403 was confirming that files exist to people who were never their audience.** | `MaterialAccessService::resolveForStudent()` decided "was this ever theirs?" by asking whether the student had *any* enrollment at all — so every enrolled student in the institute received `enrollment_expired` for every material they could not see. That is false, and worse it is a 403 where a 404 belongs: it tells somebody a material exists and merely lapsed. The question is now asked about the material's **own course**: `enrollment_expired` (and a 403 they can act on) when they were once on it, `not_targeted` (and a 404 that says nothing) when they never were. Found by probing the grant, not by a failing test. |
| D119 | **`StreamedResponse::setCallback()` replaces the body; it does not append to it.** | `MaterialAccessService::stream()` set a bare callback to stamp `bytes_sent` once the stream finished — which would have served a perfectly healthy-looking 200 containing **zero bytes**, on every private file in the phase. The existing callback is now fetched with `getCallback()` and wrapped: the file sends first, and the stamp happens only if it returned, which is exactly the semantics `bytes_sent IS NULL` is meant to carry. A method named `set` doing what it says is not a bug in Laravel; reaching for it without reading it was one here. |
| D120 | **A path that needs an id cannot be built before the row exists.** | `AssignmentService::create()` stored the brief under `FileTarget::assignmentBrief(0)`, because §6.3 binds the path to `institute/assignments/{assignment_id}/brief/…` and the row had no id yet. `FileTarget` refused the zero rather than inventing a directory — which is precisely what it is for, and the reason it validates its segments instead of trusting callers. The row is now written first and the brief attached afterwards through `attachBrief()`, with the bytes removed if the attach fails: two short transactions rather than one long one holding a row lock across an upload. |
| D121 | **A service's `update()` must not consult the settings, and an absent key must not mean "clear".** | `AssignmentService::update()` read `institute.assignment_max_files_default` as its fallback, so an edit that did not mention `max_files` silently reset a teacher's choice to the institute default. It also used `?? null` throughout, so every key the caller did not send became an instruction to wipe that column — which destroyed `passing_marks` on a partial update. Absent now means unchanged, present-and-null means cleared, and the settings are a prefill for a **new** row only. `CourseMaterialService::update()` carried the identical hazard and is fixed with it. This is **D113** in a second place: a save that moves a value nobody asked it to move. |
| D122 | **An abandoned draft is a miss, and a miss that has content is a draft.** | `markMissed()` skipped every student with a live submission — and a `draft` is live to `uq_as_live`. A student who opened the form and never submitted was therefore neither submitted nor missed: invisible to both counts, and stuck in `outstanding()` for ever on an assignment that stopped collecting weeks earlier. Drafts are now converted in place. That in turn made `reopen()`'s force-delete of every `missed` row destructive, so a miss carrying content is restored to a draft instead of removed. Today it never carries content — `submit()` is the only writer of `submission_text` — but the branch is there so that a later save-my-progress screen does not begin quietly deleting student work. |
| D123 | **An `<input type="datetime-local">` needs a wire format, and the exception has to be named.** | `->format('Y-m-d\TH:i')` in the five date inputs tripped `NoHardcodedFormatsTest`, correctly: it is the exact shape of the mistake that test exists to catch. But an input handed a localized date renders **blank** and loses what the user was editing, so the display format is the wrong answer there. `Format::inputDate()` / `inputDateTime()` and the matching `app_input_*()` helpers make the exception explicit rather than smuggling a bare `->format()` past the scan. The display timezone still applies, so the field shows the time the reader was just shown beside it. |
| D124 | **A policy cannot protect anything from a Super Admin, and two tests were written as though it could.** | `Gate::before` allows a Super Admin everything *before* a policy is consulted, so `can('delete', $submission)` is true for them however `AssignmentSubmissionPolicy::delete()` is written. The policy docblock claimed "not for any role, not for a Super Admin" — true of the model's hook, false of the policy, and the kind of comment that stops a reader looking further. Worse, `AssignmentPolicy::delete()` refuses an assignment that has submissions, but a soft delete is an **UPDATE**, so `restrictOnDelete` never sees it: the one role most able to do damage was the only role able to hide a class's marked work. `Assignment` now refuses the deletion in a model hook, as `AssignmentSubmission` already did, and both docblocks say which layer is actually load-bearing. **A `delete` policy on a soft-deleting model guards nothing on its own.** |
| D125 | **The same index bug shipped three times, so it stopped being a decision and became a test.** | D112 was Phase 18's: `student_fee_reminders.student_fee_id` leaned on `uq_sfr_dedupe`, so `down()` could not drop that index and the migration was no longer reversible. The fix came with a decision stating the rule in as many words — *a composite index is not a substitute for the foreign key's own index when the composite is `UNIQUE` and you will ever want to drop it.* **Phase 19 then made the same mistake twice**, in `uq_cmt` and `uq_as_superseded`, one commit after writing that sentence. Both were caught by the same install-and-rollback tests, ten minutes into a thirty-minute suite. A decision nobody rereads while writing the next migration is a decision that gets made again, so the rule is now `ReversibleMigrationTest`: it walks the index names any migration that calls `dropIndex` mentions, finds each index's leading column, and fails when that column is a foreign key nothing else indexes. It is scoped to **the index rather than the table** on purpose — asking the question of every foreign key in every table that drops *any* index would flag `attendance_monthly_summaries.employee_id`, which is perfectly safe because that migration drops something else entirely. It runs in under a second instead of ten minutes, and it found exactly the two offenders and nothing else. |
| D126 | **A status column has to be wider than its own longest case, and nobody ever measures.** | `exams.status` shipped as `varchar(16)`. Six of `ExamStatus`'s seven cases fitted; `results_published` is seventeen characters, so an institute could set an exam, sit it, mark it, have a second person check it — and then get `SQLSTATE[22001] Data too long` on the one write the whole phase exists for. Nothing caught it until a probe walked the ladder end to end, because every earlier step worked. CLAUDE.md §3 already said `string(32)`, and this is why it says it: the convention is a width nobody has to think about, and the moment somebody thinks about it instead they count the case they are looking at rather than the longest one. `ResultManifestTest` now asserts, for every enum-backed column in the phase, that every case fits — and separately that status columns are exactly 32, because "it fits today" is not the rule. |
| D127 | **`SettingsRepository::asSystem()` is a write escape hatch taking a callback, not a read accessor.** | Seven reads across three controllers were written as `settings_repo()->asSystem()->get('institute.…')`. `asSystem()` takes a `callable`, throws outside the console, and returns whatever the callback returns — so every one of those calls was an `ArgumentCountError` and every result screen would have fataled on its first render. The read helper is `setting('group.key', $default)`; `asSystem(fn ($repo) => $repo->set(...))` is for a seeder or a test that needs to write. The shape is memorable precisely because it is asymmetric, and a fluent-looking `->asSystem()->get()` reads as though it were not. |
| D128 | **A registry with a `register()` hook is not wired until something calls it, and a missing source reports "clean".** | `ScheduleClashDetector` was built in Phase 16 with an explicit extension point for "exams and meetings", and `ExamService` was written calling `check()` with `ignoreType: 'exam'` — both halves present, and nothing in between. The detector scanned its three built-in tables, found no exam source, and returned a clean report, so every exam was clash-checked against classes and demos and against **no other exam**. Only an exactly equal start time was caught, by `uq_ex_batch_slot`, which is why the hole looked closed. The registration now lives in `AppServiceProvider::registerPhase20()`, and its live-status list is **derived from `ExamStatus::holdsTheSlot()`** rather than written out — the same rule `active_guard` encodes, so the index and the detector cannot come to different views of which exams hold a slot. **A negative result from a registry-driven check is worth one assertion that the registry has the entry.** |
| D129 | **`Money::compare()` rounds both sides to two decimals, so comparing a value with its own two-decimal rounding is a tautology.** | `GradeBandValidator` guarded against finer-than-2dp band edges with `Money::compare($raw, Money::round($raw, 2)) !== 0` — a condition that can never be true, because `compare()` is `bccomp(of($left), of($right), SCALE)` and `of()` rounds to `SCALE = 2`. `39.9950` passed the guard and was stored as `40.00`: an administrator had deliberately excluded 40.00 from the F band and F was given it, moving a pass boundary silently. The fix is `bccomp($raw, $rounded, 4)`. **`Money::compare` answers "are these the same amount of money", not "are these the same string"** — for a precision question it is the wrong instrument, and one that always agrees. |
| D130 | **A Form Request that accepts what the service will refuse produces a worse error than refusing it.** | `StoreGradeScaleRequest` allowed `decimal:0,4` on band edges while `GradeBandValidator` reasons at two throughout — so `39.9950` and `40.0000` came back as *"F (up to 40.00) and C (from 40.00) overlap"*, a message about two numbers the administrator can see are different. The same request validated a band's colour as a hex code, but `x-ui.badge` resolves colours from a literal map so Tailwind's scanner sees every class: a hex value is not merely off-convention, it is unrenderable, and would have shown as the fallback slate while the stored row insisted it was green. Both are the same mistake — **the validation layer inventing its own idea of the domain instead of taking the one already in the codebase.** The vocabulary was already there: `GradeScaleSeeder` and every enum's `color()` speak it. |
| D131 | **A form must not offer a field the service does not write.** | `ExamService::update()` deliberately never touches `scheduled_date`: moving an exam takes a reason and re-runs the clash check, so it has its own entry point. The shared exam form offered an editable date on the edit screen anyway — a coordinator who changed it would be told "Exam updated" and see the old date, with nothing anywhere saying why. This is D121 in a new place: **a save that quietly declines part of what it was given is worse than one that fails.** The field is now read-only on every edit, published or not, and points at the reschedule panel. A test asserts that `update()` ignores a submitted date, so the day somebody "fixes" the form by re-enabling the input, the test explains what they have actually built. |
| D132 | **Do not tighten a contract rule because it looks like a misconfiguration.** | `GradeBandValidator` allows a scale whose bands all agree — every band passing, or every band failing. That reads like an obvious bug (nobody can ever fail!), and the one-line change to require exactly one pass/fail transition was already written before the contract was reread. §6.9 says **at most** one transition, and [D-20-2] is why: when an exam carries a `passing_marks` above zero it decides the outcome outright and the bands are only a naming ladder, so a participation scale with no fail band is a real thing. Two of my own assertions had by then contradicted each other, which is the useful signal — when a new test disagrees with an old one, one of them is wrong and **the contract decides which**, not whichever was written more recently. The permissive behaviour now carries a test *and* the reason, so the next person tempted by the same one-line change reads the argument instead of making it again. |
| D133 | **A render probe proves a page does not crash, not that it says anything true.** | Phase 20's render pass drove all 24 screens through the router and got 200s and intentional 404s everywhere — and shipped views printing `teachers.employee_id`, which is a **bigint foreign key to `employees`**, not a readable staff code. An examiner rendered as a raw integer, or as an empty cell when the column was null, and neither is an error: the page was structurally perfect and factually wrong. What found it was reading every column the views touch back against `information_schema`. The two probes answer different questions and neither substitutes for the other — **a render probe is a crash test; a column sweep is a correctness test** — and a phase that ships screens wants both. The sweep is cheap: one query per column, and it also caught that every Phase 21 snapshot column matches the width of the column it copies, which is the difference between a certificate carrying a student's name and carrying the first 32 characters of it. |
| D134 | **A dropdown built from an "active" scope silently unassigns anything that has since become inactive.** | The examiner select was `Teacher::teaching()`, which filters to active teachers. Edit an exam whose examiner has left, and they are simply not in the list — so the form posts no `teacher_id`, the service reads "absent means unchanged"… except `update()` writes the whole attribute set, so absent became null. The screen then said "Exam updated" and the examiner was gone. This is D121 and D131 a third time and the pattern is now explicit: **a select that scopes its options must always include the value the row already holds**, active or not. The same applies to a retired grade scale, which INV-20-4 deliberately keeps alive so an old exam stays explicable — and would have been dropped from its own exam's edit form for exactly the same reason. |
| D135 | **Work assigned to a phase by the contract is that phase's, even when the phase closes without noticing.** | Phase 20's deliverable list includes `ExamStatisticsService`. Phase 20 shipped without it and its change log recorded that the service "belongs with Phase 23" — a sentence I wrote, plausibly, about a service nothing yet called. Phase 21's pre-flight then found `CertificateEligibilityService` and `CertificateService` both reading it, and the phase blocked before a line of it was written. It shipped as a **Phase 20 completion commit** rather than as Phase 21 work, because the history should say what the thing is: a debt from the phase that owed it, paid before the next one built on the hole. The general rule: when a later phase discovers a dependency an earlier one was contracted to provide, **finish the earlier phase first, in its own commit** — folding it into the current phase hides which phase was actually incomplete, and the next person reading the tracker sees two green ticks and one unexplained service. |
| D136 | **A pre-flight that checks what a phase leans on is worth more than the code it delays.** | Before writing Phase 21, every service, column, setting key and permission it calls was checked against the live application. It cost perhaps twenty queries and found: `ExamStatisticsService` missing entirely (D135); `GradeScale::hasBeenUsed()` not asking certificates while its docblock claimed it did; `DocumentNumberService` living in `Finance`, not `Core`; the attendance setting being `attendance_minimum_percentage` while a stored orphan called `minimum_attendance_percentage` sits in the seeder's reserved list, so reading the obvious name would have returned a default and silently ignored the institute's configuration; and `TYPE_NUMBER` storing as `integer`, which would have shaved the decimals off a `decimal(8,4)` percentage setting. Every one of those is a defect that compiles, lints, and behaves plausibly. **The Phase 19 lesson was that probing beats assuming after the code is written; this is the same lesson moved earlier** — and earlier is cheaper, because nothing has been built on the assumption yet. |
| D137 | **A sanitiser with a fixed `class` allowlist decides what a stylesheet can select, and the seeded defaults found out first.** | `PrintTemplateSeeder`'s three templates were written with the class names the layouts wanted — `.doc`, `.card`, `.hdr` — and every one of them is stripped by `RichText::sanitize()`, which keeps only the tokens in `RichText::CLASSES`. The HTML would have saved cleanly, the stylesheet would have saved cleanly, and each rule would have selected nothing: a fresh install's very first certificate prints unstyled, teaching whoever opens the editor that the editor is broken. Nothing would have gone red, because a missing class is not an error anywhere. The defaults now style by element and by the allowlisted tokens (`text-center`, `lead`, `note`, `muted`, `highlight`), the seeder **refuses to write a template whose stylesheet sanitises to nothing or whose tokens do not survive**, and `PrintTemplateTest` walks every class selector in every seeded stylesheet and asserts the sanitiser keeps it. The general shape is D130's: **the layer that writes must speak the vocabulary the layer that validates already has**, and here that vocabulary is a constant one `grep` away. |
| D138 | **Two sentences of one contract disagreed, and the code implemented both.** | §2.14's rules paragraph says the policy refuses `delete` *for every role*; §7.5's route table ships `DELETE /admin/certificates/{certificate}` annotated *drafts only — policy*. So `CertificatePolicy::delete()` allows a draft, `destroy()` calls `->delete()`, the screen offers the button — and the model's `deleting` hook threw on everything, which made that button a guaranteed 500. Neither half was wrong on its own; together they were unrunnable. **INV-21-1 is the tie-breaker, and it is about a certificate *number*:** issued once, never reused, never re-numbered, never deleted. A draft has no number, no `qr_payload` and no public page, so there is nothing of it for the invariant to protect. The hook now refuses anything that was ever a document and lets a draft go — **soft**, never hard, because `freshCode()` checks `withTrashed()` and a verification code allocated once must never be handed to a second document; `forceDelete` is refused in the model as well as the policy, since `Gate::before` walks a Super Admin past every policy before one runs (D124). The general rule this adds to D132: **when a contract contradicts itself, the invariant decides, not whichever sentence is nearer the code you are writing** — and the reconciliation gets stated in both places so the next reader finds the argument instead of the contradiction. |
| D139 | **A column list is an optimisation on a read. A read that feeds a write must be whole.** | `StudentIdCardController::bulkIssue()` loaded its enrolments with `->with('student:id,name,photo_path')` — the three columns the screen needed — and handed the result to `StudentIdCardService::issue()`, which snapshots a name, a roll number, a registration number, a joining date and a guardian's phone off that same model. Everything it had not asked for came back null, and `student_code_snapshot` is `NOT NULL`, so the first bulk issue was a 1048 from the database rather than a sentence. The single-card path was fine, because it had never narrowed the select. **The tell is the direction the model is travelling**: a partial select is safe when the model is on its way to a screen and unsafe the moment it is on its way to a service, because the service knows columns the caller was not thinking about. Found the same run as a sibling: `printData()` typed its parameter as `Illuminate\Support\Collection` and `collect([$card])` satisfied it, so `->load()` — an Eloquent method — was a `BadMethodCallException` on the single-card print. Two shapes that look alike and are not the same type. |
| D140 | **D124 for the third time: a hard rule under `Gate::before` is not a rule.** | `CertificatePolicy::print()` refuses a draft — "it has no number and no QR payload, so the document would be unverifiable the moment it left the building" — and `Gate::before` allows a Super Admin everything before a policy runs. So the one role most able to hand out an unverifiable certificate on institute letterhead was the only role nothing stopped, and the `print_count` it bumped would have been counting copies of a document that was never issued. The refusal now lives in `CertificateService::assertPrintable()`, called by `renderHtml()`, `renderPdf()` and `markPrinted()` — below the gate, on every route to a printed page. This is the same shape as D124's `Assignment` hook and Phase 19's `AssignmentSubmission` one, and three occurrences make it a rule rather than an anecdote: **when a policy sentence contains the word "never", the policy is the wrong layer.** A policy decides who may try; only the model or the service can decide what is possible. |
| D141 | **A per-phase manifest asserts what its author knew about. A discovery-based test asserts what exists.** | Phase 21's five enums shipped without `App\Enums\Concerns\HasOptions`, the trait 157 of the other enums use — so `CertificateStatus::options()` and `::values()` did not exist, and any select or filter handed one would have been a runtime error in a Blade template. `DocumentManifestTest` asserted every case had a `label()` and a `color()`, which is what I knew to check, and said nothing about the two static helpers I did not know were part of the shape. What caught it was `EnumContractTest`, which **globs `app/Enums/*.php`** rather than listing classes — its own docblock records why: "a hand-maintained list is a gate that quietly stops covering the thing it was written for". The twelve Phase 22 enums written the same afternoon had the identical gap, so one cross-phase run found seventeen. **The rule: when a phase adds an artefact of a kind the system already has many of, the test that protects it should enumerate the directory, not the phase.** A manifest still earns its place for routes and screens, which are per-phase by nature — but for enums, policies, migrations and notification classes, discovery is the only gate that stays honest. |
| D142 | **The suite could not report its own failures, and a green tick was never the point of running it.** | The first full cross-phase run after Phase 21 exhausted PHP's 512 MB while *rendering* the failure output and died without printing a summary — no test count, no duration, nothing. The cause is that an `assertSee` failure on a rendered screen dumps the **entire HTML page** into the diff, and Phase 21 added a screen test class; several failures at once is several megabytes of Blade output held in memory at the same time. The failures themselves were real and were fixed, but they had to be dug out of 2,057 repetitions of the memory error and re-run one class at a time to be read at all. **A suite that cannot tell you what broke is a suite you will stop running**, and this one takes fifty minutes, so the temptation to skip it is real. Noted rather than fixed in the same breath because the fix is a choice — a bigger `memory_limit` for the runner, a bounded diff, or screen assertions that match a fragment instead of a page — and picking one belongs with Phase 24's performance work rather than inside a phase it would silently change the behaviour of. |
| D143 | **A per-row cache has to be refreshed on every row it is a cache of, and "the latest one" is not that set.** | `messages.reads_count` is defined as the number of participants whose `last_read_message_id >= id` — a figure that belongs to **each message**. `markRead()` refreshed only the message the reader had just reached, so every message before it kept saying nobody had read it: a thread of forty messages, fully read by three people, showed a read count on the fortieth and zero on the other thirty-nine. Nothing failed; the number was simply wrong everywhere except the one place the probe would have looked if it had checked the newest message instead of the first. **The set that changed is the window between where the reader was and where they are now** — normally the handful they had unread — so that is what is refreshed, capped at two hundred so somebody returning after a thousand messages does not pay for all of them in one request. The general shape: when a cache is per-row, the question is never "which row did the user touch" but "which rows' answers changed", and those are rarely the same row. |
| D144 | **A later phase joins a shared abstraction by fitting its shape, not by widening it — and the fitting has to fail loudly at the edge.** | `ScheduleClashDetector` scans a **date** column and two **TIME** columns, because that is what a timetable entry, a class session, a demo and an exam all have. A meeting has `scheduled_at` as a datetime, so `where('scheduled_at', '<', '13:00:00')` compares a datetime with a time string and **matches nothing, silently** — the detector would have run, returned clean, and double-booked every boardroom in the building. The fix is three generated STORED columns on `meetings` that present the shape the detector already reads, not a new branch inside Phase 16's class: D47 exists because two phases sharing one piece of logic and disagreeing about it is how a rule stops being one rule. **The edge is midnight.** A 23:00 meeting lasting two hours has an end *time* of 01:00 — earlier than its start — and `start < end` then finds no overlap at all, missing every conflict including the obvious one. Clamping it to `23:59:59` makes the start day correct and leaves only the small hours of the next day unchecked, which is a bounded limitation written in the migration rather than an unbounded one nobody knows about. The general shape: **when you adapt A to B's interface, the conversion's failure mode is usually "returns nothing", and "returns nothing" reads exactly like "all clear".** |
| D145 | **An after-commit event cannot be observed from inside a transaction that is never committed — and noticing that proved more than the assertion was going to.** | The `MeetingService` probe ran `Event::fake()` and asserted `MeetingScheduled` had been dispatched. It had not: `EventFake::fakeEvent()` checks `ShouldDispatchAfterCommit` and parks the recording as a deferred callback, exactly as the real dispatcher does, and the probe's outer `DB::beginTransaction()` is rolled back rather than committed. The naive reading is "the probe cannot test events"; the useful one is that **the emptiness is itself the assertion**. The section now checks that nothing has fired *yet* — proving the service dispatched in a way that cannot notify twelve people about a meeting a later failure rolled back — then drains `app('db.transactions')->getPendingTransactions()` and checks what arrives, which proves the dispatch happened at all. Two guarantees from the fact that the first attempt failed. The same run also caught a probe writing past a switch it had just flipped: a meeting booked with `meeting_room_clash_block` off went on holding that room for every later section, so an edit four sections down failed against a fixture rather than against the rule under test. **A probe that mutates a setting owns the rows it creates under it**, and they belong on their own day. |
| D146 | **A row a queue will write cannot be patched after the fact, so the columns have to be part of the insert.** | `notifications` carries `event_key`, `module`, `level`, `url` and `actor_id` as columns, because the bell filters on every one of them and a filter on a JSON field can use no index — the table only grows (§2.25 archives, never deletes), so "unread, newest first, for this user" has to stay one indexed lookup. Laravel's `DatabaseChannel` writes four columns and none of those five, and `event_key` is `NOT NULL` with no default, so **every notification in the system was one dispatch away from a 1364** — including the fourteen Cms and Crm classes whose trait had said since Phase 4 "use the database channel once Phase 22 ships the table", and which switched themselves on the moment the migration ran. My first fix was an UPDATE straight after `dispatch()`, scoped to rows with an empty `event_key`. It cannot work, and the reason generalises: **these are `ShouldQueue`, so the row is written by a worker some time after the call returned.** The update matched nothing at all, and a later one could not have told this dispatch's rows from the next one's. `RichDatabaseChannel` merges the notification's own `databaseColumns()` into the payload the channel inserts — the channel's own keys win, so a notification cannot overwrite `id`, `type` or `data` by naming one. The rule: **when the write is deferred, every value it needs has to travel with it.** |
| D147 | **A permission is not an audience, and using one as the other hides exactly the wrong rows.** | `NotificationRegistry::forUser()` is §6.19's "events whose module is enabled and whose audience can include this user", and I first implemented the second half as `requiredPermission`. The probe reported a student seeing *more* events than a project manager, which was true and was the bug: most events declare no `requiredPermission` at all, so almost everything passed for everyone, and a student's preference screen offered to mute "a wallet disagrees with its ledger". The obvious tightening — require a permission on the event's module — is worse. **A student holds no permission whatsoever on `meetings`, `messages` or `support_tickets`**; their access runs through `student_portal.*`, and yet they are invited to meetings and raise tickets daily. That rule would have hidden the rows they most need while still showing them the ones they cannot use. So the registry says it outright: each event declares the panels it reaches. The general shape is the one D141 found from the other side — **when a check needs a fact, declare the fact rather than inferring it from a neighbouring one that was never about it.** Permissions answer "may you act"; they were never asked "could this ever concern you". |
| D148 | **A cache with two owners has one of them holding a stale answer from the moment the other writes.** | `NotificationService` kept its own copy of a user's `notification_preferences` rows, and `NotificationPreferenceService` wrote them. Within one request that is enough: the probe muted an event and the very next dispatch still delivered, because the save went to one cache and the read came from the other. Nothing failed, nothing logged, and the only visible symptom was a person receiving something they had just switched off — which reads as "preferences do not work" and is close to unreportable. The cache now lives with the writer and the reader asks for it, so `update()` and `resetToDefaults()` empty the only copy there is. The same run turned up the probe-craft version of it: `DatabaseTransactionRecord::executeCallbacks()` runs the whole array and **does not clear it**, so a probe draining after-commit callbacks by hand replays every earlier one — which had this probe reporting that a client was notified about their own reply, when what had happened was the previous reply's notification being delivered a second time. An offset per record fixes it. Both halves are the same sentence: **if something can be run or read twice, say which copy is authoritative and where the mark is.** |
| D149 | **`permissionNamesFor('module')` grants every ability that module declares, which is how a privacy rule gets undone by a helper that was only being tidy.** | §9.4 says `messages.view_any` is "granted to nobody by default" — it is the compliance reader's permission, read-only even then, and every read it allows is logged. `RoleSeeder` gave the whole `messages` module to **Admin** and **Support Agent** in one line each, because that is what the helper returns. The effect was that every support agent in the installation could read every private conversation in it: a student's thread with their teacher, a collaborator's with staff, a client's with their project manager. **Nothing about the symptom would ever have announced itself** — the threads simply appeared, to people who had every reason to think they were meant to. The §94 matrix decides who may *talk* to whom, and a blanket read makes that decision cosmetic. Phase 22's policy probe found it by asking the question the contract asks rather than the question the code implies, which is the whole reason for asking it. Three things came out of the fix and each is a rule. **First: a module whose abilities are not uniformly safe must be granted ability by ability**, with the withheld ones named in a comment, or the next person to add a role will reach for the one-liner again. **Second: a seeder cannot correct an existing install** — D65 says converge additively and never revoke, which is right, so the correction is a dated, reversible migration where it can be seen. **Third: `Gate::before` means Super Admin keeps the row, and pretending otherwise in the permissions table would be worse than saying so.** |
| D150 | **A sweep is idempotent at its source or it is not idempotent.** | `withoutOverlapping` stops two runs of a command overlapping; it does nothing about a second run ten minutes later doing the work again. Every Phase 22 sweep therefore carries its guard on the row: `first_response_breached` and `resolution_breached` on a ticket, `reminder_sent_at` on a meeting, `emailed_at` on a notification — each stamped **inside** the transaction that selects the row, with the notification dispatched **after** it commits. That order is the whole thing: stamping after the dispatch re-sends everything on the next run, and dispatching inside the transaction tells somebody about a row a rollback removed. A crash between the two loses one message and never sends two, which is the right way round. The probe is built the same way, and this is the part worth copying: it runs each sweep, asserts what moved, then **runs it again and asserts nothing moved**. Without that second run a sweep that notified on "is it past the target" would pass every test and page the assignee 144 times a day. |
| D151 | **A screen probe's own plumbing can hide two real defects, and the way to tell is that the symptom moves when you reorder the list.** | The portal screen probe reported the *first* screen of each panel redirecting to `/login`. It was not a permissions bug: `$kernel->handle()` re-resolves the auth guard against that request's fresh session and discards a login made just before it, so whichever screen happened to be first was the one that failed. Reordering the list moved the failure, which is what identified it. A warm-up request per panel fixed it — **and with that noise gone, two genuine 403s were sitting underneath**: `SupportTicketPolicy::viewAny()` had never accounted for portal users, so every portal's ticket list refused while the create form and the detail page beside it worked; and `collaborator_portal.support_tickets` had never been declared at all, though §9.4 gives collaborators tickets in the same breath as meetings and messages. Two rules. **A probe failure that is uniform across a dimension is usually the probe** — four panels failing on the same *position* rather than the same *screen* is not four bugs. And **a policy's `viewAny` and `create` must ask one question in one place**: those two had separate lists of who counts as a portal user, which is exactly how they came to disagree. |
| D152 | **A notification queued inside a *nested* transaction is silently lost, so every dispatch happens after its transaction returns.** | `NotificationService` now marks every notification `afterCommit` itself rather than trusting each caller to dispatch outside a transaction — `config('queue.connections.*.after_commit')` is false here, so INV-22-8 had been resting entirely on discipline, which is an invariant in name only. Making it real immediately broke the fee reminder, and the reason generalises: **when a savepoint commits, Laravel neither runs that level's after-commit callbacks nor hands them up to the parent — it drops them.** `FeeReminderService` dispatched from inside its own `DB::transaction()`. In production that is the outermost transaction and it would have worked; under any caller that already had one open — a command wrapping a batch, a test, a probe — the student would never have been told, with nothing logged and the reminder row sitting there saying they had been. Every dispatch in the system now happens **after** its service's transaction returns, which is what `TicketService` and `MeetingService` already did and what the six Phase 19-21 triggers were written to do from the start. The rule is not "be careful about nesting": it is **do not dispatch from inside a transaction at all**, because whether you are nested is a property of your caller and you cannot see it. The probes' drain helpers had to learn the same shape — a listener that calls `dispatch()` registers another callback while it runs, so a single drain pass delivers the listener and leaves the notification unsent, which looks exactly like a listener that did nothing. They now drain in passes until nothing new appears. |
| D153 | **A magnitude column and a signed column are two different questions, and the docblock warning you wrote does not stop you using the wrong one.** | `collaborator_commission_ledger_entries.amount` is always positive; the sign lives in the generated `signed_amount` (`CASE WHEN entry_type = 'credit' THEN amount ELSE -amount END`). I wrote the trait note explaining this and then used `amount` in four reports, so `co.commission_reversals` printed a 2,500 clawback as **+2,500** — reading as earnings — and `co.student_commission` overstated a 14,000 ledger by 5,000, because counting a reversal as a credit swings a total by twice its value. **The fix that generalises is not "be careful": it is a named accessor with the rule in it.** `signedAmount()` exists so the decision has one place to be made and one place to be got wrong, and the sweep asserts the reconciliation — both commission reports together, `co.performance`'s earned column, `SUM(wallet.lifetime_earned)` and `SUM(ledger.signed_amount)` all agreeing — rather than my having checked once. CLAUDE.md §5 already required that wallets be re-derivable by summing the ledger; this is that invariant asserted through the report layer, where it can actually be seen. |
| D154 | **A filter that answers a financial question by making rows appear and disappear hands back what the withheld column took away.** | §99 lists "has outstanding" as an ungated filter on `sh.clients`. Left that way, somebody without `clients.view_financial` — for whom INV-23-2 has already removed the Invoiced, Paid and Outstanding columns — could set it to Yes, watch the row count, and learn precisely which clients owe money. The same shape appears on `in.courses` (a fee band lets you binary-search the price) and `in.pending_fees`. **The rule: a filter is gated with the column it filters on.** More generally, whenever a control's *effect* is observable, the control needs the permission its output would have needed — hiding a value while leaving the question askable is not hiding it. |
| D155 | **Reaching an `admin.*` route is about which panel your roles are on, not which permission you hold.** | A student holds `students.view` so the portal can show them their own record. `SearchProvider::urlFor()` checked that ability and generated `/admin/students/…` links for them: the palette looked correct and every result would have 403'd. A permission can be granted to a portal role for portal reasons, so a permission check is the wrong question when the thing being decided is whether somebody can reach a panel at all. `urlFor()` now asks `User::panels()` first. The same reasoning is why a hit the viewer cannot open is rendered **without a link rather than dropped** — filtering it out would make the count disagree with the rows, and somebody searching for a record they know exists would be told there are none, which reads as "no such record" rather than "not for you". |
| D156 | **An audit trail withholds values; it never withholds rows.** | The obvious implementation of "this reader may not see financial values" hides the row, and the result is not an audit trail: somebody looking at a gap cannot tell whether nothing happened or whether they were not allowed to see what did, and the absence itself is unauditable. §107's rule is that every qualifying row is listed for anybody who may open the trail, with a `financial` row's figures replaced by a marker naming the permission that would lift it. Three consequences worth keeping. **The marker travels into the export**, because a CSV is the one document where an omission is invisible — nobody reading it can tell which cells were filtered. **An encrypted value is `[encrypted]` for everybody**, Super Admin included; the probe asserts the actual secret string appears nowhere. **The module scope is applied in the SQL, not to a page afterwards** — filtering a page makes pagination lie, so the probe asserts the *total* is zero rather than merely that the row is missing. |
| D157 | **Two concurrent test runs against one database destroy it, and the recovery is not `migrate:fresh`.** | The full suite exceeds ten minutes (D142), so two runs ended up in flight at once; killing them left `my_office_test` with tables that existed and a `migrations` table that disagreed. `migrate:fresh` could not fix it — MariaDB DDL is not transactional (D70), so a half-applied `add_crm_deferred_foreign_keys` had already created `leads_service_id_foreign`, and every retry hit errno 121. `DROP DATABASE` then failed to remove the directory because an orphaned `#sql-*` temp table from an interrupted `ALTER` was still in it. **What worked: drop every table through SQL** (which clears InnoDB's dictionary entries properly, unlike deleting files), **then load a schema-only dump of the dev database and write the `migrations` table from the file list.** Two rules. **Never start a second run against a shared test database** — and because the harness backgrounds anything over ten minutes, that means running the suite one testsuite at a time rather than whole. **A known-good schema dump is a faster recovery than a replay**, because a replay re-runs every non-transactional migration that failed halfway the first time. |
| D158 | **`appendToGroup('auth', ...)` does not append to the `auth` middleware alias - it creates a group of that name, and the group wins.** | Phase 24's session-ceiling middleware belongs in the authenticated stack, and `auth` in this application is an *alias* for `Illuminate\Auth\Middleware\Authenticate`, not a group. `appendToGroup` created a **middleware group** called `auth` containing only the new class; Laravel resolves a route's middleware by checking groups before aliases, so every route declaring `->middleware(['auth', ...])` stopped running `Authenticate` and ran the ceiling check instead. Nothing verified that anybody was signed in. **It fails open and it fails silently**: every screen still rendered, for everyone, and 271 authorization tests still passed because they act as a signed-in user. One Phase 1 test caught it - `a_guest_cannot_reach_the_verification_prompt`, asserting a 302 and getting a 200. The rule: **a middleware that belongs "in the auth stack" goes in the `web` group after `AuthenticateSession`, never in a group named after an alias** - and the general lesson is that a guest-path assertion is the only kind of test that can see an authorization layer disappear, because every other test is already authenticated. |
| D159 | **A dependent validation rule compares strictly against a real boolean, so `required_if:field,1` on a checkbox never fires.** | `backup.archive_password` carried `required_if:encrypt_archives,1` and `backup.include_env` carried `prohibited_unless:encrypt_archives,1`. `UpdateSettingsRequest` casts a boolean field to a real `true`/`false` before validating, and Laravel's `parseDependentRuleParameters` converts the literals `true`/`false` but leaves `1` a string - `in_array(true, ['1'], true)` is false, so **encryption could be switched on with no archive password at all**. `prohibited_unless` was worse: it refuses a field that is *present and non-empty*, and an unticked checkbox posts `false`, which is present - so it refused every save of the backup form whether the box was ticked or not. **The fix is two different fixes, because they are two different mistakes**: the first is a literal (`true`, not `1`), and the second cannot be expressed as a rule at all, because what must be refused is the *value*, not the presence. It lives in `SettingsRegistry::crossFieldErrors()` beside the commission-percentage rule and the scratch-database check. Both were probed against Laravel 12's own `Validator` rather than reasoned about. The round-trip test found both: **re-submitting a form exactly as it was rendered must be accepted**, and that assertion catches a rule the screen itself violates. |
| D160 | **Eloquent strict mode is the N+1 audit, and the three things it found were wrong data, not slow pages.** | `Model::shouldBeStrict(! production)` turns `preventLazyLoading`, `preventSilentlyDiscardingAttributes` and `preventAccessingMissingAttributes` on everywhere except production, which makes a thousand existing tests into an eager-loading test with no line written for them. Walking all 202 admin screens found **one** lazy load in twenty-three phases of code - the codebase eager-loads well - but `preventAccessingMissingAttributes` found four narrowed `select()`s whose missing column was read anyway, and in each case the old behaviour was a silent null rather than a slow page: a project manager with an uploaded photo was shown generated initials; `Employee::offDays()` never saw its shift's own list and fell through to the system-wide weekend, so somebody on a Tuesday-off shift was marked absent every Tuesday; the messaging recipient picker asked each candidate whether it was active, got no answer, and denied all of them. **A column you did not select does not read as null - it reads as "no answer", and every one of these treated that as a value.** In production strict mode stays off (a missed `with()` must not 500 a paying client) and the violation is logged with route and relation instead. |
| D161 | **A query-count header finds what a lazy-load exception cannot: the N+1 somebody wrote out longhand.** | `preventLazyLoading` only sees a *relation* accessed without `with()`. `QueryBudgetGuard`'s `X-Query-Count` found two N+1s it could never have caught, because both were explicit queries in a loop. `MessagingMatrix::mayStart()` asks each half of a pair whether it is a student, a teacher or a collaborator - three `exists()` queries per user, and `eitherWay()` asks about both halves, so the recipient picker asked the *initiator* once per candidate: 66 queries, of which three were the answer. `RespondsForCrm::usersHolding()` loaded every active user and called `can()` on each, two queries apiece, to build one filter dropdown - 52 queries on the clients index, growing with the payroll. Memoising the first and eager-loading the permission graph for the second took them to 13 and 17. `can()` was kept over spatie's `permission()` scope deliberately: the scope reads the grant tables and would miss what `Gate::before` decides - the Super Admin bypass and the module-disabled deny that outranks it - and the eager version was asserted to return the identical set. |
| D162 | **An error page that needs the database cannot render the failure the database caused.** | Every other layout in this application reads settings, resolves the sidebar and loads the bundle through Vite. A 500 page built on one of those is a blank screen exactly when a database is down, a cache is unreachable, or a deploy has not finished building - the three failures it most needs to render. `errors/layout.blade.php` has no `@vite`, no components and inline CSS, and its two settings reads are wrapped so a failure falls back to the framework defaults rather than throwing a second exception on top of the first. Two consequences worth stating. **No inline JavaScript anywhere on these pages**, including `onclick`: the CSP this phase ships governs inline handlers as strictly as inline `<script>`, and an attribute cannot carry a nonce - a "refresh and try again" button written as `onclick="history.back()"` would work in development and be dead in production. **The same-origin guard on the back link lives in a view composer, not the layout**, because Blade captures a child template's `@section` before the layout runs, and computing it twice would be two copies of an open-redirect check. |
| D163 | **A docblock that states a security contract is not the same as a view that honours it.** | `Message::$body` carries a note reading "`body` is plain text and is **always rendered escaped**. It never goes through `RichText`: a message is typed into a chat box by anybody with an account on any of five panels, which is the widest authorship surface in the system, and the cheapest way to be certain none of it becomes markup is for none of it to be allowed to." `ConversationService` stores it after `trim()` and nothing else, and both message views printed it with `{!! !!}`. The contract was right, thought through, written down — and unenforced, which made it worse than absent, because the next reader trusts it. `meetings.agenda` and `.notes` were the same shape. The fixes differ because the fields differ: a message is escaped and its newlines become `<br>`, while a meeting agenda is rich text like a ticket description and goes through the sanitiser **at render**, which also covers every row already stored — sanitising only on write leaves existing rows dangerous for ever. **The general rule: a promise about rendering has to be enforced where rendering happens**, and the thing that found this was not a reviewer but `security:audit` comparing nineteen raw echoes against a four-row allowlist. D25's allowlist is not paperwork; it is the mechanism that made an unenforced contract visible. |
| D164 | **A checker that cries wolf is a checker people learn to skip, so a false positive is a defect in it.** | `SecurityAuditor`'s first run reported 2 unguarded routes, 1 mass-assignment hole, 5 SQL injections, 15 unlisted raw echoes and 26 unnonced scripts — and four of those classes were partly the checker. It matched `$guarded = []` inside a docblock explaining the model does *not* use it; it read the MySQL JSON path `'$.old'` as a PHP variable; it matched its own docblock example; and it scanned Blade comments, so a view header explaining "an inline `<script>` needs a nonce" was reported as an inline script with no nonce. PHP comments are now stripped with `token_get_all()` (never a regex — a regex that strips comments eventually strips something inside a string literal, and this is a security checker), Blade comments with a pattern, and a raw-SQL match requires `{$` or `$` followed by an identifier character. **The same discipline applied to `A11y`:** it demanded a `<caption>` on every table, which WCAG does not — 1.3.1 requires structure to be programmatically determinable, which `<th scope>` provides, and the caption is technique H39. Calibrating it to "a page may have one unnamed table, two or more must say which they are" turned 58 findings into 3 real ones. An interpolation that genuinely cannot be parameterised — a column identifier — gets a written exemption naming the validator behind it, the same shape as D25's allowlist. |
| D165 | **`Assert::assertSame()` needs PHPUnit's runtime; `Assert::fail()` does not — and a test-support class that both a suite and a console command call must not need it.** | §6.5's `A11y` assertions are run by `tests/Feature/Platform/AccessibilityTest` **and** by `php artisan a11y:scan`, which is the point of putting them in a class rather than in a test. Every comparison assertion builds its failure message through PHPUnit's `Exporter`, which reads the TextUI `Configuration` registry — populated only while PHPUnit is driving. Called from the command, all thirteen died with `assert(self::$instance instanceof Configuration)`, so 91 internal errors stood where 91 findings belonged and the scan looked like a catastrophe rather than a report. Since every check already builds its own message naming the offending element — which is the whole value — the comparison was never doing any work: each now decides for itself and calls `Assert::fail($message)`, with a wrapped `assertTrue(true)` to keep PHPUnit's assertion counter honest where it is listening. **The rule: a support class shared between the suite and a command may use PHPUnit only for the one thing PHPUnit uniquely does — throwing `AssertionFailedError`.** |
| D166 | **`<template>` content is in the response and not on the page, so a scanner that walks it is measuring the wrong document.** | The analytics screen builds nine charts inside `x-if` templates. `DOMDocument` hands that markup over like any other element; a browser puts none of it in the document, the accessibility tree or the tab order, and what Alpine eventually clones has its `:aria-label` and `x-text` bindings **evaluated** rather than literal. So the source attributes are not what a user meets either — checking them is doubly wrong. `A11y::parse()` now removes template subtrees before anything runs, which also states the honest scope of this tool: **it checks the page as served, and what Alpine builds on top needs a browser** (§12 Q3's Playwright specs). The same reasoning fixed `security:audit`'s view checks, and it is why the password-reveal toggle's `x-bind:aria-label` **was** a real finding while the chart's was not — the toggle is server-rendered and unnamed until Alpine boots; the chart does not exist until then. |
| D167 | **The Tailwind palette does not meet WCAG AA with white text, and a colour token is chosen by measuring rather than by looking at it.** | `x-ui.badge`'s solid variant was `bg-{token}-600 text-white`, which reads 3.19:1 on amber and 2.94:1 on yellow — a badge is body-sized text, so 4.5:1 is the bar. Dark mode was worse: white on `{token}-500` is **1.92:1** on yellow, which is not low contrast but unreadable. Nobody noticed in twenty-three phases because the person choosing a colour can always read it. Measured across all fifteen tokens any `Enum::color()` returns: white on `-700` is 4.92:1 at worst and `slate-950` on `-400` is 6.76:1 at worst, so light darkens one step and dark inverts to a bright chip with dark text — which is what a badge on a dark surface should look like anyway. `tests/Support/contrast-pairs.php` is **generated** from the palette and from the tokens the enums actually return, so a new status colour arrives in the file rather than being remembered, and A11Y-CONTRAST fails the build on a palette edit. Two thresholds are deliberately not 4.5: a border or a badge ring is held to a visibility floor, because WCAG 1.4.11's 3:1 governs a component whose *presence* carries meaning and a ring around a badge that already has a readable label is decoration beside it; and the dark-mode primary button sits at 4.47:1 against the default indigo, recorded at 4.4 rather than silently rounded, because the brand scale is a runtime setting a business moves either way. |
| D168 | **A privilege separation that only exists in production is a privilege separation nobody tests.** | §6.9.3 splits the database into three users so the application can never change the schema: an injection that reaches the database still cannot DROP a table, disable a `BEFORE DELETE` trigger or rewrite a generated column, which are the three ways money is edited without leaving a trace. The contract has specified `mysql_migration` and `mysql_backup` since it was written, and **neither was in `config/database.php`**. Two things were already written against them and were silently inert: `BackupVerificationService::ddlConnection()` and `BackupService::dumpConnection()` both resolve the split connection *when configured* and fall back to the default otherwise — so every `CREATE DATABASE`, every dump load and every trigger restore in the HD-6 deep proof ran as the DML-only application user, where the contract's own GRANT denies all of it. `verification_status` could never reach `restore_ok`, so **GL-36 would have blocked go-live for ever**, and install step 7 would have failed with "connection not configured". It passed in local dev, because on XAMPP the fallback lands on `root`, who can do everything. Two more holes surfaced with it and are now written down in `docs/PRODUCTION.md`: the migration user had **no rights on the scratch schema at all**, so the weekly restore proof the contract asks for was denied by the contract's own GRANT block; and the nine append-only triggers carry the DEFINER of whoever ran `migrate`, so a restore by any other user needs `SUPER`, which §6.9.3 forbids — the proof must run as the user that created them, which is exactly why it resolves `mysql_migration`. **The general rule: when a control is configured away in development, the test for it has to assert the control is ON, not that the code path exists.** DEP-07 is that test — `migrate --force` on the default connection must *fail*. |
| D169 | **An allowlist that grows from three to sixty-six has stopped being an exemption and become the rule.** | SEC-14 asserts every store/update action validates through a Form Request; §11.1 sanctions an allowlist of three toggle endpoints. The suite shipped with sixty-six, which is 62.3 % compliance dressed as a passing test. It was caught by the recheck agent reading the assertion against the contract rather than against the report, and the distinction it drew is the one that matters: the test still fails if any of the sixty-six stops validating **at all**, so an unvalidated write is caught — what it no longer catches is golden rule 9's actual claim, that the rules live in a Form Request class where a Policy and a test can find them. It is recorded as T48 rather than fixed, because moving sixty-six controllers is mechanical, large, and has no business sharing a change with anything else. **What was not acceptable was the docblock**: it stated the debt was "recorded in DEVELOPMENT_LOG.md, not hidden here" while nothing had been recorded. A test that describes a decision nobody made is worse than a failing test, because it reads as settled. |
| D170 | **An artefact that vouches for itself is the one place a false claim does the most damage.** | Three separate instances landed in one round, all of the same shape. **GL-25 reported green on wallet drift**: it was a command row passing on exit 0, and `collaborators:reconcile-wallets` ends `return $failed === [] ? SUCCESS : FAILURE` where `$failed` collects only reports whose `structural()` is non-empty — a pure cache drift lands in `$drifted` and never reaches the exit code. So a blocking financial row printed `pass` while a collaborator's wallet disagreed with its ledger, on a row whose own note says drift is never rounding. It is now a suite row: `IntegrityCheckStatus::blocksGoLive()` already treats a *warning* on a financial suite as blocking, which is exactly what drift is. **GL-22 could never pass**, so `golive:check` could never exit 0 — its inspector had a FAIL branch and a NOT_CHECKABLE branch and no PASS branch, and the row carried no `manual` tick path either, making the gate that install step 19 waits on unreachable on a healthy system. It asked two questions and only one was answerable, so the inspector now answers that one (`DemoSeeder::EMAIL_DOMAIN` exists for exactly this) and the row is `manual` for the other. **And three runbooks opened by naming DEP tests that enforce their step lists**, none of which has been written — `ROLLBACK.md` called its table "verified, not aspirational", which is the exact inverse of the truth, in the paragraph that tells a reader how much to trust the thirty-seven steps below. The rule: **a gate may report `not checkable`, and a runbook may say a test is owed, but neither may report green for something nothing ran.** A gate that cannot open is the second failure mode and it is nearly as bad — people stop running it. |
| D171 | **Four models added to PRF-05's small-reference-table exemption: `Branch`, `WorkShift`, `TicketDepartment`, `PortfolioCategory`.** | phase-24-25 §6.4 enumerates seventeen models whose listings may be read whole, and §11.7's scan fails every other unbounded `::all()` / `->get()` / `->cursor()` in an index, board, calendar or export action. PRF-05's first real run reported 44. **Thirty-seven were fixed in the controllers** — seventeen filter `<select>`s and eighteen listings took an explicit `->limit()`, and the two reads that must stay exact (the attendance export and the student result summary) became `chunkById` walks. The remaining seven are these four models, and the test for whether a table belongs on that list is not how many rows it has today: it is **whether its size is decided by a person maintaining a list or by the system being used.** A branch is opened by the business; a work shift is defined by HR; a ticket department is a support desk somebody staffs; a portfolio category is website taxonomy an editor curates. None grows with traffic, admissions, invoices or tickets. Each also sits beside a sibling the contract already names — `classrooms` and `leave_types` are HR configuration exactly as `work_shifts` is, and `blog_categories` is site taxonomy exactly as `portfolio_categories` is — so the seventeen were a list of examples, not an exhaustive census. **What was not acceptable was adding them silently.** The file's own rule says a model joins that list by a numbered decision and never "as a way to quieten a failing scan", and for one round the four were in the list with no number behind them. The fixer flagged them rather than hiding them, which is why this is a record being written and not a finding being argued. |
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

Contract: [`docs/phases/phase-08-09.md`](docs/phases/phase-08-09.md) · **foundation built 2026-09-20;
registries, services, policies, routes and screens still to come**

| | Item |
|---|---|
| [x] | Enums: all 8 of §3 / 51 cases across both phases — `CollaboratorStatus`, `CollaborationType`, `PayoutAccountStatus`, `CollaboratorActivityEvent` (with the `visibleProperties()` allowlist, so a partner's feed can never leak an internal note), `ReferralVisitOutcome`, `ReferralCandidateChannel` (with `rank()` and `ladder()` for §6.3's precedence), `ReferralConversionSubject`, `ReferralAttributionModel`. Every `label()` / `color()` arm walked |
| [x] | Schema: 7 migrations / 4 tables, applied to `my_office_test` **and** `my_office`; `rollback --step=7` leaves nothing behind and re-migrate is clean. The `collaborator_service` pivot and all nine FK promotions are `Schema::hasTable()`-guarded and log loudly when they skip, so `migrate` and `migrate:fresh` are legal in any order ([D-FS-1]) |
| [x] | §2.5a and [D-P6-1]: the nine deferred FK columns earlier phases shipped unconstrained are now real foreign keys — `activity_log.collaborator_id`, `contact_inquiries.collaborator_id`, five Phase 6 columns (`projects`, `project_members`, `tasks`, `time_entries`, `time_entry_segments`, all **RESTRICT**), and the two `referral_visit_id` columns on `contact_inquiries` and `leads`. Pre-existing orphans are nulled by a **reported** pre-pass and never deleted, because a silent repair of attribution data is what INV-R1 forbids |
| [x] | §2 object list verified against `information_schema` on both databases: **40/40** — 4 tables, the §2.1 column list exactly (and no `branch_id`, [D-P8-2]), 5 named unique indexes, 2 CHECKs the server actually kept, 9 FKs with the right delete rules, every clock column `DATETIME` and **0** columns silently carrying `ON UPDATE CURRENT_TIMESTAMP` (D67), and no `deleted_at` on the three append-only tables (D19) |
| [x] | Models: `Collaborator`, `CollaboratorSkill`, `CollaboratorReferralVisit`. Proved on **45 assertions** in a rolled-back transaction: INV-C1 and INV-C2 both refusing a bare save, the four unique guards, INV-C4 (only `active` and untrashed earns; a soft delete stops it), `canLogin()`, the derived skill slug making a re-submitted form idempotent, the services pivot attaching and detaching, an expired / bot / dead-code / already-spent visit never attributing **and the query scope agreeing with the loaded row every time**, both CHECKs biting, and the RESTRICT wall refusing to force-delete a partner who has a visit |
| [x] | §4 PermissionRegistry: 2 new slugs (`collaborator_payout_accounts`, `collaborator_referral_visits`), `approve` / `reject` / `view_logs` added to `collaborators`, `create` / `edit` / `change_status` / `view_logs` to `collaborator_referrals`, and 6 new `collaborator_portal.*` — **101 modules / 994 permissions**. Nothing was removed: `assign` and `upload` on `collaborators` stay because taking an ability away revokes a permission an administrator already granted (D65) |
| [x] | §5 SettingsRegistry: the 16 new `collaborator.*` keys, no new group and no Phase 2 or spine key redefined — **309 settings in 17 groups**. `collaborator_code_next_number` is `readonly` (D62): a settings form posts every field, and a stale counter would re-issue a code somebody already quotes |
| [x] | §4.3 role grants, idempotent: **Accountant** gains `collaborator_payout_accounts.*` (whoever pays a partner registers where the money goes); **Sales Executive** and **Receptionist** gain `collaborators.view_any` + `.view` + `collaborator_referrals.create` and deliberately **no** `view_financial`; the **Collaborator** role picks up the six new portal permissions through the existing "everything except `payout_request`" rule |
| [x] | D65 convergence on the dev database, twice: first run **+2 modules, +16 settings, +37 grants, 0 changed, 0 revoked**; second run **0 / 0 / 0**. Backed up first |
| [x] | §6.1 `CollaboratorCodeService`: the one normaliser (`col-1024`, ` COL-1024 ` and `COL--1024` are one code), the `%04d` counter through **Phase 5's** `DocumentNumberService` (D27 — no second `FOR UPDATE` counter exists), `assertAvailable()` whose message names nothing about the holder, INV-C2's lock walked over all three referencing tables with a `hasTable()` guard each, and `referralUrl()` appending to an existing query string instead of overwriting it |
| [x] | §6.2 `CollaboratorService` and `CollaboratorOnboardingService`: create / update / `syncSkills` / `syncServices` / soft delete with a mandatory reason; §6.2.2's transition table on the enum (`allowedTransitions()`), approval, rejection landing on `inactive` rather than inventing a fifth status, and login provisioning **through Phase 1's `UserService`** so there is one place a user is created |
| [x] | §6.6 `CollaboratorActivityService`: one audit store (D13), filtered by the indexed `collaborator_id` and **not** by causer — the rows a partner most wants to see are written by the engine with a null causer. The feed is an allowlist filtered **twice**, at write and at read, and `reason` lives in its own column so a staff sentence about a collaborator is structurally unreachable from that collaborator's screen |
| [x] | §6 proved on **77 assertions** in a rolled-back transaction: the four normalisation cases, the four format refusals, the counter, pending-by-default, an approver creating an active record, a vanity code normalised and then refused to a second taker, the code freezing the moment a visit names it, both URL shapes, all five transition edges, a suspension ending the live session and locking the login, reinstatement, a rejection with no reason refused, and the allowlist dropping a key that was never meant to travel |
| [ ] | §6.2 `CollaboratorPayoutAccountService` and §6.6 `CollaboratorPortalMetricsService` — both write or read **spine** tables that ship with Phase 10. Deferred to that release rather than written untested against a table that does not exist (§1.4 [D-P8-1]) |
| [x] | §6.4 `ReferralTrackingService`: the click record and the carrier. A refused code is a **named outcome, not a missing row** — `invalid_code`, `collaborator_not_eligible`, `self_referral`, `bot_filtered` — one row per visitor-code pair with `visits_count` growing, and a conversion stamped exactly once |
| [x] | §6.4 `ReferralLinkService` + the `CaptureReferral` middleware, on every public stack a person can land on and on no panel group. The cookie is encrypted, `httpOnly`, `sameSite=lax`, and carries a **visit token, never a code** (INV-R2) |
| [x] | §6.3 `ReferralAttributionResolver`: all six ranks, the five modifiers, losers kept with their reasons, the `referral.decided` payload, and effective dating floored at the click and capped at today |
| [x] | §7.6 `POST /referral/validate` (throttled 10/min) and `<x-site.referral-field>`. A dead code and a suspended partner's code answer **identically**, so the endpoint cannot be used to find out who has been suspended |
| [x] | §7.3 / §8.6 the visit register, the conversion report and the dead-code panel — read-only, with the IP masked to a /24 unless the viewer holds `collaborator_referral_visits.view_logs` |
| [x] | §6 proved on **58 assertions** in a rolled-back transaction, and the screens checked in a browser against the dev database with all four capture paths walked through real HTTP |
| [x] | Integration gate `tests/Feature/Collaborator/ReferralCaptureTest.php` — **18 tests**: the middleware, the cookie shape, a dead code kept as evidence, one visit per partner, a crawler filtered, the public validator's three-key answer, the ladder's ranks 1-6, a forged token, an expired visit, effective dating, the audit payload carrying no money, and the register's IP masking |
| [ ] | §6.5 linking and re-linking, and the change-attribution wizard (§8.8) — both write `collaborator_referrals`, which is the **spine's** table and ships with Phase 10 |
| [x] | §7.1 routes: 18 admin routes — the list, the approval queue, create / edit / soft delete / restore, approve / reject / status, login provisioning, the throttled referral-code change, the referral-links screen, the activity trail, the throttled picker and the CSV export |
| [x] | §9 `CollaboratorPolicy`: missing ability → 403; holding it but not reaching the row → **404** (`denyAsNotFound`); `delete` additionally refused while commission or a payout is in flight; `forceDelete` refused outright — **and repeated as a model `deleting` hook**, because `Gate::before` allows a Super Admin everything and never reaches the policy at all |
| [x] | §8 screens: 7 Blade views — the filtered list, the applications queue with its stale-application flag, the record with its code-lock state, the shared create / edit form, the referral-links screen with a live preview, and the audit trail |
| [x] | §2.5 / §13: `tapActivity()` stamps `activity_log.collaborator_id` for any model that claims a collaborator, so §60's feed is a filtered view over the one audit store (D13) and not a second table |
| [x] | Checked in a browser against the dev database with three partners seeded in — an approved agency with a login and a captured visit, a freelancer waiting for a decision, and a suspended sales partner. The edit form was submitted through the UI and the skill set replaced correctly |
| [x] | Integration gate `tests/Feature/Collaborator/CollaboratorManagementTest.php` — **20 tests / 77 assertions**: every screen's permission, module gating denying a Super Admin, 404-not-403, the picker's five columns and its refusal to offer a suspended or removed partner, create → approve → login, a rejection needing a reason and inventing no fifth status, INV-C4 across all four statuses, a suspension ending the live session, the closed transition table, INV-C2's lock, a taken code refused without naming its holder, the query string kept, the soft delete, and the activity allowlist |
| [ ] | §7.2–§7.5 the payout-account screens, the commission-rule screen and the collaborator panel — all read or write **spine** tables that ship with Phase 10 |
| [ ] | §10 events, notifications and jobs, §11's named acceptance cases FT-C01 … FT-C29 |

> **Release note** — note: the financial spine's migration set (spine §1.3, 15 tables) is applied in the same release, immediately after Phase 8's own migrations; the spine-dependent screens stay hidden behind their module switches until then, and `collaborators:backfill-wallets` + `collaborators:seed-initial-rules` run once afterwards (phase-08-09 §1.4 [D-P8-1]).

### [ ] PHASE 9 — Referral codes, referral URLs, referral tracking
### [ ] PHASE 10 — Student referral commission engine (`StudentCommissionService`)

Contract: [`docs/phases/phase-10-12.md`](docs/phases/phase-10-12.md) over
[`docs/design/finance-commission-spine.md`](docs/design/finance-commission-spine.md) ·
**schema, enums, services and the commission screens built 2026-09-20; the commands and the acceptance suite still to come**

| | Item |
|---|---|
| [x] | Enums: the 27 of §3, string-backed with `label()` / `color()` / `options()`. `PaymentMethod`, `LedgerEntryType` and `CommissionCalculationType` are **reused, not re-created** (F-5.4, F-5.5). `CommissionRuleSource` has three cases and deliberately no `global_default` ([D-FS-9]): a commission paid because nobody set a rate is one nobody decided on |
| [x] | `EnumContractTest` now **discovers** every enum instead of naming six — **785 assertions across 157 enums**. Broadening it found 10 enums claiming the colour token `zinc`, which `x-ui.badge` does not define, so those badges had been silently falling back to slate since they were written |
| [x] | Schema: the 21-file atomic set / 15 tables, applied to `my_office_test` **and** `my_office`; `rollback --step=21` leaves **0 tables and 0 triggers** behind and re-migrate is clean ([D-IMP-1]: creates declare columns only, every FK arrives in files 18, 20 and 21) |
| [x] | §2 object list verified against `information_schema` on both databases: **126/126** — 15 tables, 9 STORED generated columns, 32 CHECK constraints, 31 named unique indexes, 9 `BEFORE DELETE` triggers, `idx_cr_superseded_by` present and **not** unique (ND-12), no `deleted_at` on the twelve append-only tables, every money column `decimal(15,2)` and every rate `decimal(8,4)`, and **0** columns silently carrying `ON UPDATE CURRENT_TIMESTAMP` (D67) |
| [x] | Every guard proved to bite on **58 assertions** in a rolled-back transaction: one wallet per collaborator and a negative available balance still legal after a clawback; one **active** referral per subject with superseded rows stacking freely and several losers pointing at one winner (ND-12); one open rule version per scope; a rule refused without the number it needs; one current entitlement per document; INV-12 refusing an over-release; `uq_cle_source` refusing a second commission for one receipt; the sign, reversal, debit-clean, allocation (INV-11) and undo (INV-10) ceilings; `uq_cp_txn` refusing one bank transaction twice; `uq_cpa_pair` refusing an entry back into the same payout **even after release**; `uq_sf_generation` refusing a doubly-generated charge while hand-entered ones stack; `net_received_amount` following a refund; and all four no-delete triggers |
| [x] | §2.3 the 15 models, verified against the live schema: **201 assertions over 141 relations** — no cast on a column that does not exist, nothing fillable that is not a column, no generated column reachable through mass assignment, and every relation resolving to a real table |
| [x] | The write guards, proved on **52 assertions**: seven models refusing an insert outside their owning service and naming it; `allowDirectWrites()` letting a factory through, closing again, and closing **even when the callback throws**; INV-8 refusing a receipt's amount and value date; INV-4 refusing a commission's amount, rate and source; INV-17 refusing a rate change; three no-delete refusals; the entitlement capping a release and reporting `null` rather than a figure when uncapped; the referral window including and excluding by date and by `commission_eligible`; the wallet's R2 identity holding and failing; and an allocation returning to available on a cancellation but not on a reversal |
| [x] | §4 PermissionRegistry: 4 new money modules (`student_fee_payments`, `project_payments`, `payment_reversals`, `wallet_reconciliation`), `Ability::LinkInvoice` (D43's single narrow permission — no preset, on one slug, held by the **Accountant** alone), `create`/`APPROVE`/`LOGS` added to the commission slugs, and `collaborator_portal.wallet` / `.payouts` — **105 modules / 1,037 permissions**. Every money module has **no `edit` and no `delete`, for ever** (INV-8, INV-5) |
| [x] | §5 SettingsRegistry: the 24 keys across the existing `collaborator`, `institute` and `finance` groups, no new group. Three more `*_next_number` counters, all `readonly` (D62) |
| [x] | §6 services: `ReferralService`, `CommissionRuleService`, `CommissionBaseResolver`, `CommissionEntitlementService`, `CommissionCalculator`, `LedgerWriter`, `CollaboratorWalletService` (the `applyDelta()` half — D72), `StudentCommissionService`, `CommissionApprovalService`, `CommissionReversalService`, `Finance\PaymentService` (student side), the shared `RunsCommissionGuards`, 15 DTOs, 6 events, 2 listeners and 2 jobs |
| [x] | §7 routes: the 14 `admin.commissions.*` / `admin.commission-rules.*` / `admin.commission-skips.*` routes. **No `edit` and no `destroy` on the ledger** — the module declares neither, and `create` exists for the manual adjustment alone |
| [x] | §8 screens: the commission ledger with its pending-approval queue and bulk bar (explicit ids, page-only select-all, skip-and-report), the entry detail with the Calculation / Related / History tabs rendering the `rule_snapshot` trace, the skip report grouped by reason with an audited "evaluate selected", and the rule timeline with the immutable-version wizard and its live comparison preview |
| [x] | §7.1 / §8.1 / §8.2: the eight `admin.fee-payments.*` routes, the register with its value-date / system-date toggle and filtered-set footer, the receipt detail with its refund and void modals, the printable receipt, and the read-only commission preview. **No `edit` and no `destroy`** (INV-8): a mis-keyed receipt is voided and re-entered |
| [x] | §10.4 the five commands and their scheduler entries: `commissions:sweep` (every 10 min, **never touches a `skipped` row** — [D-IMP-4]), `commission-rules:activate` (00:05), `commissions:release-held` (00:10), `financial:verify-constraints` (02:00, **90/90 objects** on both databases) and `commissions:evaluate`, which is deliberately **not** scheduled |
| [x] | §11 the acceptance suite so far: `tests/Feature/Financial` — **48 tests / 300 assertions**. FT-01 … FT-05 of §120, the §11.2 money and rounding cases, the §11.3 rule and attribution cases, §11.4's wiring and immutability, and §11.6's authorization. Every money test ends with `assertWalletMatchesLedger()` |
| [ ] | §11's remaining rows: the concurrency cases (two workers, two payouts), the discount-supersede cases (need Phase 18's `StudentFeeService::addDiscount()`), and everything project-side or payout-side — Phase 11 and Phase 12 |
### [ ] PHASE 11 — Project referral commission engine (`ProjectCommissionService`)

Contract: [`docs/phases/phase-10-12.md`](docs/phases/phase-10-12.md) · **engine built 2026-09-20; the
register and the widgets still to come**

| | Item |
|---|---|
| [x] | `ProjectCommissionService` — the **same** G0-G11 / C1-C8 sequence as the student side, through the shared `RunsCommissionGuards`. Four things differ and they are the four it supplies: the attribution is on the **project** (a client referral deliberately does not earn on every project that client later commissions), the per-project override of §45, G10's milestone guard, and the two extra base modes |
| [x] | `PaymentService::recordProjectPayment()` on the **same class** as the student side — a refund, a void and an approval are the same act whichever table the money came from. `is_advance` is derived from the absence of an invoice, and `client_id` is denormalised from the project rather than taken from the form |
| [x] | `ProjectPaymentRecorded` + `QueueProjectPaymentCommission` + `ProcessProjectPaymentCommission`, identical queue contract to the student job |
| [x] | §11.1 FT-06 … FT-08, §120.6 / §120.7 and the screen suite: **18 tests**. 15% of 100,000 = 15,000.00; a `total_value` base on a 200,000 project promises 30,000.00 and releases 15,000.00 twice and **no more**; a refund preserves the original; a project override beats the partner's rate; a disabled partner rule beats the override |
| [x] | §7.2 / §8.2: the seven `admin.project-payments.*` routes, the register with its value-date toggle, advance filter and filtered-set footer, the payment detail with refund and void modals, and the printable copy. **Every route carries `module:project_payments`**, never phase-13's `payments` umbrella (F-6.1) — a test walks the router and asserts it |
| [x] | The payment trail on the project detail screen. `$payments` is **null, not empty**, when the viewer may not read receipts — "nothing yet" and "not yours to see" are different facts, and a card that rendered both as an empty list would answer a question it was not asked. It shows the **net** figure, not the gross |
| [ ] | The project commission widgets (§8.12) |
### [ ] PHASE 12 — Collaborator wallet, commission ledger, payouts, statements

Contract: [`docs/phases/phase-10-12.md`](docs/phases/phase-10-12.md) · **the four services, every
admin and panel screen, and the eight widgets landed 2026-09-21. One §7.5 route is outstanding and
waiting on a table that does not exist yet.**

| | Item |
|---|---|
| [x] | `CollaboratorWalletService` completed (D72's second instalment, one class): `derive()` running spine §6.5.1's two queries verbatim, `recalculate()` which rewrites the cache and **never touches a ledger row**, `freeze()` (which stops payouts without stopping earning — two genuinely separate decisions), `assertConsistent()`, `payoutsPaidTotal()` and `liabilityTotals()`, both with ND-6's company-wide form as one query rather than a loop |
| [x] | `WalletSnapshot` — the **only** definition of a balance in the system (INV-26), carrying the §6.5.2 closed identity, the per-column `differencesFrom()` a drift report needs, and `driftFrom()` |
| [x] | `PayoutService` — FIFO allocation claimed by **compare-and-swap** (`allocated_amount + slice + reversed_amount <= amount` checked *inside* the UPDATE), the payout's amount derived from what it actually claimed (INV-22), and `releaseForReversal()` closing the Phase 10 seam so a refund is never blocked by a pending withdrawal (§6.6 row 13) |
| [x] | `AllocationDelta`, deliberately not `LedgerDelta`: the §6.5.1 identity splits at the payout, because `payable_total` comes from the ledger while `reserved` / `paid` come from live allocations |
| [x] | `CommissionReconciliationService` — the eight §6.5.3 checks in two severities, a row written every run whether it passed or not, and `WalletDriftDetected` for the ones that need attention. Never repairs a structural failure (§6.5.4) |
| [x] | `collaborators:reconcile-wallets`, scheduled daily at 01:30 **without `--repair`**: a scheduled auto-repair would erase the evidence of whatever caused the drift |
| [x] | The acceptance suite's shared helper now runs all eight checks, so every money test in the suite gained the other seven without one of them being edited |
| [x] | `CollaboratorStatementService` — the §6.5.5 identity asserted against the same balance re-derived one day later, and a refusal to render rather than an unbalanced document. Filters narrow the rows, never the balances |
| [x] | Admin screens: wallet register and detail (the derivation **beside** the cache), reconciliation history and detail, payout register, wizard with its read-only FIFO preview, detail, voucher and CSV, payout destinations (masked, verified by somebody else), statement + print/PDF/CSV, and the §8.8 discrepancy queue |
| [x] | Collaborator panel: wallet, commissions, statement + exports, payouts (ask, view, withdraw own), payout accounts, referred projects — every query scoped through the session, and another partner's row a **404** rather than a 403 |
| [x] | The eight §8.12 dashboard widgets, each reading through the wallet or statement service (INV-26) and each tested against the service call it reads |
| [ ] | `collaborator.students.index` (§57) — **blocked, not skipped**: there is no `students` table until the institute phases, and a screen over a table that does not exist would be a placeholder pretending to be a feature. Everything else in §7.5 is built |
### [ ] PHASE 13 — Software-house finance: invoices, payments, expenses, income

Contract: [`docs/phases/phase-13.md`](docs/phases/phase-13.md) · **schema, services, every admin
screen, the client panel, the public link, the reports and the nine dashboard cards landed
2026-09-21. Three items are outstanding and each is waiting on something outside this phase.**

| | Item |
|---|---|
| [x] | Seven tables, ten enums and the §2.7 invoice arithmetic as a pure function (`InvoiceCalculator`) — 81/81 schema checks on both databases |
| [x] | `InvoiceService`, `ExpenseService`, `IncomeService`, `FinanceReportService` and the four §99 reports, all on a cash basis with `meta` naming the date column and the sources the reader may not see |
| [x] | **D79**: `issue()` now guards on `invoice_number` rather than the status. A status-only guard let `markSent()` re-issue an invoice and spend a second number — the series had gaps at 2, 4, 6 for three invoices |
| [x] | Admin screens: the invoice register, builder, detail, print; the expense register, approval queue, form and detail; other income; payment methods; finance categories; the four report screens and their print/CSV — 38 screens rendered against live data, 0 failures |
| [x] | `layouts/print.blade.php` (F-4.14) — A4, self-contained CSS, an inlined logo, `tabular-nums` money, no navigation, rich text through `RichText::sanitize()`. Every later printable document extends it |
| [x] | `App\Services\Reporting\ReportExporter` (F-4.14), namespaced `Reporting` because phases 18 and 19–23 export non-finance reports through it; the meta block travels with every file |
| [x] | The §32 gateway-ready abstraction: `PaymentGateway`, `ManualGateway` (which **throws** rather than returning a quiet failure), `PaymentGatewayManager` (a named exception for an unknown driver, never a silent fallback) and `PaymentMethodService` — the one source of every method dropdown |
| [x] | `PaymentMethodSeeder` — §32's four methods plus an **inactive** gateway placeholder, idempotent and additive (D65) |
| [x] | Five finance policies. The permission opens the door; the document's state decides what is behind it — an issued invoice is cancelled not deleted, a decided expense is voided not edited, nobody approves their own claim, `salaries` can be neither removed nor switched off |
| [x] | The cross-source payments register (§8.13): three `UNION ALL` sub-selects paginated by the database, with a source the reader may not see **absent from the union** and named on screen |
| [x] | Client panel invoices through `ClientPortalRegistry` (D31) — an allow-list of columns, never a draft, another client's id a **404** — and the signed public link, where a draft, a rotated token, an unknown token and the setting switched off all answer 404 |
| [x] | The nine §8.15 dashboard cards, each reading `FinanceReportService` (D28) — the narrow readers live in the service so **no widget contains its own `SUM`** |
| [x] | The two D60 manifests: 68 route-guard rows read straight off the live route table (a written rationale on each of the two public rows), and 33 screen rows whose params closures all resolve. Phases 5–12 never appended theirs; that carry-over is still open |
| [x] | Three of §10.5's four scheduled commands: `invoices:mark-overdue` (01:05), `invoices:reconcile-balances` (02:10, reports and **does not** repair) and `expenses:flag-stale-approvals` (Monday 08:00, which decides nothing) |
| [ ] | `invoices:send-reminders` — **deferred with `InvoiceDeliveryService` (D81)**: the reminder is an email, and the delivery service the contract specifies attaches a PDF that does not exist yet |
| [ ] | `RecordPayrollExpense` (D44) — **blocked, not skipped**: `PayrollRunPaid` does not exist until Phase 7's payroll ships. Without it §99's profit and loss is short by the whole payroll, and the reserved `salaries` category is already in place waiting for it |
| [ ] | `InvoicePdfService` / `InvoiceDeliveryService` — **deferred with a reason (D81)**: dompdf is not installed. The print layout is the document; the PDF routes answer 404 rather than serving an HTML blob named `.pdf` |

### [x] PHASE 14 — Institute: course categories, courses, outline (modules / topics / lectures)

Contract: [`docs/phases/phase-14-17.md`](docs/phases/phase-14-17.md) §1–§8.20 · **schema, services,
policies, every admin screen, the public catalogue and course page, the sitemap providers and the
dashboard card landed 2026-09-21.**

| | Item |
|---|---|
| [x] | Seven tables and six enums — `course_categories`, `courses` (eight CHECKs), the three outline levels, `course_topic_resources`, `course_topic_assignments` — 499/499 schema checks on both databases, 36/36 behavioural checks inside a rolled-back transaction |
| [x] | **INV-I12 in the code, not only in the contract**: every parent id is read from the parent the route bound, never from the request, so there is no path a crafted POST could use to graft a node onto another course's tree. `reorder()` and `moveTopic()` re-verify every id against its parent and the parent against the course, and a payload with one foreign id reorders nothing at all |
| [x] | `CourseService` — the §2.30.1 transition table verbatim, `archived` the one move that demands a reason, `publishingGaps()` counted live rather than from `modules_count`, `duplicate()` deep-copying the tree and the FAQs through Phase 3's `FaqService` |
| [x] | `CourseOutlineService` — the five adds, the five edits, `setActive()`, `delete()`, `reorder()`, `moveTopic()`, `duplicateModule()`, and the counter maintenance the landing page reads |
| [x] | **D83**: the public-cache invalidation §7.10 asks for, as its own step called by every write. Three catalogue tests were failing as stale `200`s and the cause was that nothing bumped Phase 3's version stamp |
| [x] | **D85**: a resource file is on the **private** disk and is served by two controllers that re-run their own rule — against §2.8's own line, which contradicts the permission the same section requires (D21) |
| [x] | `PublicCourseService` — `catalogue()`, `filterCategories()`, `landing()`, `downloadableResource()`, and every Apply / WhatsApp link, so no page writes its own `href` and drops a partner's attribution |
| [x] | Three policies (`CourseCategoryPolicy`, `CoursePolicy`, one `CourseOutlinePolicy` registered for all five node models). The permission opens the door; the course's own history decides what is behind it — a course anybody was ever admitted to is archived, never deleted, and `courses.view_financial` gates the three fee columns on the index, the form, the export and the public page |
| [x] | 47 routes (43 admin, 4 public), 19 screens rendered against live data with 0 failures: the category list, the course register, the create and edit forms, the course detail with its outline tree, the standalone outline screen, the public catalogue, a category page and a course landing page |
| [x] | §111 upload validation by **content**: `finfo` over the bytes, checked against `CourseResourceType::allowedMimes()`, a 40-character random name on disk so nothing the uploader chose becomes a path. **D86** — the test that proved this had to stop using `UploadedFile::fake()` |
| [x] | Two sitemap providers that honour `is_indexable` and skip a category with nothing published in it, and the `institute-active-courses` dashboard card reading the service (D28) |
| [x] | The four `course_id` keys Phases 4 and 10 deferred until this table existed, finally attached ([D-IN-1]) — `contact_inquiries`, `student_reviews`, `success_stories` and `student_fees`. Their own migrations are idempotent and ask to be re-run, but `migrate` runs a file once, so the phase that creates the target has to do it |
| [x] | The four D60 manifests: 47 route-guard rows read off the live route table, 13 screen rows, the seven tables' indexes and the one upload route — with **D87**'s row shape for the thirteen routes a policy guards, and `CourseManifestTest` comparing all four against the thing they describe |
### [x] PHASE 15 — Inquiries, online admission, admission workflow, registration

Contract: [`docs/phases/phase-14-17.md`](docs/phases/phase-14-17.md) §2.11–§2.16, §2.30.2–§2.30.6,
§2.31, §4, §5, §6.4–§6.6, §6.12, §7.3–§7.4, §7.10, §8.5–§8.9 · **schema, services, policies, every
admin screen, the public admission form and the four D60 manifests landed 2026-09-21.**

| | Item |
|---|---|
| [x] | Six tables and ten enums — `course_inquiries`, `course_inquiry_follow_ups` (append-only), `student_applications`, `students`, `student_admissions` (three generated columns), `demo_classes` — 730/730 schema checks on both databases, 49/49 behavioural checks inside a rolled-back transaction |
| [x] | **§68's pipeline as one column.** Every step asserts the one before it, so `activate()` on an admission still at `application` throws and names the step that was skipped rather than producing an active student with no registration number and no fee |
| [x] | **INV-I2 in the code**: `updateFigures()` refuses once `figures_locked_at` is set, and the four money caches refuse a write from outside `StudentFeeService` through the model's own `updating` hook — the guard is in the model, not in a docblock |
| [x] | **[D-IN-7]**: the public form creates one application row and nothing else. A test asserts the `students` and `users` counts do not move |
| [x] | **[D-IN-13]**: the referral is captured on the application and attached at conversion, through Phase 9's resolver and Phase 10's `ReferralService::attach()`. A `collaborator_id` posted by the browser is discarded (INV-I4), and an unrecognised code is stored verbatim and attaches nobody |
| [x] | `StudentNumberService` — six document numbers, every one through Phase 5's `DocumentNumberService` (D27). No second `FOR UPDATE`, no local fallback. **D90**: the format tokens read case-insensitively so Phase 2's seeded spelling still works |
| [x] | The `student_applications` module (§4.1) with **no `delete` ability**, so a receptionist triages the §67 inbox without holding `students.create` — and converting asks for both modules. Tested from both sides |
| [x] | `EnsureAdmissionFormOpen` — closed answers **200 with a noindex**, not 404, and a staff user holding `admissions.create` passes through to an amber ribbon naming the state |
| [x] | 60 routes (56 admin, 4 public), 27 screens rendered against live data with 0 failures: the counsellor's queue and its funnel report, the application inbox and review screen, the student directory, the §68 stepper, the demo list, calendar and slip, and the public form with its thank-you and closed pages |
| [x] | The nine foreign keys Phases 4 and 10 deferred until `students` and `student_admissions` existed, finally attached ([D-IN-1]) |
| [x] | 47 tests across five files: the pipeline, the public form's guards, the inquiry queue, authorization and branch isolation, and the manifests |
| [x] | The four D60 manifests: 60 route-guard rows with **D87**'s shape for the 34 a policy guards, 23 screen rows, the six tables' indexes, and an explicit assertion that this phase accepts no upload at all |
| [ ] | `StudentService::merge()` — **blocked, not skipped**: merging moves fee, enrolment and attendance rows between two students, and those tables arrive with Phases 16–18. The route and the permission exist and the action refuses with the reason |
| [ ] | The student importer — **deferred**: a partial importer that dropped rows quietly would be worse than none. The route answers with that sentence |
| [ ] | `assignBatch()` / `transferBatch()` / `requestFees()` — **delegated, not implemented**: `BatchEnrollmentService` is Phase 16's and `StudentFeeService` is Phase 18's. Each refuses with the name of the service that owns it rather than half-doing its job (INV-I1) |
### [x] PHASE 16 — Teachers, batches, timetable, demo classes

Contract: [`docs/phases/phase-14-17.md`](docs/phases/phase-14-17.md) §2.17–§2.23, §2.30.7–§2.30.9, §4.1–§4.4,
§5, §6.6–§6.7, §6.11, §7.5–§7.6, §7.8–§7.9, §8.10–§8.14, §8.18–§8.19 · **schema, the clash authority, six
services, five policies, every admin screen, the two panels and the four D60 manifests landed 2026-09-21.**

| | Item |
|---|---|
| [x] | Seven tables and seven enums — `teachers`, `course_teacher`, `classrooms`, `batches`, `student_batch_enrollments`, `timetable_entries`, `class_sessions` — **639/639 schema checks on both databases**, 55/55 behavioural checks inside a rolled-back transaction |
| [x] | **`ScheduleClashDetector` is the one overlap test in the system (D47, F-4.7).** Timetable slots, one-off classes, reschedules, substitutions and demo bookings all call it; Phase 19–23's exams and meetings join with one `register()` declaration each. Half-open time, four day/date combinations, three dimensions, two exemptions, and a report that names **every** conflict at once — a coordinator who fixes the teacher and is then told about the room has done the work twice |
| [x] | **Overlap is a range condition MariaDB cannot hold**, so the guard is a lock: transaction → `lockParents()` in one fixed order → check → insert. The six unique indexes underneath are the cheap backstop for the same form submitted twice, and **D94** made the two room ones exempt exactly what the detector exempts |
| [x] | **D95, found by tests**: a booking used to clash with its own parent — editing a live slot collided with the classes it had generated, and substituting a teacher collided with the slot the class came from. `alsoIgnore` and `ignoreGeneratedBy` say "this booking and what produced it" |
| [x] | **INV-I6 / INV-I7 / D48**: capacity is enforced in `BatchEnrollmentService` and nowhere else — one transaction, the batch row locked, then a **recount**. `current_students` is never read to decide. A test corrupts the cache to 99 and the real seat is still given; another sets it to 0 and the recount repairs it |
| [x] | Overbooking takes **three separate yeses** — the institute setting, the caller's flag and a reason — and each missing one is refused with a different sentence. The room is a second ceiling for a physical batch, so twenty-two students and eighteen chairs is refused rather than discovered |
| [x] | A transfer leaves the attendance in the batch where it happened, links both rows, repoints the admission, recounts both batches, and hands the fee side to Phase 18 by name (INV-I1) — this phase writes no money row |
| [x] | `ClassSessionService::generate()` is idempotent by `uq_cs_generated`, so the nightly job, a manual run and a retry can overlap. Cancelling frees the slot and keeps the class visible; `original_teacher_id` is filled once and never overwritten, so §99's missed-class question stays answerable |
| [x] | The `classrooms` module (§4.1) with **D96**'s sit-in rule, and `batches.assign` as the enrolment ability — tested from both sides: a seater cannot edit the batch, an editor cannot seat anybody |
| [x] | 61 routes (51 admin, 7 teacher panel, 3 student panel), **38 screens rendered against live data with 0 failures**: the teacher register and workload report, the room list, the batch detail with its capacity meter and roster, the enrol and transfer dialogs, the five timetable views, the class detail with its four moves, and the two panels |
| [x] | `salary` is behind `teachers.view_financial` and is **stripped from the payload**, not hidden in the template — a test posts one without the ability and asserts the column never moves |
| [x] | 45 tests across five files: the clash authority, enrolment and capacity, the timetable and its classes, authorization and panel scoping, and the manifests (4,538 assertions) |
| [x] | The four D60 manifests: 61 route-guard rows with D87's shape for the 24 a policy guards, 29 screen rows carrying an IDOR owner for the two panels, the seven tables' indexes including the three generated guards, and an explicit assertion that this phase accepts no upload |
| [x] | Three cross-phase effects of attaching the deferred keys, fixed here: a Phase 15 test that wrote `batch_id = 1`, a Phase 14 test that had been **skipped since it was written** (`class_sessions` did not exist) and turned out to be asking a Super Admin, and **D97**'s over-strict foreign-key manifest check |
| [ ] | `TeacherService::linkEmployee()` writes `employee_id` with no foreign key — **deferred, not skipped**: Phase 7 creates `employees` and its migration attaches the key ([D-IN-1]). `uq_te_employee` already stops one employee being two teachers, and both sides of the link are audited |
| [ ] | `timetable:verify-clashes` and the nightly generation command — **deferred to Phase 17**, which adds the scheduler block for attendance sweeps; a command with no scheduler entry is a command nobody runs |

### [x] PHASE 17 — Student attendance + course progress

Contract: [`docs/phases/phase-14-17.md`](docs/phases/phase-14-17.md) §2.24-§2.28, §4.2, §6.8-§6.10, §7.7,
§8.15-§8.17, §75, §99 · **schema, two services, the report engine, every screen, both panels, the scheduler
block and the four D60 manifests landed 2026-09-22.**

| | Item |
|---|---|
| [x] | Five tables and four enums — `student_attendances`, `batch_topic_coverage`, `student_course_progress`, `student_module_progress`, `student_topic_progress` — **422/422 schema checks on both databases**, 34/34 behavioural checks inside a rolled-back transaction, **40/40 on `institute:verify-constraints` on both** |
| [x] | **INV-I9 is one question asked in one place**: a register may only be taken against a *dated* class, for a student who was on that roster *on that date*. `AttendanceService::roster()` and `mark()` both resolve it from `student_batch_enrollments`, so a student transferred out last week is absent from today's register rather than present in it |
| [x] | **INV-I10 lives in the model, not the policy (D101).** `Gate::before` allows a Super Admin `student_attendance.delete` before `StudentAttendancePolicy` is ever consulted, so the policy alone cannot hold the line — `StudentAttendance::deleting` throws for every caller. After `institute.attendance_lock_hours` a change additionally needs `student_attendance.edit` **and** a reason, and `amend()` writes the old value, the new one, who changed it and why |
| [x] | **A register is idempotent by `uq_sa_session_student`**, because the marking screen is used on a phone at a classroom door where a slow response and an impatient thumb submit the same register twice. `mark()` upserts; `bulk()` and `fillUnmarkedAsAbsent()` go through the same path |
| [x] | Four reports over **three** base queries (`heldSessions`, `attendanceRows`, `enrollments`) — daily, the monthly student x day matrix, by student and by batch — each with an HTML view, a print layout and a CSV, plus `unmarked()` for the registers nobody has taken. A cancelled class is not a zero: it leaves both sides of the percentage |
| [x] | `CourseProgressService` at **three levels** (course / module / topic) from one recompute: `Money::weightedAverage()` over the topic rows, clamped, stored half-up at two in `decimal(8,4)` (INV-I11). A course with no outline reports `0.00` and never divides by zero |
| [x] | **Marking a topic for the class reaches every active student except anybody whose row was set by hand** — `ProgressSource::isOverwritableByBatch()` is true only for `batch_coverage`, so a teacher's per-student judgement survives the next class-level mark. The batch board draws an amber dot on exactly those cells |
| [x] | **Dropping a topic raises every percentage**, because its weight leaves the denominator — which is what should happen when work comes out of a syllabus, and why it is `student_progress.change_status` with a mandatory reason rather than an `edit` |
| [x] | 30 routes (20 admin, 8 teacher panel, 2 student panel), **28 screens rendered against live data with 0 failures**: the register list with its unmarked filter, the marking screen with its keyboard shortcuts, the four report matrices with print and CSV, the progress board, the per-student syllabus, and both panels |
| [x] | **Eight scheduler commands, each with its `Schedule::command()` entry** — the attendance sweeps, the progress recompute, and Phase 16's two deferred commands (`timetable:verify-clashes`, class generation), which is what that deferral was waiting for. A command with no scheduler entry is a command nobody runs |
| [x] | **A branch leak, found by a test**: the report filter dropdowns listed every batch code in the institute, to a user who may see one branch. `pickers()` and `AttendanceController::index()` now take the request and apply `->forBranch()` |
| [x] | 39 tests across four files: attendance (15), progress (15), authorization and panel scoping (10), and the manifests (9) — 3,142 assertions on the manifest file alone |
| [x] | The four D60 manifests: 30 route-guard rows with D87's shape for the one a policy guards, 18 screen rows (`board` for the two grids, `form` for the marking screen, both HTML sweeps off for a CSV), the five tables' indexes with each unique guard asserted **UNIQUE** rather than merely listed, and an explicit assertion that this phase accepts no upload |
| [x] | **D98 / D99, the phase's real cost**: seven route names collided exactly with Phase 7's employee attendance, and the collision was invisible because both halves of it answered `200`. The rename that fixed it then broke eight of Phase 7's own routes, and nothing in the repository noticed until a test was written to ask |
| [x] | **D103 — the full-suite gate had thirty seconds of headroom and I only found out by losing a run.** `InstallAndRollbackTest` migrates the entire schema in a child process, three times; the same test on the same tree took 282s, 570s and 4,800s on three consecutive runs. Its 600s ceiling is now 1800s |
| [x] | **D104 / D105 — the full cross-phase run found two things the phase's own suite could not.** Five month captions in the monthly report formatted a date by hand (`NoHardcodedFormatsTest` is in `tests/Feature/Views`, which the Institute run never touches), and a Phase 2 mail test pinned a failure sentence that depends on whether the network answers on TEST-NET-1 |
| [ ] | The attendance CSV importer — **deliberately not built (D102)**: the route exists, is guarded, and answers with a sentence explaining why a partial importer would be worse than none |

### [x] PHASE 18 — Student fees, installments, discounts, scholarships (commission triggers)

Contract: [`docs/phases/phase-18.md`](docs/phases/phase-18.md) · **one table, four services, four
policies, seven controllers, 25 routes, fourteen screens, four scheduler commands, three widgets and
57 tests landed 2026-09-22.**

| | Item |
|---|---|
| [x] | **Phase 10 owns and had already created all four money tables**; this phase creates exactly one — `student_fee_reminders` — and ships what acts on the rest. `student_fees.generation_key`, `PaymentService`, `DocumentNumberService`, `Money::distribute()` and `RemainderPlacement` were all verified present before a line was written |
| [x] | **[D18-1] the dedupe guard is a STORED generated column.** `uq_sfr_dedupe` covers `(student_fee_id, dedupe_line, type, due_date, offset_days)` where `dedupe_line = COALESCE(student_fee_installment_id, 0)` — MariaDB permits unlimited NULLs in a unique index, so a charge with **no installment plan** would have slipped the guard on every single run and been chased every night. Writing `0` into the foreign key itself was the alternative, and that is a dangling reference in a disguise |
| [x] | **`InstallmentPlanCalculator` works in integer paisa through `Money::distribute()`**, so the lines sum to the net fee by construction rather than by a final fix-up. It refuses `total < count` rather than emitting the unpayable `0.00` line `distribute()` would otherwise produce — `0.04 / 5` is a refusal with both numbers in the sentence, not a constraint violation |
| [x] | **D107: `PaymentService::recomputeCharge()` now delegates here and its private `chargeStatus()` is gone.** Three drifts, none a typo — see the decision. This is the change that made `deriveStatus()` true rather than merely stated |
| [x] | **D108, found by D107**: the spine's `charge()` fixture wrote `discount_amount` with no discount row behind it, which only survived because the old recompute left that column alone. It moved a commission by 500.00 the moment one definition existed |
| [x] | **D106: PI-1 turns out to have an exception, and a test found it.** §6.2 and §6.3 pull against each other in exactly one case — a discount larger than the remaining unpaid lines. The live paid line is right; the flat equality is wrong. An overpaid charge has already gone past `paid`, so the risk PI-1 guards is absent. The service, the nightly verifier and the suite's helper all skip it and each says why |
| [x] | `generateStructure()` is **duplicate-proof by INSERT, never by SELECT** (F-3.15): a `generation_key` per head, a 1062 treated as "already generated". A double-clicked wizard produces one set and the loser is told so. `SUM(net) = admission.net_payable` is asserted before anything is written and the whole transaction aborts otherwise — that figure is the spine's collectible denominator, so a wrong one is a wrong commission waiting to be paid |
| [x] | 25 routes (21 admin, 4 student panel) and **14 screens rendered against live data with 0 failures**, including the charge detail's five tabs, both print layouts and every empty state |
| [x] | **The student panel's response body carries no commission column** — selected away, not hidden in a template, and asserted against the body on all three screens. Somebody else's charge is a **404, never a 403** |
| [x] | **D110: one receipt document, both panels.** Phase 10's separate admin template is deleted; the routes differ only in the `FeeSlipOptions` they construct, so `studentCopy()` cannot be talked out of hiding a commission figure |
| [x] | **D109: the sidebar was offering two links to routes that do not exist.** Phase 1 reserved `admin.installments.index` and `admin.fee-discounts.index`; §7 ships neither. Replaced, and every sidebar route across all five panels now resolves |
| [x] | Four scheduler commands, and **only one of them changes anything**. `fees:verify-plan-integrity` reports and never repairs — the same argument as `attendance:recount` and the wallet reconciler: a silent nightly repair would let the same bug write a wrong number for a year |
| [x] | 57 tests across six files (453 + 3,264 assertions): the installment arithmetic, §120's commission integration, discounts and waivers, collection and the statuses, authorization and isolation, and the four D60 manifests |
| [x] | The four D60 manifests: 25 route-guard rows with D87's shape for the three a policy guards, 14 screen rows (`json` for the two previews, `print` for the three documents), the one table's indexes with the generated guard asserted UNIQUE **and** STORED, and an explicit assertion that this phase accepts no upload |
| [ ] | `transferPayment()` ships and is tested at the service level, but **Phase 15 owns the screen that calls it** (§13.3, R-11) — deferred there rather than invented here |
| [ ] | The notification classes `FeePaymentReceived`, `FeeDueReminder`, `FeeOverdue` and `FeeCacheDriftDetected` are **Phase 22's** (§13.3). `FeeReminderService` writes its row and checks `class_exists()` before dispatching, so the dedupe guard holds across the gap and nothing is lost when they land |


> **Release note** — note: consumes Phase 10's `PaymentService` and the four fee tables (`student_fees`, `student_fee_installments`, `student_fee_discounts`, `student_fee_payments`); Phase 18 creates no financial table. It ships `student_fee_reminders`, the fee services and all fee screens.

### [x] PHASE 19 — Course material + assignments (2026-09-23)
### [x] PHASE 20 — Exams + results (2026-09-23)
### [x] PHASE 21 — Certificates (public QR verification) + student ID cards (2026-09-23)

| Done | What |
|---|---|
| [x] | Four tables — `print_templates`, `certificates`, `certificate_verifications`, `student_id_cards` — with three generated STORED guard columns (`default_guard`, `live_guard` ×2), because MariaDB tolerates unlimited NULLs in a unique index and that is the whole mechanism |
| [x] | Five enums (`PrintTemplateType`, `PaperSize`, `PageOrientation`, `CertificateStatus`, `IdCardStatus`), every one string(32) in its column and asserted to fit — D126 |
| [x] | `PrintTokenRegistry`: 31 certificate, 20 card and 26 result-card tokens, the only place a token name exists, with `raw` declared per token and deliberately short |
| [x] | Six services — templates, certificates, eligibility, verification, cards, PDF rendering — plus `layouts/document`, the chromeless shell an admin-designed document prints into |
| [x] | The public verification page: rate-limited **before** the lookup, `hash_equals()` after, every attempt logged, and one identical 404 for a draft, an opt-out, a deleted row and a code nobody issued |
| [x] | 48 routes across three panels and the public site, reconciled name by name against §7.5 — including eleven the sidebar was already linking to under the contract's names and nine that had not been built |
| [x] | 20 screens, and a student's own certificates and card on the student panel; a teacher's candidate list on theirs, read-only, 403 on every write (§9.3) |
| [x] | `PrintTemplateSeeder`: one default per printable kind, idempotent, never overwriting a redesign, and refusing to write a template whose stylesheet sanitises to nothing — D137 |
| [x] | 121 acceptance tests across six files, plus the 12 `PaperSize` unit cases: the lifecycle, the eligibility rules, the verification endpoint against a hostile caller, the template sanitiser, authorization across five roles, and the manifest |
| [ ] | The notification classes `CertificateIssued`, `CertificateRevoked`, `IdCardIssued` and `IdCardExpiring` are **Phase 22's** (§13.3), along with `PrintTemplateChanged` |
| [ ] | `ResultCardBuilder` rendering *through* a `result_card` template is Phase 23's: Phase 20's result card renders from its own Blade view, and the seeded template is there so the editor is not empty when it lands |

> **Release note** — certificates and cards are printed from `print_templates`, never from a hard-coded
> layout, and every issued document keeps its own snapshots so a rename never rewrites a printed page.
> The verification page at `/verify/{code}` needs no account and is the one public write path in the
> institute.

### [x] PHASE 22 — Tickets, meetings, internal messaging, notifications

| Done | What |
|---|---|
| [x] | Twelve enums, every one through `EnumContractTest`'s 1,222 discovered cases — `ConversationScope`'s declaration order is load-bearing and `inMatchOrder()` says so |
| [x] | Ten tables plus the one foreign key that cannot be declared with its table (`conversations` and `messages` point at each other), migrated forward, rolled back and migrated again |
| [x] | Three generated STORED guards: `default_guard` on departments, `ends_at` on meetings, `active_guard` on conversation membership |
| [x] | A 407-check schema probe over `information_schema`, including all twenty enum columns measured against their own cases and D125's every-foreign-key-leads-an-index sweep |
| [x] | Nine models with their hooks, casts, relations and scopes, and a 63-check behavioural probe that names the layer and the constraint behind every refusal |
| [x] | `PermissionRegistry`'s `ticket_departments` module and the five Shared dependency edges |
| [x] | The `support` settings group, [D-22-4]'s thirty-seven keys |
| [x] | `MessagingMatrix` and `ConversationService` — §94's six pairs, re-checked on every send |
| [x] | `TicketSlaService`, `TicketAssignmentService`, `TicketService` — the clock, the four strategies and §2.28.7's transition table as data |
| [x] | `MeetingService`, with `meetings` registered as a clash occupant (D144) and an 85-check behavioural probe |
| [x] | `NotificationRegistry` (53 events), `NotificationService`, `NotificationPreferenceService`, `UnreadCounters`, and `RichDatabaseChannel` — the columns the stock channel could not write (D146) |
| [x] | Six listeners wiring tickets, meetings and messages to the bell, and `FeeReminderService` switched from a `class_exists()` guard that was never going to fire to a real dispatch |
| [x] | Seven policies over §9.4's table, and a probe that found `messages.view_any` granted to two roles the contract grants it to nobody (D149) |
| [x] | 40 admin routes, five controllers, five Form Requests and 21 screens — every one rendered through the HTTP kernel, plus the shared bell across four panels |
| [x] | The Workspace sidebar group, whose Phase 1 placeholder pointed at a route name that never existed and gated Messages on a permission nobody holds |
| [x] | The four portal panels' ticket, meeting and message screens — three shared controllers, 44 screens rendered — and the client panel migrated off Phase 5's own read-only stubs |
| [x] | Seven scheduled commands, each idempotent at its source rather than at its schedule (D150) |
| [x] | Portal sidebar entries, re-gated on their subject module so the menu and the route give the same answer |
| [x] | The six trigger call sites Phases 19-21 owed, each dispatching after its own transaction returns (D152) |

### [x] PHASE 23 — Reports, analytics, activity log, audit trail, global search, exports

| Done | Deliverable |
|---|---|
| [x] | Enums — `ReportGroup`, `ExportStatus`, `SearchEntityType`, `AuditSensitivity`, `ReportColumnType`, `ReportFilterType`, `ChartType`; `ExportFormat` gains `excel` behind `isAvailable()` |
| [x] | `report_exports` (§2.26) — no soft delete, no blameable, `uuid` the only id in a URL |
| [x] | Five composite `activity_log` indexes (§2.27) — indexes only, INV-23-5 |
| [x] | The `attachments` assertion migration — changes nothing, fails loudly if §96's one file table drifts |
| [x] | The `reports` settings group, 15 keys ([D-23-1]) |
| [x] | `ReportExport` model — append-only, `expire()` not `delete()` |
| [x] | `ReportRegistry` (the fourth registry) + `ReportDefinition` + the `Report` base |
| [x] | `ReportEngine` — §9.5's six steps, in that order |
| [x] | `ReportExportService`, `ReportExporter`'s excel branch, `BuildReportExport` |
| [x] | **All 31 §99 reports**, plus `sys.activity_log` and `sys.audit_trail` |
| [x] | `AnalyticsService` — nine charts, each a delegation |
| [x] | `ActivityLogService` (§106) and `AuditTrailService` (§107) |
| [x] | `GlobalSearchRegistry` + eleven providers + `GlobalSearchService` ([D-23-3]) |
| [x] | `audit_trail` module, `activity_log.print`, the D62 and §4.3 corrections |
| [x] | 16 routes, 5 controllers, `ReportExportPolicy`, 9 Blade screens, 4 sidebar entries |
| [x] | `reports:prune-exports`, `reports:warm-caches`, `activity-log:prune`, all scheduled |
| [x] | Ten read methods added to the services that own them (§6.22's sources) |

### [ ] PHASE 24 — Security, financial integrity, responsive and performance testing
### [ ] PHASE 25 — Deployment preparation (install guide, backups, queue/scheduler, production notes)

---

## 6. Change Log

### 2026-09-26 — T57 closed: four blank dashboards, fifteen widgets, and a ceiling that had stopped tracking the product

T57 was recorded when `Receptionist` turned out to hold 75 permissions and see **none** of the 38
registered widgets. Measuring the rest found it was not one role's problem:

| Role | Permissions | Widgets |
|---|---|---|
| Project Manager | 94 | **0** |
| Support Agent | 39 | **0** |
| Developer | 37 | **0** |
| Designer | 37 | **0** |
| HR | 176 | 2 |
| Course Coordinator | 98 | 2 |

Four people signing in to nothing, and the one who runs the entire people side of the business
getting two cards. Fifteen widgets now cover them — **People** (headcount, attendance today, leave
approvals, hiring), **Operations** (active projects, task load, milestones due, hours logged; ticket
queue, my open tasks, my time this week, my meetings) and **Institute** (classes today, attendance
gaps, active batches). Registry: 43 to 58.

**Four judgements worth keeping.** Attendance counts *from `employees`*, not from `attendances` — a
row is only born when somebody punches, so counting attendance rows silently answers a different
question, and the LEFT JOIN is what makes "not marked yet" expressible at all. "Marked" is
`attendance_marked_at IS NULL`, read from the migration rather than inferred from the presence of
rows: **a register where everybody was absent is a marked register with zero present rows.** Tasks
and milestones join to live projects, because cancelling a project does not touch its tasks and
without the join an archived project's open rows sit in the total for ever. And `hr_hiring` runs its
candidate query only behind `job_applications.view_any` — `jobs.view_any` is permission to see
adverts, not applicants.

**The three "my" widgets scope through the owning column, never a request parameter** — and
`time_entries.user_id`, the worker, deliberately not `recorded_by`: when a manager keys time for
somebody the two differ, and scoping to the recorder would show the manager their own admin and hide
the worker's week. With no authenticated user they return zeros, never an unscoped query. A widget
that falls back to "everybody" when it cannot identify the viewer is a data leak with a friendly
face.

**A latent bug in my own Reception widgets, found by a review agent.** They computed "today" from
`config('app.timezone')` while their docblocks claimed the institute's timezone.
`ConfigureFromSettings` says outright that `localization.timezone` is display-only and deliberately
not copied onto `app.timezone`, because copying it re-interprets every row already written. The
application reads the display timezone through `Format::timezone()` in 77 places. So the day
somebody changed that setting, a deadline would have been red on the card and black on the list it
links to — and nothing would have been logged. Eleven occurrences across nine files, now zero.

**`PAGE_CEILING` raised 60 to 90, and the evidence matters more than the number.** The page measured
**77**. Three things were checked first, and are written into the constant's docblock so the next
raise has to earn it the same way:

- **The growth check passes.** With the ceiling lifted out of the way the whole file went green, so
  the count does not move when every table a widget reads grows by an order of magnitude. That is
  the assertion which catches an N+1, and it is untouched at zero growth.
- **Every widget already costs one query.** There is no waste left to remove. The previous breach
  (66 against 60) was different in kind: five widgets were spending twelve queries between them, and
  the fix was to write them properly rather than to move the line.
- **The arithmetic no longer fits.** 58 cards at one query each cannot render inside 60 alongside
  session, auth, RBAC, settings and the shell.

**And the test T57 asked for.** `EveryRoleSeesADashboardTest` asserts that every seeded admin role
sees at least one widget, and that no widget is gated on a permission only Super Admin holds. The
second found exactly one: `modules_enabled`, the module kill switch — deliberately restricted, and
now a documented exception rather than a failure people learn to ignore. The first assertion is
deliberately weak (*at least one*): it is a floor, not a design review. It cannot tell a good
dashboard from a poor one; it can only refuse to let a role end up with nothing, which is the
failure that kept happening.

Dashboard suite: **35 passed, 757 assertions.**


### 2026-09-25 — Seven services, and the line a seeder does not cross

The seven service names are the client's own — they were given as the "software house" pages the
site needed. The descriptions say what each service **is**, in the general case, and nothing about
who has delivered it, how often or how well. That line is the whole design of this file:
*"e-commerce stores with a catalogue, a cart and a payment gateway"* describes a category;
*"over 200 stores delivered"* would be a claim about a company, and a seeder is not in a position
to make one.

**No price is written.** `starting_price` stays null and `price_visible` false, because a rate is a
commercial decision this file cannot make and a wrong one on a live page is worse than none — a
visitor who reads it treats it as a quote.

Unlike courses there is no completeness gate: `Service::scopePublic()` asks only for
`status = published`, so these are published on creation with no outline owed. The seeder then
enables the **Services** nav item that `WebsiteCmsSeeder` created and left off for exactly this
moment, places a services section on the home page, and republishes the shell — the menu tree is
baked into the header and footer snapshots, so a link enabled without that republish reaches nobody.

**Portfolio was asked for in the same breath and is deliberately not here.** A catalogue entry
describes a service that is on offer; a portfolio entry describes *work that was done, for a named
client*. Inventing those means a prospective client hiring on the strength of projects that do not
exist — which is a different kind of wrong from a placeholder, and not one a later edit undoes for
the people who already read it. The module, the section type and the nav item are all in place and
wait for real work.


### 2026-09-25 — The footer's emptiest column now does something

Three changes, all to the same symptom: a footer that was structurally fine and read as mostly air.

`items-start` on the column grid. Grid rows stretch by default, so two short link columns beside a
tall contact block were being held open to *its* height — the void under COMPANY and EXPLORE was
the contact block's shadow, not their own.

An action row in the brand column. It held a logo, one line of tagline and then nothing, while the
one thing a visitor might want to do from down there — message, call, email — sat three columns
away rendered as plain text. The row is built from the same `contact.*` settings that block already
prints, so nothing new is stored and nothing is repeated for its own sake: **the point is not
showing the details again, it is making them pressable.** Each button appears only when its setting
is filled, so an install without a WhatsApp number does not get a dead one.

And the rhythm tightened — `pt-16` to `pt-14`, `mt-14` to `mt-12` before the bottom bar.

**No map, by request.** `contact.map_embed` already exists and already renders through
`RichText::sanitize()` with an iframe allowlist; it is empty here and stays that way. Nothing was
added to enable it.


### 2026-09-25 — The navigation, and two ways a menu change reaches nobody

The header carried three links and the two footer menus were empty. Both are now filled from the
pages that actually work, and the rule is `WebsiteCmsSeeder`'s own: *"disabled until the phase that
owns the target ships, so the navigation is never a dead link."* The test applied to each candidate
was "what does a stranger see when they click this?"

`/courses` and `/fee-structure` are added **only** when courses are published. `/contact` and
`/request-a-quote` are added unconditionally — they are **forms**, they need no catalogue behind
them and they work on an install's first day, which is also why the quote page's toggle is switched
on here while the other five stay off. Services, portfolio, blog, careers, team, events, trainers,
timetable and student reviews are all skipped: they are empty, and a nav listing nine pages of which
six are empty is worse than one listing three that work, because the visitor learns the links are
not worth following.

The header's **Contact** item was repointed rather than merely enabled. It had been created as a
section anchor to `#contact`, assuming a contact section on the home page that was never placed —
switching it on as-is would have produced exactly the dead link the original comment guarded
against.

**Two bugs, both of the same family: a change that lands in the database and reaches nobody.**

The first run added a second **Courses** link beside the existing one. The check matched on the
column it was about to fill — `route_name` for a route item — while `WebsiteCmsSeeder` had written
its Courses item as a plain `/courses` URL. One destination written two ways is still one
destination, and the existence check now knows that: every row carries the aliases that mean the
same page, and all three link columns are searched.

The second is worth remembering beyond this seeder. **Eight items were added and not one appeared.**
The resolved menu tree is baked into each section's *published snapshot* rather than read live — it
is what makes a public page one indexed read instead of a walk down a menu table — so inserting a
`menu_items` row changes nothing until the header and footer are republished. Bumping the cache
version does not help either: that discards the stored HTML, and the next render rebuilds it from
the same stale snapshot, so the nav returns exactly as it was and the change looks like it silently
failed. The republish is now unconditional, because gating it on "did this run add anything" meant
a second run reported `0 added` and fixed nothing while the nav stayed as wrong as it was.


### 2026-09-25 — A section view that nothing could reach, and a header that offered a visitor nothing to do

Asked to enhance the landing page's UI. Most of what looked sparse turned out to be configuration
rather than code, and saying so was more useful than adding gradients — but underneath it was one
real gap.

**`site/sections/courses.blade.php` has existed since the institute phases and could not be added to
any page.** It is a one-line include of Phase 3's shared teaser, exactly like `sections/services`
beside it. But `MarketingSectionTypes` declares *Phase 4's nine types*, and `courses` belongs to
14-17, so it was never registered: no type meant no provider, no provider meant
`SectionService::place('courses', …)` threw `UnknownSectionTypeException`, and the view was
unreachable from the editor. A finished view with no way to reach it is the kind of gap that reads
as "the feature does not exist" rather than as a bug.

`CoursesSectionProvider` is the missing half, mirroring `ServicesSectionProvider`. It filters on
published **and** an active category — the same predicate `PublicCourseService::catalogue()` uses —
so a card on the home page can never link to a course whose own page 404s; a section advertising
something the site then denies is worse than an empty section. `media` is deliberately null: courses
carry `image_path` as a plain column rather than a `media_assets` row (D24's library does not cover
them), and handing the teaser a raw path would route around the image pipeline every other card goes
through. The teaser hides its image block when there is none, so a course renders as a clean text
card.

**The header was never under-built, only under-configured.** It already supports four buttons
(`login`, `contact`, `admission`, `cta`), sticky with hysteresis, transparent-over-hero, an
off-canvas drawer with a focus trap, and light/dark logo overrides. Only `login_button` was on — so
a marketing site's front door offered a visitor nothing to do but sign in to an account they do not
have.

**And the navigation was already right, waiting.** `WebsiteCmsSeeder` creates a **Courses** item in
the header menu and deliberately leaves it off: *"disabled until the phase that owns the target
ships, so the navigation is never a dead link"*. That was correct when `/courses` had nothing on it.
With 34 published courses the same rule points the other way, and the item only needed flipping.

`WebsiteCoursesSeeder` does those three things and **every one is guarded by "is there anything to
show?"** — it counts published courses first and returns untouched when the answer is zero. Run
before `courses:publish` it is a no-op, which is correct rather than inconvenient: switching on a
link to an empty page is the exact mistake the original `is_enabled => false` existed to prevent.

Two things the first runs taught, both now in the code. `CacheVersion::bump()` takes a required
reason. `menu_items` has no `deleted_at` — a removed item is gone rather than hidden — so the
"never resurrect a deleted row" guard I wrote was guarding against a state that cannot exist.
And `SectionService::place()` appends, so the new section landed *after* the closing call to action;
it is now moved to sit after About, because the CTA is the page's last word and content after it
reads as something somebody forgot to move.


### 2026-09-25 — A course code generates itself, and only on the way in

`CourseService::codeFor()` refused an empty code outright, so "what shall we call it?" was a
question that had to be answered before a new course could be saved at all. It now mints
`institute.course_code_prefix` + the next number when the field arrives blank, through the same
`DocumentNumberService::next()` seam that already produces inquiry, application, admission and
teacher numbers — a fifth generator, not a fifth mechanism.

**A typed code still wins and is kept exactly as entered.** The generator is a fallback, not a
policy: `WEB-101` reads better on a certificate than `CRS-0007`, and nothing here should argue with
somebody who has a better answer. `%04d` rather than the funnel's `%05d` for the same reason — a
catalogue holds courses in dozens, and `CRS-0007` is a course where `CRS-00007` is a transaction.

**Blank still refuses on update, and that asymmetry is the point.** An existing course has a code
that timetables, fee slips and printed certificates already point at; generating a new one there
would renumber it behind the back of everything referring to it. `$existing === null` is the whole
difference between a convenience and a data-loss bug. It is refused in three places, deliberately:
`UpdateCourseRequest` puts `required` back (so the editor sees it against the field they emptied),
the service refuses it again (the floor under the form), and the unique index refuses a duplicate
however it arrived — a generated code is unique by construction, but "by construction" is an
argument and the index is a guarantee.

Probed in a rolled-back transaction: `CRS-0001`, `CRS-0002`, `CRS-0003`.

### 2026-09-25 — Six public pages, the events module, and a role that exists to hold one button

A 33-page sitemap was specified. **Most of it already existed**, and saying so was the useful part of
the work: 14 pages are live routes, four are CMS pages at any slug the editor chooses, and the seven
"software house" pages — web development, mobile apps, custom software, e-commerce, graphic design,
digital marketing, hosting — are **seven rows in the services module, not seven pages of code**.
"Student projects" is a portfolio category, because `PortfolioController` already filters on one.
What was genuinely missing was six pages and one module.

**The five new public pages** are `/fee-structure`, `/timetable`, `/trainers`, `/student-reviews`
and `/request-a-quote`. Each is a controller, a view and a settings toggle, all following
`TeamController`'s 61-line shape.

**Every one defaults to OFF, unlike the team page.** These read live operational data — what a
course costs, when classes run, who teaches them — and an installation that has not filled that in
would publish an empty fee table the day it migrated. A page nobody switched on is invisible; a page
that switched itself on and is empty is a shop with its lights on and no stock. Off is a **404**,
never an empty 200: a soft-404 gets indexed, and an indexed empty fee page outranks the real one for
the query that mattered most.

**The timetable is the one with a real privacy question, and it decided the design.**
`ClassSession` was rejected as its source — those rows carry `expected_count`, `present_count`,
`absent_count`, `teacher_id`: an attendance register one join from a marketing page. It reads
`timetable_entries`, the weekly *rule*, instead. The controller then flattens everything to plain
arrays before the view sees it, so the template **cannot** print an attribute that was never put in
it. No student, no roster, no enrolment count, no teacher and no meeting URL is loaded at all — a
public meeting URL is a Zoom-bombing invitation, so it is absent rather than merely unprinted.

`/trainers` combines `public()` **and** `teaching()`: a resigned trainer whose `is_public` nobody
switched back off was otherwise still being advertised. Trainer photos are **not** rendered —
`teachers.photo_path` is a bare string with no upload service, no accessor and no serving route
anywhere in the repo, and D21 forbids inventing one on the public disk; the cards use initials.
`/request-a-quote` submits through `ContactInquiryService` as `InquiryType::Service`, **set server
side and never read from the body**, so no browser can retype a quote as something else.

**The events module** is new end to end: table, model, policy, admin CRUD, two public pages, a
sitemap provider and a sidebar entry. Two design decisions carried it. It **reuses `ContentStatus`**
rather than growing a fourth-case enum spelling the same four words — that is how two screens end up
disagreeing about what "scheduled" allows. And **an announcement is an event with no end**: rather
than a `type` column splitting the table in two, nullable `ends_at` does the work, because a thing
that happens at a moment and a thing that runs between two moments are the same row shape. A column
that exists only to be branched on in every query is a table pretending to be two tables.

Four CHECK constraints stand under it, and the Form Requests mirror them **in `after()`, not in the
rule array** — the agent's reasoning, and it is right: MariaDB evaluates a CHECK against the *final*
row, so on a partial update a declarative `after_or_equal:starts_at` only sees what was posted and
passes while writing a row the database will reject. `$fillable` omits `status`, `published_at` and
`is_featured` entirely, so an `events.edit`-only user cannot reach them by posting an extra field;
the policy keeps `changeStatus()` and `update()` independent, neither falling back to the other.

**Two bugs found on the way, both fixed at source.**

`DeliveryMode::Hybrid->icon()` returned `arrows-right-left`, which the icon component does not
carry — so every screen showing a hybrid class rendered the missing-icon placeholder, silently,
because an unresolvable icon is a box rather than an exception. Now `squares-2x2`, which is
registered and reads closer to the meaning anyway.

And **an all-day event dropped into "past" at 00:01 on the morning it was happening.** An all-day row
stores midnight, because that is what "no clock" means in a datetime column, and `effectiveEnd()`
compared that raw value against `now()`. The institute's own open day would have moved itself to the
archive hours before anybody arrived — invisible in testing, because it only shows on the one day the
event matters. Fixed in `effectiveEnd()` and in a single `applyWindow()` the two scopes now share, so
`hasEnded()`, `isUpcoming()`, `isInProgress()`, `upcoming()` and `past()` cannot give different
answers.

**SEO needed less than expected, and checking first is why.** The suspicion was that detail pages
were missing from the sitemap; they are not — `SitemapGenerator` has a provider seam with providers
already registered for blog posts, categories, tags, portfolio, services, team, job openings and
course categories. Events got the ninth. And `isPublicRouteKey()` is generic: any `site.*` GET route
with no parameters becomes an SEO target and a sitemap entry **automatically**, so all five new index
pages are covered with no registration at all.

The events provider keeps past events in the sitemap deliberately — the day it happened is content,
and dropping the URL the week after turns a page that earned links into a 404. It also demands
`published_at` be non-null and past, which is *stricter* than the page itself needs: a sitemap
listing a URL that 404s costs something on every crawl, while a public page missing from the sitemap
is found one link later. Where the two rules can disagree, the sitemap takes the conservative side.

**The Website Manager role exists to hold one button.** `SEO Expert` is deliberately an author — it
edits copy and metadata and cannot publish any of it. That left a gap: on a small team, the person
who *was* allowed to publish had to be an Admin, which means handing over HR, finance and the
collaborator ledger to get a publish button. The new role is everything the website is made of at
full ability, `change_status` included, and **not one permission outside it** — read-only on the four
institute modules the new pages surface, because a website manager decides whether `/fee-structure`
is visible, not what a fee is.

It cannot switch its own pages on, and that is recorded as **T58** rather than papered over: the
toggles live in the settings table and the only ability that writes one is `settings.edit`, which
also opens the SMTP credentials and the backup configuration. Paying the mail password for five
checkboxes was the wrong trade; the fix is a narrow `settings.edit_website`, in the shape
`settings.edit_mail` already established.

Verified: `view:cache` clean across the application, `route:cache` clean — which is what proves every
one of the 12 new routes resolves to a controller and method that exist.

### 2026-09-25 — Scroll effects for the public site, and the class that decides whether any of it happens

Asked for the public site to feel like a reference marketing site, with 3D scrolling. Built as a
**dependency-free** system — no GSAP, no ScrollTrigger, no Three.js — because everything wanted here
is a CSS transform and an `IntersectionObserver`, and a marketing page is the worst place to spend
150 kB of someone's data plan on a library that tilts cards. Total cost: **+1.5 kB CSS, +1.6 kB JS**,
across **81 effects and 8 parallax layers in 24 view files**.

**The design decision the whole thing rests on: an effect that can hide content must be opt-in from
a working runtime, never opt-out from a broken one.** Every entrance starts its element at
`opacity: 0`, which is only safe if something is *guaranteed* to finish. Three things stop that —
JavaScript never runs, the bundle fails, the visitor asked for reduced motion — so every hiding
declaration is scoped to `.fx-on` on `<html>`, and one inline pre-paint script is the only thing
that sets it. No class, no hiding: a crawler, a text browser, a blocked CDN and somebody with
vestibular disorder all get the complete page. There is no `<noscript>` fallback to keep in sync,
because the default state *is* the fallback. Verified by removing the class and measuring: **0 of
14 elements hidden, every one `opacity: 1, transform: none`.**

**`perspective()` sits inside each transform, never on a parent.** A parent with `perspective`
becomes a containing block for its fixed descendants, which silently re-anchors sticky headers and
modals inside that subtree. Per-element perspective costs nothing and cannot reach what it was not
applied to.

**The entrance is released when it ends, and that came out of a review.** An agent adding attributes
noticed the teaser card already carries `hover:-translate-y-0.5 transition duration-200`. The
problem was real and one specificity calculation deep: `.fx-on [data-fx].fx-in` is two classes and
an attribute against `.hover\:-translate-y-0\.5:hover`'s one class and a pseudo-class, so the card
would have **stopped lifting on hover the moment it finished appearing**, and anything still moving
would take 700ms instead of the 200ms the component chose. An entrance effect that permanently
rewrites the component it decorated is a bug wearing a feature's clothes. So `scroll-fx.js` removes
`data-fx` on `transitionend` — dropping the transition, the override and the `will-change` in one —
with a timeout as the safety net, because `transitionend` never fires for an element that was
already at its final value or sits in a throttled tab. Verified by walking the page: **14 revealed,
0 attributes left, 0 elements invisible.**

Parallax is driven by an observer too, not a `querySelectorAll` per frame: only elements actually on
screen are in the live set, so a long page with forty layers still measures the handful the reader
can see. Phones get the reveals and none of the scroll-linked work — a transform recalculated every
frame on the device least able to afford it, buying a few pixels of travel nobody reads as depth.

**What was deliberately left static, because motion is a cost paid by the reader:** prose bodies on
every `show` page (somebody arrived to read that course; fading it in paragraph by paragraph makes
reading feel like fighting the page), filter and search controls above the fold, the contact form,
breadcrumbs, pagination, empty states, the sticky asides, and the Alpine dialogs in
`success_stories` — an observer-driven transform on a `display: none` element either never fires or
fights Alpine's own show/hide. `inline.blade.php` got nothing at all: it is a one-line notice bar,
and a strip that slides in reads as a cookie banner. Quote cards and team portraits got the gentle
`rise` rather than `tilt`, because a quote you are meant to read and a person's face should not
swing in 3D.

Every Blade view compiles (`view:cache` clean across the whole application, four agents' edits
included).

### 2026-09-25 — A front desk with every permission it needed and nothing to look at

The request was "build the reception panel". The answer was that **it already exists and had been
invisible**, and the interesting part is which half was missing.

**The sidebar was already right, and needed no work.** `Receptionist` is a role on the `admin`
panel — phase-01 §214 places it there and every phase from 03 to 08-09 defines its grants against
that panel — and the architecture is deliberately five panels, a fact `CLAUDE.md` states in its
first line and phase-19-23 relies on in the shared meeting, notification and unread-counter code.
Filtering `Sidebar::adminTree()` by the role's 75 permissions already yields exactly a front desk:
**All Students, Admissions, Course Inquiries, Applications, Demo Classes, Student Fees, Fee
Receipts**, plus self-service and a collaborator lookup for picking the referring partner at
admission. Seven items in two groups, no configuration, no duplication. A sixth panel would have
meant a second set of controllers and views for screens that already exist, and a `PanelType` case
that half the shared infrastructure does not know about.

**The dashboard, by contrast, was completely empty for this role — all 38 widgets invisible.** Not
one of them is gated on a permission a Receptionist holds. Everything sits behind `*.view_reports`,
`*.view_financial`, `login_history.view_logs`, `settings.view_any` or a module the front desk has
no grant on. The near-misses are the sharp part: **`fee_collected_today`, `pending_fees` and
`overdue_fees` are gated on `student_fees.view_reports`, and the front desk holds
`student_fees` READ_CREATE only** — so the three cards about fee money were hidden from the person
who physically takes the fee money. `new_inquiries` misses the same way, wanting
`contact_inquiries.view_any` where the role has `view` alone (phase-04 §9.1.2, deliberately). A
role can therefore be perfectly configured and still sign in to a blank page, because widget
permissions were chosen per widget and never checked against the roles that would read them.

**Built: `WidgetGroup::FRONT_DESK` (sort 150, above Operations) and five widgets** in
`app/Dashboard/Widgets/Reception/`, each gated on a permission the role actually holds:

| Widget | Permission | Answers |
|---|---|---|
| `reception_applications_awaiting` | `student_applications.view_any` | how many are waiting, and how long the oldest has waited |
| `reception_open_inquiries` | `course_inquiries.view_any` | open, follow-up overdue, and never contacted at all |
| `reception_demos_today` | `demo_classes.view_any` | still to come today, no-shows, and who is next |
| `reception_fees_today` | `student_fee_payments.view_any` | net taken today, and how much of it by this user |
| `reception_admissions_range` | `admissions.view_any` | admissions in the period, and how many are stuck short of a class |

Four ignore the dashboard's date range and one honours it, which is a distinction rather than an
inconsistency: an inbox, a follow-up queue and today's appointments are **states** that mean
nothing filtered to last month, while "how many admissions did we take" is a **period** question
somebody compares against the period before. Admissions count on `admission_date`, not
`created_at` — a walk-in admitted Monday and typed in Wednesday belongs to Monday, and backdated
entry is normal at a front desk.

`reception_fees_today` is net of refunds: a receipt written for 20,000 and refunded by 5,000 is
15,000 in the drawer, and the question at six o'clock is whether the drawer agrees with the system.
Voided and bounced rows are excluded because that money never arrived; a fully refunded row is kept
because it nets to zero on its own arithmetic and the desk did write that receipt. Every figure is
summed by the database on `decimal(15,2)` and the single subtraction goes through `Money::sub()` —
no amount is ever a PHP float (golden rule 4). "Taken by you" splits on `received_by`, the user the
payment screen stamps, rather than guessing from `created_by`.

No status string is written in any of the five: the open sets are derived from
`StudentApplicationStatus::isOpen()`, `CourseInquiryStatus::isOpen()` and `AdmissionStage::isLive()`
so these cards cannot drift away from the list screens they link to (golden rule 8).

**The first version of them broke the dashboard's query budget, and the budget was right.**
`DashboardQueryBudgetTest` measures `GET /admin` as a Super Admin — who sees every card — and failed
at **66 against a ceiling of 60**. Twelve queries for five cards: each widget had been written the
obvious way, asking the table once per number it displays. Two wrong fixes were available and both
were refused. Raising `PAGE_CEILING` is the D171 antipattern — the test's own note says the ceiling
"catches a runaway, not a single extra lookup", and a ceiling edited each time it complains stops
being a ceiling. Marking the widgets `deferred()` is the same dodge wearing a costume: that flag is
for "a filesystem walk, an `information_schema` lookup", not for ordinary indexed aggregates, and
deferring the primary content of the front desk's own dashboard would be paying in the one user's
latency to make a number go down.

**The honest fix was to write the queries properly: twelve down to six.** Every figure on a card is
an aggregate over the same filtered rows, so each widget now counts them in one pass with
`SUM(CASE WHEN … END)` — applications 4→1, inquiries 4→1, fees 2→1 (the viewer's own share is a
conditional sum, not a second round trip), demos 2, admissions 1. Re-measured: **3 passed, 280
assertions.**

Two things came out of that pass and are worth keeping separately. `whereDate()` was removed from
`paid_on` and `scheduled_on`: both are already `DATE` columns, and wrapping them in `DATE()` makes
the comparison unindexable on the two tables that grow with every receipt and every booking. The
indexes it now reaches were verified to exist — `student_fee_payments.paid_on`,
`demo_classes['scheduled_on','status']`, `student_applications['status','created_at']`,
`course_inquiries['status','follow_up_date']`. And the applications card's second line changed from
"N arrived today" to "N **of them** from today", which costs one query fewer and is the better
number anyway: a backlog of twelve that all arrived this morning and a backlog of twelve that has
been accumulating for a fortnight are different problems, and only the second phrasing tells them
apart.

### 2026-09-24 — Phase 24 continued: the five commands, and what they found on their first run

**The tools were the deliverable, and then the tools reported.** `security:audit`,
`security:route-manifest`, `integrity:verify`, `perf:budget` and `a11y:scan` joined
`audit:manifest`, and `composer harden` runs all six plus the suite in one command — ordered
cheapest first, because composer stops at the first failure and a drifted manifest should cost a
second to find rather than four minutes.

`integrity:verify` proves nothing itself. Every suite is a command the owning phase already wrote —
`financial:verify-constraints` is the spine's, `collaborators:reconcile-wallets` is Phase 12's — and
a second implementation of "does the ledger balance" is a second opinion, which is the one thing
worse than none when the two disagree. What it adds is the row: a nightly sweep that quietly
stopped running looks exactly like one that keeps finding nothing.

**Then they ran, and the first run was not quiet.**

`security:audit` found stored XSS in messaging (D163) — printed raw with nothing sanitising it on
write, in a field whose own model docblock says it is always rendered escaped. It found the
raw-echo allowlist holding four rows against nineteen raw echoes, twenty-seven inline scripts with
no nonce that the CSP shipped hours earlier would have refused *silently* in production, and the
`local` disk registering `GET`/`PUT /storage/{path}` routes against `storage/app/private` that
nothing in this codebase generates signatures for.

`a11y:scan` reported 91 findings across 92 screens, and four classes of them were the scanner
(D164, D166). What survived was real: a component hard-coding `<h3>` under the page `<h1>` on
twenty-three screens, 1,167 permission checkboxes named only by `title`, a notification grid named
only by a column header a reader never reaches, and eight tables on one page that all announced
themselves as "table".

`ContrastTest` found the badge palette failing AA on thirteen of fifteen colours in light mode and
all fifteen in dark (D167).

**And `A11y` could not run outside PHPUnit at all** (D165), which meant the console scan that was
supposed to be the nightly sweep produced 91 internal errors instead of 91 findings.

Everything above is fixed. `security:audit --suite=static` passes; `a11y:scan` reports 91 screens
scanned and every one passing; `security:route-manifest` shows 1,271 rows and no drift.
`security:audit --suite=runtime` correctly flags 17 demo accounts still on seeded passwords —
warnings on this machine, failures in production, which is the distinction the severity split
exists for.

Commits `63a1ce3`, `1a61f97`, `f774b93`.


### 2026-09-24 — Phase 24: the settings, the hardening runtime, and a middleware that switched authentication off

**Sections 4 and 5 first, because the runtime reads them by name.** The `ops` group (sixteen keys),
eighteen keys added to `security`, the `backup` group (thirty keys) and the private `backups` disk
they default to; the module slugs `system_health` (91) and `integrity_checks` (92), neither core;
`backups` gaining `restore` and `view_logs`. Admin reaches `system_health` whole and
`integrity_checks` only to read — `everythingExcept()` is a default-allow list, so `create` and
`view_logs` are withheld **by name**, because whoever can produce evidence on demand can produce it
until it says what they want.

Three secrets are stored encrypted and two of them default to **null rather than to a generated
value**. A registry default is a pure literal re-read on every seed run; one that generated a fresh
token would replace the token an external monitor already holds, every time somebody ran the
seeder. They are generated once, on first use, where the act can be logged.

**Two validation rules that could not do what they said.** The settings round-trip test —
"re-submitting a form exactly as rendered must be accepted" — refused the backup group, and the
reason was `prohibited_unless:encrypt_archives,1` on `include_env`: that rule refuses a field that
is *present*, and an unticked checkbox posts `false`, which is present. It had been refusing every
save, ticked or not. Its sibling `required_if:encrypt_archives,1` on the archive password was the
opposite failure — Laravel compares a dependent value strictly against a real boolean, so `1`
matched nothing and **encryption could have been switched on with no password at all**. Both probed
against Laravel's own `Validator` rather than reasoned about (D159).

**The bug that mattered most switched authentication off.** `EnforceSessionLifetime` belongs in the
authenticated stack, and `auth` here is a middleware *alias*, not a group. `appendToGroup('auth',
...)` created a **group** of that name, groups win over aliases when a route resolves its
middleware, and every route declaring `->middleware(['auth', ...])` stopped running `Authenticate`.
Nothing checked whether anybody was signed in. It fails open and it fails silently: the screens
still render, for everyone, and 271 authorization tests still passed — because every one of them
acts as a signed-in user. The test that caught it asserts a **guest** is redirected away from
`/verify-email`, and got a 200 (D158).

**Strict mode found wrong data, not slow pages.** Walking all 202 admin screens turned up exactly
one lazy load in twenty-three phases — the codebase eager-loads well — but
`preventAccessingMissingAttributes` found four narrowed `select()`s whose missing column was read
anyway, and in each case the old behaviour was a silent null: a project manager with an uploaded
photo shown generated initials; `Employee::offDays()` falling through to the system-wide weekend, so
somebody on a Tuesday-off shift was marked absent every Tuesday; the messaging recipient picker
asking each candidate whether it was active, getting no answer, and denying all of them. A column
you did not select does not read as null — it reads as *no answer*, and every one of these treated
that as a value (D160).

**And `X-Query-Count` found the two N+1s a lazy-load exception cannot see**, because both were
explicit queries written out in a loop: `MessagingMatrix` asking the initiator three membership
questions once per candidate (66 queries → 13), and `usersHolding()` loading every active user and
calling `can()` on each to build one dropdown (52 → 17). The second grows with the payroll (D161).

Error pages are deliberately self-contained — no Vite, no components, wrapped settings reads —
because a 500 page that needs the database cannot render the failure the database caused, and no
inline JavaScript anywhere, because the CSP this phase ships would leave a `onclick` button working
in development and dead in production (D162).

Probes: settings 192 checks, modules and grants 193, hardening 172 — 557 in all, 0 failures.
Suites: Settings 28, Auth 60, Rbac + Panels + Account + Smoke 271, Crm 6 — all passing.
Commits `2e5c098`, `2cab516`, `f868fbf`.


### 2026-09-24 — Phase 23: everything that reads the system, and the sign that would have lied

**33 reports, and every one of them delegates.** `ReportRegistry` is the fourth registry and takes
the same shape as `DashboardRegistry`: a report is one class dropped into `app/Reports/`, with no
route, controller or view to edit. `ReportEngine` is §9.5's six steps written out in order, and the
step that could not live in the engine is step 4 — the engine does not know that a project is scoped
by membership and a student by branch and course, so the source module's own isolation stays with
the delegate. Step 6 does live there, and the surviving column keys are passed **into** `run()`
rather than filtered out of its result: filtering afterwards would still have queried the withheld
value, cached it, and written it into the file the export job built from that cache.

**The bug that mattered most was a sign convention.** In `collaborator_commission_ledger_entries`,
`amount` is a magnitude and `signed_amount` is the figure — a 2,500 clawback is stored as
`amount = 2500.00, entry_type = debit`. I wrote the trait docblock warning about exactly this and
then used `amount` in four reports anyway. `co.commission_reversals` printed a clawback as
**+2,500**, reading as earnings, and `co.student_commission` overstated a 14,000 ledger by 5,000,
because a reversal counted as a credit swings a total by twice its value. The reports now read
`signedAmount()` and the sweep asserts the reconciliation rather than my having checked once: both
commission reports together, `co.performance`'s earned column, `SUM(wallet.lifetime_earned)` and
`SUM(ledger.signed_amount)` all agree. That is CLAUDE.md §5's "wallet balances must always be
re-derivable by summing the ledger" holding through the report layer.

**`in.pending_fees` was showing nothing while 40,000 was outstanding.** It scoped on
`due_date BETWEEN`, and every fee in the system has a null due date — so the entire collection desk
was empty. A balance that is invisible because nobody set a date is exactly the balance nobody
chases. Undated charges are now always in scope, and when a student has no dated charge at all the
amount to quote is the whole balance rather than zero, which was the same bug one level down.

**§107 withholds values, never rows.** The obvious implementation hides the row, and an audit trail
with holes in it is not an audit trail — somebody looking at a gap cannot tell whether nothing
happened or whether they were not allowed to see what did. A financial row's figures become a marker
naming the permission that would open them; the row stays, and the marker travels into the exported
file, where the omission would otherwise be invisible. The probe asserts both halves separately,
because passing one while failing the other is the plausible failure.

**§108 needed two students with one name to be testable at all.** With a single student in the
database the rule looks right either way, so the probe creates two inside a rolled-back transaction:
the student finds exactly themselves, while a Super Admin on the same term finds both. That second
assertion carries as much weight as the first — without it the test would pass on a search that was
simply broken. It caught a real leak: a student holds `students.view` so they can read their own
record in the portal, and that ability alone was enough to generate an `/admin/students/…` link.
The palette looked correct and every result would have 403'd. Reaching an `admin.*` route is about
which panel your roles are on, not which permission you hold.

**Three permission defects, two of them pre-existing.** §4.3 says Admin is everything except
`print_templates.delete`; the seeder's exclusion list never carried it, so every install had it —
and deleting a template destroys the only record of how already-issued certificates looked. D62 says
a document counter is advanced by `DocumentNumberService` and nothing else;
`support.ticket_next_number` was not `readonly`, so an administrator who opened the settings screen
before a busy hour and saved it afterwards would roll the counter back and hand the next few tickets
numbers that already exist. Both are corrected in the seeder and, where a seeder cannot revoke (D65),
by a dated migration.

**Ten read methods were added to the services that own them** rather than built inside the reports:
`ExamStatisticsService::forBatch/forCourse`, `CourseMaterialService::engagement`,
`CertificateService::register`, `TicketService::queue`, `TicketSlaService::breaches`,
`AssignmentService::compliance`, plus `Student::enrollments`, `Course::batches`,
`Course::enrollments` and `CollaboratorReferral::student`. Four of them turn on a date choice that
decides whether the figure is honest — certificates count issued and revoked on different dates,
tickets count created and resolved on different dates, and breaches are counted where the ticket was
resolved so the rate sits over the tickets it is a rate of.

**Files.** `app/Enums/{ReportColumnType,ReportFilterType,ChartType}.php`,
`app/DataObjects/{Reporting,Search}/*`, `app/Reports/**` (36 classes),
`app/Support/{ReportRegistry,GlobalSearchRegistry}.php`,
`app/Services/{Reporting,Audit,Search}/**`, `app/Search/**` (13 classes),
`app/Jobs/Reporting/BuildReportExport.php`, `app/Models/Reporting/ReportExport.php`,
`app/Policies/Reporting/ReportExportPolicy.php`, `app/Http/Controllers/Admin/Reporting/**`,
`app/Console/Commands/Reporting/**`, `resources/views/admin/{reports,report-exports,analytics,audit-trail,search}/**`,
`routes/admin.php`, `routes/console.php`, `app/Support/{Sidebar,SettingsRegistry,PermissionRegistry}.php`.

**Migrations.** `2026_09_24_100001_create_report_exports_table`,
`2026_09_24_100002_add_reporting_indexes_to_activity_log_table`,
`2026_09_24_100003_assert_attachment_morphs_for_support_tables`,
`2026_09_24_100004_withdraw_print_template_delete_from_admin`.


### 2026-09-24 — The six triggers, and an invariant that was resting on discipline

**`NotificationService` now marks every notification `afterCommit` itself** rather than trusting
each caller to dispatch outside a transaction. The class docblock had claimed INV-22-8 all along;
`config('queue.connections.*.after_commit')` is false in this installation, so the guarantee was
resting entirely on every caller remembering. Making it real broke the fee reminder within seconds,
and what it exposed is D152: **when a savepoint commits, Laravel drops that level's after-commit
callbacks** rather than running them or handing them up. A dispatch from inside a nested transaction
vanishes — nothing logged, nothing sent, and a reminder row sitting there saying somebody had been
told. Every dispatch in the system now happens after its service's transaction returns.

**The six triggers Phases 19–21 owed are wired.** The registry entries for `material.published`,
`assignment.published`, `result.published`, `certificate.generated`, `certificate.revoked` and
`idcard.issued` had existed since the notification slice and fired nothing — which is worse than
having no entry, because the preference screen offers to mute something that was never going to
arrive.

**The audience is where these go wrong, so that is what the probe asserts.** A result goes to each
student who has a row and nobody else: publishing to the whole batch would tell every student
something about everybody else. A material goes to the union of its **student, batch and course**
targets — reading only `target_student_id` would have reached almost nobody, because sharing with a
batch is how a material is actually shared. A revoked certificate goes to the holder *and* to
`certificates.view_any`, because somebody may present it in good faith to an employer who checks it.

**Three column names were wrong on the first pass and the schema said so**, which is the argument
for writing rows rather than reading the model: `course_material_targets` has `target_student_id`
not `student_id`, `assignments` has `deadline_at` not `due_at`, and `exams` has `name` not `title`.
Writing the fixtures also turned up `chk_cm_payload` (a material is a file or a link, never both and
never neither) and `target_key` being generated.

**Files.** `app/Services/Support/NotificationService.php`,
`app/Services/Institute/{FeeReminderService,CourseMaterialService,AssignmentService,ExamResultService,CertificateService,StudentIdCardService}.php`.

### 2026-09-24 — Phase 22 closes: seven sweeps and the four portals

**Seven scheduled commands, each idempotent at its source** (D150). The probe runs every one,
asserts what moved, then runs it again and asserts nothing moved — 41 checks. `tickets:sla-sweep`
notifies at most twice in a ticket's life, once per kind, because the breach booleans are stamped in
the transaction that selects the row; without that it would page the assignee 144 times a day about
one ticket. `tickets:auto-close` never closes a ticket whose requester replied after it was
resolved, because that reply is somebody saying "it is not fixed" and closing it answers them with
silence. `meetings:close-past` waits two hours past the end, because a meeting that overruns is
still a meeting. `notifications:prune` deletes only *archived* rows — an unread notification is
somebody's outstanding record of being told something, and age is not consent.

**The four portals share three controllers and seven partials**, the same argument as the bell: a
client, a student, a teacher and a collaborator all raise tickets, sit in meetings and send
messages, and what differs between them is who the *person* is rather than which URL they arrived
at. Twelve controllers would have agreed about that until one of them was edited.

**The portal screen probe found two real defects once its own noise was removed** (D151).
`SupportTicketPolicy::viewAny()` had never accounted for portal users — every portal's ticket list
answered 403 while the create form and the detail page beside it both worked — and
`collaborator_portal.support_tickets` had never been declared, making the collaborator the one
portal of four whose ticket screen existed and refused everybody.

**The client panel moved off Phase 5's own bell and read screens.** Its `tickets`, `meetings` and
`messages` sections were registered in `ClientPortalRegistry` by nobody, so those three routes had
never rendered anything — filling them was always this phase's job, and the
`// Phase 22: client writes` marker Phase 5 left is where they went. The four now-unreferenced
`App\Http\Controllers\Client\*` classes are left in place rather than deleted: deleting another
phase's code to tidy up is how a later phase loses something it did not understand.

**Portal sidebar entries are gated on their subject module**, not on the panel namespace or a
neighbouring business module. `meetings`, `messages`, `support_tickets` and `notifications` are what
the route middleware checks, so the menu and the route now give the same answer — previously a
switch could hide a link whose route still worked, or show one that 403s. One older assertion had to
change rather than be satisfied: switching `collaborators` off no longer empties that sidebar,
because a collaborator keeps a support desk and a bell and neither was ever about that module.

**An environment incident worth recording.** MariaDB died mid-session and would not restart, dying
silently each time at the point where it loads the grant tables. `aria_chk` found `mysql.db` with a
3.4 MB index against a 28 KB data file and zero readable records, and twenty-one other system tables
marked crashed. The system-table directory was backed up first, then `--safe-recover` repaired all
of them; `mysql.global_priv` came back with its five accounts intact. Both application databases
were untouched — 173 tables, 18 roles, 19 users on each. The one real loss is `mysql.db`, which on
this installation held only the default `test` grants; the application connects as root, whose
rights come from `global_priv`.

**Files.** `app/Console/Commands/Support/` (seven commands), `app/Http/Controllers/Portal/` (three
controllers plus `Concerns/ServesPortalSupport`), `app/Http/Requests/Portal/`,
`app/Events/Support/{TicketSlaBreached,MeetingReminderDue}.php`,
`app/Listeners/Support/{NotifyOfSlaBreach,NotifyOfMeetingReminder}.php`,
`app/Notifications/Support/DailyDigestNotification.php`, `routes/portal-support.php`,
`routes/console.php`, `routes/{client,student,teacher,collaborator}.php`, `app/Support/Sidebar.php`,
`app/Support/PermissionRegistry.php`, `app/Policies/Support/SupportTicketPolicy.php`,
`app/Services/Support/{TicketSlaService,TicketService,MeetingService,NotificationService}.php`,
`resources/views/support/{tickets,meetings,messages}/`,
`resources/views/{client,student,teacher,collaborator}/{tickets,meetings,messages}/`,
`resources/views/client/notifications/`, `tests/Feature/Modules/SidebarVisibilityTest.php`.

**What Phase 22 still owes, and it is small:** the *trigger* call sites in Phases 19, 20 and 21. The
registry entries for `material.published`, `assignment.*`, `exam.scheduled`, `result.published`,
`certificate.*` and `idcard.issued` exist and are preference-able; the services that perform those
acts do not yet dispatch them. §10.3 assigns the trigger to the owning phase, so each is one
`dispatch()` call in that phase's own service.

### 2026-09-23 — Phase 22 screens: 40 routes, five controllers, twenty-one screens

**Every GET screen rendered through the HTTP kernel against real data — 21 of 21**, and the probe
earned its place on the first run: the ticket queue and the SLA desk were both 500s, because I had
written `sla_resolution_due_at` and `last_activity_at` and the columns are `resolution_due_at` and
`last_reply_at`. Neither lint nor any unit test sees a column name inside an `ORDER BY` string; the
only thing that finds it is rendering the page.

**The sidebar's Workspace group had been a placeholder since Phase 1, and carried two bugs.** It
pointed at `admin.support-tickets.index` — the module slug, not the route name §7.6 gives. And it
gated Messages on `messages.view_any`, the compliance reader's permission that §9.4 grants to nobody
and that the migration earlier today withdrew from Admin and Support Agent. Left alone, the
messaging screen would have been invisible to everybody meant to use it. It is `messages.view` now,
and Support Tickets and Meetings take either of their two permissions, because §9.4 narrows the
*query* rather than the menu.

**The bell is one routes file and one Blade partial.** §7.7 says it behaves identically on all five
panels; the only honest way to guarantee that is not to write it five times.
`routes/notifications.php` is included by each panel's own group so it inherits the prefix and the
middleware, and `NotificationController::panel()` reads the current panel from the **route name**
rather than the URL — which is what lets one controller and one partial serve them all.

**The client panel still runs Phase 5's own bell**, whose view expects a different set of variables.
Migrating it is listed below rather than done, so it cannot become the silent drift §7.7 warns
about.

**The recipient picker asks `MessagingMatrix`, not the user table** (§8.13). A picker built from the
user table would offer a student every client in the installation, refuse each one on submit, and
hand out a directory of everybody's name on the way.

**A clash the institute allowed is shown rather than swallowed.** `MeetingService` refuses a room
double-booking and returns the rest as warnings; the controller flashes them as a second message
naming each conflict, because a booking that quietly overlapped somebody else's is one nobody
discovers until they are both standing in the corridor.

**Files.** `routes/{admin,student,teacher,collaborator}.php`, `routes/notifications.php`,
`app/Http/Controllers/Admin/Support/` (four controllers), `app/Http/Controllers/Support/NotificationController.php`,
`app/Http/Requests/Admin/Support/` (five requests), `app/Support/Sidebar.php`,
`resources/views/admin/{tickets,meetings,messages,ticket-departments,notifications}/`,
`resources/views/support/notifications/` (the two shared partials),
`resources/views/{student,teacher,collaborator}/notifications/`,
`tests/Feature/Modules/SidebarVisibilityTest.php`.

**Still owed by Phase 22:** the four portal panels' ticket, meeting and message screens; the client
panel's bell migration; and the scheduled commands — `meetings:send-reminders`, the SLA breach
sweep, the daily digest and the retention prune. The trigger call sites in Phases 19-21 are still
outstanding too: their registry entries exist and are preference-able, but the services that perform
those acts do not yet dispatch them.

### 2026-09-23 — Phase 22 policies, and a permission three roles should never have held

**Seven policies over §9.4's table**, and the probe over them found something the code would never
have reported: **`messages.view_any` was granted to Admin and to Support Agent**, when §9.4 says it
is granted to nobody. Every support agent in the installation could read every private conversation
in it. The cause is one line per role — `permissionNamesFor(['support_tickets', 'messages',
'meetings'])`, which returns every ability those modules declare — and the symptom is that the
threads simply appear to somebody with every reason to think they are meant to. D149 has the three
rules that came out of it; the correction is a dated, reversible migration, because D65 rightly
forbids a seeder revoking what an administrator holds.

**Being in the room is a right, and it is not a permission.** A client invited to a kick-off holds
no `meetings.*` permission at all; a student holds none on `support_tickets` or `messages` either.
Every check in `MeetingPolicy` and `ConversationPolicy` therefore starts with "are they on the guest
list" and only then asks what a permission adds — a policy built the other way round locks the
people the meeting is *for* out of it.

**`SupportTicketPolicy::view()` has two completely different answers and both are written out.** A
holder of `view_any` sees the queue, branch-scoped. Anybody else sees the tickets they are in, and
for a client that means their company's — *except* one marked `is_private_to_creator`, which is
phase-05 §12 Q3's shape: a company shares an account, not a diary. Folding those into one condition
is how a policy ends up letting a colleague read a private ticket because the client clause happened
to be evaluated first.

**Replying is `view` plus being involved, never `edit`.** A client may never edit a ticket and must
always be able to answer one; a reply composer gated on `edit` is how a portal goes read-only by
accident.

**A requester may reopen their own ticket and do nothing else.** That is the single status move
§2.28.7 gives a portal, so `changeStatus()` is not a flat permission check. Priority is staff-only
(§12.2 Q6): a requester who could declare "urgent" would, every time, and within a month the word
would mean nothing.

**`messages.view_any` reads and never writes**, and `ConversationPolicy::send()` refuses it
explicitly. A reader who could also write would be a voice inside the §94 matrix that the matrix
never saw.

**`messages` gained `change_status`**, which §6.18's `close()` names and nothing had declared.

**`MessagePolicy` and `TicketReplyPolicy` are almost entirely `false`, on purpose.** The models
already refuse every edit and every delete below the gate, so these exist to keep the buttons off
the screen — Support Agent still holds `messages.edit` and `.delete` from the earlier seeding, D65
keeps them, and a view that asked the permission would offer an edit that throws. The policies make
inert rows look inert from the outside too.

**The probe asserts the gate as well as the policy.** For a Super Admin every one of these returns
true, because `Gate::before` runs first — so each invariant is checked twice: the policy says no to
everybody else, and the model throws on the Super Admin. That is D124 stated as a test rather than
as a paragraph, and it is the check that would catch somebody moving a rule back into a policy.

**Files.** `app/Policies/Support/` (`SupportTicketPolicy`, `TicketReplyPolicy`, `MeetingPolicy`,
`ConversationPolicy`, `MessagePolicy`, `TicketDepartmentPolicy`, `Concerns/ChecksSupportPermissions`),
`database/migrations/2026_09_23_100001_withdraw_messages_view_any_from_seeded_roles.php`,
`database/seeders/RoleSeeder.php`, `app/Support/PermissionRegistry.php`. No policy registration was
needed: `App\Models\Support\X` resolves to `App\Policies\Support\XPolicy` by Laravel's own
guesser, which the probe confirmed rather than assumed.

### 2026-09-23 — Phase 22 notifications: a registry, a channel, and six listeners

**Fifty-three events declared in one place**, for the same reason the permissions and the settings
are: a `notification_events` table can drift from the code that dispatches into it, and a typo'd key
would create a row nobody ever sees a preference for. `NotificationRegistry` is the fourth registry
and follows the shape of the first three exactly.

**The insert turned out to be the hard part.** `notifications.event_key` is `NOT NULL` with no
default and Laravel's `DatabaseChannel` writes four columns — `id`, `type`, `data`, `read_at` — so
every notification in the system was one dispatch away from a 1364, including the fourteen Cms and
Crm classes whose trait had said since Phase 4 "use the database channel once Phase 22 ships the
table" and which switched themselves on the moment the migration ran. The obvious fix, an UPDATE
after `dispatch()`, cannot work: these are `ShouldQueue`, so the row is written by a worker some
time after the call returned, and the update matches nothing at all. `RichDatabaseChannel` builds
the columns into the insert instead (D146).

**`forUser()` needed a fact the contract had a field for and I had not used.** `requiredPermission`
cannot answer "could this ever reach you": a student holds no permission whatsoever on `meetings`,
`messages` or `support_tickets` — their access runs through `student_portal.*` — yet they are
invited to meetings every day. Gating on module permissions would hide exactly the rows they need;
gating on nothing offers them "a wallet disagrees with its ledger" to mute. Each event now declares
which panels it reaches, which is the contract's own wording written down rather than inferred
(D147).

**The preference cache had two owners for about an hour**, and the probe caught it: an event muted
and dispatched against in the same request still arrived, because the save went to one cache and the
read came from the other. One owner now (D148).

**`support_tickets.view_reports` is declared.** §10.3 names it as `ticket.sla_breach`'s audience and
nothing had ever declared it, so the audience resolved to nobody and the most urgent notification in
the support module was the one guaranteed to reach no one. `PermissionRegistry` gains `REPORTS` on
that module; the seeders are idempotent and created exactly one row.

**Six listeners, each doing one thing: work out who should hear.** None of them decides a channel, a
level or a link — the registry owns those, so a change to how a meeting notification looks is one
edit rather than four. The judgements they *do* make are the ones §10.3 spells out and a reasonable
implementation would get wrong:

- an **internal note** reaches staff and never the requester, because the whole point of the
  distinction is that the requester does not know it exists;
- **nobody is told about their own act** — their own reply, their own meeting, their own message,
  their own reopen. A notification about something you just did reads as a bug;
- **`open` to `in_progress` tells the requester nothing.** That is a desk managing its own queue;
  resolved, closed and reopened are the three that change what the requester should do next;
- a **muted thread still moves the unread count.** Muting asked to stop being interrupted, not to
  lose messages;
- an **external meeting guest gets no bell row**, because they have no account to put one on
  (PH22-37) — they are reached by the invitation and the `.ics` with it.

**The fee reminder now actually reminds somebody.** Phase 18 wrote the row and then checked
`class_exists()` on two classes §13.3 assigned to Phase 22; Phase 22 shipped a registry instead, so
that guard was never going to fire and a student was never going to hear anything. `FeeReminderType`
now names an **event key** rather than a class, and `FeeReminderService` dispatches through
`NotificationService` — which is the only thing that reads the student's preferences, the module
gate and the mail master switch (INV-22-7). The reminder row is still written first and
unconditionally, because "we decided to chase them" is worth recording on a night nobody could be
reached, and the dedupe guard has to hold across that gap.

**Files.** `app/Support/{NotificationRegistry,UnreadCounters}.php`,
`app/Notifications/{NotificationEvent.php,Channels/RichDatabaseChannel.php,Support/RegistryNotification.php}`,
`app/Services/Support/{NotificationService,NotificationPreferenceService}.php`,
`app/Services/Support/Exceptions/InvalidNotificationEvent.php`,
`app/DataObjects/Support/{ChannelSet,AudienceInput,DispatchResult,BellPayload,PreferenceMatrix}.php`,
`app/Events/Support/{TicketCreated,TicketReplied,TicketAssigned,TicketStatusChanged,MessageSent}.php`,
`app/Listeners/Support/` (six classes plus `BuildsTicketLinks`),
`app/Providers/EventListenerServiceProvider.php`, `app/Support/PermissionRegistry.php`,
`app/Enums/FeeReminderType.php`, `app/Services/Institute/FeeReminderService.php`,
`app/Notifications/Cms/Concerns/BuildsCmsNotification.php`,
`app/Notifications/Cms/ScheduledPagePublished.php`.

**Still owed by Phase 22:** the policies, the routes, the controllers and the screens across five
panels; the scheduled commands (`meetings:send-reminders`, the SLA breach sweep, the digest and the
retention prune); and the *trigger* call sites in Phases 19, 20 and 21 — the registry entries for
`material.published`, `assignment.*`, `exam.scheduled`, `result.published`, `certificate.*` and
`idcard.issued` exist and are preference-able, but the services that perform those acts do not yet
dispatch them. §10.3 assigns the trigger to the owning phase, so each is one `dispatch()` call in
that phase's service.

### 2026-09-23 — Phase 22 services: the matrix, the threads, the queue, the diary

**Six services, each probed before the next was written**, in the order their dependencies run:
`MessagingMatrix` → `ConversationService` → `TicketSlaService` → `TicketAssignmentService` →
`TicketService` → `MeetingService`. 263 probe checks across the six, all green, every one inside a
rolled-back transaction.

**`MeetingService` needed the clash detector to be able to see a meeting at all.** It could not.
`ScheduleClashDetector` scans a date column and two TIME columns, and a meeting has a datetime —
so the comparison it would have run is `where('scheduled_at', '<', '13:00:00')`, which matches
nothing and returns clean. Every boardroom in the building was one release away from being
double-bookable by a check that ran and said yes. Three generated STORED columns on `meetings` now
present the shape the detector already reads, `registerPhase22()` declares the occupant, and D144
records both halves: fit the shared abstraction rather than widen it, and watch the edge, because
the conversion's failure mode is "returns nothing" and that reads exactly like "all clear".

**Midnight is that edge.** A 23:00 meeting running two hours ends at 01:00 — a *time* earlier than
its start — so `start < end` finds no overlap and misses every conflict. `meeting_end_time` is
clamped to `23:59:59`, which keeps the start day correct and leaves only the small hours of the next
day unchecked. Written into the migration, not discovered later.

**A room clash is refused and a person clash is a warning.** `support.meeting_room_clash_block` is
the switch (PH22-35). Two meetings in one room is a physical impossibility somebody discovers at the
door; one person invited to two things is an ordinary Tuesday that the person is best placed to
resolve. The non-blocking conflicts come back on the returned model through `withClashWarnings()` —
deliberately **not** a column and not an attribute, because a remark shown once in a toast is not a
record, and putting it in `$attributes` would send it to `fill()`, `toArray()` and every JSON
response. A meeting loaded from the database reports no warnings, which is the honest answer: the
row cannot say whether it clashed when it was booked.

**Moving a meeting clears every acceptance but the organiser's.** An invitation accepted for Tuesday
is not an acceptance for Thursday, and a quorum built from stale acceptances is a meeting nobody
turns up to (PH22-34). `MATERIAL` is the list that counts — time, length, room, mode, joining link —
so a corrected typo in the title does not reset twelve answers or post twelve notifications. The
probe asserts `MeetingUpdated` fired **exactly once** across a retitle and a move, and that it named
`scheduled_at`. The organiser keeps their own acceptance: they chose the new time, and asking them
to accept their own meeting would leave every rescheduled meeting one answer short of quorum.

**`participant_type` is derived, never posted.** `App\Support\ParticipantResolver` is the one place
a user is filed under one of five headings, with a stated priority order rather than whichever query
returned first — a person can hold several profiles, the row holds one type, and §9.4's scope reads
that column to decide what they may see. It resolves `staff` with a null `employee_id` for an admin
who predates the HR module, because refusing there would make the diary unbookable by the person
most likely to be booking it.

**The `.ics` lists the viewer and nobody else.** A calendar file carrying twenty colleagues' email
addresses is an address book, and it would be handed to every outside guest who accepted. `SEQUENCE`
counts the reschedules by walking `rescheduled_from_id`, because a file that always said `0` is
ignored by a calendar as a duplicate of the original and the new time never appears. Folding is on
octets and never inside a multi-byte character.

**Nothing is deleted, in any of the six.** A ticket is closed, a reply is corrected by another
reply, a meeting that will not happen is cancelled with a reason and one that moves is postponed
with a successor pointing back at it. `chk_me_cancel` requires the reason at the database for both,
and `uq_me_resched` makes a reschedule a chain rather than a fan — two coordinators moving the same
meeting at once produce one successor and one refusal, not a fork.

**Files.** `app/Services/Support/{MeetingService,TicketService,TicketSlaService,TicketAssignmentService,ConversationService}.php`,
`app/Services/Support/Exceptions/SupportRuleException.php`, `app/Support/{MessagingMatrix,ParticipantResolver}.php`,
`app/DataObjects/Support/{MeetingData,ParticipantInput,CalendarQuery,MessagingDecision,SlaMinutes}.php`,
`app/Events/Support/{MeetingScheduled,MeetingUpdated,MeetingRescheduled,MeetingCancelled}.php`,
`database/migrations/2026_09_19_100012_add_clash_columns_to_meetings_table.php`,
`app/Providers/AppServiceProvider.php` (`registerPhase22()`, the scoped `ParticipantResolver`),
`app/Models/Support/Meeting.php` (the transient clash warnings).

**Still owed by Phase 22:** `NotificationRegistry`, `NotificationService`,
`NotificationPreferenceService`, `UnreadCounters`, the policies, the routes, the controllers, the
screens across five panels, and the notification classes Phases 18, 19 and 21 are waiting on.

### 2026-09-23 — Phase 22 begins: twelve enums, ten tables, nine models

**Nothing user-facing yet, and everything probed.** The schema, the enums and the models are in, each
checked before the next was written — the order the pre-flight practice (D136) asks for, and the
reason there is nothing to unpick.

**Twelve enums, and one of them has load-bearing declaration order.** `ConversationScope` carries
§94's six pairs verbatim, and a pair that resolves to several scopes takes the **first** match — the
value that then lands in `conversations.pair_scope`. Reordering the cases would silently re-label
existing threads, so `inMatchOrder()` exists to name the fact rather than leave it to a comment
somebody edits around.

**`ParticipantType::isInternal()` had to be decided rather than looked up.** The contract names the
method and not its answer. Staff and teachers are internal; students, clients and collaborators are
not; an external guest has no panel, no profile column and no account at all, and `chk_mp_external`
refuses a row whose type contradicts the columns beside it — because that type feeds §9's isolation,
so a label that could disagree with its own row would be a way to declare yourself into another
scope.

**Ten tables, run forward, rolled all the way back, and run forward again.** Two things only the
rollback could find. A bare `NOT NULL` timestamp is the *second* timestamp column on its table, so
MariaDB hands it an implicit zero-date default and `NO_ZERO_DATE` refuses it — 1067, at create time,
on a column that looks entirely ordinary. And `meetings.rescheduled_from_id` is UNIQUE so a
reschedule is a chain rather than a fan, which means the self-referencing foreign key **needs** that
index: MariaDB refuses to drop the index while the constraint exists, so the constraint has to go
first. That dependency runs the opposite way from the usual one, which is exactly why it is easy to
miss and now carries a paragraph.

**Every enum-backed column is `string(32)` where the contract says 16.** `TicketAssignStrategy`'s
longest case is `default_assignee` — sixteen characters, which fits *exactly*, and an exact fit is
the most dangerous width there is. D126 was the phase that learned it.

**A 407-check schema probe and a 63-check behavioural probe, both clean on the first run**, which has
not happened before in this project. The schema probe asks `information_schema` for every column,
index, CHECK, generated column and foreign key the contract names, measures all twenty enum columns
against their own cases, and re-runs D125's sweep that every foreign key leads an index of its own.
The behavioural probe writes inside a rolled-back transaction and asserts the hooks refuse what they
claim and permit what they claim.

**The behavioural probe's refusal helper is worth copying.** Its first version was
`try { … } catch (Throwable) { pass }`, which would have gone green on a typo in a column name, a
missing method, or a *different* constraint firing on the same row — the "silence is not success"
trap, in a probe whose whole job is to be the thing that notices. Each call now names the layer it
expects to be stopped by (`model` or `database`) and, for a database refusal, the constraint by name.
Sixty-three checks still pass, and now they mean something.

### 2026-09-23 — Phase 21: certificates, the public verification page, and student cards

**Shipped.** Four tables, five enums, four models, one registry, six services, three policies, three
Form Requests, five controllers across three panels, 48 routes, 20 screens, one seeded template per
printable kind, and one public page that anybody can reach without an account.

**The spine.** A template is HTML with `{tokens}` in it, sanitised on save and again on render, and
never compiled. A certificate is drafted from an enrolment with every rule's verdict on screen,
issued with a number it keeps for ever, and from that moment says exactly what it said when somebody
was handed it. A card is numbered, snapshotted and printed in one step, with its own copy of the
student's photograph. Both carry a QR code that resolves on a page needing no login, and every scan
of it is logged.

**The one unauthenticated write path in the institute got the most attention.** `/verify/{code}` is
rate-limited *before* the lookup rather than after — throttling after querying would let somebody
time the database while being told to slow down — matched with `hash_equals()`, logged whatever the
answer, and answered from a whitelist built with `array_intersect_key` so a column added to
`certificates` next year is invisible there until somebody adds it to two places on purpose. A draft,
a privacy opt-out, a soft-deleted row and a code nobody ever issued all render the same page with the
same 404, because *this code is real but hidden* is exactly the fact a privacy opt-out exists to
conceal. A revoked certificate is the single exception: it resolves and says so, with the reason,
because somebody holding a revoked certificate is precisely who the page is for.

**Six defects found by probing, before a test existed to go red.** `getOriginal('status')` applies
the model's cast, so the immutability hook was casting an enum to string and fatalling — and it threw
on *every* update, including the print-count bump that makes a reprint possible. A `CHECK` requiring
a non-empty `submitted_code` turned an empty box and a press of the button into a 500 on a public
form. An override could reach the `no_live_certificate` rule and come back as a raw 1062 with no
sentence attached. A `<x-site.button type="submit">` is a link component, so the public form would
never have submitted. An interpolated Tailwind class (`bg-{{ $colour }}-50`) is invisible to the
scanner — D130, in my own new code, one phase after writing it down. And `Student` had no `idCards()`
relation, so the candidates query would have thrown on the first visit.

**D137 is the one worth reading.** The three seeded default templates were written with the class
names their layouts wanted — `.doc`, `.card`, `.hdr` — and `RichText` keeps `class` only for the
tokens on its own allowlist. The HTML would have saved cleanly, the stylesheet would have saved
cleanly, and every rule would have selected nothing: a fresh install's first certificate prints
unstyled, and the first person to open the editor concludes the editor is broken. Nothing goes red,
because a missing class is not an error anywhere. The defaults now style by element and by the
allowlisted tokens, the seeder **refuses to write a template whose stylesheet sanitises to nothing**,
and a test walks every class selector in every seeded stylesheet.

**Reconciling the routes against §7.5 was worth more than it looked.** Eleven route names had drifted
— `admin.id-cards.*` where the contract says `admin.student-id-cards.*`, `candidates` where it says
`eligible` and `create` — and the sidebar had been written against the *contract's* names, so the ID
card entry was a link to a route that did not exist. That is D109's failure mode exactly, and it was
sitting in the navigation. The sweep also found nine routes the contract names that had not been
built at all: the single-enrolment eligibility report, the standalone draft form, editing a draft,
bulk issue for both documents, PDF regeneration, a card PDF, template duplication and the token
reference. All nine now exist, and the manifest test asserts every one against the permission the
contract gives it.

**`layouts/document` is a second print layout and the docblock says why.** `layouts/print` is the
company letterhead — every invoice and payslip comes off the printer looking like a sibling — and a
certificate is the opposite kind of document: its entire appearance is a row somebody designed, down
to the institute name and where the logo sits. Printing one inside the letterhead would brand the
page twice and force an A4 portrait frame around an 85.6 mm card. The new shell contributes only the
paper, plus two marks a template author must not be able to style away: the REVOKED watermark and the
"Reprint #3" line that stops two copies circulating as though both were the original.

### 2026-09-23 — Phase 20 closed, and the service Phase 21 went looking for

**The gate is green: 3,383 passing, 0 failing, 102,909 assertions in 3,162 seconds.** That is the whole
cross-phase suite, not Phase 20's own tests, and it is the first time it has run clean end to end since
Phase 19 opened.

**Two Phase 20 defects were still in it when it went green**, because neither is something a test goes
red on. Both were found by checking the code against the schema rather than by running it.

`teachers.employee_id` is a **bigint foreign key to `employees`**, not a readable staff code — and the
exam index, show page and form all printed it directly. An examiner showed as a raw integer, or as
nothing when the column was null. `class-sessions` and `timetable` have been reading `teachers.name`
all along. The render probe could not have caught this: it proves a page returns 200, not that the page
says something true.

Behind it, the examiner dropdown was built from `Teacher::teaching()`, which filters to active
teachers. Editing an exam whose examiner had since left would drop them from the list, and saving would
post no `teacher_id` at all — silently unassigning them while reporting "Exam updated". That is D121 and
D131 a third time. The dropdown now always includes whoever the exam already names, and the same fix
covers a retired grade scale, which INV-20-4 keeps alive precisely so an old exam stays explicable.

**`ExamStatisticsService` was contracted to Phase 20 and I wrote that it belonged to Phase 23.** The
Phase 21 pre-flight is what caught it: `CertificateEligibilityService`'s `exams_passed` rule reads
`forStudent()`, and `CertificateService` resolves a certificate's grade through `aggregateFor()`. Phase
21 cannot issue a certificate without either, so it shipped as a Phase 20 completion commit with
`AggregateGrade` and `StudentExamStats` beside it, and thirteen tests that were green on the first run.

It exists because INV-23-1 says a report never re-implements a figure. The certificate grade and the
result card read one calculation, so they cannot produce two different As — and the one printed on a
certificate is the one nobody can correct afterwards.

Three decisions inside it are worth stating because each could reasonably have gone the other way. An
exam with **no weight is weighted equally with its peers**, so a set where every weight is null is a
plain mean: null-as-zero would silently drop an exam from the average, and null-as-100 would swamp the
rest. A course with **no major exam has "passed them all"**, because a short practical course may
legitimately have none and the alternative makes the rule impossible rather than strict. And an
**aggregate over nothing is null, never zero** — 0% on a certificate says the student scored nothing,
which is a different and defamatory claim.

`forBatch()` and `forCourse()` are deliberately absent. Their only consumers are Phase 23's reports, and
building a return shape before anything reads it is how it comes out plausible, gets consumed, and then
has to change.

**Two smaller things the same sweep turned up.** `Money::div()` takes two arguments and `ResultSummary`
passed three; PHP ignores the extra silently, so the `, 4` never did anything — and `div()` rounds to
`Money::SCALE` (two) regardless, so it was also asking for a precision the method does not offer.
Harmless, removed. And `GradeScale::hasBeenUsed()` asks results and exams but not certificates, while
its own docblock — written in Phase 20 in anticipation — already claims certificates hold a scale alive.
The relation cannot exist until the `Certificate` model does, so the docblock now says **Phase 21 must
add both in one commit**; otherwise a scale used solely by a certificate passes the friendly check and
fails with a raw constraint violation instead of a sentence.

**dompdf and simple-qrcode are installed**, in a commit of their own so a lockfile change is never mixed
with a behaviour change. `config/dompdf.php` is published with three options pinned off and each
carrying its reason. `enable_remote` is the one that matters: with it on, an `<img src="http://…">`
inside a print template makes **the server** fetch a URL chosen by whoever holds
`print_templates.edit` — server-side request forgery with a WYSIWYG editor attached, reachable by a role
an institute would happily give a designer, precisely because the module exists to be granted
separately from anything holding student data. `enable_javascript` defaulted to **true** and is now
false: it embeds any `<script>` the source carried into the PDF for the reader's viewer to run, so the
blast radius is somebody else's machine rather than ours, which is exactly why it is easy to leave on
and wrong to.

### 2026-09-23 — Phase 20: exams, results, and a status nobody could write

**Shipped.** Four tables, four enums, four models, two data objects, five services, three policies, three
Form Requests, eight controllers, 40 routes across three panels, 24 screens, one seeded grade scale, and
78 acceptance tests on top of the 18 unit cases the band validator already had.

**The spine is small and the rules are all in one place.** A grade scale is a ladder of contiguous bands
covering exactly 0–100; an exam belongs to one batch and snapshots what it was out of onto every row it
produces; a sheet saves whole or not at all; a second person checks it; publishing ranks the class and
stamps every row; withdrawing hides them again without deleting anything; a published mark changes only
by amendment, with a reason and an audit record. `ResultCalculator` is the only thing that may write a
grade — the model refuses the write otherwise, so a controller, a Form Request and a seeder all cannot.

**Six defects, found by probing rather than by a test going red**, because no test existed yet. Every one
was in code that compiled, linted and read correctly.

The worst was **`exams.status` at `varchar(16)`**. `ExamStatus::ResultsPublished` is seventeen characters:
six of seven cases fitted, and the one that did not was the one the whole phase exists for. An institute
could set an exam, sit it, mark it, have it checked, and then get *"Data too long"* on publish. CLAUDE.md
§3 says `string(32)` precisely because nobody counts their longest case — D126, and the manifest test now
measures every enum against its column.

Then **seven `settings_repo()->asSystem()->get(...)` reads** that would have fataled on first render
(D127); **the clash detector never told about exams**, so it scanned its three built-in tables, found no
exam source and reported clean while two papers could be booked on one batch at overlapping times (D128);
a **tautological guard** in the band validator — `Money::compare` rounds both sides to two decimals, so
comparing a value with its own two-decimal rounding is always equal, and `39.9950` was accepted and
stored as `40.00`, moving a pass boundary the administrator had deliberately drawn (D129); and two
**Form Request rules that disagreed with the domain they validated** — four decimals where the validator
reasons at two, and a hex colour where `x-ui.badge` needs a Tailwind token (D130).

**A seventh was a form lying about what a save does.** `ExamService::update()` never writes
`scheduled_date` — moving an exam takes a reason and re-runs the clash check, so it has its own entry
point — but the shared form offered an editable date anyway, which would have reported "Exam updated" and
changed nothing. That is D121 in a second place, and D131 here.

**And one thing I nearly broke by fixing it.** An all-pass grade scale looks like a misconfiguration, and
the one-line change to require exactly one pass/fail transition was already written when two of my own
assertions contradicted each other. The contract says *at most* one transition, and [D-20-2] explains
why: an exam with a pass mark above zero decides the outcome outright, so a participation ladder with no
fail band is legitimate. D132 — when a new test disagrees with an old one, the contract decides which is
wrong, not whichever was written last.

**`results.delete` and `results.restore` are no longer declared.** Phase 1 registered both through
`self::CRUD`; Phase 20 assembles the `results` ability list by hand to leave them out. A result is
amended with a reason, never removed — the policy returned false for both and the model refused the act
regardless, so the permissions only ever existed to be granted by mistake. `PermissionSeeder` reports the
two orphans rather than deleting them, which is the correct non-destructive behaviour and worth knowing
about before somebody wonders where they went.

**Verified:** 78 acceptance tests across six files plus 18 unit cases — grade-scale geometry and the
INV-20-4 deletion refusals, the exam ladder and its slot, the sheet's all-or-nothing rule and the
absence-is-not-a-zero pair at three layers, the four-eyes step, publication, withdrawal, amendment and
what a student may see, authorization across three panels, and the manifest. Every one of the 24 screens
rendered and every intentional 404 was a 404.

**Carried forward:** §6.12's `progress_from_assessment` writes to the syllabus register when an exam
names a topic — the setting ships defaulted off and the hook belongs with Phase 23's reporting; the four
notification classes go with Phase 22; `ExamStatisticsService` and the CSV result importer
(`results.import` is registered and has no route yet) belong with Phase 23.

### 2026-09-23 — D112 for the third time, and the test that ends it

**The full cross-phase suite was green on 3,241 tests and red on two** — the same two, both the same
bug, both the one Phase 18 had already written a decision about. `course_material_targets.course_material_id`
and `assignment_submissions.superseded_by_id` each leaned on a UNIQUE index that merely led with the
column, so `down()` could not drop that index (error 1553) and neither migration was reversible. Both
now carry an index of their own, added in `create()` and ensured in `guard()` on every run so a database
migrated before today heals itself rather than failing to roll back forever.

**The fix is not the interesting part; the third occurrence is.** D112 states the rule in plain words,
and Phase 19 broke it twice in the commit after the one that wrote it down. A decision nobody rereads
while writing the next migration is a decision that will be made again. So it is now
`tests/Feature/Schema/ReversibleMigrationTest` — it reads the index names out of every migration that
calls `dropIndex`, finds each index's leading column, and fails when that column carries a foreign key
that nothing else indexes.

**Scoped to the index, not to the table**, deliberately: asking the question of every foreign key in
every table that drops *any* index flags `attendance_monthly_summaries.employee_id`, which is entirely
safe because that migration drops something unrelated. The narrow question found exactly the two real
offenders and nothing else, and it answers in 142 seconds against a fresh schema instead of the thirty
minutes the install-and-rollback pair needs to reach the same verdict. D125.

**Verified:** `ReversibleMigrationTest` green; `Cms/Install/InstallAndRollbackTest` and
`Cms/Marketing/Behaviour/InstallAndRollbackTest` green (3 tests, 596 assertions, 816s). Phase 19's gate
is now **3,244 passing, 0 failing, 101,206+ assertions**.

### 2026-09-23 — Phase 19: materials, assignments, and one upload gate for everything

**Shipped.** Six tables, six enums, six models, five file objects, one hardened uploader, six services,
three policies, four Form Requests, seven controllers, 68 routes, 22 screens across three panels, 94
acceptance tests — and a `private` disk that did not exist before.

**The security centre is `SecureFileService`, and it is Phase 5's logic generalised rather than a second
copy of it.** §6.1's ten-step gate, written once: the upload arrived intact, the client's name is reduced
to a basename, an extension exists and is not hard-refused, no inner segment is an extension, the
extension survives the intersection with `security.allowed_file_types`, the file is not empty, the size
is under the smallest of three caps, the MIME is **sniffed from the bytes**, the sniffed MIME is one that
extension permits, and only then is it written as `{ulid}.{extension}`. Proven by feeding it hostile
input rather than by reading the whitelist back: a `.php` refused, `cv.pdf.php` and `scan.pdf.docx`
refused, a real PDF named `.docx` refused *naming both* what it is and what it claimed, and an `.svg`
refused even though `CourseResourceType::Image` lists `image/svg+xml` — correctly, because Phase 14 uses
that enum for the public syllabus where an SVG diagram is harmless and here it would be stored XSS.

**Three generated STORED columns, all for the same MariaDB reason.** A unique index tolerates unlimited
NULLs. `course_material_targets.target_key` COALESCEs the three target foreign keys so `uq_cmt` bites at
all. `assignment_submissions.current_guard` is NULL for `superseded` and 1 otherwise, so `uq_as_live`
permits exactly one live submission per student **while the history stacks** — here the NULL tolerance
is the mechanism rather than the hazard, which is why the expression produces one deliberately. And
`final_marks` is `GREATEST(obtained − penalty, 0)`, asserted byte-identical to `AssignmentGradeCalculator`
across five marks.

**Ten defects, and not one of them was found by a test going red.** They are D115–D124. All ten were in
code that compiled, linted and read correctly. Three came from writing against the contract from memory
instead of re-reading it. Two were D113 in a second place — a save moving a value nobody asked it to
move. Two were invariants that held in the database and not in the service. One was a Laravel method
doing exactly what its name says while I assumed otherwise, which would have served every private file
in the phase as an empty 200. One was a feature that could not be used out of the box. And one was a
pair of tests written as though a policy could stop a Super Admin, which it cannot.

**The last of those is worth restating.** `Gate::before` allows a Super Admin everything before a policy
runs, and a soft delete is an UPDATE that `restrictOnDelete` never sees. So `AssignmentPolicy::delete()`
refusing an assignment with submissions stopped every role *except* the one most able to do damage. The
model hook is what holds it now, and the tests assert the policy against an ordinary role and the model
against everyone — because a test that only checked the policy would have passed while a class's marked
work was hidden.

**Verified.** 23 upload-gate tests against eight hostile uploads · 17 access tests, each asserting that
`grantFor()` and `visibleToStudent()` agree, so a file cannot leak past a list that hides it · 32
lifecycle tests covering INV-19-5, INV-19-6 and INV-19-7 through the real service path · 16 authorization
tests checking the *status code* of every refusal, because a 403 says the row exists and a 404 says
nothing · 23 manifest tests · 22/22 screens rendered on all three panels · 373 across
`tests/Feature/Institute` · `pint --test` clean across the whole repository for the first time.

**Deferred on purpose.** `engagement()` returns the access log rather than a per-student
opened/not-opened aggregate — §8.3's screen is built and the aggregate is Phase 23's. The four
notification classes are Phase 22's; `notified_at` is written and the dedupe guard holds across the gap.
`duplicateToBatches()` ships and is tested; the screen that calls it is a follow-up. The three
teacher-scope copies of D117 are a separate change.

### 2026-09-22 — Phase 18: fees, and one definition of a fee's status

**Shipped.** One table, two enums, four services, four policies, ten DTOs, seven controllers, 25
routes, fourteen screens, two jobs, four scheduler commands, three dashboard widgets and 57 tests.
Phase 10 owns and had already created all four money tables; this phase creates `student_fee_reminders`
and ships everything that acts on the rest.

**The change that mattered was subtraction.** `PaymentService` had carried a private `chargeStatus()`
since Phase 10, and §6.4.1 says `deriveStatus()` is *the* definition. Making that true meant deleting
the other one — and the two had already drifted in three ways (D107). A charge covered entirely by a
scholarship never reached `paid`, so a fully-funded student sat on the collection desk for ever. A
part-paid charge past its due date always read `partial`, so somebody who paid a tenth and stopped
never appeared on an overdue report. And `refunded_amount` was summed from reversals of **voided**
receipts — money that never counted. None of those is a typo; they are what happens when one question
has two answers in two files.

**That deletion immediately found something else (D108).** `StudentCommissionEngineTest` started
computing 3,000.00 where it expected 2,500.00. The spine's own `charge()` fixture had been writing
`discount_amount` and `net_amount` with no discount row behind them — a state the application cannot
produce, which survived only because the old recompute left those columns alone. One definition of the
caches correctly reset the bucket to zero, which moved the collectible and therefore the commission.
The fixture now writes a real row and lets the caches derive.

**PI-1 turns out to have an exception, and a test found it (D106).** §6.2 says a discount larger than
the remaining unpaid lines leaves its remainder unconsumed — money already received is evidence, not a
slot — while §6.3 states PI-1 as a flat equality over every live line. Both cannot hold: a student who
paid 10,000 against a fee later cut to 5,000 leaves one live line of 10,000 that no longer sums to the
net fee. The line is right and the equality is wrong. "Installment 1: 10,000, paid" is a true
historical statement, and shrinking it would rewrite what somebody was asked to pay after they had
paid it. PI-1 exists so a plan that does not sum to the fee cannot leave a charge unable to reach
`paid`; an overpaid charge has already gone past `paid`. The service, the nightly verifier and the
suite's own helper all skip that one case and each says why.

**[D18-1] — a unique index that would have guarded nothing.** `student_fee_reminders` dedupes on
`(student_fee_id, student_fee_installment_id, type, due_date, offset_days)`, and MariaDB permits
unlimited NULLs in a unique index. A charge with no installment plan has a genuinely NULL
`student_fee_installment_id`, so it would have slipped the guard on every run and been chased every
single night — the one failure the table exists to prevent. The real index is on a STORED generated
column, `COALESCE(student_fee_installment_id, 0)`. Writing `0` into the foreign key itself was the
alternative, and that is a dangling reference wearing a disguise.

**The arithmetic is exact by construction.** `InstallmentPlanCalculator` works in integer paisa through
`Money::distribute()`, so there is no rounding drift to chase — only an integer remainder of at most
`count - 1`, placed where the setting says. It refuses `total < count` rather than emitting the
unpayable `0.00` line `distribute()` would otherwise produce quite correctly: `0.04 / 5` comes back as
a sentence with both numbers in it instead of a constraint name.

**D109 — the sidebar was offering two links that 404.** Phase 1 reserved `admin.installments.index` and
`admin.fee-discounts.index` before the shape of the phase was known, and §7 ships neither: an
installment and a discount are only ever read in the context of their charge. Same class of defect as
D98, pointing the other way, found by asking whether every route a menu names exists. Every sidebar
route across all five panels now resolves.

**D110 — one receipt, not two.** Phase 10's admin receipt template predated the print layout and
§6.7.2's rules, and two templates for one receipt is where those get forgotten in the copy the student
is holding. Both panels render `resources/views/fees/receipt.blade.php`; the routes differ only in the
`FeeSlipOptions` they construct.

**What the suite caught.** Seven failures across the run, and only one was a code defect (D106's
exception). The rest were mine and each taught something: six identical receipts tripped the spine's
duplicate fingerprint — the guard working; a hand-built `Course` row was missing `code`, which is the
argument for fixtures going through the owning service; a reopened charge read `pending` because the
*plan's* first due date was a month ahead, so `recomputeCaches()` correctly stopped it being late; and
`assertDatabaseCount()`'s third argument is the connection, not a message, so two assertions were
quietly asking for a database called "Nothing was written by either attempt".

**Deferred on purpose.** `transferPayment()` ships and is tested, but Phase 15 owns the screen that
calls it (§13.3, R-11). The four notification classes are Phase 22's; `FeeReminderService` writes its
row and checks `class_exists()` before dispatching, so the dedupe guard holds across the gap.

**Verified.** 40/40 behavioural against live data · 24/24 screens rendered, including the student
body asserted to carry no commission column · 114/114 static surface · 27/27 calculator · 57 Phase 18
tests · 421/421 across `tests/Feature/Financial` and `tests/Feature/Institute`.

**Then the full cross-phase suite found four more, and all four were mine** (D111–D114): a duplicated
module sort order, a foreign key leaning on the unique index so the migration would not roll back, a
multiselect default in the wrong order, and one decimal setting missing the rule that refuses exponent
notation. None was reachable from a targeted run — two live in `tests/Unit`, two take 10 minutes each
to reach the assertion. **3,090/3,090 green** after the fixes; the two install-and-rollback tests now
complete their full round trip (613s and 500s) instead of failing partway.

### 2026-09-22 — Phase 17: the register, and two phases that both owned `admin/attendance`

**Shipped.** Five tables, four enums, five models, two services plus a report engine, two policies, three
admin controllers, four panel controllers, 30 routes, 15 views, eight scheduler commands and 39 tests.
`student_attendances` is the register; `batch_topic_coverage` is what the class covered; the three
`student_*_progress` tables are the same syllabus seen per student, per module and per topic, all derived
from one recompute and all re-derivable by `progress:recompute`.

**What the register refuses.** INV-I9 — a register exists only against a *dated* class, and only for a
student the roster held *on that date*, which is resolved from `student_batch_enrollments` in both
`roster()` and `mark()` so a student transferred out last week is absent from today's register rather than
quietly present in it. INV-I10 — a row is corrected, never removed; past `institute.attendance_lock_hours`
the correction additionally needs `student_attendance.edit` and a reason, and `amend()` records the old
value, the new one, the actor and the reason.

**The invariant had to move down a layer.** `StudentAttendancePolicy::delete()` returns `false`, and that
is not enough: `Gate::before` allows a Super Admin every ability *before* a policy is consulted, so the
break-glass account could delete a register while the policy sat there saying no. `StudentAttendance::deleting`
now throws for every caller. This is the fourth phase in which that ordering has caught me, and the lesson
is the same each time — a hard invariant belongs in the model, and a test for one must not be written as a
Super Admin.

**`admin/attendance` had two owners, and the route table answered both (D98).** §7.7 asked for
`admin/attendance` and `admin.attendance.*`; Phase 7 has owned both for employee attendance since long
before the institute existed, and seven names matched exactly. Laravel keeps the last registration for name
lookup and the first match for dispatch, so `route('admin.attendance.index')` built the institute's URL
while `/admin/attendance` reached Phase 7's controller. The screen probe reported `200` for a screen it
never rendered. The phase's routes are `admin.student-attendance.*` and `admin.student-progress.*` — the
names Phase 1's sidebar had already reserved — renamed in **both** halves, names and URIs.

**Then the fix broke Phase 7 (D99).** The rename ran as a search-and-replace over `routes/admin.php`, which
by then imported the institute controller as `StudentAttendanceController` — so it rewrote Phase 7's own
`AttendanceController::class` references too, and eight employee-attendance routes began dispatching to the
institute controller. Nothing failed: the route-guard manifest records middleware, not the action, and there
is no HTTP test over those routes. `RegisterManifestTest::no_route_of_this_phase_collides_with_phase_7`
asserts the action of `admin.attendance.index` still contains `Hr`, which catches the collision and the
damage done fixing it in the same line.

**A soft-delete column under a unique guard (D101).** §2.24 and §2.26 give two of the five tables a
`deleted_at` to honour `CLAUDE.md` §3 — and neither unique guard includes it, which is precisely the D19
hazard. For the register the column is simply unreachable, because the model refuses every delete. For
progress it could not be: `openFor()` used a default query, so a trashed row was invisible and still
occupied `uq_scp` — the lookup answered `null`, the insert hit a 1062, and that seat could never have
progress again. `openFor()` now uses `withTrashed()` and restores the row, keeping its topic rows attached.
The phase's own manifest test found it; a behavioural test pins it.

**`app_clock()`, because a TIME column is a wall clock (D100).** `NoHardcodedFormatsTest` caught
`Carbon::parse($t)->format('H:i')` in the views. `app_time()` would have passed the test and been wrong —
it converts to the display timezone, moving a 09:00 class into the afternoon and a 23:30 class onto the
wrong day. `Format::clock()` reads the fields and never converts.

**A branch leak, found by a test.** The report filter dropdowns listed every batch code in the institute to
a user scoped to one branch. `pickers()` and the register index now take the request and apply
`->forBranch()`.

**Phase 16's two deferred commands landed here**, which is what the deferral was for: this phase adds the
scheduler block, and a command with no `Schedule::command()` entry is a command nobody runs. Eight entries,
eight commands.

**Deferred on purpose (D102).** The attendance CSV importer. A register is the one import where partial
success is worse than refusal — rows dropped because a name did not match the roster leave a class that
looks marked and is not, and the percentage that follows bars a student from an exam. The route exists, is
guarded, and says so.

**The gate itself needed fixing (D103).** Running the full suite three times on the same tree gave
`InstallAndRollbackTest` durations of 282s, 570s and over 4,800s — the last one killed by the 600s
ceiling on its child `artisan migrate`. Twice I read that failure as a bug in this phase's work; both
times it was the machine being busy. The ceiling is now 1800s, which still catches a hang and no longer
catches a slow afternoon.

**What the cross-phase run found that this phase's own suite could not (D104, D105).** The Institute
suite was green at 204/204 and the manifests at 9/9, and the full run still came back with three
failures. One was mine: five month captions in the monthly report used `->format('F Y')`, and
`NoHardcodedFormatsTest` lives in `tests/Feature/Views`, which no Institute run touches. `app_date($v,
'F Y')` is the fix — `Format::date()` already takes a format and treats a calendar date as one. The
other two were a Phase 2 mail test asserting a sentence that depends on the network: it saves
`192.0.2.1` expecting an unroutable address, and on this connection something answers in 0.2s, so
Symfony reports a timeout rather than a refusal. Both sentences are the transport's own reason, so the
test now asserts what it actually meant.

**Verified.** 422/422 schema checks on both databases · 34/34 behavioural · 60/60 service · 28/28 screens
rendered against live data · 40/40 `institute:verify-constraints` on both databases · 39 Phase 17 tests ·
the whole `tests/Feature/Institute/` suite green · **the full cross-phase suite 3,019 passed, 0 failed (95,090 assertions, 40 minutes)**.

### 2026-09-21 — Phase 16: one clash authority, and three bookings that clashed with themselves

**Overlap is the one rule MariaDB cannot hold for us.** A unique index says "these values may not
repeat"; it cannot say "these two hours may not intersect". So the guard for a timetable is a lock and
a service, not a constraint: `ScheduleClashDetector::check()` is the single overlap test in the system,
every write opens a transaction and locks the teacher, room and batch rows in one fixed order before it
asks, and the six unique indexes underneath catch only the cheap case — the same form submitted twice.
Phase 19–23's exams and room-bearing meetings join that scope with one declaration each, which is what
makes "a room is never double-booked" true for tables this phase has never heard of.

**Three bookings turned out to clash with themselves, and the tests found all three.** A weekly rule
and the dated classes it produces occupy the same hour — because the classes *are* that rule, dated —
so editing a live slot collided with its own generated classes, and handing a class to a substitute
collided with the slot it came from. One ignore pair cannot say "this booking and its parent", and the
generated classes are as many as the horizon is long, so `SlotCandidate` gained `alsoIgnore` and
`ignoreGeneratedBy`. The third was a demo: §2.16 defines a demo's `batch_id` as "sit in on this batch",
and a sit-in is by definition the same teacher in the same room at the same hour as a class that is
already there. Without `joiningBatchId`, the one thing that column exists for could never be booked.

**The room backstop was stricter than the room rule.** The detector skips the classroom dimension for
an online class and for a virtual room; the index knew only whether the booking was live. So two online
batches naming one Zoom link were allowed by the rule and then refused by a 1062 nobody could explain
(D94). `room_guard` is now 1 only while a booking is live *and* its mode needs a room, and a virtual
room is refused for anything but an online class — which makes the two exempt exactly the same rows
rather than nearly the same rows.

**Capacity is a recount under a lock, and the number on the screen has no authority.** Two
receptionists enrolling the last student queue on the batch row, and the second is told it is full.
`batches.current_students` is a cache: one test corrupts it to 99 and watches the real seat still be
given; another sets it to 0 and watches the recount repair it. Overbooking takes three separate yeses —
the institute setting, the caller's flag, and a reason — and each missing one is refused with its own
sentence, because "batch is full" with no numbers is a refusal somebody argues with rather than acts on.

**A transfer leaves the past where it happened.** The old seat goes to `transferred_out` with its
attendance intact and the new one starts empty, linked both ways. Moving the register across would say
the student attended classes they were not enrolled for; deleting it would say they never attended at
all. The fee side is handed to Phase 18 by name — this phase writes no money row (INV-I1).

**Attaching the eight deferred keys broke three older tests, which is the point of attaching them.** A
Phase 15 test wrote `batch_id = 1` into an admission; there was no batch 1, and until today nothing
said so. A Phase 14 test had been **skipped since the day it was written**, waiting for
`class_sessions` — it ran for the first time here and turned out to be asking a Super Admin, for whom
`Gate::before` allows everything. And the foreign-key manifest check asked for a single-column index
row where the manifest's own header says a leading column counts (D97). Each one is a thing that was
already wrong and had nothing to notice it.

**Files.** 7 migrations (`2026_09_14_1200xx`), 7 enums, 6 models in `app/Models/Institute/`, 3 DTOs in
`app/DataObjects/Institute/`, 7 services in `app/Services/Institute/` (including the detector and two
new exceptions), 5 policies, 8 controllers (6 admin, 2 teacher panel, 2 student panel) with 2 scoping
concerns, 24 Blade views, 61 routes, 13 settings keys, the `classrooms` module, 5 test files and the
four D60 manifests.

### 2026-09-21 — Phase 15: the admission pipeline, and four guards that had to be two

**One column holds §68, and every step checks it.** The pipeline spans an enquiry, an application, a
student and an admission; if each carried its own idea of where things stood, a screen would have to
pick one to believe. The admission carries it, `students.status` advances beside it, and calling
`activate()` on an admission still at `application` throws and names the step that was skipped — which
is the difference between a pipeline and four columns that drift.

**The public form creates one row.** No student, no login, no fee. A stranger filling in a form is
making a request; a student record is something a member of staff decides to create, and the distance
between those two facts is the whole reason `student_applications` exists. A test asserts the
`students` and `users` counts do not move, because that distance is the kind of thing that closes by
accident three phases later.

**The key blocks and the fingerprint flags, and the asymmetry is the point.** One ULID per rendered
form makes a double-tapped submit the same application. The fingerprint — phone, course, name — is
deliberately *not* unique, because the same person really does apply twice for the same course six
months apart, and a database that refused the second one would refuse a real customer. It turns the
row amber and puts the two side by side for somebody to judge.

**Two invisible fields that cost a real applicant nothing.** A honeypot, and a minimum age for the
form: nobody reads and completes an admission form in under two seconds, and a bot does. Neither
error says which one caught it, because a message naming the timing check would tell the next author
exactly what to change.

**A follow-up now makes two moves (D89).** §2.30.2 has no `new → interested`, and that gap is
deliberate: an enquiry that jumped straight there would have no record of ever being called. The
first version picked one status and hit the transition table — correctly, which is how the gap was
found. It records what happened instead: you reached them, and then they said something. Both moves
go through `changeStatus()`, so the table still governs each.

**The round-robin was handing every enquiry to the Super Admin (D88).** Spatie's `permission()` scope
matches a permission held through a role, and that role holds all of them — so the account with the
lowest id and no open enquiries won every time. It is the installation's break-glass account, not a
counsellor with a queue. Excluded now, and if it is the only candidate the enquiry stays visibly
unassigned rather than quietly parked where nobody looks.

**A constraint MariaDB would not accept, and what replaced it.** `chk_sap_not_self_duplicate` reads
the `id` column, and MariaDB rejects any CHECK that touches an `AUTO_INCREMENT` (error 1901) — the
migration failed on the first run and left the table created, which is D70's situation exactly. The
rule moved into `markDuplicate()` with a test to pin it (D92); the other five CHECKs on that table
stand.

**Two spellings of one instruction (D90).** §6.5 writes the numbering tokens in capitals and Phase 2
seeded `registration_number_format` as `{prefix}-{year}-{seq}`. §5 says that key is used exactly as
defined, so the service reads its tokens case-insensitively rather than the contract quietly
redefining a value somebody may already have saved.

**Nine more deferred keys.** `student_fees.student_id`, `collaborator_referrals.student_id`,
`testimonials.student_id` and six others had been unconstrained since Phases 4 and 10 — the same gap
the `courses` keys had, and the same cause: the migrations that defer them are idempotent and ask to
be re-run, but `migrate` runs a file once. Attaching them surfaced twenty-five rows in the dev
database pointing at student ids that were invented before `students` existed. Those students were
created rather than the financial rows deleted; the commission screens are built on them.

**What is delegated, and what it says instead.** `assignBatch()` and `transferBatch()` belong to
Phase 16's `BatchEnrollmentService`, which checks capacity under a row lock; `requestFees()` belongs
to Phase 18's `StudentFeeService`, which is the only thing that may write a charge. Each refuses with
the name of the service that owns it. A seat handed out anywhere else is a seat that was never
counted, and a charge nobody can reconcile is worse than not having one (INV-I1).


### 2026-09-21 — Phase 14: the catalogue, and a cache that kept serving the page after it was taken down

**Three tests failed for one reason, and it was a real gap.** Switching a category off left its
courses answering `200`. A topic the institute had stopped teaching stayed on the landing page.
"Apply now" survived admissions being closed. §7.10 asks that a course, outline or FAQ change take
Phase 3's cached copy of that page out of circulation, and nothing in this phase did it — so every
screen was right, every query was right, and the site served yesterday.

The first fix put the bump inside `recountCourse()`, on the reasoning that every outline write already
calls it. That reasoning was wrong in two places and both were the interesting ones: `recountTopic()`
never reaches the course, so adding a resource to a published syllabus recounted perfectly and told the
site nothing, and `reorder()` recounts nothing at all while changing the order a visitor reads. It is
`announce()` now — one named step, called by every write, cheap to call and impossible to mistake for
counter maintenance (D83).

**The setting that could not reach the cache.** The fourth failure had a different cause with the same
shape. The settings screen writes through `SettingsService`, which dispatches `SettingsChanged`, which
`PublicCache` listens to. The low-level door — `SettingsRepository::set()`, for the console and the
tests — wrote in silence, so `institute.admission_open` switched off left every cached course page
offering to take an admission for up to a day. The three writers announce now; `flush()` still does
not, because clearing a cache is not a change (D84).

**A contract line this phase did not follow.** §2.8 puts a syllabus file on the `public` disk and, four
lines earlier, says a resource that is not `is_public` needs `course_outline.view`. Both cannot hold: a
file the web server serves has no application code in front of it, so the permission is decoration, and
`Storage::url()` hands out an address that outlives hiding the resource, deleting it, and the employment
of whoever was shown it. CLAUDE.md §3 decides that one. The file is on the private disk and two
controllers serve it — the admin one re-running `course_outline.download`, the public one answering only for
a public, downloadable resource of a course the catalogue would show, and 404ing on every other case
(D85). It cost nothing to fix because nothing linked these files yet; in three phases' time it would
have cost a migration and an apology.

**The outline is three levels, and the code is what makes that true.** Module → topic → lecture, no
`parent_id` anywhere. Every parent id is read from the parent the route bound rather than from the
request body, so `storeTopic()` cannot be talked into filing a topic under another course's module, and
`reorder()` checks every id against its parent and the parent against the course before a single row
moves — a payload with one foreign id reorders nothing rather than reordering what it could.

**Publishing asks the table, not the cache.** `publishingGaps()` counts modules live rather than
reading `modules_count`, because the cached count is most likely to be stale on exactly the course
somebody is about to publish for the first time. It returns the missing field names, so the button is
disabled with a reason instead of failing on submit.

**An upload is what it is, not what it says.** The content goes through `finfo` and is checked against
`CourseResourceType::allowedMimes()`; the stored name is forty random characters, so nothing the
uploader chose ever becomes a path. The test that was supposed to prove this proved nothing:
`UploadedFile::fake()->createWithContent()` reports a MIME guessed from the filename, which is the one
value the rule exists to distrust. It writes a real temporary file now (D86).

**Two Blade traps, both introduced by the fix above and both caught by the full suite.** `@json`
splits its argument on every top-level comma to find its flags, so `@json(array_filter([...]), FLAGS)`
compiled to PHP that does not parse — the course page answered 500 and the static scan that had just
passed could not see it, because the scan reads the source and the failure is in the output. The array
is built in an `@php` block now and `@json` gets a variable. The second was a `{{ }}` written inside a
**CSS comment** in the print layout: Blade compiles an echo wherever it finds one, comment or not, so
`{{ }}` became `<?php echo e(}}` and every printable document 500ed. Seven failures, one line each.

**Two tests that had been red since before this phase.** `SidebarVisibilityTest` is a ledger of which
screens have shipped their routes, and Phases 12, 13 and 14 all added entries without signing it — the
finance group, the reconciler's discrepancy report, the wallet register, the payout queue and the
collaborator panel's own nav. `NoHardcodedFormatsTest` had four hits, three of them the same
hand-rolled quantity formatter: `number_format((float) $value, 4, '.', '')` trimmed of its zeros, which
is a number printed with a hard-coded dot and no thousands separator on a document where every other
number honours the localization settings. The test says what to do about that — give `Format` the
method rather than excuse the view — so there is an `app_quantity()` now, and the row counts on the
report screen go through `app_number()` like every other count.

**Also.** `outlineHours()` returns null under thirty minutes rather than "about 0 hours" — a
twenty-minute module rounded to zero and read as missing data. `applyUrl()` falls back to the contact
page with the same parameters until Phase 15 ships the admission form, so the referral code survives
the hop either way. The public controller goes through `ComposesContentPages` and `SeoService` like
every other public page rather than writing its own `@section('meta')` (D23). The five outline edits
moved out of the controller into the service, which is where three of them picked up the cache
invalidation they had been missing.


### 2026-09-21 — Phase 13: every finance screen, and a number that was being spent twice

**The bug the demo data found.** Seeding four invoices produced INV000002, INV000004 and INV000006 —
a gap between every one of them. `issue()` refused anything whose `status` was not `draft`, exactly as
the contract words it, but `statusFor()` returns `draft` while `sent_at` is null. So an invoice issued
a minute ago is still a draft to that check, `markSent()` re-issued it, and the second call reserved a
new number and **overwrote the number already assigned** — a number that may have been on a screen, a
printout or an email. D42 says "assigned once at issue, never reused"; the guard is now the
`invoice_number` column, which is the thing the rule is about, and re-issuing raises a named refusal.
Two tests pin it: the second `issue()` throws, and `markSent()` on an issued invoice leaves both the
number and the counter untouched.

**The screens.** Thirty-eight admin screens, rendered against live data through the HTTP kernel rather
than eyeballed: the invoice register, builder, detail and print; the expense register, its approval
queue, form and detail; other income; payment methods; finance categories; the four report screens
with their print and CSV; the cross-source payments register. Zero failures. The Blade compiler
catches syntax; only a render catches a column that does not exist.

**What the print layout is for.** `layouts/print.blade.php` is deliberately self-contained — inline
CSS, the logo read from disk and base64-inlined, nothing from Vite or a CDN. dompdf has no bundler and
no network, so a layout that depended on either would look right on screen and come out unstyled in
the copy the client receives: the one failure mode nobody sees before the client does. It ships now
because four later phases extend it, and it is ready for dompdf the day dompdf is installed.

**Money columns are absent, not blank.** `FinanceVisibility` removes a withheld field from the set
entirely, so a reader without `view_financial` gets a register with no Balance header rather than a
Balance column full of dashes — and the CSV is refused outright, because a finance export without
amounts is a list of reference numbers. The report hub goes further: it lists only the reports the
route would actually open, because a card that advertises something and then says no reads as a bug
rather than as a decision.

**The union that tells you what it left out.** The payments register merges project payments, student
fees and other income in the database, and a source the reader may not see is **not in the union** —
not filtered afterwards, not zeroed. The screen names the ones it dropped. A partial total read as a
full one is worse than a refusal, because somebody will quote it.

**The gateway that refuses rather than pretends.** §32 asks for a gateway-*ready* architecture, so the
interface, the manager and one driver ship and no integration does. `ManualGateway::charge()` throws
`GatewayNotConfiguredException` instead of returning a failed result: a caller that believed a charge
had been attempted could mark an invoice paid on the strength of a no-op. `PaymentGatewayManager`
throws a named exception for an unknown driver rather than falling back to manual, because a method
configured for "stripe" that quietly behaved like an offline one would take a payment nobody could
later find.

**No widget has its own `SUM`.** The nine dashboard cards read `FinanceReportService`; the questions a
card asks that a report does not — how many are overdue, what is waiting for approval — became narrow
readers on the service. A card with its own query is a second answer to the same question, and nobody
would know which was right. One test asserts the revenue and expense cards equal the reports they link
to, to the paisa.

**Three commands that decide nothing.** `invoices:mark-overdue` calls the same `recomputeStatus()`
every payment path calls, over `sent`, `partial` *and* `overdue`, so an extended due date moves a row
back out again — a job that only marked things overdue would leave a corrected invoice wearing a red
badge until the next payment touched it. `invoices:reconcile-balances` checks D40's three cached
columns against the receipts and **reports** rather than repairing: drift is evidence of something
upstream, and rewriting the column quietly erases the evidence while leaving the cause.
`expenses:flag-stale-approvals` sends a list to the people who can act on it and approves nothing — an
auto-approval after a fortnight would turn the approval step into a delay.

The reconciler also shipped with the bug it exists to catch. A `return` inside its `chunkById`
callback left the whole batch rather than skipping one clean invoice, so the first run reported "1
invoice checked" and looked perfect on a database with three. A reconciler that quietly checks less
than it claims is worse than no reconciler, because it is evidence of correctness that was never
gathered. It is a `continue` now, and a test with two invoices — one drifted, one clean — proves the
second one is still reached.

**What is deferred, and why.** `RecordPayrollExpense` is blocked on `PayrollRunPaid`, which Phase 7
has not shipped; the reserved `salaries` category is already in place and refuses both deletion and
deactivation so that the listener has somewhere to post the day it exists. The PDF services are
deferred because dompdf is not installed (D81) — the print view is the document, and the PDF routes
answer 404 rather than serving an HTML blob with a `.pdf` name that somebody would attach to an email.
`invoices:send-reminders` waits with them, because the reminder is an email and the delivery service
the contract specifies attaches that PDF.

**A visible link that 403s.** `PermissionStringConsistencyTest` failed the moment the payments
register shipped: the route carries `payments.view_any` **and** `payments.view_financial`, the sidebar
item advertised only the first, and a link that leads to a refusal is precisely what that test exists
to catch. The route is right — a register whose whole content is amounts is not worth opening without
them — so the sidebar learned to and a list, and the test now compares the whole rule instead of the
first name of it (D82). It could previously only pass for single-permission routes, so it was also
the first thing to widen.

**Also.** `AgingBucket::forDays()` is the only bucket rule, so §6.9's `AgingCalculator` was not built
(D80). `PaymentMethodSeeder` ships §32's four methods plus an inactive gateway placeholder, claiming
the default only when nobody holds it — `uq_pm_default` permits exactly one, and a seeder that claimed
it unconditionally would fail its second run on an installation where somebody had moved it.


### 2026-09-21 — Phase 12: payouts, the reconciler, the statement, and every screen over them

**`PayoutService`.** A payout claims named ledger entries FIFO and is worth exactly what it manages to
claim (INV-22, [D-FS-11]). The claim is a compare-and-swap — `allocated_amount + slice +
reversed_amount <= amount` is checked *inside* the UPDATE, not before it — so two payouts racing for
one entry cannot both believe they won it. The seam Phase 10 left open is closed:
`CommissionReversalService` calls `releaseForReversal()` **before** it measures the paid portion,
because releasing changes it, and a refund is never blocked by a pending withdrawal (§6.6 row 13).

`AllocationDelta` is deliberately not `LedgerDelta`, and the distinction is not tidiness: the §6.5.1
identity splits at the payout. `payable_total` comes from the ledger while `reserved` and `paid` come
from live allocations, so an entry moving `available -> paid` changes **nothing** on the ledger side
and a real amount between allocation buckets.

**`CommissionReconciliationService`** is §6.5.3's eight checks in two severities. R1 and R8 are drift —
the cache fell behind, or a payment was processed with no trace of why nothing posted — and may be
repaired by recomputing. R2–R7 are structural: the ledger disagrees with itself, and recomputing a
cache derived from that ledger would only hide it. The nightly job never repairs either way; it
records, fires `WalletDriftDetected`, and the screens fall back to the derived figures behind a banner
until a human presses Recalculate. A row is written every run, pass or fail — a table holding only the
bad days proves nothing about the good ones, and §50 asks for evidence rather than alarms.

`assertWalletMatchesLedger()` now calls it, which is what the helper's docblock has promised since
Phase 10: all seventy-nine money tests that existed gained the other seven checks without one of them
being edited. R7 immediately found **D77** — a rejected commission was not giving its promise back —
and R4 found a payout that kept promising the amount it was requested for after a refund took part of
its claim away.

**`CollaboratorStatementService`** is movement-based and refuses to print without its proof.
`opening + credits − debits − payouts = closing` is checked against the same balance re-derived as an
opening balance one day later — two different queries that must agree — and a mismatch throws.
With the schema intact those two *cannot* disagree (`chk_cle_sign` pins purpose to six values and the
two lists cover all six), so the guard is really a tripwire for a seventh purpose; a test asserts that
coverage directly, which catches it on the day it is added rather than the day a partner is handed a
statement that will not render. Filters narrow the rows and never the balances, and each visible row
carries its true running balance, taken from the full ordered list before filtering.

**Fourteen admin screens and six panel screens.** The wallet detail shows the derivation *beside* the
cache rather than instead of it — "the cache says 12,400 and the ledger says 12,350" is the sentence
somebody needs. The payout wizard previews its allocation through a GET that claims nothing, so the
figure can be stale by the time the form is submitted, and when it is the store refuses with the
shortfall named rather than paying less than was asked for. In the panel, every query is scoped
through the session by one trait, and another partner's row is a **404**: "that payout exists but is
not yours" leaks that it exists.

**§8.8's discrepancy queue** and **§8.12's eight widgets** close the contract. Accepting a discrepancy
writes a note and closes the row and moves nothing, which is what stops `over_released_amount` growing
for ever (spine R-6); the figure is kept, because it is a fact about what happened.

Four things the work found beyond D76–D78: the wallet register mixed aggregates with the list's ORDER
BY, which MariaDB refuses; two Phase 11 receipts read a settings key the registry does not declare and
the new print views reached for three deprecated ones (`tests/Feature/Settings` had been red since
Phase 11 and is green again); the sidebar named three collaborator routes that never existed and gated
Payouts on `payout_request`, which would have hidden a partner's own history from anyone not allowed to
ask for more; and `chk_cce_cap` turned out to make R7's over-release half unreachable by any path
including raw SQL, so the test asserts the database's refusal instead of simulating a breach that
cannot happen.

**Tests.** `tests/Feature/Financial` 147, `tests/Feature/Rbac` 163, `tests/Feature/Settings` 356,
`tests/Feature/Dashboard` 27 — all green. Eighteen admin screens and eight widgets render against live
development data.

**Outstanding.** `collaborator.students.index` (§57) is blocked on the `students` table, which arrives
with the institute phases. A browser pass over the new screens is owed: the in-app browser pane is not
signed in, and entering a password to authenticate is not something this assistant does.

### 2026-09-20 — Phase 10 §6: the engine, and two unique indexes that forbade legitimate money

Eleven services and the trait that holds the guard sequence, so that a student commission is computed
in exactly one place. `RunsCommissionGuards` runs G0-G11 and C1-C8 verbatim; `StudentCommissionService`
supplies the four things that are actually about students; Phase 11's project engine will supply four
more. `CommissionCalculator` is the arithmetic as a **pure function**, which is what lets the
record-payment wizard's preview and the posted entry be the same number by construction rather than by
two implementations agreeing.

`PaymentService` is the only writer of the payment tables, and it does no commission work at all: the
engine is reached through an event dispatched after commit, so a cashier never waits on it, a
rolled-back receipt never earns anybody anything, and a queue outage degrades to "commission pending"
rather than to a failed receipt for money already in the drawer.

**Two unique indexes forbade cases the design requires.** Both were in the migration set this session
shipped, and both would have failed as money rather than as errors:

* `uq_cle_reversal_pair(payment_reversal_id, reverses_entry_id)` made §6.6's headline row impossible.
  A refund of a commission that was partly paid out posts **two** debits against one original — a
  `reversal` for the unpaid part and a `clawback` for the rest — and spine §2.19 says so two
  paragraphs below the index that forbade it. The second debit was a 1062 inside the reversal
  transaction, so the whole refund rolled back and the receipt kept its commission. Migration 22 adds
  `purpose` (D73).
* `uq_cle_source` allowed each partner exactly **one** manual adjustment, for ever. Its four columns
  are NOT NULL by design, and a manual adjustment has no causing row, so `source_id` falls back to the
  collaborator and the tuple never varies. Migration 23 adds the generated `source_guard`, NULL on the
  two manual purposes, which drops them out of the index while every receipt and reversal keeps the
  guarantee unchanged (D74).

**Four more defects the probes caught, each of them money.** A superseded referral had
`commission_eligible` cleared, so a receipt back-dated into the previous partner's own window earned
nothing — the re-attribution INV-18 forbids. Resolution filtered revoked rows out of the query, so a
revoked attribution fell through to the predecessor whose window overlaps the changeover day; the
resolver now sees every row covering the date, takes the newest decision, and *then* asks whether it
earns, because "nobody" is a decision. Branching on `entitlement_amount === null` turned every partner
with a `max_commission_amount` into a prorated one — a cap on the `paid` base is a ceiling, not a
promise, and "10 % of each receipt up to 1,500" was quietly becoming "1,500 spread across the charge".
And four columns cast `array` were handed pre-encoded JSON, so `rule_snapshot` — the permanent record
of what a past entry meant (§6.1.9) — stored a document containing a document and read back as a
string.

**G3 asks a different question from the one that already existed** (D75). `countsAsReceived()` excludes
a fully refunded receipt, correctly: the business does not have that money. But a refunded receipt
still **earns**, and its reversal posts the offset — which is precisely what makes the order of the two
jobs irrelevant. `earnsCommission()` is the second question, and the two differ by exactly one case.

`CollaboratorWalletService` ships half a class (D72): `LedgerWriter` must move the cache inside the
ledger's own transaction, and Phase 12 adds the reporting half to the same file rather than a second
class with a similar name.

**Verified end to end on a real commit path**, not in a rolled-back transaction: receipt -> event ->
listener -> job -> engine -> ledger -> wallet, with `QUEUE_CONNECTION=sync` so the probe exercises the
production code path. Every worked example in spine §6.1.7 passes, including the fixed 2,000 prorated
over three receipts of 10,000 as 666.67 + 666.66 + 666.67 = **2,000.00 exactly**, and three partial
refunds undoing 333.30 + 333.30 + 333.40 = **1,000.00 exactly**. After every scenario, each wallet
equals the sum of its ledger and the §6.5.2 closed identity holds.


### 2026-09-20 — Phase 10 registries, and a permission that had been going to the wrong role

Four money modules, twenty-four settings, and one narrow ability.

**`project_payments.link_invoice` permits exactly one mutation** (D43): `invoice_id` moving NULL → value
→ NULL through `InvoiceService`, with a mandatory reason, audited, and with zero commission effect. It
belongs to **no preset** — specifically not to `MONEY`, so holding it reveals no amount — it is written
on one slug, and the Accountant is the only seeded role that gets it. The point of a single-purpose
ability is that it cannot be reused: a registered `edit` on a money module is exactly what a later role
edit or seeder would quietly repurpose for a real edit, while nothing else checks `link_invoice`.

**None of the four money modules has `edit` or `delete`, and none ever will** (INV-8, INV-5). Voiding a
receipt is `change_status`; a reversal is created and approved, never amended.

**A defect the Phase 8 commit shipped, found here.** The collaborator payout-account grants I added then
landed in the **Digital Marketer** block, not the Accountant's — both roles' permission lists end with
the same `website_media` line, and the anchor matched the wrong one. A marketing role has been holding
`collaborator_payout_accounts.*` since that commit. It was visible in the convergence output at the time
(`+ grant Digital Marketer | collaborator_payout_accounts.delete`) and I read past it. Both grant blocks
are now in the Accountant, and the stale rows were removed from the dev database explicitly — seeders
converge additively (D65) and would never have revoked them.

**Two other things the seeders caught that a reading would not.** `Ability::label()` and `::color()` are
`match` expressions with no default, so the new case was an `UnhandledMatchError` the first time
`PermissionSeeder` ran — and `EnumContractTest` pins the exact ability list, whose own docblock already
said `link_invoice` "joins the same tail in Phase 10".

**A migration that was quietly costing every test run.** `add_external_fks_to_financial_tables` asked
`Schema::hasTable()`, `Schema::hasColumn()` and an `information_schema` existence query **per key** —
about two hundred metadata round trips, which made it the slowest migration in the project at 19
seconds. Every test class that refreshes the database paid that again. Three queries answer the same
questions, and a full `migrate` went from 74 seconds to 54.

**Verified:** 105 modules / 1,037 permissions / 24 new settings seeded and read back; `link_invoice` and
the payout-account permissions held by exactly Accountant, Admin and Super Admin; and the Settings, Rbac
and Modules suites green at 613 tests.


### 2026-09-20 — Phase 10 models: three guarantees, stated once

Fifteen models, and the interesting part is a single trait. `FinancialRow` states three things so that
twelve classes do not each carry their own version of them:

1. **A money row is inserted only by its owning service** (INV-21, [D-IMP-2]). The service assigns the
   number under a lock, snapshots the attribution, writes the cache delta and schedules the follow-up
   work. A row created anywhere else has none of that **and looks completely normal in the table** —
   which is why the refusal is structural and names the service it wants.
2. **Only a short whitelist of columns may ever change.** What somebody earned, from which receipt,
   under which rule, at which rate, on which date — none of it moves. What moves is the bookkeeping
   *around* the row: its status, its approval, how much a payout has claimed, how much has been undone.
3. **Nothing is ever deleted.** The database has the trigger; the model throws **first** and says why,
   because the spine's R-5 lesson is that a bare `SQLSTATE 45000` with no explanation is how a trigger
   eventually gets dropped.

The escape hatch is one greppable call — `allowDirectWrites()` — restored in a `finally`, so a throwing
factory cannot leave the guard open for the rest of the process. The probe asserts that specifically.

**Three defects the verification found, none of which a reading would have.**

- `CollaboratorWalletReconciliation::isClean()` **shadowed `Model::isClean()`**, Eloquent's dirty-tracking
  method, with an incompatible signature — a fatal error the moment the class was instantiated. It is
  `matchesLedger()` now.
- `replicate()` on six of these models **fails**, because Eloquent copies every loaded attribute and
  MariaDB refuses an INSERT that names a generated column (`1906`). The error names the column but not
  the reason. `HasGeneratedColumns` declares them and drops them from the copy, and the list is now
  written down where somebody adding a cast can see it.
- `activitySecretAttributes()` was overridden with `parent::` — but it comes from a **trait**, not a
  parent class, so there was nothing to call. The two payout models restate the four defaults.

**What the models carry beyond their columns.** `payableRemaining()` is one expression of the rule the
`payable()` scope filters on, so a screen and a query can never disagree about what a payout may
consume. `CollaboratorCommissionEntitlement::remaining()` returns **null** when the promise is uncapped
rather than a large number, because a caller that treats "no limit" as a figure will eventually compare
it. `CollaboratorWallet::identityHolds()` puts R2 — `lifetime = pending + available + reserved + paid` —
where the nightly job, a test and a screen all ask it in the same words.

**Verified:** 201 assertions over 141 relations against the live schema, and 52 on the write guards in
a rolled-back transaction.


### 2026-09-20 — Phase 10 schema: fifteen tables, and the guarantees they actually carry

The commission spine's whole schema in one atomic set. What is worth saying about it is not the column
count — it is which rules are **structural** rather than hopeful.

**`uq_cle_source(source_type, source_id, collaborator_id, purpose)`, with all four columns NOT NULL.**
The obvious-looking alternative — a guard over `(student_fee_payment_id, project_payment_id,
collaborator_id, source_type)` — would let **every** project commission through, because they all share
`student_fee_payment_id = NULL` and MariaDB unique indexes ignore NULLs. This index makes a second
commission for the same receipt impossible even if somebody hand-crafts a different dedupe key.

**Six generated guard columns exist for one reason**: MariaDB unique indexes ignore NULLs. A column
that is `1` when a row is current and NULL otherwise turns "at most one active referral per subject",
"one open rule version per scope", "one current entitlement per document" and "one default payout
account" into unique indexes that superseded rows stack freely underneath. The alternative is a service
that checks first and writes second, which is a race with a name.

**`chk_cce_cap` is the over-release ceiling** (INV-12): even a future caller with a bug in the
proportional arithmetic cannot push total commission past the promise, because the UPDATE fails. A rule
enforced only in a service is a rule that holds until somebody writes a second service.

**`idx_cr_superseded_by` is deliberately NOT unique** (ND-12). One winner legitimately supersedes
several rows — the previously active referral plus one per losing candidate — and a unique index there
would 1062 on the second of those perfectly legal writes and destroy the attribution evidence the table
exists to keep.

**`trg_cle_no_delete` is the one that matters most.** Every duplicate guarantee in the engine rests on a
unique index, and a DELETE would **free the unique slot for a second commission on the same receipt**.
Without the trigger, "a payment can never pay twice" is true only as long as nobody deletes a row.

**Two defects, both found by verifying rather than by reading.**

- **D70.** An index name overflowed 64 characters mid-`CREATE TABLE`. MariaDB DDL is not transactional,
  so the server kept the table while the migration was recorded as failed — and the re-run's
  `if (Schema::hasTable(...)) return;` saw a table and **skipped every CHECK constraint on it**. The
  schema then looked complete and was not. The guard now covers the CREATE only; the constraints are
  ensured on every run. The §2 object verification is what caught it; the migration reported success.
- **D71.** Laravel's generated foreign-key name overflows on the two longest tables. Names are now
  explicit and stable, because `down()` has to find what `up()` created.

**`RawSchema` writes nothing it does not then prove.** Every CHECK, generated column, guard index and
trigger is read back out of `information_schema` and throws if the server did not keep it — no
try/catch, no silent skip (spine R-3). A constraint the server quietly ignored is worse than none,
because every layer above it goes on trusting a promise that is not there.

**Verified:** the §2 object list **126/126** on both databases; the guards **58/58** in a rolled-back
transaction; `rollback --step=21` leaving no table and no trigger behind, and re-migrate clean.


### 2026-09-20 — Phase 9: the click, the carrier, and the ladder that decides

**A refused code is still written down.** `invalid_code`, `collaborator_not_eligible`, `self_referral`
and `bot_filtered` are named outcomes on a kept row rather than a missing one, because "forty-one people
this month used a code that no longer exists" is something a business acts on, and a gap in a table
cannot tell it. The conversion report's dead-code panel is that fact made visible — it is how a partner
who printed an old flyer actually gets found.

**The browser never carries a referral code.** The session, the cookie and the hidden form field all
carry the same opaque **visit token**, and the server re-resolves the collaborator from the row it names
on every read (INV-R2). A visitor who edits the value can at worst point at a visit that does not exist;
they can never name a partner they never came through, which is exactly what a code in a cookie would
let them do. The cookie is encrypted, `httpOnly` and `sameSite=lax`, and the test asserts the code does
not appear in it.

**Three carriers, deliberately.** The session survives a form post, the cookie survives the session
expiring between the click and the admission a week later, and the hidden field survives a visitor who
blocks cookies. They agree because all three hold the same token.

**`capture_referral` runs before `site.cache`.** An anonymous visitor is served a stored copy of the
page, so a capture placed after the cache middleware would never run at all — for precisely the
visitors a referral link brings. It is attached to every public stack a person can land on (including
`/{slug}`, the most likely page on a flyer) and to **no** panel group: a signed-in member of staff
following a partner's link is not a referral.

**The ladder, and what it keeps.** Six ranks, first eligible wins: a staff pick, a typed code, then the
session, the cookie, the hidden field and the raw `?ref=`. Staff choosing "no collaborator" is rank 1
with a **null winner** — a decision, not an absence — and no referral row is created at all. Every
lower rank that named a *different* partner is kept as a loser with its reason, because the question
this system will actually be asked is never "who won"; it is "why did my code not win", six weeks
later, by somebody whose commission depended on the answer.

**Effective dating is floored at the click.** A back-dated admission credits the partner from the
admission date, never from before their link was used, and never from the future. Both directions are
asserted.

**Two defects the work found.**

- **`uq_crv_token` is unique, and the first draft ignored that.** A visitor arriving through a *second*
  partner's link reused the token they already carried, which meant a 1062 that `capture()`'s own
  try/catch swallowed — so the second partner's click silently vanished. Found by walking four real
  HTTP requests with curl and reading the table. A different code now mints a new token, and the older
  visit stays as evidence for `first_touch` attribution to find.
- **Staff "no collaborator" was being overruled by a cookie.** The flag was resolved *after* the lower
  ranks had already produced a winner, so a captured code quietly won anyway. It is rank 1 and is now
  resolved as one. Found by the probe.

**Two earlier phases' contracts moved, deliberately.** Phase 3's and Phase 4's route manifests pin the
exact middleware stack of every public route — which is the point of them — so adding `capture_referral`
drifted sixteen rows. They are updated with the reason. `site.careers.apply` is the one POST that
carries it, because it lives inside the jobs group and a POST inherits its group's stack; that is
harmless, because the middleware acts only on GET and HEAD.

**And one test that was passing for the wrong reason.** `MarketingReviewerIsolationTest`'s
contract-64 case stored a `users.id` in `contact_inquiries.collaborator_id`. A collaborator is not a
user (D2), and it only worked because the foreign key Phase 4 deferred did not exist yet — Phase 6's R-9
named that exposure out loud. The promotion in this release turned it into a 1452, and the fixture now
names a real partner record.

**Verified:** 58 probe assertions, 38 tests in the two collaborator gates, and 188 across Modules,
Views, Platform and the smoke test; the CMS and marketing route suites green at 121 after the manifest
updates.


### 2026-09-20 — Phase 8: the admin screens, and a guarantee that had an exception in it

Eighteen routes, one policy, seven screens, and a browser check that found two things a test would not
have.

**The policy was not enough for `forceDelete`.** INV-C5 says a collaborator record is never destroyed,
and `CollaboratorPolicy::forceDelete()` duly refuses it — but `Gate::before` allows a Super Admin
everything and **never reaches the policy**. So the one account that could make that mistake was
precisely the one the guarantee did not cover. The refusal is now a model `deleting` hook as well, and
the test asserts both halves: the policy refuses whoever it is consulted for, and the model refuses the
Super Admin. The RESTRICT foreign keys from the ledger, the payouts and the attributions are still the
last line; the hook is the one that says *why*.

**`activity_log.collaborator_id` was never being filled.** The column shipped with the migrations, and
`CollaboratorActivityService::record()` stamps it — but a profile edit or a status change goes through
spatie, which knows nothing about it, so the admin activity screen read "0 entries" for a partner whose
record had been approved, edited and suspended. `tapActivity()` now asks the model
`activityCollaboratorId()`, and `Collaborator` answers with its own id. Found by opening the screen.

**Two feeds, not one.** `feed()` is the **partner's** view and is limited to §60's eleven events with
the property allowlist applied. `auditTrail()` is the **admin's** view behind `collaborators.view_logs`
and is deliberately *not* limited — an auditor asking "what happened to this partner" must not be shown
a filtered subset without being told.

**The live link preview was building its own base URL.** The two real referral links come from
`CollaboratorCodeService`, which prefers `seo.canonical_base_url`; the Alpine preview box built one from
`config('app.url')`. On this installation those differ, so the preview showed `http://localhost:8000`
for a link that is actually `https://myoffice.test`. `baseUrl()` is now public and both sides ask it.

**The picker is narrow by construction.** `admin.collaborators.options` selects five columns as a
literal list that nothing in the request can widen, orders active partners first, and never offers a
suspended or a removed one — a suspension exists precisely to stop new business flowing to somebody.
The test asserts the exact key list and that the email and phone of a matching partner are absent from
the body.

**Verified:** 20 tests / 77 assertions in the new gate; 313 tests across the Modules, Views, Audit, Rbac
and Panels suites; and the screens opened in a browser with three partners seeded into the dev database
— including submitting the edit form through the UI and watching the skill set replace rather than grow.


### 2026-09-20 — Phase 8: the registries, the role grants, and the first four services

**Two module slugs and 37 new grants, all additive.** `collaborator_payout_accounts` exists as its own
module because §55 asks for sensitive payout data to be protected: an Accountant who may approve a
payout should not thereby get to manage *where the money goes*. It deliberately carries **no
`view_financial`** — no ability anywhere reveals `details_encrypted`, so there is nothing to unmask
(INV-C6). `collaborator_referral_visits` is separate from `collaborator_referrals` because those rows
carry IP addresses and user agents, which a Sales Executive who may link a referral has no business
reading.

`collaborators` gained `approve` / `reject` / `view_logs` and `collaborator_referrals` gained `create` /
`edit` / `change_status` / `view_logs`. **Nothing was removed.** `assign` and `upload` on `collaborators`
are not in §4.2's list, but they were registered in Phase 1 and taking them away would revoke a
permission an administrator has already granted — D65's rule, which is why the seeders converge rather
than `syncPermissions`.

**Sixteen settings, one readonly.** `collaborator_code_next_number` carries `readonly => true` (D62) for
the reason every other counter does: a settings form posts every field, so an admin saving an unrelated
key with a stale counter would re-issue a collaborator ID that is already quoted in a commission
dispute. Only `DocumentNumberService` moves it, under a row lock.

**D65 convergence proved on the dev database, twice.** First run: +2 modules, +16 settings, +37 grants,
**0 values changed, 0 modules toggled, 0 grants revoked**. Second run: 0 / 0 / 0.

**Four services, and one deliberate deferral.**

- `CollaboratorCodeService` — the **one** normaliser. A partner reading a code off a printed flyer types
  `COL--1024`; treating that as a different code would lose the attribution, so normalisation collapses
  repeated hyphens, strips inner spaces and upper-cases, and every caller goes through it. INV-C2's lock
  walks all three referencing tables with a `hasTable()` guard each — a code must not be declared free
  merely because the spine table that would hold its evidence has not been built yet.
- `CollaboratorService` — create, edit, the skill set, the services pivot, soft delete. Four columns are
  unreachable from here on purpose: `collaborator_code`, `referral_code`, `status` and `user_id` each
  have their own method, their own permission and their own audit row.
- `CollaboratorOnboardingService` — §6.2.2's transition table now lives on the enum. **A suspension has
  to reach the session, not only the row**: a suspended partner whose browser still held a valid session
  would keep reading their own dashboard until it expired, so the suspension mirrors onto `users.status`
  (Phase 1's `active` middleware is the single enforcement point) *and* deletes the user's `sessions`
  rows. Login provisioning goes through Phase 1's `UserService` rather than a second `new User` — with
  the actor deliberately **not** passed, because `UserService` would otherwise refuse the role grant
  unless the approver personally held every `collaborator_portal.*` permission, and approving a partner
  is authorised by `collaborators.approve`, not by being able to do a collaborator's job.
- `CollaboratorActivityService` — §60 as a filtered view over `activity_log` (D13), keyed on
  `collaborator_id` rather than the causer, because commission-created and payout-paid rows are written
  by the engine with a **null** causer and scoping by causer would silently drop exactly those. The
  property allowlist is applied **twice**, at write and at read: rows written before an allowlist
  tightened must not start leaking because the write-time filter was the only one. `reason` goes in its
  own column, so a staff sentence about a collaborator ("suspended after repeated disputes") is
  structurally unreachable from that collaborator's own screen rather than filtered case by case.

**Deferred, and logged as owed:** `CollaboratorPayoutAccountService` and `CollaboratorPortalMetricsService`.
Both write or read **spine** tables that ship with Phase 10 (§1.4 [D-P8-1]). Writing them now would mean
committing code that cannot be run, let alone probed, against a table that does not exist — so they wait
for that release instead.

**Verified:** 77 service assertions in a rolled-back transaction, and 700 tests / 15,346 assertions
across the five suites the registry changes touch.


### 2026-09-20 — Phase 8/9 foundation: the collaborator record, and nine deferred foreign keys

**Eight enums, 51 cases.** `CollaboratorStatus` is the whole of eligibility — there is no
`commission_eligible` column anywhere (INV-C4), because a second expression of the same fact is a second
thing that can be wrong. `CollaboratorActivityEvent` carries a `visibleProperties()` allowlist rather
than a blocklist, so a partner's own feed shows what it was told to show and an internal note added
later cannot leak by default. `ReferralCandidateChannel::referralSource()` returns a **string**, not the
spine's `ReferralSource` enum, because the spine ships in Phase 10 — the value is right and the type
tightens later, rather than this phase inventing a duplicate enum it would have to delete.

**Four tables, and nine foreign keys that were owed.** `collaborators` (no `branch_id` — a referral
partner introduces a student to whichever branch suits the student, [D-P8-2]), `collaborator_skills`,
`collaborator_service`, and Phase 9's `collaborator_referral_visits`. The interesting half is §2.5a and
[D-P6-1]: **nine columns that earlier phases shipped unconstrained**, because `collaborators` did not
exist yet — Phase 4's `contact_inquiries.collaborator_id`, Phase 6's five, Phase 1's `activity_log`, and
the two `referral_visit_id` columns. Phase 6's R-9 named the exposure out loud: until today a typo could
write `collaborator_id = 999`. All nine are now real constraints, and the five Phase 6 ones are
**RESTRICT** — a partner with delivery history must not vanish, and a cascade would take somebody's time
entries with them.

**Pre-existing orphans are nulled by a reported pre-pass, never deleted.** MariaDB refuses a foreign key
over a dangling value, so the promotion has to do something about one. These columns are display
snapshots under **D37**, re-derivable from the attribution rows, so nulling a dangling pointer loses no
fact — but every migration prints and logs the count, because a silent repair of attribution data is
exactly what INV-R1 forbids.

**Two defects found by writing the probe rather than by reading the code.**

- `Collaborator::services()` used `withTimestamps('created_at', null)`. Laravel takes the *updated_at*
  column name from the **parent** model when the second argument is null, so it would have added
  `updated_at` to the pivot columns and then written it — to a pivot that deliberately has no such
  column (§2.3: "the row has nothing to update"). Every `attach()` would have been a SQL error. The
  relation is now `withPivot('created_at')` and the service supplies the stamp.
- `CollaboratorReferralVisit::isAttributable()` and `scopeAttributable()` were two independent
  statements of the same rule — the row check asked the enum, the query hardcoded `Captured`. They agree
  today and would have drifted the first time a case was added. Both now read
  `ReferralVisitOutcome::attributableCases()`, and the probe asserts they agree on all four negative
  cases (expired, crawler, dead code, already spent).

**Verified:** §2 object list **40/40** against `information_schema` on both databases; model behaviour
**45/45** in a rolled-back transaction; `rollback --step=7` clean and re-migrate clean; the four suites
the new constraints touch (Audit, Project, Crm, Modules) green at 126 tests.

**What is not here yet:** the registries, the six services, the policies, the routes and every screen.
This commit creates the *subject* of money and the *evidence* of attribution — not one line of it
inserts a ledger row, a wallet balance or a payout.


### 2026-09-20 — Phase 7 follow-up: the display formats, and the five-hour attendance bug behind them

**Every `->format()` in the Phase 7 views is gone.** `NoHardcodedFormatsTest` found 33 of them across
14 Blade files — dates, times and month labels printed with a hardcoded pattern instead of through
`app_date()` / `app_time()` / `app_datetime()`, which is the only path that honours the
`localization.*` settings. All 33 are now helper calls; the helpers take an explicit pattern, so
`app_date($d, 'l')` and `app_time($t, 'H:i')` keep the places that genuinely wanted a fixed shape.

**That fix exposed a real defect (D69).** With the times finally routed through `Format`, the
attendance register printed **14:02 – 22:10** for a punch at nine in the morning, and marked everybody
late. The cause was not the formatter:

- `work_shifts.start_time` is **business wall clock** — "09:00" means nine o'clock where the business is.
- `ShiftWindow::fromShift()` built the window by stamping that time onto the date **on a UTC clock**.
- The punch itself comes from `now()`, a genuine UTC instant.

So the two sides of every comparison meant different things, and in Asia/Karachi (UTC+5) that is a
five-hour error in one direction: every arrival late, every departure early. `fromShift()` now builds
the day in `Format::timezone()` and converts with `->utc()`, and `AttendanceService::businessDate()`
files a row under the business calendar date rather than the UTC one — so a 01:00 punch in Karachi is
still yesterday's night shift, not a row of its own.

**A test that pins it.** `a_shift_window_is_business_time_and_a_punch_is_measured_against_it` builds
09:00 business time, punches it, and asserts `late_minutes === 0` and that the register reads `09:00`;
a second punch at 09:45 asserts 30 late minutes (45 less the 15-minute grace) and `AttendanceStatus::Late`.
The old code passes none of it.

**Verified in the browser, not only in the suite.** The dev month was re-seeded with punches built the
way a real kiosk in Karachi produces them, and `/admin/attendance?date=2026-08-05` now reads
`09:40 – 17:10 · 25m late` for the one late employee and `09:02 – 17:10 · Present` for the other two.

**The browser check has now earned its place three times** in this phase: the correction queue's
`Unknown column 'branch_id'` 500, `periodLabel()` printing `2026-08` from a column that narrowed selects
never loaded, and this. None of the three was visible to a service-level probe.

**Suite:** 1,577 tests / 64,965 assertions green (run in two slices for live progress —
1,121 / 39,744 and 456 / 25,221).


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
| 2026-09-24 | Feature suite, by directory | `DB_DATABASE=my_office_test php artisan test tests/Feature/<dir>` | PASS — **2,511 tests** across Settings (358), Rbac (163), Modules (94), Account (76), Auth (60), Views (29), Dashboard (27), Install (27), Support (18), Panels (16), Audit (11), Platform (11), Schema (1). Run one directory at a time: the whole suite exceeds the ten-minute cap and two concurrent runs destroy the database (D157). **Cms, Crm, Institute, Hr, Financial, Collaborator and Project were not re-run** — each exceeds the cap alone, and D142's suite-performance work is Phase 24's. Five real defects came out of the ones that did run, four of them Phase 22's: the piped `can:` that denied three screens to everyone but Super Admin, the client portal advertising a permission its route does not use, `meetings` being unable to roll back, a calendar bypassing the display timezone, and `support.ticket_next_number` being editable |
| 2026-09-24 | Phase 23 schema — `report_exports` and the reporting indexes | probe over `information_schema`, both databases | PASS — **107/107** on each: every column type, nullability and default, the four keys and their exact column order, `chk_rx_counts`, the FK's RESTRICT, the five log indexes, and that `activity_log` gained no column. Rollback verified non-destructive first: its 1,649 rows survived, which is what INV-23-5 means in practice |
| 2026-09-24 | The `reports` settings group | registry probe + HTTP screen probe, rolled back | PASS — **86/86** and **41/41**: fifteen keys with their declared types and defaults, both list defaults checked against their sources rather than a copy, the seeder idempotent and refusing to overwrite a value somebody set, and the tab rendering last in the rail with a save that writes and an invalid payload that is refused without writing |
| 2026-09-24 | `ReportExport` | behavioural probe, rolled back | PASS — **54/54**: casts, the uuid hook, the guarded columns (a forged payload cannot point the row at somebody else's file), the four self-answers, the three scopes, the delete refusal, and the database's own half — `chk_rx_counts` against a negative count, `uq_rx_uuid` against a duplicate, and the FK refusing to hard-delete a user who has exports |
| 2026-09-24 | Phase 23 permissions | probe over §4.1/§4.2/§4.3/§4.4, rolled back | PASS — **52/52**: `audit_trail` declared non-core with its dependency, `activity_log.print` added, Admin holding the trail and **not** `print_templates.delete`, §9.4 still holding after a re-seed, and `Gate::before` denying a disabled module's ability to an Admin who holds it while leaving `activity_log` alone |
| 2026-09-24 | `ReportRegistry` + `ReportEngine` | probe with three throwaway reports, rolled back | PASS — **68/68**: the duplicate-key throw, the derived permission stack, the meta stamp, the cache keyed per viewer, the column strip (a money column **absent** from the row, not blank), the filter strip recorded in meta, `describe()` narrowing with it, the unavailable path, §9.5 steps 1–3, and the three ways an export ends |
| 2026-09-24 | **All 33 reports** | sweep: plain, every filter set, every alternative date column, `describe()` | PASS — **589/589**. Each report is run three ways because a filter or a date column only executes when somebody uses it, and until then one naming a renamed column is invisible. Includes the money reconciliation: the two commission reports together, `co.performance`, `SUM(wallet.lifetime_earned)` and `SUM(ledger.signed_amount)` all agree |
| 2026-09-24 | §106 and §107 | probe over hand-written fixtures, rolled back | PASS — **88/88**: a `created` row excluded from the trail, only changed fields in the diff, an encrypted value never printed (the secret string appears nowhere, Super Admin included), a withheld figure marked and named while **the row stays**, the marker reaching the exported file, the module scope applied in SQL so the total is zero rather than the row merely missing, and INV-23-5 asserted by reflection — neither service has a write method |
| 2026-09-24 | §108 global search | probe with two identically-named students, rolled back | PASS — **98/98**: the student finds exactly themselves while a Super Admin finds both, pasting another student's code finds nothing, the entities setting narrows and never widens, a disabled module drops its provider, a deliberately broken provider is named as unavailable while the other ten answer, and a hit the viewer cannot open is shown **without a link** rather than dropped |
| 2026-09-24 | Every Phase 23 screen | 16 routes through the HTTP kernel, rolled back | PASS — **97/97**, after two real finds: the base `Controller` in Laravel 12 has no `authorize()` (each controller brings `AuthorizesRequests` itself), and a Phase 1 `ActivityLogController` already owned `/admin/activity-log`, so mine would have shadowed it and was deleted. All 33 report screens render, the CSV export streams with its own headers and its explaining block, and an unknown report key 404s |
| 2026-09-24 | Unit suite after Phase 23 | `DB_DATABASE=my_office_test php artisan test --testsuite=Unit` | PASS — **1,620 tests / 39,209 assertions**, after three real fixes: `audit_trail` is the first System module deliberately declared switchable (the invariant now names its exceptions rather than being relaxed), the settings-group list had not been updated since Phase 22, and **`support.ticket_next_number` was not `readonly`** — a D62 violation that would let an administrator roll the ticket counter back and hand the next few tickets numbers that already exist |
| 2026-09-24 | The six Phase 19-21 triggers | probe over the audiences, rolled back | PASS — **16/16**: a targeted student is told and an untargeted one is not, the row names its module and its material, all six keys resolve in the registry, all five owning services still construct, and a dispatch with no audience is a no-op rather than a throw |
| 2026-09-24 | Institute suite after the triggers | `DB_DATABASE=my_office_test php artisan test tests/Feature/Institute/` | PASS — **600 tests / 20,560 assertions**, 411 s, after injecting `NotificationService` into five Phase 19-21 services |
| 2026-09-24 | The seven scheduled sweeps | probe with fixtures built to be swept, rolled back | PASS — **41/41**, and every section runs its sweep twice: the second run must move nothing. Covers the SLA booleans, a ticket parked on the customer being left alone, auto-close skipping one the requester answered, the department recount, reminders skipping a decliner, `close-past` leaving a meeting that ended fifteen minutes ago, and a retention of `0` meaning keep-for-ever |
| 2026-09-24 | Every portal screen, four panels | 44 GET routes through the HTTP kernel, rolled back | PASS — **44/44**, after the probe's own guard handling stopped hiding two real 403s: `SupportTicketPolicy::viewAny()` ignoring portal users, and `collaborator_portal.support_tickets` never having been declared (D151) |
| 2026-09-24 | Sidebar after the portal entries | `DB_DATABASE=my_office_test php artisan test --filter=SidebarVisibility` | PASS — **9 tests / 228 assertions**; all four portals carry Meetings, Messages, Support and Notifications, and disabling `collaborators` now leaves exactly those four rather than emptying the tree |
| 2026-09-23 | Every Phase 22 admin screen | 21 GET routes through the HTTP kernel against live data, rolled back | PASS — **21/21**, after finding two 500s on the first run: the queue and the SLA desk both ordered on column names I had guessed (`sla_resolution_due_at`, `last_activity_at`) rather than the ones the table has |
| 2026-09-23 | Sidebar after the Workspace group | `DB_DATABASE=my_office_test php artisan test --filter=SidebarVisibility` | PASS — **9 tests / 226 assertions**; the five new entries appear for a Super Admin, and `Files` and `Reports` stay hidden until Phase 23 registers their routes |
| 2026-09-23 | The Phase 22 policies | probe over §9.4's table, rolled back | PASS — **54/54**, and one real finding on the way: a client sees their firm's shared ticket and not its private one and not another firm's; a requester may reply and reopen and may never set a priority, assign, or write an internal note; an internal note is invisible to the requester; being in the room is what grants a meeting, and removing somebody revokes it at once; a student never writes minutes and reads them only once the meeting is completed; `messages.view_any` reads and never writes; somebody who left a thread loses it; a desk with tickets cannot be deleted but can be retired; and for every invariant the gate says yes to a Super Admin while the model still throws |
| 2026-09-23 | The notification layer | behavioural probe over 12 sections, rolled back | PASS — **75/75**: the registry's 53 events and their groups, an unknown key throwing with a named near miss, the row's five columns, inactive / missing / unpermitted recipients each counted separately, preferences saved, cleared when they agree with the default and ignored when they try to mute a mandatory event, the master mail switch, a disabled module silencing its events and then the bell itself, the page-plus-one bell query, archive-is-not-delete, the memoised counters, and `dispatchToPermission` resolving to exactly the holders |
| 2026-09-23 | The pre-Phase-22 notification classes | probe against the table that did not exist when they were written | PASS — **9/9**: a Crm and a Cms notification both land, carrying their own `kind` (and, for the one that called it `type`, that) promoted to `event_key`, with the module, a level and the deep link they already built, and `data` untouched so rows written before today still read |
| 2026-09-23 | The six listeners | probe draining after-commit callbacks by hand, rolled back | PASS — **32/32**: nothing fires before the commit; an internal note never reaches the requester; a public reply does; a requester's reply reaches the assignee; nobody hears about their own reply, meeting, message or reopen; the desk's own queue moves tell the requester nothing while `resolved` does; `notified_at` is stamped; an external guest gets no row; a muted thread sends nothing but still moves the unread count; and every row written carries a registry key, a link and a level |
| 2026-09-23 | The fee reminder, end to end | probe, rolled back | PASS — **9/9**: the reminder row is written first and unconditionally, a `fee.overdue` bell row lands with the amount and the fee it is about, and a student who muted it keeps the reminder row and loses only the bell row — which is the whole reason it dispatches through the service |
| 2026-09-23 | `meetings` as a clash occupant | probe over the generated columns and the detector, rolled back | PASS — **18/18**: `meeting_date` / `meeting_start_time` / `meeting_end_time`, the midnight clamp keeping end after start, overlap, back-to-back, another day, cancelled and soft-deleted releasing the room, the self-ignore doing real work, and a virtual room holding nothing. The cancellation leg found `chk_me_cancel` refusing a cancel with no reason — the constraint working |
| 2026-09-23 | `MeetingService` | behavioural probe over 15 sections, rolled back | PASS — **85/85**: booking and counts, derived `participant_type`, the four form refusals, outside guests and the switch that forbids them, the room block on and off with warnings carried out, answering, the retitle that keeps acceptances against the move that clears them, the reschedule chain and its refusal to fork, cancellation, the guest list, attendance closing a past meeting, the minutes refusing a student, the `.ics` (UID, SEQUENCE 0 then 1, one ATTENDEE, VALARM, RFC 5545 escaping, no line over 75 octets), the scope, and every cached count re-derived from its rows |
| 2026-09-23 | Scheduling regression after registering the new occupant | `DB_DATABASE=my_office_test php artisan test --filter="ExamLifecycle\|ScheduleClash\|Timetable\|ClassSession"` | PASS — **54 tests / 127 assertions**, 218 s. Adding `meeting` to the occupant list disturbed no existing clash behaviour |
| 2026-09-23 | Phase 21 acceptance | `DB_DATABASE=my_office_test php artisan test tests/Feature/Institute/Documents/` | PASS — **121 tests / 1,029 assertions**, 181 s: the certificate lifecycle, the verification endpoint against a hostile caller, the template sanitiser, the card register, authorization across five roles, and the manifest |
| 2026-09-23 | Phase 21 screens | the same suite's `DocumentScreenTest` — 31 cases over every route and **state** | PASS — a draft, an issued and a revoked certificate; a live, a replaced and a lost card; single and batch print; both PDFs; the student and teacher panels; the public page's three answers |
| 2026-09-23 | The public verification page | browser, `php artisan serve` on 8123 | PASS — desktop, 375 px mobile and dark mode all render with the site chrome; an unknown code gives a genuine 404 with the neutral wording, and the typed code is kept in the field |
| 2026-09-21 | Phase 16 schema | probe over `information_schema` on both databases | PASS — **639/639 each**: seven tables column by column, three generated guard columns, every named index and its uniqueness, 33 CHECKs, 30 foreign keys with their exact `ON DELETE`, and the assertion that `teachers.employee_id` still has none |
| 2026-09-21 | Phase 16 constraints | behavioural probe inside a rolled-back transaction | PASS — **55/55**: every CHECK bites, `current_guard` frees on drop, `active_guard` frees on cancel, `uq_cs_generated` makes generation idempotent, `teacher_id` RESTRICT and `classroom_id` SET NULL behave as declared |
| 2026-09-21 | `ScheduleClashDetector` | probe of the three dimensions, two exemptions and four day/date combinations | PASS — **38/38**, including the gap setting applying to teacher and room but never to a batch |
| 2026-09-21 | The six services | probe against live data, rolled back | PASS — **69/69**: teacher status guards, room closure guards, batch transitions, capacity and overbooking, transfers, generation, the four class moves |
| 2026-09-21 | Phase 16 screens | 38 GET routes through the HTTP kernel against live data | PASS — **38/38**, 0 failures: 29 admin screens, 6 teacher-panel, 3 student-panel |
| 2026-09-21 | Institute suite | `DB_DATABASE=my_office_test php artisan test tests/Feature/Institute/` | PASS — **157 tests / 10,756 assertions**, 158 s (45 of them Phase 16's) |
| 2026-09-21 | Phase 16 manifests | `--filter=SchedulingManifestTest` | PASS — 6 tests / 4,538 assertions; 61 routes and 29 GET screens matched against the live route table |
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
| 2026-09-20 | Phase 7 display formats | `tests/Feature/Views/NoHardcodedFormatsTest` | PASS — 33 hardcoded `->format()` calls across 14 HR Blade files replaced with `app_date()` / `app_time()` / `app_datetime()`; `tests/Feature/Views` **29 tests** green |
| 2026-09-20 | Phase 7 shift window is business time (D69) | `tests/Feature/Hr` after the fix | PASS — **31 tests**; the new case asserts a 09:00 business punch is 0 late minutes and renders `09:00`, and a 09:45 punch is 30 late minutes and `Late` |
| 2026-09-20 | Phase 7 attendance register, in a browser | dev month re-seeded, `/admin/attendance?date=2026-08-05` read in the browser | PASS — `09:40 – 17:10 · 25m · Late` for the late employee and `09:02 – 17:10 · Present` for the other two; before the fix the same rows read `14:40 – 22:10` and marked everybody late |
| 2026-09-20 | Full suite after the format + timezone fixes | `./vendor/bin/phpunit` in two slices (live progress) | PASS — **1,577 tests / 64,965 assertions**: 1,121 / 39,744 in 7 m 41 s and 456 / 25,221 in 16 m 42 s |
| 2026-09-20 | Phase 8/9 enum walk | every case's `label()`, `color()` and helper called | PASS — **8 enums / 51 cases**, including `CollaboratorActivityEvent::filterProperties()` dropping everything outside its allowlist |
| 2026-09-20 | Phase 8/9 §2 object list | `information_schema` counted in PHP, on `my_office_test` **and** `my_office` | PASS — **40/40** each: 4 tables, the §2.1 column list exactly and no `branch_id` ([D-P8-2]), `uq_col_code` / `uq_col_referral_code` / `uq_col_user` / `uq_col_email` / `uq_cskill`, the `(collaborator_id, service_id)` composite PK, `chk_crv_dates` and `chk_crv_visits` kept by the server, 9 FKs with the contract's delete rules, every clock column `DATETIME` and **0** carrying `ON UPDATE CURRENT_TIMESTAMP` (D67), no `deleted_at` on the three append-only tables (D19) |
| 2026-09-20 | Phase 8/9 migration round trip | `migrate` → `rollback --step=7` → `migrate` on both databases | PASS — nothing left behind, object list 40/40 again afterwards |
| 2026-09-20 | Phase 8/9 model behaviour | probe in a rolled-back transaction | PASS — **45/45**: INV-C1 and INV-C2 refusing a bare save, the four unique guards (and `uq_col_email` still tolerating many NULLs), INV-C4 across all four statuses plus the soft delete, the derived skill slug making a re-submitted form idempotent, the services pivot attaching / re-syncing / detaching, an expired, bot, dead-code and already-spent visit each refusing to attribute **with the query scope agreeing every time**, both CHECKs biting, and the RESTRICT wall refusing to force-delete a partner who has a visit. Found 2 real bugs (the pivot's phantom `updated_at`, and the scope disagreeing with the row check), both fixed |
| 2026-09-20 | Phase 8/9 regression on the constrained tables | `tests/Feature/{Audit,Project,Crm,Modules}` | PASS — **126 tests / 861 assertions** |
| 2026-09-20 | Phase 8 §4 / §5 registries | seeders on `my_office_test`, then rows read back | PASS — **101 modules / 994 permissions / 309 settings in 17 groups**; both new modules carry `depends_on = ["collaborators"]`, `collaborator_code_next_number` is readonly (D62), and all 16 `collaborator.*` keys landed with the contract's defaults |
| 2026-09-20 | Phase 8 seed convergence (D65) | backup, then Module / Permission / Role / Setting seeders on `my_office`, before/after snapshot compared in PHP | PASS — run 1: **+2 modules, +16 settings, +37 grants, 0 values changed, 0 modules toggled, 0 grants revoked**; run 2: **0 / 0 / 0** |
| 2026-09-20 | Phase 8 §6 services | probe in a rolled-back transaction | PASS — **77/77**: the four normalisation cases and four format refusals, the `%04d` counter never repeating, pending-by-default, an approver creating an active record directly, a vanity code normalised then refused to a second taker **without naming the holder**, INV-C2 freezing the code the moment a visit references it, a campaign query string kept rather than overwritten, all five §6.2.2 transition edges, a suspension locking the login and ending the live session, reinstatement needing no reason, a rejection landing on `inactive` with a mandatory reason, and the activity allowlist dropping a key at write time while `reason` stays in its own column |
| 2026-09-20 | Phase 8 registry regression | `tests/Feature/{Rbac,Settings,Modules,Audit,Account}` | PASS — **700 tests / 15,346 assertions** |
| 2026-09-20 | Phase 8 integration gate | `tests/Feature/Collaborator` | PASS — **20 tests / 77 assertions**: every screen's permission, module gating denying a Super Admin, 404-not-403 for a row out of reach, the picker's exact five columns with no contact detail in the body, create → approve → login with `must_change_password`, a rejection needing a reason, INV-C4 across all four statuses **and no `commission_eligible` column**, a suspension ending the live session, the closed §6.2.2 table, INV-C2's lock, a taken code refused without naming its holder, and the activity allowlist |
| 2026-09-20 | Phase 8 collaborator screens, in a browser | three partners seeded into `my_office`, every screen opened, the edit form submitted through the UI | PASS — the list, the queue, the record, the two forms, the referral links and the audit trail; the skill set replaced rather than grew, the duplicate collapsed, and the picker returned five fields over `fetch`. Found 3 real defects (`forceDelete` unreachable for a Super Admin, `collaborator_id` never stamped by the spatie path, the preview URL built from a different base), all fixed |
| 2026-09-20 | Phase 8 screen regression | `tests/Feature/{Modules,Views,Audit,Rbac,Panels}` | PASS — **313 tests**; `SidebarVisibilityTest` extended with the two new labels, the other five collaborator entries still hidden behind gate 2 |
| 2026-09-20 | Phase 9 §6.3 / §6.4 services | probe in a rolled-back transaction | PASS — **58/58**: all four classification outcomes, one row per visitor-code pair, a different partner minting a new token, tracking switched off writing nothing, a conversion stamped once, all six ladder ranks with the right channel and source, a staff pick keeping two losers, staff "nobody" beating a captured code, a suspended partner refused and a pending one allowed with a confirmation, a forged token naming nobody, an expired visit stamping itself, effective dating floored at the click and capped at today, and the audit payload carrying no money anywhere. Found 2 real bugs (the unique-token collision, staff "nobody" resolved too late), both fixed |
| 2026-09-20 | Phase 9 capture, over real HTTP | 4 curl requests against the dev site, then the rows read back | PASS — `ref_attr` set encrypted / httpOnly / sameSite=lax with a 30-day life; `captured` then `visits_count = 2` on a second page; `invalid_code` for a dead code; `collaborator_not_eligible` naming the suspended partner; `bot_filtered` for a crawler. The register and the conversion report were then read in a browser |
| 2026-09-20 | Phase 9 integration gate | `tests/Feature/Collaborator/ReferralCaptureTest` | PASS — **18 tests**; with Phase 8's gate, `tests/Feature/Collaborator` is **38 tests / 145 assertions** |
| 2026-09-20 | Phase 8/9 public-route regression | `tests/Feature/Cms` + `{Modules,Views,Platform,SmokeTest}` | PASS — 16 manifest rows and 12 marketing contract rows updated for `capture_referral` with the reason recorded; one Phase 4 fixture corrected (it stored a `users.id` in `contact_inquiries.collaborator_id`, which the new foreign key exposed) |
| 2026-09-20 | Full suite after phases 8 and 9 | `./vendor/bin/phpunit` in two slices | PASS — **1,615 tests / 66,198 assertions**: 1,213 / 41,704 in 4 m 49 s and 402 / 24,494 in 12 m 34 s |
| 2026-09-20 | Phase 10 enum contract, broadened | `tests/Unit/Enums` | PASS — **785 tests / 4,892 assertions** across **157 enums**, discovered rather than listed. Found 10 enums using the undefined colour token `zinc`, all corrected to `slate` |
| 2026-09-20 | Phase 10 §2 object list | `information_schema` counted in PHP, on `my_office_test` **and** `my_office` | PASS — **126/126** each: 15 tables, 9 STORED generated columns, 32 CHECKs the server kept, 31 named unique indexes, 9 `BEFORE DELETE` triggers, `idx_cr_superseded_by` present and NOT unique (ND-12), no `deleted_at` on the twelve append-only tables, every money column `decimal(15,2)`, every rate `decimal(8,4)`, and **0** columns carrying `ON UPDATE CURRENT_TIMESTAMP` (D67) |
| 2026-09-20 | Phase 10 migration round trip | `migrate` → `rollback --step=21` → `migrate` on both databases | PASS — 0 tables and 0 triggers left behind; object list 126/126 again afterwards. 8 external and 3 deferred foreign keys reported as waiting for phases 13-17, as designed |
| 2026-09-20 | Phase 10 schema guards | probe in a rolled-back transaction on `my_office` | PASS — **58/58**: one wallet per collaborator and a negative available balance still legal; one active referral per subject with several losers sharing one winner; one open rule per scope and a rule refused without its number; one current entitlement per document and INV-12 refusing an over-release; `uq_cle_source` and `uq_cle_dedupe`; the sign, reversal, debit-clean, INV-11 and INV-10 ceilings; `uq_cp_txn`; `uq_cpa_pair` holding even after release; `uq_sf_generation`; `net_received_amount` following a refund; and four no-delete triggers. Found 1 real defect (D70) |
| 2026-09-20 | Phase 10 models against the live schema | reflection walk over all 15 models | PASS — **201/201** across **141 relations**: no stray cast, no stray fillable, no generated column mass-assignable, every relation resolving to a real table. Found 1 fatal (`isClean()` shadowing Eloquent's) and 1 latent break (`replicate()` on the six tables with generated columns) |
| 2026-09-20 | Phase 10 model write guards | probe in a rolled-back transaction | PASS — **52/52**: seven insert refusals naming their service, `allowDirectWrites()` opening and closing (including after a throw), INV-8 on a receipt's amount and date, INV-4 on a commission's amount, rate and source, INV-17 on a rate change, three no-delete refusals, the entitlement's cap and its null-when-uncapped remaining, the referral date window in three directions, the wallet identity and freeze, and an allocation returning to available on a cancellation but not on a reversal |
| 2026-09-20 | Phase 10 §4 / §5 registries | seeders on `my_office_test` and `my_office`, then rows read back | PASS — **105 modules / 1,037 permissions / 24 new settings**; `project_payments.link_invoice` and `collaborator_payout_accounts.*` held by exactly Accountant, Admin and Super Admin. Found 1 real defect shipped by the Phase 8 commit (both grant blocks had landed in Digital Marketer) and 2 caught by the seeder (`Ability::label()` / `::color()` missing the new case) |
| 2026-09-20 | Phase 10 registry regression | `tests/Feature/{Settings,Rbac,Modules}` | PASS — **613 tests**: Settings 356 / 3,097 assertions, Rbac 163 / 11,598, Modules 94 / 713. `ModuleDependencyTest` updated for the two new edges (`project_payments` off `projects`, `payment_reversals` off that) |
| 2026-09-20 | Migration speed | `migrate` timed on an empty database, before and after batching the FK metadata lookups | PASS — **74 s → 54 s**; `add_external_fks_to_financial_tables` from 19 s to under 1 s, `add_internal_fks` from 7 s to 2 s. The object list still verifies 126/126 and the 21-file round trip is still clean |
| 2026-09-20 | Phase 10 §6 referral and rule services | probe in a rolled-back transaction | PASS — **71 checks**. Found 3 real defects: a superseded referral was made commission-ineligible (a back-dated receipt into its own window earned nothing), resolution could not see a revoked row blocking an older overlapping window, and a cancelled scheduled rule held the one open-ended slot so its predecessor could not reopen |
| 2026-09-20 | Phase 10 §6 the commission engine | probe in a rolled-back transaction, every worked example of spine §6.1.7 | PASS — **66 checks**. 10% of 10,000 = 1,000.00; three receipts earn three times; fixed 2,000 prorated over 3 x 10,000 = 666.67 + 666.66 + 666.67 summing to **2,000.00 exactly**; 35,000 on a 25,000 net charge earns on 25,000; 10% of 3,333.33 = 333.33. Found 3 defects: branch selection treated a cap as a promise, four `array`-cast columns were double-encoded, and G3 was asking `countsAsReceived()` |
| 2026-09-20 | Phase 10 §6 end to end | probe on a **committed** path with `QUEUE_CONNECTION=sync` — receipt to event to listener to job to ledger to wallet | PASS — **83 checks**: idempotent submits, the duplicate-fingerprint warning and its override, backdate limits, preview equals receipt to the paisa, approval (single, idempotent, bulk, capped at 500, skip-and-report), full and partial refunds (333.30 + 333.30 + 333.40 = 1,000.00), void, approval-gated refunds, rejection rolling the money back, manual adjustments and write-offs. Found 2 unique indexes that forbade legitimate rows (D73, D74) |
| 2026-09-20 | Phase 10 §6 regression | `tests/Unit` + 12 Feature directories | PASS — **1,399 tests / 33,288 assertions** |
| 2026-09-20 | Phase 10 §7 / §8 screens | every new route rendered through the HTTP kernel as the Admin demo user | PASS — 6/6 at 200. Found 4 `Undefined array key` warnings in the rule-preview endpoint (`validate()` returns only the keys that were sent) and the Super Admin's `must_change_password`, which 302s every request to the password screen before it reaches a route |
| 2026-09-20 | Phase 10 §10.4 commands | probe against the test database, including dropping a real index | PASS — **35 checks**. `financial:verify-constraints` fails and names `uq_cle_dedupe` when it is dropped and passes when restored; the sweeper recovers a stranded receipt and **leaves a skip untouched**; `commissions:evaluate` refuses without a reason, refuses an unbounded run, and cannot pay twice. Found a timezone bug that refused every receipt dated today |
| 2026-09-20 | Phase 10 §11 acceptance | `tests/Feature/Financial` | PASS — **37 tests / 237 assertions**. The static scan FT-IMP-01 fired on six legitimate `Money::percentage()` calls in payroll, attendance and project progress, so it now scans for commission arithmetic specifically rather than for the general money helper |
| 2026-09-20 | Phase 10 §7.1 / §8.2 screens | rendered through the HTTP kernel, then `tests/Feature/Financial/FeePaymentScreenTest` | PASS — 4/4 at 200; **11 tests / 63 assertions**. A double submit takes the money once, a submission with no idempotency key is refused and writes nothing, the preview writes nothing and matches the receipt, a refund needs `payment_reversals.create` rather than the receipt's own permission, and no edit or destroy route exists |
| 2026-09-20 | Phase 10 sidebar placement | `tests/Feature/Modules` | PASS — 94 tests. The Fee Receipts entry was first put in the Collaborator group; `student_fee_payments` is an **Institute** module, so it moved beside the charges it pays off. Its parent "Fees" node appears with it, because a group renders once it has a visible child |
| 2026-09-20 | Phase 11 project engine | `tests/Feature/Financial/ProjectCommissionEngineTest` | PASS — **10 tests**, and the whole financial suite at **58 / 358**. Found that the per-project override was gated on `projects.collaborator_id`, a display snapshot the engine is explicitly never supposed to read — a project whose snapshot was unset fell silently back to the partner's ordinary rate |
| 2026-09-21 | Phase 11 §7.2 / §8.2 screens | rendered through the HTTP kernel, then `tests/Feature/Financial/ProjectPaymentScreenTest` | PASS — **7 tests / 54 assertions**; the whole financial suite at **65 / 412**, and 243 / 12,122 with Rbac and Project. A test walks the router and asserts every project-payment route carries `module:project_payments` rather than the `payments` umbrella (F-6.1) |

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
| T43 | Seven permission rows in the database are no longer declared in `PermissionRegistry`: `certificates.upload`, `results.delete`, `results.restore`, `student_id_cards.delete`, `student_id_cards.restore`, `student_progress.delete`, `student_progress.restore`. | low | `PermissionSeeder` names them on every run and deliberately does **not** delete them — dropping a row a role still holds would silently narrow that role. Harmless while they exist: no route and no policy consults one. Remove them deliberately, after checking no role grants them, rather than letting a seeder do it. |
| T44 | `certificates.download` is declared but no route uses it: §7.5 gates the PDF on `certificates.print`, and `CertificatePolicy::download()` resolves to `print()`. | low | Left declared so a role that already holds it keeps working, and so the finer distinction is available if an institute ever wants to separate printing from saving a PDF. |
| T45 | Seven Feature directories were not re-run after Phase 23: `Cms`, `Crm`, `Institute`, `Hr`, `Financial`, `Collaborator`, `Project`. | med | Each exceeds the harness's ten-minute cap on its own, and two concurrent runs destroy the shared test database (D157, T6). Phase 23 changed the registries, routes, sidebar and settings — all covered by the thirteen directories that did run — but "not run" is not "passing", and this stays open until D142's suite-performance work in Phase 24 makes a full run possible. |
| T46 | `App\Http\Controllers\Client\TicketController` is orphaned: no route reaches it. | low | `client.tickets.*` is served by Phase 22's shared `Portal\TicketController`. The Phase 5 controller still checks `client_portal.tickets`, which is also still declared and now unused. Remove both deliberately, after checking no role grants the permission — the same care T43 asks for. |
| T47 | `reports.warm-caches` warms one report view per staff user per report. | low | On an installation with many staff and all 33 reports that is a few hundred queries at 06:30. Fine at current scale and bounded by `--user` / `--report`, but Phase 24's performance pass should measure it before it is left on unattended. |
| T48 | **66 of 175 store/update actions validate inline instead of through a Form Request** (golden rule 9, DoD item 3). | med | Found by SEC-14, which the contract expected to carry an allowlist of *three* toggle endpoints and which ships holding 66. Compliance is 62.3 %: 109 actions take a real Form Request, 66 take `Illuminate\Http\Request` and call `$this->validate(...)` inline — `Admin/Finance/{Expense,Income,FinanceCategory,PaymentMethod}Controller`, `Admin/Collaborator/CollaboratorController`, six `Admin/Hr/*` controllers and the rest. **The test is not a rubber stamp**: every exemption is re-derived from the source on each run and fails if an action stops validating at all, so an unvalidated write is still caught. What it no longer catches is the rule living in the wrong place — and two of the 66 put no ceiling on a free-text field. Moving them is mechanical but it is 66 controllers and their tests; it is not Phase 24 work and it should not be done in the same change as anything else. |
| T49 | The two privileged database connections existed in the contract and not in `config/database.php` until Phase 24 added them. | — | **Resolved.** §6.9.3 has always specified `mysql_migration` and `mysql_backup`; neither was configured, so `BackupService::dumpConnection()` and `BackupVerificationService::ddlConnection()` fell back to the runtime user permanently and install step 7 (`migrate --database=mysql_migration`) would have failed outright. See D168. |
| T50 | `SystemHealthService::snapshot()` has no `trends` key, so §8.4's 14-day failed-job and error chart never renders. | low | The screen degrades honestly rather than lying — the view guards with `@if` and simply omits the panel — so this is a missing feature, not a wrong one. Raised by the health slice's own reviewer while confirming a different fix. |
| T51 | `backup:restore` opens its own `backup_restores` row instead of adopting the pending one the screen wrote. | med | The restore screen writes a row carrying `confirmed_at` and `password_confirmed_at` — §2.2 stores those as stamps precisely so the gates can be read back — and then the console starts a second row. The first is stranded at `requested` for ever, detached from the restore that actually ran, so the evidence that the typed phrase and the password confirmation were passed is not attached to anything. Needs a `--restore=<uuid>` option on the command that adopts the pending row. The screen's toast now prints the five options the command really accepts (D170). |
| T52 | `payouts:expire-stale-requests` is named by §10.4 and does not exist. | med | `golive:check` found it: "1 of 20 §10.4 entries not scheduled". The command has never been written — it appears nowhere in `app/Console` — so the weekly Monday 06:00 entry the contract specifies cannot be scheduled. It belongs to the collaborator spine, and its semantics are the careful part: §10.4 says it "never auto-rejects money", so a stale payout request is expired, not refused, and what that means for the wallet has to come from the spine's contract rather than be guessed here. |
| T53 | Four deployment tests the runbooks name do not exist: DEP-17, DEP-18, DEP-19, DEP-20. | med | There is no deployment test directory at all. `docs/{RESTORE,DEPLOY,ROLLBACK}.md` each opened by asserting that one of these enforced its step list — ROLLBACK.md said the per-phase table was "verified, not aspirational" — and none of them had been written. The claims are now stated as contracted-but-unwritten, with the honest consequence spelled out where the reader meets it (D170). The tests themselves remain owed. |
| T54 | **Parallel agent rounds must not share one test database.** | Running six test-running agents at once produced five concurrent PHPUnit processes against `my_office_test`, each doing `migrate:fresh --seed` and dropping the others tables mid-migration - D157 happening live, and caused by the fan-out rather than by any agent: each had been told never to run two test processes at once, and each obeyed. The agents adapted by creating their own schemas, which is why both primary databases survived; twenty-five probe databases were left behind and dropped afterwards. **The rule for a future round: either give each test-running agent its own `DB_DATABASE` in the prompt, or run those slices one at a time.** `phpunit.xml` sets `DB_DATABASE` without `force="true"`, so an environment variable already overrides it - the mechanism exists and only needs to be assigned. |
| T55 | **Every documented `queue:work` command omits the `financial` queue, so no commission is ever generated.** | **high** | `PRODUCTION.md` §5, `docs/phases/phase-24-25.md` §6.9.5 and both worker definitions specify `--queue=high,default`; `SystemHealthService::probeQueue()`'s remediation hint says `--queue=high,default,low`, naming a `low` queue that does not exist. The jobs this codebase actually dispatches are `high` (the `ops:heartbeat` stamp), **`financial`** (`ProcessStudentFeeCommission`, `ProcessProjectPaymentCommission`, `ProcessCommissionReversal`, `GenerateMonthlyFeeCharges`, `RecomputeStudentFeeCaches`), `default` (two) and `exports` (`BuildReportExport`). A worker started from any documented command therefore never drains `financial` or `exports`. **It fails silently in the worst possible direction**: the heartbeat rides on `high`, so `ops:health` keeps reporting the queue **ok** while every commission, every reversal and every monthly fee generation accumulates unprocessed in `jobs` — which is verbatim the failure PRODUCTION.md §5 warns about (*"a fee payment is recorded, the commission job is enqueued, and no ledger entry ever appears"*), reached by following PRODUCTION.md's own command. The working form is `--queue=high,financial,default,exports`: heartbeat first so health stays truthful, money second, general work third, bulk exports last. Found while writing `docs/AAPANEL.md`, which documents the correct command and the discrepancy; the four stale spellings are still to be corrected at source, and the queue probe should assert that a worker is listening on `financial` rather than inferring liveness from a heartbeat on another queue. |
| T59 | **A code deploy does not invalidate the public page cache, so a fix can land completely and change nothing a visitor sees.** | **high** | `CachePublicResponse` stores rendered public pages under a `CacheVersion` stamp that a **publish** bumps — a section saved, a page put live. A `git pull` bumps nothing, because from the cache's point of view nothing was published. Observed on knsoftic.com within an hour of the feature shipping: `/trainers` went out without its settings guard and answered 200 on a site that had never enabled it; the guard was added, deployed, `route:cache` and `view:cache` rebuilt and php-fpm reloaded, and **it still answered 200**. Every instinct said the fix had not deployed. It had — the response was minted before the guard existed and the cache had no reason to think otherwise. `cache:clear` and the next request was a 404. **The symptom argues convincingly for the wrong cause**, which is what makes it worth a number: the operator re-reads correct code, re-runs a correct deploy, and concludes the code is wrong. `docs/AAPANEL.md` §15.3 now carries the step and the story. The proper fix is for the deploy to bump the stamp — a `site:cache-flush` command, or `CacheVersion::bump()` from a deploy hook — so the blunt `cache:clear` (which also drops the settings and module caches) stops being the only lever. |
| T58 | **There is no narrow way to grant the website's own settings, so the Website Manager role cannot switch its own pages on.** | med | The five `website.*_page_enabled` toggles decide whether `/fee-structure`, `/timetable`, `/trainers`, `/student-reviews` and `/request-a-quote` exist at all, and they live in the settings table. The only ability that can write a setting is `settings.edit`, which is all-or-nothing: granting it to a content role to buy five checkboxes would also hand over the SMTP credentials, the security group, the trusted-proxy list and the backup configuration. So the new role holds `settings` READ — it can see that a page is switched off and cannot switch it on, and an Admin does that once per page. **The fix is a narrow ability, not a wider grant**: `settings.edit_website`, scoped to the `website` settings group, in exactly the shape of the existing Super-Admin-only `settings.edit_mail` (§4.3's precedent for a single guarded operation). Until then the limitation is real but small — five one-time toggles, not day-to-day work — which is why the role shipped with it rather than waiting. |
| T57 | **Widget permissions were chosen per widget and never checked against the roles that read them, so a correctly-configured role can sign in to a blank dashboard.** | **Resolved 2026-09-26** | Found while adding the front-desk widgets: of 38 widgets, **not one** was visible to `Receptionist` — a role with 75 permissions. The near-misses are the evidence that this is a mismatch rather than an intention. **`fee_collected_today`, `pending_fees` and `overdue_fees` all require `student_fees.view_reports`, and the front desk holds `student_fees` READ_CREATE** — so the three cards about fee money were hidden from the person who takes the fee money, while `student_fee_payments.view_any`, which that role does hold, gated nothing at all. `new_inquiries` and `inquiry_routing_backlog` miss the same way: they want `contact_inquiries.view_any` where §9.1.2 deliberately grants `view` alone. Five new widgets now cover the front desk, but the **general** gap is unfixed: nothing asserts that every panel-facing role sees at least one widget, so the next role added inherits the same blank page silently. The test to write is a matrix — for each seeded role, `DashboardRegistry::for($user)` must be non-empty — and it belongs beside the existing `DashboardWidgetRegistryTest`. Re-gating the three fee widgets is the separate, more careful question, since widening them touches every other role that sees them. |
| T56 | **`integrity:verify --suite=all` exits 2, so the nightly integrity run and `composer harden` both fail every time.** | **high** | `IntegrityCheckSuite` has nine cases and no `all`; the command's guard rejects an unknown suite before doing any work and returns 2. Confirmed by running it: `Unknown suite [all]. Known suites: constraints, wallet, schema, routes, isolation, uploads, performance, security, backup.` Omitting `--suite` is what runs all nine (`$requested === null` → `runAll()`). The broken form is live in **`composer.json:84`** (harden step 3, so the gate cannot pass), **`routes/console.php:419`** (`dailyAt('02:15')`, so the daily proof of the money never runs), `docs/INSTALL.md:420` and `DemoSeed.php`. **`GoLiveCheck.php:120` asserts the schedule *contains* `--suite=all`**, so the go-live gate currently requires the broken invocation to be scheduled and would fail if the schedule were fixed alone — both must change together. `ROLLBACK.md` already states the correct usage, which is how the discrepancy was noticed. This is D170's pattern once more: a green scheduler is not evidence the suites ran. |

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
