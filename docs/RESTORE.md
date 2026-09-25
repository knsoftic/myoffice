# RESTORE.md — backups, verification, and restoring one

This is the backup and restore procedure of **phase-24-25 section 6.10**, with the restore itself —
section 6.10.5 — as the thirteen numbered steps below. It is written for an operator who has not read the
architecture contract and who is reading this at two in the morning because something has gone wrong.

The step list is contracted to be machine-checked. **DEP-17
(`test_restore_executes_the_documented_procedure`) is specified to assert that a real restore performs
section 6.10.5's thirteen steps in this order**, reading the order from the contract.

> **That test has not been written.** Nothing currently proves that the command below performs these
> thirteen steps, in this order, or at all. You are reading this at two in the morning, so it is worth
> saying plainly: **the thirteen steps are what the code is meant to do, and today the only thing checking
> that is you.** Rehearse a restore into the scratch database (`backup:verify --latest --deep`) before you
> need one for real — that path *is* exercised, weekly, by the schedule. If you think a step is wrong, change the contract, then the command, then this file — in that
order. A runbook that has drifted from what the software does is worse than no runbook, because it is
trusted at the worst possible moment.

---

## Read this before you restore anything

**A restore is not an undo.** It replaces the contents of the database with the contents of an archive.
Everything written after that archive was taken — every fee receipt, every commission ledger entry, every
payout, every ticket reply, every uploaded document row — is gone, and the only way back is the
pre-restore backup that step 5 takes on your behalf. If you are here because a figure is wrong rather than
because the database is broken, **stop and read [`ROLLBACK.md`](ROLLBACK.md)**: a restore is rung 5 of six
on that ladder, the money tables are append-only, and a wrong amount is corrected by a reversing entry
that references the original, never by restoring last night over it (`CLAUDE.md` section 1, rule 3).

| Situation | This document? |
|---|---|
| The database is corrupt, dropped, or a migration destroyed a table | **yes** — you are in the right place |
| Somebody deleted a batch of records and nobody can say which | yes, but read ROLLBACK.md rung 6 first — a forward fix keeps today's data |
| A commission, fee, invoice or payout figure is wrong | **no.** ROLLBACK.md rung 6. Restoring loses every receipt taken since the backup and does not make the figure right |
| A release is misbehaving and the schema did not change | **no.** ROLLBACK.md rung 3 — swap the directory back |
| You want to practise, or to answer "how long would we be down?" | yes — Appendix A, into the scratch database, on any day but today |

**An archive whose `verification_status` has never been proved must not be restored.** A row sitting at
`unverified` means nothing has ever opened that file; `checksum_ok` means the bytes are intact and says
nothing about whether the dump inside would load; only `restore_ok` means somebody — the weekly deep
verification — actually restored it and found a working system on the other side. Restoring an unproved
archive turns one incident into two: you will have lost today's data *and* not have yesterday's. If the
only archive you hold is unproved, read Appendix B before you type anything.

**The console is the only path today.** Section 8.3 specifies a three-step restore wizard at
`admin.backups.restore.create`, and the backups screens of sections 8.1 and 8.2 alongside it. **None of
them has shipped** — `php artisan route:list` returns no `admin.backups.*` route in this release — so every
sentence below about "the screen" describes where this is going, and `php artisan backup:restore` is how
you do it tonight. That has one consequence worth knowing before a gate refuses you: see "the four gates".

---

## Quote every path. Every single one.

The project lives at `C:/xampp/htdocs/my office`, and that folder name contains a space (tech debt **T2**).
Unquoted paths are by a wide margin the most common way these commands fail on this machine, and the
failure is never honest about its cause: an unquoted archive path makes `mysql` read `C:/xampp/htdocs/my`
and report a file-not-found for a file that is sitting on disk, and an unquoted `mysqldump` path makes the
pre-restore backup of step 5 fail — which **aborts the restore**, correctly, and looks like a broken
restore rather than a missing pair of quotes. Forward slashes work everywhere in PHP, Composer, Node and
Apache on Windows; use them, inside double quotes, always.

