<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * A log tap that keeps secrets and personal data out of the log files (phase-24-25 §6.3).
 *
 * **A log file is the place data ends up when nobody was deciding.** Every other store in this
 * system has a contract — a column type, a policy, a visibility flag. A log line has none: whatever
 * was in the exception context at the moment something went wrong is written verbatim, and then
 * kept for `ops.log_retention_days`, copied into every backup, and read by whoever is debugging.
 * A failed SMTP connection logs the credentials it tried. A failed payout logs the account details.
 * Neither is a bug in the code that logged it; both are the reason this exists.
 *
 * Three rules, applied to every channel:
 *
 *   · **any context key that looks like a secret** has its value replaced. Matched on the key,
 *     recursively, at any depth — a `config` array nested four levels down still has a `password`
 *     in it;
 *
 *   · **any value over 2 KB is truncated.** A 40 MB request body in a context array turns a log
 *     file into a disk-space incident, and nobody reads past the first screen of it anyway;
 *
 *   · **anything shaped like a card number or a full CNIC is stripped from the message itself**,
 *     which is the part no key-based rule can reach. The last four digits of a card and the last
 *     four of a CNIC are left: enough to recognise which one it was, not enough to be it.
 *
 * The redaction is deliberately blunt. A key called `key_performance_indicator` matches `key` and
 * gets redacted, and that is the right trade: a false positive costs a debugging session, a false
 * negative writes a credential to disk.
 */
final class RedactSensitive
{
    /**
     * The replacement. Distinct from the `[withheld]` the audit trail uses, so a reader can tell
     * which layer hid the value.
     */
    public const REDACTED = '[redacted]';

    /**
     * Context keys whose value is never written.
     */
    public const KEY_PATTERN = '/pass|secret|token|key|authorization|cookie|account_details|archive_password|dsn|cvv|cnic/i';

    /**
     * Bytes of any single value that survive.
     */
    public const MAX_VALUE_BYTES = 2048;

    /**
     * How deep the walk goes before it stops.
     *
     * A context array with a circular reference or a deeply nested object graph must not be able to
     * hang the logger — which would turn a logged warning into an outage.
     */
    private const MAX_DEPTH = 8;

    /**
     * 13-19 digits, optionally in groups: a payment card number.
     *
     * Bounded by non-digits on both sides so a 20-digit identifier is not mistaken for one.
     */
    private const PAN_PATTERN = '/(?<![\d-])(?:\d[ -]?){12,18}\d(?![\d-])/';

    /**
     * A Pakistani CNIC, with or without dashes: 5 digits, 7 digits, 1 digit.
     */
    private const CNIC_PATTERN = '/(?<!\d)(\d{5})-?(\d{7})-?(\d)(?!\d)/';

    /**
     * Laravel calls this on the configured channel: `'tap' => [RedactSensitive::class]`.
     */
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            if ($handler instanceof ProcessorInterface || method_exists($handler, 'pushProcessor')) {
                $handler->pushProcessor($this->processor());
            }
        }
    }

    /**
     * The processor, exposed so a test can run it without building a logger.
     *
     * @return callable(LogRecord): LogRecord
     */
    public function processor(): callable
    {
        return function (LogRecord $record): LogRecord {
            return $record->with(
                message: $this->scrubMessage($record->message),
                context: $this->scrub($record->context),
                extra: $this->scrub($record->extra),
            );
        };
    }

    /**
     * Walk an array, redacting by key and truncating by size.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function scrub(array $values, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['...' => 'truncated: too deeply nested to log'];
        }

        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::KEY_PATTERN, $key) === 1) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = $this->scrubValue($value, $depth);
        }

        return $clean;
    }

    /**
     * One value: recursed if it is an array, scrubbed and clamped if it is a string, left alone
     * otherwise.
     */
    private function scrubValue(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->scrub($value, $depth + 1);
        }

        if (! is_string($value)) {
            return $value;
        }

        return $this->clamp($this->scrubMessage($value));
    }

    /**
     * Strip anything that looks like a card number or a full CNIC from free text.
     *
     * The PAN check runs Luhn before redacting: a 16-digit order reference is not a card number,
     * and redacting one would make the log useless for the case it was written for. A CNIC has no
     * checksum, so the pattern alone decides — and a 13-digit number that is not a CNIC is not
     * something worth keeping in a log either.
     */
    public function scrubMessage(string $message): string
    {
        $message = (string) preg_replace_callback(
            self::PAN_PATTERN,
            static function (array $matches): string {
                $digits = preg_replace('/\D/', '', $matches[0]) ?? '';

                if (! self::passesLuhn($digits)) {
                    return $matches[0];
                }

                return str_repeat('*', max(0, mb_strlen($digits) - 4)).mb_substr($digits, -4);
            },
            $message,
        );

        return (string) preg_replace(self::CNIC_PATTERN, '*****-*******-$3', $message);
    }

    /**
     * The check digit every payment card carries.
     */
    private static function passesLuhn(string $digits): bool
    {
        $length = mb_strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    /**
     * Clamp a string to {@see self::MAX_VALUE_BYTES}, saying that it was clamped.
     *
     * `mb_strcut` rather than `mb_substr`: the limit is bytes, and cutting a multi-byte character
     * in half would write an invalid sequence into the log.
     */
    private function clamp(string $value): string
    {
        if (strlen($value) <= self::MAX_VALUE_BYTES) {
            return $value;
        }

        return mb_strcut($value, 0, self::MAX_VALUE_BYTES).sprintf(
            '… [truncated, %d bytes]',
            strlen($value),
        );
    }
}
