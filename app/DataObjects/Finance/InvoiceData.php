<?php

declare(strict_types=1);

namespace App\DataObjects\Finance;

use App\Enums\DiscountMode;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * What somebody typed into the invoice form (phase-13 §6.1).
 *
 * **It carries no total.** Every money column on an invoice and its lines is derived by
 * `InvoiceCalculator` from these figures, so a request body cannot name a total — which is what stops
 * a hand-crafted POST asking a client for a number the lines do not add up to.
 *
 * `taxLabel`, `taxRate` and `paymentTermsDays` are read from settings by the service at creation and
 * **snapshotted**, so they are absent here too: they are facts about the business at that moment, not
 * fields on a form.
 */
final readonly class InvoiceData
{
    /**
     * @param  list<array<string, mixed>>  $lines  in the order they will print
     */
    public function __construct(
        public int $clientId,
        public CarbonInterface $issueDate,
        public array $lines,
        public ?int $projectId = null,
        public ?int $branchId = null,
        public ?CarbonInterface $dueDate = null,
        public ?string $title = null,
        public ?string $reference = null,
        public DiscountMode $discountMode = DiscountMode::None,
        public ?string $discountRate = null,
        public ?string $discountFixed = null,
        public ?int $paymentMethodId = null,
        public ?string $notes = null,
        public ?string $internalNotes = null,
    ) {
        if ($this->discountMode->requiresRate() && $this->discountRate === null) {
            throw new InvalidArgumentException('A percentage discount needs a rate.');
        }

        if ($this->discountMode->requiresAmount() && $this->discountFixed === null) {
            throw new InvalidArgumentException('A fixed discount needs an amount.');
        }
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            clientId: $request->integer('client_id'),
            issueDate: Carbon::parse((string) $request->input('issue_date')),
            lines: array_values($request->input('lines', [])),
            projectId: $request->filled('project_id') ? $request->integer('project_id') : null,
            branchId: $request->filled('branch_id') ? $request->integer('branch_id') : null,
            dueDate: $request->filled('due_date') ? Carbon::parse((string) $request->input('due_date')) : null,
            title: $request->input('title'),
            reference: $request->input('reference'),
            discountMode: DiscountMode::tryFrom((string) $request->input('discount_mode', 'none')) ?? DiscountMode::None,
            discountRate: $request->input('discount_rate'),
            discountFixed: $request->input('discount_fixed'),
            paymentMethodId: $request->filled('payment_method_id') ? $request->integer('payment_method_id') : null,
            notes: $request->input('notes'),
            internalNotes: $request->input('internal_notes'),
        );
    }

    /**
     * The header columns a create or an update writes, before the calculator's figures are merged in.
     *
     * @return array<string, mixed>
     */
    public function headerColumns(): array
    {
        return [
            'client_id' => $this->clientId,
            'project_id' => $this->projectId,
            'branch_id' => $this->branchId,
            'title' => $this->title,
            'reference' => $this->reference,
            'discount_mode' => $this->discountMode->value,
            // Null for the mode that does not use it: `chk_inv_discount_payload` refuses a row that
            // carries a rate it is not using, which is what stops "10% off" quietly meaning PKR 10.
            'discount_rate' => $this->discountMode->requiresRate() ? $this->discountRate : null,
            'discount_fixed' => $this->discountMode->requiresAmount() ? $this->discountFixed : null,
            'payment_method_id' => $this->paymentMethodId,
            'notes' => $this->notes,
            'internal_notes' => $this->internalNotes,
        ];
    }
}