| Fact | Value |
|---|---|
| Base path | `C:/xampp/htdocs/my office` |
| Live database | `my_office` |
| Restore scratch database | `my_office_restore_test` (`backup.restore_scratch_database`) |
| **Not** the scratch database | `my_office_test` — the test suite owns that one and wipes it on every run (**D157**) |
| Archive disk | `backups` → `storage/app/backups`, outside the webroot, never the `public` disk |
| Offsite disk | `backup.offsite_disk` — S3, SFTP or a mapped drive; the 3-2-1 copy |
| Archive path pattern | `<type>/<YYYY-MM>/my_office-<type>-<YYYY-MM-DD-HHMMSS>.zip` |
| The dump inside the archive | `db-dumps/mysql-my_office.sql` |
| `mysql` / `mysqldump` clients | `backup.mysql_path`, `backup.mysqldump_path` — `C:/xampp/mysql/bin/` on XAMPP |

**Every schema statement runs as the migration user, never the application user** (**D58**, **D168**). The
application's own credentials hold `SELECT, INSERT, UPDATE, DELETE, EXECUTE` and nothing else, so they
cannot create the scratch database, cannot load a dump and cannot restore a trigger. That is deliberate:
an injection that reaches the database still cannot drop a table or disable a `BEFORE DELETE` trigger. It
also means a restore wired to the default connection fails on its first statement on a correctly hardened
host and succeeds on a developer's XAMPP, which is exactly the shape of bug that reaches production —
so the restore resolves `mysql_migration` explicitly. There is a second reason, and it is the one that
will bite you by hand: MariaDB stamps each of the nine `BEFORE DELETE` triggers with the DEFINER of
whoever ran `migrate`, and restoring a trigger whose DEFINER is not the current user needs `SUPER`, which
section 6.9.3 forbids on purpose. **Restore as the user that created the triggers.**

---

## What is in an archive

| Content | Database archive | Files archive |
|---|---|---|
| Every table except `backup.excluded_tables` | yes | — |
| `jobs`, `failed_jobs` | **yes** — a queued commission job is money that has been earned and not yet written | — |
| `cache`, `cache_locks`, `sessions`, `job_batches` | no — noise, and a restore should log everyone out anyway | — |
| Triggers, routines, events | yes (`--triggers --routines --events`) — the nine delete triggers are part of the schema's guarantees | — |
| `storage/app/public` — CMS media, avatars, banners | — | yes |
| `storage/app/private` — client and employee documents, CVs, expense receipts, invoice PDFs, course materials, submissions, certificates, ID cards, payout proofs, exports | — | yes |
| `storage/logs`, `storage/framework`, `node_modules`, `vendor`, `.git`, `storage/app/backups` | — | no |
| `.env` | — | **only** when `backup.encrypt_archives` **and** `backup.include_env` are both on; otherwise the operator keeps `.env` in the password manager, and this runbook is where that is written down (invariant HD-8) |

The last row is the one to understand before you need it. An `.env` holds the application key, the database
password and the mail credentials, so an unencrypted archive carrying one is not a backup of the system,
it *is* the system — including the offsite copy and including the copy somebody put on a laptop. The
default is therefore to leave it out, and `backup_runs.includes_env` records what was actually done rather
than what was configured. **If `.env` is not in the archive, a bare-metal rebuild needs it from the
password manager**; a database restore onto a working host does not need it at all.

`backup_runs` and `backup_restores` are themselves inside the dump unless somebody has put them on
`backup.excluded_tables`. That is not a footnote — it is why step 7 below writes an evidence file, and why
the restore's own record survives the restore.

---

## Where archives live, and for how long

`BackupRetentionService` stamps `retention_class` and `retention_until` when the run is created, and
`backup:prune` removes **files only**.

| Class | Kept for | Rule |
|---|---|---|
| `transient` | `backup.retention_keep_all_days` (7) | every backup, whatever bucket it falls in |
| `daily` | `backup.retention_daily_days` (30) | one per day |
| `weekly` | `backup.retention_weekly_weeks` (12) | the Sunday one |
| `monthly` | `backup.retention_monthly_months` (12) | the first of the month |
| `yearly` | `backup.retention_yearly_years` (3) | 1 January |

Five rules matter to you while restoring, each of them a test (DEP-15):

