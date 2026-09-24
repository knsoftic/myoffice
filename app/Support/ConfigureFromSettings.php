<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Saved settings -> runtime configuration (phase-02 §3).
 *
 * Called once per process from `AppServiceProvider::boot()` (inside its own try/catch) so the
 * values an administrator saved in the UI — not the ones baked into `.env` — are what the
 * application actually runs on:
 *
 *   · `mail.*`        the transport, credentials, from/reply-to addresses (D10, Q7)
 *   · `app.locale`    from `localization.locale`
 *   · `session.lifetime` from `security.session_lifetime`
 *
 * It deliberately does **not** touch `app.timezone` (D61): storage is UTC, and
 * `localization.timezone` is a display setting read by `Format` and `DateRange`.
 *   · `branding.*`    the `--brand-*` CSS variable payload derived from `branding.brand_color`,
 *                     ready for the layout's `:root` block (phase-02 §5)
 *
 * Three hard rules this class lives by:
 *
 *  1. **It can never break the app.** Every read and every write is wrapped: on a fresh install
 *     the `settings` table does not exist yet, during `migrate:fresh` it disappears mid-command,
 *     and the cache store may be unavailable. `SettingsRepository` already degrades to "no
 *     settings stored" in those cases; `attempt()` here contains anything else so one bad value
 *     (an unparseable timezone, a broken colour) cannot take the boot down with it.
 *  2. **It adds no query to a request.** Reads go through the cached `SettingsRepository`
 *     payload — the same one the shell already loads — never through a fresh query per key.
 *  3. **It is the only mail mapping.** `mailConfig()` is pure and public so `TestMailService`
 *     sends through exactly the configuration a real mail would use, rather than a second,
 *     slightly different translation of the same nine settings.
 */
final class ConfigureFromSettings
{
    /** Transports `mail.default` may be switched to from the settings table. */
    public const MAILERS = ['smtp', 'log', 'sendmail', 'array'];

    /**
     * Mail config keys that hold a decrypted secret — applied only when the mail manager is built
     * (see applyMail()), never at boot.
     *
     * @var list<string>
     */
    public const MAIL_SECRET_KEYS = ['mail.mailers.smtp.password'];

    /** Config key holding the derived brand palette (see brandPalette()). */
    public const PALETTE_KEY = 'branding.palette';

    /**
     * The Phase 1 indigo scale, used when `branding.brand_color` is missing or unreadable.
     *
     * Values are "R G B" channel triplets, never hex: `tailwind.config.js` wraps them as
     * `rgb(var(--brand-500) / <alpha-value>)`, which is what keeps `bg-brand-500/25` working.
     *
     * @var array<int, string>
     */
    public const FALLBACK_PALETTE = [
        50 => '238 242 255',
        100 => '224 231 255',
        200 => '199 210 254',
        300 => '165 180 252',
        400 => '129 140 248',
        500 => '99 102 241',
        600 => '79 70 229',
        700 => '67 56 202',
        800 => '55 48 163',
        900 => '49 46 129',
        950 => '30 27 75',
    ];

    /**
     * How far each stop is mixed away from the base colour: towards white below the anchor,
     * towards black above it.
     *
     * The anchor is the **600** stop, not 500 (Phase 2 integration fix). Three reasons:
     *
     *  1. `branding.brand_color` defaults to #4f46e5, which is Tailwind indigo-**600**. Anchoring
     *     at 500 made every derived stop one step darker than the Phase 1 design, so a default
     *     install rendered a shell that did not match `FALLBACK_PALETTE` below — the very palette
     *     `resources/css/app.css` carries as its bare-`:root` fallback.
     *  2. The shell paints its primary controls with `brand-600`, so the 600 stop is the one a
     *     business actually means by "our brand colour". Anchored there it is reproduced exactly
     *     (0.0 mix), which is the single property a colour picker has to honour.
     *  3. The branding screen's live preview
     *     (`resources/views/components/settings/brand-preview.blade.php`) already derives its ramp
     *     this way. Two derivations of one palette must agree or the preview lies about what
     *     saving will do; these ratios are that file's `stops` map, sign-for-sign — positive mixes
     *     toward white, negative toward black.
     *
     * A mixed ramp is an approximation of Tailwind's hand-tuned indigo away from the anchor (the
     * light end loses some blue chroma); deriving in HSL would keep it, and is the named follow-up.
     *
     * @var array<int, array{0: string, 1: float}>
     */
    private const RAMP = [
        50 => ['white', 0.93],
        100 => ['white', 0.86],
        200 => ['white', 0.73],
        300 => ['white', 0.55],
        400 => ['white', 0.33],
        500 => ['white', 0.15],
        600 => ['base', 0.0],
        700 => ['black', 0.15],
        800 => ['black', 0.30],
        900 => ['black', 0.39],
        950 => ['black', 0.64],
    ];

