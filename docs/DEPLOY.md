# DEPLOY.md — deploying a release

This is the deploy runbook of **phase-24-25 section 6.11**: every release after the first. The first
install is [`INSTALL.md`](INSTALL.md) and is a different procedure — it has no data to protect and nothing
to roll back to. From the second release onward, this file is the procedure.

The step list is contracted to be machine-checked. **DEP-18 (`test_migration_rehearsal_gate`) is
specified to assert that step 4 is executable and that a red rehearsal returns a non-zero exit the deploy
treats as fatal.** **That test has not been written**, so the rehearsal gate below is enforced by whoever
is running the deploy and by nothing else. Section 6.11 also
specifies `deploy/deploy.ps1` (Windows) and `deploy/deploy.sh` (Linux) as thin wrappers around these steps,
so that the runbook and the script cannot diverge. **Those scripts have not shipped yet** — there is no
`deploy/` directory in this release — so until they do, this file *is* the deploy, typed by hand, and the
steps below are what the scripts will be generated from. If a step is wrong, change the contract, then the
script, then this file — in that order.

---

## What this procedure guarantees, and what it does not

**A release is a directory swap, not an edit in place** (**D57**). The new tree is built beside the old
one, verified, and then the two are renamed; a code rollback is therefore a rename rather than a rebuild,
and it takes seconds. That is the single decision that makes everything else in this document affordable.

**A migration is not part of that guarantee.** Renaming the directory back gives you the old code; it does
not give you the old schema and it does not give you the old data. So the deploy takes a **pre-deploy
backup before it migrates** (step 2) and **rehearses the migration before it takes the site down** (step 4).
[`ROLLBACK.md`](ROLLBACK.md) is the other half of this document: read its first section now rather than
during an incident, because the question it answers — what can be undone and what cannot — has a different
answer for code, for schema and for money.

| Deploy step | Exists because of | Without it |
|---|---|---|
| 2, the pre-deploy backup | ROLLBACK.md rung 5 | there is no restore point from before this release, so the bottom rung of the ladder is missing |
| 4, the migration rehearsal | invariant HD-9 | the first time the release's migrations meet real data is on production, with the site down |
| 7, the directory swap | **D57** | a rollback is a rebuild under pressure instead of a rename |
| 14, the smoke test behind maintenance | GL-46 | the first person to find the broken screen is a client |

---

## Quote every path. Every single one.

The project lives at `C:/xampp/htdocs/my office`, and that folder name contains a space (tech debt **T2**).
An unquoted path is by a wide margin the most common way these commands fail on this machine, and during a
deploy the failure is expensive: an unquoted staging path unpacks the release into
`C:/xampp/htdocs/my` and leaves you with two half-trees to untangle, an unquoted rename in step 7 renames
nothing and reports success, and an unquoted `AppDirectory` in step 12 starts the worker in the wrong
directory where it will run the *old* code indefinitely. Forward slashes work everywhere in PHP, Composer,
Node and Apache on Windows; use them, inside double quotes, always. `icacls` is the one tool here that
wants backslashes — keep the quotes regardless.

| Fact | Value |
|---|---|
| Live tree | `C:/xampp/htdocs/my office` |
| Previous tree, kept for at least seven days | `C:/xampp/htdocs/my office.previous` |
| Staging area for the new release | `C:/xampp/htdocs/my office.releases/<timestamp>` |
| Database | `my_office`, migrated on the `mysql_migration` connection only (**D58**, **D168**) |
| Rehearsal / scratch database | `my_office_restore_test` (`backup.restore_scratch_database`) |
| Maintenance secret | `ops.maintenance_secret` in Settings → Operations |
| Version stamp | `ops.app_version` (see step 13 — it is `readonly`) |

**Migrations run on the `mysql_migration` connection, never the default one.** The application's own
credentials hold `SELECT, INSERT, UPDATE, DELETE, EXECUTE` and nothing else, so `php artisan migrate
--force` without `--database=mysql_migration` **fails with a privilege error on a correctly hardened host
and succeeds on a developer's XAMPP** (**D168**, DEP-07). That separation is the reason an injection which
reaches the database still cannot drop a table, disable a `BEFORE DELETE` trigger or rewrite a generated
column — the three ways money is edited without leaving a trace. If a step of this runbook fails on
privileges, the answer is never to run it as the application user.

---

## Before you start