1. `backup.retention_min_copies` usable **database** archives always survive a prune.
2. The newest usable database archive is never pruned, whatever the dates say.
3. A `pre_restore` or `pre_deploy` backup is never pruned automatically
   (`BackupTrigger::isProtectedFromPruning()`). **This is why a rollback has something to go back to.**
4. **No `backup_runs` row is ever deleted.** Pruning sets `file_pruned_at` and `status = pruned`, so a row
   describing an archive that no longer exists on disk is normal and is evidence, not a bug. A `pruned`
   row cannot be restored — the file is gone, and the restore refuses it by name. Look for one that is
   `completed`.
5. When the store would exceed `backup.max_storage_gb`, the job **fails loudly** with a notification
   instead of pruning past policy. Running out of disk is an operations problem, not a licence to destroy
   history.

```bash
php artisan backup:prune --dry-run
```

Run that before any prune you are unsure about: it prints exactly what `prune()` would then do and touches
nothing.

---

## Proving an archive — the two levels

| Level | Command | What it proves | Cadence |
|---|---|---|---|
| Checksum | `php artisan backup:verify --latest` | the file on disk is byte-identical to what was written (`checksum_sha256`) and opens as a valid zip | daily 04:00 |
| Deep | `php artisan backup:verify --latest --deep` | the archive **restores**: into `backup.restore_scratch_database`, `migrate:status` shows nothing pending, the proof counts match, `financial:verify-constraints` passes on the restored copy, and wallet reconciliation reports zero structural failures. The scratch database is dropped afterwards and `verification_status` becomes `restore_ok` | weekly Sunday 04:30 |

The deep level counts the same 26 tables on both sides of every restore — the financial and identity
spine, from `users` and `roles` through the commission ledger, entitlements, wallets, payouts and
allocations to `student_fee_payments`, `project_payments`, `payment_reversals`, `invoices` and
`activity_log`. The list is fixed rather than derived from the schema, because a count over "every table"
changes meaning every time a phase ships a table, and a proof whose definition moves cannot be compared
with last month's.

**The deep level fails on purpose when a migration is pending**, because a dump restored into a codebase
that has moved on is not the schema this release runs against. That makes it a proof of *the archive
against the code you are running it from*, so run it from the tree that is actually live. Run from a staged
release that adds migrations, it fails and stamps a perfectly good archive `failed` — see DEPLOY.md step 4.

**A `backup_runs` row that never reached `restore_ok` is not a backup** for go-live purposes (GL-36,
invariant HD-6). Neither level ever repairs anything it finds: a proof that is allowed to edit the thing it
is proving is not a proof.

---

## The four gates

| Gate | What it is | Why it is there |
|---|---|---|
| Permission | `backups.restore`, granted to Super Admin only by default | the ability to overwrite today's data with last night's is the second most dangerous permission in the system (GL-52) |
| Re-authentication | Laravel's `password.confirm` on both the form and the submit, stamped onto `backup_restores.password_confirmed_at` | an unlocked laptop is not authorisation |
| Typed confirmation | `backup.restore_confirmation_phrase`, rendered with `{database}` and `{date}` substituted, compared **case-sensitively**, stamped onto `confirmed_at` | typing the name of the database you are about to overwrite is the last moment at which "wrong window" is recoverable |
| Written reason | `backup_restores.reason`, NOT NULL, at least 20 characters and at most 500 | "testing" does not pass review. In six months this sentence is the only explanation anybody has |

**The phrase, in full, so you are not guessing at the prompt.** The default is
`RESTORE {database} {date}`, `{date}` is rendered ISO — `Y-m-d`, never the display date format, because a
phrase typed exactly cannot be in a format where `03/04` means two different days depending on who
configured the install. So restoring `my_office` on 25 September 2026, you type:

```
RESTORE my_office 2026-09-25
```

Case included. If the client changed `backup.restore_confirmation_phrase`, the command prints the phrase it
wants, in full, ready to copy, every time it refuses — a gate that says "wrong phrase" and nothing else
teaches the operator to guess, and guessing at this prompt is how the wrong database gets eaten.

