#!/usr/bin/env bash
#
# aapanel-deploy.sh — deploy the latest master to knsoftic.com on aaPanel, in place.
#
# Run it as root in the aaPanel terminal. The newest copy of this script always lives on GitHub, so
# fetch it from there rather than running the copy in the tree (which is about to be replaced):
#
#   cd /www/wwwroot/knsoftic.com && git -c safe.directory="$PWD" fetch origin master && git -c safe.directory="$PWD" show origin/master:deploy/aapanel-deploy.sh > /root/myoffice-deploy.sh && bash /root/myoffice-deploy.sh
#
# Options:  --yes        do not ask before taking the site down (a failed smoke test still keeps it down)
#           --full-npm   run `npm ci` even when package-lock.json did not change
#           --landing    also run `cms:landing-page --hero --menu --tidy` (rewrites the live hero copy)
#
# The folder it deploys is the one nginx actually serves for the site — the `root` of aaPanel's vhost
# file, minus `/public` — not an assumed path: on knsoftic.com the git checkout at the default path was
# found at an older commit than the code the site was serving. Pass APP_DIR to choose explicitly; it is
# refused when it disagrees with nginx.
#
# Before the backup it checks that `backup.mysqldump_path` exists. A database installed from the defaults
# still holds the Windows XAMPP path; when it does not exist and aaPanel's binary does, the setting is
# pointed at aaPanel's through SettingsService (audited), and the change is printed.
#
# This is docs/AAPANEL.md section 15 as one script, with the corrections docs/DEPLOY.md makes to it:
# the four reference seeders by name (a bare `db:seed` runs every seeder, CMS content included), migrations on
# the `mysql_migration` connection, php-fpm reloaded (opcache.validate_timestamps = 0), the public page
# cache cleared. It is NOT the directory-swap deploy of DEPLOY.md: a code rollback here is
# `git reset --hard <previous commit>` plus the cache steps, and a migration is rolled back only per
# ROLLBACK.md. The previous commit is printed at the start and written to storage/logs/deploys.log.
#
# Every setting below can be overridden from the environment, e.g. PHP_BIN=/www/server/php/83/bin/php.

set -Eeuo pipefail

SITE="${SITE:-knsoftic.com}"
NGINX_VHOST="${NGINX_VHOST:-/www/server/panel/vhost/nginx/$SITE.conf}"
MYSQL_BIN_DIR="${MYSQL_BIN_DIR:-/www/server/mysql/bin}"
APP_DIR="${APP_DIR:-}"
PHP_BIN="${PHP_BIN:-/www/server/php/82/bin/php}"
FPM_SERVICE="${FPM_SERVICE:-/etc/init.d/php-fpm-82}"
WEB_USER="${WEB_USER:-www}"
BRANCH="${BRANCH:-master}"
BACKUP_TYPE="${BACKUP_TYPE:-database}"

ASSUME_YES=0
FULL_NPM=0
LANDING=0
for arg in "$@"; do
    case "$arg" in
        --yes)      ASSUME_YES=1 ;;
        --full-npm) FULL_NPM=1 ;;
        --landing)  LANDING=1 ;;
        *) echo "Unknown option: $arg" >&2; exit 64 ;;
    esac
done

# Run from a private copy: `git merge` below rewrites this file when it is run from inside the tree.
if [[ -z "${MYOFFICE_DEPLOY_COPY:-}" ]]; then
    copy="$(mktemp /tmp/myoffice-deploy.XXXXXX.sh)"
    cp "$0" "$copy"
    MYOFFICE_DEPLOY_COPY="$copy" exec bash "$copy" "$@"
fi
trap 'rm -f "$MYOFFICE_DEPLOY_COPY"' EXIT

STEP="preflight"
SITE_DOWN=0
BYPASS_URL=""

bold()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
warn()  { printf '\033[33m!! %s\033[0m\n' "$*"; }

still_down() {
    if [[ "$SITE_DOWN" == 1 ]]; then
        cat >&2 <<EOF
   The site is STILL in maintenance mode, on purpose: nothing half-deployed is serving users.
   You can look at it through the bypass URL:  $BYPASS_URL
   Fix the error above and run this script again (it is safe to re-run), or bring the site back with:
     cd $APP_DIR && sudo -u $WEB_USER $PHP_BIN artisan up
   The commit you were running before: ${OLD:-unknown}  (rollback: docs/ROLLBACK.md)
EOF
    else
        echo "   The site was never taken down. Nothing has changed for users." >&2
    fi
}

