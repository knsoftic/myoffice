<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

/**
 * One of the eight checks of spine §6.5.3, and what it found.
 *
 * The severity is the check's own, decided when it was written and never by the caller: R1 and R8 are
 * **drift** (the cache fell behind something that is still correct underneath), R2–R7 are **failed**
 * (the ledger itself does not hold together). That distinction is the whole of §6.5.4 — drift may be
 * repaired by recomputing the cache, failure may not be repaired at all, because the only honest fix
 * for a broken ledger is a human posting an adjustment with a reason.
 */
final readonly class ReconciliationFinding
{
    /**
     * @param  'drift'|'failed'  $severity
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        public string $check,
        public string $severity,
        public string $message,
        public array $details = [],
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public static function drift(string $check, string $message, array $details = []): self
    {
        return new self($check, 'drift', $message, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function failed(string $check, string $message, array $details = []): self
    {
        return new self($check, 'failed', $message, $details);
    }

    public function isStructural(): bool
    {
        return $this->severity === 'failed';
    }

    public function line(): string
    {
        return $this->check.': '.$this->message;
    }
}