**On the console, gate 2 is honestly unmet.** There is no session to re-authenticate against, so
`password_confirmed_at` stays **null** and `notes` records why. That is not a shortcut, it is the opposite
of one: `BackupRestore::gatesSatisfied()` reads those stamps precisely so that in six months "did the gates
pass?" has an answer nobody reconstructed, and one fabricated stamp makes every genuine one worthless. A
console restore therefore reads as gates-unsatisfied on that one axis, truthfully, and the other three
gates are enforced in full. When the wizard of section 8.3 ships, it is the path that can satisfy all four.

The HTTP rate limiter `backup-restore` (1/hour, section 6.3.1) guards the screen and **does not apply to
the console** — deliberately, because during a recovery an operator may legitimately need three attempts in
ten minutes, and a limiter that locked the console for fifty minutes mid-incident would be a self-inflicted
outage. What stands in front of the console instead is the typed phrase, the written reason, the
pre-restore archive and the verification check.

DEP-16 asserts every gate, and asserts that in each failing case **no restore row reaches `running` and the
database is untouched**: no permission is a 403, no password confirmation redirects to the confirm screen,
a wrong-case phrase is a 422, a ten-character reason is a 422, and a `confirmed_at` forged into the payload
is ignored.

---

## Exit codes — three, and the third one is the point

| Code | Status | What it means for you |
|---|---|---|
| **0** | `completed` | restored, migrated forward, counted and proved |
| **1** | `aborted` | a gate refused, or the archive would not extract. **The target database was not written to.** Fix what it named and run it again |
| **2** | `failed` | it ran, and a proof did not hold. **The application is still down**, and the way back is the pre-restore archive whose id the command prints |

The difference between 1 and 2 is the first thing anybody needs at two in the morning: "nothing happened"
and "the database is half-restored" are not the same incident and do not wake the same person. A monitoring
wrapper that collapses them is worse than none.

---

## The restore, step by step

```bash
php artisan backup:restore --backup=<id> --target=production --database=my_office --reason="<at least 20 characters saying why>" --confirm="<the phrase, exactly>"
```

The thirteen steps below are what that command does, in order. Each says what it does, what success looks
like, and what to do when it fails. Watch them go past; if one of them does not appear, that is the
failure, and the step that did not appear is the one to read.

**`--target` is not a label — it decides how many gates stand in front of you.**

| `--target` | Phrase | Pre-restore backup | Maintenance mode | Notes |
|---|---|---|---|---|
| `production` | required | yes | yes | the only target allowed to name the live database, and it is refused if `--database` names anything else |
| `staging` | required | yes | yes | refused if `--database` names the live database — a staging runbook pasted onto a production console reads as a rehearsal right up to the moment it is not |
| `local` | skipped | skipped | skipped | **refused outright when `APP_ENV=production`**, because it is otherwise a one-word way to restore over the live database with no phrase and no safety net |

`--database` defaults to the database this application is connected to. It must be a plain identifier, it
is compared case-insensitively (MariaDB on Windows folds database names, and a case-sensitive check here
would be a check that passed immediately before it destroyed production), and it **may not be
`backup.restore_scratch_database`** — the weekly proof creates and drops that schema without asking anybody,
so a restore that landed there would be gone by 04:30 on Sunday.

**A restore into any database other than the application's own skips steps 8 to 11** and says so. Migrating,
cache-clearing and money-checking would all be aimed at production on the strength of a restore that never
touched it. To prove an archive without touching anything, use `php artisan backup:verify --backup=<id> --deep`.

## Step 1 — Announce

```bash
php artisan backup:restore --backup=<id> --target=production --database=my_office --reason="<why>" --confirm="<phrase>"
```

Before anything is touched, the console and the screen print the same three facts: which **database** is
about to be overwritten, which **archive** is going in, and that archive's **checksum and age**.

**Working looks like**: three facts you recognise. The database is the one you meant. The archive is the
one you meant. The age is what you expected — if the "latest" archive is four days old, your schedule has
been failing quietly and that is now the more urgent problem.

**When it fails**: an unknown `--backup=` id is a typo; `php artisan backup:verify --latest` names the
newest usable run. Every refusal at this stage exits **1** and has written nothing. If the age surprises
you, **stop here** — take a fresh backup of the current state first (`php artisan backup:run
--type=database --reason="before restore, current state"`) so that whatever is in the database now is
preserved before you replace it with something four days older.

