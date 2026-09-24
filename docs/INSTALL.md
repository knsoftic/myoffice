# INSTALL.md — installing MyOffice ERP from nothing

This is the installation runbook of **phase-24-25 section 6.8**. It is written for an operator who has
never read the architecture contract: every step says what it does, what it looks like when it worked,
and what to do when it did not.

The step list here is not a suggestion and it is not editable prose. **DEP-01
(`test_install_guide_matches_this_contract`) parses this file and compares its numbered steps — ids and
commands — one for one against section 6.8.** If you add a step, remove one, renumber, or improve a
command, that test goes red and the build fails. Change the contract first, then this file. That is the
whole point: a runbook that drifts from the contract is worse than no runbook, because it is trusted.

---

## Before you start

**Quote every path. Every single one.**

The project lives at `C:/xampp/htdocs/my office` and that folder name contains a space (tech debt **T2**).
An unquoted path is by a wide margin the most common way these commands fail on this machine, and the
failure is rarely honest about its cause: `cd C:/xampp/htdocs/my office` does not say "you forgot the
quotes", it says `The system cannot find the path specified` — or worse, it succeeds into
`C:/xampp/htdocs/my` and every later command runs in the wrong directory. Forward slashes work everywhere
on Windows in PHP, Composer, Node and Apache; backslashes only work in `cmd`, PowerShell and `icacls`.
So: forward slashes, always inside double quotes.

| Fact | Value |
|---|---|
| Base path | `C:/xampp/htdocs/my office` |
| PHP | `C:/xampp/php/php.exe`, 8.2 or newer |
| Database engine | MariaDB 10.4 (XAMPP) |
| Application database | `my_office` |
| Test database | `my_office_test` (created empty; `php artisan test` owns it and wipes it freely) |
| Restore scratch database | `my_office_restore_test` (`backup.restore_scratch_database`; never the live one) |
| Currency / timezone | PKR, `Asia/Karachi` |

Read [`PRODUCTION.md`](PRODUCTION.md) alongside this file. Steps 6, 11, 14, 15 and 16 are one-line
pointers here because their content is production configuration and lives there in full — the vhost, the
SQL for the three database users, the file permissions, the queue service and the scheduler task.

Work top to bottom. Do not skip a step because the previous install did not need it; steps 11, 13 and 16
are the ones people skip and they are the ones that end up as an incident.

---

## Step 1 — Check the runtime

```bash
php -v
php -m
composer --version
node -v
mysql --version
```

Five one-line checks before anything is written to disk, because every failure below this point is
cheaper than a failure above it.

**Working looks like**: PHP 8.2 or newer; `php -m` lists `bcmath`, `curl`, `gd`, `mbstring`, `openssl`,
`pdo_mysql`, `zip`, `exif`, `fileinfo`, `json`, `tokenizer` and `xml` (`intl` is optional); Composer 2.x;
Node 20 or newer; MariaDB 10.4 or newer.

**When it fails**: a missing extension is a `php.ini` edit, not a reinstall — uncomment the matching
`extension=` line in `C:/xampp/php/php.ini` and restart Apache. `bcmath` is not negotiable: all money
arithmetic goes through `App\Support\Money`, which is bcmath, and without it the application will not
boot. If `php` or `composer` is not on the PATH, add `C:/xampp/php` to it rather than typing the full
path into every later step. If `mysql` is not on the PATH, it is at `C:/xampp/mysql/bin/mysql.exe`.

## Step 2 — Get the code

```bash
cd "C:/xampp/htdocs"
# unpack the release archive into "my office", or clone it:
git clone <repo> "my office"
```

The release lands in one directory and never spreads outside it. Unpacking a release archive and cloning
the repository are equivalent here; a production host normally unpacks a release, a staging host clones.

**Working looks like**: `"C:/xampp/htdocs/my office/artisan"` exists.

**When it fails**: if the unpack created `my office/my office/artisan`, you unpacked one level too deep —
move the inner folder's contents up rather than adjusting every path that follows. If `git clone` wrote
to `C:/xampp/htdocs/my` you dropped the quotes; delete that folder and run it again.

## Step 3 — PHP dependencies

