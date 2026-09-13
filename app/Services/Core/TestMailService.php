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
 *     returned (see `TestMailResult`).
 *  3. **It is rate limited to three per minute per user**, in the service as well as in the route's
 *     `throttle:3,1`: the route protects the endpoint, this protects the capability — an SMTP probe
 *     loop is a port scanner with our server's IP on it.
 */
final class TestMailService
{
    /** Attempts allowed inside one window, per user. */
    public const MAX_ATTEMPTS = 3;

    /** Length of that window, in seconds. */
    public const DECAY_SECONDS = 60;

    /** Rate-limiter key prefix. */
    public const RATE_LIMIT_KEY = 'settings:mail-test';

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

        try {
            Mail::mailer($mailer)->raw(
                $this->body($recipient, $actor),
                function (Message $message) use ($recipient): void {
                    $message->to($recipient)->subject($this->subject());
                },
            );
        } catch (Throwable $exception) {
            return TestMailResult::failed(
                $this->redact($this->failureMessage($exception), $password),
                $this->redact(get_class($exception).': '.$exception->getMessage(), $password),
            );
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
     * A sentence an administrator can act on, with the transport's own words kept.
     */
    private function failureMessage(Throwable $exception): string
    {
        $detail = trim($exception->getMessage());

        if ($detail === '') {
            $detail = get_class($exception);
        }

        return 'The test email could not be sent: '.$detail;
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