## Step 2 — Verify the archive

```bash
php artisan backup:verify --backup=<id>
```

Re-computes the SHA-256 of the file on disk and compares it with `checksum_sha256`, and opens the zip. A
mismatch **stops the restore here**, before anything is down and before anything is overwritten.

**Working looks like**: `checksum_verified = true` on the restore row, and the archive's own
`verification_status` at `checksum_ok` or better. Better means `restore_ok`, and `restore_ok` is what you
want to see.

**When it fails**: a checksum mismatch means the file is not the file that was written — a truncated write,
a half-finished offsite copy, a failing disk. Do not force it; pick the previous archive and verify that
one instead, then read Appendix B. If the archive is `pruned`, the file is gone by policy and the row is
only a record that it existed; choose a `completed` row.

## Step 3 — Maintenance mode

```bash
php artisan down --secret="<ops.maintenance_secret>" --render=errors::503
```

The command does this for you, with the secret from settings. The public site and all five panels return a
branded 503 with `Retry-After`, and the operator can still browse the system through the secret URL —
`https://<host>/<secret>` sets a cookie, after which the site is normal for you and down for everybody
else. That is the whole point of the secret: you can look at the system you are restoring without letting
anyone write to it. Skipped for `--target=local`, where there is no audience.

**Working looks like**: `step 3  the site and all five panels are returning 503`, and an incognito window
confirming it on the public home page, on `/admin` and on one portal.

**When it fails**: a warning that `ops.maintenance_secret` is empty means there is no bypass URL, so you
cannot check the restored application before letting everybody back in — set it now, before you continue.
If maintenance mode cannot be entered at all the restore aborts with exit **1** and nothing is restored,
which is correct: a write arriving from a panel while the dump is going in lands in a database halfway
between two snapshots and is then overwritten by the rest of the dump — a lost write nobody will ever find,
because the request returned 200. Note this is **not** `maintenance.maintenance_mode` in Settings, which
shows the holding page on the public website only and never blocks `/admin` or the portals (DEP-23).

## Step 4 — Stop the queue worker

```bash
nssm stop MyOfficeQueue
# Linux: supervisorctl stop myoffice-queue:*
```

**This step is a question, not an action.** PHP cannot stop an nssm service or a supervisor group portably,
and a command that pretended to would be worse than one that asks — so the restore prints those two lines
and then asks *"Is the worker stopped?"*. Answer it honestly. Run with `--no-interaction` and it takes the
runbook's word for it and warns that it has.

A worker still running during the swap writes commission rows into a database that is being replaced under
it: the job is marked done, the row it wrote is gone, and the ledger is short one entry with nothing
anywhere to say so.

**Working looks like**: the service reporting stopped before you answer yes. On Linux, `supervisorctl
status` shows the workers as `STOPPED`.

**When it fails**: never `kill -9` a worker mid-transaction — `stopwaitsecs=3600` exists so the worker can
finish the job it is holding. Wait for it. If a worker genuinely will not stop, the jobs it is running are
in the archive anyway (`jobs` and `failed_jobs` are backed up on purpose), so the restored snapshot will
re-queue them and `commissions:sweep` will finish the work.

## Step 5 — Pre-restore backup

```bash
# Automatic — a database backup with BackupTrigger::PreRestore, linked on the restore row.
```

The command takes a backup of the current state and links it on
`backup_restores.pre_restore_backup_run_id`. **A failed pre-restore backup aborts the restore**, and that
refusal is the most valuable thing in this document: it is what makes the restore itself reversible.
Restoring over production without one means the state you just replaced is gone, and there is no undo for
an undo. The trigger is `pre_restore`, which `isProtectedFromPruning()`, so retention will never quietly
remove your way back.

**Working looks like**: a `completed` `backup_runs` row with `trigger = pre_restore`, a non-null
`checksum_sha256`, and its id on the restore row. **Write that id down now** — it is what you will need if
step 10 goes wrong.

**When it fails**: the restore exits **1** and the target database is untouched. `mysqldump` not found is
`backup.mysqldump_path` (`C:/xampp/mysql/bin/mysqldump.exe`, quoted). A disk-full failure here is a genuine
blocker — free space and start again rather than skipping the step. Only `--target=local` skips it
(`RestoreTarget::requiresPreBackup()`), because a developer machine has nothing to lose.

