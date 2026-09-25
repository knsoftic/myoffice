# ROLLBACK.md — undoing a release, rung by rung and phase by phase

This is the rollback procedure of **phase-24-25 section 6.12**. It is the other half of
[`DEPLOY.md`](DEPLOY.md), and it is written for the moment somebody wants to undo something — which is
exactly the moment to be precise about what can be undone and what cannot.

The ladder is contracted to be machine-checked. **DEP-20 (`test_rollback_table_is_honest`) is specified
to run the per-phase table below** — for every phase whose row says `migrate:rollback` is safe, the
rollback actually runs on a seeded database and `migrate` re-applies cleanly; for every row that says no, a
test asserts the rollback would be refused or destructive. **DEP-19
(`test_module_kill_switch_is_a_real_rollback`)** is specified to do the same for rung 1, against every
non-core module in the registry.

> **Neither test has been written.** There is no deployment test directory in this release, so nothing
> currently checks the table below against the software. Until DEP-19 and DEP-20 ship, **treat every "yes"
> in that table as a claim to re-verify on the day, on a copy, not as a proof.** The one row that has been
> exercised for real is Phase 24/25's own: both backup migrations were rolled back and re-applied on the
> dev and test databases on 2026-09-25.

---

## Read this first

**A migration rollback is not a data rollback.** `migrate:rollback` runs a `down()` method. It undoes a
*schema* change — a table, a column, an index — and the way it "undoes" a table is by dropping it, with
every row that was in it. It does not put back a value somebody changed, it does not un-send an email, it
does not un-receive a payment, and on a table holding real business data it is not a rollback at all: it is
a deletion with a reassuring name.

**A wrong financial figure is never corrected by undoing anything.** The money tables are append-only.
There is no `deleted_at` on the commission ledger, on fee payments, on project payments, on payouts or on
their allocations (**D16**, **D19**), and nine `BEFORE DELETE` triggers in the database refuse a `DELETE`
on those tables with SQLSTATE 45000 even when it is typed straight into MariaDB by a database
administrator. That is deliberate, and it is not an obstacle to work around. **A wrong commission is
corrected by inserting a reversing negative entry that references the original, with a written reason and an
actor** (`CLAUDE.md` section 1, rule 3). A wrong rate is a new effective-dated rule version. A wrong
payment is a reversal. The original row stays, because the original row is what makes the correction
auditable.

So before choosing a rung, be clear about which of these five things you actually want to undo:

| What you want to undo | Can it be undone? | How |
|---|---|---|
| **Code** — a release behaves wrongly | **yes, completely, in seconds** | rung 3. A release is a directory swap (**D57**), so the rollback is a rename |
| **A feature being available at all** | **yes, completely, with no data risk** | rung 1. The module kill switch 403s every route and hides the sidebar entry while leaving every row intact |
| **A setting** — a rate, a threshold, a format, a toggle | **yes** | rung 2. `SettingsService` logs the old and the new value, so the old value is recorded |
| **A schema change** | only sometimes, and only where the table below says so | rung 4, and read the per-phase table first |
| **Business data** — rows that were written | **no.** Not by a rollback | rung 6, a forward fix. Rung 5 (a restore) replaces *everything* with an older copy and loses everything written since; it is the last resort, not the undo button |

**Most incidents stop at rung 1**, which is why the module system exists at all. Go down the ladder in
order and stop at the first rung that solves the problem; every rung below the one you need costs more data
than the one above it.

---

## Quote every path. Every single one.

The project lives at `C:/xampp/htdocs/my office`, and that folder name contains a space (tech debt **T2**).
An unquoted path is by a wide margin the most common way these commands fail on this machine, and in a
rollback the failure is cruel: an unquoted rename in rung 3 renames nothing and reports success, so you
will believe you have rolled back while the broken release is still serving traffic. Forward slashes work
everywhere in PHP, Composer, Node and Apache on Windows; use them, inside double quotes, always.

| Fact | Value |
|---|---|
| Live tree | `C:/xampp/htdocs/my office` |
| Previous tree, kept at least seven days after a deploy | `C:/xampp/htdocs/my office.previous` |
| Database | `my_office`; every schema statement on the `mysql_migration` connection (**D58**, **D168**) |
| Maintenance secret | `ops.maintenance_secret` in Settings → Operations |
| The restore point a rollback depends on | the `pre_deploy` backup taken by DEPLOY.md step 2 |

