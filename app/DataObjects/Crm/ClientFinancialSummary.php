<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\Support\Money;

/**
 * Invoiced / paid / outstanding / overdue for one client (phase-05 §6.7 `financialSummary()`, test 59).
 *
 * Never a zero pretending to be a fact:
 *   · `unavailable()` — no owning phase's read model is bound yet; every figure is null and renders "-";
 *   · `withheld()` — the caller lacks `clients.view_financial`; every figure is null and is absent from the
 *     response body, not merely hidden;
 *   · otherwise each figure is a decimal(15,2) string when a provider answered for it, else null.
 */
final readonly class ClientFinancialSummary
{
    public const STATE_AVAILABLE = 'available';

    public const STATE_UNAVAILABLE = 'unavailable';

    public const STATE_WITHHELD = 'withheld';

    public function __construct(
        public string $state,
        public ?string $invoiced = null,
        public ?string $paid = null,
        public ?string $outstanding = null,
        public ?string $overdue = null,
    ) {}

    public static function unavailable(): self
    {
        return new self(self::STATE_UNAVAILABLE);
    }

    public static function withheld(): self
    {
        return new self(self::STATE_WITHHELD);
    }

    /**
     * @param  array{invoiced?: string, paid?: string, outstanding?: string, overdue?: string}  $figures
     */
    public static function fromFigures(array $figures): self
    {
        $read = static fn (string $key): ?string => isset($figures[$key]) ? Money::of((string) $figures[$key]) : null;

        return new self(
            self::STATE_AVAILABLE,
            invoiced: $read('invoiced'),
            paid: $read('paid'),
            outstanding: $read('outstanding'),
            overdue: $read('overdue'),
        );
    }

    public function isAvailable(): bool
    {
        return $this->state === self::STATE_AVAILABLE;
    }

    public function isWithheld(): bool
    {
        return $this->state === self::STATE_WITHHELD;
    }

    /**
     * @return array{state: string, invoiced: string|null, paid: string|null, outstanding: string|null, overdue: string|null}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'invoiced' => $this->invoiced,
            'paid' => $this->paid,
            'outstanding' => $this->outstanding,
            'overdue' => $this->overdue,
        ];
    }
}