| Have ready | Why |
|---|---|
| The release, and its version string | step 13 stamps it; every later `backup_runs` row carries it |
| A window agreed with the client | steps 5 to 15 are downtime |
| `ops.maintenance_secret` | step 5 puts the site down; without the secret you cannot see the deploy you are testing |
| The migration user's credentials | steps 4 and 8 |
| `DEVELOPMENT_LOG.md` open | steps 8 and 17 write to it |
| [`ROLLBACK.md`](ROLLBACK.md) read, not skimmed | step 16's failure path is "fix it or roll back", and the choice of rung is not a decision to make for the first time at 1am |

Work top to bottom. The steps people skip are 4, 11 and 14, and those are the three that turn into
incidents: an unrehearsed migration, new code that is not actually live, and a broken screen found by a
client rather than by you.

---

## Step 1 — Freeze

```bash
php artisan ops:health --deep
```

Announce the window, stop anybody from starting long work, and take the system's temperature. There are
eleven probes in all: nine standard ones — database, cache, queue heartbeat, scheduler heartbeat, failed
jobs, storage, backups, integrity runs and runtime — and `--deep` adds the two expensive ones, pending
migrations and disk space.

**Working looks like**: exit 0 and every probe green. Exit 1 is degraded, exit 2 is failed. **Nothing is
deployed over a degraded system**: if the queue heartbeat is stale, the deploy will look like it broke the
worker; if `failed_jobs` is already above threshold, you will not be able to tell your new failures from
the old ones.

**When it fails**: fix the named probe first. A stale scheduler heartbeat is usually the Windows task
account or the missing `Start in` (PRODUCTION.md section 6); disk space below the threshold must be fixed
before step 2, because a pre-deploy backup that cannot be written stops the deploy — correctly.

## Step 2 — Pre-deploy backup

```bash
php artisan backup:run --type=full --reason="pre-deploy <version>"
```

A full backup — database and files — **before the schema changes**. Passing `--reason` is what makes the
run manual rather than scheduled; its trigger is `pre_deploy`, and `BackupTrigger::isProtectedFromPruning()`
returns true for that trigger, so retention will never remove it while you might still need it. This is the
restore point the bottom rung of the rollback ladder uses, and every later step of this runbook is allowed
to be brave because this one ran.

**Working looks like**: one `backup_runs` row with `status = completed`, `trigger = pre_deploy`, a non-null
`checksum_sha256` and a plausible `size_bytes`. Confirm it, do not assume it.

**When it fails**: `mysqldump` not found is `backup.mysqldump_path` (`C:/xampp/mysql/bin/mysqldump.exe`,
quoted). A refusal saying a backup is already running is the `ops.backup.running` cache lock — wait for the
other one rather than forcing this one; two dumps of the same database competing for the same rows while
fees are being collected is the thing that lock exists to prevent. **Do not continue past a failed
pre-deploy backup.** A deploy without a restore point is a deploy you cannot undo, and you will not know
that until you need to.

## Step 3 — Stage the release

```bash
cd "C:/xampp/htdocs/my office.releases"
# unpack the release into "<timestamp>", then, from inside it:
composer install --no-dev --optimize-autoloader --classmap-authoritative
npm ci && npm run build
# copy ".env" from the live tree, and symlink or copy "storage"
php artisan about
```

The new tree is built beside the live one, while the live one is still serving traffic. `npm ci` installs
exactly the locked dependency tree — never `npm install` on a deploy host, because it may resolve versions
nobody tested. `--no-dev` keeps PHPUnit, Faker and the dev tooling off a production host.

**Working looks like**: `php artisan about` **from inside the staged tree** exits 0, `vendor/autoload.php`
and `public/build/manifest.json` both exist, and no `.map` files were shipped. Then run `composer audit` and
`npm audit --omit=dev`: no high or critical advisory (GL-18).

**When it fails**: `npm ci` without a `package-lock.json` means the release is incomplete, not the host —
go back to whoever cut it. If `php artisan about` cannot boot inside the staged tree, the usual causes are a
missing `.env` or a `storage` that was neither copied nor linked; fix it here, where the live site is still
up and nothing is at stake. **Keep `storage` as one shared directory across releases** — uploads, logs and
the archive store must not be re-created empty by every deploy.

## Step 4 — Rehearse the migration