fail() {
    printf '\033[31mxx %s\033[0m\n' "$*" >&2
    still_down
    exit 1
}

on_error() {
    local code=$?
    printf '\n\033[31mxx Deploy FAILED during: %s (exit %s)\033[0m\n' "$STEP" "$code" >&2
    still_down
    exit "$code"
}
trap on_error ERR

# Root may run git in a tree owned by www (safe.directory), and `chmod +x artisan` is not a change.
repo()    { git -c safe.directory="$APP_DIR" -c core.fileMode=false -C "$APP_DIR" "$@"; }
# Never run artisan as root: it leaves root-owned logs and caches that www then cannot write (AAPANEL.md 7).
artisan() { sudo -u "$WEB_USER" "$PHP_BIN" artisan "$@"; }

confirm() {
    [[ "$ASSUME_YES" == 1 ]] && return 0
    local answer
    read -r -p "$1 Type yes to continue: " answer
    [[ "$answer" == "yes" ]]
}

# --- preflight -------------------------------------------------------------------------------------

[[ "$(id -u)" == 0 ]] || fail "Run this as root (the aaPanel terminal is root)."

# Deploy the folder nginx serves, not the one we assume it serves.
SERVED=""
if [[ -f "$NGINX_VHOST" ]]; then
    SERVED="$(sed -nE 's/^[[:space:]]*root[[:space:]]+([^;[:space:]]+)[[:space:]]*;.*/\1/p' "$NGINX_VHOST" | head -n1)"
    SERVED="${SERVED%/}"
    SERVED="${SERVED%/public}"
fi
if [[ -z "$APP_DIR" ]]; then
    [[ -n "$SERVED" ]] || fail "Could not read the site folder from $NGINX_VHOST. Pass it: APP_DIR=/www/wwwroot/<folder> bash /root/myoffice-deploy.sh"
    APP_DIR="$SERVED"
elif [[ -n "$SERVED" && "$SERVED" != "${APP_DIR%/}" ]]; then
    fail "nginx serves $SITE from $SERVED, not $APP_DIR. Deploying $APP_DIR would change nothing users see."
fi
echo "Site folder: $APP_DIR${SERVED:+  (the root nginx serves for $SITE)}"

[[ -d "$APP_DIR/.git" ]] || fail "$APP_DIR is not a git checkout, so it was uploaded by hand and cannot be fast-forwarded. Clone the repository there first (docs/AAPANEL.md 5.1)."
[[ -x "$PHP_BIN" ]] || fail "PHP not found at $PHP_BIN — check: ls /www/server/php/"
[[ -f "$APP_DIR/.env" ]] || fail "$APP_DIR/.env is missing."
COMPOSER_BIN="$(command -v composer || true)"
[[ -n "$COMPOSER_BIN" ]] || fail "composer is not on PATH (AAPANEL.md 1.3)."
command -v npm >/dev/null || fail "npm is not on PATH (AAPANEL.md 1.3)."
command -v sudo >/dev/null || fail "sudo is not installed."

cd "$APP_DIR"

APP_URL="$(grep -E '^APP_URL=' .env | tail -n1 | cut -d= -f2- | tr -d "\"' \r")"
APP_URL="${APP_URL%/}"
[[ -n "$APP_URL" ]] || fail "APP_URL is empty in .env."

bold "Fetching origin/$BRANCH"
repo fetch origin "$BRANCH"
OLD="$(repo rev-parse HEAD)"
NEW="$(repo rev-parse "origin/$BRANCH")"

dirty="$(repo status --porcelain --untracked-files=no)"
if [[ -n "$dirty" ]]; then
    echo "$dirty"
    fail "Files in the live tree were edited by hand. Save or discard those edits first — this script will not overwrite them."
fi
repo merge-base --is-ancestor "$OLD" "$NEW" \
    || fail "The server has commits that origin/$BRANCH does not. Resolve that by hand; this script only fast-forwards."

if [[ "$OLD" == "$NEW" ]]; then
    echo "The code is already at ${NEW:0:7}. Continuing anyway: migrations, seeders and caches are re-applied."
else
    echo "Currently live: ${OLD:0:7}    Deploying: ${NEW:0:7}"
    echo
    repo log --oneline --no-decorate "$OLD..$NEW"
    echo
    migrations="$(repo diff --name-only --diff-filter=A "$OLD" "$NEW" -- database/migrations)"
    if [[ -n "$migrations" ]]; then
        echo "New migrations:"; echo "$migrations" | sed 's/^/  /'
    else
        echo "New migrations: none"
    fi
    repo diff --quiet "$OLD" "$NEW" -- composer.lock    || echo "composer.lock changed — PHP packages will change."
    repo diff --quiet "$OLD" "$NEW" -- package-lock.json || { echo "package-lock.json changed — npm ci will run."; FULL_NPM=1; }
