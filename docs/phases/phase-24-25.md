# PHASE 24-25 CONTRACT - Hardening, verification and deployment

Requirement sections **110-116** and **120**. This contract covers two phases that ship together:

- **Phase 24 - hardening.** An executable test and audit plan: the security matrix, the authorization and
  five-panel isolation matrix, the IDOR sweep, the financial integrity suite (all nine tests of §120 plus
  concurrency, reconciliation, rounding, reversal, payout and a property-style wallet-equals-ledger test),
  responsive verification, performance budgets and an accessibility pass.
- **Phase 25 - deployment.** Installation guide, production notes, the backup and restore system, a
  rollback procedure per phase, and a go-live checklist.

Binding like every other phase contract: names, column names, command names, permission strings, test ids
and file paths below are fixed. Conventions live in [`../../CLAUDE.md`](../../CLAUDE.md); the foundation in
[`phase-01.md`](phase-01.md); settings, modules and the dashboard in [`phase-02.md`](phase-02.md); the
money rules in [`../design/finance-commission-spine.md`](../design/finance-commission-spine.md) (cited
below as **spine §x**). Progress is tracked in [`../../DEVELOPMENT_LOG.md`](../../DEVELOPMENT_LOG.md).

---

## Contents

1. [Goal, dependencies, ownership, invariants](#1-goal-dependencies-ownership-invariants)
2. [Schema](#2-schema)
3. [Enums to add](#3-enums-to-add)
4. [PermissionRegistry additions](#4-permissionregistry-additions)
5. [SettingsRegistry additions](#5-settingsregistry-additions)
6. [Services, commands and runbooks](#6-services-commands-and-runbooks)
7. [Routes](#7-routes)
8. [UI screens](#8-ui-screens)
9. [Data isolation](#9-data-isolation)
10. [Events, notifications, jobs, scheduled tasks](#10-events-notifications-jobs-scheduled-tasks)
11. [Acceptance tests](#11-acceptance-tests)
12. [Risks and open questions](#12-risks-and-open-questions)
13. [Requests to other phases](#13-requests-to-other-phases)

---

## 1. Goal, dependencies, ownership, invariants

### 1.1 Goal

After these two phases the business can put the platform in front of real clients, real students and real
money with written proof that it is safe to do so: every route is proven to enforce its permission, every
panel is proven unable to read another tenant's rows, every rupee in a collaborator wallet is proven to be
re-derivable from the ledger after a randomised storm of payments, refunds, clawbacks and payouts, every
screen is proven to work at five widths in both themes, every page is proven to stay inside its query
budget, and the system can be installed, backed up, restored and rolled back from a written runbook by
someone who has never seen the code. Phase 24 produces **evidence and fixes**; Phase 25 produces the
**operational capability** (backups, restore, health, go-live) and the documents that make the install
repeatable.

### 1.2 Dependencies

| Needs | From | Used for |
|---|---|---|
| RBAC, `Gate::before` module gating, `PermissionRegistry`, the five panel route files, `EnsureUserIsActive`, `EnsurePanelAccess`, `login_histories`, `activity_log`, `Money`, `Blameable` | Phase 1 | every security, authorization and isolation test; the two new module slugs |
| `SettingsRegistry`, `SettingsService`, `ModuleService`, `DashboardRegistry`, `DateRange`, `Format`, `SystemHealthWidget`, `maintenance` group | Phase 2 | the `backup` / `ops` setting groups, the health screen, the widgets |
| `PublicCache`, `EnsurePublicSiteAvailable`, `App\Services\Cms\MediaService` + `ImageProfile` (**the one public-image pipeline**, D24), **`App\Support\RichText::sanitize()`** (**the one HTML sanitiser**, over `mews/purifier`, applied on write and on render - D25), `site` / `site.cache` | Phase 3 | public-page cache budgets, upload matrix, maintenance behaviour, and the single sanitiser SEC-04 / SEC-05 assert |
| `SpamGuard`, the `public-contact` / `public-apply` limiters | Phase 4 | the public-form rate-limit tests. **Phase 4 declares no sanitiser and no uploader of its own** (`HtmlSanitizer` and `ImageUploadService` are deleted by resolutions F-2.5 / F-2.4): XSS goes through Phase 3's `RichText`, images through Phase 3's `MediaService` |
| Every panel and screen of phases 5-23 | 5-23 | the screen manifest that drives the responsive, a11y, performance and IDOR sweeps |
| The 15 financial tables, `LedgerWriter`, `PaymentService`, `StudentCommissionService`, `ProjectCommissionService`, `CommissionReversalService`, `PayoutService`, `CollaboratorWalletService`, `CollaboratorStatementService`, `CommissionReconciliationService`, `financial:verify-constraints`, `assertWalletMatchesLedger()` | Phases 10-12 + spine | the whole financial integrity suite; **Phase 24 writes no new money code** |
| `student_fees` + receipts + `fees:verify-plan-integrity` | Phase 18 | the §120 tests 1-5 and 9 driven through real HTTP routes |
| Reports, exports, global search, audit trail | Phase 23 | export streaming budgets, log-viewer authorization |

**Blocked until**: phases 1-23 are ticked in `DEVELOPMENT_LOG.md` §5. Phase 24 may **start** as soon as a
phase ships (each phase's rows in the manifests of §6.1 are added by that phase), but it cannot be
**closed** before Phase 23, because a green matrix over 22 of 23 phases proves nothing about the 23rd.

### 1.3 Ownership - what these phases may and may not touch

| May | May not |
|---|---|
| Create the three tables of §2, the two module slugs of §4, the two setting groups of §5 | Create or alter any table owned by phases 1-23, except by an **index-only** additive migration listed in §2.5 |
| Ship additive, index-only migrations, middleware, commands, tests, seeders, runbooks, deploy scripts | Change a business rule, a money algorithm, a status lifecycle or a permission name to make a test pass |
| Fix a defect **in the file that owns it**, additively, leaving that phase's own acceptance tests green | Delete, skip, `@group ignore`, loosen or rename a failing test from another phase |
| Add `@group` annotations to existing tests so they can be run as named suites | Touch a financial row, a ledger entry or a commission figure by hand, in any environment, ever |
| Register middleware aliases and limiters in `bootstrap/app.php` and the providers Phase 1 owns | Introduce a second code path for anything that already has one (backups, money, uploads, balances) |

### 1.4 Invariants

| # | Invariant | Enforced by |
|---|---|---|
| HD-1 | **A finding is fixed, never documented away.** Every red row in §11 ends as green code or as a written client decision in `DEVELOPMENT_LOG.md` §9 with the risk stated. A skipped test is a failed phase. | Phase review; `php artisan test` exits non-zero on any skip outside the documented `@group optional` list |
| HD-2 | **Phase 24 adds no production behaviour that can change money.** Its only runtime additions are read-only probes, response headers, rate limiters and the backup/restore module. | §1.3; FIN-16 static scan finds no Phase-24 file writing to a financial table |
| HD-3 | **Every proof is re-runnable by one command** and leaves a record: a test id, an `integrity_check_runs` row, or a `backup_runs` row. Nothing is proven by a screenshot alone except the manual responsive and keyboard passes, which are ticked per screen in §8.7 / §8.8. | §6, §11 |
| HD-4 | **Authorization is proven by enumeration, not by sampling.** The route list is the test's data provider; a new route with no manifest entry fails CI. | SEC-20, SEC-21 |
| HD-5 | **Isolation is proven on the response body**, not on the rendered view: every isolation test asserts the forbidden column names and values are absent from the raw HTML/JSON. | ISO-01..ISO-12 |
| HD-6 | **A backup is not a backup until it has been restored.** A `backup_runs` row is `verification_status = restore_ok` only after it was restored into a scratch database and the restored copy passed `financial:verify-constraints` plus a wallet reconciliation. | §6.10, DEP-14 |
| HD-7 | **Nothing is ever deleted to fix a deployment.** Rollback is: module kill-switch, then forward fix, then restore from the pre-deploy backup. `migrate:rollback` is allowed only where §6.12 says so. | §6.12; DEP-20 |
| HD-8 | **Secrets never leave the secret store.** No secret is in `.env.example`, in a backup archive (unless the archive is encrypted), in a log line, in an activity-log value, in a response body, or in a screenshot. | SEC-36..SEC-39, DEP-03, DEP-12 |
| HD-9 | **Production is never the first place a migration runs.** The deploy runbook requires a dry run of the exact release on a restored copy of the production database. | §6.9 step 4; DEP-18 |
| HD-10 | **The proof suites run forever**, not once: `integrity:verify`, `collaborators:reconcile-wallets`, `financial:verify-constraints`, `backup:verify` and `security:audit` are scheduled (§10.4), so a regression introduced in month seven is found that night, not by a client. | §10.4; DEP-24 |

---

## 2. Schema

Three new tables, all InnoDB / utf8mb4, all with `timestamps` and the `Blameable` columns. **None of them
uses soft deletes**, and none of them may be deleted from: they are run history, which is exactly the
"audit, log and run history" category of `DEVELOPMENT_LOG.md` §4 **D19** (the full category rule lives in
`CLAUDE.md` §3; the nine append-only financial tables are **D16**). These phases therefore invent no
soft-delete decision of their own and cite D19 rather than claiming a number.
`backup_runs` and `integrity_check_runs` carry a `BEFORE DELETE` trigger
raising `SIGNAL SQLSTATE '45000'`; `backup_restores` carries one too, because a restore is the single most
consequential act an operator can perform and its record must outlive the operator.

| # | Table | Grain (one row per ...) | Append-only | Soft deletes | Blameable |
|---|---|---|---|---|---|
| 1 | `backup_runs` | backup attempt (database, files or full) | yes (lifecycle columns only) | no | yes |
| 2 | `backup_restores` | restore attempt | yes (lifecycle columns only) | no | yes |
| 3 | `integrity_check_runs` | run of one verification suite | yes (lifecycle columns only) | no | yes |

### 2.1 `backup_runs` (§114)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | |
| `uuid` | char(26) | not null | ULID, **unique** `uq_br_uuid`; the id quoted in notifications and on screen |
| `type` | string(16) | not null, index | cast `BackupType` (`database`, `files`, `full`) |
| `status` | string(16) | not null, default `pending` | cast `BackupStatus`; lifecycle column, may be UPDATEd |
| `trigger` | string(16) | not null | cast `BackupTrigger` (`manual`, `scheduled`, `pre_restore`, `pre_deploy`, `test`) |
| `disk` | string(32) | not null, default `backups` | the filesystem disk the archive was written to |
| `path` | string(512) | nullable | relative path on `disk`; **unique** `uq_br_location` (`disk`,`path`) - the same archive is never recorded twice |
| `filename` | string(255) | nullable | `my_office-database-2026-09-12-023001.zip` |
| `size_bytes` | unsignedBigInteger | nullable | |
| `checksum_sha256` | char(64) | nullable | computed after write; re-checked by `backup:verify` |
| `is_encrypted` | boolean | default false | true when `backup.encrypt_archives` was on |
| `includes_env` | boolean | default false | only ever true when `is_encrypted` is true (see §6.10) |
| `database_name` | string(64) | nullable | `my_office` |
| `table_count` | unsignedInteger | nullable | proof figures captured at dump time |
| `row_count_total` | unsignedBigInteger | nullable | sum over the tables of §6.10.4's proof list |
| `file_count` | unsignedInteger | nullable | for `files` / `full` |
| `started_at` | timestamp | nullable, index | |
| `finished_at` | timestamp | nullable | |
| `duration_seconds` | unsignedInteger | nullable | |
| `error_class` | string(191) | nullable | exception class only |
| `error_message` | text | nullable | scrubbed by `RedactSensitive` before storage |
| `retention_class` | string(16) | not null, default `daily` | `transient`, `daily`, `weekly`, `monthly`, `yearly` - decided at creation by §6.10.3 |
| `retention_until` | date | nullable, index | the prune job never touches a row whose date is in the future |
| `file_pruned_at` | timestamp | nullable | the **file** was removed by policy; the row stays for ever |
| `pruned_by` | FK `users.id` | nullable, nullOnDelete | null when the scheduler pruned it |
| `verification_status` | string(16) | not null, default `unverified` | cast `BackupVerificationStatus` |
| `verified_at` | timestamp | nullable | |
| `verification_notes` | text | nullable | what `backup:verify --deep` proved |
| `offsite_disk` | string(32) | nullable | |
| `offsite_copied_at` | timestamp | nullable | |
| `reason` | string(255) | nullable | **mandatory for `trigger = manual`** (Form Request) |
| `app_version` | string(32) | nullable | from `config('app.version')`, written by the deploy step |
| `php_version` | string(16) | nullable | |
| `notes` | string(500) | nullable | |
| `created_by`, `updated_by` | FK `users.id` | nullable, nullOnDelete | `Blameable`; null for scheduled runs |
| `created_at`, `updated_at` | timestamps | - | no `deleted_at` |

Indexes: `idx_br_type_status_created (type, status, created_at)`, `idx_br_retention (retention_until, file_pruned_at)`,
`idx_br_verification (verification_status, verified_at)`, `idx_br_started (started_at)`.
Trigger: `trg_br_no_delete BEFORE DELETE ... SIGNAL SQLSTATE '45000'`.
Model guard: `updating` throws `ImmutableBackupRecordException` when a dirty attribute is outside
`{status, finished_at, duration_seconds, size_bytes, checksum_sha256, path, filename, table_count,
row_count_total, file_count, error_class, error_message, retention_class, retention_until, file_pruned_at,
pruned_by, verification_status, verified_at, verification_notes, offsite_disk, offsite_copied_at, notes,
updated_by, updated_at}`.

### 2.2 `backup_restores` (§114 restore procedure)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | |
| `uuid` | char(26) | not null | ULID, **unique** |
| `backup_run_id` | FK `backup_runs.id` | not null, **restrictOnDelete**, index | what was restored |
| `pre_restore_backup_run_id` | FK `backup_runs.id` | nullable, restrictOnDelete | the mandatory safety backup taken first (§6.10.5); null only for `target = local` |
| `target` | string(16) | not null | cast `RestoreTarget` (`local`, `staging`, `production`) |
| `status` | string(16) | not null, default `requested` | cast `RestoreStatus` |
| `database_name` | string(64) | not null | the database actually written - never defaulted silently |
| `reason` | string(500) | **not null** | why; no restore without a written reason |
| `requested_by` | FK `users.id` | not null, restrictOnDelete | the actor survives a user soft delete |
| `confirmed_at` | timestamp | nullable | the typed confirmation phrase was accepted |
| `password_confirmed_at` | timestamp | nullable | `password.confirm` middleware stamp |
| `checksum_verified` | boolean | default false | refused to proceed when false and `target = production` |
| `started_at`, `finished_at` | timestamp | nullable | |
| `duration_seconds` | unsignedInteger | nullable | |
| `row_counts_before` | json | nullable | `{table: count}` over §6.10.4's proof list - **table names and integers only** |
| `row_counts_after` | json | nullable | same shape |
| `ledger_rows_before` | unsignedBigInteger | nullable | the financial proof, broken out so it is greppable |
| `ledger_rows_after` | unsignedBigInteger | nullable | |
| `proof` | json | nullable | `{constraints: pass/fail, reconciliation: {checked, drift, failed}, migrations_pending: n}` |
| `error_class` | string(191) | nullable | |
| `error_message` | text | nullable | scrubbed |
| `notes` | string(500) | nullable | |
| `created_by`, `updated_by` | FK `users.id` | nullable, nullOnDelete | |
| `created_at`, `updated_at` | timestamps | - | no `deleted_at` |

Indexes: `idx_brs_backup (backup_run_id)`, `idx_brs_status_created (status, created_at)`,
`idx_brs_target (target, created_at)`. Trigger `trg_brs_no_delete`.
Model guard: `updating` whitelist `{status, confirmed_at, password_confirmed_at, checksum_verified,
started_at, finished_at, duration_seconds, row_counts_after, ledger_rows_after, proof, error_class,
error_message, notes, updated_by, updated_at}`.

### 2.3 `integrity_check_runs` (§110 audit trail, spine §10.4)

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | |
| `uuid` | char(26) | not null | ULID, **unique**; one uuid per `integrity:verify` invocation, shared by the suite rows it spawns |
| `run_uuid` | char(26) | not null, index | groups the suites of one invocation (`--suite=all` writes one row per suite) |
| `suite` | string(32) | not null, index | cast `IntegrityCheckSuite` |
| `status` | string(16) | not null, default `passed` | cast `IntegrityCheckStatus` (`passed`, `warning`, `failed`) |
| `scope` | string(64) | nullable | `all`, `collaborator:12`, `table:student_fees` |
| `checks_total` | unsignedInteger | default 0 | |
| `checks_passed` | unsignedInteger | default 0 | |
| `checks_warned` | unsignedInteger | default 0 | |
| `checks_failed` | unsignedInteger | default 0 | |
| `findings` | json | nullable | bounded list, max 200 entries of `{code, severity, subject, expected, actual}`; `findings_truncated` says when more existed |
| `findings_truncated` | boolean | default false | |
| `started_at`, `finished_at` | timestamp | nullable | |
| `duration_ms` | unsignedInteger | nullable | |
| `triggered_by` | string(16) | not null, default `scheduled` | `manual`, `scheduled`, `deploy`, `test`, `restore` |
| `command` | string(128) | nullable | the exact command line, for reproduction |
| `exit_code` | tinyInteger | nullable | 0 pass, 1 warning, 2 failed |
| `app_version` | string(32) | nullable | |
| `created_by`, `updated_by` | FK `users.id` | nullable, nullOnDelete | |
| `created_at`, `updated_at` | timestamps | - | no `deleted_at` |

Indexes: `idx_icr_suite_created (suite, created_at)`, `idx_icr_status_created (status, created_at)`,
`idx_icr_run (run_uuid)`. Trigger `trg_icr_no_delete`.
**Retention**: `ops:prune-integrity-runs` deletes nothing - it is forbidden by the trigger. Instead rows
older than `ops.integrity_run_retention_days` (default 180) have `findings` set to `null` and
`findings_truncated = true`; rows whose `status = failed` keep their findings for three years.

### 2.4 Relationships

| Edge | Declaration |
|---|---|
| `BackupRun` 1-n `BackupRestore` | `BackupRun::restores(): hasMany(BackupRestore::class, 'backup_run_id')` |
| `BackupRestore` n-1 `BackupRun` | `belongsTo(BackupRun::class, 'backup_run_id')` |
| `BackupRestore` n-1 `BackupRun` (safety copy) | `belongsTo(BackupRun::class, 'pre_restore_backup_run_id')` as `preRestoreBackup()` |
| `BackupRun` 1-n `BackupRun` | none - a pre-restore backup is linked only from the restore row, so the graph stays acyclic |
| `BackupRestore` n-1 `User` | `belongsTo(User::class, 'requested_by')` as `requester()` |
| `BackupRun` / `BackupRestore` / `IntegrityCheckRun` n-1 `User` | `creator()` / `editor()` from `Blameable` |
| `IntegrityCheckRun` | no FK to any business table; `scope` is a **string**, deliberately not a polymorphic edge, so a reconciliation row can reference a collaborator that was later soft-deleted |

No pivot tables, no `belongsToMany`, no polymorphic relations are introduced by these phases.

### 2.5 The index manifest, and the one migration Phase 24 may ship against other phases' tables

**[D-P24-1]** Phase 24 owns exactly one migration that touches tables it does not own:
`add_audit_performance_indexes_table` - **index additions only**. It may `ADD INDEX`, never add, alter or
drop a column, never drop an index it did not create, and its `down()` drops only the indexes it created.
Every index in it is justified by a measured query from §11.8 and is named `idx_p24_*` so its origin is
greppable. Anything beyond an index is a request to the owning phase (§13).

The authority for "which indexes must exist" is `tests/Support/index-manifest.php`, appended to by every
phase as it ships and asserted by **PRF-04**:

```php
// tests/Support/index-manifest.php
return [
    // table => [ [columns], ... ]   (order matters; a prefix index counts as present)
    'users'                                     => [['status'], ['branch_id'], ['email'], ['deleted_at']],
    'login_histories'                           => [['user_id', 'created_at'], ['status'], ['session_id']],
    'activity_log'                              => [['module'], ['subject_type', 'subject_id'], ['causer_type', 'causer_id'], ['created_at']],
    'student_fees'                              => [['student_id'], ['status'], ['due_date', 'status'], ['branch_id', 'status'], ['fee_number']],
    'student_fee_payments'                      => [['student_fee_id'], ['paid_on'], ['commission_state', 'id'], ['collaborator_id'], ['receipt_no']],
    'project_payments'                          => [['project_id'], ['client_id'], ['paid_on'], ['commission_state', 'id']],
    'collaborator_commission_ledger_entries'    => [['collaborator_id', 'transaction_date'], ['status', 'entry_type'], ['source_type', 'source_id'], ['purpose'], ['reverses_entry_id']],
    'collaborator_payout_allocations'           => [['collaborator_id'], ['ledger_entry_id'], ['payout_id']],
    // ... every phase appends its own rows; PRF-04 fails on the first missing index
];
```

Rules the manifest enforces, each its own assertion inside PRF-04:

| Rule | Assertion |
|---|---|
| Every foreign key column has an index | `information_schema.KEY_COLUMN_USAGE` joined to `STATISTICS`; a FK without a usable index fails |
| Every `status` column used in a filter is indexed | from the manifest |
| Every column a controller sorts by is indexed, or the sort allowlist marks it `unindexed` with a row cap | manifest + the sort allowlist of SEC-12 |
| Every `(branch_id, ...)` institute table is indexed on `branch_id` (D11) | manifest |
| Every `deleted_at` on a table queried with `withTrashed()` is indexed | manifest |
| No table has more than 12 indexes without a written note | warning, not failure |

---
## 3. Enums to add

All in `app/Enums/`, string-backed, each implementing `label(): string` and `color(): string` and exposing
`static options(): array`, exactly as Phase 1 §2 requires.

| Enum | Cases (case => value) | Extra members |
|---|---|---|
| `BackupType` | `Database => database`, `Files => files`, `Full => full` | `includesDatabase(): bool`, `includesFiles(): bool`, `artisanFlags(): array` (`--only-db` / `--only-files` / none) |
| `BackupStatus` | `Pending => pending`, `Running => running`, `Completed => completed`, `Failed => failed`, `Pruned => pruned` | `isTerminal(): bool`, `isUsable(): bool` (true only for `Completed`) |
| `BackupTrigger` | `Manual => manual`, `Scheduled => scheduled`, `PreRestore => pre_restore`, `PreDeploy => pre_deploy`, `Test => test` | `requiresReason(): bool` (true for `Manual`), `isProtectedFromPruning(): bool` (true for `PreRestore`, `PreDeploy`) |
| `BackupVerificationStatus` | `Unverified => unverified`, `ChecksumOk => checksum_ok`, `RestoreOk => restore_ok`, `Failed => failed` | `satisfiesGoLive(): bool` (true only for `RestoreOk`) |
| `RestoreTarget` | `Local => local`, `Staging => staging`, `Production => production` | `requiresPreBackup(): bool` (false only for `Local`), `requiresConfirmationPhrase(): bool` (true for `Staging`, `Production`) |
| `RestoreStatus` | `Requested => requested`, `Running => running`, `Completed => completed`, `Failed => failed`, `Aborted => aborted` | `isTerminal(): bool` |
| `IntegrityCheckSuite` | `Constraints => constraints`, `Wallet => wallet`, `Schema => schema`, `Routes => routes`, `Isolation => isolation`, `Uploads => uploads`, `Performance => performance`, `Security => security`, `Backup => backup` | `command(): string` (the console command that implements it), `isFinancial(): bool` (`Constraints`, `Wallet`), `defaultSchedule(): ?string` |
| `IntegrityCheckStatus` | `Passed => passed`, `Warning => warning`, `Failed => failed` | `exitCode(): int` (0/1/2), `blocksGoLive(): bool` (true for `Warning` and `Failed` on a financial suite, true for `Failed` on any) |

**Retention classes** are not an enum: `retention_class` is a string validated against the fixed list
`transient / daily / weekly / monthly / yearly` declared as constants on `BackupRetentionService`, because
the set is a policy parameter read from settings, not a domain status with a colour.

---

## 4. PermissionRegistry additions

Additive to Phase 1 §4. `PermissionRegistry` stays the only place a permission name exists (D4); the
seeders stay idempotent and never delete.

### 4.1 New module slugs (both `is_core = false`, both `ModuleGroup::System`)

| Slug | Name | Icon | `is_core` | Sort | Abilities (preset) | Resulting permissions |
|---|---|---|---|---|---|---|
| `system_health` | System Health | `activity` | false | 91 | `READ` + `LOGS` + `[export]` | `system_health.view_any`, `.view`, `.view_logs`, `.export` |
| `integrity_checks` | Integrity Checks | `shield-check` | false | 92 | `READ` + `[create, export]` + `LOGS` | `integrity_checks.view_any`, `.view`, `.create`, `.export`, `.view_logs` |

`modules.depends_on` for both: `[]`. Neither may be made core: the business must be able to hide an
operations screen without being told it cannot.

**Module gating has no effect on the console.** Disabling `system_health` or `integrity_checks` 403s their
routes for everyone including Super Admin (Phase 1 §6), while `integrity:verify`,
`collaborators:reconcile-wallets`, `financial:verify-constraints`, `backup:run` and the scheduler keep
running and keep writing rows - exactly the rule the spine states for `collaborator_commissions`
(spine §6.6 row 22). Proof that money is intact does not stop because a screen was hidden. Asserted by
SEC-23.

### 4.2 Abilities added to Phase 1's existing `backups` slug

`backups` is a **Phase 1 System module with `is_core = true`** and already has its permissions seeded.
These phases only add the abilities that were not in its Phase 1 preset:

| Permission | Meaning | Notes |
|---|---|---|
| `backups.view_any` | see the backup register | already present via `READ` |
| `backups.view` | see one backup's detail and its verification history | already present via `READ` |
| `backups.create` | take a manual backup | throttled (§7), reason mandatory |
| `backups.download` | stream an archive | signed URL, 5-minute expiry, logged |
| `backups.delete` | prune an archive **file** early | never deletes the `backup_runs` row; refuses the newest usable database backup |
| `backups.restore` | perform a restore | **Super Admin only by default**; additionally gated by `password.confirm`, a typed confirmation phrase and a mandatory reason |
| `backups.view_logs` | read the raw dump / prune log output | may contain table names, never row data |

`Ability::Restore` already exists in Phase 1's enum (introduced there for soft-delete restore). Its use here
is a second meaning of the same ability word, scoped by its module: `backups.restore` can only ever mean
"restore from a backup" because the `backups` module has no soft-deletable model. Stated explicitly so no
one adds a `backups.restore_backup` synonym.

### 4.3 Role grants (delta to Phase 1 §5, applied by the idempotent `RoleSeeder`)

| Role | Grant | Why |
|---|---|---|
| Super Admin | everything, including `backups.*` | Phase 1 §5 and `Gate::before` |
| Admin | `system_health.*`, `integrity_checks.view_any` / `.view` / `.export` - **no `backups.*`** | Phase 1 §5 excludes `backups.*` from Admin; that exclusion stands |
| Accountant | `integrity_checks.view_any`, `.view`, `.export`, scoped to the financial suites by policy (§9) | the wallet and constraint proof is an accounting artefact - it is the evidence behind §120 |
| Every other role | nothing from these phases | an operations screen is not a business screen |

No portal permission (`collaborator_portal.*`, `student_portal.*`, `teacher_portal.*`, `client_portal.*`) is
added: no panel user ever sees a backup, a health probe or an integrity run. `panel:admin` denies them
before a permission is even consulted.

---

## 5. SettingsRegistry additions

Additive to Phase 2 §2, same field-definition shape (`label`, `type`, `rules`, `default`, `options`, `help`,
`encrypted`, `public`, `readonly`, `span`, `sort`). **No key here is `public`** - none of it is readable by
the public website.

### 5.1 New group `backup` (icon `archive`, permission `settings.edit`)

| Key | Type | Default | Rules / notes |
|---|---|---|---|
| `enabled` | boolean | `true` | master switch for the scheduled jobs; a manual backup still works when off |
| `disk` | select | `backups` | options from the configured filesystem disks **excluding `public`**; `required` |
| `database_schedule` | select | `daily` | `off` / `twice_daily` / `daily` / `weekly` |
| `database_time` | time | `02:30` | `required_unless:database_schedule,off` |
| `files_schedule` | select | `weekly` | `off` / `daily` / `weekly` / `monthly` |
| `files_time` | time | `03:00` | |
| `include_paths` | json | `["storage/app/public","storage/app/private"]` | repo-relative paths, validated to exist and to sit inside the base path (no `..`) |
| `exclude_paths` | json | `["storage/framework","storage/logs","storage/app/backups","node_modules","vendor",".git"]` | never back up the backups |
| `excluded_tables` | json | `["cache","cache_locks","sessions","job_batches","telescope_entries"]` | `jobs` and `failed_jobs` are deliberately **included**: a restore must not lose queued commission work |
| `encrypt_archives` | boolean | `false` | when true, `archive_password` is required and `.env` may be included |
| `archive_password` | password | `null` | **encrypted**; `required_if:encrypt_archives,true`, `min:16`; never echoed, never logged, never in a notification |
| `include_env` | boolean | `false` | `prohibited_unless:encrypt_archives,true` - an unencrypted archive may never carry `.env` (HD-8) |
| `retention_keep_all_days` | number | `7` | `integer|min:1|max:90` |
| `retention_daily_days` | number | `30` | `integer|min:7` |
| `retention_weekly_weeks` | number | `12` | `integer|min:2` |
| `retention_monthly_months` | number | `12` | `integer|min:1` |
| `retention_yearly_years` | number | `3` | `integer|min:0` |
| `retention_min_copies` | number | `3` | `integer|min:2` - the prune job never leaves fewer than this many usable database archives, whatever the dates say |
| `max_storage_gb` | number | `20` | `numeric|min:1`; on breach the job **fails loudly** instead of pruning past policy |
| `offsite_disk` | select | `null` | nullable; the second disk (`s3`, `sftp`, a mapped drive) that `CopyBackupOffsite` writes to |
| `offsite_required_for_go_live` | boolean | `true` | `readonly` in the UI; flipping it is a written client decision |
| `notify_emails` | text | `null` | comma-separated, each `email`; empty means "notify in-app only" |
| `notify_on_success` | boolean | `false` | |
| `notify_on_failure` | boolean | `true` | `readonly` - failure notification cannot be switched off |
| `verify_checksum_daily` | boolean | `true` | |
| `verify_restore_weekly` | boolean | `true` | the HD-6 proof; needs `restore_scratch_database` |
| `restore_scratch_database` | text | `my_office_restore_test` | `regex:/^[A-Za-z0-9_]{1,64}$/`; a custom rule asserts it does **not** equal `DB_DATABASE` |
| `restore_confirmation_phrase` | text | `RESTORE {database} {date}` | the template the operator must type; `{database}` and `{date}` are substituted and compared case-sensitively |
| `mysqldump_path` | text | `C:/xampp/mysql/bin/mysqldump.exe` | `required`; validated to exist and be executable; always quoted by the service |
| `mysql_path` | text | `C:/xampp/mysql/bin/mysql.exe` | used by the restore path |

### 5.2 New group `ops` (icon `gauge`, permission `settings.edit`)

| Key | Type | Default | Rules / notes |
|---|---|---|---|
| `health_check_token` | password | generated | **encrypted**, 48 random chars, `min:32`; "regenerate" writes a new one and logs the act |
| `health_endpoint_enabled` | boolean | `true` | when false `/health` returns **404**, not 403 |
| `queue_heartbeat_max_minutes` | number | `10` | `integer|min:2` |
| `scheduler_heartbeat_max_minutes` | number | `5` | `integer|min:2` |
| `failed_jobs_alert_threshold` | number | `10` | `integer|min:1` |
| `slow_query_ms` | number | `250` | slower queries are logged once per request with the route name |
| `query_budget_enforced` | boolean | `false` | `readonly` in production; when true a budget breach **throws** in local/testing instead of logging |
| `log_retention_days` | number | `14` | mirrored into the `daily` channel by `ConfigureFromSettings` |
| `integrity_run_retention_days` | number | `180` | §2.3 |
| `error_monitoring_enabled` | boolean | `false` | tier 2 (§6.9.7) |
| `error_monitoring_dsn` | password | `null` | **encrypted**, `nullable|url` |
| `error_digest_enabled` | boolean | `true` | the tier-1 daily digest |
| `error_digest_time` | time | `07:00` | |
| `uptime_ping_url` | url | `null` | pinged after a successful `schedule:run`; nullable |
| `maintenance_secret` | password | generated | **encrypted**; the `php artisan down --secret=` bypass token, so an operator can verify a deploy from behind the maintenance page |
| `app_version` | text | `null` | `readonly`; stamped by the deploy script, shown on the health screen, copied onto `backup_runs.app_version` |

### 5.3 Keys added to Phase 2's existing `security` group

| Key | Type | Default | Rules / notes |
|---|---|---|---|
| `force_https` | boolean | `true` | applied by `ForceHttps` + `URL::forceScheme`; ignored when `APP_ENV=local` |
| `hsts_enabled` | boolean | `false` | `readonly` until HTTPS is verified; the go-live checklist flips it (§6.13) |
| `hsts_max_age` | number | `31536000` | `integer|min:300` |
| `hsts_include_subdomains` | boolean | `false` | |
| `csp_enabled` | boolean | `true` | |
| `csp_report_only` | boolean | `true` | report-only by default; flipped to enforcing by the go-live checklist once the report log is clean |
| `csp_report_uri` | url | `null` | nullable; when set, violations POST here (rate-limited, body capped at 8 KB) |
| `frame_ancestors` | select | `none` | `none` / `self` - the admin is never embeddable |
| `login_throttle_per_minute` | number | `5` | `integer|min:1|max:30`; feeds the `login` limiter (Phase 1 keeps Breeze's email+IP key) |
| `global_write_throttle_per_minute` | number | `120` | `integer|min:30`; the authenticated-write limiter |
| `export_throttle_per_minute` | number | `10` | `integer|min:1` |
| `print_throttle_per_minute` | number | `20` | `integer|min:1` |
| `session_absolute_lifetime_hours` | number | `24` | `integer|min:1`; enforced by `EnforceSessionLifetime` regardless of activity |
| `session_single_device` | boolean | `false` | when true, a new login calls `Auth::logoutOtherDevices` |
| `password_history_count` | number | `3` | `integer|min:0|max:10`; a new password may not equal the last N hashes (needs `password_histories`, §13) |
| `upload_blocked_extensions` | json | `["php","phtml","phar","php3","php4","php5","php7","php8","pht","shtml","cgi","pl","asp","aspx","jsp","jspx","exe","com","bat","cmd","sh","msi","dll","so","svg","html","htm","xhtml","js","mjs","htaccess","ini","env"]` | checked **in addition to** the per-endpoint MIME allowlist, against the full filename and every dot-segment of it |
| `upload_require_mime_match` | boolean | `true` | `readonly true`; the real `finfo` MIME must match the endpoint's allowlist (Phase 3 INV-11) |
| `trusted_proxies` | text | `null` | comma-separated CIDRs or `*`; `null` means none |

Phase 2 already owns `security.session_lifetime`, `security.login_max_attempts`, `security.lockout_minutes`,
`security.allowed_file_types`, `security.max_upload_mb`, `security.password_min_length`,
`security.force_password_change_days` and `security.two_factor_enabled`. **None of them is redefined here.**
`session_lifetime` stays the single authority for idle timeout (`session_absolute_lifetime_hours` is a
second, independent ceiling, not a duplicate), and `login_max_attempts` stays the authority for the lockout
count while `login_throttle_per_minute` governs the per-minute rate - the two are reconciled in
`RateLimitServiceProvider` and asserted by SEC-26 so no screen can show contradictory numbers.

---
## 6. Services, commands and runbooks

Phase 24's deliverables are the manifests (§6.1), the hardening runtime (§6.3, §6.4), the audit commands
(§6.6) and the test suites (§11). Phase 25's deliverables are the backup/restore/health services (§6.2) and
the five runbooks (§6.8-§6.13). Nothing in this section duplicates an existing service: where a capability
already exists - balances, reconciliation, constraint verification, settings writes, module toggles, uploads
- these phases **call** it.

### 6.1 The four manifests - how a sweep can cover 100% of a 25-phase system

A hand-written list of screens goes stale in a week. These four PHP files are the data providers for every
sweep, each phase appends its own rows as it ships, and a drift check fails CI the moment the code and the
manifest disagree.

| File | Shape of one row | Drift check |
|---|---|---|
| `tests/Support/screen-manifest.php` | `['route', 'panel', 'kind' => index/show/form/board/calendar/wizard/print/public, 'params' => fn(Fixture): array, 'permissions' => [...], 'module' => slug, 'owner_phase' => n, 'query_budget' => n, 'responsive' => bool, 'a11y' => bool, 'idor' => ['owner' => 'client_id'\|null]]` | `audit:manifest --check` lists every GET route with no row and every row whose route no longer exists; **exit 1 on either** |
| `tests/Support/route-guard-manifest.php` | `['route', 'methods', 'middleware' => [...], 'permission' => 'module.ability'\|null, 'panel' => admin/…/public, 'state_changing' => bool, 'rationale' => 'why this route has no can: middleware' (required when permission is null)] ` | same command; a public route must carry an explicit `rationale` string, so "I forgot the permission" can never look like "this route is deliberately public" |
| `tests/Support/index-manifest.php` | §2.5 | PRF-04 |
| `tests/Support/upload-manifest.php` | `['route', 'field', 'disk', 'allowed_mimes' => [...], 'max_mb', 'permission', 'owner_phase', 'public_reachable' => bool]` | `audit:manifest --check` compares it against every Form Request rule containing `file`/`image`/`mimes`; a new upload endpoint with no row fails. **A row whose `disk` is `public` while the field is a private artefact fails too** (D21) |

```
php artisan audit:manifest --check          # CI gate: every route, upload and index is accounted for
php artisan audit:manifest --write          # scaffolds the missing rows with TODO markers for the owning phase
php artisan audit:manifest --coverage       # prints per-phase coverage: screens, routes, uploads, indexes
```

`audit:manifest --check` is a **required step of every phase's definition of done from Phase 24 onward**, and
is run by `golive:check` (§6.13).

**Two `upload-manifest.php` rows the audit forced into the open** (resolutions F-12.5, D21 - no money
artefact on the public disk). Both are phase-13 fields and both are **private**:

| route | field | disk | permission | `public_reachable` |
|---|---|---|---|---|
| `admin.expenses.receipt` (stream) / the expense create-update form | `expenses.receipt_path` | **private `local`**, under `expenses/` | `expenses.view` + the `ProjectManagerScope` ownership predicate; the write needs `expenses.create` / `.edit` | **false** |
| `admin.invoices.pdf` (stream) | `invoices.pdf_path` | **private `local`**, under `invoices/` | `invoices.view` + the invoice's own ownership predicate | **false** |

`invoices.pdf_path` is a **generated** artefact rather than an uploaded one, and it is registered here anyway
so that the disk-and-permission sweep reaches every money artefact, not only the ones a user posts. A vendor
invoice or a bank receipt at a guessable `/storage/expenses/...` URL is a §111 defect, so SEC-15 and SEC-16
both cover these two rows, and a future `public` disk on either is a CI failure rather than a review comment.

### 6.2 Phase 25 runtime services

| Class | Public API |
|---|---|
| `App\Services\Ops\BackupService` | `run(BackupType $type, BackupTrigger $trigger, ?string $reason = null, ?User $actor = null): BackupRun`<br>`isRunning(): bool`<br>`latestUsable(BackupType $type): ?BackupRun` |
| `App\Services\Ops\BackupVerificationService` | `verifyChecksum(BackupRun $run): BackupRun`<br>`verifyByRestore(BackupRun $run): BackupRun` (the HD-6 deep proof)<br>`proofFigures(string $database): array` |
| `App\Services\Ops\BackupRetentionService` | `plan(): RetentionPlan` (what *would* be pruned, no side effect)<br>`prune(?User $actor = null): RetentionResult`<br>`classify(BackupRun $run): string` |
| `App\Services\Ops\BackupRestoreService` | `request(BackupRun $run, RestoreRequestData $data, User $actor): BackupRestore`<br>`execute(BackupRestore $restore): BackupRestore`<br>`abort(BackupRestore $restore, string $reason): BackupRestore` |
| `App\Services\Ops\SystemHealthService` | `snapshot(bool $deep = false): HealthReport`<br>`probe(string $key): ProbeResult`<br>`probes(): array` |
| `App\Services\Ops\IntegrityCheckService` | `run(IntegrityCheckSuite $suite, ?string $scope = null, string $triggeredBy = 'manual'): IntegrityCheckRun`<br>`runAll(string $triggeredBy = 'scheduled'): Collection`<br>`latest(IntegrityCheckSuite $suite): ?IntegrityCheckRun` |
| `App\Services\Ops\GoLiveChecklistService` | `evaluate(): ChecklistReport` (one row per §6.13 item: key, group, status, evidence, command)<br>`blockers(): Collection` |
| `App\Support\Ops\HealthReport` / `ProbeResult` / `RetentionPlan` / `RestoreRequestData` / `ChecklistReport` | readonly DTOs; `toArray()` is what the JSON endpoint and the Blade view both render, so screen and API can never disagree |

**`BackupService::run()` guarantees**, in this order:

1. Refuses when `isRunning()` (a cache lock `ops.backup.running`, TTL 2 hours) - two concurrent dumps of the
   same database are never started.
2. Writes the `backup_runs` row with `status = running` and `started_at` **before** shelling out, so a process
   killed mid-dump leaves evidence rather than silence.
3. Resolves `mysqldump` from `backup.mysqldump_path`, **quotes every path** (the project path contains a
   space - T2), and runs with
   `--single-transaction --quick --routines --triggers --events --hex-blob --default-character-set=utf8mb4`
   plus one `--ignore-table=` per `backup.excluded_tables` entry. `--single-transaction` is mandatory: a
   dump that locks tables would block fee collection.
4. Never puts the database password on the command line: it is passed through a temporary
   `--defaults-extra-file` written with 0600 permissions and deleted in a `finally` block.
5. Computes `checksum_sha256` from the written archive, fills `size_bytes`, `table_count`,
   `row_count_total` (over §6.10.4's proof list), `retention_class` and `retention_until`.
6. Excludes `.env` unless `backup.encrypt_archives && backup.include_env` (HD-8); `includes_env` records what
   was actually done.
7. Dispatches `CopyBackupOffsite` (`afterCommit`) when `backup.offsite_disk` is set, and `VerifyBackupJob`
   when `backup.verify_checksum_daily` is on.
8. On failure: `status = failed`, `error_class`, a scrubbed `error_message`, `BackupFailed` event, and a
   notification that **cannot be switched off** (`backup.notify_on_failure` is readonly true).
9. Writes one activity-log row (module `backups`) with the type, trigger, reason and byte size. A manual run
   without a reason never reaches the service - the Form Request rejects it.
10. **Never deletes anything.** Pruning is `BackupRetentionService`'s job and only ever removes files.

**`BackupRestoreService::execute()` guarantees**:

1. Refuses unless the `BackupRestore` row has `password_confirmed_at`, `confirmed_at` and a non-empty
   `reason`, and unless the actor holds `backups.restore`.
2. Refuses when `backup_runs.status !== Completed` or `checksum_verified = false` for
   `target = staging|production`.
3. Takes the **mandatory pre-restore backup** itself (`BackupTrigger::PreRestore`) and stores its id on the
   row; a failed pre-restore backup aborts the restore. `target = local` may skip it (`requiresPreBackup()`).
4. Captures `row_counts_before` and `ledger_rows_before` before touching anything.
5. Puts the application down (`php artisan down --secret=<ops.maintenance_secret> --render=errors::503`)
   for `staging|production`, and brings it back up in a `finally` block even on failure.
6. Restores with the quoted `mysql` client into `database_name`, then runs `migrate --force`,
   `optimize:clear`, `permission:cache-reset`.
7. Captures `row_counts_after` / `ledger_rows_after`, then runs `financial:verify-constraints` and
   `collaborators:reconcile-wallets` on the restored database and stores both results in `proof`. A
   structural reconciliation failure sets `status = failed` and **says so loudly**; it does not repair
   anything (spine §6.5.4).
8. Never writes to `DB_DATABASE` when `target = local`: the scratch database name is validated not to equal
   it, so a rehearsal cannot eat production.
9. Writes one activity row with old/new row counts and the reason, and notifies every Super Admin.

**`IntegrityCheckService::run()` guarantees**: one `integrity_check_runs` row per suite per invocation, a
bounded `findings` array, an `exit_code` mirroring `IntegrityCheckStatus`, no repair of anything, and - for
the financial suites - delegation to the phase that owns the logic (`financial:verify-constraints`,
`CommissionReconciliationService`), never a second implementation (spine INV-26).

### 6.3 The hardening runtime (Phase 24)

| Class | Responsibility |
|---|---|
| `App\Http\Middleware\SecurityHeaders` (alias `headers`, **global**) | Sets `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options` from `security.frame_ancestors`, `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()`, `Cross-Origin-Opener-Policy: same-origin`, `X-Permitted-Cross-Domain-Policies: none`, `Strict-Transport-Security` when `hsts_enabled` **and** the request is HTTPS, and `Content-Security-Policy` / `Content-Security-Policy-Report-Only` built by `CspBuilder`. Removes `X-Powered-By` and `Server` detail where PHP allows. |
| `App\Support\Ops\CspBuilder` | `default-src 'self'`; `script-src 'self' 'nonce-<per-request>' 'unsafe-eval'`; `style-src 'self' 'unsafe-inline'`; `img-src 'self' data: blob:`; `font-src 'self' data:`; `connect-src 'self'`; `frame-ancestors 'none'`; `form-action 'self'`; `base-uri 'self'`; `object-src 'none'`. `'unsafe-eval'` is a **documented, deliberate** concession: Alpine 3 evaluates `x-` expressions with `new Function` (§12 Q4 offers the CSP build as the alternative). Every inline `<script>` in the Blade layouts carries `nonce="{{ csp_nonce() }}"`; a scan (SEC-08) fails on an inline script without one. |
| `App\Http\Middleware\ForceHttps` (global) | When `security.force_https` and not local: `URL::forceScheme('https')`, 301 any plain-HTTP GET, 403 any plain-HTTP write. |
| `App\Http\Middleware\NoStoreForAuthenticated` (global, after the response) | `Cache-Control: no-store, no-cache, must-revalidate, private` + `Pragma: no-cache` on every authenticated HTML response, and `X-Robots-Tag: noindex, nofollow` on every panel route. Stops a logged-out session's data returning on the browser back button, and stops a panel page being indexed. |
| `App\Http\Middleware\EnforceSessionLifetime` (in the `auth` stack) | Absolute lifetime from `security.session_absolute_lifetime_hours` measured from `login_histories.logged_in_at`; expiry logs out, records a `logout` login-history row with reason `absolute_timeout`, and redirects with a toast. |
| `App\Providers\RateLimitServiceProvider` | The named limiters of §6.3.1. Registered in `bootstrap/app.php` alongside Phase 1's aliases. |
| `App\Logging\RedactSensitive` (log tap on every channel) | Replaces the value of any context key matching `/pass|secret|token|key|authorization|cookie|account_details|archive_password|dsn|cvv|cnic/i` with `[redacted]`, truncates any value over 2 KB, and strips anything that looks like a PAN or a full CNIC from message bodies. |
| `App\Exceptions\Handler` additions | Renders the styled 403/404/419/429/500/503 Blade pages; never leaks a stack trace when `APP_DEBUG=false`; logs `LazyLoadingViolationException` with route + relation; maps `DirectLedgerWriteException`, `ImmutableLedgerAttributeException`, `ImmutableBackupRecordException` to 500 + a loud log line (they are programmer errors, never user errors). |

#### 6.3.1 Named rate limiters (the complete list)

| Limiter | Applied to | Limit | Key |
|---|---|---|---|
| `login` | `POST /login` | `security.login_throttle_per_minute` per minute, then `security.login_max_attempts` / `lockout_minutes` lockout (Breeze) | email + IP |
| `password-reset` | reset link request + reset submit | 3 / minute, 10 / hour | email + IP |
| `verification` | resend verification | 3 / minute (Laravel default `6,1` tightened) | user id |
| `password-confirm` | `password.confirm` screen | 5 / minute | user id |
| `public-contact` | Phase 4 contact form | 5 / min / IP, `website.contact_rate_per_hour` / hour / IP, 3 / hour / email | IP + email |
| `public-apply` | Phase 4 job application, Phase 15 online admission | 3 / hour / IP, 2 / day / email | IP + email |
| `public-verify` | certificate verification (Phase 21) | 30 / minute / IP | IP |
| `mail-test` | Phase 2 test email | 3 / minute | user id |
| `export` | every export route | `security.export_throttle_per_minute` | user id |
| `print` | every print route | `security.print_throttle_per_minute` | user id |
| `payout-request` | collaborator payout request | 3 / hour | collaborator id |
| `backup-run` | manual backup | 2 / hour | user id |
| `backup-restore` | restore submit | 1 / hour | user id |
| `integrity-run` | manual integrity check | 3 / minute | user id |
| `health` | `/health` | 30 / minute | IP |
| `csp-report` | CSP report endpoint | 60 / minute | IP |
| `global-writes` | every authenticated non-GET | `security.global_write_throttle_per_minute` | user id |

Every limiter returns a **styled** 429 (site layout for public routes, panel layout for authenticated ones)
naming the wait in minutes, and never echoes the key.

### 6.4 Performance runtime (Phase 24)

| Item | Decision |
|---|---|
| Eloquent strict mode | `Model::shouldBeStrict(! app()->isProduction())` in `AppServiceProvider::boot()` - this turns on `preventLazyLoading`, `preventSilentlyDiscardingAttributes` and `preventAccessingMissingAttributes`. **This is the N+1 audit**: every lazy load in local, CI and staging throws, so the whole test suite becomes an eager-loading test. In production it is off (a missed `with()` must not 500 a paying client) but each violation is still **logged** with route and relation by a `LazyLoadingViolationException` handler, and `ops:digest` reports them. |
| `App\Support\Ops\QueryBudget` | `for(string $route): int` reads `screen-manifest.php`. `measure(Closure): QueryProfile` wraps `DB::listen` and returns `{count, duration_ms, slowest, duplicates}`. `assert(string $route, QueryProfile $p)` fails with a readable diff listing the duplicate SQL. Used by PRF-01..PRF-03 and by `perf:budget`. |
| `App\Http\Middleware\QueryBudgetGuard` (local/testing only) | Adds `X-Query-Count`, `X-Query-Time` headers and, when `ops.query_budget_enforced`, throws on a breach. Never registered in production. |
| Slow query log | `DB::whenQueryingForLongerThan(ops.slow_query_ms, ...)` logs once per request with the route, the SQL (bindings redacted) and the duration. |
| Pagination | **Every** index query paginates. `PRF-05` scans controllers: `::all()`, `->get()` and `->cursor()` on a business model inside an `index`/`board`/`calendar`/`export` action fail unless the model is in `tests/Support/small-reference-tables.php` (roles, modules, settings, permissions, departments, designations, course categories, classrooms, leave types, salary components, payment methods, finance categories, service categories, technologies, blog categories/tags, faq categories) **and** the query carries an explicit `->limit()`. Exports stream (`LazyCollection` + `chunkById`), never `->get()`. |
| Asset build | `npm ci && npm run build`; Vite manifest hashed; the CSS and JS budget of PRF-11; no source maps in production (`build.sourcemap: false`); `@vite` in the layouts only (never a CDN - there is none in this stack). |
| Tailwind purge safety | **Runtime-built class names are the one real purge risk**: every enum's `color()` returns a token (`emerald`, `amber`, `rose`, `slate`) that a component turns into `bg-emerald-100 text-emerald-800`. `x-ui.badge` and `x-ui.stat-card` therefore resolve the token through a **PHP `match` returning full class strings**, and `tailwind.config.js` `safelist` additionally carries the full cross-product of the tokens actually returned by any enum. PRF-12 asserts every distinct value returned by every `Enum::color()` in `app/Enums` is present in the built CSS. |

#### 6.4.1 Caching strategy (the complete table)

| What | Key | Invalidation | Driver | Rule |
|---|---|---|---|---|
| Permissions | spatie's own | `permission:cache-reset` on every role/permission write | database cache | Phase 1 |
| Module enabled map | `modules.enabled.map` | `Module::saved` | database | Phase 1 |
| Settings payload | `settings.all` (one payload) | `Setting::saved/deleted` | database | Phase 2 |
| Sidebar tree | `sidebar.v{n}.user.{user_id}.{permission_hash}` | `n` bumped on module/permission change; hash changes with the user's roles | database, 1 h | **must contain the user id** - a shared sidebar cache would leak the existence of modules a user cannot see |
| Dashboard widget JSON | `dash.{widget}.{user_id}.{range_hash}` | 60 s TTL + bumped on the widget's own events | database, 60 s | per user, always |
| Public CMS pages | `cms:v{version}:{sha1(...)}` | `PublicCache::bump()` | database | Phase 3 §6.7 - `ref` is part of the key |
| Public statistics | `stats.{provider}` | 5 min TTL | database | Phase 3 §6.11 |
| Sitemap | `sitemap.xml` file | regenerated daily + on publish | file | Phase 3 |
| Course landing pages | part of the CMS version stamp | as above | database | Phase 14 |
| Global search suggestions | `search.suggest.{user_id}.{sha1(term)}` | 60 s | database | per user; a shared key would cross a tenancy boundary |
| **Money and balances** | **never cached** | - | - | `CollaboratorWalletService` reads the wallet row (itself a proven cache) and `derive()` hits the ledger. **No commission, balance, statement, payout or fee figure is ever put in the cache store** (spine INV-13, INV-26). PRF-13 asserts it |
| Config / routes / views / events | `bootstrap/cache/*` | `optimize:clear` in the deploy and rollback runbooks | file | `config:cache` forbids `env()` outside `config/` - DEP-04 scans for it |

**The isolation rule for caches**: any cache key whose value depends on who is asking must contain the user
id (or the collaborator/student/client id). PRF-14 enumerates every `Cache::remember` call in `app/` and
fails on one whose key has no owner component and whose closure touches a model carrying a global scope.

### 6.5 Accessibility support (Phase 24)

| Class | Responsibility |
|---|---|
| `Tests\Support\A11y` | DOM assertions over rendered HTML using `DOMDocument` + XPath: `assertSingleH1`, `assertHeadingOrder`, `assertLandmarks` (`header`, `nav`, `main`, `footer`, one `main`), `assertHtmlLang`, `assertUniqueTitle`, `assertEveryInputLabelled` (a `label[for]`, a wrapping `label`, `aria-label` or `aria-labelledby`), `assertIconButtonsLabelled` (a `button`/`a` whose text content is empty must carry `aria-label` or `title`), `assertTablesCaptioned`, `assertErrorsAssociated` (`aria-describedby` + `aria-invalid` on invalid fields), `assertSkipLink`, `assertLiveRegions` (toast container `aria-live="polite"`, skeleton `role="status"`), `assertNoPositiveTabIndex`, `assertChartHasTextAlternative` (every `x-ui.chart` has a `<table class="sr-only">` or a `aria-describedby` summary) |
| `Tests\Support\Contrast` | Pure-PHP WCAG contrast: `ratio(string $hexA, string $hexB): float`, `assertAtLeast(4.5, ...)`. Iterates `tests/Support/contrast-pairs.php` - every documented foreground/background token pair in both themes - so a palette change fails CI instead of failing a user |
| `Tests\Browser\*` (optional, §12 Q3) | Playwright specs for the keyboard, focus-trap and overflow checks that genuinely need a browser |

### 6.6 Console commands

| Command | Phase | What it does | Exit codes |
|---|---|---|---|
| `audit:manifest {--check\|--write\|--coverage}` | 24 | §6.1 | 0 ok, 1 drift |
| `security:audit {--suite=all} {--quiet} {--json}` | 24 | Static + runtime audit: unguarded routes, `VerifyCsrfToken::$except` non-empty, `$guarded = []` models, raw-SQL interpolation, `{!! !!}` outside the allowlist, inline script without a nonce, `APP_DEBUG` / `APP_ENV` sanity, `.env` permissions and web-reachability, default or seeded passwords still in use, missing security headers on a sample request, writable-directory audit, `composer audit`, `npm audit --omit=dev`. Writes an `integrity_check_runs` row with `suite = security` | 0 pass, 1 warnings, 2 findings |
| `security:route-manifest {--write}` | 24 | regenerates `route-guard-manifest.php` from the live route list, preserving existing `rationale` strings | 0/1 |
| `integrity:verify {--suite=} {--scope=} {--json}` | 24 | runs one or all suites through `IntegrityCheckService`; `constraints` delegates to the spine's `financial:verify-constraints`, `wallet` to `CommissionReconciliationService` (read-only, never `--repair`) | 0/1/2 |
| `perf:budget {--route=} {--all} {--write-baseline}` | 24 | logs in as a seeded Super Admin over the test harness, hits each manifest route, asserts the query budget and the p95 wall time, prints a table of breaches and duplicate queries | 0 ok, 2 breach |
| `a11y:scan {--route=}` | 24 | renders each manifest screen and runs the `A11y` assertion set headlessly (no browser); prints one row per failure | 0/2 |
| `backup:run {--type=database\|files\|full} {--reason=}` | 25 | `BackupService::run()` with `BackupTrigger::Manual` when a reason is given, else `Scheduled` | 0/1 |
| `backup:verify {--backup=} {--latest} {--deep}` | 25 | checksum, or the deep restore-into-scratch proof (HD-6) | 0/1 |
| `backup:prune {--dry-run}` | 25 | `BackupRetentionService`; `--dry-run` prints the plan and changes nothing | 0/1 |
| `backup:restore {--backup=} {--target=} {--database=} {--reason=} {--confirm=}` | 25 | the console path of §6.10.5; refuses without the typed confirmation phrase; refuses `--target=production` unless `--i-have-a-pre-restore-backup` is implied by the service having taken one | 0/1 |
| `ops:health {--json} {--deep}` | 25 | `SystemHealthService::snapshot()`; non-zero when any probe fails - the command a monitoring agent calls | 0 ok, 1 degraded, 2 failed |
| `ops:heartbeat` | 25 | every minute: stamps `ops.scheduler_heartbeat`, dispatches `QueuePingJob` every fifth run, pings `ops.uptime_ping_url` | 0 |
| `ops:check-heartbeats` | 25 | compares both heartbeats against their `ops.*_max_minutes` and `failed_jobs` against the threshold; fires `QueueWorkerStalled` / `SchedulerStalled` / `FailedJobThresholdExceeded` | 0/1 |
| `ops:digest` | 25 | the tier-1 daily mail/in-app digest: errors by class, failed jobs, backup status, last reconciliation, drift, lazy-load violations, slow queries, 429 counts | 0 |
| `ops:prune-integrity-runs` | 25 | §2.3 - nulls old `findings`, deletes nothing | 0 |
| `user:create-super-admin {--name=} {--email=} {--show-password} {--force}` | 25 | §6.8 step 8 | 0/1 |
| `golive:check {--json}` | 25 | runs every item of §6.13 that is machine-checkable and prints the rest as manual rows; **exit 2 while any blocker is open** | 0/1/2 |
| `demo:seed {--fresh}` | 25 | §6.7; refuses outright when `APP_ENV=production` | 0/1 |

`composer.json` scripts, so the whole gate is one command (paths quoted for the space in the project path):

```json
"scripts": {
    "harden": [
        "@php artisan audit:manifest --check",
        "@php artisan security:audit",
        "@php artisan integrity:verify --suite=all",
        "@php artisan perf:budget --all",
        "@php artisan a11y:scan",
        "@php artisan test --stop-on-failure"
    ],
    "golive": ["@php artisan golive:check"]
}
```

### 6.7 Seeders - production versus demo (§116)

| Seeder | Contents | Guard |
|---|---|---|
| `ProductionSeeder` | `ModuleSeeder`, `PermissionSeeder`, `RoleSeeder`, `SettingSeeder`, `BranchSeeder` (one default branch), `PaymentMethodSeeder`, `LeaveTypeSeeder`, `SalaryComponentSeeder`, `FinanceCategorySeeder`, `CourseCategorySeeder` (the §64 starting set), `WebsiteSectionSeeder` (the §100 sections, disabled), `FaqSeeder` (empty), `PageSeeder` (privacy, terms, refund, course policy as drafts) | Idempotent (`firstOrCreate` / `syncPermissions`), deletes nothing, creates **no user** |
| `SuperAdminSeeder` | **does not exist.** The first account is created only by `user:create-super-admin` (§6.8 step 8), so no known password is ever seeded | DEP-06 asserts no seeder creates a user with a literal password |
| `DemoDataSeeder` | §116 in full: one test account per role (Super Admin, Admin, HR, Accountant, Project Manager, Developer, Designer, SEO Expert, Digital Marketer, Sales Executive, Receptionist, Support Agent, Institute Manager, Course Coordinator, Teacher, Student, Client, Collaborator), each with `must_change_password = true` and a password from `DEMO_PASSWORD` (no default); 2 branches (D11 coverage); 20 clients, 60 leads across every status, 15 projects with milestones and tasks, 40 project payments, 8 collaborators with commission rule versions, referrals, ledger entries, wallets and 6 payouts, 12 courses with outlines, 6 batches, 80 students, 120 admissions, 300 fee charges with installments, discounts, 500 receipts, 40 refunds, 3 voids, attendance for a month, 4 exams with results, 10 certificates, 30 inquiries, 12 blog posts, 6 jobs with applications, 20 tickets, 30 meetings | **Refuses when `APP_ENV=production`** (the command and the seeder both check); every money row is written through `PaymentService::allowDirectWrites()` or the real service, never by a raw insert, so the demo data is itself a commission-engine test |
| `PerformanceFixtureSeeder` | The volume fixture for PRF-06..PRF-10: 5 000 students, 20 000 fee charges, 60 000 receipts, 150 000 ledger entries, 2 000 projects, 500 collaborators - generated in chunks with `allowDirectWrites`, no events | `--force` + non-production only |

**`demo:seed` then `integrity:verify --suite=all` must be clean.** That single pairing is what makes §116
more than decoration: demo data that cannot reconcile is a bug in the engine, not in the seeder (FIN-19).

---
### 6.8 Installation runbook (§115)

Ships as `docs/INSTALL.md`, generated from this section so the two can never diverge (DEP-01 asserts the two
are in sync by comparing the step list). **Every path is quoted in every command**: the project directory
contains a space (`my office`, tech-debt T2), and an unquoted path is the single most likely installation
failure on this system.

| # | Step | Command | Acceptance criterion |
|---|---|---|---|
| 1 | Check the runtime | `php -v`, `php -m`, `composer --version`, `node -v`, `mysql --version` | PHP >= 8.2; extensions `bcmath curl gd mbstring openssl pdo_mysql zip exif fileinfo json tokenizer xml` all present (`intl` optional); Composer 2.x; Node >= 20; MariaDB >= 10.4 |
| 2 | Get the code | `cd "C:/xampp/htdocs"` then unpack the release into `"my office"`, or `git clone <repo> "my office"` | `"C:/xampp/htdocs/my office/artisan"` exists |
| 3 | PHP dependencies | `cd "C:/xampp/htdocs/my office"`<br>`composer install --no-dev --optimize-autoloader --classmap-authoritative` | exit 0; `vendor/autoload.php` exists; `composer audit` reports no high/critical advisory |
| 4 | Environment file | `copy .env.example .env` (Windows) / `cp .env.example .env` | `.env` exists and every key of §6.8.1 is present |
| 5 | App key | `php artisan key:generate --force` | `APP_KEY=base64:...` is set and is **not** the example value |
| 6 | Database + users | run the SQL of §6.9.3 as root | `SHOW GRANTS FOR 'my_office_app'@'127.0.0.1'` contains no `ALTER`, `DROP`, `CREATE`, `GRANT OPTION`, `SUPER`, `FILE`, `PROCESS` |
| 7 | Schema | `php artisan migrate --database=mysql_migration --force --step` | `php artisan migrate:status` shows every migration `Ran`; `integrity:verify --suite=constraints` exits 0 (every CHECK, generated column, unique guard and delete trigger of the spine exists) |
| 8 | Reference data | `php artisan db:seed --class=ProductionSeeder --force` | modules, permissions, the 18 roles, every setting key, one default branch exist; re-running changes nothing (idempotent) |
| 9 | The first Super Admin, securely | `php artisan user:create-super-admin --name="<real name>" --email="<real address>"` | Exactly one Super Admin exists; `must_change_password = true`; `email_verified_at` set; the command printed **no password** and instead sent a signed password-reset link valid 60 minutes. Offline install: add `--show-password`, which prints a 24-char random password **once to stdout** (never to the log, never to the activity log, never mailed) and exits. The command refuses when a Super Admin already exists unless `--force`, and refuses a disposable or example domain |
| 10 | Storage link | `php artisan storage:link` | `public/storage` resolves to `storage/app/public`. On Windows without symlink privileges: run the shell as Administrator (or enable Developer Mode), or skip it and use the vhost `Alias` of §6.9.2 - **never** copy the folder |
| 11 | Writable paths | §6.9.4 | `storage/` and `bootstrap/cache/` writable by the web user only; everything else read-only to it |
| 12 | Front-end build | `npm ci`<br>`npm run build` | `public/build/manifest.json` exists; CSS and JS inside the PRF-11 budget; no source maps shipped |
| 13 | Caches | `php artisan config:cache`<br>`php artisan route:cache`<br>`php artisan view:cache`<br>`php artisan event:cache` | all four exit 0; `php artisan about` reports Config/Routes/Views/Events **CACHED**; the app still boots (`php artisan about` is the proof that no `env()` call outside `config/` broke) |
| 14 | Queue worker | §6.9.5 | `php artisan queue:work --once` processes a job; the service/daemon is running and restarts on failure; `ops:health` reports the queue heartbeat fresh |
| 15 | Scheduler | §6.9.6 | `php artisan schedule:list` lists every command of §10.4 with the expected next-run time; one minute after enabling, `ops.scheduler_heartbeat` is fresh |
| 16 | Apache vhost + HTTPS | §6.9.1, §6.9.2 | `https://<host>/` serves the public site; `http://` 301s to `https://`; `https://<host>/.env` returns 403/404; `https://<host>/storage/<an-uploaded>.php` is **not executed**; `https://<host>/admin` redirects to login |
| 17 | Settings | log in, open Settings, fill `company`, `branding`, `localization` (PKR, Asia/Karachi), `contact`, `mail`, then send a test email | the test email arrives using the **saved** SMTP settings, not `.env` |
| 18 | Backups | configure the `backup` group, then `php artisan backup:run --type=full --reason="install verification"` followed by `php artisan backup:verify --latest --deep` | one `backup_runs` row `completed` with a checksum, and `verification_status = restore_ok` |
| 19 | Final gate | `php artisan golive:check` | exit 0 - no blocker open (§6.13) |
| 20 | Demo data (non-production only) | `php artisan demo:seed --fresh` then `php artisan integrity:verify --suite=all` | 18 demo accounts exist, every money figure reconciles, drift 0.00 |

Rollback of a failed install is trivial and stated so no one improvises: drop the database, delete `.env`,
and start at step 4.

#### 6.8.1 `.env.example` - the complete file

No value here is a real secret; DEP-03 asserts that every `env('KEY')` referenced anywhere under `config/`
has a key in this file, and that no key in it holds a non-empty secret.

```dotenv
APP_NAME="MyOffice ERP"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://erp.example.com
APP_TIMEZONE=Asia/Karachi
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_VERSION=
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14
LOG_DEPRECATIONS_CHANNEL=null

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=my_office
DB_USERNAME=my_office_app
DB_PASSWORD=
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
# DDL-capable connection, used only by `php artisan migrate --database=mysql_migration`
DB_MIGRATION_USERNAME=my_office_migrator
DB_MIGRATION_PASSWORD=
# read-only connection used by mysqldump
DB_BACKUP_USERNAME=my_office_backup
DB_BACKUP_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

CACHE_STORE=database
CACHE_PREFIX=myoffice_
QUEUE_CONNECTION=database
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=public

# Runtime mail is configured in Settings > Mail (encrypted in the DB) and overrides these at boot.
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@example.com"
MAIL_FROM_NAME="${APP_NAME}"

BACKUP_DISK=backups
BACKUP_OFFSITE_DISK=
MYSQLDUMP_PATH="C:/xampp/mysql/bin/mysqldump.exe"
MYSQL_CLIENT_PATH="C:/xampp/mysql/bin/mysql.exe"

FORCE_HTTPS=true
TRUSTED_PROXIES=
ASSET_URL=
VITE_APP_NAME="${APP_NAME}"

# Non-production only; the seeder refuses to run when APP_ENV=production
DEMO_PASSWORD=
```

### 6.9 Production notes

#### 6.9.1 Apache vhost - a DocumentRoot whose path contains a space

Three rules make the space a non-issue: **quote every path**, use **forward slashes** even on Windows, and
point `DocumentRoot` *inside* the folder so no URL ever has to encode it. Place the file at
`C:/xampp/apache/conf/extra/httpd-vhosts.conf` (Windows) or
`/etc/apache2/sites-available/myoffice.conf` (Linux), and ship it in the repo as `deploy/apache-vhost.conf`.

```apache
# Required modules: rewrite headers ssl deflate expires mime
<VirtualHost *:80>
    ServerName erp.example.com
    DocumentRoot "C:/xampp/htdocs/my office/public"
    RedirectPermanent / https://erp.example.com/
    ErrorLog  "C:/xampp/apache/logs/myoffice-error.log"
    CustomLog "C:/xampp/apache/logs/myoffice-access.log" combined
</VirtualHost>

<VirtualHost *:443>
    ServerName erp.example.com
    DocumentRoot "C:/xampp/htdocs/my office/public"

    SSLEngine on
    SSLCertificateFile      "C:/xampp/apache/conf/ssl.crt/erp.example.com.crt"
    SSLCertificateKeyFile   "C:/xampp/apache/conf/ssl.key/erp.example.com.key"
    SSLCertificateChainFile "C:/xampp/apache/conf/ssl.crt/erp.example.com-chain.crt"
    SSLProtocol             -all +TLSv1.2 +TLSv1.3
    SSLHonorCipherOrder     off
    SSLSessionTickets       off

    <Directory "C:/xampp/htdocs/my office/public">
        Options -Indexes -MultiViews +FollowSymLinks
        AllowOverride All                 # Laravel's public/.htaccess provides the rewrite
        Require all granted
    </Directory>

    # Only needed when `php artisan storage:link` could not create the symlink (Windows privileges).
    Alias "/storage" "C:/xampp/htdocs/my office/storage/app/public"
    <Directory "C:/xampp/htdocs/my office/storage/app/public">
        Options -Indexes -ExecCGI
        AllowOverride None
        Require all granted
        # mod_php:
        php_flag engine off
        RemoveHandler .php .phtml .phar .php3 .php4 .php5 .php7 .php8 .pht
        # php-fpm / proxy_fcgi (use instead of php_flag):
        # <FilesMatch "\.(?i:php|phtml|phar|ph[0-9]|pht)$">
        #     SetHandler none
        #     ForceType text/plain
        # </FilesMatch>
        <FilesMatch "\.(?i:php|phtml|phar|ph[0-9]|pht|inc|cgi|pl|asp|aspx|jsp|htaccess|env|ini|sh|bat|exe)$">
            Require all denied
        </FilesMatch>
    </Directory>

    # Hashed build assets are immutable.
    <Directory "C:/xampp/htdocs/my office/public/build">
        Header always set Cache-Control "public, max-age=31536000, immutable"
    </Directory>

    # Belt and braces: the application sets these too (SecurityHeaders middleware is the authority).
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set X-Frame-Options "DENY"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()"
    Header always set Strict-Transport-Security "max-age=31536000" env=HTTPS
    Header unset X-Powered-By
    ServerSignature Off

    <FilesMatch "^\.(?i:env|git|gitignore|htaccess|user\.ini)">
        Require all denied
    </FilesMatch>

    AddOutputFilterByType DEFLATE text/html text/css text/plain text/xml application/javascript application/json image/svg+xml
    <IfModule mod_expires.c>
        ExpiresActive On
        ExpiresByType image/webp "access plus 1 month"
        ExpiresByType image/jpeg "access plus 1 month"
        ExpiresByType image/png  "access plus 1 month"
        ExpiresByType font/woff2 "access plus 1 year"
    </IfModule>

    ErrorLog  "C:/xampp/apache/logs/myoffice-ssl-error.log"
    CustomLog "C:/xampp/apache/logs/myoffice-ssl-access.log" combined
</VirtualHost>
```

Additional rules:

| Rule | Why / how |
|---|---|
| Nothing outside `public/` is ever served | `DocumentRoot` points at `public/`; the directories above it are not inside any `Alias`. DEP-09 asserts `GET /.env`, `/storage/logs/laravel.log`, `/composer.json`, `/vendor/autoload.php`, `/database/database.sqlite`, `/docs/requirements.md`, `/.git/config` all fail |
| Apache must not be the global XAMPP instance on a shared box | one vhost per site, `ServerName` set, no `_default_` catch-all serving `htdocs/` |
| `php artisan serve` is **never** the production server | DEP-10: the go-live check fails if the `APP_URL` host resolves to a `:8000` dev server |
| Linux variant | identical file with `/var/www/myoffice/public` paths, `a2ensite myoffice && systemctl reload apache2`, certs from certbot (`certbot --apache -d erp.example.com`), renewal verified with `certbot renew --dry-run` |
| Behind a proxy or load balancer | set `TRUSTED_PROXIES` and `security.trusted_proxies`; otherwise every `login_histories.ip_address` records the proxy and the rate limiters key on one IP for all users - DEP-11 checks a known client IP arrives intact |

#### 6.9.2 HTTPS

1. Install the certificate (client-provided on Windows; `certbot --apache` on Linux).
2. `APP_URL=https://...`, `SESSION_SECURE_COOKIE=true`, `security.force_https = true`.
3. Verify: `https://` serves, `http://` 301s, no mixed-content warning in the console on the public home,
   the admin shell and a print view (PRF/SEC check: every asset URL in the HTML starts with `https` or `/`).
4. **Only then** set `security.hsts_enabled = true` (and decide `hsts_include_subdomains` deliberately - it
   is hard to undo). Order matters: HSTS before a working certificate locks the site out of browsers.
5. Re-run `php artisan config:cache` after changing `.env`; settings changes need no cache rebuild.

#### 6.9.3 A least-privilege MySQL user

```sql
CREATE DATABASE IF NOT EXISTS `my_office`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. runtime user: DML only. The application can never change the schema.
CREATE USER 'my_office_app'@'127.0.0.1' IDENTIFIED BY '<32 random chars>';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON `my_office`.* TO 'my_office_app'@'127.0.0.1';

-- 2. migration user: used only by `php artisan migrate --database=mysql_migration --force`.
--    TRIGGER is required: the spine creates nine BEFORE DELETE triggers.
CREATE USER 'my_office_migrator'@'127.0.0.1' IDENTIFIED BY '<32 random chars>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES,
      CREATE VIEW, SHOW VIEW, TRIGGER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE, LOCK TABLES
  ON `my_office`.* TO 'my_office_migrator'@'127.0.0.1';

-- 3. backup user: read and dump only.
CREATE USER 'my_office_backup'@'127.0.0.1' IDENTIFIED BY '<32 random chars>';
GRANT SELECT, SHOW VIEW, LOCK TABLES, TRIGGER, EVENT ON `my_office`.* TO 'my_office_backup'@'127.0.0.1';

-- 4. close the XAMPP defaults (tech debt T3)
ALTER USER 'root'@'localhost' IDENTIFIED BY '<strong password>';
DELETE FROM mysql.user WHERE User = '';        -- anonymous users
DROP DATABASE IF EXISTS test;
FLUSH PRIVILEGES;
```

`config/database.php` gains a second connection `mysql_migration`, identical to `mysql` but reading
`DB_MIGRATION_USERNAME` / `DB_MIGRATION_PASSWORD`, and a third `mysql_backup` used only by the dump.

| Acceptance criterion | Proof |
|---|---|
| The app user cannot change the schema | `php artisan migrate --force` (default connection) **fails** with a privilege error; `--database=mysql_migration` succeeds (DEP-07) |
| No user holds `SUPER`, `FILE`, `PROCESS`, `RELOAD`, `GRANT OPTION`, `CREATE USER` | `SHOW GRANTS` for all three users asserted by `security:audit` |
| The triggers still fire for the app user | FIN-17 runs a raw `DELETE` on each of the nine append-only tables **as the app user** and expects SQLSTATE 45000 |
| No credential is in the repo | `git grep` / `security:audit` finds no password literal; `.env` is outside the webroot and `chmod 600` |

#### 6.9.4 File permissions

**Linux**

```bash
sudo chown -R deploy:www-data /var/www/myoffice
sudo find /var/www/myoffice -type d -exec chmod 750 {} \;
sudo find /var/www/myoffice -type f -exec chmod 640 {} \;
sudo chmod -R ug+rwX /var/www/myoffice/storage /var/www/myoffice/bootstrap/cache
sudo chmod 600 /var/www/myoffice/.env
sudo chmod +x  /var/www/myoffice/artisan
```

**Windows** - first give Apache its own low-privilege account (`svc_myoffice`) instead of LocalSystem, then:

```powershell
icacls "C:\xampp\htdocs\my office" /inheritance:r `
  /grant:r "Administrators:(OI)(CI)F" "SYSTEM:(OI)(CI)RX" "svc_myoffice:(OI)(CI)RX"
icacls "C:\xampp\htdocs\my office\storage"          /grant "svc_myoffice:(OI)(CI)M"
icacls "C:\xampp\htdocs\my office\bootstrap\cache"  /grant "svc_myoffice:(OI)(CI)M"
icacls "C:\xampp\htdocs\my office\.env" /inheritance:r /grant:r "Administrators:F" "svc_myoffice:R"
icacls "C:\xampp\htdocs\my office\storage\app\backups" /grant "svc_myoffice:(OI)(CI)M"
```

Acceptance criterion: the web user can write **only** `storage/` and `bootstrap/cache/`; `security:audit`
enumerates every directory under the base path and fails on any other writable one, and on a world-readable
`.env`.

#### 6.9.5 Queue worker

**Linux (supervisor)** - `/etc/supervisor/conf.d/myoffice-queue.conf`:

```ini
[program:myoffice-queue]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/myoffice/artisan queue:work --queue=high,default --sleep=1 --tries=3 --max-time=3600 --max-jobs=500
directory=/var/www/myoffice
user=www-data
numprocs=2
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/log/myoffice/queue.log
```

**Windows (NSSM)** - note the quoted working directory:

```powershell
nssm install MyOfficeQueue "C:\xampp\php\php.exe"
nssm set MyOfficeQueue AppDirectory "C:\xampp\htdocs\my office"
nssm set MyOfficeQueue AppParameters "artisan queue:work --queue=high,default --sleep=1 --tries=3 --max-time=3600 --max-jobs=500"
nssm set MyOfficeQueue AppStdout "C:\xampp\htdocs\my office\storage\logs\queue.out.log"
nssm set MyOfficeQueue AppStderr "C:\xampp\htdocs\my office\storage\logs\queue.err.log"
nssm set MyOfficeQueue AppRestartDelay 5000
nssm set MyOfficeQueue Start SERVICE_AUTO_START
nssm start MyOfficeQueue
```

| Rule | Why |
|---|---|
| `--queue=high,default` | commission jobs and reversals are dispatched to `high` so a bulk export can never delay money |
| `--max-time=3600 --max-jobs=500` | bounded lifetime defeats memory creep; the supervisor restarts it |
| `queue:restart` is part of every deploy (§6.12) | a worker holds the old code in memory; skipping this is the classic "the fix did not take" bug |
| `stopwaitsecs=3600` | never SIGKILL a worker mid-transaction |
| Two processes, not ten | the DB queue driver serialises on `jobs`; the spine's `ShouldBeUnique` jobs plus `uq_cle_dedupe` make concurrency safe, but more workers only add lock contention at this scale |
| Failed jobs | `php artisan queue:failed`, `queue:retry <id>`, `queue:retry all`; `failed_jobs` count is a health probe and an `ops:digest` line - **never** `queue:flush` on production without reading them first, because a flushed commission job is lost work (the `commissions:sweep` job re-queues it, which is exactly why the sweeper exists) |

#### 6.9.6 Scheduler

**Linux** - one crontab line, nothing else:

```cron
* * * * * cd /var/www/myoffice && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

**Windows** - a `.bat` wrapper dodges the quoting trap of nested quotes in `schtasks /TR`. Ship it as
`deploy/schedule.bat`:

```bat
@echo off
cd /d "C:\xampp\htdocs\my office"
"C:\xampp\php\php.exe" artisan schedule:run >> "C:\xampp\htdocs\my office\storage\logs\schedule.log" 2>&1
```

```powershell
schtasks /Create /TN "MyOffice Scheduler" /SC MINUTE /MO 1 /RL HIGHEST /RU "svc_myoffice" /RP * `
  /TR "C:\xampp\htdocs\my office\deploy\schedule.bat"
schtasks /Run /TN "MyOffice Scheduler"
```

Acceptance: `php artisan schedule:list` shows all 20 commands of §10.4 with the right cadence and timezone
(`Asia/Karachi`); `ops.scheduler_heartbeat` is refreshed within two minutes; `schedule:test` runs a chosen
command interactively; a scheduler that stops is reported by `ops:check-heartbeats` within
`ops.scheduler_heartbeat_max_minutes`.

#### 6.9.7 PHP, opcache, logging, monitoring

`php.ini` (production):

```ini
expose_php = Off
display_errors = Off
display_startup_errors = Off
log_errors = On
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
memory_limit = 512M
max_execution_time = 60
upload_max_filesize = 20M
post_max_size = 24M
max_input_vars = 5000            ; the role permission matrix posts several hundred checkboxes
max_file_uploads = 20
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_samesite = Lax

[opcache]
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 32
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0   ; production only - a deploy MUST reload Apache (below)
opcache.revalidate_freq = 0
opcache.save_comments = 1         ; required: attributes and annotations are read at runtime
opcache.max_wasted_percentage = 10
realpath_cache_size = 4096K
realpath_cache_ttl = 600
```

| Item | Decision |
|---|---|
| `max_input_vars = 5000` | **not cosmetic**: the Phase 1 role editor posts one checkbox per permission and the registry declares several hundred. At the default 1000, PHP silently truncates the array and the role saves with permissions missing. SEC-19 posts a full matrix and asserts every checked permission is persisted |
| Resetting opcache on deploy | `validate_timestamps=0` means new code is invisible until the SAPI restarts. The deploy step is `httpd -k graceful` (Windows: `Restart-Service Apache2.4`) / `systemctl reload apache2` - **not** a web-reachable `opcache_reset()` route, which would be an unauthenticated denial-of-service lever |
| JIT | left **off**. This workload is I/O and query bound; JIT adds risk without a measured win. Revisit only with a PRF baseline that shows CPU as the bottleneck |
| Disabled functions | `dl` only. `proc_open` must stay enabled - `mysqldump`, the backup package and `npm run build` all need it. Disabling `exec`/`shell_exec` is fine and recommended; `security:audit` warns if `proc_open` was disabled because backups would silently stop working |
| Logging | `LOG_CHANNEL=stack`, `LOG_STACK=daily`, `LOG_LEVEL=warning`, `LOG_DAILY_DAYS` from `ops.log_retention_days` (Laravel prunes its own dailies - no logrotate needed for app logs). Apache logs rotate with the OS tool (`logrotate` on Linux, a weekly `schtasks` archive job on Windows). Every channel has the `RedactSensitive` tap |
| Log access | `storage/logs` is not web-reachable (DEP-09) and the in-app log viewer is gated by `activity_log.view_logs` |
| Error monitoring, tier 1 (**the contract**) | No new dependency: a `ReportCriticalError` listener + `ops:digest`. A 500, a `CommissionGenerationFailed`, a `WalletDriftDetected`, a failed backup, a stalled worker or scheduler, and a failed integrity suite each raise a database notification to holders of `system_health.view_logs` and, when `backup.notify_emails` is set, an email. The digest lands daily at `ops.error_digest_time` |
| Error monitoring, tier 2 (optional, §12 Q5) | `sentry/sentry-laravel` behind `ops.error_monitoring_enabled` + `error_monitoring_dsn`, `traces_sample_rate = 0.1`, PII scrubbing on (`send_default_pii = false`) and the `RedactSensitive` keys added to its scrubber |
| Health endpoint | `GET /health` with the `ops.health_check_token` in an `X-Health-Token` header **or** a signed URL - never a query string (privacy rule). Wrong or missing token returns **404**, so the endpoint is not discoverable. Returns 200 `{status: ok|degraded, checks: {...}}` or 503. `/up` (the framework default) stays as a bare liveness probe |

---

### 6.10 The backup system (§114)

#### 6.10.1 Package and disks

`spatie/laravel-backup ^9` (already planned in `DEVELOPMENT_LOG.md` §2). `config/backup.php` is generated
**from the settings group**, not hand-edited, by `App\Providers\BackupConfigProvider` at boot - so the admin
UI is the single source of truth and a setting change needs no file edit.

| Disk | Definition | Rule |
|---|---|---|
| `backups` | `storage/app/backups` (local) | **outside the webroot**; DEP-09 asserts it is not web-reachable; never inside `storage/app/public` |
| `backups_offsite` | `backup.offsite_disk` - S3, SFTP, or a mapped network/USB drive | the 3-2-1 copy; go-live blocker while `backup.offsite_required_for_go_live` is true |

#### 6.10.2 What is in an archive

| Content | In the database archive | In the files archive |
|---|---|---|
| Every table except `backup.excluded_tables` | yes | - |
| `jobs`, `failed_jobs` | **yes** (queued commission work must survive a restore) | - |
| `cache`, `cache_locks`, `sessions`, `job_batches` | no (noise; a restore should log everyone out anyway) | - |
| Triggers, routines, events | yes (`--triggers --routines --events`) - the spine's nine delete triggers are part of the schema's guarantees | - |
| `storage/app/public` (CMS media and its derivatives, avatars, public page banners) | - | yes |
| `storage/app/private` (client and employee documents, CVs, **expense receipts**, **invoice PDFs**, course materials, submissions, certificates, ID cards, payout proofs, exports) | - | yes |
| `storage/logs`, `storage/framework`, `node_modules`, `vendor`, `.git`, `storage/app/backups` | - | no |
| `.env` | - | **only** when `backup.encrypt_archives` **and** `backup.include_env`; otherwise the operator stores `.env` in the password manager and the runbook says so (HD-8) |

#### 6.10.3 Retention

`BackupRetentionService::classify()` stamps `retention_class` at creation and `retention_until` from the
settings of §5.1. `prune()` then removes **files only**, newest-first-safe:

| Class | Kept | Rule |
|---|---|---|
| `transient` | `retention_keep_all_days` (7) | every backup, whatever its age bucket |
| `daily` | `retention_daily_days` (30) | one per day |
| `weekly` | `retention_weekly_weeks` (12) | the Sunday one |
| `monthly` | `retention_monthly_months` (12) | the first of the month |
| `yearly` | `retention_yearly_years` (3) | 1 January |

Hard rules, each a test (DEP-15):

1. Never fewer than `retention_min_copies` usable **database** archives exist after a prune.
2. The newest usable database archive is never pruned, whatever the dates say.
3. A `pre_restore` or `pre_deploy` backup is never pruned automatically (`isProtectedFromPruning()`).
4. A `backup_runs` row is **never deleted**; pruning sets `file_pruned_at` and `status = pruned`.
5. When total size would exceed `backup.max_storage_gb`, the job **fails loudly** with a notification rather
   than pruning past policy - running out of disk is an operations problem, not a licence to destroy history.
6. `--dry-run` prints the plan and touches nothing; the UI's "Preview prune" button uses it.

#### 6.10.4 Verification - the proof list

`BackupVerificationService::proofFigures()` counts these tables (the financial and identity spine) and
stores them on the `backup_runs` row and on both sides of a restore:

`users`, `roles`, `permissions`, `collaborators`, `collaborator_commission_ledger_entries`,
`collaborator_commission_entitlements`, `collaborator_wallets`, `collaborator_payouts`,
`collaborator_payout_allocations`, `collaborator_referrals`, `collaborator_commission_settings`,
`student_fees`, `student_fee_installments`, `student_fee_discounts`, `student_fee_payments`,
`project_payments`, `payment_reversals`, `invoices`, `invoice_items`, `expenses`, `incomes`,
`students`, `student_admissions`, `projects`, `clients`, `activity_log`.

| Level | Command | What it proves | Cadence |
|---|---|---|---|
| Checksum | `backup:verify --latest` | the archive on disk is byte-identical to what was written (`checksum_sha256`), and it opens as a valid zip | daily 04:00 |
| Deep (HD-6) | `backup:verify --latest --deep` | the archive **restores**: into `backup.restore_scratch_database`, then `migrate:status` shows nothing pending, the proof counts match the source within the expected delta, `financial:verify-constraints` passes on the restored copy, and `collaborators:reconcile-wallets` reports zero structural failures. The scratch database is dropped afterwards; `verification_status = restore_ok` | weekly Sunday 04:30 |

A `backup_runs` row that never reached `restore_ok` **is not a backup** for go-live purposes (§6.13).

#### 6.10.5 Restore procedure (§114, authorized access only)

Authorization is four gates, all mandatory, none skippable from the UI or the console:

| Gate | Implementation |
|---|---|
| Permission | `backups.restore` - granted to Super Admin only by default (§4.3) |
| Re-authentication | Laravel's `password.confirm` middleware on both the form and the submit, stamped onto `backup_restores.password_confirmed_at` |
| Typed confirmation | `backup.restore_confirmation_phrase` rendered with `{database}` and `{date}` substituted; compared **case-sensitively**; stamped onto `confirmed_at` |
| Written reason | `backup_restores.reason` is NOT NULL and validated `min:20` - "testing" does not pass review |

The procedure, executed by `BackupRestoreService::execute()` and mirrored exactly by
`php artisan backup:restore`:

| # | Step | Command / action | Acceptance criterion |
|---|---|---|---|
| 1 | Announce | tell the operator which database and which archive, with its checksum and age | the screen and the console print the same three facts |
| 2 | Verify the archive | `backup:verify --backup=<id>` | `checksum_verified = true`; a mismatch **stops here** |
| 3 | Maintenance mode | `php artisan down --secret="<ops.maintenance_secret>" --render=errors::503` | the public site and all five panels return 503 with `Retry-After`; the operator can still browse using the secret URL |
| 4 | Stop the worker | `nssm stop MyOfficeQueue` / `supervisorctl stop myoffice-queue:*` | no worker writes during the swap |
| 5 | Pre-restore backup | automatic, `BackupTrigger::PreRestore` | a `completed` row exists and is linked on `pre_restore_backup_run_id`; failure **aborts the restore** |
| 6 | Capture before-counts | automatic | `row_counts_before`, `ledger_rows_before` stored |
| 7 | Restore | `"C:/xampp/mysql/bin/mysql.exe" --defaults-extra-file=<temp> my_office < "<archive>/db-dumps/mysql-my_office.sql"` (the service does this; the password is never on the command line) | exit 0 |
| 8 | Bring the schema forward | `php artisan migrate --database=mysql_migration --force` | `migrate:status` shows nothing pending |
| 9 | Clear derived state | `php artisan optimize:clear && php artisan permission:cache-reset` | caches, config, routes, views and the permission cache rebuilt |
| 10 | Prove the money | `php artisan integrity:verify --suite=constraints` then `--suite=wallet` | constraints pass; reconciliation reports **zero structural failures**; drift is reported, never auto-repaired (spine §6.5.4) |
| 11 | Capture after-counts | automatic | `row_counts_after`, `ledger_rows_after`, `proof` stored; the screen shows a before/after diff table |
| 12 | Restart the worker, leave maintenance | `supervisorctl start ...` / `nssm start MyOfficeQueue`, then `php artisan up` | `ops:health` green; `commissions:sweep` completes any work that was queued in the restored snapshot |
| 13 | Record | automatic | `backup_restores` row `completed`, one activity-log row with old/new counts and the reason, a notification to every Super Admin |

If step 10 fails, the restore is recorded `failed`, the application **stays down**, and the runbook's next
line is step 5's pre-restore backup - the way back is always the backup taken two minutes ago.

---
### 6.11 Deploy runbook (every release after the first)

Releases are **directory swaps**, not in-place edits, so a rollback is a rename rather than a rebuild. Ship
as `deploy/deploy.ps1` (Windows) and `deploy/deploy.sh` (Linux); both are thin wrappers around this table so
the runbook and the script cannot diverge.

| # | Step | Command | Acceptance criterion |
|---|---|---|---|
| 1 | Freeze | announce the window; `php artisan ops:health --deep` | green before you start; nothing is deployed over a degraded system |
| 2 | Pre-deploy backup | `php artisan backup:run --type=full --reason="pre-deploy <version>"` | a `completed` row with `trigger = pre_deploy` (never pruned automatically) |
| 3 | Stage the release | unpack into `"C:/xampp/htdocs/my office.releases/<timestamp>"`; `composer install --no-dev --optimize-autoloader --classmap-authoritative`; `npm ci && npm run build`; copy `.env` and symlink/copy `storage` | the staged tree boots: `php artisan about` from inside it exits 0 |
| 4 | **Rehearse the migration** (HD-9) | restore the latest production archive into `backup.restore_scratch_database`, then `php artisan migrate --database=mysql_migration --force --pretend` and for real against the scratch copy; then `integrity:verify --suite=constraints --suite=wallet` on the scratch copy | the exact release's migrations run clean on a copy of today's production data, and the money still reconciles. A red rehearsal cancels the deploy |
| 5 | Maintenance mode | `php artisan down --secret="<ops.maintenance_secret>"` | 503 everywhere; the operator can browse with the secret |
| 6 | Stop the worker | `nssm stop MyOfficeQueue` / `supervisorctl stop myoffice-queue:*` | no job runs against half-migrated schema |
| 7 | Swap | rename `"my office"` -> `"my office.previous"`, rename the staged release -> `"my office"` | `php artisan --version` from the new tree |
| 8 | Migrate | `php artisan migrate --database=mysql_migration --force --step` | `migrate:status` clean; the command's output is pasted into `DEVELOPMENT_LOG.md` §6 |
| 9 | Reference data | `php artisan db:seed --class=ProductionSeeder --force` | idempotent; new modules, permissions, roles and setting keys appear, nothing is deleted |
| 10 | Rebuild caches | `php artisan optimize` then `php artisan event:cache` and `php artisan permission:cache-reset` | `php artisan about` reports everything cached |
| 11 | Reload the SAPI | `Restart-Service Apache2.4` / `systemctl reload apache2` | new code is actually live despite `opcache.validate_timestamps=0` |
| 12 | Restart the worker | start the service; `php artisan queue:restart` | `ops:health` shows a fresh queue heartbeat |
| 13 | Stamp the version | `php artisan settings:set ops.app_version "<version>"` (or the deploy script writes it) | the health screen and the next `backup_runs` row carry the version |
| 14 | Smoke test behind maintenance | the secret URL: log in, open the dashboard, one index, one money screen, one public page | no 500, no console error, `X-Query-Count` inside budget |
| 15 | Up | `php artisan up` | the public site and five panels respond |
| 16 | Verify | `php artisan golive:check` | exit 0; any new blocker is fixed or the deploy is rolled back (§6.12) |
| 17 | Watch | `ops:digest` the next morning; `storage/logs` for one hour | no new error class, no lazy-load violation, no 429 spike |

`"my office.previous"` is kept for at least seven days, then archived. Disk is cheaper than a bad evening.

### 6.12 Rollback procedure, per phase

**The ladder, always in this order.** Most incidents stop at rung 1, which is why the module system exists.

| Rung | Action | Data risk | When |
|---|---|---|---|
| 1 | **Module kill-switch** - `admin.modules.toggle` off with a reason, or `php artisan module:disable <slug> --reason=""` | **none** (Phase 2 guarantees no data is touched; the toggle is audited) | A feature misbehaves. The module's routes 403 for everyone including Super Admin, its sidebar item disappears, and its data is untouched. This is the first move for 19 of the 25 phases |
| 2 | **Setting revert** - flip the offending key back; `SettingsService` logs old and new | none | A rule, rate, format, threshold or toggle was wrong |
| 3 | **Code rollback** - swap `"my office"` back to `"my office.previous"`, `optimize:clear`, rebuild caches, reload Apache, `queue:restart` | none **if** no migration ran | The release is wrong and the schema did not change |
| 4 | **`migrate:rollback --step=N`** | **medium** - only where the table below says "yes" | A structural defect in a migration that has written no business data yet |
| 5 | **Restore the pre-deploy backup** (§6.10.5) | **high** - everything written since the backup is lost | Nothing else can fix it. Requires the four authorization gates and a written decision |
| 6 | **Forward fix** - a new additive migration, a new release | none | Always preferable to rungs 4 and 5 once real data exists. For anything financial this is the **only** acceptable answer (HD-7, CLAUDE.md §1.3) |

| Phase | Primary rollback | `migrate:rollback` safe? | Kill switch | Notes |
|---|---|---|---|---|
| 1 Foundation, auth, RBAC | rung 3, then 6 | **no** - `users`, `roles`, `permissions` hold live identity from hour one | none (System modules are core) | A bad permission grant is fixed by re-running the idempotent seeders, never by dropping tables |
| 2 Settings, modules, dashboard | rung 2, then 3 | only the three additive columns, and only before any value is set | `dashboard` widgets hide themselves per permission | A bad setting is a one-click revert; `settings:reset <group>` restores registry defaults |
| 3 Website CMS | rung 1 (`website_sections`, `pages`, `menus`) | yes, before content is authored | disabling `public_site_enabled` shows the holding page | `PublicCache::bump()` after any rollback or the old HTML survives |
| 4 Services, portfolio, blog, careers | rung 1 per module (`services`, `portfolio`, `blog_posts`, `jobs`) | yes, before content exists | per-module | A disabled public module 404s (Phase 4's `site_module`), which is the correct visitor experience |
| 5 CRM, clients, client panel | rung 1 (`leads`, `clients`) | no once leads exist | `clients` off also closes the client panel | `lead_conversions` are history; never roll them back |
| 6 Projects, tasks, time | rung 1 (`projects`, `tasks`, `time_tracking`) | no once a project exists | per-module | Tasks reference employees and collaborators; a rollback would orphan time entries |
| 7 HR, attendance, payroll | rung 1, else 6 | **no** - `payroll_runs` are locked financial documents | `payroll`, `attendance`, `leaves` | A wrong payroll run is reversed by the phase's own reversal path, never by rollback |
| 8-9 Collaborators, referrals | rung 1 (`collaborators`), else 6 | **no** once a referral exists - a referral is attribution evidence | `collaborators` off also closes the collaborator panel and its commission routes | Suspending a collaborator is a business action, not a rollback |
| 10-12 **Commission engine, wallet, payouts** | **rung 6 only**; rung 1 as the emergency stop | **never.** The nine append-only tables, their triggers, their generated columns and their unique guards are the system's memory of money | `collaborator_commissions`, `collaborator_wallets`, `collaborator_payouts`, `student_fees`, `payments` - all 403 while data and queued jobs stay intact (spine §6.6 row 22) | A wrong commission is corrected by a `manual_adjustment` or a reversal with a written reason. A wrong rate is a new effective-dated version. A restore (rung 5) loses receipts that were taken after the backup and is a last resort requiring the client's written instruction |
| 13 Finance: invoices, expenses | rung 6 | no once an invoice is sent | `invoices`, `expenses`, `income` | Invoice numbers are gap-free; a rollback would reuse a number already printed |
| 14-17 Institute core | rung 1 per module | only `classrooms` / `timetable_entries` before use | `courses`, `students`, `batches`, `timetable`, `student_attendance` | Attendance and progress are evidence for certificates - forward-fix |
| 18 Fees, installments, discounts | **rung 6 only**; rung 1 as the stop | **never** (shares the spine's tables) | `student_fees`, `installments`, `fee_discounts`, `fee_reminders` | `student_fee_reminders` is the only table Phase 18 owns and it still may not be rolled back once a reminder was sent to a parent |
| 19 Materials, assignments | rung 1 | yes, before submissions exist | `course_materials`, `assignments` | A submission is a student's work; never destroy it |
| 20 Exams, results | rung 1, else 6 | no once a result is published | `exams`, `results` | A published result is corrected by an audited edit, not a rollback |
| 21 Certificates, ID cards | rung 6 | **no** - a certificate number is public and verifiable | `certificates` off disables the public verification page (state that to the client first) | Revoke a certificate through its own status, never by deleting it |
| 22 Tickets, meetings, messaging, notifications | rung 1 | yes, before messages exist | `support_tickets`, `messages`, `meetings`, `notifications` | A conversation is a record |
| 23 Reports, search, exports, audit | rung 3 | yes - it owns few tables | `reports`, `global_search` | A report is derived; rolling it back loses nothing |
| 24 Hardening | rung 3; the index-only migration of §2.5 **is** rollback-safe | yes | `system_health`, `integrity_checks` | Dropping an index never loses data; rolling back the three ops tables loses backup history, so do it only before go-live |
| 25 Deployment | rung 3 | yes, before the first backup row exists | `backups` is core and cannot be disabled | Never roll back `backup_runs` - it is the evidence that a restore point existed |

Every rollback, at any rung, writes: an activity-log entry with the reason, a `DEVELOPMENT_LOG.md` §6 entry
dated and naming the rung, and - for rungs 4 and 5 - the client's written instruction quoted in §9 of that
log. A rollback that nobody recorded did not happen, and the next person repeats the incident.

### 6.13 Go-live checklist

`php artisan golive:check` evaluates every **machine** row and prints every **manual** row as an explicit
tick. Exit code 2 while any blocker is open; the phase is not done, and the client does not go live, until it
exits 0. Grouped exactly as the screen renders it (§8.5).

| # | Group | Item | Command / evidence | Blocker |
|---|---|---|---|---|
| GL-01 | Environment | `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` set and not the example | machine: `config('app.*')` | yes |
| GL-02 | Environment | `APP_URL` is the real HTTPS host and matches the request host | machine | yes |
| GL-03 | Environment | Config, routes, views and events cached | machine: `php artisan about` | yes |
| GL-04 | Environment | No `env()` call outside `config/` | machine: DEP-04 scan | yes |
| GL-05 | Environment | `ops.app_version` stamped; it matches the deployed release | machine | no |
| GL-06 | Environment | PHP extensions present; opcache on with `validate_timestamps=0`; `max_input_vars >= 5000` | machine | yes |
| GL-07 | Security | HTTPS serves, HTTP 301s, no mixed content on home / admin / print | machine + manual | yes |
| GL-08 | Security | `security.force_https` on; HSTS on **after** GL-07 is green | machine | yes |
| GL-09 | Security | Every security header present on an authenticated and a public response | machine: SEC-06 | yes |
| GL-10 | Security | CSP enforcing (not report-only) and the report log clean for 48 hours | machine + manual | no |
| GL-11 | Security | `php artisan security:audit` exits 0 | machine | yes |
| GL-12 | Security | `audit:manifest --check` exits 0 - every route, upload and index accounted for | machine | yes |
| GL-13 | Security | Exactly one Super Admin; no account still on a seeded or demo password; every staff account `must_change_password` cleared by its owner | machine: DEP-06 | yes |
| GL-14 | Security | `/register` returns 404 (D15); password reset and verification throttled | machine | yes |
| GL-15 | Security | `.env` not web-reachable, `chmod 600`; `storage/logs`, `vendor/`, `composer.json`, `/docs`, `/.git` not web-reachable | machine: DEP-09 | yes |
| GL-16 | Security | Only `storage/` and `bootstrap/cache/` writable by the web user | machine | yes |
| GL-17 | Security | An uploaded `.php` in the storage alias is served as text or denied, never executed | manual (needs Apache) + DEP-08 | yes |
| GL-18 | Security | `composer audit` and `npm audit --omit=dev`: no high or critical advisory | machine | yes |
| GL-19 | Data | MySQL: three least-privilege users; root has a password; anonymous users dropped; the app user cannot migrate | machine: DEP-07 | yes |
| GL-20 | Data | `migrate:status` clean; `integrity:verify --suite=constraints` exits 0 (every CHECK, generated column, unique guard and delete trigger present) | machine | yes |
| GL-21 | Data | `integrity:verify --suite=schema`: every money column is `decimal(15,2)`, every `*_rate` / `*_percentage` `decimal(8,4)`, no float or double anywhere, and FIN-18's allowlist still holds exactly its three entries (`progress`, `rating`, `*_marks`) | machine: FIN-18 | yes |
| GL-22 | Data | Demo data **absent** from production; `demo:seed` refuses to run | machine | yes |
| GL-23 | Data | One default branch exists; `institute.default_branch_id` set (D11) | machine | no |
| GL-24 | Financial | The nine §120 tests green, by name | machine: `php artisan test --group=financial-120` | yes |
| GL-25 | Financial | `collaborators:reconcile-wallets` over every collaborator: zero drift, zero structural failure, closed identity holds | machine | yes |
| GL-26 | Financial | `fees:verify-plan-integrity` zero drift | machine | yes |
| GL-27 | Financial | The property suite green on the committed seeds **and** one fresh random seed | machine: FIN-15 | yes |
| GL-28 | Financial | Commission settings confirmed with the client in writing: base, approval mode, minimum payout, payout request on/off, fixed release mode | manual, recorded in `DEVELOPMENT_LOG.md` §9 | yes |
| GL-29 | Financial | `commissions:sweep`, `commissions:release-held`, `collaborators:reconcile-wallets`, `financial:verify-constraints` all listed in `schedule:list` | machine | yes |
| GL-30 | Performance | `perf:budget --all` exits 0 on the volume fixture | machine | yes |
| GL-31 | Performance | No lazy-loading violation in the suite; none logged in the last 24 hours of staging | machine | yes |
| GL-32 | Performance | Assets built, hashed, inside budget, no source maps; `build/` served with a one-year immutable cache | machine: PRF-11 | yes |
| GL-33 | Performance | Every index route paginates (PRF-05); exports stream | machine | yes |
| GL-34 | Backup | Database backup scheduled and a `completed` row exists | machine | yes |
| GL-35 | Backup | Files backup scheduled and a `completed` row exists | machine | yes |
| GL-36 | Backup | The latest database archive has `verification_status = restore_ok` (HD-6) | machine | yes |
| GL-37 | Backup | An offsite copy exists (`offsite_copied_at`) while `backup.offsite_required_for_go_live` is true | machine | yes |
| GL-38 | Backup | A full restore rehearsal into the scratch database was performed **by the client's operator**, timed, and the RTO recorded | manual, recorded | yes |
| GL-39 | Backup | Retention policy reviewed and the archive store has headroom below `max_storage_gb` | machine + manual | no |
| GL-40 | Operations | Queue worker running as a service, auto-restarting, heartbeat fresh | machine | yes |
| GL-41 | Operations | Scheduler running every minute, heartbeat fresh, all 20 commands listed | machine | yes |
| GL-42 | Operations | `failed_jobs` empty (or every row read and explained) | machine | yes |
| GL-43 | Operations | `/health` answers with the token and 404s without it; the client's monitoring is pointed at it | machine + manual | no |
| GL-44 | Operations | Error notifications reach a real person: `ops:digest` delivered, `backup.notify_emails` verified, SMTP test mail received | manual | yes |
| GL-45 | Operations | Log rotation verified: app dailies pruned at `ops.log_retention_days`, Apache logs rotating | machine + manual | no |
| GL-46 | Operations | Maintenance mode tested with the secret URL, and the 503 page is the branded one | manual | no |
| GL-47 | UX | The responsive matrix (§8.7) ticked for every screen at five widths in both themes | manual | yes |
| GL-48 | UX | The accessibility matrix (§8.8) ticked; `a11y:scan` exits 0; contrast pairs pass in both themes | machine + manual | yes |
| GL-49 | UX | Every error page (403, 404, 419, 429, 500, 503) is branded and leaks nothing | machine: SEC-37 | yes |
| GL-50 | Content | Company details, logo, favicon, currency PKR, timezone Asia/Karachi, date format, SEO defaults, sitemap, robots all set by the client | manual | yes |
| GL-51 | Sign-off | `DEVELOPMENT_LOG.md` §5 shows phases 1-25 `[x]` with test notes; §7 carries the dated results of every suite; §8 lists every accepted risk; §9 every client decision | manual | yes |
| GL-52 | Sign-off | The client names the people who hold Super Admin, `backups.restore` and `collaborator_payouts.approve`, and confirms the separation of duties | manual, recorded | yes |

---
## 7. Routes

Every admin route additionally carries `auth`, `active`, `panel:admin` from the `routes/admin.php` group
(Phase 1 §8). `module:*` is stated where it applies. New limiters are the names of §6.3.1.

### 7.1 Admin - backups (`module:backups`; the module is core, so it is never disabled)

| Method + URI | Route name | Middleware |
|---|---|---|
| `GET /admin/backups` | `admin.backups.index` | `can:backups.view_any` |
| `POST /admin/backups` | `admin.backups.store` | `can:backups.create`, `throttle:backup-run` |
| `GET /admin/backups/{backup}` | `admin.backups.show` | `can:backups.view` |
| `GET /admin/backups/{backup}/download` | `admin.backups.download` | `can:backups.download`, `signed`, `throttle:6,1` |
| `POST /admin/backups/{backup}/verify` | `admin.backups.verify` | `can:backups.create`, `throttle:integrity-run` |
| `DELETE /admin/backups/{backup}/file` | `admin.backups.file.destroy` | `can:backups.delete` |
| `GET /admin/backups/prune/preview` | `admin.backups.prune.preview` | `can:backups.delete` |
| `POST /admin/backups/prune` | `admin.backups.prune` | `can:backups.delete`, `throttle:3,60` |
| `GET /admin/backups/{backup}/log` | `admin.backups.log` | `can:backups.view_logs` |
| `GET /admin/backups/{backup}/restore` | `admin.backups.restore.create` | `can:backups.restore`, `password.confirm` |
| `POST /admin/backups/{backup}/restore` | `admin.backups.restore.store` | `can:backups.restore`, `password.confirm`, `throttle:backup-restore` |
| `GET /admin/backup-restores` | `admin.backup-restores.index` | `can:backups.view_any` |
| `GET /admin/backup-restores/{restore}` | `admin.backup-restores.show` | `can:backups.view` |

### 7.2 Admin - system health and integrity checks

| Method + URI | Route name | Middleware |
|---|---|---|
| `GET /admin/system-health` | `admin.system-health.index` | `module:system_health`, `can:system_health.view_any` |
| `GET /admin/system-health/probe/{probe}` | `admin.system-health.probe` | `module:system_health`, `can:system_health.view`, `throttle:30,1` |
| `GET /admin/system-health/go-live` | `admin.system-health.go-live` | `module:system_health`, `can:system_health.view_any` |
| `GET /admin/system-health/export` | `admin.system-health.export` | `module:system_health`, `can:system_health.export`, `throttle:export` |
| `GET /admin/integrity-checks` | `admin.integrity-checks.index` | `module:integrity_checks`, `can:integrity_checks.view_any` |
| `POST /admin/integrity-checks` | `admin.integrity-checks.store` | `module:integrity_checks`, `can:integrity_checks.create`, `throttle:integrity-run` |
| `GET /admin/integrity-checks/{run}` | `admin.integrity-checks.show` | `module:integrity_checks`, `can:integrity_checks.view` |
| `GET /admin/integrity-checks/{run}/export` | `admin.integrity-checks.export` | `module:integrity_checks`, `can:integrity_checks.export`, `throttle:export` |

### 7.3 Public / unauthenticated

| Method + URI | Route name | Middleware |
|---|---|---|
| `GET /up` | (framework) | none - bare liveness, returns 200 and nothing else |
| `GET /health` | `ops.health` | `health.token` (the `X-Health-Token` header or a signed URL), `throttle:health`; **404** when the token is wrong or `ops.health_endpoint_enabled` is false |
| `POST /csp-report` | `ops.csp-report` | `throttle:csp-report`, body capped at 8 KB, written to the `daily` log channel only when `security.csp_report_uri` points here |

No panel route (`collaborator.*`, `student.*`, `teacher.*`, `client.*`) is added by these phases.

### 7.4 Middleware aliases added (registered in `bootstrap/app.php`, additive to Phase 1 §6)

| Alias | Class | Scope |
|---|---|---|
| (global) | `SecurityHeaders` | every response |
| (global) | `ForceHttps` | every request |
| (global, after) | `NoStoreForAuthenticated` | authenticated HTML responses |
| `health.token` | `VerifyHealthToken` | `/health` |
| (in `auth` stack) | `EnforceSessionLifetime` | every authenticated request |
| (local/testing only) | `QueryBudgetGuard` | every request |
| `password.confirm` | Laravel's own | the restore routes |

---

## 8. UI screens

Three new admin screens, one new tab, and two verification matrices over everything that already exists.
All views `@extends('layouts.admin')` and live under `resources/views/admin/`; only the Phase 1 `x-ui.*` set
plus Phase 2's `x-ui.chart` are used. **These phases add no new base UI component.** Every list screen
carries, without exception: search, the filters listed, sortable headers (`x-ui.th-sortable`), pagination with
`x-ui.pagination-summary`, skeleton rows, an `x-ui.empty-state` with a primary action, `x-ui.confirm` on every
destructive button, and a toast on every write.

### 8.1 Backups register - `admin.backups.index`

| Aspect | Detail |
|---|---|
| Purpose | The operator's one screen: what restore points exist, whether they are proven, and where they live |
| Components | `x-ui.page-header` (with "Back up now"), `x-ui.stat-card` x4 (latest database backup age, latest verified restore point, total archive size vs `max_storage_gb`, offsite status), `x-ui.filter-bar`, `x-ui.table`, `x-ui.badge`, `x-ui.modal`, `x-ui.confirm`, `x-ui.empty-state`, `x-ui.skeleton` |
| Filters | type (database/files/full), status, trigger, verification status, date range (Phase 2 `DateRange`), "has offsite copy", "file still on disk" |
| Columns | Taken (relative + absolute, `tabular-nums`), Type, Status badge, Trigger, Size, Tables / Rows, Verification badge (with the date), Offsite tick, Retention class + `retention_until`, Taken by, Actions (Verify, Download, Restore, Prune file, View log) |
| Empty state | "No backups yet. The first one proves the second one." + a primary "Back up now" button and a link to the backup settings group |
| Row detail | A stat line per row showing `row_count_total` so a shrinking database is visible at a glance - the cheapest corruption alarm there is |
| Danger affordances | Restore is a **red** button with a tooltip naming the four gates; Prune is disabled with an explanatory tooltip on the newest usable database archive; "Back up now" requires a reason in its modal |
| Behaviour | "Back up now" posts and returns immediately with a toast ("Backup started - this page refreshes when it finishes"); the row polls `admin.backups.show` every 5 s while `status = running` (Alpine, max 2 minutes, then stops and says so) |

### 8.2 Backup detail - `admin.backups.show`

Purpose: the evidence for one restore point. Sections: identity (uuid, filename, disk, path, size, checksum,
encryption, whether `.env` is inside), content (table count, row count, the §6.10.4 proof list as a two-column
table, file count), timings, verification history (every `backup:verify` result with its notes), offsite copy,
retention, and the restores that used it. A `x-ui.tabs` splits Overview / Verification / Restores / Log. The
checksum is shown in full with a copy button; the archive password is **never** shown, only the fact that the
archive is encrypted.

### 8.3 Restore wizard - `admin.backups.restore.create` (3 steps)

| Step | Content | Guard |
|---|---|---|
| 1 Review | What will be overwritten: the target database name, its current row counts, the archive's row counts, and the delta per table, with every decrease highlighted in red. A plain-language warning naming the exact data that will be lost ("47 receipts and 12 commission entries recorded since this backup was taken will be gone"), computed from `created_at > backup.started_at` | `can:backups.restore` + `password.confirm`; the step refuses to continue when the archive checksum is unverified |
| 2 Reason and target | Target (local / staging / production - production preselected only when `APP_ENV=production`), scratch database name for non-production, a `min:20` reason textarea, and a checkbox acknowledging that a pre-restore backup will be taken automatically | Form Request |
| 3 Confirm | The typed confirmation phrase (`RESTORE my_office 2026-09-12`) with the expected text displayed above the input, and a final summary | the submit button stays disabled until the typed text matches exactly; the server re-checks it case-sensitively |

During the restore the screen shows a progress panel driven by `RestoreStatus` with the step list of §6.10.5;
on completion it renders the before/after row-count diff and the `proof` block (constraints, reconciliation,
pending migrations) as three pass/fail badges. Wizard steps stack vertically under `md`; the diff table
scrolls inside `overflow-x-auto`.

### 8.4 System health - `admin.system-health.index`

| Aspect | Detail |
|---|---|
| Purpose | One screen an operator can read in ten seconds, and the place Phase 2's `SystemHealthWidget` links to |
| Components | `x-ui.page-header`, `x-ui.stat-card`, `x-ui.card` per probe group, `x-ui.badge`, `x-ui.table`, `x-ui.tabs` (Probes / Integrity / Go-live), `x-ui.chart` (14-day failed-job and error counts), `x-ui.skeleton` per probe while its JSON loads |
| Probe groups | **Runtime** (PHP version + extensions, Laravel version, opcache on/off and hit rate, `APP_DEBUG`, `APP_ENV`, config/route/view/event cached, app version). **Database** (connection, server version, database size, table count, pending migrations, the constraint-verification result from the last `integrity:verify --suite=constraints`). **Queue** (driver, pending jobs, failed jobs, worker heartbeat age, oldest pending job age). **Scheduler** (heartbeat age, last run, next run, the 20 commands with their last outcome). **Storage** (disk free, `storage/` writable, `public/storage` link present, upload directory size). **Money** (last reconciliation run, collaborators checked, drift total, structural failures - read from `collaborator_wallet_reconciliations`, never recomputed here, INV-26). **Backups** (age of the newest database archive, newest verified restore point, offsite status). **Mail** (configured mailer, last send outcome) |
| Behaviour | Each probe is a card with a green/amber/red dot, the measured value, the threshold it is judged against, and a "what to do" line; probes load in parallel through `admin.system-health.probe` so one slow probe never blocks the page; a red probe expands to the remediation command |
| Empty state | Not applicable - a system always has a state. A probe that cannot run renders amber with "could not be measured" and the reason, never green |

### 8.5 Go-live checklist tab - `admin.system-health.go-live`

The 52 rows of §6.13 grouped by their group column, each row showing: status badge (pass / fail / manual /
waived), the evidence (a number, a date or a command output excerpt), the command to re-check it, and - for
manual rows - a tick-box that records who ticked it and when into the activity log with an optional note.
A blocker that is failing renders red and the page header shows "N blockers open"; zero blockers renders a
single green panel with the date and the name of the person who ran it. Waiving a blocker requires
`system_health.view_logs` plus a `min:20` reason and is recorded - the checklist can be overridden, but never
silently.

### 8.6 Integrity checks - `admin.integrity-checks.index` / `.show`

Index: filters (suite, status, triggered by, date range), columns (Run at, Suite, Scope, Status badge,
Checks passed/warned/failed, Duration, Triggered by, Actions). Empty state: "No checks have been run yet" +
"Run all checks". A "Run check" modal picks the suite and an optional scope. Detail: the header figures, then
`findings` as a table (code, severity, subject, expected, actual) with a copy-as-CSV action, the exact command
for reproduction, and - for the `wallet` suite - a link to each affected collaborator's wallet screen (Phase
12's), never a balance computed here.

### 8.7 Responsive verification matrix (§2 "fully responsive", Phase 24)

**Breakpoints.** Six widths, the first five mandatory for every screen, the sixth for print views only:

| Width x height | Why | Tailwind band |
|---|---|---|
| 360 x 640 | the smallest Android still in real use - where the sidebar drawer and the table scroller break first | `< sm` |
| 390 x 844 | modern phone (iPhone 14 class), with the safe-area inset | `< sm` |
| 768 x 1024 | tablet portrait - the band where two-column forms must collapse | `md` |
| 1024 x 768 | tablet landscape / small laptop - where the fixed sidebar appears | `lg` |
| 1440 x 900 | the working laptop | `xl` |
| 794 x 1123 | A4 at 96 dpi, for print views | print |

**Both themes, every width.** Light is the default; Dark and System are switched through the Phase 1 theme
switcher, and a screen is only ticked when both renders pass.

**Pass criteria, identical for every screen** (the first four are machine-checked by the Playwright sweep of
RSP-01 when the dev dependency is approved, otherwise manually):

1. `document.documentElement.scrollWidth <= window.innerWidth + 1` - **no horizontal page scroll**; wide
   content scrolls only inside its own `overflow-x-auto` container.
2. No console error, no 404 asset, no CSP violation.
3. No text clipped, no overlap, nothing under the notch / safe-area inset, nothing under a sticky bar.
4. Every interactive target at least 44 x 44 CSS px below `sm`.
5. Contrast passes in both themes (the `Contrast` assertion of §6.5 covers the palette; the sweep catches
   one-off inline colours).
6. The screen's own behaviour below, verified by hand.

| Group | Screens | Behaviour to verify below `lg` |
|---|---|---|
| Shell (every screen) | sidebar, topbar, breadcrumbs, toasts, confirm dialog, profile dropdown, notification panel, global search | Sidebar collapses to an off-canvas drawer with a visible trigger, a scrim, Esc to close and focus return; topbar collapses to icons; breadcrumbs truncate with an ellipsis, never wrap to three lines; toasts stack bottom-centre and do not cover the primary action |
| Auth | login, forgot, reset, verify, change-password, session list | Single column, no fixed-height card that cuts off on a 640-high screen, the keyboard does not cover the submit button |
| Dashboards (5) | admin, collaborator, student, teacher, client | The 12-column widget grid becomes one column; charts resize without overflow and keep their legend; the date-range selector becomes a bottom sheet; "Customize" mode is reachable or hidden deliberately |
| Index screens (the manifest's `kind = index`, ~45 of them) | users, roles, leads, clients, projects, tasks, employees, attendance, payroll, invoices, payments, expenses, collaborators, commissions, wallets, payouts, courses, students, admissions, batches, timetable, fees, installments, exams, results, certificates, tickets, meetings, blog, jobs, applications, inquiries, media, backups, integrity checks | Filter bar collapses into a "Filters" sheet showing the active-filter count; the table scrolls inside its container with a sticky first column where an identity column exists; row actions become a bottom sheet; pagination summary stays readable |
| Show / detail screens | client, project, collaborator, student, batch, invoice, fee charge, payout, wallet, certificate, ticket | Tabs become a horizontally scrollable strip or a select; two-column detail becomes one; long reference numbers wrap or truncate with a copy button |
| Forms and wizards | user, role permission matrix, lead, project, employee, invoice, fee structure (3 steps), installment plan (3 steps), payout (wizard), record payment (4 steps), restore (3 steps), admission, collaborator onboarding | Steps stack with a visible step indicator; the sticky save bar stays above the keyboard and never covers the last field; the permission matrix becomes an accordion per module group and stays usable at 360 px |
| Kanban | leads board, tasks board, applications pipeline | Horizontal scroll with CSS scroll-snap per column, the column header sticky; **a keyboard/menu alternative to drag exists** (A11Y-02) and is visible on touch |
| Calendar / timetable | timetable (daily, weekly, teacher, batch, classroom), meetings, blog calendar, attendance month | Week and month views collapse to an agenda list under `md` with the same filters, not a pinched grid |
| Modals | every `x-ui.modal`, record-payment, discount, refund, impact, restore confirm | Full-screen sheet under `sm`, internal scroll (never a page scroll behind a locked body), close button reachable by thumb, focus trapped |
| Print views | invoice, fee slip, receipt, salary slip, result card, certificate, ID card, statement, reports | A4 at 794 px: one page where intended, no clipped column, no dark-mode colours in print CSS, `print:` utilities verified with the browser print preview |
| Public site | home, services, service detail, portfolio, portfolio item, team, testimonials, courses, course landing, batches, blog index, post, careers, job, apply, contact, admission form, certificate verification, custom page, 404, holding, maintenance | Mobile menu drawer, hero image `srcset` picks the small width (check the network panel), forms single column, the `ref=` referral parameter survives a mobile submit (Phase 3 §6.7), no layout shift (the `<picture>` carries intrinsic width/height) |

### 8.8 Accessibility pass (§2, Phase 24)

Target: **WCAG 2.1 AA** on every screen of the manifest. Two tracks: the machine assertions of `a11y:scan`
(§6.5) over every screen, and a manual pass per group below.

| # | Area | Criterion | How it is verified |
|---|---|---|---|
| A-a | Keyboard | Every action reachable and operable by keyboard alone, in visual order; no keyboard trap; Esc closes every overlay; focus returns to the trigger | manual per group, Playwright for the modal/dropdown/drawer trio |
| A-b | Focus | A visible `focus-visible` ring on every control in both themes, never `outline: none` without a replacement | `a11y:scan` (CSS scan) + manual |
| A-c | Drag | **Every drag has a non-drag alternative**: the Kanban card menu ("Move to..."), the reorder rows' up/down buttons (Phase 3's four reorder surfaces), the dashboard customize mode's position select | manual; a failing row blocks go-live |
| A-d | Labels | Every input, select, textarea, toggle and file field has a programmatic label; every icon-only button has `aria-label`; every table has a caption or `aria-label` | `A11y::assertEveryInputLabelled`, `assertIconButtonsLabelled`, `assertTablesCaptioned` |
| A-e | Errors | Validation errors are associated (`aria-describedby`, `aria-invalid`), announced, and not conveyed by colour alone | `A11y::assertErrorsAssociated` + manual with a screen reader |
| A-f | Structure | One `<h1>`, no skipped heading level, landmarks present, one `<main>`, a skip-to-content link, `<html lang>`, a unique `<title>` per screen | `A11y` assertions over every manifest screen |
| A-g | Status | Toasts in an `aria-live="polite"` region; skeletons `role="status"`; a destructive confirm is `role="alertdialog"` with `aria-describedby` | `A11y::assertLiveRegions` |
| A-h | Colour | 4.5:1 for text, 3:1 for large text and UI boundaries, in **both** themes; status is never colour-only (every badge carries its label text) | `Contrast` over `contrast-pairs.php`; `a11y:scan` fails a badge with no text node |
| A-i | Motion | `prefers-reduced-motion: reduce` disables transitions, the skeleton shimmer and the Kanban animation | manual + CSS scan |
| A-j | Zoom / reflow | 200% browser zoom and a 320 px reflow width lose no content or function | manual, once per screen group |
| A-k | Charts and data | Every chart has a text alternative (an `sr-only` table of the same series) and does not rely on colour alone to distinguish series | `A11y::assertChartHasTextAlternative` |
| A-l | Forms that matter most | Login, admission, contact, record payment, payout request, restore confirm: completed end to end with the keyboard only and with a screen reader (NVDA on Windows) | manual, recorded with the tester's name and date |

---

## 9. Data isolation

Every rule is a policy check plus, where a query exists, a scope - never a hidden form field
(`CLAUDE.md` §1.10). The cross-panel isolation matrix for the **rest of the system** is this phase's test
subject, not its own scoping rule, and lives in §11.3 / §11.4.

| Role | Exact rule on this phase's routes |
|---|---|
| **Super Admin** | Unrestricted on `backups.*`, `system_health.*`, `integrity_checks.*`. Still subject to module gating: `system_health` or `integrity_checks` disabled 403s them too (the `backups` module is core and cannot be disabled) |
| **Admin** | `system_health.*` and `integrity_checks.view_any` / `.view` / `.export` in full. **403 on every `/admin/backups*` and `/admin/backup-restores*` route** - Phase 1 §5 excludes `backups.*` from Admin, and that exclusion is a deliberate separation of duties, not an oversight |
| **Accountant** | `integrity_checks` index and detail scoped by `IntegrityCheckPolicy::view()` to `suite IN ('constraints','wallet')` - the financial proof they need for §120 evidence. A `routes`, `isolation`, `security`, `uploads` or `performance` run returns **404, not 403**, so the existence of other suites is not even disclosed. No `backups.*`, no `system_health.*` |
| **HR, Project Manager, Developer, Designer, SEO Expert, Digital Marketer, Sales Executive, Receptionist, Support Agent, Institute Manager, Course Coordinator** | No permission from these phases -> **403** on every route here, and the sidebar shows nothing (Phase 1's `Sidebar` hides an item whose permission is absent) |
| **Collaborator, Student, Teacher, Client** | `panel:admin` denies before any permission is consulted -> 403 on every route here. No portal permission exists for backups, health or integrity, so none can be granted by accident |
| **Unauthenticated** | `/up` returns 200 and nothing else. `/health` returns **404** without a valid token (not 401, not 403 - the endpoint is not advertised), and with a valid token returns aggregate figures only: counts, ages, booleans, versions. **Never** a row, a name, an email, a balance, a path outside the base directory, or a credential |
| **Branch (D11)** | Irrelevant here: a backup, a health probe and an integrity run are system-wide. `integrity_check_runs.scope` may name a collaborator, never a branch-restricted subject, and the Accountant's scoping is by suite, not by branch |

Additional isolation rules specific to these phases:

| Rule | Enforcement |
|---|---|
| A backup archive is **never web-reachable** | the `backups` disk is `storage/app/backups`, outside the webroot; DEP-09 asserts a direct URL fails; the only way to an archive is `admin.backups.download`, which is a **signed** route (5-minute expiry) whose controller re-checks `backups.download` and streams through `Storage::download()` - never a path from the request |
| A download is bound to the requester | the signature covers the backup id and the user id; replaying another user's signed URL 403s |
| Path parameters are never filesystem paths | `{backup}` and `{restore}` are route-model bound by id; `path` is read from the model row only. Traversal payloads in any parameter 404 (SEC-16) |
| `row_counts_before/after` and `findings` hold no business content | validated on write: keys must match `/^[a-z_]+$/` (table names), values must be integers; `findings` entries carry ids and figures, never names, emails or amounts attributable to a person |
| A restore record outlives its actor | `requested_by` is `restrictOnDelete` and the model is never deletable |
| The raw dump log may contain table names | `backups.view_logs` is a separate ability from `backups.view`, and the log is scrubbed by `RedactSensitive` before it is stored or rendered |

---
## 10. Events, notifications, jobs, scheduled tasks

### 10.1 Events (all dispatched through `DB::afterCommit()`)

`BackupStarted`, `BackupCompleted`, `BackupFailed`, `BackupVerified`, `BackupVerificationFailed`,
`BackupFilePruned`, `BackupCopiedOffsite`, `RestoreRequested`, `RestoreStarted`, `RestoreCompleted`,
`RestoreFailed`, `IntegrityCheckCompleted`, `IntegrityCheckFailed`, `QueueWorkerStalled`, `SchedulerStalled`,
`FailedJobThresholdExceeded`, `GoLiveBlockerWaived`, `SecurityAuditFailed`.

These phases subscribe to, but never re-implement, the spine's `WalletDriftDetected` and
`CommissionGenerationFailed`: both become `ops:digest` lines and `system_health` red probes.

### 10.2 Listeners and observers

| Listener | On | Action |
|---|---|---|
| `RecordSpatieBackupOutcome` | spatie's `BackupWasSuccessful`, `BackupHasFailed`, `CleanupWasSuccessful`, `CleanupHasFailed`, `HealthyBackupWasFound`, `UnhealthyBackupWasFound` | fills the `backup_runs` row the service opened, so a backup started from the console, the UI or the scheduler always lands in the same table |
| `NotifyBackupFailure` | `BackupFailed`, `BackupVerificationFailed` | database + mail notification; cannot be disabled |
| `NotifyRestoreOutcome` | `RestoreCompleted`, `RestoreFailed` | notifies every Super Admin, with the before/after row counts in the payload |
| `FlagGoLiveBlocker` | `IntegrityCheckFailed`, `SecurityAuditFailed` | marks the matching `golive:check` row red until the next green run |
| `ReportCriticalError` | the framework's `MessageLogged` at `error` and above, plus `JobFailed` | tier-1 monitoring (§6.9.7); deduplicates by exception class + file + line for 15 minutes so one bad loop cannot send 4 000 notifications |
| `BackupRunObserver` / `BackupRestoreObserver` / `IntegrityCheckRunObserver` | `deleting`, `forceDeleting` | throw unconditionally - the DB triggers are the backstop, the observers are the readable error |

### 10.3 Notifications (database channel now, mail-ready - §97)

To holders of `backups.view_any`: `BackupFailedNotification`, `BackupSucceededNotification` (only when
`backup.notify_on_success`), `BackupVerificationFailedNotification`, `RetentionBlockedNotification` (the
`max_storage_gb` breach).
To every **Super Admin**: `RestoreCompletedNotification`, `RestoreFailedNotification`,
`GoLiveBlockerWaivedNotification`.
To holders of `system_health.view_logs`: `QueueWorkerStalledNotification`,
`SchedulerStalledNotification`, `FailedJobThresholdNotification`, `IntegrityCheckFailedNotification`,
`CriticalErrorNotification`, `OpsDigestNotification`.
Every notification names the run uuid and the command that reproduces it, and **no notification ever carries a
password, a token, an archive password, a DSN or a row of business data**.

### 10.4 Scheduled tasks

All times are `Asia/Karachi`. The first eight rows are the spine's and Phase 18's, restated here only so the
whole schedule can be read in one place and so `schedule:list` can be diffed against it (GL-41); they are
**not** redefined by these phases.

| Command | Cadence | Owner | Notes |
|---|---|---|---|
| `commissions:sweep` | every 10 min | spine | `withoutOverlapping` |
| `commissions:release-held` | daily 00:10 | spine | |
| `commission-rules:activate` | daily 00:05 | spine | |
| `fees:mark-overdue` | daily 01:00 | 18 | |
| `collaborators:reconcile-wallets` | daily 01:30 | spine | writes a reconciliation row per collaborator |
| `financial:verify-constraints` | daily 02:00 | spine | called by `integrity:verify --suite=constraints` |
| `fees:installment-reminders` | daily 09:00 | 18 | |
| `payouts:expire-stale-requests` | weekly Mon 06:00 | spine | never auto-rejects money |
| **`ops:heartbeat`** | every minute | 25 | stamps the scheduler heartbeat; dispatches `QueuePingJob` every 5th run; pings `ops.uptime_ping_url` |
| **`ops:check-heartbeats`** | every 5 min | 25 | queue + scheduler + failed-job thresholds; `withoutOverlapping` |
| **`ops:prune-logs`** | daily 00:30 | 25 | prunes app dailies beyond `ops.log_retention_days`; never deletes today's |
| **`integrity:verify --suite=all`** | daily 02:15 | 24 | after `financial:verify-constraints`; writes one row per suite |
| **`backup:run --type=database`** | `backup.database_schedule` / `database_time` (default daily 02:30) | 25 | skipped when `backup.enabled` is false; `withoutOverlapping`; `onFailure` -> notification |
| **`backup:run --type=files`** | `backup.files_schedule` / `files_time` (default weekly Sun 03:00) | 25 | |
| **`backup:prune`** | daily 03:30 | 25 | files only, never a row (§6.10.3) |
| **`backup:verify --latest`** | daily 04:00 | 25 | checksum; skipped when `backup.verify_checksum_daily` is false |
| **`backup:verify --latest --deep`** | weekly Sun 04:30 | 25 | the HD-6 restore proof into the scratch database |
| **`ops:prune-integrity-runs`** | weekly Sun 05:00 | 24 | nulls old findings, deletes nothing |
| **`security:audit --quiet`** | weekly Mon 05:30 | 24 | writes an `integrity_check_runs` row; notifies on a new finding |
| **`ops:digest`** | daily at `ops.error_digest_time` (07:00) | 25 | the tier-1 monitoring mail |

Every entry is `->withoutOverlapping()` and `->onOneServer()`-ready; every entry that writes a row is
idempotent for the day, so a missed night self-heals the next.

### 10.5 Queued jobs

| Job | Key properties |
|---|---|
| `RunBackupJob` | `ShouldBeUnique` (`backup:{type}`, `uniqueFor` 7200), `tries` 2, `backoff [300]`, `timeout` 3600, queue `low`; `failed()` closes the `backup_runs` row as `failed` with the exception class |
| `VerifyBackupJob` | `ShouldBeUnique` (`backup-verify:{id}`), `tries` 2, queue `low` |
| `PruneBackupsJob` | `ShouldBeUnique`, `tries` 1 - a prune is never retried blindly |
| `CopyBackupOffsite` | `ShouldBeUnique` (`backup-offsite:{id}`), `tries` 5, `backoff [60,300,900,1800,3600]` - a flaky network must not cost the offsite copy |
| `RunIntegrityCheckJob` | `ShouldBeUnique` (`integrity:{suite}:{scope}`), `tries` 1, queue `low` |
| `QueuePingJob` | trivial: stamps `ops.queue_heartbeat`; queue `high` so a congested `default` still proves the worker is alive |
| `PruneIntegrityRunsJob` | `tries` 1 |

Backups and integrity runs go to a **`low`** queue so a dump can never delay a commission job (which runs on
`high`), and the worker of §6.9.5 listens `--queue=high,default` with a separate single-process worker for
`low` where the client's hardware allows it (otherwise `--queue=high,default,low`, documented in the runbook).

---

## 11. Acceptance tests

Test files live in `tests/Feature/Security/`, `tests/Feature/Isolation/`, `tests/Feature/Financial/`,
`tests/Feature/Performance/`, `tests/Feature/Accessibility/`, `tests/Feature/Ops/`, plus optional
`tests/Browser/`. Every id below is a real test method name prefix, and every test either asserts a status
code **and** that nothing was written, or asserts a measured number against a stated budget.

**Suites, runnable one at a time:**

```
php artisan test --group=security        php artisan test --group=isolation
php artisan test --group=idor            php artisan test --group=financial
php artisan test --group=financial-120   php artisan test --group=concurrency
php artisan test --group=perf            php artisan test --group=a11y
php artisan test --group=ops             composer harden
```

Phase 24 **does not duplicate** the suites phases 5-23 already own; it adds the cross-cutting ones and, where
a phase's test already covers a matrix cell, its id is cited instead of rewritten (for example FT-46, PH18-31).

### 11.1 Security - injection, escaping, mass assignment, uploads

| # | Test | Assertion |
|---|---|---|
| SEC-01 | `test_every_state_changing_route_requires_a_csrf_token` | data provider = every POST/PUT/PATCH/DELETE route in `route-guard-manifest.php`; each, called with a valid session but no token, returns **419** and the target table's row count is unchanged. Asserts `VerifyCsrfToken::$except` is **empty** - a non-empty array fails the test and names the entry |
| SEC-02 | `test_session_cookie_flags` | the login response's session cookie has `HttpOnly`, `SameSite=Lax`, and `Secure` when `security.force_https`; `SESSION_ENCRYPT=true` so the raw `sessions.payload` contains no readable email; the session-management screen still lists device, IP and last activity (the columns are not encrypted) |
| SEC-03 | `test_stored_xss_is_escaped_everywhere_it_is_rendered` | for each of 24 representative free-text fields (lead name + notes, client name + address, contact person, project name + description, task title + comment, employee name, department, student name + guardian name, course name + short description, batch name, testimonial review, student review, success story, blog title + excerpt, job title, ticket subject + message, meeting title, payout notes, collaborator company, fee note, `settings.company.name`) store each of five payloads (`<script>alert(1)</script>`, `"><img src=x onerror=alert(1)>`, `<svg onload=alert(1)>`, `javascript:alert(1)`, `<iframe src=javascript:alert(1)>`) then GET **every** screen the manifest says renders it (admin index, admin show, the owning panel, the public site): the response contains the **escaped** form (`&lt;script&gt;`) and contains none of `<script`, `onerror=`, `onload=`, `javascript:` in an attribute position |
| SEC-04 | `test_rich_text_is_sanitised_on_write` | every rich-text field in the system - all of them written through **`App\Support\RichText::sanitize()`**, Phase 3's single sanitiser over `mews/purifier` (D25; there is no `HtmlSanitizer` class, resolutions F-2.5) - stored with `on*` handlers, `<script>`, `<style>`, `<iframe>`, `<object>`, `<form>`, `javascript:` and `data:text/html` URLs persists **without** them; an allowlisted tag survives; a row written directly to the DB with a script tag renders escaped when the view falls back to `{{ }}` |
| SEC-05 | `test_raw_blade_output_is_allowlisted` | a scan of `resources/views/**` finds every `{!! !!}` and every Alpine `x-html`; each occurrence must be listed in `tests/Support/raw-output-allowlist.php` naming the sanitiser that guarantees it - and **the only legal value of that column is `App\Support\RichText::sanitize()`** (D25, resolutions F-2.5), including for phase-21's print templates, which render through `PrintTemplateService` over `RichText`. A second sanitiser name in the allowlist is itself a failure, because a duplicated security control is a security defect. A new raw echo fails CI. Also asserts no `{{ }}` inside a `<script>` block (use `@json`) |
| SEC-06 | `test_security_headers_present` | on a public GET, an authenticated GET and a JSON response: `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`/`frame-ancestors`, `Permissions-Policy`, CSP (report-only or enforcing per setting), HSTS when enabled over HTTPS; `Cache-Control: no-store` on authenticated HTML; `X-Robots-Tag: noindex` on every panel route; **no** `X-Powered-By` |
| SEC-07 | `test_csp_blocks_inline_script_without_a_nonce` | the rendered layouts contain no inline `<script>` without `nonce`; the nonce changes per request; an injected inline script in stored content carries no nonce (so even a sanitiser bypass is inert under an enforcing CSP) |
| SEC-08 | `test_sql_injection_payloads_are_harmless` | for every index route in the manifest x every request parameter it accepts (`q`, `search`, `sort`, `direction`, `per_page`, `status`, `from`, `to`, `filter[*]`, every `*_id`) x eight payloads (`' OR 1=1 --`, `1; DROP TABLE users;--`, `%' UNION SELECT NULL--`, `\\'`, `0x27`, `" OR ""="`, `1 AND SLEEP(3)`, `../../etc/passwd`): the response is 200, 302, 404 or 422 - **never 500** - `users` and `collaborator_commission_ledger_entries` still exist with unchanged counts, and the request completes in under one second (no `SLEEP` executed) |
| SEC-09 | `test_sort_and_direction_are_allowlisted` | `?sort=password`, `?sort=(select 1)`, `?sort=users.password`, `?direction=; drop` are rejected or ignored; the executed SQL (captured with `DB::listen`) contains no user-supplied identifier; every controller resolves `sort` through an array allowlist, asserted by a scan |
| SEC-10 | `test_no_raw_sql_interpolation` | a scan of `app/` finds no `DB::raw`, `whereRaw`, `orderByRaw`, `selectRaw`, `havingRaw`, `fromRaw` or `DB::statement` whose argument contains `$` concatenation or interpolation of a request value; every permitted raw expression is in `tests/Support/raw-sql-allowlist.php` (the spine's reconciliation SQL, generated-column DDL, and the index-existence queries) and uses bindings |
| SEC-11 | `test_every_model_is_explicitly_fillable` | every class under `app/Models/**` that extends `Model`: `getFillable()` is non-empty **or** `getGuarded()` is a non-empty explicit list; `$guarded = []` anywhere fails, naming the file |
| SEC-12 | `test_forged_fields_are_ignored_or_rejected` | for each of 30 write routes, POST the legitimate payload plus the forged keys `id`, `created_by`, `updated_by`, `deleted_at`, `created_at`, `branch_id`, `user_id`, `status`, `is_system`, `is_core`, `level`, `email_verified_at`, `must_change_password`, `password`, `collaborator_id`, `collaborator_referral_id`, `commission_state`, `commission_rate`, `base_amount`, `amount`, `paid_amount`, `balance_amount`, `net_amount`, `receipt_no`, `invoice_number`, `idempotency_key`, `allocated_amount`, `signed_amount`, `permissions[]`: every one is either rejected (422) or absent from the stored row; the derived money columns keep the value the service computed |
| SEC-13 | `test_profile_update_cannot_escalate` | a suspended user's own profile POST with `status=active`, `role=Super Admin`, `branch_id=2`, `email_verified_at` and `must_change_password=false` changes none of them; a non-Super-Admin cannot grant themselves a permission through the role editor (`roles.edit` without `permissions.edit` 403s the permission sync) |
| SEC-14 | `test_controllers_validate_through_form_requests` | a scan: no controller passes `$request->all()`, `$request->input()` or `$request->except(...)` into `create`, `update`, `fill` or `forceFill`; every write action's first parameter is a Form Request class (allowlist for the three toggle endpoints that take no body) |
| SEC-15 | `test_upload_matrix` | for **every** row of `upload-manifest.php` (avatar, settings logo/favicon/og/login background, CMS media, page banner, client document, employee document, course material, assignment brief, assignment submission, CV, portfolio image, team photo, testimonial photo, student photo, ID card template, ticket attachment, **expense receipt** and **invoice PDF** - both on the private disk per §6.1 - payout proof, import CSV) reject: a PHP file renamed `.jpg` with a GIF magic header; `x.php.jpg`; `x.jpg.php`; `.phtml`; `.svg` (where the endpoint is an image endpoint - Phase 3 [D-W3-15]); `.html`; `.exe`; a null-byte filename; a 0-byte file; a file over `security.max_upload_mb`; a real PNG sent to a PDF-only field; a JPEG with PHP in its EXIF comment (stored, but EXIF stripped and never executable). Accept the legitimate type. Assert the stored name is a ULID (never the user's filename), the path contains no user input, and the original filename is rendered escaped |
| SEC-16 | `test_download_routes_resist_traversal_and_idor` | every download/stream route with `../../.env`, `..%2f..%2f.env`, an absolute path, a URL-encoded null byte, and another tenant's id: **404**, nothing streamed, and an activity row for the refusal where the route is financial. Each download re-checks its permission **and** its ownership predicate before the first byte |
| SEC-17 | `test_storage_directory_cannot_execute_php` | `storage/app/public/.htaccess` exists and contains the `FilesMatch` deny + `RemoveHandler`/`SetHandler none` directives of §6.9.1; a file written there with a `.php` extension is refused by the upload layer in the first place; the Apache-level proof is manual (GL-17) and recorded |
| SEC-18 | `test_password_policy_and_history` | `security.password_min_length`, complexity, the compromised-password rule (Laravel's `Password::uncompromised()` where network allows, else `min` + `mixedCase` + `numbers` + `symbols`), reuse of the last `security.password_history_count` hashes refused, `password_changed_at` stamped, other sessions invalidated |
| SEC-19 | `test_role_permission_matrix_posts_completely` | the role editor posts the **full** permission set (several hundred checkboxes); every checked permission is persisted - the guard against PHP's `max_input_vars` silently truncating the array (§6.9.7). Asserts `ini_get('max_input_vars') >= 5000` in the ops health probe and fails the test when the count of submitted inputs exceeds 80% of the limit |

### 11.2 Security - authorization, sessions, rate limits, secrets

| # | Test | Assertion |
|---|---|---|
| SEC-20 | `test_every_route_is_accounted_for` | `audit:manifest --check` exits 0: every route in `Route::getRoutes()` has a `route-guard-manifest.php` row; every row's route still exists; every route without a `can:` middleware **and** without a policy call carries a written `rationale`. The failure message lists the offenders |
| SEC-21 | `test_authorization_on_every_route` | data provider = the manifest. For each route: (a) a user holding **no** permission gets 403 (or 404 where the convention is 404) and nothing is written; (b) a user holding **exactly** the declared permission gets 200/302 - never 403, which catches a route guarded by the *wrong* permission; (c) an unauthenticated request redirects to login (or 404 for panel-scoped ids); (d) for every write route, the 403 case asserts the row count is unchanged |
| SEC-22 | `test_panel_middleware_precedes_permission` | a Student holding (impossibly) `leads.view_any` still gets 403 on `/admin/leads` because `panel:admin` denies first; the reverse - an Admin with no `collaborator_portal.*` - gets 403 on `/collaborator` |
| SEC-23 | `test_module_gating_denies_everyone_and_preserves_data` | for **every** non-core module slug: disable it, assert its routes 403 for Super Admin, its sidebar item is absent, its API/AJAX endpoints 403, its public counterpart 404s (Phase 4's `site_module`); re-enable and assert row counts for its tables are identical before and after; assert its scheduled commands and queued jobs still ran while it was off (spine §6.6 row 22) |
| SEC-24 | `test_inactive_and_suspended_users_are_stopped_immediately` | suspending a logged-in user takes effect on the **next request** (`EnsureUserIsActive`): logged out with a named message, a `blocked` login-history row; the same for `inactive`; `must_change_password` forces the change-password screen and blocks every other route |
| SEC-25 | `test_session_fixation_and_revocation` | the session id changes on login and on logout; revoking one session from the session-management screen deletes its `sessions` row and the next request from that cookie is unauthenticated; "revoke all others" leaves exactly the current one; a password change invalidates every other session and the remember-me cookie |
| SEC-26 | `test_session_timeouts` | idle beyond `security.session_lifetime` redirects to login; active use beyond `security.session_absolute_lifetime_hours` also logs out (`EnforceSessionLifetime`) with a `logout` history row reason `absolute_timeout`; `session_single_device` on makes a second login kill the first; the login-attempt settings and the limiter agree (one source of truth, §5.3) |
| SEC-27 | `test_rate_limiters_fire_and_are_styled` | every limiter of §6.3.1: the documented attempt returns **429**, the response is the branded page (site layout for public, panel layout for authenticated), it names a wait time, it contains neither the limiter key nor another user's email, and the underlying action did **not** happen. Login throttling is keyed on email+IP so one attacker cannot lock out the whole company. A `Retry-After` header is present |
| SEC-28 | `test_permission_revocation_takes_effect_next_request` | revoking a permission mid-session 403s the next request (spatie cache reset on write); granting one works without a re-login; the cached sidebar for that user rebuilds (the key carries the permission hash) |
| SEC-29 | `test_password_confirmation_gates_the_restore` | `admin.backups.restore.create` and `.store` redirect to the password-confirm screen when the confirmation is older than the window; a forged `confirmed_at`/`password_confirmed_at` in the request body is ignored; the typed phrase is compared case-sensitively server-side |
| SEC-30 | `test_registration_is_absent` | `/register` returns 404 (D15); no route name contains `register`; a POST to `/register` is 404, not 405 |
| SEC-31 | `test_signed_urls_cannot_be_replayed_or_shared` | an expired signature 403s; a tampered signature 403s; another user's signed download URL 403s (the signature covers the user id); the same for Phase 21's certificate links where they are signed |
| SEC-32 | `test_idor_sweep` | data provider = every route in the manifest whose `idor.owner` is non-null, over **four pairs** of tenants (two clients, two students, two collaborators, two teachers): request the foreign id as each tenant -> **404** (never 403, never 200); write routes additionally assert the foreign row is byte-identical afterwards; sequential id enumeration over 20 ids returns 404 for every id not owned; the owner's own id returns 200 |
| SEC-33 | `test_relationship_ids_in_payloads_are_validated_against_the_owner` | posting another tenant's `client_id`, `project_id`, `student_id`, `collaborator_id`, `batch_id`, `invoice_id`, `student_fee_id` or `payout_account_id` into a create/update request is rejected (422) because the rule is `Rule::exists()->where(owner)` - not silently accepted and not 500 |
| SEC-34 | `test_no_sensitive_data_in_urls` | a scan of every route definition and every `route()`/`action()` call: no path or query parameter named `password`, `token` (except signed-URL signatures), `email`, `cnic`, `account`, `secret`, `dsn`; the health token is a header or a signature, never a query string; search terms are allowed |
| SEC-35 | `test_secrets_never_reach_a_response_or_a_log` | crawl every manifest screen as a Super Admin and assert the body contains none of: the `APP_KEY`, the DB password, the decrypted `mail.password`, `backup.archive_password`, `ops.health_check_token`, `ops.maintenance_secret`, a decrypted payout account number (only `masked_account`), any `*_encrypted` column value, or an absolute filesystem path above `public/`. Then trigger a handled and an unhandled exception and assert the same over the log line after `RedactSensitive` |
| SEC-36 | `test_debug_mode_leaks_nothing` | with `APP_DEBUG=false`, a forced exception renders the branded 500 page: no stack trace, no SQL, no file path, no vendor name; the exception **is** in the log with its trace; `APP_DEBUG=true` in a production-like env is a `security:audit` finding |
| SEC-37 | `test_error_pages_are_branded_and_silent` | 403, 404, 419, 429, 500, 503 each render the Blade error view in both themes, contain no framework branding or trace, keep the layout (no broken CSS because the asset URL was wrong), and - for 419 - offer a "refresh and try again" action |
| SEC-38 | `test_dependency_advisories` | `composer audit` and `npm audit --omit=dev` report no high or critical advisory; the test records the output into the `integrity_check_runs` findings so a newly published advisory is visible the next morning |
| SEC-39 | `test_env_is_not_reachable_and_not_committed` | `GET /.env`, `/.env.example`, `/.git/config`, `/composer.json`, `/composer.lock`, `/package.json`, `/vendor/autoload.php`, `/storage/logs/laravel.log`, `/database/`, `/docs/requirements.md`, `/tests/` all fail (403/404); `.env` has no world-read bit; `.gitignore` excludes `.env`, `storage/app/backups`, `public/build` is committed-or-built deliberately (stated either way) |
| SEC-40 | `test_mail_and_smtp_credentials_are_protected` | `mail.password` is unreadable in the raw `settings` row, decrypts through the repository, never appears in the rendered settings form (masked), never in the activity log (logged as `[encrypted]`), and the test-mail failure message does not echo it (Phase 2's test, re-asserted here as a cross-cutting secret) |

---
### 11.3 The five-panel isolation matrix (§112)

`tests/Feature/Isolation/PanelMatrixTest.php`. The fixture seeds one user per role (18 of them) plus a second
user for each of the four portal roles, and a two-branch institute. **Expected = the exact HTTP status**, and
every non-2xx row additionally asserts that no row was written and that the response body carries none of the
forbidden column names listed for that panel.

| # | Test | Assertion |
|---|---|---|
| ISO-01 | `test_panel_entry_matrix` | 5 panels x 22 users = 110 cells. A role reaches **only** its own panel: every other panel returns 403 (panel users) or 403 (staff hitting a portal). Super Admin reaches `/admin` and is 403 on the four portals - a Super Admin is not a collaborator. A user holding two panel roles (teacher + collaborator) reaches exactly those two and `primaryPanel()` is deterministic |
| ISO-02 | `test_client_panel_isolation` | Client A over every `client.*` route: sees only `client_id = A` projects, tasks (`is_client_visible`), milestones, invoices (never `draft`), `project_payments`, documents (`visible_to_client`), files, meetings, tickets, messages (by participant), notifications. B's id on any of them = **404**. The body contains none of `budget`, `estimated_hours`, `actual_hours`, `collaborator_id`, `collaborator_referral_id`, `commission_state`, `commission_skip_reason`, `commission_rate`, `base_amount`, internal notes (Phase 5 §9.2, spine §9) |
| ISO-03 | `test_student_panel_isolation` | Student A over every `student.*` route: own profile, admission, batch, timetable, attendance, charges, installments, receipts, slips, materials, assignments, submissions, exams, results, certificate, notifications. B's id = **404** on all of them. The body contains none of `collaborator_id`, `collaborator_referral_id`, `commission_state`, `commission_skip_reason`, `commission_skip_detail`, `base_amount`, `commission_rate`, another student's name, or any ledger figure (Phase 18 §9). The fee slip's student copy names the collaborator but carries no commission figure (PH18-29 re-asserted) |
| ISO-04 | `test_collaborator_panel_isolation` | Collaborator A over every `collaborator.*` route: dashboard, referred students, student fee status, own student commission, projects, project payments, own project commission, tasks, files, comments, meetings, messages, payout accounts, payouts, statement. B's ledger entry, wallet, payout, allocation, account, referral, statement or payment row = **404**. A's lists never contain B's rows. The global scope `BelongsToAuthenticatedCollaborator` is asserted active on all 11 models **and both payment tables** (spine §9) |
| ISO-05 | `test_collaborator_money_columns_are_permission_gated` | without `collaborator_portal.student_commission` no student-commission figure appears **in the body**; without `.project_value` no project value; without `.project_payments` no payment amount; without `.project_client` no client name; without `.statement_download` the statement route 403s; without `.payout_request` a payout POST 403s **even when `collaborator.payout_request_enabled` is true** (spine FT-48) |
| ISO-06 | `test_teacher_panel_isolation` | Teacher A sees only batches where `batches.teacher_id = A` (or a `course_teacher` row), only those batches' students, only their own timetable, attendance, materials, assignments, exams and results. Another teacher's batch, student, assignment or result = **404**. A teacher is **403 on every financial route in the system** - fees, receipts, invoices, commissions, wallets, payouts (Phase 18 §9, spine §9) |
| ISO-07 | `test_staff_role_scoping` | Receptionist: may create a receipt and an inquiry, 403 on refund, void, discount, cancel, payroll, commission, wallet, payout, backup. Course Coordinator: no `fee_discounts.*`, no `collaborator_*`. HR: 403 on every institute and finance route. Accountant: read everywhere in finance, 403 on `leads.*`, and cannot approve a payout they created. Sales Executive: whole lead pipeline, no payroll, no fees. Each assertion names the permission that is absent |
| ISO-08 | `test_branch_scoping` (D11) | With two branches and a user whose `users.branch_id = 1`: every institute query returns only `branch_id = 1 OR branch_id IS NULL`; a branch-2 student, charge, receipt, batch or timetable entry is **404**; the fee-structure generator stamps `branch_id` from the student, never from the form; collaborator ledger, wallet and payouts are **global** (a collaborator is not branch-bound) |
| ISO-09 | `test_pipeline_visibility_scope` | A user with `leads.view` but not `leads.view_any` sees only leads where `assigned_to` or `created_by` is them, and their lead's activities, follow-ups and conversions by id are 404 (Phase 5 [D-P5-8]) |
| ISO-10 | `test_global_search_respects_every_scope` | the same query string run as Super Admin, Accountant, Receptionist, Teacher, Collaborator A, Student A and Client A returns strictly decreasing, non-overlapping result sets; no result leaks a row the role could not open; clicking every returned result yields 200 (never 403/404), which catches a search index that outruns its scopes |
| ISO-11 | `test_notifications_and_messages_are_personal` | a notification is visible only to its `notifiable`; marking one read re-asserts ownership; a conversation is scoped by `conversation_participants.user_id`, **not** by `client_id` - two contacts of the same client do not share a private conversation (Phase 5 §9.2) |
| ISO-12 | `test_exports_and_prints_carry_the_same_scope_as_the_screen` | for every export and print route: the file produced as Collaborator A, Student A, Client A and a branch-scoped Institute Manager contains exactly the rows their screen showed - no more - and no withheld column. A CSV is parsed and compared row-for-row against the screen's query, so an export that bypasses a scope fails loudly |

### 11.4 Horizontal privilege escalation (the same tenant class, two tenants)

| # | Test | Assertion |
|---|---|---|
| ESC-01 | `test_client_a_cannot_act_as_client_b` | A posts B's `project_id` into a comment, a file upload, a ticket, a meeting acceptance and an invoice view: 422 or 404, nothing written, B's rows untouched. A's session cookie replayed with B's `client_id` in a hidden field changes nothing - the context comes from the session (`ClientContext`), never the request |
| ESC-02 | `test_student_a_cannot_act_as_student_b` | A submits an assignment against B's `assignment_id`/`enrollment_id`, opens B's receipt, downloads B's certificate, marks B's attendance: 404/422, nothing written. A cannot pay against B's charge |
| ESC-03 | `test_collaborator_a_cannot_reach_b_money` | A requests a payout naming B's `payout_account_id`, approves its own payout, posts a ledger adjustment, downloads B's statement, or passes `collaborator_id=B` in any payload: 403/404/422, **zero ledger rows written**, and `assertWalletMatchesLedger(A)` and `(B)` both still pass |
| ESC-04 | `test_teacher_a_cannot_reach_b_batch` | A marks attendance, uploads material, creates an assignment or enters a result against B's batch: 404, nothing written; A cannot read B's students' contact details |
| ESC-05 | `test_employee_cannot_read_another_employee_payroll` | a Developer reading another employee's salary structure, slip, advance or document: 403/404; their own slip is visible only when the phase grants it; an HR user can, and the difference is a permission, not a role string |
| ESC-06 | `test_vertical_escalation_is_impossible_too` | no non-Super-Admin can: grant themselves a role or permission, toggle a module, edit SMTP settings, read the backup register, restore a backup, approve their own payout, or change a user's status to `active` for themselves. Each is 403 and each leaves an activity row for the attempt where the route is financial or administrative |

### 11.5 Financial integrity (§110, §120)

`tests/Feature/Financial/`. **Every** money test ends with the spine's shared helper
`assertWalletMatchesLedger($collaborator)`, which runs all eight reconciliation checks of spine §6.5.3, so the
nightly proof and the suite share one implementation (spine §11). Phase 24 does not rewrite the spine's or
Phase 18's tests - it **pins** them (FIN-01), adds the cross-cutting ones (FIN-10..FIN-20), and makes the whole
set a named, runnable suite.

#### 11.5.1 The nine requirement tests (§120), pinned

| # | Test | Assertion |
|---|---|---|
| FIN-01 | `test_the_nine_requirement_tests_exist_and_are_grouped` | The suite `--group=financial-120` contains **exactly** these nine method names, each present in both `tests/Feature/Financial/` (service level, spine FT-01..FT-09) and, for the six it covers, `tests/Feature/Institute/Fees/` (HTTP level, PH18-01..PH18-06): `test_student_without_collaborator_creates_no_commission_row`, `test_student_payment_creates_one_commission_at_configured_rate`, `test_same_fee_payment_processed_twice_creates_one_commission`, `test_three_installments_create_three_commissions`, `test_student_refund_creates_negative_reversal_and_preserves_original`, `test_project_without_collaborator_creates_no_commission`, `test_project_payment_creates_commission_at_configured_rate`, `test_project_payment_refund_creates_reversal_entry`, `test_payout_of_20000_against_50000_wallet`. A missing or renamed test **fails this test by name** - the §120 suite can never be quietly reduced |
| FIN-02 | §120.1 | student with no referral pays 10,000 -> `assertDatabaseCount('collaborator_commission_ledger_entries', 0)`; **no zero-amount row** (`where amount = 0` is empty); no entitlement row; payment `commission_state = skipped`, `commission_skip_reason = no_referral`; the charge still reaches `paid` and the receipt still prints |
| FIN-03 | §120.2 | 10% rule, base `paid`, receipt 10,000 -> exactly one row: `amount 1000.00`, `entry_type credit`, `purpose student_commission`, `base_amount 10000.00`, `commission_rate 10.0000`, `commission_base paid`, `transaction_date = paid_on`, `rule_snapshot` non-empty, `status pending` (manual mode, D-Q5); wallet `pending 1000.00`, `available 0.00` |
| FIN-04 | §120.3 | the same receipt POSTed twice with one `idempotency_key` -> one receipt; the service called twice and the job dispatched twice -> **one** ledger row, the second returning `created: false`; a raw duplicate INSERT throws on `uq_cle_dedupe`; one with a hand-made `dedupe_key` throws on `uq_cle_source` |
| FIN-05 | §120.4 | three 10,000 receipts on three installment lines -> three `1000.00` credits with distinct `student_fee_payment_id` **and** `student_fee_installment_id`; one entitlement with `collected_amount 30000.00`; wallet `3000.00` |
| FIN-06 | §120.5 | full refund after a 1,000 commission -> a new `amount 1000.00`, `entry_type debit`, `signed_amount -1000.00`, `purpose reversal` row carrying `reverses_entry_id` and `payment_reversal_id`; the original re-fetched is **byte-identical** on `amount`, `commission_rate`, `base_amount`, `rule_snapshot`; both rows `reversed`; wallet back to `0.00`; **nothing deleted** |
| FIN-07 | §120.6 | project with no referral receives 100,000 -> zero ledger rows, `commission_skip_reason = no_referral` |
| FIN-08 | §120.7 | 15% rule, base `paid`, payment 100,000 -> one `15000.00` credit, `purpose project_commission`. Base `total_value` variant on a 200,000 project: entitlement `30000.00`, the first 100,000 releases `15000.00`, the second releases exactly the residual `15000.00` and **no more** |
| FIN-09 | §120.8 | refunding the 15,000-commission payment -> a `-15000.00` reversal referencing the original and the `payment_reversals` row; original preserved |
| FIN-10 | §120.9 | one 50,000 `available` credit, a 20,000 payout approved and paid -> wallet `available 30000.00`, `paid 20000.00`, `reserved 0.00`, `lifetime 50000.00`; the closed identity holds; the payout row **and** its allocation still exist; the entry is still `available` with `allocated_amount 20000.00` |

#### 11.5.2 Concurrency, rounding, reversal, payout, reconciliation

| # | Test | Assertion |
|---|---|---|
| FIN-11 | `test_concurrent_workers_create_one_commission` (`@group concurrency`) | `Tests\Support\Concurrency::run(8, ...)` starts **eight real OS processes** with `Illuminate\Process::pool()`, each running `php artisan commissions:evaluate --payment={id}` against the test database, all polling a `test_barriers` row until a shared `start_at` so they collide deliberately (portable on Windows - no `pcntl`). Result: exactly **one** ledger row, no unhandled exception in any worker, no deadlock after the spine's 3 attempts, `assertWalletMatchesLedger()` passes. The same harness is run for: two workers on two different payments sharing one entitlement; 20 parallel receipt posts on one charge (20 distinct `receipt_no`, no gap - PH18-22); two concurrent payout creations against one entry (disjoint allocations, `SUM(allocated) <= amount` - FT-25); a payout `markPaid` raced twice (the second fails on `UNIQUE (method, transaction_id)`); two concurrent attribution changes on one student (one wins, `uq_cr_student_current` holds) |
| FIN-12 | `test_money_rounding_is_exact` | `Money::percentage`, `add`, `sub`, `mul`, `div`, `distribute` over 10,000 pseudo-random pairs from a fixed seed: every result is a string with exactly two decimals; every result equals the bcmath half-up reference computed at scale 6 then rounded; `distribute($amount, $parts)` always sums **exactly** to `$amount` as a string compare for parts 1..60 and amounts `0.01`..`9999999.99`; `percentage('3333.33','10.0000') === '333.33'`, `percentage('10000.00','10.0000') === '1000.00'`, `percentage('0.04','10.0000') === '0.00'`. Also asserts no `float` cast occurs anywhere in `Money` (a reflection + static scan) |
| FIN-13 | `test_reversal_scenarios` | the full matrix, each ending in `assertWalletMatchesLedger()`: partial refund (one `-400.00` for a 4,000 refund of a 10,000 receipt with a 1,000 commission; original still `available`, `reversed_amount 400.00`, available `600.00`); three partial refunds `3,333 / 3,333 / 3,334` -> `333.30 / 333.30 / 333.40`, **sum exactly 1000.00**; a refund larger than the receipt refused by validation **and** by `chk_sfp_refund_ceiling`; a void before any refund (full-amount `void` reversal, charge back to `pending`, not `refunded`); a reversal arriving **before** its earning and the earning arriving first - identical final wallet in both orders (FT-18); a clawback after payout (original stays `paid`, a negative `available` debit, wallet legitimately negative, a new payout refused, a later 5,000 earning leaves `4000.00`); a `write_off` instead (clawback posted `cancelled`, available 0.00); a refund while an in-flight payout holds the allocation (allocation released `released_for_reversal`, payout recomputed or cancelled with a reason, **then** the reversal posts) |
| FIN-14 | `test_payout_scenarios` | request below `collaborator.minimum_payout` refused with the figure named; request above `available` refused with the shortfall named; a collaborator without `collaborator_portal.payout_request` 403s even with the setting on; approve-by-the-creator refused when both abilities are held (spine §9); reject releases allocations and restores `available` to the paisa; `markPaid` twice fails; `cancelAfterPayment` walks entries `paid -> available`, releases allocations with `payout_returned`, **keeps the payout row**, and writes an audit row with the reason; a payout is refused while the wallet is negative; a soft-deleted collaborator's payout is refused but their **reversals still post** (spine §6.6 row 2) |
| FIN-15 | `test_wallet_always_equals_the_ledger` (`@group financial`, the property test) | `WalletLedgerPropertyTest` builds a world of 5 collaborators, 10 students with admissions and charges, 5 projects and 3 branches, then applies **200 operations** drawn by a seeded PRNG from: issue charge, build installment plan, collect full payment, collect partial payment, collect overpayment, apply discount, apply scholarship, apply waiver, refund partially, refund fully, void a receipt, transfer a payment between charges, approve commission, reject commission, post a manual adjustment, post a write-off, request payout, approve payout, pay payout, reject payout, cancel a paid payout, change attribution with a reason, add a commission rule version, suspend a collaborator, reinstate a collaborator, disable the commission module and re-enable it, and a back-dated receipt inside the window.<br>**After every single operation** it asserts: all eight reconciliation checks (R1-R8), the closed identity `lifetime = pending + available + reserved + paid`, `reversed_amount + clawed_back_amount <= amount` on every entry, `allocated_amount + reversed_amount <= amount` on every entry, `released_amount <= entitlement_amount` on every entitlement, that the ledger row count **never decreased**, and that no row's money columns changed since the previous step (a hash of `id, amount, commission_rate, base_amount, rule_snapshot` per row).<br>At the end it asserts `SUM(signed_amount)` over all ledger rows reconciles to the sum of every wallet's `lifetime_earned`, and that `CollaboratorStatementService` balances for three random date windows per collaborator.<br>Seeds: the committed list `[1, 42, 1337, 20260912, 999983]` always runs; CI additionally runs **one fresh random seed** per build and prints it. A failure prints the seed **and** the operation log, and that seed is committed as a permanent regression case |
| FIN-16 | `test_no_float_and_no_second_balance_source` | static scans, failing with file and line: no `+ - * /` or `round()`/`floatval()`/`(float)`/`number_format()` applied to a money attribute or a `decimal:2` cast anywhere in `app/Services/{Finance,Institute,Collaborator,Ops}`, `app/Models/**`, `app/Http/Controllers/**`, `app/Dashboard/**` or `resources/views/**` (spine INV-7, FT-41); no `SUM(` over a ledger, entitlement, allocation or payout table outside `CollaboratorWalletService` / `CollaboratorStatementService` / `CommissionReconciliationService` (INV-26, FT-42); no `Cache::` write whose value is a balance (§6.4.1); no Phase-24/25 file writing to any of the 15 financial tables (HD-2) |
| FIN-17 | `test_financial_immutability_is_enforced_by_the_database` | as the **runtime app user** (§6.9.3): a raw `DELETE` on each of the nine append-only tables raises SQLSTATE 45000; `$entry->delete()` and `forceDelete()` throw; an `update` of `amount`, `commission_rate`, `base_amount`, `rule_snapshot`, `dedupe_key` or `source_id` throws `ImmutableLedgerAttributeException`; a payment `update` of `amount`/`paid_on`/`student_fee_id` is refused by the policy; `CollaboratorCommissionLedgerEntry::create()` outside `LedgerWriter` throws `DirectLedgerWriteException`; none of the nine tables has a `deleted_at` column |
| FIN-18 | `test_money_columns_are_decimal_everywhere` | a query over `information_schema.COLUMNS` for the whole schema: every column whose name matches `amount|fee|salary|price|budget|balance|total|paid|discount|tax|value|commission` is `decimal(15,2)` (or `decimal(8,4)` for a `rate`/`percentage`/`_pct` name), **never** `float`, `double`, `real`, `int` or `varchar`; every `*_rate` / `*_percentage` is `decimal(8,4)` with **no "reported percentage" exception** (resolutions F-7.1). The allowlist file is **closed to exactly three entries**, each with its one-line reason:<br>• `progress` - `unsignedTinyInteger`: a 0-100 step counter, not a measured rate<br>• `rating` - `unsignedTinyInteger`: a 1-5 star value, not a rate<br>• **`*_marks`** - `decimal(8,2)`: marks are not money and not percentages; the ceiling is a per-row CHECK against a snapshotted `total_marks`, so the `total` branch of the regex must not claim them (resolutions F-7.2; covers `assignments.total_marks`, `assignment_submissions.total_marks`, `exams.total_marks`, `course_topic_assignments.estimated_marks`)<br>Nothing else may be added: a fourth entry is a review failure, not a configuration change |
| FIN-19 | `test_demo_and_volume_fixtures_reconcile` | after `demo:seed --fresh`: `integrity:verify --suite=wallet` reports zero drift and zero structural failure for all 8 demo collaborators; `fees:verify-plan-integrity` zero drift; `financial:verify-constraints` passes. The same after `PerformanceFixtureSeeder` (500 collaborators, 150,000 ledger rows) - which doubles as the reconciler's performance test (it must finish inside 120 seconds, chunked) |
| FIN-20 | `test_drift_is_detected_reported_and_never_silently_repaired` | a hand-corrupted `collaborator_wallets` row is found by R1, the run is `drift`, a notification is sent, **the cache is not rewritten**, and the wallet screen, the collaborator panel and every dashboard widget render the **derived** figures behind the red banner; `--repair` then rewrites the cache and records before/after; a corrupted allocation (structural, R4) sets `failed` and repairs **nothing** (spine §6.5.4, FT-43) |
| FIN-21 | `test_settings_changes_never_move_posted_money` | flipping `collaborator.student_commission_base`, `project_commission_base`, `commission_approval_mode`, the default rates, `fixed_commission_release`, `commission_on_overpayment`, `minimum_payout` and the currency leaves every existing ledger row, entitlement and `rule_snapshot` **byte-identical**; only future payments behave differently (FT-38) |
| FIN-22 | `test_financial_reports_agree_with_the_ledger` | Phase 23's collaborator performance, commission, pending-commission, paid-commission, payout-history and reversal reports, and Phase 13's profit-and-loss, are compared figure-for-figure against `CollaboratorWalletService::derive()` and `CollaboratorStatementService::build()` over the demo fixture; "paid commission" is derived from **allocations**, never from `status = paid` (INV-23, FT-27); a CSV, an Excel and a PDF export of each match the screen to the paisa |

---
### 11.6 Responsive and theme

| # | Test | Assertion |
|---|---|---|
| RSP-01 | `responsive.spec.ts` (Playwright, `@group optional` until §12 Q3 is answered) | every screen of the manifest x the six widths of §8.7 x light and dark: `document.documentElement.scrollWidth <= innerWidth + 1`, zero console errors, zero failed asset requests, zero CSP violations, and a screenshot written to `storage/app/audit/responsive/{screen}-{width}-{theme}.png`. A failure names the screen, the width, the theme and the widest offending element's selector |
| RSP-02 | `test_every_view_has_dark_variants` | a Blade scan: inside one `class` attribute, any `bg-*`, `text-*`, `border-*`, `divide-*`, `ring-*`, `placeholder-*` or `shadow-*` colour utility (excluding `white`/`transparent`/`current`/`inherit` and the CSS-variable brand utilities) must be accompanied by a `dark:` counterpart in the same attribute or be listed in `tests/Support/dark-mode-allowlist.php` with a reason (print views and the certificate template are the legitimate exceptions) |
| RSP-03 | `test_theme_switch_has_no_flash_and_persists` | the pre-paint script in `layouts/*` sets the class before first paint (asserted by its position above any stylesheet in the head and by its content); switching theme writes `localStorage` **and** `users.theme`; a hard reload keeps it; `System` follows `prefers-color-scheme` in both directions |
| RSP-04 | `test_tables_and_wide_content_scroll_in_their_own_container` | a Blade scan: every `<table` is inside an element carrying `overflow-x-auto` (or the `x-ui.table` component, which provides it); every Kanban board, timetable grid and chart canvas is likewise wrapped. A new raw table outside a wrapper fails |
| RSP-05 | `test_modals_lock_the_body_and_scroll_internally` | `x-ui.modal` sets `overflow-hidden` on `body` while open, restores it on close, and its panel carries `max-h-[...] overflow-y-auto`; asserted on the component and, under Playwright, at 360 px on the five largest modals (record payment, fee structure, impact, restore confirm, role matrix) |
| RSP-06 | `test_print_views_render_at_a4` | each of the nine print views renders with the print stylesheet applied: no dark-mode colour, no sidebar, no navigation, page-break rules present, `tabular-nums` on every money column, and the document fits the stated page count (invoice 1, fee slip 1, receipt 1, salary slip 1, result card 1, certificate 1, ID card 1, statement n, report n) |
| RSP-07 | `test_public_pages_carry_responsive_images_and_no_layout_shift` | every `<picture>` from `x-site.image` has a WebP source, a fallback `srcset`, the profile's `sizes`, intrinsic `width`/`height`, `loading="lazy"` unless eager, `decoding="async"`; at 390 px the network panel shows the 640-wide variant was fetched, not the 2560 (Playwright) |

### 11.7 Performance

Budgets are measured on the `PerformanceFixtureSeeder` volume (5,000 students, 20,000 charges, 60,000
receipts, 150,000 ledger rows, 2,000 projects, 500 collaborators) with a warm opcache, a cold application
cache, and `DB::listen` counting. **Query count is the hard budget** (it does not depend on the machine);
wall time is a soft budget recorded as a baseline and compared run-to-run for regression.

| # | Test | Assertion |
|---|---|---|
| PRF-01 | `test_query_budgets_per_page` | every screen of the manifest is under its `query_budget`. The defaults by kind, overridable per row with a written reason: **index 12**, **show 20**, **form 15**, **board 10**, **calendar 12**, **wizard step 10**, **print 12**, **public page 8 cold / 1 warm (the cached response)**, **dashboard 25** (Phase 2's bounded-widget rule), **statement 15**, **export 6 + n chunks**. The failure message prints every query with its count, highlighting duplicates |
| PRF-02 | `test_no_n_plus_one_anywhere` | `Model::shouldBeStrict()` is active in the test environment, so **any** lazy load throws `LazyLoadingViolationException` and fails the owning test. PRF-02 additionally walks every manifest screen and asserts no violation, and asserts no single SQL string is executed more than **3** times in one request |
| PRF-03 | `test_wall_time_budgets` | p95 over 10 runs: index < 400 ms, show < 500 ms, dashboard < 900 ms, public cached page < 120 ms, board < 600 ms, statement < 900 ms, report < 2,000 ms, export of 50,000 rows streams with a first byte < 2,000 ms and peak memory < 128 MB (`memory_get_peak_usage` asserted) |
| PRF-04 | `test_required_indexes_exist` | `index-manifest.php` (§2.5) against `information_schema.STATISTICS`: every listed index exists and is usable; every FK column has an index; the failure names the exact `ALTER TABLE` to add each missing one |
| PRF-05 | `test_everything_paginates` | the scan of §6.4: no unbounded `get()`/`all()` in an index, board, calendar or export action; every index response contains a pagination summary; `per_page` is clamped to a maximum of 100 and a non-numeric value falls back to the default rather than erroring |
| PRF-06 | `test_explain_plans_use_indexes` | for the 15 heaviest queries (collaborator ledger page, wallet derive, statement build, reconciliation per collaborator, fee collection desk, overdue sweep, attendance month, timetable week, lead board, project list with client and PM, invoice aging, payroll run items, global search, the public course list, the sitemap): `EXPLAIN` shows `type != ALL` and `key IS NOT NULL` on every table over 1,000 rows, and `rows` examined is under 5% of the table |
| PRF-07 | `test_scheduled_jobs_stay_bounded` | `commissions:sweep` (500 rows), `fees:mark-overdue`, `fees:installment-reminders`, `collaborators:reconcile-wallets` (chunk 200), `backup:prune`: each runs on the volume fixture inside its stated time budget, chunked, with a bounded query count per chunk, and is **idempotent** when run twice in the same minute |
| PRF-08 | `test_caches_are_used_and_invalidated` | the settings payload, module map, sidebar and public page each hit the cache on the second request (query count drops); changing the underlying row invalidates it immediately (`PublicCache::bump`, `Setting::saved`, `Module::saved`, the permission hash); a stale sidebar never survives a role change |
| PRF-09 | `test_cache_keys_never_cross_a_tenant` | every `Cache::remember` in `app/` whose closure touches a model with a global scope has an owner component in its key (PRF-14's scan); functionally: collaborator A's dashboard widget JSON, sidebar and search suggestions are **never** served to collaborator B, asserted by priming A's caches and then asserting B's responses contain none of A's values |
| PRF-10 | `test_reconciliation_and_statement_scale` | `collaborators:reconcile-wallets` over 500 collaborators / 150,000 ledger rows finishes under 120 s with a bounded query count per collaborator (no N+1 across the eight checks); a 200-entry statement renders under 900 ms and a 20,000-entry statement streams as CSV without exceeding 128 MB |
| PRF-11 | `test_asset_budget` | `public/build/manifest.json` exists; total CSS <= 70 KB gzipped, total eagerly-loaded JS <= 200 KB gzipped (Alpine + Chart.js + app), no `.map` file shipped, every entry is content-hashed, and Chart.js is loaded only on pages that declare a chart (code-split), asserted by reading the manifest's chunk graph |
| PRF-12 | `test_tailwind_purge_keeps_runtime_classes` | every distinct string returned by `color()` on every enum in `app/Enums` resolves to classes that **exist in the built CSS** (`public/build/*.css` is grepped for `bg-{token}-100` and `text-{token}-800`); the `x-ui.badge` and `x-ui.stat-card` components resolve tokens through a PHP `match` returning full class strings, asserted by rendering every enum case and checking the emitted class against the built CSS |
| PRF-13 | `test_no_money_figure_is_cached` | the scan of §6.4.1 plus a runtime assertion: after exercising the wallet, statement, commission ledger and fee screens, the `cache` table contains no value matching a money pattern attributable to a collaborator or a student |
| PRF-14 | `test_slow_queries_are_logged_not_ignored` | a deliberately slow query (a `SLEEP` in a test-only query) produces exactly one slow-query log line naming the route and the duration, with bindings redacted; the production threshold comes from `ops.slow_query_ms` |

### 11.8 Accessibility

| # | Test | Assertion |
|---|---|---|
| A11Y-01 | `test_structural_accessibility_of_every_screen` | `a11y:scan` over every manifest screen: `assertSingleH1`, `assertHeadingOrder`, `assertLandmarks`, `assertHtmlLang`, `assertUniqueTitle`, `assertSkipLink`, `assertEveryInputLabelled`, `assertIconButtonsLabelled`, `assertTablesCaptioned`, `assertErrorsAssociated`, `assertLiveRegions`, `assertNoPositiveTabIndex`, `assertChartHasTextAlternative`. One row per failure, naming the screen and the offending element's XPath |
| A11Y-02 | `test_every_drag_has_a_keyboard_alternative` | the lead board, task board, application pipeline, the four CMS reorder surfaces and the dashboard customize mode each expose a non-drag control (a "Move to" menu or up/down buttons) that performs the same write through the same route; the test drives the alternative and asserts the status changed |
| A11Y-03 | `test_contrast_pairs_pass_in_both_themes` | `tests/Support/contrast-pairs.php` - every documented foreground/background pair of the palette, plus every badge colour token on its surface, plus the focus ring on every surface - at >= 4.5:1 for body text and >= 3:1 for large text and UI boundaries, computed in both themes |
| A11Y-04 | `test_status_is_never_colour_only` | every `x-ui.badge` renders its label text (not just a dot); every chart has a text alternative (A11Y-01); every required field is marked textually as well as visually; every validation error has text |
| A11Y-05 | `test_reduced_motion_is_respected` | a CSS scan: every `transition`, `animate-` and the skeleton shimmer is disabled inside `@media (prefers-reduced-motion: reduce)`; Playwright confirms on three screens with the media feature emulated |
| A11Y-06 | `test_focus_is_visible_and_trapped` (Playwright) | tab order matches visual order on six representative screens; the focus ring is visible in both themes; modal, drawer and dropdown trap focus, close on Esc and return focus to the trigger; no element is focusable while a modal is open |
| A11Y-07 | `test_forms_work_with_the_keyboard_only` (manual, recorded) | login, online admission, contact, record payment, payout request and restore confirm completed end to end with the keyboard and with NVDA; the tester's name, date and findings go into `DEVELOPMENT_LOG.md` §7 |
| A11Y-08 | `test_zoom_and_reflow` (manual, recorded) | 200% zoom and a 320 px reflow width on one screen per group of §8.7 lose no content or function |

### 11.9 Deployment, backup, restore, rollback (Phase 25)

`tests/Feature/Ops/`. The rows marked **manual** are ticked on the go-live checklist with evidence; every
other row is automated.

| # | Test | Assertion |
|---|---|---|
| DEP-01 | `test_install_guide_matches_this_contract` | `docs/INSTALL.md`'s numbered steps match §6.8's step list one for one (ids and commands compared), so the runbook can never drift from the contract |
| DEP-02 | `test_fresh_install_succeeds` | on an empty database: `migrate --force` then `db:seed --class=ProductionSeeder --force` exits 0; `migrate:status` is clean; `integrity:verify --suite=constraints` exits 0; the 18 roles, every module, every permission and every setting key exist; **zero users exist** |
| DEP-03 | `test_env_example_is_complete_and_secretless` | every `env('KEY')` referenced under `config/` has a key in `.env.example`; no key in `.env.example` holds a non-empty secret value; `APP_KEY` is empty; `APP_DEBUG=false`; `APP_ENV=production`; `SESSION_SECURE_COOKIE=true`; the file parses |
| DEP-04 | `test_no_env_call_outside_config` | a scan of `app/`, `routes/`, `database/`, `resources/views/`: no `env(` call - otherwise `config:cache` silently turns it into `null` in production. An allowlist covers `config/*.php` only |
| DEP-05 | `test_migrations_roll_forward_and_back` | every migration of these phases rolls forward and back on a database **holding rows** (the three triggers dropped before their tables; the index-only migration of §2.5 drops only `idx_p24_*`); `migrate:fresh --seed` is clean; the guarded external-FK migrations are no-ops when their target table is absent (spine FT-51 re-asserted for the whole set) |
| DEP-06 | `test_the_first_super_admin_is_created_securely` | `user:create-super-admin` creates exactly one user with the Super Admin role, `must_change_password = true`, `email_verified_at` set, a cryptographically random password, and sends a signed reset link; the command prints **no** password unless `--show-password`; it refuses a second run without `--force`; it refuses an `example.com`/disposable address; **no seeder anywhere creates a user with a literal password** (a scan of `database/seeders` for `'password' =>` outside `DemoDataSeeder`); `DemoDataSeeder` refuses to run when `APP_ENV=production` |
| DEP-07 | `test_database_privileges_are_separated` | with the runtime credentials, `migrate --force` **fails** on privileges and `CREATE TABLE`/`DROP TABLE`/`ALTER TABLE` are refused; with `--database=mysql_migration` migration succeeds; `SHOW GRANTS` for all three users contains no `SUPER`, `FILE`, `PROCESS`, `RELOAD`, `GRANT OPTION` or `CREATE USER`; the nine delete triggers still fire for the runtime user (FIN-17) |
| DEP-08 | `test_upload_directories_cannot_execute_code` | `storage/app/public/.htaccess` exists with the §6.9.1 directives; the upload layer refuses every blocked extension (SEC-15); the Apache-level check is **manual** (GL-17) and its result is recorded |
| DEP-09 | `test_nothing_outside_public_is_web_reachable` | over the running vhost (or the test server as a proxy for it): `/.env`, `/.env.example`, `/.git/config`, `/composer.json`, `/composer.lock`, `/package.json`, `/artisan`, `/vendor/autoload.php`, `/storage/logs/laravel.log`, `/storage/app/backups/<a real filename>`, `/database/`, `/docs/requirements.md`, `/tests/`, `/node_modules/` all return 403 or 404 and no file content |
| DEP-10 | `test_production_configuration_sanity` | `APP_DEBUG=false`, `APP_ENV=production`, `APP_URL` is HTTPS and matches the request host, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `LOG_LEVEL` not `debug`, `CACHE_STORE`/`QUEUE_CONNECTION`/`SESSION_DRIVER` all `database`, config and routes cached, `APP_URL` host does not resolve to a `:8000` dev server |
| DEP-11 | `test_real_client_ip_is_recorded` | with `TRUSTED_PROXIES` set and an `X-Forwarded-For` header, `login_histories.ip_address` and the rate limiter key use the client IP; without it, the header is **ignored** (a spoofed `X-Forwarded-For` must not bypass a limiter) |
| DEP-12 | `test_logs_are_scrubbed_and_pruned` | a log line containing a password, a token, an `*_encrypted` value, a CNIC and a 4,000-character payload comes out with `[redacted]` and truncated; `ops:prune-logs` removes dailies beyond `ops.log_retention_days` and never today's; the log file is not web-reachable (DEP-09) |
| DEP-13 | `test_backup_run_records_and_proves_itself` | `backup:run --type=database --reason="test"` creates one `completed` row with a non-null `checksum_sha256`, `size_bytes`, `table_count`, `row_count_total`, `retention_class`, `retention_until`, the reason and one activity row; the archive exists on the `backups` disk, is a valid zip, contains a dump whose `CREATE TRIGGER` statements for the nine delete triggers are present, and contains **no `.env`** unless encryption is on; the DB password appears nowhere in the process arguments (asserted on the recorded command line); a second concurrent run is refused while one is running |
| DEP-14 | `test_deep_verification_restores_and_reconciles` (HD-6) | `backup:verify --latest --deep` restores into `backup.restore_scratch_database`, finds no pending migration, matches the proof counts of §6.10.4, passes `financial:verify-constraints` **on the restored copy**, reports zero structural reconciliation failures, drops the scratch database, and sets `verification_status = restore_ok` with notes. A deliberately truncated archive sets `failed` and notifies |
| DEP-15 | `test_retention_never_destroys_the_last_restore_point` | the §6.10.3 rules: with 40 seeded backups across a year, `backup:prune --dry-run` output equals what `prune()` then does; `retention_min_copies` usable database archives remain; the newest usable database archive is never pruned; a `pre_restore` and a `pre_deploy` backup are never pruned; **no `backup_runs` row is deleted** (count unchanged, `file_pruned_at` set, a raw DELETE raises 45000); exceeding `max_storage_gb` fails loudly with a notification and prunes nothing beyond policy |
| DEP-16 | `test_restore_requires_all_four_gates` | without `backups.restore` -> 403; with the permission but no password confirmation -> redirected to the confirm screen; with a wrong-case confirmation phrase -> 422; with a 10-character reason -> 422; a forged `confirmed_at` in the payload is ignored. In every failing case **no restore row reaches `running`** and the database is untouched |
| DEP-17 | `test_restore_executes_the_documented_procedure` | a full restore into a scratch database performs §6.10.5's 13 steps in order (asserted from the `backup_restores` row and the activity log): a pre-restore backup exists and is linked, before/after row counts and `ledger_rows_*` are recorded, the schema is brought forward, constraints and reconciliation are proven in `proof`, the worker is stopped and restarted, maintenance mode is entered and left even when the restore throws, and a failing proof leaves `status = failed` with the application **still down** |
| DEP-18 | `test_migration_rehearsal_gate` (partly manual) | the deploy script's step 4 is executable: a production archive restored into the scratch database, the release's migrations run there, and `integrity:verify --suite=constraints --suite=wallet` green. A red rehearsal returns a non-zero exit that the deploy script treats as fatal |
| DEP-19 | `test_module_kill_switch_is_a_real_rollback` | data provider = **every** module row where `is_core = false` (the registry is the provider, so a module added in a later phase is covered automatically): disabling hides the sidebar item, 403s every route for Super Admin, 404s the public counterpart, leaves every row intact (counts compared before and after a disable/enable cycle), leaves queued jobs completing, and writes `disabled_at`, `disabled_by`, `disable_reason` plus an activity row (Phase 2's guarantee, re-asserted as the rollback mechanism of §6.12 rung 1) |
| DEP-20 | `test_rollback_table_is_honest` | for every phase whose §6.12 row says `migrate:rollback` is **safe**, the rollback actually runs on a seeded database and `migrate` re-applies cleanly; for every row that says **no**, a test asserts the rollback would be refused or destructive - either because a delete trigger raises 45000, or because the down migration would drop a table holding rows (asserted by a dry check, not by running it). The table is therefore verified, not aspirational |
| DEP-21 | `test_queue_and_scheduler_wiring` | `schedule:list` contains all 20 commands of §10.4 with the stated cadence and the `Asia/Karachi` timezone; every financial command is present; `ops:heartbeat` stamps the scheduler heartbeat; `QueuePingJob` stamps the queue heartbeat; `ops:check-heartbeats` fires `SchedulerStalled` / `QueueWorkerStalled` / `FailedJobThresholdExceeded` at the configured thresholds and notifies the right permission holders |
| DEP-22 | `test_health_endpoint` | `/health` without a token -> **404**; with a wrong token -> 404; with the right token -> 200 JSON containing only counts, ages, booleans and versions, and **no** row data, name, email, balance or path above the base directory; a failing probe makes it 503; `ops.health_endpoint_enabled = false` -> 404; the endpoint is rate-limited; `/up` returns 200 |
| DEP-23 | `test_maintenance_mode_behaviour` | `php artisan down --secret=` returns a **branded** 503 with `Retry-After` on the public site and all five panels, while the secret URL lets an operator browse; `maintenance.maintenance_mode` (Phase 2's setting) blocks the public site but never `/admin`; `public_site_enabled = false` returns the holding page; `up` restores everything |
| DEP-24 | `test_the_proof_suites_are_scheduled_not_just_runnable` | `integrity:verify --suite=all`, `collaborators:reconcile-wallets`, `financial:verify-constraints`, `backup:run`, `backup:verify`, `backup:prune`, `security:audit` and `ops:check-heartbeats` all appear in `schedule:list`; removing one from the schedule fails this test (HD-10) |
| DEP-25 | `test_golive_check_blocks_on_a_real_blocker` | with every blocker green, `golive:check` exits 0. Then, one at a time, break six blockers (`APP_DEBUG=true`; a missing security header; an unverified latest backup; a drifted wallet; a missing index; a demo account still on its seeded password) and assert `golive:check` exits 2 each time and names exactly that row; waiving a blocker requires the permission and a `min:20` reason and writes an activity row |
| DEP-26 | `test_documentation_is_updated` | `DEVELOPMENT_LOG.md` §5 has phases 24 and 25 with their item rows, §6 has a dated change-log entry per suite delivered, §7 has a dated row per suite **actually run** with its command and outcome, §8 lists every accepted risk of §12.1, and §9 records every client answer to §12.2. A phase cannot be ticked without these rows |

---
## 12. Risks and open questions

### 12.1 Risks accepted, with the mitigation

| # | Risk | Mitigation / why it is accepted |
|---|---|---|
| R-1 | **The manifests are the single point of failure of every sweep.** A screen nobody registered is a screen nobody tested, and the sweep will still report green. | `audit:manifest --check` compares the manifests against the **live route list**, not against a hand-written list, and is a required step of every phase's definition of done from Phase 24 onward plus a go-live blocker (GL-12). A new route fails CI within a day of being written. The residual risk is a route that exists but is unreachable by any manifest fixture, which `--coverage` reports per phase |
| R-2 | **Windows is a second-class production target.** Symlinks need privileges, supervisor does not exist, `pcntl` is unavailable, icacls is easy to get wrong, and XAMPP's Apache runs as LocalSystem by default. | Every runbook gives both a Windows and a Linux path; the concurrency harness uses OS processes through `Process::pool()` rather than `pcntl`; the storage symlink has a documented `Alias` fallback; a dedicated `svc_myoffice` service account is part of the checklist. The honest recommendation, recorded as Q1, is a Linux host |
| R-3 | **The project path contains a space** (`C:\xampp\htdocs\my office`, tech debt T2). An unquoted path in a cron entry, a service definition, a vhost or a `mysqldump` invocation fails in a way that looks like a permission error. | Every command in this contract is quoted; the Windows scheduler uses a `.bat` wrapper precisely to avoid nested-quote breakage; DEP-13 asserts the backup works from the real path. Q1 offers the clean fix (deploy to a path without a space) |
| R-4 | **`'unsafe-eval'` stays in the CSP** because Alpine 3 evaluates `x-` expressions with `new Function`. | The nonce requirement, the sanitiser, the raw-output allowlist (SEC-05) and the escaping tests (SEC-03) are the real XSS defences; CSP is defence in depth. Q4 offers Alpine's CSP build, which would cost a rewrite of every inline expression |
| R-5 | **A property test can pass on its seeds and still hide a bug** reachable only by an operation sequence it never generated. | The operation set of FIN-15 is derived from the spine's own edge-case table (22 rows) rather than invented, every failure seed is committed permanently, and CI runs one fresh random seed per build, so coverage grows monotonically. It complements - never replaces - the 51 deterministic spine tests |
| R-6 | **The concurrency tests are the slowest and flakiest part of the suite** and will tempt someone to skip them. | They are tagged `@group concurrency`, excluded from the fast local run, and **required** in CI and by `golive:check`. The barrier is a table row rather than a sleep, which removes the usual source of flakiness. HD-1 forbids skipping |
| R-7 | **Responsive and keyboard verification is partly manual**, so it is partly a matter of diligence. | The machine half (overflow, console errors, labels, landmarks, contrast, reduced motion) is automated; the manual half is a per-screen tick recorded with the tester's name and date in `DEVELOPMENT_LOG.md` §7 and is a go-live blocker (GL-47, GL-48). Q3 offers Playwright, which moves four more criteria into the machine half |
| R-8 | **`Model::shouldBeStrict()` is off in production**, so a lazy load that only happens on real data will not throw there. | It throws in local, CI and staging - where the volume fixture lives - and in production it is still **logged** and surfaced in `ops:digest` and on the health screen. Turning it on in production would convert a slow page into a 500 for a paying client, which is worse |
| R-9 | **A restore always loses the writes made since the backup**, and a full archive is only as young as last night. | The default schedule is daily at 02:30; the restore screen computes and displays exactly what will be lost before the operator can continue; the procedure takes a pre-restore backup first so the decision is reversible. Q2 asks whether the client needs a shorter RPO (binary logs or hourly dumps), which is a host capability question, not a code change |
| R-10 | **Nine `BEFORE DELETE` triggers make some legitimate operations impossible** - including a GDPR-style erasure request for a student whose receipts exist. | That is the requirement (§110: never delete financial history). An erasure is handled by anonymising the **personal** columns on `students`/`users` while the financial rows keep their ids, which is a Phase 14-15 capability, not a deletion. Recorded as Q8 |
| R-11 | **The go-live checklist can be waived.** A determined operator can tick a blocker and go live anyway. | Waiving needs `system_health.view_logs`, a `min:20` reason, and writes an activity row and a notification to every Super Admin; the checklist screen shows every waiver permanently. A system that could not be overridden at 2 a.m. during an outage would be worse |
| R-12 | **`security:audit` is a static scan, not a penetration test.** It finds forgotten guards and known patterns, not logic flaws or a novel chain. | Its scope is stated on the screen so nobody mistakes a green run for a pentest; the authorization, isolation and IDOR sweeps are behavioural, not static. Q9 asks whether the client wants an independent third-party assessment before go-live; this contract makes one cheap by handing over the matrices |
| R-13 | **`backup_runs` and `integrity_check_runs` grow for ever** (they cannot be deleted, by design). | Both are narrow rows; a daily backup plus nine daily suites is roughly 3,700 rows a year. `ops:prune-integrity-runs` nulls old `findings` blobs, which is where the bytes actually are |
| R-14 | **Phase 24 may fix defects in 23 phases' files**, which is the largest blast radius in the project. | Every fix is additive, lands in the owning phase's file, and must leave that phase's own acceptance tests green; the phase's §1.3 ownership table forbids changing a rule to make a test pass; every fix gets a dated `DEVELOPMENT_LOG.md` §6 entry naming the phase and the test that found it |
| R-15 | **The manifests can only ever be as complete as the last phase to append to them** - a phase that ships screens, uploads, routes or indexes without registering them is silently outside every sweep. | The manifests are append-only per phase and §13.2 states the ask explicitly; `audit:manifest --coverage` prints per-phase coverage so a phase that never registered anything is visible immediately rather than silently untested. (The earlier form of this risk said phases 19-23 had no written contract; `phase-19-23.md` exists and its §13.1 supplies the four manifest additions - resolutions F-11.7.) |

### 12.2 Open questions (defaults assumed, nothing blocked)

| # | Question | Default assumed until answered |
|---|---|---|
| Q1 | **Production host**: Linux (Apache or nginx, supervisor, certbot) or Windows/XAMPP at `C:\xampp\htdocs\my office`? And may the deploy path be changed to one **without a space** (`C:\inetpub\myoffice` or `/var/www/myoffice`)? | Both documented, Windows/XAMPP treated as the live target because that is the current environment; the space is handled by quoting everywhere. A path without a space removes a whole class of failure and is the recommendation |
| Q2 | **RPO and RTO**: how much data may a disaster lose, and how long may recovery take? | RPO 24 hours (nightly 02:30 database backup), RTO 2 hours (the rehearsed restore of GL-38). A shorter RPO means hourly dumps or MariaDB binary logs - a host decision |
| Q3 | May Phase 24 add **Playwright** (`@playwright/test`) as a dev dependency for the responsive, focus-trap and overflow sweeps? | Yes, as a **dev** dependency, with the specs tagged `@group optional` so the suite stays green where the browser is unavailable. Without it, RSP-01, A11Y-06 and RSP-07 become manual rows on the checklist |
| Q4 | Switch Alpine to its **CSP build** so `'unsafe-eval'` can leave the policy? | No for this release (it would require rewriting every inline `x-` expression). The policy keeps `'unsafe-eval'` with the reason stated in the code |
| Q5 | **Error monitoring**: tier 1 (in-app notifications + daily digest, no new dependency) or tier 2 (Sentry, a DSN and an external service)? | Tier 1 ships; tier 2 is wired behind two settings and can be turned on in five minutes |
| Q6 | Is **two-factor authentication** in scope? Phase 2 declares `security.two_factor_enabled` as "implemented later", and Phase 24 is the natural home. | **Out of scope for this release**, setting left declared and inert. If it is wanted, it is a small phase of its own (TOTP, recovery codes, a `two_factor_*` column set, a confirm screen) and must not be bolted onto a hardening pass |
| Q7 | **Offsite backup destination**: S3, an SFTP host, or a mapped drive / external disk the client rotates? Who holds the credentials and the archive password? | `backup.offsite_disk` unset until the client names one, and `offsite_required_for_go_live` keeps GL-37 red until then - deliberately, because a single-disk backup is not a backup |
| Q8 | **Data erasure requests**: does the business need to erase a person's data, given that financial rows can never be deleted? | Anonymise the personal columns, keep the financial rows and their ids; no deletion. Needs a Phase 14-15 `students.anonymise()` path if the client confirms the requirement |
| Q9 | Does the client want an **independent security assessment** before go-live? | Not assumed. The matrices, the route manifest and the isolation suite are written so an external tester can start from them |
| Q10 | May the **demo data** (§116) be seeded on the client's **staging** server, and must it be absent from production? | Demo data on staging only; production refuses (`APP_ENV=production`) and GL-22 is a blocker |
| Q11 | Who holds `backups.restore`, and is a **second pair of eyes** required for a production restore? | Super Admin only, single operator, four gates, full audit. A two-person rule is a small addition (`approved_by` on `backup_restores` already exists in shape) if the client wants it |
| Q12 | Should the platform keep **`.env` inside the file backup** (encrypted) so a restore is self-contained, or stay out of it? | Out, unless `backup.encrypt_archives` is on; the operator stores `.env` in the password manager and the runbook says so |
| Q13 | `password_history_count` needs a **`password_histories`** table (user_id, hash, created_at). May Phase 24 create it, or does Phase 1 own it? | Phase 1 owns identity, so it is requested in §13. Until it exists, `security.password_history_count` is inert and SEC-18 asserts only the policy rules it can |
| Q14 | **Log and archive retention** durations: 14 days of application logs, 30/12/12/3 for backups - acceptable to the client's auditors? | As defaulted in §5.1; all settings-driven, so a change is a form, not a release |

---

## 13. Requests to other phases

### 13.1 Registries, middleware and support classes

| Request | From | Why |
|---|---|---|
| `PermissionRegistry`: the two module slugs of §4.1 and the five added abilities on `backups` (§4.2) | Phase 1 | the registry is the only place a permission name exists (D4) |
| `bootstrap/app.php`: register `SecurityHeaders`, `ForceHttps`, `NoStoreForAuthenticated` globally, `EnforceSessionLifetime` in the `auth` stack, the `health.token` alias, and `RateLimitServiceProvider` | Phase 1 | Phase 1 owns the file; these are additive lines |
| `Model::shouldBeStrict(! app()->isProduction())` and the `LazyLoadingViolationException` log handler in `AppServiceProvider::boot()` | Phase 1 | the N+1 audit is a boot-time switch, not a test helper |
| `App\Support\Money` - **SATISFIED by phase-01 §3**, which now publishes the canonical surface of **20 methods**: `add`, `sub`, `mul`, `div`, `percentage($base,$rate)`, `percentageOf($part,$whole)`, `compare`, `isZero`, `isNegative`, `abs`, `min`, `max`, `sum(array)`, `round($v,$scale=2)`, `roundTo($amount,int $nearest)`, `prorate($amount,$part,$total)`, `distribute($amount,int $parts,RemainderPlacement $r = RemainderPlacement::First): array`, `toMinor`, `fromMinor`, `format` - **bcmath only, intermediate scale 6, final half-up at 2, strings in and out, never a float** - plus `App\Enums\RemainderPlacement` (`first`, `last`, `largest`) | Phase 1 §3 (resolutions F-4.11) | FIN-12 tests this exact list directly, as the foundation of every money figure; the class change itself belongs to the Phase 1 remediation team |
| A `password_histories` table (`user_id` FK cascade, `password` string(255), `created_at`) and a `PasswordHistory` check in the change-password path | Phase 1 (Q13) | `security.password_history_count` is otherwise inert (SEC-18) |
| `SettingsRegistry`: the `backup` and `ops` groups and the 18 `security` keys of §5 | Phase 2 | settings definitions live in code, values in the DB |
| `ConfigureFromSettings`: apply `security.force_https`, `ops.log_retention_days`, `security.trusted_proxies`, `security.session_absolute_lifetime_hours` and `ops.error_monitoring_*` at boot, inside the existing try/catch | Phase 2 | one runtime-wiring class, not two |
| `DashboardRegistry`: accept `BackupStatusWidget`, `IntegrityStatusWidget`, `FailedJobsWidget`, `QueueHealthWidget`; and `SystemHealthWidget` must read `SystemHealthService` rather than computing its own probes | Phase 2 | one health implementation (HD-3); Phase 2 declared the widget before the service existed |
| `php artisan settings:set <group.key> <value>` (console write path through `SettingsService`) | Phase 2 | the deploy script stamps `ops.app_version` without a browser |
| `PublicCache::bump()` called by the deploy script after a release | Phase 3 | otherwise yesterday's HTML survives a CMS change in the release |
| `assertWalletMatchesLedger($collaborator)`, `CommissionReconciliationService::run()` (read-only by default), `CollaboratorWalletService::derive()` and the `financial:verify-constraints` command must be **public, stable API** with the names used here | Phases 10-12 | `integrity:verify` and FIN-11..FIN-22 call them; a rename breaks the nightly proof and the go-live gate |
| Each of the nine §120 test methods must carry `@group financial-120` and keep its exact name | Phases 10-12, 18 | FIN-01 pins the suite by name so it can never be quietly reduced |
| `PaymentService::allowDirectWrites()` must stay the single, greppable escape hatch and must remain usable from seeders | Phase 10 | `DemoDataSeeder` and `PerformanceFixtureSeeder` need volume without re-implementing the money path |
| `fees:verify-plan-integrity` must be callable as a class (not only a command) and must report without repairing | Phase 18 | `integrity:verify --suite=wallet` aggregates it |

### 13.2 Manifest rows - the ask of every phase

Every phase from 3 to 23 appends its own rows to the four manifests of §6.1 **as part of its own definition of
done**, and Phase 24 supplies the scaffolding command (`audit:manifest --write`) that generates them:

| Manifest | What each phase must add |
|---|---|
| `screen-manifest.php` | one row per screen it ships: route, panel, kind, a params factory, the permissions needed, the module slug, its query budget, and whether it is in the responsive / a11y / IDOR sweeps (with the owner column for IDOR) |
| `route-guard-manifest.php` | one row per route: methods, middleware, the exact `can:` permission or a written `rationale` for a public route, and whether it is state-changing |
| `upload-manifest.php` | one row per upload field: route, field, disk, allowed MIME list, max MB, permission |
| `index-manifest.php` | every index its queries rely on. **Every FK column listed in that contract's own `Keys` block has a row here** (resolutions F-9.2) - plus each filtered `status`, each sorted column and each `(branch_id, ...)` pair. PRF-04 joins `KEY_COLUMN_USAGE` to `STATISTICS`, so an FK index named in a contract but missing from the manifest, or missing from the schema, fails the same way |

Phases **19-23** must include these four additions in their own §13 so the sweeps cover them from the day they
ship (R-15) - and **`phase-19-23.md` §13.1 already supplies them**.

### 13.3 Behavioural asks

| Request | Why |
|---|---|
| **Every controller resolves `sort` through an array allowlist** and clamps `per_page` to 100 | SEC-09, PRF-05 |
| **Every download and print route re-checks its permission *and* its ownership predicate** before streaming, and resolves the path from the model, never from the request | SEC-16 |
| **Every export streams** (`chunkById` / `LazyCollection`) and carries the same scope as the screen it exports | ISO-12, PRF-03 |
| **Every `{!! !!}`** is registered in `raw-output-allowlist.php` naming `App\Support\RichText::sanitize()` as the sanitiser that makes it safe - the one sanitiser in the system (D25) | SEC-05 |
| **No `env()` outside `config/`** in any phase's code | DEP-04 - `config:cache` would turn it into `null` in production |
| **Every enum's `color()` token** must be resolvable to full class strings through a `match` in `x-ui.badge` / `x-ui.stat-card`, never interpolated into a class attribute | PRF-12 - Tailwind purges runtime-built class names |
| **Every Kanban, pipeline and reorder surface ships a non-drag alternative** | A11Y-02; a drag-only write is inaccessible and would block go-live |
| **Every chart ships a text alternative** (an `sr-only` table of the same series) | A11Y-01, A11Y-04 |
| **Every table lives inside an `overflow-x-auto` wrapper**, and every modal scrolls internally | RSP-04, RSP-05 |
| **Every cache key whose value depends on the viewer carries the viewer's id** | PRF-09; a shared key is a data-isolation defect, not a performance one |
| **No phase computes a balance, a commission or a wallet figure outside `CollaboratorWalletService` / `CollaboratorStatementService`** | spine INV-26, FIN-16 |
| **Phase 23's reports and exports go through the owning phase's scopes and enums**, never a raw query | ISO-12, FIN-22 |
| **Phase 22 ships the notification classes** listed in §10.3 that are not ops-specific, in the same style as the rest of §97 | one notification layer |

### 13.4 Documentation and log updates

| Update | Where |
|---|---|
| `DEVELOPMENT_LOG.md` §4: **cite D16** (the nine append-only financial tables carry no `deleted_at` - approved 2026-09-12; these phases only assert the consequence) and **cite D19** (the general soft-delete category rule, which is why §2's three run-history tables carry none either); then record **D57** (release deploys are directory swaps with a rename rollback), **D58** (three separated MySQL users: runtime DML, migration DDL, backup read-only), **D59** (`Model::shouldBeStrict()` outside production is the N+1 audit) and **D60** (the four manifests are the authority for every sweep, and `audit:manifest --check` is part of every phase's definition of done). These numbers come from `docs/design/resolutions.md` §4, the only decision-number authority - this contract claims none of its own | §4 |
| `DEVELOPMENT_LOG.md` §2: add `spatie/laravel-backup ^9` and, if Q3 is approved, `@playwright/test` (dev) | §2 |
| `DEVELOPMENT_LOG.md` §5: the Phase 24 and Phase 25 item rows, ticked only with a test note | §5 |
| `DEVELOPMENT_LOG.md` §7: one dated row per suite **actually run**, with the command and the outcome - including the manual responsive, keyboard and restore-rehearsal passes with the tester's name | §7 |
| `DEVELOPMENT_LOG.md` §8: close T2 (the path with a space - documented and quoted, or moved per Q1), T3 (the root password and the three least-privilege users), T1 (the unused `@tailwindcss/vite`), T4 (`/register` removed, asserted by SEC-30) | §8 |
| `DEVELOPMENT_LOG.md` §9: the client's answer to every Q of §12.2, dated | §9 |
| `CLAUDE.md`: add the five golden rules these phases introduce - no `env()` outside `config/`; every route in the guard manifest; every cache key carries its owner; every drag has a keyboard alternative; a backup is not a backup until it has been restored | §1 |
| New documents: `docs/INSTALL.md` (§6.8, kept in sync by DEP-01), `docs/OPERATIONS.md` (§6.9-§6.11), `docs/ROLLBACK.md` (§6.12), `docs/GO-LIVE.md` (§6.13), `deploy/apache-vhost.conf`, `deploy/schedule.bat`, `deploy/deploy.ps1`, `deploy/deploy.sh`, `deploy/supervisor-queue.conf`, `deploy/php-production.ini` | `docs/`, `deploy/` |

---

## Convergence log (2026-09-12)

Applied from `docs/design/resolutions.md` §7 (Apply map row `docs/phases/phase-24-25.md`) plus its §2
ownership maps. Nothing outside those rows was restructured.

| Finding | Change made |
|---|---|
| F-2.5 | §1.2: the `HtmlSanitizer` dependency row is **removed** - Phase 4 declares no sanitiser and no uploader; `App\Support\RichText::sanitize()` (`mews/purifier`, write **and** render, D25) and `MediaService` + `ImageProfile` move onto the Phase 3 row. SEC-04 now names `RichText::sanitize()` as the sanitiser under test; **SEC-05 names it as the only legal value of `raw-output-allowlist.php`'s sanitiser column** (a second sanitiser name is itself a failure), and §13.3's `{!! !!}` rule says the same. |
| F-4.11 | §13.1's `Money` ask is marked **SATISFIED by phase-01 §3** and carries the canonical surface verbatim (**20 methods** + `App\Enums\RemainderPlacement` - the count corrected from "19" by ND-11), with "bcmath only, intermediate scale 6, final half-up at 2, strings in and out, never a float". FIN-12's assertions are unchanged - they now pin a published list. |
| F-7.1 | FIN-18 restated: every `*_rate` / `*_percentage` is `decimal(8,4)` with **no "reported percentage" exception**, and the allowlist is **closed** to `progress` (tinyint) and `rating` (tinyint). GL-21 asserts the allowlist still holds exactly its entries. |
| F-7.2 | **`*_marks` added to FIN-18's allowlist** as the third and final entry, with the reason "marks are not money and not percentages; the ceiling is a per-row CHECK against a snapshotted `total_marks`" - covering `assignments.total_marks`, `assignment_submissions.total_marks`, `exams.total_marks` and `course_topic_assignments.estimated_marks`, which match the regex on `total`. A fourth entry is a review failure. |
| F-9.1 | §2's preamble cites `DEVELOPMENT_LOG.md` §4 **D19** (the "audit, log and run history" category) for the three tables' missing `deleted_at`, and **D16** for the nine financial tables, instead of appealing loosely to "the spine's append-only rule". No local decision number is claimed. |
| F-9.2 | §13.2's `index-manifest.php` row now requires that **every FK column listed in a contract's own `Keys` block has a manifest row**, and states that PRF-04 fails identically whether the index is missing from the manifest or from the schema. |
| F-11.7 | §13.2's false clause "whose contracts are not yet written" is **deleted** (and the row now records that `phase-19-23.md` §13.1 already supplies the four manifest additions). R-15 is re-stated as the real, permanent risk - a phase that ships without appending to the manifests - with the stale premise noted as corrected. |
| F-12.5 | §6.1 gains the two explicit `upload-manifest.php` rows the audit demanded, both **private `local`** with their streaming route and permission: `expenses.receipt_path` (`admin.expenses.receipt`, `expenses.view` + the PM ownership predicate) and `invoices.pdf_path` (`admin.invoices.pdf`, `invoices.view` + its ownership predicate), both `public_reachable = false`. The manifest drift check now fails a `public` disk on a private artefact (D21). §6.10.2's archive table moves receipts out of `storage/app/public` and names expense receipts and invoice PDFs under `storage/app/private`; SEC-15's field list names both. |
| F-10.1 | §13.4 renumbered to the registry of resolutions §4: **cite D16** and **cite D19**, then **D57** (directory-swap deploys with a rename rollback), **D58** (three separated MySQL users), **D59** (`Model::shouldBeStrict()` outside production), **D60** (the four manifests are the authority for every sweep). The row records that this contract claims no number of its own. |

---

## Drift fixes (round 2)

Applied from `docs/design/consistency-audit-round-2.md` §4. These close contradictions the convergence
pass itself created; no money guarantee was weakened and nothing outside the listed items was touched.

| ND | Change made |
|---|---|
| ND-11 | The `Money` surface is **20 methods**, not 19. Counting the list published at phase-01 §3 (and quoted verbatim in §13.1 here): `add`, `sub`, `mul`, `div`, `percentage`, `percentageOf`, `compare`, `isZero`, `isNegative`, `abs`, `min`, `max`, `sum`, `round`, `roundTo`, `prorate`, `distribute`, `toMinor`, `fromMinor`, `format` = **20**, plus `App\Enums\RemainderPlacement` (`first`, `last`, `largest`), which is an enum and not a method. The F-4.11 convergence-log row's "19 methods" is corrected and §13.1's row now states the count, so FIN-12 - which "tests this exact list" - agrees with phase-01 §3 and §11 A1. No method was added or removed; the list itself is unchanged. |
