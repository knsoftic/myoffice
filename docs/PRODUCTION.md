# PRODUCTION.md — production notes

The production configuration of **phase-24-25 section 6.9**: the web server, TLS, the database users, file
permissions, the queue worker, the scheduler, and PHP itself. [`INSTALL.md`](INSTALL.md) is the ordered
runbook and points here for steps 6, 11, 14, 15 and 16; this file is the reference those steps expand into.
[`GO-LIVE.md`](GO-LIVE.md) is what verifies the result.

Everything below assumes the base path `C:/xampp/htdocs/my office` on Windows with XAMPP and MariaDB 10.4,
with the Linux equivalents given where they differ. **Quote every path and use forward slashes**: the folder
name contains a space (tech debt **T2**), and an unquoted path is the most common failure on this system —
in a vhost it stops Apache from starting, in a service definition it starts a worker in the wrong directory,
and in a scheduled task it produces a task that reports success while running nothing.

| Section | Covers | Used by |
|---|---|---|
| 1 | Apache vhost | INSTALL step 16, GL-15, GL-17 |
| 2 | HTTPS | INSTALL step 16, GL-07, GL-08 |
| 3 | A least-privilege MySQL user | INSTALL step 6, GL-19 |
| 4 | File permissions | INSTALL step 11, GL-16 |
| 5 | Queue worker | INSTALL step 14, GL-40 |
| 6 | Scheduler | INSTALL step 15, GL-41 |
| 7 | PHP, opcache, logging, monitoring | INSTALL steps 1 and 13, GL-06, GL-45 |

---

## 1. Apache vhost — a DocumentRoot whose path contains a space

Three rules make the space a non-issue: **quote every path**, use **forward slashes** even on Windows, and
point `DocumentRoot` *inside* the folder so no URL ever has to encode it. Place the file at
`C:/xampp/apache/conf/extra/httpd-vhosts.conf` (Windows) or `/etc/apache2/sites-available/myoffice.conf`
(Linux); it ships in the repository as `deploy/apache-vhost.conf` so the server configuration is reviewed
like code rather than typed from memory on the night of a deploy.

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

The storage `Alias` block is the one people delete because "the symlink works fine". Keep it and keep its
`FilesMatch` deny list even when the symlink exists: it is the second lock on the same door. The upload
layer refuses blocked extensions (`security.upload_blocked_extensions`) and the private disk is not
web-reachable at all — but the day one of those fails, this block is what stops an uploaded `.php` from
being *executed* rather than merely *stored*.

Additional rules:

| Rule | Why / how |
|---|---|
| Nothing outside `public/` is ever served | `DocumentRoot` points at `public/`; the directories above it sit in no `Alias`. DEP-09 asserts `GET /.env`, `/storage/logs/laravel.log`, `/composer.json`, `/vendor/autoload.php`, `/database/database.sqlite`, `/docs/requirements.md` and `/.git/config` all fail |
| Apache must not be the global XAMPP instance on a shared box | one vhost per site, `ServerName` set, no `_default_` catch-all serving `htdocs/` |
| `php artisan serve` is **never** the production server | DEP-10: the go-live check fails if the `APP_URL` host resolves to a `:8000` dev server |
| Linux variant | the identical file with `/var/www/myoffice/public` paths, `a2ensite myoffice && systemctl reload apache2`, certificates from certbot (`certbot --apache -d erp.example.com`), renewal verified with `certbot renew --dry-run` |
| Behind a proxy or load balancer | set `TRUSTED_PROXIES` and `security.trusted_proxies`; otherwise every `login_histories.ip_address` records the proxy and the rate limiters key every user to one IP — DEP-11 checks a known client IP arrives intact, and that a spoofed `X-Forwarded-For` is ignored when no proxy is trusted |

**When Apache will not start after adding the vhost**, read
`C:/xampp/apache/logs/myoffice-error.log` before changing anything. An unquoted `DocumentRoot` and a
missing module (`headers`, `ssl`, `expires`) account for nearly every case; `httpd -t` names the line.

## 2. HTTPS

1. Install the certificate (client-provided on Windows; `certbot --apache` on Linux).
2. Set `APP_URL=https://...`, `SESSION_SECURE_COOKIE=true` and `security.force_https = true`.
3. Verify: `https://` serves, `http://` 301s, and there is no mixed-content warning in the browser console
   on the public home page, the admin shell and a print view — every asset URL in the HTML must start with
   `https` or `/`.