fi
[[ -d node_modules ]] || FULL_NPM=1

bold "System health (informational)"
health=0
artisan ops:health --deep || health=$?
[[ "$health" == 0 ]] || warn "ops:health exited $health. Read it above: a known-red probe is fine, a new one is not."

echo
confirm "Deploy ${NEW:0:7} to $APP_URL? The site goes into maintenance for a few minutes." \
    || { echo "Cancelled. Nothing has changed."; exit 0; }

# --- 1. backup, while the site is still up ---------------------------------------------------------

STEP="backup tool path"
bold "1/9  Backup ($BACKUP_TYPE)"
helper="$(mktemp /tmp/myoffice-backup-paths.XXXXXX.php)"
cat > "$helper" <<'PHP'
<?php
// Point backup.mysqldump_path / backup.mysql_path at aaPanel's binaries when the stored ones do not exist.
[, $base, $binDir] = $argv;
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$wanted = [];
foreach (['mysqldump_path' => 'mysqldump', 'mysql_path' => 'mysql'] as $key => $binary) {
    $current = trim((string) setting('backup.'.$key, ''));
    if ($current !== '' && is_file($current)) {
        echo "  backup.$key: $current", PHP_EOL;
        continue;
    }
    $candidate = rtrim($binDir, '/').'/'.$binary;
    if (is_file($candidate)) {
        $wanted[$key] = $candidate;
    } elseif ($binary === 'mysqldump') {
        fwrite(STDERR, "  backup.$key: '$current' does not exist, and neither does $candidate".PHP_EOL);
        exit(1);
    } else {
        echo "  backup.$key: '$current' does not exist (only a restore needs it)", PHP_EOL;
    }
}
if ($wanted !== []) {
    $changes = app(App\Services\Core\SettingsService::class)->asSystem()->update('backup', $wanted);
    foreach ($changes as $key => $change) {
        echo "  $key: ", json_encode($change['old']), ' -> ', json_encode($change['new']), '  (fixed)', PHP_EOL;
    }
}
PHP
chmod 644 "$helper"
sudo -u "$WEB_USER" "$PHP_BIN" "$helper" "$APP_DIR" "$MYSQL_BIN_DIR"
rm -f "$helper"

STEP="pre-deploy backup"
artisan backup:run --type="$BACKUP_TYPE" --reason="pre-deploy ${NEW:0:7}"

# --- 2. maintenance --------------------------------------------------------------------------------

STEP="maintenance mode"
bold "2/9  Maintenance mode"
SECRET="$(openssl rand -hex 16 2>/dev/null || head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
artisan down --secret="$SECRET" --render=errors::503 --retry=60
SITE_DOWN=1
BYPASS_URL="$APP_URL/$SECRET"
echo "Bypass URL (opens the site for you only): $BYPASS_URL"
# Queue workers stop taking jobs while the application is down, so no job runs on a half-migrated schema.

# --- 3. code ---------------------------------------------------------------------------------------

STEP="git fast-forward"
bold "3/9  Code -> ${NEW:0:7}"
repo merge --ff-only --quiet "origin/$BRANCH"
rm -f public/hot   # trap 5: a stale hot file points every asset at a dev server

# --- 4. dependencies and front end -----------------------------------------------------------------

STEP="composer install"
bold "4/9  PHP packages"
# Run composer with PHP 8.2 explicitly: the `php` on root's PATH may be another version.
if head -n1 "$COMPOSER_BIN" | grep -q php; then
    COMPOSER=("$PHP_BIN" "$COMPOSER_BIN")
else
    COMPOSER=("$COMPOSER_BIN")
fi
COMPOSER_ALLOW_SUPERUSER=1 "${COMPOSER[@]}" install --no-dev --optimize-autoloader --no-interaction

STEP="front-end build"
bold "5/9  Front end"
if [[ "$FULL_NPM" == 1 ]]; then
    npm ci --no-audit --no-fund
fi
npm run build
[[ -f public/build/manifest.json ]] || fail "public/build/manifest.json was not produced."

