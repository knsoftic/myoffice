<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use Carbon\CarbonInterface;

/**
 * What a bank actually did (spine §6.4.4).
 *
 * `transactionId` is the bank's reference, and `uq_cp_txn(method, transaction_id)` makes one real
 * transfer impossible to record twice — which matters more than it sounds, because the second record
 * would move a second set of entries from `available` to `paid` and the money for them never left.
 *
 * `paidOn` is the **business** date the transfer settled, kept separately from the moment somebody
 * typed it in: a Friday transfer entered on Monday belongs to Friday in every report.
 */
final readonly class MarkPaidData
{
    public function __construct(
        public ?string $transactionId = null,
        public ?CarbonInterface $paidOn = null,
        public ?string $receiptPath = null,
        public ?string $notes = null,
    ) {}
}
