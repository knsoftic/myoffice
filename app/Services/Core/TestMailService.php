<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\User;
use App\Support\ConfigureFromSettings;
use App\Support\SettingsRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Mail\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * "Send test email" for the mail settings group (phase-02 §3, §5).
 *
 * Three properties make this worth a service of its own:
 *
 *  1. **It proves the SAVED settings, not `.env`.** The mail group is re-read from the database
 *     (cache flushed first), mapped onto `config('mail')` through the one mapping
 *     `ConfigureFromSettings::mailConfig()` that a real mail also goes through, and any mailer
 *     instance already built in this request is discarded. If the saved transport is SMTP with no
 *     host the attempt is refused outright rather than silently falling back to the environment's
 *     transport and reporting a success that proves nothing.
 *  2. **It never exposes the password.** Symfony puts the transport DSN — credentials included —
 *     into its exception messages. Every string that leaves here passes through `redact()`, which
 *     removes the saved password in plain and URL-encoded form. The throwable itself is never
 *     returned (see `TestMailResult`): it is report()ed, and the result carries one short sentence
 *     with no class name and no stack.
 *  3. **It is rate limited to three per minute per user**, in the service as well as in the route's
 *     `throttle:3,1`: the route protects the endpoint, this protects the capability — an SMTP probe
 *     loop is a port scanner with our server's IP on it.
 *  4. **It is not an SSRF primitive.** An SMTP test connects only to a mail port
 *     (ALLOWED_PORTS) and never to a loopback, link-local or cloud-metadata address, judged on every
 *     address the host resolves to (see REFUSED_RANGES for why private relays stay allowed). The
 *     transport's reply is flattened to one line and cut short before it is shown.
 */
final class TestMailService
{
    /** Attempts allowed inside one window, per user. */
    public const MAX_ATTEMPTS = 3;

    /** Length of that window, in seconds. */
    public const DECAY_SECONDS = 60;

    /** Rate-limiter key prefix. */
    public const RATE_LIMIT_KEY = 'settings:mail-test';

    /**
     * The only ports a test email connects to: SMTP relay (25), implicit TLS submission (465),
     * STARTTLS submission (587) and the de-facto alternative submission port (2525).
     *
     * Anything else turns "send a test" into a TCP probe of an arbitrary service whose first reply
     * line (an SSH, Redis or HTTP banner) came straight back in the failure message.
     *
     * @var list<int>
     */
    public const ALLOWED_PORTS = [25, 465, 587, 2525];

    /**
     * Cloud instance-metadata endpoints, refused by address whatever range they sit in.
     * 169.254.169.254 is AWS / GCP / Azure / OpenStack (also covered by the link-local range below),
     * fd00:ec2::254 is AWS's IPv6 endpoint and 100.100.100.200 is Alibaba Cloud's.
     *
     * @var list<string>
     */
    public const METADATA_ADDRESSES = ['169.254.169.254', 'fd00:ec2::254', '100.100.100.200'];

    /**
     * Address ranges a test email never connects to: [network, prefix length].
     *
     * Deliberately NOT here: the private RFC 1918 ranges (10/8, 172.16/12, 192.168/16) and IPv6
     * unique-local addresses. A company's own mail relay — an on-premises Exchange, a Postfix
     * smarthost, a relay inside the same VPC — very often has exactly such an address, and the test
     * exists to prove that relay works; refusing private space would make the feature useless for
     * the installs most likely to need it. The exposure that remains is bounded: only a Super
     * Admin holds `settings.edit_mail`, only the four mail ports above are reachable, loopback,
     * link-local and metadata addresses (the classic SSRF targets on the server itself) are refused,
     * and the endpoint is throttled to three attempts a minute.
     *
     * @var list<array{0: string, 1: int}>
     */
    private const REFUSED_RANGES = [
        ['0.0.0.0', 8],          // "this host" — 0.0.0.0 connects to the local machine
        ['127.0.0.0', 8],        // IPv4 loopback
        ['169.254.0.0', 16],     // IPv4 link-local, incl. 169.254.169.254
        ['255.255.255.255', 32], // limited broadcast
        ['::', 128],             // IPv6 unspecified
        ['::1', 128],            // IPv6 loopback
        ['fe80::', 10],          // IPv6 link-local
    ];

    /** Longest transport reply handed back to the screen; the rest is noise or a payload. */
    private const MAX_DETAIL_LENGTH = 300;

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * Send one test message to `$recipient`.
     *
     * Never throws: every outcome — invalid address, throttled, unusable configuration, transport
     * failure, success — comes back as a `TestMailResult`.
     */
    public function send(string $recipient, ?Authenticatable $actor = null): TestMailResult
    {
        $recipient = trim($recipient);

        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return TestMailResult::failed('Enter a valid email address to send the test to.');
        }

        $actor = $actor ?? Auth::user();

        $limiter = $this->limiterKey($actor);

        if (RateLimiter::tooManyAttempts($limiter, self::MAX_ATTEMPTS)) {
            return TestMailResult::throttled(RateLimiter::availableIn($limiter));
        }