**A rollback that changes the schema runs on the `mysql_migration` connection, never the default one.** The
application's own credentials cannot change the schema, deliberately, so `php artisan migrate:rollback`
without `--database=mysql_migration` fails with a privilege error on a correctly hardened host — and
succeeds on a developer's XAMPP, where the fallback lands on `root` (**D168**). If a rollback step fails on
privileges, the answer is never to re-run it as the application user.

---

## The ladder

| Rung | Action | Data risk | When |
|---|---|---|---|
| **1** | **Module kill switch** — switch the module off with a reason | **none.** Phase 2 guarantees no data is touched, and the toggle is audited | a feature misbehaves. This is the first move for 19 of the 25 phases |
| **2** | **Setting revert** — flip the offending key back | none | a rule, rate, format, threshold or toggle was wrong |
| **3** | **Code rollback** — swap `"my office"` back to `"my office.previous"` | none **if** no migration ran | the release is wrong and the schema did not change |
| **4** | **`migrate:rollback --step=N`** | **medium** — only where the per-phase table says "yes" | a structural defect in a migration that has written no business data yet |
| **5** | **Restore the pre-deploy backup** | **high** — everything written since the backup is lost | nothing else can fix it. Four authorisation gates and a written decision |
| **6** | **Forward fix** — a new additive migration, a new release | none | always preferable to rungs 4 and 5 once real data exists. For anything financial it is the **only** acceptable answer (invariant HD-7, `CLAUDE.md` section 1, rule 3) |

---

## Which rung, by phase

Find the phase that owns the misbehaving feature and start at its primary rung. The `migrate:rollback`
column is the one to read twice: **"no" means a rollback would destroy evidence, not that it would throw an
error.** Some of those rollbacks would run.

| Phase | Primary rung | `migrate:rollback` safe? | Kill switch | Notes |
|---|---|---|---|---|
| 1 Foundation, auth, RBAC | 3, then 6 | **no** — `users`, `roles` and `permissions` hold live identity from hour one | none; System modules are core | a bad permission grant is fixed by re-running the idempotent seeders, never by dropping tables |
| 2 Settings, modules, dashboard | 2, then 3 | only the three additive columns, and only before any value is set | dashboard widgets hide themselves per permission | a bad setting is a one-click revert; "Reset group" on the settings screen restores registry defaults |
| 3 Website CMS | 1 (`website_sections`, `pages`, `menus`) | yes, before content is authored | disabling `public_site_enabled` shows the holding page | call `PublicCache::bump()` after any rollback or the old HTML survives |
| 4 Services, portfolio, blog, careers | 1 per module | yes, before content exists | per module | a disabled public module 404s, which is the correct visitor experience |
| 5 CRM, clients, client panel | 1 (`leads`, `clients`) | no once leads exist | `clients` off also closes the client panel | `lead_conversions` are history; never roll them back |
| 6 Projects, tasks, time | 1 (`projects`, `tasks`, `time_tracking`) | no once a project exists | per module | tasks reference employees and collaborators; a rollback orphans time entries |
| 7 HR, attendance, payroll | 1, else 6 | **no** — `payroll_runs` are locked financial documents | `payroll`, `attendance`, `leaves` | a wrong payroll run is reversed by the phase's own reversal path |
| 8–9 Collaborators, referrals | 1 (`collaborators`), else 6 | **no** once a referral exists — a referral is attribution evidence | `collaborators` off also closes the collaborator panel and its commission routes | suspending a collaborator is a business action, not a rollback |
| 10–12 **Commission engine, wallet, payouts** | **6 only**; 1 as the emergency stop | **never.** The nine append-only tables, their triggers, their generated columns and their unique guards are the system's memory of money | `collaborator_commissions`, `collaborator_wallets`, `collaborator_payouts`, `student_fees`, `payments` — all 403 while data and queued jobs stay intact | a wrong commission is corrected by a manual adjustment or a reversal with a written reason; a wrong rate is a new effective-dated version. A restore loses receipts taken after the backup and needs the client's written instruction |
| 13 Finance: invoices, expenses | 6 | no once an invoice is sent | `invoices`, `expenses`, `income` | invoice numbers are gap-free; a rollback would reuse a number already printed |
| 14–17 Institute core | 1 per module | only `classrooms` / `timetable_entries` before use | `courses`, `students`, `batches`, `timetable`, `student_attendance` | attendance and progress are evidence for certificates — forward-fix |
| 18 Fees, installments, discounts | **6 only**; 1 as the stop | **never** — it shares the finance spine's tables | `student_fees`, `installments`, `fee_discounts`, `fee_reminders` | `student_fee_reminders` is the only table Phase 18 owns, and it still may not be rolled back once a reminder reached a parent |
| 19 Materials, assignments | 1 | yes, before submissions exist | `course_materials`, `assignments` | a submission is a student's work; never destroy it |
| 20 Exams, results | 1, else 6 | no once a result is published | `exams`, `results` | a published result is corrected by an audited edit |
| 21 Certificates, ID cards | 6 | **no** — a certificate number is public and verifiable | `certificates` off disables the public verification page — tell the client first | revoke a certificate through its own status, never by deleting it |
| 22 Tickets, meetings, messaging, notifications | 1 | yes, before messages exist | `support_tickets`, `messages`, `meetings`, `notifications` | a conversation is a record |
| 23 Reports, search, exports, audit | 3 | yes — it owns few tables | `reports`, `global_search` | a report is derived; rolling it back loses nothing |
| 24 Hardening | 3; the index-only migration **is** rollback-safe | yes | `system_health`, `integrity_checks` | dropping an index never loses data; rolling back the three ops tables loses backup history, so only before go-live |
| 25 Deployment | 3 | yes, before the first backup row exists | `backups` is core and cannot be disabled | **never roll back `backup_runs`** — it is the evidence that a restore point existed |