## Step 6 — Capture the before-counts

```bash
# Automatic — row_counts_before and ledger_rows_before, written into the INSERT.
```

Counts over the 26 proof tables, plus the ledger row count on its own, taken from the live database before
it is overwritten. They are written in the INSERT that creates the `backup_restores` row rather than
updated onto it afterwards, because `row_counts_before`, `ledger_rows_before` and
`pre_restore_backup_run_id` are outside that model's mutable columns for ever — on an append-only table a
value that is not in the INSERT can never be written at all (**D19**).

**Working looks like**: `row_counts_before` populated on the restore row. The screen shows it as the left
column of the before/after diff at the end.

**When it fails**: it does not fail on its own — a failure here is a database that is already unreachable,
which means you are in a rebuild rather than a restore. Rebuild the database and the schema first
(`INSTALL.md` steps 6 and 7), then restore into it.

## Step 7 — Restore the dump

```bash
"C:/xampp/mysql/bin/mysql.exe" --defaults-extra-file="<temp>" my_office < "<archive>/db-dumps/mysql-my_office.sql"
```

The command does this for you: it extracts the dump from the archive into a private workspace, writes a
temporary `--defaults-extra-file` holding the migration user's credentials, loads the dump, and deletes
that file in a `finally` block. **The password is never on the command line** — a command line is readable
by every other process on the machine through the process list, and it lands in shell history the first
time somebody reproduces the command by hand.

The extraction happens *before* the row flips to `running`, so a failure to extract exits **1** as an
abort: nothing was written. Once the load starts, this is the point of no return and a failure from here on
exits **2**.

**The restore overwrites the table this restore is being recorded in.** `backup_restores` and `backup_runs`
are inside the dump, so the load silently deletes the in-flight row and the pre-restore archive's row with
it, and every later save would update nothing while reporting success. So the row is serialised to a file
outside the database first — under `"C:/xampp/htdocs/my office/storage/app/backups/restore-evidence"` —
and re-inserted afterwards. **That path is printed whatever happens.** Evidence that lives only in the thing being replaced is not evidence,
and on a bad night that file is the only record of which archive went in and which pre-restore copy is your
way back.

**Working looks like**: exit 0 from the load. Nothing on stderr. The load takes as long as it takes; the
ceiling is one hour.

**When it fails**: `ERROR 1227 (42000): Access denied; you need SUPER privilege` is the trigger DEFINER
problem — you are loading the dump as a user other than the one that ran `migrate` (**D168**). Load it as
the migration user. `ERROR 2006 MySQL server has gone away` on a large dump is `max_allowed_packet`; raise
it in `my.ini` and re-run — a partial load is not a restore, so start the load again from the top rather
than continuing it. If you are typing this by hand, note the redirect reads the file **out of an extracted
archive**, not out of the zip: unzip it first, into a directory whose path you have quoted.

## Step 8 — Bring the schema forward

```bash
php artisan migrate --database=mysql_migration --force
```

The archive holds the schema as it was when the backup was taken. If the code is newer than the archive —
which it is whenever you are restoring after a deploy — the schema has to catch up. Always on the
**migration** connection: the application user cannot change the schema, deliberately (**D58**).

**Working looks like**: `php artisan migrate:status` shows nothing pending.

**When it fails**: the restore exits **2**: the archive is in but the schema is behind the code, and the
application stays down. `Database connection [mysql_migration] not configured` means the release predates
**D168**; add the connection rather than migrating as the application user, because an application user
that can migrate is an application user that can drop your ledger. A migration that fails halfway leaves a
half-applied schema — MariaDB DDL is not transactional (**D70**) — so read the error, fix the cause, and
re-run; the migrations of this system are written to be re-runnable.

## Step 9 — Clear the derived state

```bash
php artisan optimize:clear && php artisan permission:cache-reset
```

Config, routes, views, the compiled events and the spatie permission cache all describe the database you
just replaced. **Anything that touched roles, permissions, modules or settings needs both of these
commands**, and a restore touches all four by definition.

**Working looks like**: both exit 0. Then rebuild the caches you want in production —
`php artisan optimize` and `php artisan event:cache` — because an uncached production host is a slow
production host (INSTALL step 13).

