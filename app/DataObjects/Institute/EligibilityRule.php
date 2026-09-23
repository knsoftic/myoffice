<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

/**
 * One rule's verdict, with the numbers that produced it (phase-19-23 §6.14).
 *
 * **`required` and `actual` are strings, and both are optional.** Some rules are comparisons — "75.00
 * needed, 68.50 actual" — and some are facts with no threshold at all: a course either offers a
 * certificate or it does not. Forcing every rule into a numeric shape would mean inventing a `1` and
 * a `0` for the second kind, which is how a screen ends up saying "certificate_available: required 1,
 * actual 0" at somebody.
 *
 * **`message` is written for the person reading it, not for a log.** It names the thing and the
 * number, because whoever is looking at it is mid-task and needs to know what to do next — the same
 * discipline every refusal in this system follows.
 */
final readonly class EligibilityRule
{
    public function __construct(
        public string $key,
        public bool $passed,
        public string $message,
        public ?string $required = null,
        public ?string $actual = null,
        /** True when the rule was switched off in settings and therefore not really tested. */
        public bool $skipped = false,
    ) {}

    /** A rule that was checked and passed. */
    public static function pass(string $key, string $message, ?string $required = null, ?string $actual = null): self
    {
        return new self($key, true, $message, $required, $actual);
    }

    /** A rule that was checked and failed. */
    public static function fail(string $key, string $message, ?string $required = null, ?string $actual = null): self
    {
        return new self($key, false, $message, $required, $actual);
    }

    /**
     * A rule the institute has switched off.
     *
     * **It counts as passed**, because the institute decided it does not apply — but it is marked
     * `skipped` so the snapshot records that it was not really tested. A year later, "attendance
     * passed" and "attendance was not being checked" are very different answers to why a certificate
     * was issued, and a boolean cannot tell them apart.
     */
    public static function skip(string $key, string $message): self
    {
        return new self($key, true, $message, skipped: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'passed' => $this->passed,
            'skipped' => $this->skipped,
            'required' => $this->required,
            'actual' => $this->actual,
            'message' => $this->message,
        ];
    }
}