```bash
cd "C:/xampp/htdocs/my office"
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

Installs production dependencies only and compiles a fully authoritative classmap, so no class lookup
touches the filesystem at runtime. `--no-dev` is deliberate: PHPUnit, Faker and the dev tooling have no
business on a production host.

**Working looks like**: exit 0, and `vendor/autoload.php` exists. Then run `composer audit` — it must
report no high or critical advisory.

**When it fails**: a `zip` or `curl` extension error is step 1 coming back; fix the extension and re-run.
A memory exhaustion is solved with `php -d memory_limit=-1 "C:/ProgramData/ComposerSetup/bin/composer.phar" install ...`
rather than by raising the global limit. On a development machine, drop `--no-dev` — but never on the
host that will serve real users.

## Step 4 — Environment file

```bash
# Windows:
copy .env.example .env
# Linux / macOS:
cp .env.example .env
```

`.env.example` is the complete key list (phase-24-25 section 6.8.1) and holds no secret — DEP-03 asserts
both. Copy it, then fill in the real values: `APP_URL`, `DB_PASSWORD`, `DB_MIGRATION_PASSWORD`,
`DB_BACKUP_PASSWORD` and the mail keys. Leave `APP_KEY` empty; step 5 writes it.

**Working looks like**: `.env` exists and every key present in `.env.example` is present in it.

**When it fails**: never edit `.env.example` to make `.env` easier to produce — it is an asserted
artefact. Runtime mail is configured in **Settings → Mail** (encrypted in the database) and overrides the
`MAIL_*` keys at boot, so treat those keys as a fallback only. Do not commit `.env`; do not put it
anywhere under `public/`.

## Step 5 — App key

```bash
php artisan key:generate --force
```

Writes the application encryption key. Every encrypted setting — SMTP password, backup archive password,
health token, maintenance secret, error-monitoring DSN — is encrypted with it.

**Working looks like**: `.env` now has `APP_KEY=base64:...`, and it is not the value from `.env.example`.

**When it fails**: if the key already exists, `--force` overwrites it — and **that destroys every existing
encrypted value in the database**. On a fresh install that is harmless. On a host that already has data,
stop: recovering an encrypted setting whose key was rotated means re-entering it by hand, and an archive
password you cannot recover means an archive you cannot restore. Rotate keys deliberately, never as a
troubleshooting step.

## Step 6 — Database + users

```bash
# Run the SQL of PRODUCTION.md section 3 as the database root user.
```

Creates the `my_office` database (utf8mb4 / utf8mb4_unicode_ci) and **three** database users with
different privileges: `my_office_app` for the running application (DML only — it can never change the
schema), `my_office_migrator` for migrations (DDL plus `TRIGGER`, because the finance spine creates nine
`BEFORE DELETE` triggers), and `my_office_backup` for dumps (read and lock only). The same SQL closes the
XAMPP defaults: it puts a password on `root` and drops the anonymous users (tech debt **T3**).

Create `my_office_test` in the same session — `phpunit.xml` points at it and the test suite rebuilds it
on every run, so it must exist and must never be the live database.

**Working looks like**: `SHOW GRANTS FOR 'my_office_app'@'127.0.0.1'` contains no `ALTER`, `DROP`,
`CREATE`, `GRANT OPTION`, `SUPER`, `FILE` or `PROCESS`. That separation is what DEP-07 asserts, and it is
the reason a compromised application cannot drop a table.

**When it fails**: `Access denied for user 'root'@'localhost'` after you set the root password means the
password is now required — pass `-p`. If a later step reports `Access denied ... for table 'migrations'`,
you are running migrations on the wrong connection; that is step 7.

## Step 7 — Schema

```bash
php artisan migrate --database=mysql_migration --force --step
```

Builds the schema on the **migration** connection, not the runtime one. `--step` records each migration
as its own batch, so a rollback later moves one migration at a time instead of unwinding a whole release.

**Working looks like**: `php artisan migrate:status` shows every migration `Ran`, and
`php artisan integrity:verify --suite=constraints` exits 0 — proof that every CHECK constraint, generated
column, unique guard and delete trigger the finance spine requires actually exists in this database.

**When it fails**: `Database connection [mysql_migration] not configured` means `config/database.php` in
your release has no `mysql_migration` connection yet; add it (it is identical to `mysql` but reads
`DB_MIGRATION_USERNAME` / `DB_MIGRATION_PASSWORD`) rather than migrating as the application user — an
application user that can migrate is an application user that can drop your ledger. A trigger-creation
error means the migration user is missing `TRIGGER`; re-run the grant from PRODUCTION.md section 3 and
`FLUSH PRIVILEGES`. If `integrity:verify --suite=constraints` reports missing objects, do not continue:
the money rules are enforced in the database and a schema missing them is a schema that will silently
accept a double commission.

## Step 8 — Reference data

```bash
php artisan db:seed --class=ProductionSeeder --force
```

Seeds everything the system needs to exist and nothing that belongs to a business: modules, permissions,
the 18 roles, every setting key at its registry default, one default branch, payment methods, leave types,
salary components, finance and course categories, the website sections (disabled), an empty FAQ set and
the four policy pages as drafts.

**Working looks like**: the command exits 0, and running it a second time changes nothing. The seeder is
idempotent by construction (`firstOrCreate` / `syncPermissions`) and deletes nothing, which is why it is
also safe as a deploy step.

**When it fails**: **it creates no user** — that is intentional, not a bug (DEP-06 asserts no seeder
anywhere creates a user with a literal password). The first account comes from step 9. If permissions look
wrong afterwards, run `php artisan permission:cache-reset` before you debug anything else; a stale spatie
permission cache is the usual explanation.

## Step 9 — The first Super Admin, securely

```bash
php artisan user:create-super-admin --name="<real name>" --email="<real address>"
```

Creates exactly one Super Admin with a cryptographically random password nobody has seen, marks it
`must_change_password`, sets `email_verified_at`, and mails a signed password-reset link valid for 60
minutes. No password is printed, logged or written to the activity log.

**Working looks like**: one Super Admin exists, the named person receives the reset link and sets their
own password.

**When it fails**: for an offline install where mail cannot leave the host, add `--show-password`; it
prints a 24-character random password **once to stdout** and nowhere else — copy it now, it is not
recoverable. The command refuses to run when a Super Admin already exists (use `--force` only when you
mean it) and refuses example or disposable domains, so use the client's real address, not
`admin@example.com`.

## Step 10 — Storage link

```bash
php artisan storage:link
```

Points `public/storage` at `storage/app/public` so publicly visible uploads — CMS images and the like —
are servable. Private artefacts (client documents, receipts, certificates, exports) are **never** on this
disk; they live on a private disk and are streamed by a controller that re-runs the permission chain
(**D21**).

**Working looks like**: `public/storage` resolves to `storage/app/public`.

**When it fails**: Windows refuses symlinks to unprivileged accounts. Either run the shell as
Administrator, or enable Developer Mode, or skip this step and use the `Alias "/storage"` block in
PRODUCTION.md section 1 — which is the better option anyway, because it also switches PHP execution off
for that directory. **Never copy the folder** instead of linking it: a copy goes stale the moment someone
uploads a file, and you will spend an afternoon on a "missing image" that is sitting on disk.

## Step 11 — Writable paths

```bash
# Apply the permissions of PRODUCTION.md section 4.
```

The web user gets write access to `storage/` and `bootstrap/cache/` and read-only access to everything
else. This is the step that decides whether an upload bug is an inconvenience or a remote code execution:
if the web user can write into `public/`, an attacker who gets one file past the upload filter has a
webshell.

**Working looks like**: `php artisan security:audit` enumerates every directory under the base path and
reports no writable directory outside those two, and no world-readable `.env`.

**When it fails**: a `Permission denied` or `failed to open stream` on `storage/logs/laravel.log` means
you went too far the other way — re-grant modify rights on `storage` and `bootstrap/cache` only. Do not
"fix" it by granting full control over the whole tree; that undoes the step.

## Step 12 — Front-end build

```bash
npm ci
npm run build
```

`npm ci` installs exactly the locked dependency tree (never `npm install` on a deploy host — it may
resolve different versions than the ones that were tested). `npm run build` produces the hashed production
bundle.

**Working looks like**: `public/build/manifest.json` exists; assets are content-hashed; **no `.map` files
are shipped**; CSS and eagerly-loaded JS are inside the PRF-11 budget (70 KB and 200 KB gzipped).

**When it fails**: `npm ci` requires `package-lock.json` — if it is missing, the release is incomplete,
not the host. If every page loads unstyled afterwards, the manifest is missing or `ASSET_URL` is wrong;
check `public/build/manifest.json` before touching Blade. Run `php artisan optimize:clear` after a rebuild
if the old asset URLs persist.

## Step 13 — Caches

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Compiles configuration, routes, views and event listeners. On this application the route cache is worth
real milliseconds per request — there are several hundred routes across five panels and the public site.

**Working looks like**: all four exit 0 and `php artisan about` reports Config, Routes, Views and Events
as **CACHED**. That `about` call is the actual proof: once configuration is cached, any `env()` call
outside `config/` returns `null`, so if the application still boots and reports correctly, no such call
broke it (DEP-04 scans for them, GL-04 blocks go-live on them).

**When it fails**: `php artisan optimize:clear` undoes all four in one command — use it first whenever the
application behaves as if your last change never happened. Re-run these four after **every** `.env` change;
settings changed in the admin UI need no cache rebuild, because settings live in the database.

## Step 14 — Queue worker

```bash
# Install and start the queue service of PRODUCTION.md section 5.
```

Commission generation, reversals, notifications, exports and backups are queued work. Without a running
worker the application appears to work and quietly does nothing: a fee payment is recorded, the commission
job is enqueued, and no ledger entry ever appears.

**Working looks like**: `php artisan queue:work --once` processes one job; the service is running under
NSSM (Windows) or supervisor (Linux) and restarts itself on failure; `php artisan ops:health` reports a
fresh queue heartbeat.

**When it fails**: check `storage/logs/queue.err.log` first. A worker that starts and exits immediately is
almost always a wrong `AppDirectory` — again the space in the path, again the quotes. Remember that a
worker holds code in memory: after any code change, `php artisan queue:restart`. Never `queue:flush` a
failed-jobs table you have not read; a flushed commission job is lost work.

## Step 15 — Scheduler

```bash
# Register the scheduled task of PRODUCTION.md section 6.
```

One task, running `php artisan schedule:run` every minute, drives everything periodic: backups, retention
pruning, wallet reconciliation, constraint verification, integrity suites, fee reminders, heartbeats and
the daily digest. The proof suites are scheduled precisely so a regression introduced in month seven is
found that night rather than by a client (invariant HD-10).

**Working looks like**: `php artisan schedule:list` lists every scheduled command with a sensible next-run
time in `Asia/Karachi`, and one minute after enabling, `ops.scheduler_heartbeat` is fresh.

**When it fails**: on Windows, run the `.bat` wrapper by hand first — if it works interactively but not as
a task, the task is running as an account that cannot read the directory, or `Start in` was not set. A
scheduler that stops silently is caught by `ops:check-heartbeats` within
`ops.scheduler_heartbeat_max_minutes`, which is exactly why that probe exists.

## Step 16 — Apache vhost + HTTPS

```bash
# Install the vhost of PRODUCTION.md section 1 and the certificate of section 2, then restart Apache.
```

Points `DocumentRoot` inside `public/`, denies the dotfiles, disables PHP execution under the storage
alias, sets the security headers at the server as well as in the application, and terminates TLS.

**Working looks like**, all five checked by hand before you continue: `https://<host>/` serves the public
site; `http://<host>/` 301s to `https://`; `https://<host>/.env` returns 403 or 404; a `.php` file placed
under the storage alias is **not executed** (it downloads or is denied); `https://<host>/admin` redirects
to the login page.