**When it fails**: if the application behaves afterwards as though the restore never happened, it is
almost always one of three caches and not the restore: config/route/view, the permission cache, or a queue
worker still holding old code. `INSTALL.md` Appendix B is the short version of that.

## Step 10 — Prove the money

```bash
php artisan integrity:verify --suite=constraints
php artisan integrity:verify --suite=wallet
```

`constraints` asserts every CHECK constraint, generated column, unique guard and `BEFORE DELETE` trigger
the finance spine requires is present in the restored database. `wallet` reconciles every collaborator:
the wallet balance is a cache that must always be re-derivable by summing the ledger, and the closed
identity `lifetime = pending + available + reserved + paid` must hold. Two invocations, not one —
`--suite` takes a single suite name.

**Working looks like**: both exit 0. Constraints complete, reconciliation reporting **zero structural
failures**. Drift is reported, never auto-repaired — never pass `--repair` as part of a restore.

**When it fails**: **this is the step that decides the outcome, so read it now rather than then.** The
restore is recorded `failed`, it exits **2**, **the application stays down**, and the runbook's next line
is step 5: restore the pre-restore backup you took two minutes ago, whose id this command prints and whose
id is also in the evidence file from step 7. The way back is always the backup taken two minutes ago. Do
not bring the site up on a database whose money does not reconcile; a system that is down is an incident,
and a system that is up and silently wrong is a liability.

## Step 11 — Capture the after-counts

```bash
# Automatic — row_counts_after, ledger_rows_after and proof are written to the backup_restores row.
```

The same 26 counts, taken again, plus the full output of both proof commands stored in `proof`. They are
written whether or not the proof held: the diff is what tells an operator what actually happened, and a
failed restore is the case where it matters most.

**Working looks like**: a diff you can explain out loud. Rows lost are the rows written since the archive
was taken — that is expected and is the price of a restore. **Rows that went up are not**: an archive
cannot contain more than it contained, so an increase means you restored the wrong archive, or into the
wrong database, or the load did not replace what you thought it replaced.

**When it fails**: an empty `proof` with a `completed` status is a reporting bug worth raising, but it is
also a restore whose money was never proved. Re-run step 10 by hand and record the output in
`DEVELOPMENT_LOG.md` section 7 yourself.

## Step 12 — Restart the worker and leave maintenance

```bash
nssm start MyOfficeQueue
# Linux: supervisorctl start myoffice-queue:*
php artisan commissions:sweep
```

The command prints those lines and then lifts maintenance mode itself. Start the worker **before** you let
traffic in, so that the queued work inside the restored snapshot starts draining first, and run
`commissions:sweep` once by hand rather than waiting for the schedule if the restore lost hours — it
re-queues receipts and reversals the commission engine never finished.

**Working looks like**: `php artisan ops:health --deep` green — a fresh queue heartbeat, a fresh scheduler
heartbeat, no failed-job spike — and the public site plus all five panels responding.

**When it fails**: if the command reports `the site is STILL DOWN and "up" failed`, run `php artisan up`
yourself; that message exists because a restore that succeeded and left the site down is an outage with no
cause. A worker that starts and exits immediately is almost always a wrong `AppDirectory` — the space in
the path again, the quotes again.

## Step 13 — Record it

```bash
# Automatic — the backup_restores row reaches `completed`, one activity-log row carries the old and new
# counts and the reason, and every Super Admin is notified. Then write the human half yourself.
```

The machine records what it did, and the activity row names the actor the gates resolved rather than
"system" — a restore is the most consequential row in the log and it is the one place worth spending a
line of code on attribution. You record what it was for, in `DEVELOPMENT_LOG.md` section 6 (dated, naming
the archive and the rung) and — because a restore is rung 5 — with the client's written instruction quoted
in section 9.

**Working looks like**: a `completed` restore row, an activity row, a notification the Super Admins
actually received, and a log entry somebody who was not there could follow. Keep the evidence file path
the command printed until all of that is confirmed.

**When it fails**: a restore that nobody recorded did not happen, and the next person repeats the
incident. If the notification did not arrive, that is GL-44 — unmonitored monitoring is worse than none,
because it produces the belief that somebody would be told.