```bash
# 4a. From the LIVE tree — prove the archive you are about to depend on.
php artisan backup:verify --latest --deep

# 4b. From the STAGED tree — read the SQL this release will run, against production's real schema.
php artisan migrate --database=mysql_migration --force --pretend
```

**This is the step that earns the deploy**, and it is two commands because the shipped tooling splits the
question in two. Run them in this order, from the trees named in the comments, and read both outputs.

**4a proves the restore point.** `backup:verify --latest --deep` restores the newest archive into
`backup.restore_scratch_database`, checks that nothing is pending, compares the proof counts of section
6.10.4 on both sides, runs `financial:verify-constraints` and reconciles every wallet **on the restored
copy**, then drops the scratch database and sets `verification_status = restore_ok`. That is invariant HD-6
in one command: a backup is not a backup until it has been restored.

**Run 4a from the live tree, never from the staged one.** The deep verification asks the restored copy
whether any migration is pending, and *throws* when the answer is more than zero — deliberately, because a
dump restored into a codebase that has moved on is not the schema this release runs against. Run from the
staged tree, this release's new migrations are pending by definition, so the command fails and stamps a
perfectly good production archive `verification_status = failed`. That is a self-inflicted GL-36 blocker
five minutes before a deploy.

**4b reads the migration without running it.** `--pretend` prints every statement this release would
execute and writes nothing, so it is safe against production from the staged tree. Read the SQL. A
`DROP`, a `MODIFY` on a money column, a `DELETE`, or a rename on a table that holds rows is a cancelled
deploy, not a surprise for step 8.

**What these two commands do not give you**, and section 6.11 asks for: *this release's* migrations actually
executed against a copy of today's data, with the money reconciled afterwards. The blocker is a grant, not
a missing idea — `backup:verify --deep` owns `backup.restore_scratch_database` and drops it when it
finishes, and `backup:restore` refuses to write to that schema for exactly that reason, so a rehearsal
needs a *second* schema that the migration user holds rights on. PRODUCTION.md section 3 grants
`my_office`.\* and `my_office_restore_test`.\* by name and nothing else. **Until that grant exists, treat
4a and 4b as the gate**, and cancel the deploy on either one. Never substitute a rehearsal that writes to
the live database in order to have rehearsed something.

**Working looks like**: 4a exits 0 with `restore_ok` on the latest archive, constraints complete and
reconciliation reporting zero structural failures; 4b prints migrations you recognise and statements you
are willing to run against real data.

**When it fails**: **a red rehearsal cancels the deploy.** Not "proceed carefully" — cancel. You have
learned, for free and with the site still up, something you would otherwise have learned with the site
down and the schema half-changed. Fix the migration, cut a new release, rehearse again. A failure in 4a
that looks like a privilege error is usually the scratch-schema grants or the trigger DEFINER problem — the
migration user needs rights on `my_office_restore_test` by name, and the nine delete triggers can only be
restored by the user that created them (PRODUCTION.md section 3, **D168**).

## Step 5 — Maintenance mode

```bash
php artisan down --secret="<ops.maintenance_secret>" --render=errors::503
```

From here to step 15 the system is down. The public site and all five panels return a branded 503 with
`Retry-After`, and you can still browse everything through the secret URL — visit
`https://<host>/<secret>` once and the cookie it sets makes the site normal for you and down for everybody
else. That is what makes step 14's smoke test possible.

**Working looks like**: an incognito window shows the branded 503 on the public home page, on `/admin` and
on one portal; your own window, after the secret URL, shows the application.

**When it fails**: an unstyled 503 means `--render=errors::503` was dropped. An empty
`ops.maintenance_secret` means there is no bypass URL at all, so you would be taking the site down with no
way to look at it — set it before step 5, not after. This is **not**
`maintenance.maintenance_mode` in Settings, which shows the holding page on the public website only and
never blocks `/admin` or the portals (DEP-23) — for a deploy you want `artisan down`, which blocks
everything, including the panels whose users would otherwise write to a half-migrated schema.

## Step 6 — Stop the queue worker

```bash
nssm stop MyOfficeQueue
# Linux: supervisorctl stop myoffice-queue:*
```

No job may run against a half-migrated schema. A job that reads the old columns and writes the new ones is
a corrupted row nobody finds for weeks.

**Working looks like**: the service reports stopped; on Linux `supervisorctl status` shows `STOPPED`.

**When it fails**: never `kill -9` a worker mid-transaction — `stopwaitsecs=3600` exists so it can finish
the job it holds. Wait. Queued work is not lost by waiting; it is lost by killing.

