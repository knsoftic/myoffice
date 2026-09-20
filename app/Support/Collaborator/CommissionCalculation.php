<?php

declare(strict_types=1);

namespace App\Support\Collaborator;

use App\Enums\CommissionSkipReason;

/**
 * The result of spine §6.1.6 - §6.1.7: how much this payment releases, or why it releases nothing.
 *
 * A value rather than a tuple because it is produced once and read in three places — the engine that
 * posts the row, the preview the record-payment wizard shows before anybody commits, and the skip
 * report. All three have to agree to the paisa, and the way to guarantee that is for all three to be
 * looking at the same object.
 */
final readonly class CommissionCalculation
{
    /**
     * @param  array<string, mixed>  $trace  the permanent calculation record of spine §6.1.9
     */
    private function __construct(
        public string $baseAmount,
        public string $release,
        public string $branch,
        public array $trace,
        public ?CommissionSkipReason $skip = null,
        public ?string $detail = null,
        public ?string $step = null,
    ) {}

    /**
     * @param  array<string, mixed>  $trace
     */
    public static function releases(string $baseAmount, string $release, string $branch, array $trace): self
    {
        return new self($baseAmount, $release, $branch, $trace);
    }

    /**
     * @param  array<string, mixed>  $trace
     */
    public static function skips(
        CommissionSkipReason $reason,
        string $detail,
        string $step,
        string $branch,
        array $trace = [],
    ): self {
        return new self('0.00', '0.00', $branch, $trace, $reason, $detail, $step);
    }

    public function earns(): bool
    {
        return $this->skip === null;
    }
}