---

## Step 1 — Rung 1, the module kill switch

```bash
# Admin > Modules > (module) > Impact, then switch it off with a reason.
# Routes: admin.modules.index, admin.modules.impact, admin.modules.toggle.
```

This rung is a screen, not a command. Section 6.12 also names
`php artisan module:disable <slug> --reason=""`, and **that command has not shipped** — `php artisan list`
has no `module:` namespace in this release, so an operator who types it gets "Command is not defined" and
loses two minutes at the worst possible time. Use the admin screen; it is the path every test asserts
against anyway.

The module's routes 403 for **everyone, Super Admin included** — `Gate::before` denies a disabled module's
abilities before it grants Super Admin anything — its sidebar entry disappears, its public counterpart
404s, and **every row it owns is left exactly as it was**. Queued jobs already in flight complete. This is
the only rung with literally no data risk, and it is the right first move for nineteen of the
twenty-five phases.

Open the impact screen first (`admin.modules.impact`). It lists the module's dependents, because a module
with an enabled dependent cannot be switched off on its own: you either take the dependents down too, by
ticking the explicit cascade confirmation, or you leave it alone. The cascade is never implicit — a default
of "yes, take the others down as well" would turn one intended change into several unintended ones.

A disable **requires a reason of 5 to 255 characters on the server** (**D63**), not only in the form. It
lands in `modules.disable_reason` with `disabled_at` and `disabled_by`, and in the activity log. Write what
actually happened — "commission preview screen 500s on batch view, ticket 1182" — not "temporarily off".

**Working looks like**: the sidebar entry gone; every route of that module returning 403 for a Super Admin;
the public counterpart returning 404; row counts for its tables identical before and after. DEP-19 asserts
all four for every non-core module.

**When it fails**: a core module cannot be disabled and that refusal is correct — `backups`, the System
group and the authentication spine are core by definition, so for those phases the primary rung is 3. If
switching a module off does not stop the misbehaviour, you have the wrong module: use the impact screen to
find which one owns the route, rather than switching several off to see which helps.

## Step 2 — Rung 2, the setting revert

```bash
# Admin > Settings > (group): change the key back and save, or use Reset group.
# Routes: admin.settings.index, admin.settings.update, admin.settings.reset.
```