## Step 7 — Swap

```bash
cd "C:/xampp/htdocs"
# Windows:
Rename-Item "my office" "my office.previous"
Rename-Item "my office.releases/<timestamp>" "my office"
# Linux: mv /var/www/myoffice /var/www/myoffice.previous && mv /var/www/releases/<timestamp> /var/www/myoffice
cd "C:/xampp/htdocs/my office"
php artisan --version
```

Two renames. This is the deploy (**D57**), and the reverse of these two renames is the code rollback
(ROLLBACK.md rung 3). **Note the `cd` back in**: you renamed the directory you were standing in, so every
`artisan` call from step 8 onward needs a shell that is inside the new tree — and on Windows a shell
sitting inside the old one is also the commonest reason the rename itself refuses.

**Working looks like**: `php artisan --version` from the new tree prints the framework version, and
`"C:/xampp/htdocs/my office.previous"` exists and is the release you were running five minutes ago.

**When it fails**: on Windows a rename fails while any process holds a handle inside the directory — the
queue worker (step 6), an open console sitting in that directory, an editor, or Apache itself. Close them;
do not force it. **Do not delete `"my office.previous"`**: keep it at least seven days, then archive it.
Disk is cheaper than a bad evening.

## Step 8 — Migrate

```bash
php artisan migrate --database=mysql_migration --force --step
```

The schema moves forward, on the migration connection (**D58**, **D168**). `--step` records each migration
as its own batch so that a later rollback moves one migration at a time instead of unwinding the whole
release.

**Working looks like**: `php artisan migrate:status` shows nothing pending, and
`php artisan integrity:verify --suite=constraints` exits 0 — proof that every CHECK constraint, generated
column, unique guard and delete trigger the finance spine requires still exists after the change. **Paste
the command's output into `DEVELOPMENT_LOG.md` section 6**; in six months it is the only record of what
this release did to the schema.

**When it fails**: this is the situation step 4 exists to prevent, so if it happens anyway, read the error
before doing anything. MariaDB DDL is not transactional (**D70**): a migration that failed halfway may have
created the table and not its constraints, and re-running it must therefore be safe — the migrations of
this system ensure constraints on every run for exactly that reason. Do **not** reach for
`migrate:rollback` reflexively; ROLLBACK.md rung 4 lists, per phase, where a rollback is safe and where it
destroys evidence, and for anything financial the answer is a forward fix.

## Step 9 — Reference data

```bash
php artisan db:seed --class=ProductionSeeder --force
```

Modules, permissions, roles, setting keys, payment methods, leave types, salary components, categories and
website sections. The seeder is idempotent by construction (`firstOrCreate` / `syncPermissions`) and
**deletes nothing**, which is what makes it safe as a deploy step: new modules, permissions, roles and
setting keys appear, and nothing existing is touched.

**Working looks like**: exit 0, and running it twice changes nothing. New permissions from this release
exist; the roles that should hold them hold them.

**When it fails**: if permissions look wrong afterwards, run step 10 before you debug anything else — a
stale spatie permission cache explains most of it. A seeder that reports a duplicate-key error is a seeder
that is not idempotent, which is a bug in the release, not something to work around by editing rows by
hand.

## Step 10 — Rebuild the caches

```bash
php artisan optimize
php artisan event:cache
php artisan permission:cache-reset
```

`optimize` compiles config, routes, views and the event map; `event:cache` is named again because the
contract names it and because a release that changed a listener and skipped it is indistinguishable from
one that did not deploy. **Step 9 touched modules, permissions, roles and settings, so
`permission:cache-reset` is mandatory here, not optional** — and the same rule holds any other time you
change one of those four things.

**Working looks like**: `php artisan about` reports Config, Routes, Views and Events as **CACHED**. That
`about` call is itself the proof of GL-04: once configuration is cached, an `env()` call outside `config/`
returns `null`, so an application that still boots and reports correctly has no such call on its boot path.

**When it fails**: `php artisan optimize:clear` undoes all of it in one command; run that first whenever
the application behaves as though your change never happened, then rebuild. If a route from this release
404s, the route cache was built before the route existed — clear and rebuild rather than looking for the bug
in the controller.

## Step 11 — Reload the SAPI

```bash
Restart-Service Apache2.4
# Linux: systemctl reload apache2
```

