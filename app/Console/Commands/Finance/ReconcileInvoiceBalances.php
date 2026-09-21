<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\ReceivedPaymentStatus;
use App\Models\Finance\Invoice;
use App\Services\Finance\InvoiceService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `invoices:reconcile-balances` — assert the three cached columns against the canonical SQL
 * (phase-13 §2.8, §10.5, D40).
 *
 * `paid_amount`, `refunded_amount` and `balance_amount` are a **cache** of one query over
 * `project_payments`. A cache is only trustworthy while something checks it, and the failure mode here
 * is silent: nothing raises when a column drifts, the register simply starts showing a figure the
 * receipts do not support, and the first symptom is a client disputing a statement.
 *
 * **It reports; it does not silently repair** (the spine §6.5.4 discipline). Drift is evidence of
 * something that went wrong upstream, and quietly rewriting the column erases the evidence while
 * leaving the cause in place. `--repair` exists for when somebody has decided to fix it, and it prints
 * every before-and-after so the correction is itself a record.
 */
#[AsCommand(name: 'invoices:reconcile-balances')]
final class ReconcileInvoiceBalances extends Command
{
    protected $signature = 'invoices:reconcile-balances
        {--repair : Rewrite the cached columns from the canonical SQL, printing before and after}
        {--chunk=500 : How many invoices to load at a time}
        {--quiet-when-ok : Say nothing when every invoice agrees}';

    protected $description = 'Assert every non-draft invoice\'s paid, refunded and balance columns against the receipts behind them';

    public function handle(DatabaseManager $db, InvoiceService $invoices): int
    {
        $chunk = max(50, min(2000, (int) $this->option('chunk')));
        $repair = (bool) $this->option('repair');

        $checked = 0;
        $drifted = [];

        Invoice::query()
            // A draft has no receipts by construction, so it has nothing to disagree with.
            ->whereNot('status', InvoiceStatus::Draft->value)
            ->orderBy('id')
            ->chunkById($chunk, function ($batch) use ($db, $invoices, $repair, &$checked, &$drifted): void {
                foreach ($batch as $invoice) {
                    $checked++;

                    $row = $db->table('project_payments')
                        ->where('invoice_id', $invoice->getKey())
                        ->whereNot('status', ReceivedPaymentStatus::Voided->value)
                        ->selectRaw('COALESCE(SUM(net_received_amount), 0) as paid')
                        ->selectRaw('COALESCE(SUM(refunded_amount), 0) as refunded')
                        ->first();

                    $paid = Money::of((string) ($row->paid ?? Money::ZERO));
                    $refunded = Money::of((string) ($row->refunded ?? Money::ZERO));
                    $balance = Money::sub((string) $invoice->total_amount, $paid);

                    $differences = [];

                    foreach ([
                        'paid_amount' => $paid,
                        'refunded_amount' => $refunded,
                        'balance_amount' => $balance,
                    ] as $column => $expected) {
                        if (Money::compare((string) $invoice->{$column}, $expected) !== 0) {
                            $differences[$column] = [(string) $invoice->{$column}, $expected];
                        }
                    }

                    if ($differences === []) {
                        // `continue`, never `return`: a `return` here leaves the chunk callback and
                        // silently skips every remaining invoice in the batch — which is how a
                        // reconciler ends up reporting "1 invoice checked" and looking clean.
                        continue;
                    }

                    $drifted[] = [
                        'invoice' => $invoice->invoice_number ?? $invoice->draft_reference,
                        'id' => (int) $invoice->getKey(),
                        'differences' => $differences,
                    ];

                    if ($repair) {
                        $invoices->recomputePaid($invoice);
                        $invoices->recomputeStatus($invoice->refresh());
                    }
                }
            });

        if ($drifted === []) {
            if (! $this->option('quiet-when-ok')) {
                $this->info(sprintf('%d invoice(s) checked · every cached figure matches its receipts.', $checked));
            }

            return self::SUCCESS;
        }

        $this->components->error(sprintf(
            '%d of %d invoice(s) disagree with the receipts behind them.',
            count($drifted),
            $checked,
        ));

        foreach ($drifted as $entry) {
            foreach ($entry['differences'] as $column => [$was, $shouldBe]) {
                $this->line(sprintf(
                    '  %-14s %-20s cached %s · receipts say %s%s',
                    $entry['invoice'],
                    $column,
                    $was,
                    $shouldBe,
                    $repair ? ' → repaired' : '',
                ));
            }
        }

        // Nobody reads a scheduled command's console: the finding has to reach the error log and its
        // alerting, whether or not a human ran it.
        Log::error('invoices:reconcile-balances found drift', [
            'checked' => $checked,
            'drifted' => count($drifted),
            'repaired' => $repair,
            'invoices' => array_slice(array_column($drifted, 'invoice'), 0, 50),
        ]);

        if (! $repair) {
            $this->line('');
            $this->line('  Nothing was changed. Re-run with --repair once somebody has decided why they drifted;');
            $this->line('  a silent auto-repair would erase the evidence and leave the cause in place.');
        }

        return $repair ? self::SUCCESS : self::FAILURE;
    }
}