4. **Only then** set `security.hsts_enabled = true`, and decide `hsts_include_subdomains` deliberately,
   because it is hard to undo. Order matters: HSTS before a working certificate locks the site out of every
   browser that saw the header, for as long as `hsts_max_age` says. There is no server-side fix for that;
   each visitor has to clear it themselves.
5. Re-run `php artisan config:cache` after any `.env` change. Settings changed in the admin UI need no cache
   rebuild — they live in the database.

`security.force_https` is ignored when `APP_ENV=local`, so a developer machine is not forced onto TLS it
does not have.

## 3. A least-privilege MySQL user

Run this as root, once, at install (INSTALL step 6). The separation it creates is the reason a compromised
application cannot drop a table, and DEP-07 asserts it is still in place.

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

-- 2b. the scratch schema the weekly restore proof uses (HD-6, GL-36).
--     WITHOUT THIS THE PROOF CANNOT PASS. `backup:verify --deep` restores the latest archive into
--     `my_office_restore_test`, runs the reconciliation against it and drops it again; every one
--     of those steps is DDL, and the grant above covers `my_office`.* only.
--
--     CREATE is global because `CREATE DATABASE` is a global privilege — it cannot be scoped to a
--     schema that does not exist yet. Everything else is scoped to the scratch schema by name, so
--     the migration user gains nothing further on `my_office` and nothing at all elsewhere.
GRANT CREATE ON *.* TO 'my_office_migrator'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES,
      CREATE VIEW, SHOW VIEW, TRIGGER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE, LOCK TABLES
  ON `my_office_restore_test`.* TO 'my_office_migrator'@'127.0.0.1';

-- 3. backup user: read and dump only.
CREATE USER 'my_office_backup'@'127.0.0.1' IDENTIFIED BY '<32 random chars>';
GRANT SELECT, SHOW VIEW, LOCK TABLES, TRIGGER, EVENT ON `my_office`.* TO 'my_office_backup'@'127.0.0.1';

-- 4. close the XAMPP defaults (tech debt T3)
ALTER USER 'root'@'localhost' IDENTIFIED BY '<strong password>';
DELETE FROM mysql.user WHERE User = '';        -- anonymous users
DROP DATABASE IF EXISTS test;
FLUSH PRIVILEGES;
```

> **The restore proof and trigger DEFINERs.** The spine creates nine `BEFORE DELETE` triggers, and
> MariaDB stamps each with the DEFINER of whoever ran `migrate` — `my_office_migrator` on a split
> install. A dump carries those DEFINER clauses, and restoring a trigger whose DEFINER is not the
> current user requires `SUPER`, which §6.9.3 forbids on purpose. So the weekly deep verification
> must run its restore **as the same user that created the triggers**, which is why
> `BackupVerificationService` resolves `mysql_migration` rather than the runtime connection. If you
> change who runs migrations, the restore proof starts failing on the triggers and not on the data.

> **This separation is only real in production.** On a single-user install — XAMPP, a developer
> machine — `mysql_migration` and `mysql_backup` fall back to the app credentials, which is
> usually `root`, and every one of these restrictions silently does nothing. `DEP-07` is the test
> that catches it: `php artisan migrate --force` on the *default* connection must **fail** with a
> privilege error. If it succeeds on your server, the users are not split and nothing above is in
> force.

`config/database.php` carries a second connection `mysql_migration`, identical to `mysql` but reading
`DB_MIGRATION_USERNAME` / `DB_MIGRATION_PASSWORD`, and a third `mysql_backup` used only by the dump. That is
what makes the separation real rather than aspirational: the application literally has no connection with
DDL rights.

On a machine that also runs the test suite, create the test database in the same session —
`CREATE DATABASE IF NOT EXISTS my_office_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` —
because `phpunit.xml` points at `my_office_test` and the suite rebuilds it on every run. It must never be
the live database, and no production host needs it at all.

| Acceptance criterion | Proof |
|---|---|
| The app user cannot change the schema | `php artisan migrate --force` on the default connection **fails** with a privilege error; `--database=mysql_migration` succeeds (DEP-07) |
| No user holds `SUPER`, `FILE`, `PROCESS`, `RELOAD`, `GRANT OPTION` or `CREATE USER` | `SHOW GRANTS` for all three users, asserted by `php artisan security:audit` |
| The triggers still fire for the app user | FIN-17 runs a raw `DELETE` on each of the nine append-only tables **as the app user** and expects SQLSTATE 45000 |
| No credential is in the repository | `git grep` and `security:audit` find no password literal; `.env` is outside the webroot and readable only by the service account |

The nine `BEFORE DELETE` triggers are not decoration. They are the last line under the append-only rule
(**D19**, and **D16** for the nine financial tables): a ledger row cannot be deleted by any code path,
including a mistaken `DELETE` typed straight into the database. If a restore or a migration ever leaves
them missing, `integrity:verify --suite=constraints` says so and GL-20 blocks go-live.

## 4. File permissions

The web user writes **only** `storage/` and `bootstrap/cache/`. Everything else is read-only to it. This is
the difference between an upload bug that is an inconvenience and an upload bug that is a remote code
execution.

**Linux**

```bash
sudo chown -R deploy:www-data /var/www/myoffice
sudo find /var/www/myoffice -type d -exec chmod 750 {} \;
sudo find /var/www/myoffice -type f -exec chmod 640 {} \;
sudo chmod -R ug+rwX /var/www/myoffice/storage /var/www/myoffice/bootstrap/cache
sudo chmod 600 /var/www/myoffice/.env
sudo chmod +x  /var/www/myoffice/artisan
```

**Windows** — first give Apache its own low-privilege account (`svc_myoffice`) instead of LocalSystem, then:

```powershell
icacls "C:\xampp\htdocs\my office" /inheritance:r `
  /grant:r "Administrators:(OI)(CI)F" "SYSTEM:(OI)(CI)RX" "svc_myoffice:(OI)(CI)RX"
icacls "C:\xampp\htdocs\my office\storage"          /grant "svc_myoffice:(OI)(CI)M"
icacls "C:\xampp\htdocs\my office\bootstrap\cache"  /grant "svc_myoffice:(OI)(CI)M"
icacls "C:\xampp\htdocs\my office\.env" /inheritance:r /grant:r "Administrators:F" "svc_myoffice:R"
icacls "C:\xampp\htdocs\my office\storage\app\backups" /grant "svc_myoffice:(OI)(CI)M"
```