With `opcache.validate_timestamps = 0` in production, **new code is invisible until the SAPI restarts.**
Every symptom of skipping this step looks like a failed deploy — the fix is not live, the version string is
old, a new route 404s — and every one of them is one restart away.

**Working looks like**: Apache comes back, the site still answers the 503 (you are still in maintenance
mode), and the secret URL now shows the new code.

**When it fails**: Apache refusing to start after a deploy is nearly always a vhost path that no longer
resolves — check `C:/xampp/apache/logs/myoffice-error.log` and remember that the vhost points at
`"C:/xampp/htdocs/my office/public"`, which after step 7 is the new tree. Never expose a web-reachable
`opcache_reset()` route as a shortcut: it is an unauthenticated denial-of-service lever.

## Step 12 — Restart the queue worker

```bash
nssm start MyOfficeQueue
# Linux: supervisorctl start myoffice-queue:*
php artisan queue:restart
```

The worker holds code in memory, so it must be restarted with every deploy — a worker still running last
release's code is the classic "the fix did not take" bug, and it is invisible because the web tier is
correct.

**Working looks like**: `php artisan ops:health` shows a fresh queue heartbeat within a minute or two.

**When it fails**: check `storage/logs/queue.err.log` first. A worker that starts and exits immediately is
almost always a wrong `AppDirectory` — the space in the path again. If the release renamed a job class,
`failed_jobs` will collect the old ones; read them, do not `queue:flush` them, because a flushed commission
job is lost work until `commissions:sweep` re-queues it.

## Step 13 — Stamp the version

```bash
# Section 6.11 specifies: php artisan settings:set ops.app_version "<version>"
# That command has not shipped. Read the paragraph below before doing anything.
php artisan golive:check --json
```

`ops.app_version` is what the health screen shows, what `ops:digest` reports, and what every later
`backup_runs` row carries, so that a restored archive can say which release made it. It is how anybody
answers "which release is this?" without guessing from a file date.

**There is currently no shipped way to set it.** The key is declared `readonly` in
`App\Support\SettingsRegistry` — on purpose, because a version somebody types is a version that is wrong
the first time somebody forgets to — so `SettingsService` refuses it from the settings form (**D62**), and
the `settings:set` command of section 6.11 and the `deploy/` scripts that were to write it have not landed.
Until one of them does, `golive:check` will report GL-05 unstamped and you record the deployed version in
`DEVELOPMENT_LOG.md` section 6 instead, beside the migration output from step 8. Do not work around it by
editing the `settings` table by hand: a value written past the service has no audit row, and the next
person will not know where it came from.

**Working looks like**: today, `golive:check` naming GL-05 as the only open non-blocker, and the version
written into the log. Once the stamp exists, the system health screen showing the version you staged in
step 3.

**When it fails**: GL-05 is a non-blocker — an unstamped version will not hurt anyone today, but the next
person to debug an incident will not know which code they are looking at. Write it in the log now.

## Step 14 — Smoke test behind maintenance

```bash
# In a browser, through the secret URL, while the site is still down for everybody else.
```

Log in. Open the dashboard. Open one index screen. Open one money screen — a fee payment, an invoice or a
collaborator wallet. Open one public page. This takes four minutes and it is the last cheap moment of the
deploy.

**Working looks like**: no 500, nothing in the browser console, and `X-Query-Count` inside budget on the
screens that report it. The money screen shows figures you recognise.

**When it fails**: **this is why the site is still down.** A 500 here is a decision point, not a puzzle to
solve with users waiting: fix it now if the cause is obvious and local, otherwise roll back
(ROLLBACK.md — rung 3 if no migration ran in step 8, rung 4 or 6 if one did). Do not bring the site up
"to see whether real traffic reproduces it".

## Step 15 — Up

```bash
php artisan up
```

Maintenance mode ends. The public site and all five panels respond.

**Working looks like**: an incognito window loads the public home page and the login screen; a real user
can log in.

**When it fails**: if `artisan up` says the application is not in maintenance mode, somebody else brought
it up — find out who, and whether they did it before step 14 finished. If the 503 persists for visitors
after `up`, you are looking at a cached response or a proxy; check the `Cache-Control` on the 503 and the
proxy's own cache before touching the application.

## Step 16 — Verify

```bash
php artisan golive:check
```