Like rung 1, this rung is a screen. Section 6.12 names `php artisan settings:reset <group>` and
`settings:set`; **neither has shipped** — there is no `settings:` namespace in `php artisan list` — so the
admin screen is the whole of this rung today. "Reset group" (`admin.settings.reset`) is the same operation:
it puts every key in the group back to its registry default, which is the right move when several keys were
changed together and nobody is sure which one did it.

A rule, a rate, a threshold, a date format, a throttle, a toggle. `SettingsService` writes the old and the
new value into the audit trail, which means the value you are reverting *to* is recorded somewhere even if
nobody wrote it down — look at the activity row for the change that caused the incident rather than
guessing.

Settings live in the database, so a revert needs no cache rebuild and no deploy. Three exceptions are worth
knowing before you go looking for them. **A `readonly` key cannot be changed from the form at all** and
`SettingsService` refuses it (**D62**): that covers `security.two_factor_enabled`, `ops.app_version` and
every `*_next_number` document counter — the counters because a settings form posts every field, and saving
a stale one would re-issue an invoice, ticket or collaborator number that somebody already quotes. And a
`.env` change is not a setting: it needs `php artisan config:cache` again (INSTALL step 13).

**Working looks like**: the behaviour returns to what it was, with an activity row naming the old value,
the new value and who changed it.

**When it fails**: if the setting reverts and the behaviour does not, run
`php artisan optimize:clear && php artisan permission:cache-reset` — **any change touching roles,
permissions, modules or settings needs both** — and restart the queue worker, which holds settings in
memory for the life of the process.

## Step 3 — Rung 3, the code rollback

```bash
cd "C:/xampp/htdocs/my office"
php artisan down --secret="<ops.maintenance_secret>" --render=errors::503
nssm stop MyOfficeQueue
cd "C:/xampp/htdocs"
Rename-Item "my office" "my office.failed"
Rename-Item "my office.previous" "my office"
cd "C:/xampp/htdocs/my office"
php artisan optimize:clear
php artisan optimize && php artisan event:cache && php artisan permission:cache-reset
Restart-Service Apache2.4
nssm start MyOfficeQueue
php artisan queue:restart
php artisan up
```

Three `cd`s, and they are not padding. You cannot run `artisan` from `C:/xampp/htdocs` because there is no
`artisan` there, and you cannot rename a directory your own shell is standing in — on Windows the rename
fails with a file-in-use error that names nothing useful. Go in, take the site down, come out, rename, go
back in.

The reverse of DEPLOY.md step 7, and the reason releases are directory swaps (**D57**). It takes seconds
and it risks no data — **provided no migration ran**. If step 8 of that deploy migrated, the old code is
now running against a newer schema, which usually works (migrations here are additive) and sometimes does
not; check the per-phase table above, and be ready for rung 4 or rung 6.

`Restart-Service Apache2.4` is not optional. With `opcache.validate_timestamps = 0` the old code is
invisible until the SAPI reloads, and every symptom of skipping it looks like a failed rollback: the bug is
still there, the version string is wrong, a route 404s.

**Working looks like**: `php artisan --version` from the live tree, the version stamp back to the previous
release, and the misbehaviour gone. Rename the broken tree to something you will recognise — `"my
office.failed"` — rather than deleting it; it is the evidence for the forward fix.

**When it fails**: on Windows a rename fails while any process holds a handle inside the directory — the
queue worker, an open console sitting in that folder, an editor, Apache. Close them; do not force it. If
`"my office.previous"` does not exist, the deploy that installed this release did not keep it, or somebody
cleaned it up early; you are down to rung 5 or rung 6, and this is the moment the seven-day rule in
DEPLOY.md Appendix B stops being bureaucracy.

## Step 4 — Rung 4, `migrate:rollback`

```bash
php artisan migrate:status
php artisan migrate:rollback --database=mysql_migration --step=1 --pretend
php artisan migrate:rollback --database=mysql_migration --step=1
```

Only for a structural defect in a migration **that has written no business data yet**, and only where the
per-phase table says yes. Read `migrate:status` first so you know exactly which migration `--step=1` will
take, and run `--pretend` before the real thing so you read the SQL before it executes. Because deploys
migrate with `--step`, each migration is its own batch, so a rollback moves one migration at a time instead
of unwinding the whole release.

Three things to hold in mind:

1. **The trigger-protected tables will refuse.** A `down()` that tries to delete rows from one of the nine
   append-only financial tables raises SQLSTATE 45000 from the database itself. That is the guard working,
   not a broken migration.
2. **MariaDB DDL is not transactional** (**D70**). A `down()` that fails halfway leaves the schema in the
   state it reached — half the indexes dropped, the table still there. Re-running `migrate` must then bring
   it forward again, which is why the migrations of this system ensure their constraints on every run rather
   than only at creation.
3. **A `down()` that drops a table drops its rows.** "Safe" in the table above means "safe on an empty
   table". The same rollback on a table that has been in use for a week is a deletion.

**Working looks like**: `--pretend` output you have read and understood, then `migrate:status` showing the
migration as pending, then `php artisan integrity:verify --suite=constraints` exiting 0 — because a rollback
that removed a CHECK constraint or a delete trigger has quietly removed a guarantee, and this is the command
that notices.

**When it fails**: if the rollback errors halfway, do not loop it. Read the error, bring the schema forward
with `php artisan migrate --database=mysql_migration --force`, and switch to rung 6. If it succeeds and
something is now missing that you did not expect to lose, stop and go to rung 5 while the pre-deploy backup
is still the newest thing you have.

## Step 5 — Rung 5, restore the pre-deploy backup

```bash
# The full procedure, gates included, is RESTORE.md. In outline:
php artisan backup:verify --backup=<the pre_deploy run id>
php artisan backup:restore --backup=<id> --target=production --database=my_office --reason="<why>" --confirm="<phrase>"
```

**Everything written since that backup is lost** — every fee receipt, every commission, every payout, every
ticket reply, every uploaded document row. This is why it is the second-to-last rung and why it has four
authorisation gates: the `backups.restore` permission, a password re-confirmation, a typed
case-sensitive confirmation phrase naming the database and the date, and a written reason of at least twenty
characters.

The restore point is the `pre_deploy` backup that DEPLOY.md step 2 took before it migrated;
`BackupTrigger::PreDeploy` is protected from pruning precisely so that it is still there when you need it.
The restore itself takes its own `pre_restore` backup first, so even this rung is reversible — read
[`RESTORE.md`](RESTORE.md) in full before starting, and in particular its rule that an archive whose
`verification_status` has never reached `restore_ok` must not be restored.

**Working looks like**: the thirteen steps of RESTORE.md, each one visible, with
`integrity:verify --suite=constraints` and `--suite=wallet` both green on the restored database at step 10
and a before/after diff you can explain out loud.

**When it fails**: if the money does not prove at step 10, the application **stays down** and the next move
is to restore the `pre_restore` backup taken two minutes earlier. Never bring the site up on a database
whose money does not reconcile. For anything financial, get the client's written instruction **before**
starting this rung and quote it in `DEVELOPMENT_LOG.md` section 9: a restore silently discards receipts that
staff have already handed to students, and nobody but the client can authorise that.

## Step 6 — Rung 6, the forward fix

```bash
php artisan make:migration <additive_change> --table=<table>
# then: DEPLOY.md, from step 1, with the fix as a new release.
```

A new additive migration and a new release. Nothing is undone; the system moves forward into a correct
state, and the record of what happened stays intact. **Once real data exists this is preferable to rungs 4
and 5, and for anything financial it is the only acceptable answer** (invariant HD-7, `CLAUDE.md`
section 1, rule 3).

What a forward fix looks like, by kind of defect:

| Defect | Forward fix |
|---|---|
| A wrong commission amount | a reversing negative ledger entry referencing the original, plus an audit record with the reason and the actor. The original entry stays |
| A wrong commission rate | a new effective-dated rule version. Past entries were correct under the rule that was effective when the money arrived |
| A payment recorded in error | a reversal through the payment's own reversal path, which cascades to the commission it generated |
| A column of the wrong type | a new migration that adds the correct column, backfills it, and leaves the old one in place until a later release drops it |
| A wrong status on a published document | the document's own audited status change, never a `DELETE` and never a rollback |
| Data written by a bug | a one-off, reviewed, reversible correction script that writes through the owning service — never a hand-typed `UPDATE` on a money table |