    /**
     * Apply every saved setting that belongs in the runtime configuration.
     *
     * Never throws. Safe to call more than once (the settings screen calls it again after a
     * successful write so the very next response already reflects the change).
     */
    public static function apply(): void
    {
        self::attempt(static function (): void {
            self::applyLocalization(self::group('localization'));
        });

        self::attempt(static function (): void {
            self::applyMail(self::group('mail'));
        });

        self::attempt(static function (): void {
            self::applyBranding(self::group('branding'));
        });

        self::attempt(static function (): void {
            self::applySecurity(self::group('security'));
        });

        self::attempt(static function (): void {
            self::applyOps(self::group('ops'));
        });
    }

    /**
     * phase-24-25 5.2 - the two `ops` keys that are config rather than a value somebody reads.
     *
     * `log_retention_days` reaches `logging.channels.daily.days`, which is the only place the
     * rotation actually happens: a retention setting nothing applies is a promise on a screen.
     *
     * `trusted_proxies` is here rather than in bootstrap/app.php because it is a setting, and the
     * setting is read from the database - which does not exist yet when the middleware stack is
     * being assembled. Applied at boot, before any request resolves its client address.
     *
     * **An empty proxy list is the safe value and the default.** With no trusted proxy, Laravel
     * ignores `X-Forwarded-For` entirely, so a client cannot hand itself a fresh rate-limit counter
     * or a false entry in `login_histories.ip_address` by inventing one (DEP-11). `*` is accepted
     * because behind a load balancer you control it is correct; it is never a default.
     *
     * @param  array<string, mixed>  $settings  the `ops` group, keyed relative to it
     */
    public static function applyOps(array $settings): void
    {
        $days = $settings['log_retention_days'] ?? null;

        if (is_numeric($days)) {
            // Clamped for the same reason the session lifetime is: a value written by raw SQL must
            // not be able to switch rotation off (0) or keep a year of logs on a small disk.
            Config::set('logging.channels.daily.days', max(1, min(365, (int) $days)));
        }
    }

