<?php

declare(strict_types=1);

namespace App\Services\Core;

/**
 * The outcome of a "send test email" attempt (phase-02 §3).
 *
 * Deliberately a value object and not an exception: a failed test mail is the normal, expected
 * answer to "are these SMTP settings right?", and the administrator needs to read the real reason
 * — a refused connection, a rejected login, an unknown host — not a stack trace.
 *
 * `exception` is a **string**, never the throwable: a Symfony transport exception carries the
 * transport DSN through its message and its trace, and the SMTP password with it. `TestMailService`
 * redacts the saved password out of both before they ever reach this object, so nothing that is
 * handed to a view or flashed into a session can leak it.
 */
final readonly class TestMailResult
{
    /**
     * @param  bool  $ok  did the message reach the transport?
     * @param  string  $message  one sentence for the administrator, safe to render
     * @param  string|null  $exception  "Class: redacted message", or null when nothing threw
     */
    private function __construct(
        public bool $ok,
        public string $message,
        public ?string $exception = null,
    ) {}

    /**
     * Named `success()`, not `ok()`: `ok` is a public property, and a consumer that probes the
     * result with `method_exists($result, 'ok')` before reading `$result->ok` would otherwise call
     * this factory on an instance and blow up on the missing argument.
     */
    public static function success(string $message): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message, ?string $exception = null): self
    {
        return new self(false, $message, $exception);
    }

    /**
     * Refused by the rate limiter — three attempts per minute per user (phase-02 §3).
     */
    public static function throttled(int $seconds): self
    {
        return self::failed(sprintf(
            'Too many test emails. Try again in %d second%s.',
            max(1, $seconds),
            $seconds === 1 ? '' : 's',
        ));
    }

    /**
     * Payload for `session()->flash('toast', …)` (phase-01 §9).
     *
     * @return array{type: string, message: string}
     */
    public function toToast(): array
    {
        return [
            'type' => $this->ok ? 'success' : 'error',
            'message' => $this->message,
        ];
    }

    /**
     * @return array{ok: bool, message: string, exception: string|null}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'exception' => $this->exception,
        ];
    }
}