        RateLimiter::hit($limiter, self::DECAY_SECONDS);

        // The saved settings become the live configuration before anything is sent.
        ConfigureFromSettings::refreshMail();

        $mail = $this->mailSettings();

        $mailer = $this->string($mail['mailer'] ?? null);
        $host = $this->string($mail['host'] ?? null);
        $password = $this->string($mail['password'] ?? null);

        if (! ConfigureFromSettings::isUsableMailer($mailer, $host)) {
            return TestMailResult::failed($this->unusableMessage($mailer, $host));
        }

        if ($mailer === 'smtp') {
            $refusal = $this->smtpEndpointRefusal($host, $this->effectivePort());

            if ($refusal !== null) {
                return TestMailResult::failed($refusal);
            }
        }

        try {
            Mail::mailer($mailer)->raw(
                $this->body($recipient, $actor),
                function (Message $message) use ($recipient): void {
                    $message->to($recipient)->subject($this->subject());
                },
            );
        } catch (Throwable $exception) {
            // Phase 2 review low 3: the full exception goes to the log only; the screen and the JSON
            // get one short, redacted sentence — no class name, no stack, no second copy.
            report($exception);

            return TestMailResult::failed($this->failureMessage($exception, $password));
        }

        return TestMailResult::success($this->successMessage($mailer, $host, $recipient, $mail));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The mail group as it is stored right now.
     *
     * @return array<string, mixed>
     */
    private function mailSettings(): array
    {
        try {
            return $this->settings->all('mail');
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * One bucket per user; per IP when there is no user (there always is on the admin route, but a
     * console or queued caller must still be bounded).
     */
    private function limiterKey(?Authenticatable $actor): string
    {
        if ($actor instanceof User) {
            return self::RATE_LIMIT_KEY.':user:'.$actor->getKey();
        }

        try {
            $ip = (string) request()->ip();
        } catch (Throwable) {
            $ip = '';
        }

        return self::RATE_LIMIT_KEY.':ip:'.($ip === '' ? 'console' : $ip);
    }

    private function subject(): string
    {
        return sprintf('%s — test email', $this->companyName());
    }

    private function body(string $recipient, ?Authenticatable $actor): string
    {
        $by = $actor instanceof User ? (string) $actor->name : 'the console';

        return implode("\n", [
            sprintf('This is a test message from %s.', $this->companyName()),
            '',
            'If you are reading it, the saved mail settings work: the transport accepted the',
            'message and delivered it to this address.',
            '',
            sprintf('Requested by: %s', $by),
            sprintf('Sent to:      %s', $recipient),
            sprintf('Sent at:      %s', Carbon::now()->toDayDateTimeString()),
        ]);
    }

    private function companyName(): string
    {
        try {
            $name = $this->settings->get('company.name', config('app.name', 'MyOffice ERP'));
        } catch (Throwable) {
            $name = 'MyOffice ERP';
        }

        return is_string($name) && trim($name) !== '' ? trim($name) : 'MyOffice ERP';
    }

    private function unusableMessage(string $mailer, string $host): string
    {
        if ($mailer === 'smtp' && $host === '') {
            return 'Save an SMTP host before sending a test — without one the test would fall back to the environment file and prove nothing.';
        }

        if ($mailer === '') {
            return 'No mail transport is saved yet. Choose one and save the group first.';
        }

        return sprintf('"%s" is not a transport this system can send through.', $mailer);
    }

    /**
     * @param  array<string, mixed>  $mail
     */
    private function successMessage(string $mailer, string $host, string $recipient, array $mail): string
    {
        if ($mailer === 'log') {
            return sprintf(
                'The transport is set to "log", so nothing left the server: the test message for %s was written to the application log instead.',
                $recipient,
            );
        }

        if ($mailer === 'array') {
            return sprintf(
                'The transport is set to "array" (discard), so the test message for %s was accepted and thrown away.',
                $recipient,
            );
        }

        if ($mailer === 'sendmail') {
            return sprintf('The local sendmail transport accepted the test message for %s.', $recipient);
        }

        $port = (int) ($mail['port'] ?? 0);

        return sprintf(
            'Test email sent to %s through %s%s.',
            $recipient,
            $host,
            $port > 0 ? ':'.$port : '',
        );
    }

    /**
     * A sentence an administrator can act on, with the transport's own words kept — on one line,
     * without control characters, and cut to MAX_DETAIL_LENGTH so a hostile server cannot hand a
     * page of its own text back through the screen.
     *
     * Phase 2 review low 3: never the exception's class name (an empty message gets a plain
     * sentence instead), and the password is redacted BEFORE the text is cut, so a cut that lands
     * inside the password cannot leave a prefix of it behind for redact() to miss.
     */
    private function failureMessage(Throwable $exception, string $password): string
    {
        $detail = $this->redact($exception->getMessage(), $password);
        $detail = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $detail));
        $detail = $this->redact($detail, $password);

        if ($detail === '') {
            return 'The test email could not be sent, and the transport gave no reason. The full error has been logged.';
        }

        if (mb_strlen($detail) > self::MAX_DETAIL_LENGTH) {
            $detail = mb_substr($detail, 0, self::MAX_DETAIL_LENGTH).'…';
        }

        return 'The test email could not be sent: '.$detail;
    }

