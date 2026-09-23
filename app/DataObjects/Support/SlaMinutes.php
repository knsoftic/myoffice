<?php

declare(strict_types=1);

namespace App\DataObjects\Support;

/**
 * How long a ticket has, in whole minutes (phase-19-23 §6.16).
 *
 * **A null pair is the SLA being switched off**, not a ticket with no target — the distinction the
 * whole feature turns on. `support.sla_enabled` false produces `none()`, and every caller reads
 * `applies()` rather than testing the two integers, so nothing has to remember that null means
 * "don't stamp a clock" rather than "stamp zero".
 *
 * Minutes, never money: these are multiplied by a priority factor and rounded half-up to a whole
 * minute, which is `round()` on an integer count — `App\Support\Money` is for currency and has no
 * business here (CLAUDE.md §1.4 governs the other direction, and this is the note that says why it
 * does not apply).
 */
final readonly class SlaMinutes
{
    private function __construct(
        public ?int $firstResponse,
        public ?int $resolution,
    ) {}

    public static function of(int $firstResponse, int $resolution): self
    {
        // Floored at one: a target of zero minutes is not a demanding target, it is a breach on
        // arrival, and `chk_td_sla` refuses one at the database for the same reason.
        return new self(max(1, $firstResponse), max(1, $resolution));
    }

    /** The SLA is switched off. No clock is stamped and nothing is measured. */
    public static function none(): self
    {
        return new self(null, null);
    }

    public function applies(): bool
    {
        return $this->firstResponse !== null && $this->resolution !== null;
    }
}
