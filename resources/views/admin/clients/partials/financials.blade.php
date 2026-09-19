{{--
    The client's financial summary (phase-05 §6.7 financialSummary(), §8.8 "Financials"): invoiced, paid, outstanding and
    overdue as x-ui.stat-cards, each sourced from the owning phase's read model (Phase 13 invoices, the spine's
    project_payments). A figure whose capability is not bound renders as "-" with an explanation — never a fabricated
    0.00 (D28, test 59). Rendered only for clients.view_financial; the controller does not compute it otherwise (test 58).

    @include('admin.clients.partials.financials', ['summary' => $financialSummary])

      $summary  App\DataObjects\Crm\ClientFinancialSummary (state: available | unavailable | withheld; invoiced, paid,
                outstanding, overdue: ?string decimal(15,2)) — or an array of the same keys. A withheld summary renders
                nothing at all.
--}}

@php
    $summaryState = is_object($summary ?? null) && method_exists($summary, 'isAvailable')
        ? ($summary->isAvailable() ? 'available' : (method_exists($summary, 'isWithheld') && $summary->isWithheld() ? 'withheld' : 'unavailable'))
        : (string) (data_get($summary ?? null, 'state') ?? (data_get($summary ?? null, 'available') ? 'available' : 'unavailable'));
    $summaryAvailable = $summaryState === 'available';
    $summaryReason = 'Available once invoicing and payments are in use.';
    $cards = [
        ['key' => 'invoiced', 'label' => 'Invoiced', 'icon' => 'receipt-percent', 'color' => 'brand'],
        ['key' => 'paid', 'label' => 'Paid', 'icon' => 'banknotes', 'color' => 'emerald'],
        ['key' => 'outstanding', 'label' => 'Outstanding', 'icon' => 'clock', 'color' => 'amber'],
        ['key' => 'overdue', 'label' => 'Overdue', 'icon' => 'exclamation-triangle', 'color' => 'rose'],
    ];
@endphp

@if ($summaryState !== 'withheld')
    <div class="space-y-3">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($cards as $card)
                @php $figure = $summaryAvailable ? data_get($summary, $card['key']) : null; @endphp
                <x-ui.stat-card
                    :label="$card['label']"
                    :value="$figure !== null ? money((string) $figure) : '-'"
                    :icon="$card['icon']"
                    :color="$card['color']"
                    :title="$figure === null ? $summaryReason : null"
                >
                    @if ($figure === null)
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ $summaryReason }}</p>
                    @endif
                </x-ui.stat-card>
            @endforeach
        </div>
        @unless ($summaryAvailable)
            <p class="text-xs text-slate-500 dark:text-slate-400">No figure is shown as zero: a dash means the figure does not exist yet, not that nothing is owed.</p>
        @endunless
    </div>
@endif
