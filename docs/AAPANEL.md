# AAPANEL.md — deploying MyOffice ERP to knsoftic.com on aaPanel

The concrete deployment of this application to **one real server**: aaPanel, nginx, php-fpm 8.2,
MariaDB 10.4, web root `/www/wwwroot/knsoftic.com`, domain `knsoftic.com`.

[`PRODUCTION.md`](PRODUCTION.md) is the reference for *why* each control exists; it is written
against Apache on Windows XAMPP. This file is the same controls translated to aaPanel, with the
panel clicks and the Linux paths spelled out. Where the two disagree about a path, this file wins —
it is the one describing the machine you are actually on. Where they disagree about a **rule**
(least privilege, append-only triggers, what the web user may write), PRODUCTION.md wins, because
those rules are the application's and not the panel's.

| You want | Read |
|---|---|
| The ordered first install, panel by panel | this file, sections 1–14 |
| Why a control exists at all | [`PRODUCTION.md`](PRODUCTION.md) |
| The generic install runbook | [`INSTALL.md`](INSTALL.md) |
| Releases after the first | [`DEPLOY.md`](DEPLOY.md) + section 15 here |
| Proving it is ready | [`GO-LIVE.md`](GO-LIVE.md) + section 14 here |
| Getting data back | [`RESTORE.md`](RESTORE.md) |
| Undoing a release | [`ROLLBACK.md`](ROLLBACK.md) |

---

## 0. The six things that actually break on aaPanel

Read this section before you start. Every item below has cost somebody an afternoon, and none of
them produces an error message that names the cause.