---

## Appendix A — Rehearsing a restore, and the recovery time objective

```bash
php artisan backup:verify --latest --deep
```

That is the rehearsal, automated: it restores the latest archive into `backup.restore_scratch_database`,
proves the restored copy, and drops the scratch database. It runs weekly, and GL-36 blocks go-live until
the latest archive is `restore_ok`.

GL-38 asks for something the command cannot give you: **a rehearsal performed by the client's own
operator, by hand, timed.** Do it on a quiet afternoon, with somebody to ask. Two things come out of it.
The first is a person who has done this before, which matters because on the day it is needed they will be
doing it under pressure, possibly at night. The second is the elapsed time, which is the recovery time
objective and the only honest answer to "how long would we be down?". Record both, with a name and a date,
in `DEVELOPMENT_LOG.md`:

> **GL-38** — full restore rehearsal into `my_office_restore_test` performed by `<operator name>` on
> `<date>`; elapsed 41 minutes; proof: constraints pass, reconciliation drift 0.00. Recovery time
> objective recorded as 45 minutes.

The scratch database is `my_office_restore_test`. It is **not** `my_office_test`, which the test suite owns
and wipes on every run (**D157**), and it is validated by name against the live database every time so that
a rehearsal cannot eat production. Note that `backup:restore` will refuse `--database=my_office_restore_test`
outright — the weekly proof drops that schema without warning, so the by-hand rehearsal is
`backup:verify --deep` plus a stopwatch, not a hand-driven `backup:restore`.

## Appendix B — When the only archive you hold is unproved

You are here because the newest archive is `unverified` or `failed`, or because its checksum did not match
in step 2. In order:

1. **Take a backup of the current state first**, whatever state it is in:
   `php artisan backup:run --type=database --reason="current state before restoring an unproved archive"`.
   A broken present is still information; do not overwrite it with an archive you do not trust.
2. **Verify the previous archives, newest first**: `php artisan backup:verify --backup=<id>`. Retention
   keeps `retention_min_copies` usable database archives precisely so that there is a second candidate.
3. **Check the offsite copy.** A local disk failure that truncated one archive often did not touch the
   offsite one; `offsite_copied_at` tells you which runs have a second copy.
4. **Prove the candidate before you commit to it**: `php artisan backup:verify --backup=<id> --deep`
   restores it into the scratch database and reconciles it, without touching production. It costs minutes
   and it answers the only question that matters.
5. **If nothing verifies**, restore nothing yet. Escalate, and say the true sentence out loud: there is no
   proven restore point. Then go back to why — a failing disk, a full disk, a schedule that has not run,
   an offsite copy nobody configured — because that is the incident, and the data loss is only its symptom.

**Never restore an archive that has failed verification in order to "see what is in it".** Restore it into
the scratch database instead, where a corrupt dump costs nothing.

## Appendix C — Rebuilding a host from nothing

A database restore assumes a working application. If the host itself is gone, the order is: `INSTALL.md`
steps 1 to 7 to get a runtime, a schema and the three database users; then `.env` from the password
manager (it is in the archive only when the archive is encrypted and `backup.include_env` was on); then
this document from step 7 to load the newest proven archive; then the files archive extracted over
`storage/app/public` and `storage/app/private`; then `INSTALL.md` steps 10 to 16 for the storage alias,
permissions, assets, caches, worker, scheduler and vhost. Do not skip step 10 of this document at the end
of that: a rebuilt host whose money has not been proved is not a restored system.

---

| Related | Document |
|---|---|
| Installing from nothing, step by step | [`INSTALL.md`](INSTALL.md) |
| Web server, TLS, the three database users, permissions, queue, scheduler, PHP | [`PRODUCTION.md`](PRODUCTION.md) |
| Deploying a release, and the pre-deploy backup this document restores | [`DEPLOY.md`](DEPLOY.md) |
| The rollback ladder — read it before choosing a restore | [`ROLLBACK.md`](ROLLBACK.md) |
| Go-live checklist, GL-34 to GL-39 | [`GO-LIVE.md`](GO-LIVE.md) |
| The contract this document implements | `docs/phases/phase-24-25.md` section 6.10 |