The full go-live checklist, re-run against the release you just deployed. It evaluates every machine row
and prints the manual ones as explicit ticks. [`GO-LIVE.md`](GO-LIVE.md) explains every row and who signs
it.

**Working looks like**: exit 0. Exit 2 names the blocker.

**When it fails**: fix the named row, or roll back. **Do not loosen a check to make the command green** —
the command's whole value is that it disagrees with people (invariant HD-1). A new blocker introduced by
this release is the release's problem: a missing security header, a demo account left on a seeded password,
an unverified latest backup and a drifted wallet are all things a deploy can cause and all things this
command will catch in the minute after you caused them.

## Step 17 — Watch

```bash
php artisan ops:digest --dry-run
# and, for the first hour:
Get-Content "C:/xampp/htdocs/my office/storage/logs/laravel.log" -Wait -Tail 50
```

Watch `storage/logs` for an hour, and read the digest — errors by class, failed jobs, backup status, the
last reconciliation, wallet drift, lazy-load violations, slow queries and 429 counts. `--dry-run` assembles
and prints it without sending it or stamping that today's digest has gone, so reading it now does not stop
the scheduled one from reaching the people who are meant to receive it in the morning.

**Working looks like**: no new error class, no lazy-loading violation, no 429 spike, `failed_jobs` flat.
Then write the deploy into `DEVELOPMENT_LOG.md` section 6, dated, with the version and the migration output
from step 8.

**When it fails**: a new error class in the first hour is this release's, whatever it claims to be about. A
burst of 429s means a limiter is now keying something it did not key before. A lazy-loading violation is
logged rather than thrown in production (**D59**) precisely so a missed `with()` does not 500 a paying
client — but it is still a defect, and it is cheapest to fix while you still remember what you changed.

---

## Appendix A — If the deploy has to be abandoned

The decision is [`ROLLBACK.md`](ROLLBACK.md)'s, and the ladder there is in order of increasing data risk.
Where you are in this runbook narrows the choice:

| Abandoned at | Rung | Why |
|---|---|---|
| Steps 1 to 4 | none | nothing has changed; the live tree is untouched and the site never went down. Delete the staged release |
| Steps 5 to 7 | rung 3 | rename `"my office.previous"` back, `optimize:clear`, rebuild caches, reload Apache, `queue:restart`, `php artisan up`. **No migration has run**, so no data is at risk |
| After step 8 | rung 4 or rung 6 | a migration has run. Read ROLLBACK.md's per-phase table before touching `migrate:rollback`; for anything financial the answer is a forward fix, never a rollback |
| Data is already wrong in production | rung 5, or rung 6 | the pre-deploy backup of step 2 is the restore point, and [`RESTORE.md`](RESTORE.md) is the procedure. It loses everything written since step 2, which is why it is the last rung |

Whatever you choose, the site stays down until the choice is made and verified. A half-deployed system
serving real users writes rows that the next hour will have to untangle.

## Appendix B — The seven-day rule

`"C:/xampp/htdocs/my office.previous"` is kept for at least seven days and then archived, and the
pre-deploy backup is protected from pruning for as long as retention keeps it. Both exist for the same
reason: the defects a release introduces are usually found by users during the following week, not by you
during the following hour. Deleting either one early converts a two-minute rename into a restore.

## Appendix C — When a step behaves as if your change never happened

In this order: `php artisan optimize:clear`, `php artisan permission:cache-reset`,
`Restart-Service Apache2.4`, `php artisan queue:restart`.

Four out of five "the deploy did not take" reports on this system are one of four caches — the
config/route/view cache, the spatie permission cache, opcache (which needs the SAPI reload of step 11,
because `validate_timestamps = 0`), or a queue worker still holding the previous code. Clear those four
before you debug anything else, then rebuild the caches of step 10.

---

| Related | Document |
|---|---|
| Installing from nothing, step by step | [`INSTALL.md`](INSTALL.md) |
| Web server, TLS, database users, permissions, queue, scheduler, PHP | [`PRODUCTION.md`](PRODUCTION.md) |
| Rollback ladder, per phase — the other half of this document | [`ROLLBACK.md`](ROLLBACK.md) |
| Backups, verification and the restore procedure | [`RESTORE.md`](RESTORE.md) |
| Go-live checklist, GL-01 to GL-52 | [`GO-LIVE.md`](GO-LIVE.md) |
| The contract this document implements | `docs/phases/phase-24-25.md` section 6.11 |