| # | Trap | Symptom | Section |
|---|---|---|---|
| 1 | **`disable_functions`** — aaPanel ships PHP with `proc_open`, `putenv`, `symlink`, `pcntl_*` and others disabled by default | backups silently never run; `storage:link` fails; the queue worker ignores restart signals | [3.3](#33-disable_functions--the-big-one) |
| 2 | **Running directory** — the site serves `/www/wwwroot/knsoftic.com`, not its `public/` | the whole source tree is downloadable, including `.env` | [2.2](#22-point-the-site-at-public) |
| 3 | **`.user.ini` / `open_basedir`** — aaPanel writes one into the site root and makes it immutable | "open_basedir restriction in effect" on backups, temp files, or anything outside the site root | [3.4](#34-the-userini-that-cannot-be-deleted) |
| 4 | **`public/build` is gitignored** — a git deploy ships no CSS or JS at all | site renders as unstyled raw HTML | [6.2](#62-build-the-front-end-on-the-server) |
| 5 | **A stale `public/hot`** left by a killed `npm run dev` | site renders unstyled even though the build exists | [6.3](#63-the-stale-hot-file) |
| 6 | **`opcache.validate_timestamps = 0`** | you deploy new code and nothing changes, with no error anywhere | [15.2](#152-reload-the-sapi-or-the-deploy-did-not-happen) |

Traps 4 and 5 produce the *same* symptom from opposite causes — no assets built, versus assets
built but not used. Section 6.3 tells them apart in one command.

### And two that are not aaPanel's fault

These are defects in the application's own documentation and configuration. They are listed here
rather than in [section 16](#16-what-this-deployment-does-not-have) because copying the commands
from the other docs is the natural thing to do, and both commands are wrong.

| Trap | Symptom | Section |
|---|---|---|
| **The documented queue command omits the `financial` queue.** Every other doc says `--queue=high,default`; all five commission-engine jobs dispatch to `financial` | payments record, **no commission is ever generated**, and `ops:health` still reports the queue as **ok** | [10](#10-queue-worker) |
| **`integrity:verify --suite=all` exits 2.** There is no `all` suite; omit `--suite` instead | the nightly 02:15 integrity run fails every night; `composer harden` cannot pass | [14.1](#141-the-gates-that-exist) |

The first of these is the most dangerous single line in this deployment, because it fails silently
in the direction of losing money nobody notices is missing.

---

## 1. Provision the server

### 1.1 Install aaPanel

On a clean Debian 12 / Ubuntu 22.04 box. Take the install script from **aapanel.com** and run it as
root; it prints the panel URL, username and password **once**. Save all three before you close the
terminal — the panel password cannot be read back, only reset from the shell.

### 1.2 Install the stack from the App Store

In the panel: **App Store**, then install exactly these.

| Software | Version | Why this version |
|---|---|---|
| Nginx | 1.24+ | any current build |
| PHP | **8.2** | `composer.json` requires `^8.2`. 8.3/8.4 are untested against this codebase |
| MySQL | **MariaDB 10.4** | see [4.0 below](#40-why-mariadb-104-and-not-mysql-8) — this is not a preference |
| Supervisor Manager | any | runs the queue worker (section 10) |
| phpMyAdmin | optional | convenient, and one more public attack surface — see 1.4 |

Do **not** install "Apache" alongside nginx. This file assumes nginx.

### 1.3 Node and Composer

Composer arrives with aaPanel's PHP; check it. Node does not, and you need it unless you build
assets elsewhere (see [6.2](#62-build-the-front-end-on-the-server)).

```bash
/www/server/php/82/bin/php -v          # expect 8.2.x
composer -V || echo "install composer"
node -v                                 # expect 20.x or 22.x
```

If Node is missing, install it from nodesource rather than from the aaPanel App Store's PM2 plugin,
which bundles a Node that is often older than Vite 7 wants:

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
```

### 1.4 Lock the panel down before anything else is on it

| Do this | Why |
|---|---|
| Change the panel port off 8888, and set a non-default entry path | the default panel URL is scanned constantly |
| Bind the panel to your IP, or put it behind the panel's own IP allowlist | the panel is root on this machine. It is a more valuable target than the app |
| Turn off "phpMyAdmin" public access, or restrict it by IP | it authenticates to the database, and it is on the same host as the money |
| SSH: keys only, `PasswordAuthentication no` | |
| Firewall: allow 80, 443, SSH, the panel port. Nothing else | MariaDB must **not** be reachable from the internet — this app connects over `127.0.0.1` |

**Do not skip this because "the app isn't live yet".** The window between provisioning and
hardening is exactly when a default-port panel gets found.

---

## 2. Create the site

### 2.1 Add it

Panel → **Website** → **Add site**.

| Field | Value |
|---|---|
| Domain | `knsoftic.com` — add `www.knsoftic.com` on the second line if you want it |
| Root directory | `/www/wwwroot/knsoftic.com` (aaPanel's default for this domain) |
| PHP version | **8.2** |
| Create database | **No** — create it by hand in [section 4](#4-the-database), because this app needs three users, not one |
| FTP | **No** |

aaPanel drops an `index.html` placeholder in the root. Delete it once the code is in place, or it
will be served instead of Laravel.

### 2.2 Point the site at `public/`

**This is trap #2 and it is the one that exposes `.env`.**

Site → **Site directory** → **Running directory** → select **`/public`** → Save.

Laravel's front controller is `public/index.php`, and everything above it — `.env`, `storage/`,
`vendor/`, `database/`, `.git/` — must never be reachable. Serving the repository root hands all of
it to anyone who guesses a filename.

Verify it immediately, before you go further. From your own machine:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://knsoftic.com/.env
```

Anything other than `404` (or a connection refused, before DNS points here) means the running
directory is wrong. Section 8.3 adds a second lock on the same door; this is the first.

### 2.3 DNS

Point `knsoftic.com` (and `www`) A records at the server's IP. Let's Encrypt in [section 9](#9-https)
cannot issue until DNS resolves, so do this early — propagation is the one step you cannot hurry.

---

## 3. PHP 8.2

### 3.1 Settings

Panel → **App Store** → PHP 8.2 → **Settings**. Set these on the **Configuration** tab, or edit
`/www/server/php/82/etc/php.ini` directly.

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
max_input_vars = 5000
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
opcache.validate_timestamps = 0
opcache.revalidate_freq = 0
opcache.save_comments = 1
opcache.max_wasted_percentage = 10
realpath_cache_size = 4096K
realpath_cache_ttl = 600
```

Two of these are load-bearing and get "tidied up" by people who do not know why they are there:

- **`max_input_vars = 5000`.** The role editor posts one checkbox per permission, and the registry
  declares several hundred. At PHP's default of 1000 the array is **silently truncated** — the role
  saves, no error appears, and half its permissions are missing.
- **`opcache.save_comments = 1`.** Attributes and annotations are read at runtime. Stripping
  comments breaks them.

Also raise nginx's body limit to match `post_max_size`, or a 20 MB upload dies at the web server
before PHP sees it. Site → **Config** (the nginx conf) → set `client_max_body_size 24m;`.

### 3.2 Extensions

Enable on the PHP 8.2 **Install extensions** tab: `bcmath`, `mbstring`, `pdo_mysql`, `mysqlnd`,
`openssl`, `tokenizer`, `xml`, `ctype`, `json`, `curl`, `fileinfo`, `zip`, `gd`, `intl`, `exif`.

`bcmath` is not optional. All money arithmetic goes through `App\Support\Money`, which is bcmath —
golden rule #4, "money never touches a float". Without the extension the application does not boot.

Check what you actually have:

```bash
/www/server/php/82/bin/php -m
```

### 3.3 `disable_functions` — the big one

**This is trap #1.** aaPanel ships a `disable_functions` list that disables several functions this
application needs. Nothing warns you: the features fail quietly, mostly at 2am when the scheduler
runs them.

Read what your box actually has — do not assume, the list has changed across aaPanel versions:

```bash
/www/server/php/82/bin/php -i | grep disable_functions
```

Then, in PHP 8.2 → **Settings** → **Disabled functions**, **remove** each of these if present:

| Function | What dies without it |
|---|---|
| `proc_open`, `proc_get_status` | **Backups.** `mysqldump` is shelled out to. Also `npm run build` if you build on the server |
| `putenv` | Laravel and Composer both use it; symptoms are scattered and confusing |
| `symlink`, `readlink` | `php artisan storage:link` — uploaded public files 404 |
| `pcntl_signal`, `pcntl_alarm`, `pcntl_fork`, `pcntl_waitpid`, `pcntl_signal_dispatch` | **The queue worker's graceful restart.** Without these `queue:restart` does not stop a worker cleanly, so a worker keeps serving *old code* after a deploy |
| `escapeshellarg`, `escapeshellcmd` | argument escaping for the dump — removing these while `proc_open` is on is worse than leaving both off |

Keep `exec`, `shell_exec`, `passthru` and `system` **disabled**. This application does not need
them, and they are the classic post-exploitation lever. `dl` stays disabled too.

PRODUCTION.md §7 says the same thing in one line — *"`proc_open` must stay enabled — `mysqldump`,
the backup package and `npm run build` all need it"* — and notes that `security:audit` **warns when
`proc_open` was disabled, because backups would otherwise stop working silently.** Run it (section
14) and believe it.

Restart php-fpm after changing any of this, from the panel or:

```bash
/etc/init.d/php-fpm-82 restart
```

### 3.4 The `.user.ini` that cannot be deleted

**Trap #3.** aaPanel writes `/www/wwwroot/knsoftic.com/.user.ini` containing an `open_basedir`
restriction, and sets the immutable attribute so it survives a careless `rm`. It is a good control —
it stops PHP reading outside the site — but it bites when something legitimately lives elsewhere,
most often the backup path or a system temp directory.

The error is explicit when it happens: `open_basedir restriction in effect`.

```bash
cat  /www/wwwroot/knsoftic.com/.user.ini
lsattr /www/wwwroot/knsoftic.com/.user.ini      # 'i' = immutable
```

Prefer keeping it and making the app work inside it — backups default to
`storage/app/backups`, which is already inside the site root, so the default layout needs no change.
If you must edit it:

```bash
chattr -i /www/wwwroot/knsoftic.com/.user.ini
# edit, then put the flag back:
chattr +i /www/wwwroot/knsoftic.com/.user.ini
```

Add paths to `open_basedir`; do not empty it.

---

## 4. The database

### 4.0 Why MariaDB 10.4 and not MySQL 8

The schema is not portable. It uses generated columns, `CHECK` constraints and **nine `BEFORE
DELETE` triggers** through `App\Support\Schema\RawSchema`, and those triggers are the last line
under the append-only rule (decisions **D16** and **D19**): a ledger row cannot be deleted by any
code path, *including a `DELETE` typed straight into the database*. Install MariaDB 10.4. If
`integrity:verify --suite=constraints` later reports missing triggers, GL-20 blocks go-live — and
it is right to.

> ### The aaPanel App Store item is called "MySQL", and its default is MySQL
>
> This is the single most likely way to get the wrong database on this panel. The installer lists
> MySQL 5.7 / 8.0 **and** MariaDB versions under one "MySQL" entry; if you accept the default you
> get MySQL, and nothing complains until migration 22 of 190-odd.
>
> **Check before you migrate — one query:**
>
> ```bash
> mysql -u root -p -e "SELECT VERSION();"
> ```
>
> | Output looks like | Verdict |
> |---|---|
> | `10.4.34-MariaDB` | correct |
> | `8.0.36` or `5.7.x` | **wrong — stop here**, see below |
>
> **The failure signature if you do not check.** Migrations run happily for twenty-one files and
> then `2026_09_12_070100_create_media_assets_table` dies with:
>
> ```
> SQLSTATE[42S22]: Column not found: 1054 Unknown column 'TABLE_NAME' in 'where clause'
> SQL: SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
>      WHERE CONSTRAINT_SCHEMA = ... AND TABLE_NAME = ... AND CONSTRAINT_NAME = ...
> ```
>
> `information_schema.CHECK_CONSTRAINTS` carries a **`TABLE_NAME`** column in MariaDB (since
> 10.2.22) and **does not** in MySQL 8, where the table has only `CONSTRAINT_CATALOG`,
> `CONSTRAINT_SCHEMA`, `CONSTRAINT_NAME` and `CHECK_CLAUSE`. That error *is* the version check,
> arriving late. Do not patch the query — the CHECK probe is the small visible part of a schema
> that assumes MariaDB throughout.
>
> **Recovering from it** is in [4.3](#43-if-you-already-migrated-against-mysql). The half-built
> schema cannot be migrated forward, because MariaDB DDL is not transactional (**D70**) — the
> twenty-one tables that succeeded are still there and the failed one is partly built.

One more property to know before you migrate: **MariaDB DDL is not transactional** (decision
**D70**). A migration that fails halfway leaves the tables it already created. It does not roll
back. That is why section 15 rehearses migrations rather than trusting them.

### 4.1 Create the database and the three users

Panel → **Database**, or over the shell as the MariaDB root user. **Three users, not one** — this
is what makes it true that a compromised application *cannot change the schema*.

Generate three distinct 32-character passwords and keep them in your password manager, not in a
terminal you will close.

```sql
CREATE DATABASE IF NOT EXISTS `knsoftic_erp`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. runtime user: DML only. The application can never change the schema.
CREATE USER 'knsoftic_app'@'127.0.0.1' IDENTIFIED BY '<32 random chars>';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON `knsoftic_erp`.* TO 'knsoftic_app'@'127.0.0.1';

-- 2. migration user: used only by `migrate --database=mysql_migration --force`.
--    TRIGGER is required: the spine creates nine BEFORE DELETE triggers.
CREATE USER 'knsoftic_migrator'@'127.0.0.1' IDENTIFIED BY '<32 random chars>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES,
      CREATE VIEW, SHOW VIEW, TRIGGER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE, LOCK TABLES
  ON `knsoftic_erp`.* TO 'knsoftic_migrator'@'127.0.0.1';

-- 2b. the scratch schema the weekly restore proof uses (GL-36).
--     WITHOUT THIS THE PROOF CANNOT PASS: `backup:verify --deep` restores the latest archive into
--     a scratch schema, reconciles against it, and drops it again. Every step is DDL.
--     CREATE is global because CREATE DATABASE cannot be scoped to a schema that does not exist yet.
GRANT CREATE ON *.* TO 'knsoftic_migrator'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES,
      CREATE VIEW, SHOW VIEW, TRIGGER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE, LOCK TABLES
  ON `knsoftic_erp_restore_test`.* TO 'knsoftic_migrator'@'127.0.0.1';

-- 3. backup user: read and dump only.
CREATE USER 'knsoftic_backup'@'127.0.0.1' IDENTIFIED BY '<32 random chars>';
GRANT SELECT, SHOW VIEW, LOCK TABLES, TRIGGER, EVENT ON `knsoftic_erp`.* TO 'knsoftic_backup'@'127.0.0.1';

FLUSH PRIVILEGES;
```

> **The scratch schema name is a setting, and the grant must match it.** `backup:verify --deep`
> reads `backup.restore_scratch_database`, which **defaults to `my_office_restore_test`** — not to
> anything derived from your database name. So if you grant on `knsoftic_erp_restore_test` as above,
> you must also set **Settings → Backup → Scratch database** to `knsoftic_erp_restore_test`, or the
> weekly proof will try to create a schema it has no grant for. The simpler alternative is to leave
> the setting alone and write `my_office_restore_test` in the two grant lines instead; either is
> fine, but they have to agree. The code refuses a scratch name that matches the live database —
> that one mistake would be unrecoverable — but it cannot tell that a grant is missing until it
> fails.

> **Trigger DEFINERs and the restore proof.** MariaDB stamps each trigger with the DEFINER of
> whoever ran `migrate` — `knsoftic_migrator` here. A dump carries those DEFINER clauses, and
> restoring a trigger whose DEFINER is not the current user needs `SUPER`, which the grants above
> deliberately withhold. So the deep verification restores **as the migration user**. If you ever
> change who runs migrations, the restore proof starts failing on the triggers rather than on the
> data, and the error will not say so.

Do **not** create `knsoftic_erp_test` on this server. `phpunit.xml` rebuilds its database on every
run; a production host never needs it, and the name being one keystroke from the live database is
not a risk worth carrying.

### 4.2 Prove the separation is real

```bash
cd /www/wwwroot/knsoftic.com
sudo -u www /www/server/php/82/bin/php artisan migrate --force
```

This must **fail** with a privilege error. If it succeeds, the users are not split and every
restriction above is decoration — the app is running as something with DDL rights. Fix it before
going further.

---

### 4.3 If you already migrated against MySQL

You will have twenty-one tables, a `migrations` table that lists them, and a twenty-second that is
partly built. **This cannot be migrated forward.** MariaDB DDL is not transactional (**D70**), so
the failed migration left behind whatever it had already created, and re-running it collides with
its own leftovers. If `db:seed` also ran, you additionally have branches, modules, permissions,
roles, settings and a seeded Super Admin sitting on an incomplete schema.

Nothing here is worth keeping — it is minutes old and contains no real data. Rebuild.

**1. Confirm what you are actually running.**

```bash
mysql -u root -p -e "SELECT VERSION();"
```

**2. Install MariaDB 10.4.** In aaPanel → **App Store** → MySQL → uninstall the MySQL build, then
install **MariaDB 10.4**.

> **This destroys every database on the server, not just this one.** aaPanel replaces the data
> directory. If anything else lives on this box — another site, a staging schema — dump it first:
> `mysqldump --all-databases > /root/pre-mariadb.sql`. On a server provisioned for this
> application alone there is nothing to save.

**3. Recreate the database and the three users** — re-run the whole SQL block from
[4.1](#41-create-the-database-and-the-three-users). The old users are gone with the old data
directory.

**4. Replay.**

```bash
cd /www/wwwroot/knsoftic.com
sudo -u www /www/server/php/82/bin/php artisan migrate --database=mysql_migration --force
sudo -u www /www/server/php/82/bin/php artisan db:seed --force
```

#### If the engine was right and the schema is still half-built

A different situation with the same shape — a migration that failed for its own reason. **Do not
reach for `migrate:fresh`**: decision **D157** records it failing repeatedly here, because a
half-applied migration had already created a foreign key and every retry hit errno 121, and
`DROP DATABASE` then failed on an orphaned `#sql-*` temp table from the interrupted `ALTER`.

What works is dropping every table **through SQL**, which clears InnoDB's data dictionary properly
rather than leaving files behind:

```sql
SET FOREIGN_KEY_CHECKS = 0;
-- generate and run the DROPs:
SELECT GROUP_CONCAT(CONCAT('DROP TABLE IF EXISTS `', TABLE_NAME, '`') SEPARATOR '; ')
  FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'knsoftic_erp';
SET FOREIGN_KEY_CHECKS = 1;
```

Then replay as in step 4. On a database that already holds real data, this is a restore
([`RESTORE.md`](RESTORE.md)), not a rebuild.

---

## 5. Code and configuration

### 5.1 Get the code

```bash
cd /www/wwwroot
rm -f knsoftic.com/index.html
git clone https://github.com/knsoftic/myoffice.git knsoftic.com
cd knsoftic.com
```

If the directory already exists from aaPanel's site creation, clone into a temp directory and move
the contents in, or `git init && git remote add origin … && git fetch && git checkout`.

### 5.2 `.env`

```bash
cp .env.example .env
```

Then edit it. These are the values that matter on this server:

```dotenv
APP_NAME="MyOffice ERP"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://knsoftic.com
APP_TIMEZONE=Asia/Karachi

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=knsoftic_erp
DB_USERNAME=knsoftic_app
DB_PASSWORD=<the runtime password>

# NOT in .env.example — add them by hand, or the split above silently does nothing.
DB_MIGRATION_USERNAME=knsoftic_migrator
DB_MIGRATION_PASSWORD=<the migration password>
DB_BACKUP_USERNAME=knsoftic_backup
DB_BACKUP_PASSWORD=<the backup password>

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database

# Set these BEFORE db:seed — see the note below.
SUPERADMIN_EMAIL=<a real address that can receive mail>
SUPERADMIN_NAME="<real name>"
```

> **Set `SUPERADMIN_EMAIL` before you seed, not after.** `SuperAdminSeeder` runs as part of
> `db:seed` — including in production — and defaults to **`superadmin@myoffice.test`**, generating
> a 16-character password and printing it once. `.test` is a reserved domain that can never receive
> mail, so that account has **no password-reset route**: if the printed password is lost, the only
> account that can do anything is unreachable. The seeder is idempotent and **never resets a live
> credential**, so you cannot fix this later by re-seeding — you would have to change the address on
> the row by hand.
>
> A password printed to a console also ends up in shell scrollback, in `~/.bash_history` if it was
> echoed, and in any terminal recording or support thread it gets pasted into. Treat one that has
> been printed as already disclosed: sign in and change it immediately, or rebuild before go-live.

> **`DB_MIGRATION_*` and `DB_BACKUP_*` are absent from `.env.example`.** `config/database.php`
> defines the `mysql_migration` and `mysql_backup` connections to **fall back to `DB_USERNAME`**
> when those variables are missing. So an operator who copies the example file and stops gets one
> user wearing three hats, with no error and no warning — the exact thing section 4 exists to
> prevent. Add all four lines.

`APP_DEBUG=false` is not a style preference. With it on, any 500 renders a stack trace containing
environment variables, including database credentials, to whoever triggered it.

### 5.3 App key

```bash
sudo -u www /www/server/php/82/bin/php artisan key:generate
```

**Back `APP_KEY` up now, somewhere that is not this server.** It decrypts every encrypted column
and every session. Losing it is not recoverable by any means, and a restored database without its
key is unreadable.

---

## 6. Dependencies and the front end

### 6.1 PHP dependencies

```bash
cd /www/wwwroot/knsoftic.com
composer install --no-dev --optimize-autoloader --no-interaction
```

`--no-dev` matters: dev dependencies include the test suite and debugging tools that have no
business on a public box.

### 6.2 Build the front end on the server

**Trap #4.** `.gitignore` excludes `/public/build`, `/public/hot`, `/public/storage`, `/vendor`,
`/node_modules` and `.env`. A git clone therefore contains **no compiled CSS or JS whatsoever**.
The site will render as unstyled raw HTML until you build.

```bash
npm ci
npm run build
```

Expect `public/build/manifest.json` plus hashed files under `public/build/assets/`.

If you would rather not have Node on the production box — a defensible choice — build on another
machine with the **same commit checked out** and upload `public/build/` in full. What you must not
do is upload a `public/build` from a different commit: Tailwind only emits the classes it finds in
the Blade files it scans, so assets built from an older tree are missing every class added since,
and the result is a page that is styled *almost* correctly, which is much harder to diagnose than
one that is not styled at all.

### 6.3 The stale `hot` file

**Trap #5**, and it looks identical to trap #4 from the browser. When `public/hot` exists, Laravel's
`@vite` directive emits **dev-server** URLs (`http://localhost:5173/...`) instead of reading the
built manifest. A `npm run dev` that was killed rather than stopped leaves that file behind, and
every asset request then goes to a port nothing is listening on.

```bash
ls -la /www/wwwroot/knsoftic.com/public/hot   # on production this must NOT exist
rm -f /www/wwwroot/knsoftic.com/public/hot
```

Telling the two traps apart, from the browser's network tab:

| What you see | Cause | Fix |
|---|---|---|
| Asset requests to `:5173`, connection refused | stale `public/hot` | `rm public/hot` |
| Asset requests to `/build/...`, **404** | never built | `npm run build` |
| Asset requests to `/build/...`, **200**, but the page still looks wrong | built from a different commit | rebuild from this commit |

### 6.4 Schema and reference data

```bash
sudo -u www /www/server/php/82/bin/php artisan migrate --database=mysql_migration --force
sudo -u www /www/server/php/82/bin/php artisan db:seed --force
```

The `--database=mysql_migration` is the whole point of section 4. The default connection cannot do
this, by design.

### 6.5 Storage link and caches

```bash
sudo -u www /www/server/php/82/bin/php artisan storage:link
sudo -u www /www/server/php/82/bin/php artisan config:cache
sudo -u www /www/server/php/82/bin/php artisan route:cache
sudo -u www /www/server/php/82/bin/php artisan view:cache
sudo -u www /www/server/php/82/bin/php artisan event:cache
```

If `storage:link` fails, `symlink` is still in `disable_functions` — go back to [3.3](#33-disable_functions--the-big-one).

**After any later `.env` change, re-run `config:cache`.** A cached config ignores the file. This is
the single most common "my change did nothing" on this stack. Settings changed in the admin UI need
no cache rebuild — those live in the database.

---

## 7. File permissions

php-fpm runs as **`www:www`** on aaPanel. The web user writes **only** `storage/` and
`bootstrap/cache/`; everything else is read-only to it. That is the difference between an upload bug
being an inconvenience and an upload bug being remote code execution.

```bash
cd /www/wwwroot/knsoftic.com

chown -R www:www .
find . -type d -not -path './node_modules/*' -exec chmod 750 {} \;
find . -type f -not -path './node_modules/*' -exec chmod 640 {} \;

chmod -R ug+rwX storage bootstrap/cache
chmod 600 .env
chmod +x  artisan
```

`public/` must stay readable by nginx — it runs as `www` too, so `750`/`640` under `www:www` is
enough. If you ever change nginx's user, revisit this.

**Acceptance:** `php artisan security:audit` enumerates every directory under the base path and
fails on any writable one outside `storage/` and `bootstrap/cache/`, and on a world-readable `.env`
(GL-16).

**When it goes wrong in the other direction** — `failed to open stream: Permission denied` on
`storage/logs/laravel.log`, an upload that 500s, a view cache that will not write — re-grant on
those two directories only. `chmod -R 777 .` makes the symptom vanish and undoes this entire
section; a `.php` uploaded into a writable directory then becomes executable code.

> ### Never run `artisan` as root
>
> This is how that permission error almost always happens, and it is worth stating on its own
> because the cause is not where the symptom appears.
>
> The first `php artisan` you run creates `storage/logs/laravel.log` **owned by whoever ran it**.
> Run it as `root` once — a `key:generate`, a `config:cache` — and the log file is root-owned with
> `640`. Every later command run properly as `www` then fails to append to it, and because the
> failure is *in the logger*, it surfaces as a `StreamHandler` exception that **buries the real
> error underneath it**:
>
> ```
> The stream or file ".../storage/logs/laravel.log" could not be opened in append mode: Permission denied
> The exception occurred while attempting to log: <-- the actual problem is on this line
> ```
>
> Always read the second line. The first one is the logger complaining; the second is what actually
> went wrong.
>
> The same applies to `bootstrap/cache`, `framework/views` and `framework/cache` — a root-owned
> compiled view is a 500 the next time `www` tries to rewrite it.
>
> **Fix and prevention:**
>
> ```bash
> cd /www/wwwroot/knsoftic.com
> chown -R www:www storage bootstrap/cache
> chmod -R ug+rwX storage bootstrap/cache
> ```
>
> Then prefix **every** artisan command with `sudo -u www`, including the ones you run once. It is
> in every command in this document for exactly this reason.

---

## 8. nginx

Site → **Config** edits `/www/server/panel/vhost/nginx/knsoftic.com.conf`. Edits made in the panel
survive; edits made to files the panel regenerates do not, so keep custom blocks in the site config
itself.

### 8.1 The Laravel rewrite

Site → **Rewrite** → choose the **`laravel5`** preset. It writes
`/www/server/panel/vhost/rewrite/knsoftic.com.conf` with:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

That single rule is all Laravel's routing needs. If you get a 404 on every URL except `/`, this is
what is missing.

### 8.2 Body size and timeouts

```nginx
client_max_body_size 24m;      # must be >= post_max_size
fastcgi_read_timeout 120s;     # long report exports
```

### 8.3 Deny what must never be served

The running directory in [2.2](#22-point-the-site-at-public) already puts the source tree out of
reach. These are the second lock — keep them even though the first one works, because the day the
running directory gets reset by a panel action, this is what is still standing.

```nginx
# Never serve dotfiles, VCS metadata or environment files.
location ~ /\.(?!well-known) {
    deny all;
    access_log off;
    log_not_found off;
}

# Uploaded files are data. They are never code.
location ^~ /storage/ {
    location ~ \.(php|phtml|phar|ph[0-9]|pht|inc|cgi|pl|asp|aspx|jsp|sh|bat|exe)$ {
        deny all;
    }
}

# Hashed build assets are immutable.
location ^~ /build/ {
    expires 1y;
    add_header Cache-Control "public, max-age=31536000, immutable";
    access_log off;
}
```

The `/storage/` block is the one people delete because "the upload validator already refuses those
extensions". Keep it. The upload layer refuses blocked extensions
(`security.upload_blocked_extensions`) and the private disk is not web-reachable at all — but the
day one of those fails, this block is what stops an uploaded `.php` being **executed** rather than
merely **stored**.

Note the two disks this protects, from `config/filesystems.php`:

| Disk | Root | Web reachable? |
|---|---|---|
| `public` | `storage/app/public` | yes, via the `public/storage` symlink — hence the block above |
| `private` | `storage/app/private` | **no**, and it must stay that way |
| `backups` | `storage/app/backups` | **no** |

Decision **D21**: no private artefact is ever written to the `public` disk; private uploads are
served by a controller that re-runs the permission chain. Nothing in nginx should ever expose
`storage/app/private`.

### 8.4 Security headers

The application sets these itself — `SecurityHeaders` middleware is the authority, and it is
prepended globally so even a 500 page carries them. Adding them in nginx as well is belt and braces,
not a substitute. If you do add them, use `add_header ... always;` so they survive error responses,
and do not set a *conflicting* value: two different `X-Frame-Options` is worse than one.

Verify what is actually being sent, rather than what you configured:

```bash
curl -sSI https://knsoftic.com | grep -iE 'x-frame|x-content-type|referrer|strict-transport|permissions-policy'
```

### 8.5 Logs

aaPanel writes `/www/wwwlogs/knsoftic.com.log` and `/www/wwwlogs/knsoftic.com.error.log`, and
rotates them itself. Application logs are separate — `storage/logs/`, daily, pruned by Laravel from
`ops.log_retention_days`, so they need no logrotate entry. `storage/logs` is not web-reachable, and
the in-app log viewer is gated by `activity_log.view_logs`.

---

## 9. HTTPS

Order matters here, and one of these steps cannot be undone. Do them in this sequence.

### 9.1 Certificate

Site → **SSL** → **Let's Encrypt** → tick `knsoftic.com` and `www.knsoftic.com` → apply. DNS must
already resolve to this server ([2.3](#23-dns)). Then turn on **Force HTTPS** in the same tab, which
adds the `:80` → `:443` redirect to the nginx config.

aaPanel renews automatically. Confirm after issuing that the renewal task exists in **Cron** — a
certificate that silently stops renewing takes the site down 90 days later.

### 9.2 Application settings

```dotenv
APP_URL=https://knsoftic.com
SESSION_SECURE_COOKIE=true
```

```bash
sudo -u www /www/server/php/82/bin/php artisan config:cache
```

Then in the admin UI: **Settings → Security → Require HTTPS** (`security.force_https`) → on. It
defaults to `true` already, and it is ignored when `APP_ENV=local`, so a developer machine without a
certificate is never forced onto TLS it does not have.

### 9.3 Trusted proxies — and the redirect loop

`ForceHttps` redirects a plain-HTTP **GET** with a 301, and **refuses a plain-HTTP POST with a 403**
rather than redirecting it. That asymmetry is deliberate: a POST has already put its body — a
password, an invoice line, a commission rate — on the wire in clear text, and redirecting would only
protect the second copy.

The decision rests on `$request->isSecure()`. TLS terminates at nginx, and PHP runs behind it over
FastCGI, so what PHP sees depends on what nginx passes:

- **aaPanel terminating TLS itself** (the normal case here): nginx's stock `fastcgi_params` includes
  `fastcgi_param HTTPS $https if_not_empty;`, so `isSecure()` is true and nothing more is needed.
- **Behind Cloudflare, a load balancer, or any upstream that terminates TLS and talks plain HTTP to
  this box**: `isSecure()` is **false**, `ForceHttps` redirects to `https://`, the upstream sends it
  back over HTTP, and you get an **infinite redirect loop** — with every POST 403ing.

`security.trusted_proxies` is what fixes the second case, and **empty is both the default and the
safe value**: with no trusted proxy Laravel ignores `X-Forwarded-For` entirely, so a client cannot
invent a client address and hand itself a fresh rate-limit counter, or write a chosen IP into
`login_histories`. Set it only when there really is a proxy, and set it to that proxy's addresses or
CIDR ranges — `*` only behind a load balancer you control.

Check which case you are in before you change anything:

```bash
curl -sS https://knsoftic.com/up -o /dev/null -w '%{http_code}\n'
```

A loop shows up as a redirect chain; `curl -ILsS https://knsoftic.com` makes it obvious.

### 9.4 HSTS — last, and deliberately

Only after `https://` serves, `http://` 301s, and there is no mixed-content warning on the public
home page, the admin shell **and** a print view.

**`security.hsts_enabled` is `readonly` in the settings registry — you cannot switch it on from the
UI.** That is on purpose: enabling HSTS tells every browser that has seen the header to refuse plain
HTTP for the whole `max-age`, and if the certificate is not working those visitors are locked out of
a site that cannot serve them. There is no server-side undo; each visitor has to clear it
themselves. Turning it on means editing `'readonly' => true` in
`app/Support/SettingsRegistry.php`, which puts the decision in version control where it belongs.

Decide `hsts_include_subdomains` just as deliberately, and for the same reason.

---

## 10. Queue worker

**Without a running worker the application looks completely healthy.** A fee payment is recorded,
the commission job is enqueued, and no ledger entry ever appears. There is no error anywhere. This
is the failure mode to recognise on this system.

Panel → **App Store** → **Supervisor Manager** → **Add Daemon**:

| Field | Value |
|---|---|
| Name | `myoffice-queue` |
| Run User | `www` |
| Run Directory | `/www/wwwroot/knsoftic.com` |
| Start Command | `/www/server/php/82/bin/php artisan queue:work --queue=high,financial,default,exports --sleep=1 --tries=3 --max-time=3600 --max-jobs=500` |
| Processes | `2` |

> ### Do not use `--queue=high,default` here
>
> **PRODUCTION.md, `phase-24-25.md` and the phase contract all specify
> `--queue=high,default`. On this codebase that command never processes the commission engine.**
>
> The jobs this application actually dispatches, and the queue each one names:
>
> | Queue | Jobs |
> |---|---|
> | `high` | the `ops:heartbeat` stamp — the thing `ops:health` reads to say the worker is alive |
> | **`financial`** | `ProcessStudentFeeCommission`, `ProcessProjectPaymentCommission`, `ProcessCommissionReversal`, `GenerateMonthlyFeeCharges`, `RecomputeStudentFeeCaches` |
> | `default` | two general jobs |
> | `exports` | `BuildReportExport` |
>
> A worker listening on `high,default` leaves **`financial` and `exports` untouched for ever**. The
> heartbeat still gets stamped, so `ops:health` reports the queue as **ok** — and every commission,
> every reversal and every monthly fee generation sits in the `jobs` table unprocessed, with no
> error anywhere.
>
> That is not a hypothetical. It is the exact failure PRODUCTION.md §5 warns about in its own words
> — *"a fee payment is recorded, the commission job is enqueued, and no ledger entry ever
> appears"* — reached by following PRODUCTION.md's own command.
>
> `SystemHealthService` has a third spelling, `--queue=high,default,low`, in its remediation hint;
> there is no `low` queue in this codebase and it also omits `financial`. Use the command in the
> table above, and treat the other three as stale.

Every flag earns its place:

| Flag | Why |
|---|---|
| `--queue=high,financial,default,exports` | priority runs left to right. The heartbeat first so health stays truthful, **money second**, general work third, and bulk report exports last so a large export can never delay a commission |
| `--max-time=3600 --max-jobs=500` | a bounded lifetime defeats memory creep; supervisor restarts the worker |
| `2` processes, not ten | the database queue driver serialises on `jobs`. More workers only add lock contention at this scale |

If the panel exposes it, set **stopwaitsecs** to `3600`. Never SIGKILL a worker mid-transaction.

`queue:restart` is part of **every** deploy (section 15) — a running worker holds the old code in
memory, and skipping this is the classic "the fix did not take" bug. It needs the `pcntl_*`
functions from [3.3](#33-disable_functions--the-big-one) to work properly.

Failed jobs: `queue:failed`, `queue:retry <id>`, `queue:retry all`. **Never `queue:flush` on
production without reading the rows first** — a flushed commission job is lost work, which is
exactly why `commissions:sweep` exists to re-queue it.

---

## 11. Scheduler

**One cron entry drives sixty-one scheduled commands.** Panel → **Cron** → Add task:

| Field | Value |
|---|---|
| Type | Shell Script |
| Name | `MyOffice scheduler` |
| Period | **every 1 minute** |
| Script | `cd /www/wwwroot/knsoftic.com && /www/server/php/82/bin/php artisan schedule:run >> /dev/null 2>&1` |

aaPanel runs panel cron tasks as `root` by default. Prefer `www`, so the scheduler cannot write
files the web user then cannot read:

```bash
cd /www/wwwroot/knsoftic.com && sudo -u www /www/server/php/82/bin/php artisan schedule:run >> /dev/null 2>&1
```

Verify:

```bash
sudo -u www /www/server/php/82/bin/php artisan schedule:list
```

Every command should show the right cadence in **Asia/Karachi**. A sample of what is on that
schedule, to make the stakes clear:

| Cadence | Commands include |
|---|---|
| every minute | `ops:heartbeat`, `blog:publish-scheduled` |
| every 5–15 min | `crm:follow-up-reminders`, `commissions:sweep`, `tickets:sla-sweep`, `meetings:send-reminders`, `ops:check-heartbeats` |
| hourly | `backup:run --type=database`, `backup:run --type=files`, `ops:digest`, `cms:cache-prune` |
| daily | `integrity:verify --suite=all` (02:15 — **this one fails every night**, see [14.1](#141-the-gates-that-exist)), `collaborators:reconcile-wallets` (01:30), `financial:verify-constraints` (02:00), `backup:verify --latest` (04:00), `fees:mark-overdue`, `invoices:mark-overdue` |
| weekly | `backup:verify --latest --deep` (Sun 04:30), `security:audit --quiet-run` (Mon 05:30) |

**A silent scheduler is a financial control failure, not a cosmetic one.** `integrity:verify`,
`collaborators:reconcile-wallets`, `financial:verify-constraints`, `backup:verify` and
`security:audit` all run from it — that is what makes the proof suites run *forever* rather than
once, so a regression introduced in month seven is found that night instead of by a client.

A scheduler that stops is reported by `ops:check-heartbeats` within
`ops.scheduler_heartbeat_max_minutes`. Trust that probe rather than the absence of error messages.

---

## 12. Backups

The schedule already runs `backup:run` hourly, `backup:prune` daily, `backup:verify --latest` daily
and `backup:verify --latest --deep` weekly. What you must do on this server is make them *able* to
run.

| Requirement | On aaPanel |
|---|---|
| `proc_open` enabled | [3.3](#33-disable_functions--the-big-one) — without it backups fail silently |
| `mysqldump` reachable | typically `/www/server/mysql/bin/mysqldump`. Confirm with `ls -l /www/server/mysql/bin/mysqldump`, and make sure it is inside `open_basedir` or invoked in a way that is |
| Local archive path | `storage/app/backups`, already inside the site root and inside `open_basedir` |
| The scratch schema grant | section [4.1](#41-create-the-database-and-the-three-users) 2b — **without it the weekly deep proof cannot pass** |
| `backup.restore_scratch_database` matches that grant | it defaults to `my_office_restore_test`, which is **not** derived from your database name — [4.1](#41-create-the-database-and-the-three-users) |
| Offsite copy | configure in **Settings → Backup**. A backup that only exists on the machine it backs up is not a backup |

Configure the rest in the admin UI under **Settings → Backup** (30 keys), including
`backup.notify_emails` so a failed run reaches a person.

Prove a restore works **before** you need one:

```bash
sudo -u www /www/server/php/82/bin/php artisan backup:verify --latest --deep
```

Read [`RESTORE.md`](RESTORE.md) once now, while nothing is on fire, and note its honest warning:
the DEP-17 test that was supposed to assert the restore performs its thirteen documented steps
**has not been written**, so today the only thing checking that procedure is the person running it.

---

## 13. The first Super Admin

```bash
cd /www/wwwroot/knsoftic.com
sudo -u www /www/server/php/82/bin/php artisan user:create-super-admin \
  --name="<real name>" --email="<real address>"
```

The password is **generated, never supplied** — the command takes no password argument. It emails a
reset link to the address you give, so that address must be able to receive mail *before* you run
this; the command refuses `example.*` and known disposable domains precisely because the one account
that can do anything would otherwise have no recovery route.

For an offline install, `--show-password` prints the generated password once. The account is created
with "must change password" set either way.

Configure SMTP under **Settings → Email** and send a test message before relying on any of this.

---

## 14. Verify

### 14.1 The gates that exist

```bash
cd /www/wwwroot/knsoftic.com
sudo -u www /www/server/php/82/bin/php artisan security:audit
sudo -u www /www/server/php/82/bin/php artisan integrity:verify          # NOT --suite=all, see below
sudo -u www /www/server/php/82/bin/php artisan golive:check
```

> **`integrity:verify --suite=all` does not work, and several places still call it.** `--suite`
> takes **one** suite name and there is no `all` among them, so the command exits **2** with
> `Unknown suite [all]`. Omitting `--suite` is what runs all nine — `constraints`, `wallet`,
> `schema`, `routes`, `isolation`, `uploads`, `performance`, `security`, `backup`.
> [`ROLLBACK.md`](ROLLBACK.md) states this correctly; `composer.json`, `routes/console.php` and
> `INSTALL.md` still pass the broken form. Verified on this codebase:
>
> ```
> $ php artisan integrity:verify --suite=all
> Unknown suite [all]. Known suites: constraints, wallet, schema, routes, isolation, uploads, performance, security, backup.
> exit 2
> ```
>
> The practical consequences on this server are in [section 16](#16-what-this-deployment-does-not-have)
> — they include the **nightly 02:15 integrity run failing every night**.

`composer harden` runs six checks — `audit:manifest --check`, `security:audit`,
`integrity:verify --suite=all`, `perf:budget --all`, `a11y:scan`, and `php artisan test
--stop-on-failure`. **The third of those is the broken invocation above, so `composer harden`
cannot currently pass**; run the checks individually until `composer.json` is corrected. The test
step needs dev dependencies and a test database in any case, so it does not belong on a production
box installed with `--no-dev`.

### 14.2 Prove the door is shut

From a machine that is not the server:

```bash
for p in /.env /storage/logs/laravel.log /composer.json /vendor/autoload.php /.git/config /docs/requirements.md; do
  printf '%s -> ' "$p"
  curl -sS -o /dev/null -w '%{http_code}\n' "https://knsoftic.com$p"
done
```

Every one must be `404` or `403`. A `200` on any of them means [2.2](#22-point-the-site-at-public)
is wrong — stop and fix it before the site is announced.

### 14.3 Prove the invisible things work

| Check | Command | Healthy answer |
|---|---|---|
| Queue worker alive | `artisan ops:health` | queue heartbeat recent — **but see below, this probe alone is not enough** |
| Queue worker draining **money** | `SELECT queue, COUNT(*) FROM jobs GROUP BY queue;` | no growing backlog on `financial` |
| Scheduler alive | `artisan ops:health` | scheduler heartbeat within 2 minutes |
| Triggers present | `artisan integrity:verify --suite=constraints` | pass |
| Wallets match their ledgers | `artisan collaborators:reconcile-wallets` | no drift |
| Backups real | `artisan backup:verify --latest --deep` | pass |

> **Why the queue needs two checks and not one.** `ops:health`'s queue probe reads a heartbeat that
> `ops:heartbeat` stamps on the **`high`** queue. A worker listening on `high` but not `financial`
> stamps that heartbeat perfectly while never touching a single commission — so the probe says
> **ok** and the money silently stops. Until the probe asserts the `financial` queue is being
> drained, the `jobs` table is the honest answer. A backlog there that grows rather than drains is
> the symptom; [section 10](#10-queue-worker) is the cause.

[`GO-LIVE.md`](GO-LIVE.md) is the full GL-01..GL-52 checklist and is what actually signs this off.

---

## 15. Deploying a release

[`DEPLOY.md`](DEPLOY.md) is the full seventeen-step runbook. On this server the short form is:

```bash
cd /www/wwwroot/knsoftic.com

sudo -u www /www/server/php/82/bin/php artisan backup:run --type=database   # 1. backup first
sudo -u www /www/server/php/82/bin/php artisan down --secret="<random>"     # 2. maintenance
# 3. stop the queue worker in Supervisor Manager

git pull
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build

sudo -u www /www/server/php/82/bin/php artisan migrate --database=mysql_migration --force
sudo -u www /www/server/php/82/bin/php artisan db:seed --force

sudo -u www /www/server/php/82/bin/php artisan config:cache
sudo -u www /www/server/php/82/bin/php artisan route:cache
sudo -u www /www/server/php/82/bin/php artisan view:cache
sudo -u www /www/server/php/82/bin/php artisan event:cache

/etc/init.d/php-fpm-82 reload            # see 15.2 — not optional
# start the queue worker again in Supervisor Manager
sudo -u www /www/server/php/82/bin/php artisan queue:restart
sudo -u www /www/server/php/82/bin/php artisan up
```

### 15.1 Rehearse the migration

MariaDB DDL is not transactional (**D70**): a migration that fails halfway leaves behind what it
already created, and does not roll back. Before a release with migrations, restore the latest backup
into a scratch schema and run the migration against **production's real schema** there. That is the
only way to find out what a migration does to your data before it does it.

### 15.2 Reload the SAPI, or the deploy did not happen

**Trap #6.** With `opcache.validate_timestamps = 0`, new code is invisible until php-fpm restarts.
Every symptom looks like a failed deploy — the fix is not live, a new route 404s, the version string
is old — and every one is one reload away. Put the reload in the script, not in your memory.

Never expose a web-reachable `opcache_reset()` route to do this. That is an unauthenticated
denial-of-service lever.

### 15.3 Rolling back

[`ROLLBACK.md`](ROLLBACK.md) has the ladder, from the module kill switch upward. Read its own
warning first: the DEP-19 and DEP-20 tests that were meant to verify the per-phase rollback table
**have not been written**, so treat every "yes" in that table as a claim to re-verify on the day, on
a copy — not as a proof.

---

## 16. What this deployment does not have

Stated plainly, because finding it out during an incident is worse.

| Gap | Consequence on this server |
|---|---|
| **`deploy/` does not exist.** PRODUCTION.md says the vhost, supervisor config, `schedule.bat` and `php-production.ini` "ship in the repository" at `deploy/`. They do not — the directory is absent | every config in this file is typed by hand. That is why they are reproduced here in full rather than referenced |
| **Every documented queue worker command omits the `financial` queue** | `--queue=high,default` is specified by PRODUCTION.md §5, the phase contract, and (as `high,default,low`) by `SystemHealthService`'s own remediation hint. All five commission-engine jobs dispatch to `financial`, and report exports to `exports`. Following any of those commands means **no commission is ever generated**, while `ops:health` still reports the queue as ok because the heartbeat rides on `high`. [Section 10](#10-queue-worker) has the command that works |
| **`integrity:verify --suite=all` exits 2 — "Unknown suite"** | three real consequences here: the **nightly 02:15 integrity run fails every night**, so the daily proof of the money never runs; `composer harden` cannot pass, because that is its third step; and `golive:check` asserts the schedule *contains* `--suite=all`, so the gate currently requires the broken form to be scheduled. Until `composer.json` and `routes/console.php` are corrected to omit `--suite`, **run `php artisan integrity:verify` by hand** on the cadence you need, and do not read a green scheduler as evidence the suites ran |
| **`settings:set` was never written** | DEPLOY.md step 13 stamps `ops.app_version` with it. You cannot. Record the deployed version elsewhere until it ships |
| **`payouts:expire-stale-requests` was never written** (T52) | `golive:check` reports "1 of 20 §10.4 entries not scheduled". Expected, not a misconfiguration on your part |
| **DEP-17..DEP-20 do not exist** (T53) | nothing automatically verifies the restore procedure, the migration rehearsal gate, or the rollback table. The runbooks say so where you meet them |
| **`backup:restore` does not adopt the pending row** (T51) | a restore started from the screen leaves a stranded `requested` row; the evidence that the typed phrase and password confirmation were passed is not attached to the restore that ran |
| **Phases 6–13 are not ticked** | some functionality is incomplete. Check the phase tracker in `DEVELOPMENT_LOG.md` before promising a module to a client |
| **304 screens have a null `query_budget`** | unmeasured, not unlimited. `perf:budget` skips them and reports the count |

---

## Appendix A — aaPanel path reference

| Thing | Path |
|---|---|
| Site root | `/www/wwwroot/knsoftic.com` |
| Document root (running directory) | `/www/wwwroot/knsoftic.com/public` |
| nginx site config | `/www/server/panel/vhost/nginx/knsoftic.com.conf` |
| nginx rewrite | `/www/server/panel/vhost/rewrite/knsoftic.com.conf` |
| nginx access/error logs | `/www/wwwlogs/knsoftic.com.log`, `…error.log` |
| PHP binary | `/www/server/php/82/bin/php` |
| `php.ini` | `/www/server/php/82/etc/php.ini` |
| php-fpm service | `/etc/init.d/php-fpm-82` |
| `open_basedir` file | `/www/wwwroot/knsoftic.com/.user.ini` (immutable) |
| `mysqldump` | `/www/server/mysql/bin/mysqldump` |
| Application logs | `/www/wwwroot/knsoftic.com/storage/logs/` |
| Backups | `/www/wwwroot/knsoftic.com/storage/app/backups/` |
| Private uploads | `/www/wwwroot/knsoftic.com/storage/app/private/` |
| Web user | `www:www` |

Paths under `/www/server/php/82` assume PHP 8.2; confirm with `ls /www/server/php/`.

## Appendix B — Troubleshooting

| Symptom | First thing to check |
|---|---|
| Site renders unstyled | `public/hot` exists, or `public/build` is missing — [6.3](#63-the-stale-hot-file) |
| 404 on every URL except `/` | the `laravel5` rewrite is not applied — [8.1](#81-the-laravel-rewrite) |
| `.env` downloads in a browser | running directory is not `/public` — [2.2](#22-point-the-site-at-public). **Rotate `APP_KEY` and every credential in that file** |
| Infinite redirect loop, POSTs 403 | `isSecure()` false behind an upstream that terminates TLS — [9.3](#93-trusted-proxies--and-the-redirect-loop) |
| Backups never produce a file | `proc_open` disabled — [3.3](#33-disable_functions--the-big-one) |
| `storage:link` fails | `symlink` disabled — [3.3](#33-disable_functions--the-big-one) |
| Payment recorded, no commission appears | the worker is not running — **or it is running without the `financial` queue**, which is what every other doc's command does. [Section 10](#10-queue-worker). Check with `SELECT queue, COUNT(*) FROM jobs GROUP BY queue;` |
| A role saves with permissions missing | `max_input_vars` is below 5000 — [3.1](#31-settings) |
| `open_basedir restriction in effect` | [3.4](#34-the-userini-that-cannot-be-deleted) |
| Permission denied writing logs/cache | an `artisan` command was run as **root** and left root-owned files — [section 7](#7-file-permissions). Grant on `storage` and `bootstrap/cache` only, never `777` on the tree |
| `could not be opened in append mode`, then a second error | the second line is the real error; the first is only the logger failing — [section 7](#7-file-permissions) |
| `Unknown column 'TABLE_NAME' in 'where clause'` at migration 22 | **the server is MySQL, not MariaDB** — [4.0](#40-why-mariadb-104-and-not-mysql-8), recover with [4.3](#43-if-you-already-migrated-against-mysql) |
| `Table '….menus' doesn't exist` while seeding | migrations did not finish; seeding an incomplete schema. Fix the migration failure first — [4.3](#43-if-you-already-migrated-against-mysql) |
| A `.env` change did nothing | `php artisan config:cache` was not re-run — [6.5](#65-storage-link-and-caches) |
| New code deployed, behaviour unchanged | php-fpm was not reloaded — [15.2](#152-reload-the-sapi-or-the-deploy-did-not-happen) |
| Migration failed halfway, tables half-created | MariaDB DDL is not transactional (**D70**) — [15.1](#151-rehearse-the-migration) |
| `Unknown suite [all]`, exit 2 | omit `--suite` entirely — [14.1](#141-the-gates-that-exist) |
| `composer harden` fails on step 3 | same cause; run the five non-test checks individually — [14.1](#141-the-gates-that-exist) |
| `golive:check` says "1 of 20 §10.4 entries not scheduled" | `payouts:expire-stale-requests` was never written (T52). Expected — [section 16](#16-what-this-deployment-does-not-have) |