STEP="file permissions"
# composer, npm and git ran as root; hand the tree back to www (AAPANEL.md 7). The immutable .user.ini
# cannot be chowned and is skipped.
find . -path ./.user.ini -prune -o -exec chown -h "$WEB_USER:$WEB_USER" {} +
find . \( -path ./node_modules -o -path ./.user.ini \) -prune -o -type d -exec chmod 750 {} +
find . \( -path ./node_modules -o -path ./.user.ini \) -prune -o -type f -exec chmod 640 {} +
chmod -R ug+rwX storage bootstrap/cache
chmod 600 .env
chmod +x artisan

# --- 5. schema and reference data ------------------------------------------------------------------

STEP="clear old caches"
# The previous release's config, route and event caches are still on disk; nothing below may read them.
# This also empties the cache store, which holds the public page cache (AAPANEL.md 15.3).
artisan optimize:clear

STEP="migrate"
bold "6/9  Database"
artisan migrate:status --pending || true
artisan migrate --database=mysql_migration --force --step

STEP="reference seeders"
# By name, never a bare db:seed (DEPLOY.md step 9). All four are idempotent and never revoke anything.
for seeder in ModuleSeeder PermissionSeeder RoleSeeder SettingSeeder; do
    artisan db:seed --class="$seeder" --force
done

if [[ "$LANDING" == 1 ]]; then
    STEP="landing page"
    artisan cms:landing-page --hero --menu --tidy --force
fi

# --- 6. caches -------------------------------------------------------------------------------------

STEP="caches"
bold "7/9  Caches"
artisan permission:cache-reset
artisan optimize
artisan event:cache
artisan media:warm-settings-images || warn "media:warm-settings-images failed — logos are served unresized; not fatal."

STEP="php-fpm reload"
# opcache.validate_timestamps = 0: without this reload the old code keeps running (AAPANEL.md 15.2).
if [[ -x "$FPM_SERVICE" ]]; then
    "$FPM_SERVICE" reload
elif ! { command -v systemctl >/dev/null && systemctl reload php-fpm-82; }; then
    fail "Cannot find php-fpm to reload (looked for $FPM_SERVICE). Without a reload the old code keeps running."
fi
artisan queue:restart

# --- 7. smoke test, still behind maintenance -------------------------------------------------------

STEP="smoke test"
bold "8/9  Smoke test through the bypass"
jar="$(mktemp)"
CURL=(curl -sS --max-time 30 -b "$jar" -c "$jar")
status() { "${CURL[@]}" -o /dev/null -w '%{http_code}' "$1" 2>/dev/null || true; }
smoke_ok=1
"${CURL[@]}" -L -o /dev/null "$BYPASS_URL" || true   # sets the bypass cookie
for path in / /login; do
    code="$(status "$APP_URL$path")"
    echo "  $path -> ${code:-000}"
    [[ "$code" == 200 ]] || smoke_ok=0
done
# The stylesheet the home page names must answer 200, or the site is up but unstyled (AAPANEL.md 6.2).
css="$("${CURL[@]}" "$APP_URL/" 2>/dev/null | grep -oE '/build/assets/[^"]+\.css' | head -n1 || true)"
if [[ -n "$css" ]]; then
    code="$(status "$APP_URL$css")"
    echo "  $css -> $code"
    [[ "$code" == 200 ]] || smoke_ok=0
else
    echo "  stylesheet: not found in the home page HTML"
    smoke_ok=0
fi
rm -f "$jar"

if [[ "$smoke_ok" != 1 ]]; then
    warn "The smoke test did not pass. Open the bypass URL and look before letting users in:"
    warn "  $BYPASS_URL"
    if [[ "$ASSUME_YES" == 1 ]] || ! confirm "Bring the site up anyway?"; then
        echo "Left in maintenance mode. When it looks right:  cd $APP_DIR && sudo -u $WEB_USER $PHP_BIN artisan up"
        exit 1
    fi
fi

# --- 8. up -----------------------------------------------------------------------------------------

STEP="artisan up"
bold "9/9  Up"
artisan up
SITE_DOWN=0

printf '%s  %s -> %s  by %s\n' "$(date '+%F %T %Z')" "${OLD:0:7}" "${NEW:0:7}" "${SUDO_USER:-$(whoami)}" \
    | sudo -u "$WEB_USER" tee -a storage/logs/deploys.log >/dev/null

bold "Deployed ${NEW:0:7} to $APP_URL"
echo "Previous commit: $OLD"
echo "Watch the log for the next hour:  tail -f $APP_DIR/storage/logs/laravel-\$(date +%F).log"