**When it fails**: Apache not starting after the vhost is added is nearly always an unquoted
`DocumentRoot` — the space again. Check `C:/xampp/apache/logs/myoffice-error.log`. Do **not** enable HSTS
yet: turning on `security.hsts_enabled` before the certificate is verified locks the site out of every
browser that saw the header, and there is no quick undo. That switch belongs to go-live (GL-08), after
GL-07 is green.

## Step 17 — Settings

```bash
# In the browser: log in, open Settings, and complete the groups below, then send a test email.
```

Fill `company`, `branding`, `localization` (currency **PKR**, timezone **Asia/Karachi**, date format),
`contact` and `mail`. These are database settings read through `App\Support\SettingsRegistry`; nothing here
is a code change and nothing here needs a cache rebuild.

**Working looks like**: the test email arrives, sent using the **saved SMTP settings**, not the `.env`
values. That distinction matters: the saved settings override `.env` at boot, so a test that succeeds
proves the credentials the application will actually use.

**When it fails**: an authentication failure against the SMTP host is a credentials or port problem, not
an application one — try the same credentials from another client before changing anything here. Do not
paste a password into `.env` to "work around" a settings problem: the settings value is what runs, and you
will have left a secret in a file that must not hold one (invariant HD-8).

## Step 18 — Backups

