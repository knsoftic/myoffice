<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Enums\InvoiceStatus;
use App\Models\Finance\Invoice;
use App\Services\Finance\InvoiceService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `invoices:mark-overdue` — let the clock move a status (phase-13 §2.8, §10.5).
 *
 * **It computes nothing of its own.** `InvoiceService::recomputeStatus()` owns what an invoice's status
 * is, and this command calls it for every live claim. A command with its own "is it past due" test
 * would be a second opinion, and the two would eventually disagree on the day a due date was extended.
 *
 * It runs over `sent`, `partial` **and** `overdue` for exactly that reason: the same call that moves a
 * row *into* `overdue` moves one back *out* when somebody extends the due date. A job that only marked
 * things overdue would leave a corrected invoice wearing a red badge until the next payment touched it.
 *
 * **Nothing here touches money.** No payment, no reversal, no ledger row and no wallet: the three
 * amount columns are recomputed only by the events that actually move cash.
 */
#[AsCommand(name: 'invoices:mark-overdue')]
final class MarkInvoicesOverdue extends Command
{
    protected $signature = 'invoices:mark-overdue {--chunk=500 : How many invoices to load at a time}';

    protected $description = 'Recompute the status of every live invoice so the clock can move it into — or out of — overdue';

    public function handle(InvoiceService $invoices): int
    {
        $chunk = max(50, min(2000, (int) $this->option('chunk')));

        $moved = 0;
        $into = 0;
        $outOf = 0;
        $seen = 0;

        Invoice::query()
            ->whereIn('status', [
                InvoiceStatus::Sent->value,
                InvoiceStatus::Partial->value,
                InvoiceStatus::Overdue->value,
            ])
            ->orderBy('id')
            ->chunkById($chunk, function ($batch) use ($invoices, &$moved, &$into, &$outOf, &$seen): void {
                foreach ($batch as $invoice) {
                    $seen++;
                    $before = $invoice->status;

                    $after = $invoices->recomputeStatus($invoice)->status;

                    if ($after === $before) {
                        continue;
                    }

                    $moved++;

                    if ($after === InvoiceStatus::Overdue) {
                        $into++;
                    } elseif ($before === InvoiceStatus::Overdue) {
                        $outOf++;
                    }
                }
            });

        $this->info(sprintf(
            '%d invoice(s) checked · %d moved (%d into overdue, %d back out)',
            $seen,
            $moved,
            $into,
            $outOf,
        ));

        return self::SUCCESS;
    }
}