    /*
    |--------------------------------------------------------------------------
    | SSRF guard
    |--------------------------------------------------------------------------
    */

    /**
     * The port the SMTP transport will really dial: the configuration refreshMail() just applied,
     * or Symfony's own default when none is set (465 for implicit TLS, 25 otherwise).
     */
    private function effectivePort(): int
    {
        $port = (int) config('mail.mailers.smtp.port', 0);

        if ($port > 0) {
            return $port;
        }

        return config('mail.mailers.smtp.scheme') === 'smtps' ? 465 : 25;
    }

    /**
     * Why this SMTP endpoint is refused, or null when the test may connect to it.
     *
     * Every address the host resolves to is checked (A and AAAA), so a DNS name pointing at
     * 127.0.0.1, a numeric shorthand (`127.1`, `2130706433`) or an IPv4-mapped IPv6 literal cannot
     * slip past a check of the text alone. A name that resolves to nothing is refused too: the
     * transport could not connect to it anyway, and a name that fails now but resolves on the next
     * lookup is the start of a rebinding trick.
     *
     * Residual risk, accepted and recorded: Symfony resolves the name again when it connects, so a
     * DNS server answering differently between the two lookups could still steer the connection.
     * The port allow-list and the Super-Admin-only permission bound what that could reach.
     */
    private function smtpEndpointRefusal(string $host, int $port): ?string
    {
        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            return sprintf(
                'The test email only connects to a mail port (%s); the saved port %d is refused.',
                implode(', ', self::ALLOWED_PORTS),
                $port,
            );
        }

        $name = strtolower(trim($host, " \t\n\r\0\x0B[]"));
        $refused = sprintf(
            'The SMTP host "%s" points at a loopback, link-local or cloud-metadata address, which the test email never connects to.',
            $host,
        );

        if ($name === '' || $name === 'localhost' || str_ends_with($name, '.localhost')) {
            return $refused;
        }

        $addresses = $this->resolve($name);

        if ($addresses === []) {
            return sprintf('The SMTP host "%s" does not resolve to an address.', $host);
        }

        foreach ($addresses as $address) {
            if ($this->isRefusedAddress($address)) {
                return $refused;
            }
        }

        return null;
    }

    /**
     * Every IPv4 and IPv6 address a host name (or IP literal) stands for.
     *
     * @return list<string>
     */
    private function resolve(string $name): array
    {
        if (filter_var($name, FILTER_VALIDATE_IP) !== false) {
            return [$name];
        }

        if (filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return [];
        }

        $addresses = [];

        try {
            // gethostbynamel() goes through the system resolver, which also reads numeric
            // shorthands like `127.1` the way the socket layer will.
            foreach ((array) (@gethostbynamel($name) ?: []) as $ipv4) {
                $addresses[] = (string) $ipv4;
            }

            foreach ((array) (@dns_get_record($name, DNS_AAAA) ?: []) as $record) {
                if (is_array($record) && isset($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
        } catch (Throwable) {
            // A resolver failure is "no address", which is refused above.
        }

        return array_values(array_unique(array_filter(
            $addresses,
            static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false,
        )));
    }

    private function isRefusedAddress(string $address): bool
    {
        $binary = @inet_pton($address);

        if ($binary === false) {
            return true;
        }

        // IPv4-mapped (::ffff:a.b.c.d), IPv4-compatible (::a.b.c.d) and NAT64 (64:ff9b::a.b.c.d)
        // IPv6 addresses reach the embedded IPv4 host: judge that host.
        if (strlen($binary) === 16) {
            $embedded = substr($binary, 12, 4);
            $prefix = substr($binary, 0, 12);

            if (
                $prefix === str_repeat("\0", 10)."\xff\xff"
                || ($prefix === str_repeat("\0", 12) && $embedded !== "\0\0\0\0" && $embedded !== "\0\0\0\1")
                || $prefix === "\0\x64\xff\x9b".str_repeat("\0", 8)
            ) {
                return $this->isRefusedAddress((string) inet_ntop($embedded));
            }
        }

        foreach (self::METADATA_ADDRESSES as $metadata) {
            if (@inet_pton($metadata) === $binary) {
                return true;
            }
        }

        foreach (self::REFUSED_RANGES as [$network, $bits]) {
            $networkBinary = @inet_pton($network);

            if ($networkBinary !== false && strlen($networkBinary) === strlen($binary) && $this->inRange($binary, $networkBinary, $bits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Do the first `$bits` bits of two packed addresses of the same family agree?
     */
    private function inRange(string $address, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /**
     * Remove the saved SMTP password from any string on its way out.
     */
    private function redact(string $text, string $password): string
    {
        if ($password === '') {
            return $text;
        }

        $needles = array_unique(array_filter([
            $password,
            rawurlencode($password),
            urlencode($password),
        ]));

        foreach ($needles as $needle) {
            $text = str_replace($needle, SettingsService::REDACTED, $text);
        }

        return $text;
    }

    private function string(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