```bash
php artisan backup:run --type=full --reason="install verification"
php artisan backup:verify --latest --deep
```

Configure the `backup` settings group first — disk, schedule, retention, `mysqldump_path`, and the offsite
disk if the client has one — then take a real backup and **prove it can be restored**.

**Working looks like**: one `backup_runs` row with `status = completed` and a non-null `checksum_sha256`,
and after the deep verify, `verification_status = restore_ok`. The deep verify restores the archive into
`backup.restore_scratch_database`, checks for pending migrations, re-runs the financial constraint proof on
the restored copy, reconciles wallets, then drops the scratch database.

**When it fails**: `mysqldump` not found is `backup.mysqldump_path` — it is
`C:/xampp/mysql/bin/mysqldump.exe` and the service always quotes it. A failed deep verify is a **blocking**
result, never a warning to note and move past: a backup that has not been restored is not a backup
(invariant HD-6, checklist GL-36). Fix it here, where nothing is at stake, rather than discovering it on
the night you need it.

## Step 19 — Final gate

```bash
php artisan golive:check
```

Evaluates every machine-checkable row of the go-live checklist and prints the manual rows as explicit
ticks. See [`GO-LIVE.md`](GO-LIVE.md) for what each row means and who signs it.

**Working looks like**: exit 0. Exit 2 means at least one blocker is open and the client does not go live.