**Working looks like**: the misbehaviour gone, the history still complete, `php artisan integrity:verify`
green, and a `DEVELOPMENT_LOG.md` entry that explains the defect and the fix. **Omit `--suite` to run them
all** — the option takes one suite name and there is no `all` among them, so `--suite=all` exits 2 with
"Unknown suite". The nine are `constraints`, `wallet`, `schema`, `routes`, `isolation`, `uploads`,
`performance`, `security` and `backup`.

**When it fails**: if a forward fix seems impossible because "the data has to go", stop and check whether
what you actually need is a status, a reversal or a soft delete that already exists. Append-only tables have
reversal paths for exactly this reason, and the absence of one is a gap in the phase that owns it — which is
a contract question, not something to settle with a `DELETE` at 2am.

## Step 7 — Record what you did

```bash
# Automatic: every rung writes an activity-log row. Then write the human half yourself.
```

Every rollback, at any rung, produces three records:

1. **An activity-log entry with the reason.** Rungs 1, 2 and 5 write it themselves; for rungs 3, 4 and 6
   the release and the migration are the record, and the reason is yours to write.
2. **A dated `DEVELOPMENT_LOG.md` section 6 entry naming the rung**, what was wrong, and what you did.
3. **For rungs 4 and 5, the client's written instruction, quoted in section 9** of that log, with a date.

**Working looks like**: an entry somebody who was not there could follow, six months from now, without
asking you.

**When it fails**: **a rollback that nobody recorded did not happen, and the next person repeats the
incident.** That is the whole reason this step exists. Two hours of investigation are not reproducible from
memory, and the second occurrence of an unrecorded incident always costs more than the first.

---

## Appendix A — Choosing a rung in sixty seconds

1. **Is a whole feature misbehaving, and can it be off for an hour?** Rung 1. Nothing is at risk. Do it now
   and investigate afterwards.
2. **Did somebody change a setting today?** Rung 2. Check the activity log for the change before you assume
   it was the release.
3. **Did the release change the schema?** `php artisan migrate:status`. If nothing ran, rung 3 and you are
   done in two minutes.
4. **Is the data still correct?** If yes, never go below rung 4. A rollback is for schema and for code, not
   for rows.
5. **Is the data wrong?** Then the question is not "how do I undo this" but "what is the correct next
   entry" — rung 6. Only when the database is genuinely unusable does rung 5 beat rung 6, and then it needs
   the client's written instruction.

If two rungs both look right, take the higher one first: it is cheaper and it is reversible.

## Appendix B — Things that are not rollbacks, whatever they look like

| Tempting action | What it actually is |
|---|---|
| `php artisan migrate:fresh` | destroying the database. It is forbidden outside local development (`CLAUDE.md` section 1, rule 2) |
| `php artisan queue:flush` | discarding queued work, including commission jobs — lost money until `commissions:sweep` re-queues it. Read `queue:failed` and retry; never flush what nobody has read |
| A hand-typed `UPDATE` on a money table | an edit with no audit trail, no reversing entry and no actor. The triggers stop the `DELETE` version of this; nothing stops the `UPDATE` version except you |
| Adding `deleted_at` to an append-only table so a row can be hidden | removing the guarantee. One `->delete()` then hides a row from every aggregate while the wallet cache keeps the money (**D19**) |
| Editing a check so `golive:check` or an integrity suite goes green | a review failure. A finding is fixed, never documented away (invariant HD-1) |
| Restoring a backup "to see what was in it" | overwriting production. Restore into `backup.restore_scratch_database` instead — `php artisan backup:verify --backup=<id> --deep` does it for you and drops the copy afterwards |

---

| Related | Document |
|---|---|
| Deploying a release — and the pre-deploy backup rung 5 depends on | [`DEPLOY.md`](DEPLOY.md) |
| Backups, verification and the full restore procedure (rung 5) | [`RESTORE.md`](RESTORE.md) |
| Installing from nothing | [`INSTALL.md`](INSTALL.md) |
| Web server, TLS, database users, permissions, queue, scheduler, PHP | [`PRODUCTION.md`](PRODUCTION.md) |
| Go-live checklist, GL-01 to GL-52 | [`GO-LIVE.md`](GO-LIVE.md) |
| The contract this document implements | `docs/phases/phase-24-25.md` section 6.12 |
