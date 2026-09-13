# Installation and operations guide

This guide covers requirement §115: environment file, database setup, migrations, seeders, storage link,
queue, scheduler and production notes. It was checked on 2026-09-13 against `composer.json`,
`package.json`, `.env.example`, `config/`, `bootstrap/app.php`, `database/seeders/`, `phpunit.xml` and
`DEVELOPMENT_LOG.md` §2–§4. Numbers such as "30 migrations" or "788 permissions" are true for the code
as it stands. They grow as phases land.

Phase 25 (deployment preparation) is not built yet. §2 says what the current code supports and marks what
is only decided. The planned full runbook is in [`docs/phases/phase-24-25.md`](docs/phases/phase-24-25.md)
§6.7–§6.13.

**Shell.** The commands below are for Git Bash, run from the project folder. In PowerShell, put `&` in
front of a quoted executable path (`& "C:/xampp/mysql/bin/mysql.exe" ...`). Windows PowerShell 5.1 has
no `&&`.

---

## 1. Local install (Windows, XAMPP, a path with a space)

### 1.1 Requirements

| Component | Required | Used in development |
|---|---|---|
| PHP | 8.2 or newer (`composer.json`: `"php": "^8.2"`) | 8.2.12, XAMPP, ZTS x64 |
| Composer | 2.x | 2.10.2 |
| MariaDB | 10.4 (the schema uses MariaDB/InnoDB specifics and CHECK constraints) | 10.4.32, XAMPP |
| Node.js | `^20.19.0 \|\| >=22.12.0` (Vite 7's `engines` field) | 24.18.0 |
| npm | ships with Node | 11.16.0 |

PHP extensions:

| Extension | Why this application needs it |
|---|---|
| `pdo_mysql` | the database connection |
| `bcmath` | all money arithmetic goes through `App\Support\Money` (bcmath, never floats) |
| `mbstring`, `openssl`, `ctype`, `filter`, `hash`, `session`, `tokenizer` | required by `laravel/framework` |
| `fileinfo` | file uploads (avatars, branding files) |
| `dom` / `libxml` | `App\Support\RichText`, the single HTML sanitiser (D25) |
| `gd` | the Phase 3 CMS image pipeline (`app/Services/Cms/Media/GdImageProcessor.php`), plus image fakes in tests |
| `exif` | the same pipeline reads the EXIF orientation |
| `curl`, `zip` | listed as required in `DEVELOPMENT_LOG.md` §2 |
| `intl` | not needed yet. It is commented out in XAMPP's `php.ini` |

Check what is loaded:

```bash
php -m
```

### 1.2 Living with the space in `my office`

| Rule | Why |
|---|---|
| Quote every path in every command | an unquoted `C:/xampp/htdocs/my office` splits into two arguments |
| Use forward slashes in paths you type | they work in Git Bash, PHP and Apache alike |
| Develop on `php artisan serve` (`http://localhost:8000`), never on `http://localhost/my%20office/` | XAMPP's Apache has `DocumentRoot "C:/xampp/htdocs"`. Through it, the **project root** is served, `.env` included. If Apache has to run on this machine, give the project a vhost whose document root is `public/` (§2.1) |

### 1.3 Get into the folder and install PHP dependencies

```bash
cd "C:/xampp/htdocs/my office"
```

```bash
composer install
```

### 1.4 Create the two databases

Start MySQL from the XAMPP Control Panel first.

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS my_office CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS my_office_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

| Database | Used by | Notes |
|---|---|---|
| `my_office` | the application (`DB_DATABASE` in `.env`) | Holds real data once in use. It is only ever migrated forward |
| `my_office_test` | PHPUnit only (`phpunit.xml`) | Every test process drops and rebuilds it. Never point it at data you want to keep |

XAMPP's `root` user has no password (tech debt T3). That is acceptable on a developer machine only.

### 1.5 The environment file

```bash
cp .env.example .env
```

The keys that matter, as shipped in `.env.example`:

| Key | Value in `.env.example` | What to know |
|---|---|---|
| `APP_NAME` | `"MyOffice ERP"` | The fallback name. The company name saved in Settings wins wherever a view reads it |
| `APP_ENV` | `local` | `production` on a live server. It also changes what `DemoUserSeeder` does (§1.8) |
| `APP_DEBUG` | `true` | `false` in production |
| `APP_URL` | `http://localhost:8000` | Must match the URL you open. Public-disk file URLs are built from it |
| `APP_TIMEZONE` | `Asia/Karachi` | **Not read.** `config/app.php` hardcodes `'timezone' => 'UTC'` (D61, §1.13) |
| `DB_CONNECTION` … `DB_PASSWORD` | `mysql`, `127.0.0.1`, `3306`, `my_office`, `root`, empty | Development defaults |
| `SESSION_DRIVER` | `database` | The session list and "revoke other sessions" read the `sessions` table |
| `CACHE_STORE` | `database` | The settings payload, the module map and spatie's permission cache live here |
| `QUEUE_CONNECTION` | `database` | The `jobs` table |
| `FILESYSTEM_DISK` | `public` | See §1.9 |
| `MAIL_MAILER` | `log` | Mail is written to `storage/logs/laravel.log`. The Mail settings group overrides the `MAIL_*` values at runtime |

Optional keys. They are not in `.env.example`, and the seeders read them with `env()`:

| Key | Default when absent | Effect |
|---|---|---|
| `SUPERADMIN_EMAIL` | `superadmin@myoffice.test` | email of the first account |
| `SUPERADMIN_NAME` | `Super Admin` | its display name |
| `SUPERADMIN_PASSWORD` | empty: a random 16-character password (letters and digits) is generated and printed once | its first password. It must be changed at first sign-in either way |
| `SEED_DEMO` | unset: demo accounts are seeded in every environment except `production` | `true` or `false` decides explicitly |

### 1.6 Application key

```bash
php artisan key:generate
```

Generate the key **once** per installation. Settings marked encrypted, such as the SMTP password, are
encrypted with `APP_KEY`. After a key change they no longer decrypt: `SettingsRepository` returns `null`
for them. Every browser session is also signed out.

### 1.7 Migrate

```bash
php artisan migrate
```

At the time of writing this runs 30 migrations:

| Group | Count | Tables |
|---|---|---|
| Laravel | 3 | `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`, `failed_jobs` … |
| spatie vendor | 4 | permission tables, `activity_log` |
| Phase 1 | 7 | `branches`, `modules`, `settings`, `login_histories`; extensions to `users`, the permission tables and `activity_log` |
| Phase 2 | 6 | Module dependencies and disable audit, settings audit and `is_readonly`, `users.preferences`, and two data migrations that mark legacy setting keys read-only (nothing is deleted). The sixth, `060600_pin_utc_session_and_convert_timestamp_columns`, re-bases `timestamp` values stored before the UTC pin (§1.13). It does nothing on a fresh install or on a host whose zone is UTC |
| Phase 3 | 10 | `media_assets`, `cta_blocks`, `pages`, `menus`, `menu_items`, `website_sections`, `website_section_items`, FAQ tables, `seo_meta`, `cms_revisions`, `sitemap_generations` |

Install with `migrate` only. `migrate:fresh`, `migrate:reset`, `migrate:rollback` and `db:wipe` destroy
data. Never run them against a database that holds rows you want to keep.

### 1.8 Seed

```bash
php artisan db:seed
```

`DatabaseSeeder` runs seven seeders in this order:

| # | Seeder | Creates |
|---|---|---|
| 1 | `BranchSeeder` | The default branch: code `HQ`, "Head Office", Lahore (D11) |
| 2 | `ModuleSeeder` | One `modules` row per module in `PermissionRegistry`: 79 modules, 14 of them core. New modules start enabled. It also writes the `depends_on` graph |
| 3 | `PermissionSeeder` | One permission per registry entry, named `module.ability`: 788 permissions |
| 4 | `RoleSeeder` | The 18 system roles and their grants |
| 5 | `SettingSeeder` | One `settings` row per key in `SettingsRegistry`: 120 keys in 13 groups (company, branding, appearance, localization, contact, social, seo, mail, collaborator, institute, finance, security, maintenance) |
| 6 | `SuperAdminSeeder` | The first account (below) |
| 7 | `DemoUserSeeder` | One demo account for each of the other 17 roles (below) |

**Re-running is safe.** Every seeder matches rows on a natural key and deletes nothing. It keeps any
setting value, disabled module or password an administrator changed. Two things it does converge:

| What | Behaviour |
|---|---|
| Grants of the 18 system roles | `RoleSeeder` calls `syncPermissions()`, so the seeded grant wins. A permission added to or removed from one of those 18 roles in the role editor **is reset** by the next `db:seed`. Roles created in the UI are not touched |
| A core module stored as disabled | re-enabled, because a core module can never be off |

**The Super Admin account**

| Item | Value |
|---|---|
| Email | `SUPERADMIN_EMAIL`, or `superadmin@myoffice.test` |
| Password | `SUPERADMIN_PASSWORD`, or a random 16-character password printed **once**, framed as `SUPER ADMIN` in the seeder output. Copy it before the console scrolls |
| State | Active, email verified, default branch, role `Super Admin` |
| First sign-in | `must_change_password = true`. The `active` middleware allows only the change-password screen (plus logout and the theme switch) until the password is changed |
| New password rules | `App\Services\Auth\PasswordPolicy`: at least 10 characters (`security.password_min_length` can raise this, up to 64), mixed case, a number and a symbol, and not found in the haveibeenpwned range API. If that API cannot be reached, this last check passes |
| Seeding again | An existing account keeps its password, and the output says `unchanged` |
| Password lost before first sign-in | Use `/forgot-password`. With the `log` mailer the reset mail is written to `storage/logs/laravel.log` |

**Demo accounts**

Every demo account has the password `password`. Each is active and email verified, and none has to change
its password. The address is the role name as a slug, `@myoffice.test`:

| Role | Panel | Email |
|---|---|---|
| Admin | admin | `admin@myoffice.test` |
| HR | admin | `hr@myoffice.test` |
| Accountant | admin | `accountant@myoffice.test` |
| Project Manager | admin | `project-manager@myoffice.test` |
| Developer | admin | `developer@myoffice.test` |
| Designer | admin | `designer@myoffice.test` |
| SEO Expert | admin | `seo-expert@myoffice.test` |
| Digital Marketer | admin | `digital-marketer@myoffice.test` |
| Sales Executive | admin | `sales-executive@myoffice.test` |
| Receptionist | admin | `receptionist@myoffice.test` |
| Support Agent | admin | `support-agent@myoffice.test` |
| Institute Manager | admin | `institute-manager@myoffice.test` |
| Course Coordinator | admin | `course-coordinator@myoffice.test` |
| Teacher | teacher | `teacher@myoffice.test` |
| Student | student | `student@myoffice.test` |
| Client | client | `client@myoffice.test` |
| Collaborator | collaborator | `collaborator@myoffice.test` |

When they are created:

| Condition | Result |
|---|---|
| `SEED_DEMO=true` | seeded, in any environment |
| `SEED_DEMO=false` | skipped |
| `SEED_DEMO` unset and `APP_ENV=production` | skipped (`Demo users: skipped …`) |
| `SEED_DEMO` unset, any other environment | seeded |

`DemoUserSeeder` does not refuse production on its own. An explicit `SEED_DEMO=true` overrides the
production check. On a live server, set `SEED_DEMO=false`.

### 1.9 Storage link

```bash
php artisan storage:link
```

This links `public/storage` to `storage/app/public`. On Windows, Laravel creates a directory junction
(`mklink /J`), which needs no administrator rights, and it quotes the spaced path itself.

| Disk | Root | Holds |
|---|---|---|
| `public` | `storage/app/public` | Profile avatars, public branding images and CMS media. Served via the link |
| `local` | `storage/app/private` | Private files (D21). Never linked or aliased. Served only by a controller that re-checks permission |

If the link is missing, avatars and uploaded images return 404.

### 1.10 Front-end assets

```bash
npm install
```

```bash
npm run build
```

The build writes `public/build/` (git-ignored). While working on views, run the Vite dev server in a
second terminal instead:

```bash
npm run dev
```

### 1.11 Run it

```bash
php artisan serve
```

Open `http://localhost:8000/login`. Public self-registration does not exist (`/register` returns 404,
D15). `GET /up` is the framework's liveness route.

`composer dev` starts `php artisan serve`, `php artisan queue:listen --tries=1 --timeout=0`,
`php artisan pail --timeout=0` and `npm run dev` together, through `concurrently` with `--kill-others`.
At the time of writing no class in `app/` implements `ShouldQueue` and `routes/console.php` schedules
nothing. Phases 1–2 therefore need neither a queue worker nor a scheduler.

### 1.12 Tests

```bash
composer test
```

`composer test` runs `php artisan config:clear` and then `php artisan test`. There were 1020 tests green
at the Phase 2 checkpoint.

| Fact | Detail |
|---|---|
| Database | `my_office_test` on MariaDB, user `root`, no password (`phpunit.xml`). SQLite is deliberately not used |
| Fixture | `Tests\TestCase` sets `$seed = true`, so `RefreshDatabase` runs one `migrate:fresh --seed` per test process and then wraps every test in a transaction |
| Environment | `SEED_DEMO=true`, `CACHE_STORE=array`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=database`, `BCRYPT_ROUNDS=4` |
| One run at a time | two processes drop each other's tables (tech debt T6, §3.3) |
| Never with a cached config | With `bootstrap/cache/config.php` present, `.env` and the `phpunit.xml` overrides are ignored. The suite then runs `migrate:fresh` on **`my_office`**. `composer test` clears the cache first. If you call `php artisan test` directly, clear it yourself |

A subset:

```bash
php artisan test --filter=SmokeTest
```

### 1.13 Time zone and document numbers

| Rule | What it means in practice |
|---|---|
| **Storage time zone is UTC (D61)** | `config/app.php` hardcodes `'timezone' => 'UTC'`, and nothing changes it at runtime: `ConfigureFromSettings` never touches `app.timezone`. `APP_TIMEZONE` in `.env` is not read |
| **The database session time zone is pinned to UTC (D61)** | `config/database.php` sets `'timezone' => '+00:00'` on the `mysql` and `mariadb` connections. This is deliberately not an `.env` value. A `timestamp` column therefore stores exactly the UTC string Laravel writes, whatever the server's own zone (the XAMPP server here reports `system_time_zone = Asia/Karachi`). Migration `060600` refuses to run, and changes nothing, if the connection is not pinned. For example, a stale cached config would cause that, so run `php artisan config:clear` first when upgrading |
| **`localization.timezone` is display-only (D61)** | Default `Asia/Karachi`. The `Format` helpers render dates in it, and `DateRange` takes input in it and queries with UTC bounds. A user's own profile time zone overrides it for that user |
| **Document counters are not editable in Settings (D62)** | Every `*_next_number` key (today `finance.invoice_next_number`) is read-only in `SettingsRegistry`: shown, never posted, and refused by `SettingsService`. Only `DocumentNumberService` (Phase 5, D27) will advance a counter, under a row lock. Do not change these rows in SQL either: rolling a counter back re-issues a number (D42) |

---

## 2. Production notes

Only part of this section is in the code today. Each item says whether it is.

### 2.1 Document root and a vhost for a spaced path

| Rule | Status |
|---|---|
| `DocumentRoot` is the project's `public/` folder, quoted, with forward slashes | Required now. `public/.htaccess` (Laravel's rewrite) needs `AllowOverride All` |
| No catch-all vhost serving `C:/xampp/htdocs` | Required now. Otherwise `.env`, `storage/` and `vendor/` are reachable |
| `php artisan serve` is never the production server | Required now |

XAMPP loads `conf/extra/httpd-vhosts.conf` (it is included by `httpd.conf`). A minimal HTTPS vhost:

```apache
<VirtualHost *:443>
    ServerName erp.example.com
    DocumentRoot "C:/xampp/htdocs/my office/public"
    <Directory "C:/xampp/htdocs/my office/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile    "C:/xampp/apache/conf/ssl.crt/erp.example.com.crt"
    SSLCertificateKeyFile "C:/xampp/apache/conf/ssl.key/erp.example.com.key"
</VirtualHost>
```

The hardened version (port-80 redirect, security headers, and no PHP execution under `/storage`) is in
`phase-24-25.md` §6.9.1.

### 2.2 HTTPS

| Setting | Status |
|---|---|
| `APP_URL=https://…` | Available now |
| `SESSION_SECURE_COOKIE=true` | Available now (`config/session.php` reads it) |
| HTTP to HTTPS redirect | Do it in the vhost for now. The app has no force-HTTPS setting or middleware yet |
| HSTS | Not in the app yet. Enable it only after the certificate works (`phase-24-25.md` §6.9.2) |
| Reverse proxy | `bootstrap/app.php` does not configure trusted proxies yet. Behind a proxy, login history records the proxy's address |

### 2.3 Database users (D58)

**Decided:** three separate MySQL users. A runtime user with DML only, a migration user with DDL, and a
read-only backup user.

**In the code today:** `config/database.php` has one `mysql` connection, so the application and
`php artisan migrate` use the same `DB_USERNAME`. The `mysql_migration` and `mysql_backup` connections
come with Phase 25. The `CREATE USER` / `GRANT` script is in `phase-24-25.md` §6.9.3.

Until then, never run production as `root` without a password (T3). Give `DB_USERNAME` its own user with a
strong password. To keep DDL rights off the runtime user already, run `migrate` while `.env` temporarily
names a DDL-capable user. Then put the runtime user back and rebuild the config cache.

### 2.4 File permissions

| Path | Web-server user |
|---|---|
| `storage/`, `bootstrap/cache/` | Read and write. These are the only writable paths |
| `.env` | Readable by the application account only |
| `storage/app/private` | Private uploads (D21). Never link or alias it |
| everything else | Read-only |

`icacls` (Windows) and `chown`/`chmod` (Linux) commands are in `phase-24-25.md` §6.9.4.

### 2.5 Config, route and view caching

```bash
php artisan config:cache
```

```bash
php artisan route:cache
```

```bash
php artisan view:cache
```

| Fact | Detail |
|---|---|
| No secret from the database is cached | The SMTP password saved in Settings is stored encrypted. It is copied into the mail config only when the mail manager is first built (`ConfigureFromSettings::MAIL_SECRET_KEYS`, `afterResolving('mail.manager')`). `config:cache` therefore never writes it into `bootstrap/cache/config.php`. This was checked with a probe secret during Phase 2 verification |
| `.env` values *are* cached | `APP_KEY`, `DB_PASSWORD` and any `MAIL_PASSWORD` set in `.env` end up in `bootstrap/cache/config.php`, as in any Laravel app. Keep that folder private |
| Settings need no rebuild | Saved settings are applied at boot from the cached settings payload |
| `.env` changes do | Run `config:cache` again after editing `.env` |
| Seeders and a cached config | With the config cached, Laravel does not load `.env`, so `env('SUPERADMIN_PASSWORD')` and `env('SEED_DEMO')` read nothing. Run `db:seed` before `config:cache`, or after `config:clear` |
| Route caching | `config:cache` was exercised in Phase 2. `route:cache` has not been run against this codebase yet |

### 2.6 Queue worker and scheduler as services

Nothing is queued or scheduled yet (§1.11). Install both at go-live anyway, so later phases need no
infrastructure change.

| Service | Command it runs | How to keep it alive |
|---|---|---|
| Queue worker | `php artisan queue:work --tries=3 --max-time=3600` | Windows: a service manager such as NSSM with `AppDirectory "C:\xampp\htdocs\my office"`. Linux: supervisor. Full definitions in `phase-24-25.md` §6.9.5 |
| Scheduler | `php artisan schedule:run`, every minute | Windows: Task Scheduler running a `.bat` that does `cd /d "C:\xampp\htdocs\my office"` and then runs PHP, which avoids nested quotes in `schtasks /TR`. Linux: `* * * * * cd /var/www/myoffice && php artisan schedule:run`. See §6.9.6 |

After every deploy, restart the workers so they load the new code:

```bash
php artisan queue:restart
```

### 2.7 Log rotation

`.env.example` ships `LOG_CHANNEL=stack` and `LOG_STACK=single`, which writes one ever-growing
`storage/logs/laravel.log`. In production use the `daily` channel. `config/logging.php` keeps
`LOG_DAILY_DAYS` files (default 14) and prunes older ones itself.

| Key | Production value |
|---|---|
| `LOG_STACK` | `daily` |
| `LOG_DAILY_DAYS` | `14` (or your retention) |
| `LOG_LEVEL` | `warning` |

Queue and scheduler output files, and Apache's logs, rotate with the operating system's tools.

### 2.8 Go-live settings

| Where | Setting |
|---|---|
| `.env` | `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, one `APP_KEY` generated once, a non-root `DB_USERNAME` with a password, `SESSION_SECURE_COOKIE=true`, `LOG_STACK=daily`, `LOG_LEVEL=warning`, `SEED_DEMO=false` |
| `.env`, for the seed run | Set `SUPERADMIN_EMAIL` and `SUPERADMIN_NAME` to a real person. Either leave `SUPERADMIN_PASSWORD` empty and copy the printed password, or set it and remove it from `.env` after seeding. The account must change it at first sign-in either way |
| Settings → Company, Branding, Localization, Contact | Name, logo, currency (default PKR) and display time zone (default Asia/Karachi) |
| Settings → Mail | SMTP host, port, user, password and encryption, then send a test mail. Only Super Admin can edit this group: the Admin role is withheld `settings.edit_mail` |
| Settings → Security | `password_min_length` (never below 10), `login_max_attempts`, `lockout_minutes` and `session_lifetime` are enforced. `force_password_change_days`, `allowed_file_types` and `two_factor_enabled` are read-only (not enforced yet) |
| Settings → Maintenance | `maintenance_mode` closes the **public website** with a 503 page and never closes a panel. `php artisan down` closes everything |
| Modules | Disable what the business does not use. A reason of 5–255 characters is required (D63), and the data stays untouched (D5) |
| Roles | Review the 18 system roles. Remember that `db:seed` resets their grants (§1.8) |
| `php.ini` | `display_errors = Off`, and raise `max_input_vars`. The role editor posts one checkbox per granted permission (788 permissions exist), which is close to PHP's default of 1000. `phase-24-25.md` §6.9.7 recommends 5000 |

The full Phase 25 go-live checklist is `phase-24-25.md` §6.13.

---

## 3. Troubleshooting

### 3.1 "Table 'my_office.settings' doesn't exist" during install

| | |
|---|---|
| Symptom | `SQLSTATE[42S02]: Base table or view not found: 1146 Table 'my_office.settings' doesn't exist` (or `modules`, `cache`) from a seeder or a page request |
| Cause | Something needed the table before `php artisan migrate` ran. Booting does not fail without it: `SettingsRepository` and `Modules` degrade to "nothing stored", and `ConfigureFromSettings` falls back to `config/` and `.env`. `artisan` commands therefore still run |
| Fix | Run `migrate`, then `db:seed` (§1.7, §1.8) |

If settings rows were changed directly in SQL, the cached payload still holds the old values. The UI and
the seeders flush it themselves.

```bash
php artisan cache:clear
```

### 3.2 A role or permission change does not take effect

| | |
|---|---|
| Cause | spatie caches the permission map for 24 hours in the cache store (`config/permission.php`). The module map and settings payload are cached until flushed. The role editor and the seeders flush these caches. A change made in SQL or tinker does not, and neither does a deploy that changed `PermissionRegistry` |
| Fix | Reset the permission cache, then clear the framework caches (`CLAUDE.md` §7) |

```bash
php artisan permission:cache-reset
```

```bash
php artisan optimize:clear
```

After a deploy that added modules or permissions, run the seeders again (`db:seed`). Remember the
`RoleSeeder` reset in §1.8.

### 3.3 Two test processes against `my_office_test`

| | |
|---|---|
| Symptom | A suite that was green fails at random: tables that "don't exist" or "already exist", deadlocks, missing roles |
| Cause | Each process starts with `migrate:fresh`, which drops every table the other process is using (tech debt T6) |
| Fix | Stop every run (terminals, IDE test runners, agents). Then run one suite on its own |

See which connections are open on the server:

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root -e "SHOW PROCESSLIST"
```

### 3.4 A slow first `migrate:fresh` on a busy MariaDB

| | |
|---|---|
| Symptom | The test run sits for a long time before the first result, or fails with `General error: 1205 Lock wait timeout exceeded` |
| Cause | Each test process first drops and re-creates every table (all migrations). It then seeds 788 permissions, 18 role grants and 120 settings. DDL waits behind any other connection holding a lock on the same schema: a GUI client with a table open, or a stuck earlier run |
| Fix | Let the first rebuild finish, since it happens once per process. Close other clients on `my_office_test`. Look in `SHOW PROCESSLIST` (above) for `Waiting for table metadata lock`, end the stuck connection, then run again |

### 3.5 Compiled Blade files corrupted with null bytes

| | |
|---|---|
| Symptom | A page that used to render now fails with a PHP parse error in a file under `storage/framework/views/`, and that file is full of NUL characters |
| Cause | A compiled view was left damaged by an interrupted write. The templates in `resources/views` are fine |
| Fix | Delete the compiled views. Blade recompiles each one on the next request |

```bash
php artisan view:clear
```

### 3.6 Other quick checks

| Symptom | Cause | Fix |
|---|---|---|
| Pages have no styles after stopping `npm run dev` | A stale `public/hot` file still points at the stopped Vite server | Delete `public/hot`, or start `npm run dev` again. `npm run build` provides the static assets |
| Avatars and uploaded images return 404 | `public/storage` link missing | `php artisan storage:link` (§1.9) |
| The saved SMTP password stopped working after a key change | Encrypted settings cannot be decrypted with a new `APP_KEY` | Restore the old key, or re-enter the password in Settings → Mail |
| Tests changed data in `my_office` | They ran with a cached config (§1.12) | `php artisan config:clear` before every test run, or use `composer test` |