`icacls` is one of the few tools here that wants backslashes; keep the quotes regardless.

**Acceptance criterion**: `php artisan security:audit` enumerates every directory under the base path and
fails on any writable one outside `storage/` and `bootstrap/cache/`, and on a world-readable `.env`
(GL-16, invariant HD-8).

**When it goes wrong in the other direction** — `failed to open stream: Permission denied` on
`storage/logs/laravel.log`, an upload that 500s, a view cache that will not write — re-grant modify rights
on those two directories only. Granting full control over the whole tree makes the symptom disappear and
undoes the step.

## 5. Queue worker

**Linux (supervisor)** — `/etc/supervisor/conf.d/myoffice-queue.conf`, shipped as
`deploy/supervisor-queue.conf`:

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

**Windows (NSSM)** — note the quoted working directory:

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
| `--queue=high,default` | commission jobs and reversals are dispatched to `high`, so a bulk export can never delay money |
| `--max-time=3600 --max-jobs=500` | a bounded lifetime defeats memory creep; the supervisor restarts the worker |
| `queue:restart` is part of every deploy | a worker holds the old code in memory; skipping this is the classic "the fix did not take" bug |
| `stopwaitsecs=3600` | never SIGKILL a worker mid-transaction |
| Two processes, not ten | the database queue driver serialises on `jobs`; the unique-job guards make concurrency safe, but more workers only add lock contention at this scale |
| Failed jobs | `php artisan queue:failed`, `queue:retry <id>`, `queue:retry all`. The `failed_jobs` count is a health probe and an `ops:digest` line. **Never** `queue:flush` on production without reading the rows first — a flushed commission job is lost work, which is exactly why `commissions:sweep` exists to re-queue it |

**The failure mode to recognise**: without a running worker the application *looks* healthy. A fee payment
is recorded, the commission job is enqueued, and no ledger entry ever appears. `php artisan ops:health`
reports the queue heartbeat, and `ops:check-heartbeats` raises `QueueWorkerStalled` within
`ops.queue_heartbeat_max_minutes` — trust those probes rather than the absence of error messages.

## 6. Scheduler

**Linux** — one crontab line, nothing else:

```cron
* * * * * cd /var/www/myoffice && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

**Windows** — a `.bat` wrapper dodges the quoting trap of nested quotes in `schtasks /TR`. It ships as
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

**Acceptance**: `php artisan schedule:list` shows every scheduled command with the right cadence and
timezone (`Asia/Karachi`); `ops.scheduler_heartbeat` is refreshed within two minutes; `schedule:test` runs a
chosen command interactively; a scheduler that stops is reported by `ops:check-heartbeats` within
`ops.scheduler_heartbeat_max_minutes`.

The scheduler is not only housekeeping. `integrity:verify`, `collaborators:reconcile-wallets`,
`financial:verify-constraints`, `backup:verify` and `security:audit` all run from it, which is what makes
invariant HD-10 true: the proof suites run forever, not once, so a regression introduced in month seven is
found that night instead of by a client. A silent scheduler is therefore a financial control failure, not
a cosmetic one.

**When the task runs but nothing happens**, run `deploy/schedule.bat` by hand first. If it works
interactively but not as a task, the task account cannot read the directory, or `Start in` was never set —
which is precisely what the `cd /d` line in the wrapper exists to avoid.

## 7. PHP, opcache, logging, monitoring

`php.ini` (production), shipped as `deploy/php-production.ini`:

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
| `max_input_vars = 5000` | **not cosmetic**: the role editor posts one checkbox per permission and the registry declares several hundred. At the default 1000, PHP silently truncates the array and the role saves with permissions missing — no error, no warning, a role that quietly lost half its access. SEC-19 posts a full matrix and asserts every checked permission is persisted |
| Resetting opcache on deploy | `validate_timestamps=0` means new code is invisible until the SAPI restarts. The deploy step is `httpd -k graceful` (Windows: `Restart-Service Apache2.4`) or `systemctl reload apache2` — **not** a web-reachable `opcache_reset()` route, which would be an unauthenticated denial-of-service lever |
| JIT | left **off**. This workload is I/O and query bound; JIT adds risk without a measured win. Revisit only with a performance baseline that shows CPU as the bottleneck |
| Disabled functions | `dl` only. `proc_open` must stay enabled — `mysqldump`, the backup package and `npm run build` all need it. Disabling `exec` and `shell_exec` is fine and recommended; `security:audit` warns when `proc_open` was disabled, because backups would otherwise stop working silently |
| Logging | `LOG_CHANNEL=stack`, `LOG_STACK=daily`, `LOG_LEVEL=warning`, `LOG_DAILY_DAYS` from `ops.log_retention_days` (Laravel prunes its own dailies — no logrotate needed for application logs). Apache logs rotate with the OS tool: `logrotate` on Linux, a weekly `schtasks` archive job on Windows. Every channel carries the `RedactSensitive` tap |
| Log access | `storage/logs` is not web-reachable (DEP-09) and the in-app log viewer is gated by `activity_log.view_logs` |
| Error monitoring, tier 1 (**the contract**) | No new dependency: a `ReportCriticalError` listener plus `ops:digest`. A 500, a `CommissionGenerationFailed`, a `WalletDriftDetected`, a failed backup, a stalled worker or scheduler and a failed integrity suite each raise a database notification to holders of `system_health.view_logs` and, when `backup.notify_emails` is set, an email. The digest lands daily at `ops.error_digest_time` |
| Error monitoring, tier 2 (optional) | `sentry/sentry-laravel` behind `ops.error_monitoring_enabled` and `error_monitoring_dsn`, `traces_sample_rate = 0.1`, PII scrubbing on (`send_default_pii = false`), and the `RedactSensitive` keys added to its scrubber |
| Health endpoint | `GET /health` with `ops.health_check_token` in an `X-Health-Token` header **or** a signed URL — never a query string, because a token in a URL ends up in access logs and referrers. A wrong or missing token returns **404**, so the endpoint is not discoverable. It answers 200 `{status: ok|degraded, checks: {...}}` or 503. `/up` stays as a bare liveness probe |

**The deploy trap worth stating twice**: with `opcache.validate_timestamps = 0`, swapping in new code
changes nothing until the SAPI is reloaded. Every symptom looks like a failed deploy — the fix is not live,
the version string is old, a new route 404s — and every one of them is one `Restart-Service Apache2.4`
away. Put the reload in the deploy script, not in your memory.

---

## Where the rest lives

| Topic | Document |
|---|---|
| Ordered installation steps | [`INSTALL.md`](INSTALL.md) |
| Go-live checklist, GL-01..GL-52 | [`GO-LIVE.md`](GO-LIVE.md) |
| Backup, verification, retention and the restore procedure | phase-24-25 section 6.10 |
| Deploy runbook for every release after the first | phase-24-25 section 6.11 |
| Rollback ladder, per phase | phase-24-25 section 6.12 |
| Decisions, tech debt, accepted risks, client decisions | `DEVELOPMENT_LOG.md` sections 4, 8, 9 |