    /**
     * `security.trusted_proxies` - applied separately because the middleware reads it directly.
     *
     * Returns the value in the shape `trustProxies()` wants: `'*'`, a list of addresses, or null
     * for "trust nothing", which is the default and the safe answer.
     *
     * @return string|list<string>|null
     */
    public static function trustedProxies(): string|array|null
    {
        try {
            $value = setting('security.trusted_proxies');
        } catch (\Throwable) {
            // Read before the database exists - during a migration, in a console command that never
            // opens a connection. Trusting nothing is the right answer when nothing is known.
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if ($value === '*') {
            return '*';
        }

        $proxies = array_values(array_filter(array_map(
            static fn (string $proxy): string => trim($proxy),
            explode(',', $value),
        ), static fn (string $proxy): bool => $proxy !== ''));

        return $proxies === [] ? null : $proxies;
    }

    /**
     * `security.session_lifetime` → `session.lifetime`, clamped to the hard server floor.
     *
     * Applied at boot, before `StartSession` reads it, so the idle timeout the Security screen shows
     * is the one sessions really have. The password length and the sign-in throttle are read where
     * they are enforced (`PasswordPolicy`, `LoginRequest`), not copied into config.
     *
     * **Clamped, not trusted.** The registry refuses a lifetime outside
     * `SettingsRegistry::SESSION_LIFETIME_MIN`..`SESSION_LIFETIME_MAX` (15 minutes to a day), and a
     * stored value outside that range — raw SQL, an older release that allowed 30 days — is pulled
     * back inside it here, exactly as `PasswordPolicy::minLength()` clamps its minimum. A setting
     * may tighten the idle timeout, never loosen it past a day. An unreadable value leaves the
     * environment's own lifetime in place.
     *
     * @param  array<string, mixed>  $settings  the `security` group, keyed relative to it
     */
    public static function applySecurity(array $settings): void
    {
        $lifetime = $settings['session_lifetime'] ?? null;

        if (! is_numeric($lifetime)) {
            return;
        }

        Config::set('session.lifetime', self::clampSessionLifetime((int) $lifetime));
    }

    /**
     * A session lifetime in minutes, inside the hard server floor.
     */
    public static function clampSessionLifetime(int $minutes): int
    {
        return max(SettingsRegistry::SESSION_LIFETIME_MIN, min(SettingsRegistry::SESSION_LIFETIME_MAX, $minutes));
    }

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    */

    /**
     * Push one mail group onto `config('mail')`.
     *
     * **The secret is applied lazily.** Everything but the SMTP password goes into the configuration
     * straight away; the password is only copied in when the mail manager is actually built (or
     * immediately, when it already has been). Booting — which is what `php artisan config:cache`
     * and `optimize` do before they `var_export` the whole configuration to
     * `bootstrap/cache/config.php` — therefore never puts the decrypted credential into a
     * configuration array that can be serialised to disk, into deploy artefacts and backups, where
     * it would outlive a rotation in the UI. No mail is ever sent without the manager being
     * resolved first, so a send always carries the saved secret.
     *
     * @param  array<string, mixed>  $settings  the `mail` group, keyed relative to it
     */
    public static function applyMail(array $settings): void
    {
        $config = self::mailConfig($settings);

        if ($config === []) {
            return;
        }

        $secrets = array_intersect_key($config, array_flip(self::MAIL_SECRET_KEYS));

        Config::set(array_diff_key($config, $secrets));

        if ($secrets === []) {
            return;
        }

        $app = app();

        if ($app->resolved('mail.manager')) {
            Config::set($secrets);

            return;
        }

        // Callbacks run in registration order, so when apply() runs twice before the first send
        // the most recently saved secret is the one left in place.
        $app->afterResolving('mail.manager', static function () use ($secrets): void {
            Config::set($secrets);
        });
    }

    /**
     * Re-read the mail group from the database and apply it, discarding any mailer instance
     * already built from the previous values.
     *
     * This is the path `TestMailService` takes: "uses the SAVED settings" has to mean the row as
     * it is right now, not a payload cached before the administrator pressed save.
     */
    public static function refreshMail(): void
    {
        self::attempt(static function (): void {
            $repository = self::repository();

            if ($repository === null) {
                return;
            }

            $repository->flush();

            $settings = $repository->all('mail');

            self::applyMail($settings);

            // A Mailer resolved earlier in this request still holds the old transport.
            $mailer = is_string($settings['mailer'] ?? null) ? trim((string) $settings['mailer']) : '';

            foreach (array_unique(array_filter([$mailer, (string) Config::get('mail.default', '')])) as $name) {
                try {
                    Mail::purge($name);
                } catch (Throwable) {
                    // Nothing resolved under that name — nothing to discard.
                }
            }
        });
    }

    /**
     * The `config()` payload a mail group translates into — pure, so it can be asserted and
     * reused without touching the container.
     *
     * `encryption` is mapped onto Symfony's transport vocabulary rather than passed through:
     * `ssl` (or port 465) means implicit TLS and therefore the `smtps` scheme, `tls` means
     * STARTTLS on a plain `smtp` connection, and `none` switches Symfony's automatic STARTTLS
     * off through the `auto_tls` DSN option — otherwise "None" would still negotiate TLS and the
     * setting would be a lie.
     *
     * @param  array<string, mixed>  $settings  the `mail` group, keyed relative to it
     * @return array<string, mixed> dotted config key => value
     */
    public static function mailConfig(array $settings): array
    {
        $mailer = self::str($settings['mailer'] ?? null);
        $host = self::str($settings['host'] ?? null);
        $port = (int) ($settings['port'] ?? 0);
        $username = self::str($settings['username'] ?? null);
        $password = self::str($settings['password'] ?? null);
        $encryption = strtolower(self::str($settings['encryption'] ?? null));
        $fromAddress = self::str($settings['from_address'] ?? null);
        $fromName = self::str($settings['from_name'] ?? null);
        $replyTo = self::str($settings['reply_to'] ?? null);

        if ($settings === []) {
            return [];
        }

        $config = [];

        if ($host !== '') {
            $config['mail.mailers.smtp.host'] = $host;
        }

        if ($port > 0 && $port <= 65535) {
            $config['mail.mailers.smtp.port'] = $port;
        }

        $config['mail.mailers.smtp.username'] = $username === '' ? null : $username;
        $config['mail.mailers.smtp.password'] = $password === '' ? null : $password;

        $implicitTls = $encryption === 'ssl' || $port === 465;

        $config['mail.mailers.smtp.scheme'] = $implicitTls ? 'smtps' : 'smtp';
        $config['mail.mailers.smtp.encryption'] = $encryption === '' || $encryption === 'none' ? null : $encryption;
        $config['mail.mailers.smtp.auto_tls'] = $encryption !== 'none';

        if ($fromAddress !== '') {
            $config['mail.from.address'] = $fromAddress;
        }

        if ($fromName !== '') {
            $config['mail.from.name'] = $fromName;
        }

        $config['mail.reply_to'] = $replyTo === ''
            ? null
            : ['address' => $replyTo, 'name' => $fromName === '' ? null : $fromName];

        if (self::isUsableMailer($mailer, $host)) {
            $config['mail.default'] = $mailer;
        }

        return $config;
    }

    /**
     * Would switching `mail.default` to this transport leave the app able to send?
     *
     * An SMTP transport with no host would fail on every send with an obscure error, so the
     * environment's own default is left in place until a host is saved.
     */
    public static function isUsableMailer(string $mailer, string $host): bool
    {
        if (! in_array($mailer, self::MAILERS, true)) {
            return false;
        }

        return $mailer !== 'smtp' || $host !== '';
    }

    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    */

    /**
     * Locale only.
     *
     * **D61 — the storage timezone is UTC and is never touched here.** `config('app.timezone')`
     * stays exactly what `config/app.php` says (`UTC`), and nothing in the application calls
     * `Config::set('app.timezone')` or `date_default_timezone_set()` at runtime. The Phase 2
     * build used to copy `localization.timezone` onto both, which made every row written after
     * it Asia/Karachi wall-clock time beside Phase 1's UTC rows, and let an administrator re-base
     * every later write by changing a dropdown. `localization.timezone` is display-only: `Format`
     * renders in it (`Format::displayTimezone()`), and `DateRange` takes input in it and queries
     * with UTC boundaries.
     *
     * @param  array<string, mixed>  $settings  the `localization` group, keyed relative to it
     */
    public static function applyLocalization(array $settings): void
    {
        $locale = self::str($settings['locale'] ?? null);

        if ($locale !== '' && preg_match('/^[A-Za-z]{2}(?:[_-][A-Za-z0-9]{2,8})?$/', $locale) === 1) {
            Config::set('app.locale', $locale);

            try {
                app()->setLocale($locale);
            } catch (Throwable) {
                // No translator bound yet (early console bootstrap): the config value stands.
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    */

    /**
     * Publish the brand colours and the derived `--brand-*` payload into the configuration, so
     * the layout's `:root` block reads one prepared array instead of deriving a second ramp of
     * its own (hard rule 9 — everyone uses the same token names and the same values).
     *
     * @param  array<string, mixed>  $settings  the `branding` group, keyed relative to it
     */
    public static function applyBranding(array $settings): void
    {
        $brand = self::str($settings['brand_color'] ?? null);
        $accent = self::str($settings['accent_color'] ?? null);

        Config::set([
            'branding.brand_color' => $brand === '' ? null : $brand,
            'branding.accent_color' => $accent === '' ? null : $accent,
            self::PALETTE_KEY => self::brandPalette($brand),
        ]);
    }

    /**
     * The eleven `--brand-*` stops for one hex colour, as "R G B" channel triplets.
     *
     *   ConfigureFromSettings::brandPalette('#4f46e5')  // [50 => '243 242 253', …, 600 => '79 70 229', …]
     *
     * The colour given becomes the **600** stop (see RAMP for why); lighter stops are mixed
     * towards white and darker ones towards black. An empty, malformed or unreadable value returns
     * the Phase 1 indigo scale unchanged, so a page rendered before the settings table exists
     * still gets indigo rather than black.
     *
     * @return array<int, string> stop => "R G B"
     */
    public static function brandPalette(?string $hex): array
    {
        $base = self::parseHex($hex);

        if ($base === null) {
            return self::FALLBACK_PALETTE;
        }

        $palette = [];

        foreach (self::RAMP as $stop => [$towards, $amount]) {
            $palette[$stop] = match ($towards) {
                'white' => self::channels(self::mix($base, [255, 255, 255], $amount)),
                'black' => self::channels(self::mix($base, [0, 0, 0], $amount)),
                default => self::channels($base),
            };
        }

        return $palette;
    }

    /**
     * The palette as ready-to-print CSS custom properties.
     *
     *   --brand-50: 238 242 255; --brand-100: …
     *
     * Kept here rather than in the view so the variable names exist in exactly one place.
     */
    public static function brandPaletteCss(?string $hex): string
    {
        $declarations = [];

        foreach (self::brandPalette($hex) as $stop => $channels) {
            $declarations[] = sprintf('--brand-%d: %s;', $stop, $channels);
        }

        return implode(' ', $declarations);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * One settings group off the cached payload, or an empty array when settings are not
     * readable yet (fresh install, missing cache store, mid-`migrate:fresh`).
     *
     * @return array<string, mixed>
     */
    private static function group(string $group): array
    {
        $repository = self::repository();

        if ($repository === null) {
            return [];
        }

        try {
            return $repository->all($group);
        } catch (Throwable) {
            return [];
        }
    }

    private static function repository(): ?SettingsRepository
    {
        try {
            $repository = app(SettingsRepository::class);

            return $repository instanceof SettingsRepository ? $repository : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run one step; swallow and report anything it throws.
     *
     * @param  callable(): void  $step
     */
    private static function attempt(callable $step): void
    {
        try {
            $step();
        } catch (Throwable $exception) {
            // Boot must survive a single unusable value. Reported so it is not invisible.
            try {
                report($exception);
            } catch (Throwable) {
                // No handler bound this early — nothing further to do.
            }
        }
    }

    private static function str(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * '#4f46e5' / '4f46e5' / '#f0f' => [r, g, b]; anything else => null.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function parseHex(?string $hex): ?array
    {
        $hex = strtolower(trim((string) $hex));
        $hex = ltrim($hex, '#');

        if (preg_match('/^[0-9a-f]{3}$/', $hex) === 1) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (preg_match('/^[0-9a-f]{6}$/', $hex) !== 1) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $from
     * @param  array{0: int, 1: int, 2: int}  $towards
     * @return array{0: int, 1: int, 2: int}
     */
    private static function mix(array $from, array $towards, float $amount): array
    {
        $amount = max(0.0, min(1.0, $amount));

        return [
            (int) round($from[0] + (($towards[0] - $from[0]) * $amount)),
            (int) round($from[1] + (($towards[1] - $from[1]) * $amount)),
            (int) round($from[2] + (($towards[2] - $from[2]) * $amount)),
        ];
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private static function channels(array $rgb): string
    {
        return sprintf(
            '%d %d %d',
            max(0, min(255, $rgb[0])),
            max(0, min(255, $rgb[1])),
            max(0, min(255, $rgb[2])),
        );
    }
}