**When it fails**: fix the named row. Do not add an exception, loosen a check, or record a blocker as
"accepted" without a written client decision in `DEVELOPMENT_LOG.md` section 9 — a finding is fixed, never
documented away (invariant HD-1).

## Step 20 — Demo data (non-production only)

```bash
php artisan demo:seed --fresh
php artisan integrity:verify --suite=all
```

Populates a demonstration or training environment: one account per role, two branches, clients, leads,
projects, courses, students, admissions, fee charges, receipts, refunds, commissions, wallets, payouts,
attendance, exams, certificates, tickets and meetings.

**Working looks like**: 18 demo accounts exist, and the integrity suites are clean — every money figure
reconciles and wallet drift is exactly `0.00`. That pairing is the point of the step: the demo data is
written through the real services, so demo data that cannot reconcile is a bug in the commission engine,
not in the seeder.

**When it fails**: the seeder and the command both refuse to run when `APP_ENV=production`, and that
refusal is correct — do not work around it. `DEMO_PASSWORD` has no default; set it in `.env` on the demo
host only. On production this step is skipped entirely, and GL-22 asserts the demo data is absent.

---

## Appendix A — Rolling back a failed install

Trivial, and stated here so nobody improvises: **drop the database, delete `.env`, and start again at
step 4.** Nothing outside those two places has been written yet, and no real data exists. Once real data
exists this appendix no longer applies — from that point on, rollback follows the ladder in the phase
contract (module kill-switch, setting revert, code swap, restore, forward fix) and never "just re-run the
installer".

## Appendix B — When a step behaves as if your change never happened

Run `php artisan optimize:clear`, then `php artisan permission:cache-reset`, then restart the queue worker.

Roughly four out of five "it did not take" reports on this system are one of three caches: the config /
route / view cache (step 13), the spatie permission cache (after any role, permission, module or settings
change), or an old queue worker still holding the previous code in memory (`php artisan queue:restart`).
Clear those three before you debug anything else, then rebuild the caches of step 13.

## Appendix C — The verification gate

Five commands, in this order: `php artisan audit:manifest --check`, `php artisan security:audit`,
`php artisan integrity:verify --suite=all`, `php artisan perf:budget --all`, `php artisan a11y:scan`.

These are the same commands the `composer harden` script runs, and they are the evidence base behind the
go-live checklist: the manifests account for every route, upload and index; the security audit sweeps
guards, headers, secrets and dependency advisories; the integrity suites prove the money; the performance
budget proves no screen is one deploy away from a timeout; the accessibility scan proves the screens are
usable. Run them after the install and again after every release — each writes an `integrity_check_runs`
row, so the answer to "when was this last proven?" is a query, not a memory.

## Appendix D — "Command ... is not defined"

Artisan commands arrive with the phase that owns them. If a step reports an undefined command, your
release predates that phase rather than your install being broken — check `php artisan list` against the
command table in the phase contract, confirm with whoever cut the release, and do not substitute a
different command to get past the step. Substituting `php artisan migrate --force` for step 7's
`--database=mysql_migration --force --step`, in particular, migrates as the application user and quietly
destroys the privilege separation that step 6 just built.
