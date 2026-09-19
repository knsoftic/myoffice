{{--
    Printable client sheet — admin.clients.print {client} (phase-05 §7, §8.8 "Print"). A standalone document: no sidebar,
    light theme forced, A4-friendly. Internal notes are never printed; financial figures only for clients.view_financial.

    Controller variables (Admin\ClientController@print), clients.print has passed:
      $client            App\Models\Crm\Client with accountManager and contacts
      $contacts          optional Collection<ClientContact>; the loaded `contacts` relation otherwise
      $companyName       optional ?string  the business name for the heading (passed in — views read no setting)
      $canViewFinancial  bool   clients.view_financial
      $financialSummary  ClientFinancialSummary — present ONLY with clients.view_financial; printed as figures or "-"
      $printedBy         App\Models\User
--}}

@php
    $contacts = collect($contacts ?? ($client->relationLoaded('contacts') ? $client->contacts : []))
        ->sortByDesc(static fn ($contact): int => (int) (bool) $contact->is_primary)
        ->values();
    $printSummary = ($canViewFinancial ?? false) && isset($financialSummary) ? $financialSummary : null;
    $printSummaryAvailable = is_object($printSummary) && method_exists($printSummary, 'isAvailable') ? $printSummary->isAvailable() : false;
    $manager = $client->relationLoaded('accountManager') ? $client->accountManager : null;
    $label = static fn ($value): string => $value instanceof \BackedEnum
        ? (method_exists($value, 'label') ? (string) $value->label() : \Illuminate\Support\Str::headline((string) $value->value))
        : (filled($value) ? \Illuminate\Support\Str::headline((string) $value) : '—');
    $rate = static fn ($value): string => $value === null || $value === '' ? 'Default' : \App\Support\Format::percentage((string) $value);
    $displayName = $client->display_name ?? ($client->company_name ?: $client->name);

    $sections = [
        'Client' => [
            'Client ID' => $client->client_code,
            'Type' => $label($client->client_type),
            'Company' => $client->company_name,
            'Contact person' => $client->name,
            'Email' => $client->email,
            'Phone' => $client->phone,
            'WhatsApp' => $client->whatsapp,
            'Website' => $client->website,
            'Industry' => $client->industry,
            'Status' => $label($client->status),
            'Account manager' => $manager?->name,
        ],
        'Address' => [
            'Address' => $client->address,
            'City' => $client->city,
            'State' => $client->state,
            'Postal code' => $client->postal_code,
            'Country' => $client->country,
            'Billing address' => $client->billing_same_as_address ? 'Same as the address' : $client->billing_address,
        ],
        'Tax and terms' => [
            'Tax registered' => $client->tax_registered ? 'Yes' : 'No',
            'NTN' => $client->tax_number,
            'STRN / GST' => $client->sales_tax_number,
            'CNIC' => $client->cnic,
            'Tax exempt' => $client->tax_exempt ? 'Yes' : 'No',
            'Tax rate' => $rate($client->tax_rate_override),
            'Withholding rate' => $rate($client->withholding_tax_rate),
            'Currency' => $client->currency ?: 'Business default',
            'Payment terms' => $client->payment_terms_days !== null ? app_number((int) $client->payment_terms_days).' days' : 'Business default',
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('layouts.partials.head')
    <style>
        @@page { size: A4; margin: 14mm; }
        @@media print { .no-print { display: none !important; } body { background: #fff !important; } }
    </style>
</head>
<body class="min-h-full bg-slate-100 text-slate-900 antialiased">
    <div class="no-print sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
        <a href="{{ route('admin.clients.show', $client) }}" class="text-sm font-semibold text-slate-600 hover:text-slate-900">← Back to the client</a>
        <button type="button" onclick="window.print()" class="inline-flex h-9 items-center gap-2 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-700">Print</button>
    </div>

    <main class="mx-auto my-6 max-w-3xl bg-white p-8 shadow-sm ring-1 ring-slate-200 print:my-0 print:max-w-none print:p-0 print:shadow-none print:ring-0">
        <header class="flex items-start justify-between gap-6 border-b border-slate-200 pb-4">
            <div>
                @if (filled($companyName ?? null))
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $companyName }}</p>
                @endif
                <h1 class="mt-1 text-2xl font-semibold tracking-tight">{{ $displayName }}</h1>
                <p class="font-mono text-sm text-slate-600">{{ $client->client_code }}</p>
            </div>
            <p class="text-right text-xs text-slate-500">Client sheet<br>Printed {{ app_datetime(now()) }}</p>
        </header>

        @foreach ($sections as $sectionTitle => $rows)
            <section class="mt-6 break-inside-avoid">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500">{{ $sectionTitle }}</h2>
                <dl class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2 text-sm">
                    @foreach ($rows as $rowLabel => $rowValue)
                        <div class="flex justify-between gap-4 border-b border-slate-100 py-1">
                            <dt class="text-slate-500">{{ $rowLabel }}</dt>
                            <dd class="text-right font-medium">{{ filled($rowValue) ? $rowValue : '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach

        @if ($printSummary !== null)
            <section class="mt-6 break-inside-avoid">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Financial summary</h2>
                <dl class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2 text-sm">
                    @foreach (['invoiced' => 'Invoiced', 'paid' => 'Paid', 'outstanding' => 'Outstanding', 'overdue' => 'Overdue'] as $figureKey => $figureLabel)
                        @php $figure = $printSummaryAvailable ? data_get($printSummary, $figureKey) : null; @endphp
                        <div class="flex justify-between gap-4 border-b border-slate-100 py-1">
                            <dt class="text-slate-500">{{ $figureLabel }}</dt>
                            <dd class="text-right font-medium tabular-nums">{{ $figure !== null ? money((string) $figure) : '-' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endif

        <section class="mt-8 break-inside-avoid">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Contacts</h2>
            @if ($contacts->isEmpty())
                <p class="mt-2 text-sm text-slate-500">None.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-300 text-xs uppercase tracking-wider text-slate-500">
                            <th class="py-1.5 pr-3">Name</th><th class="py-1.5 pr-3">Designation</th><th class="py-1.5 pr-3">Email</th><th class="py-1.5">Phone</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contacts as $contact)
                            <tr class="border-b border-slate-100">
                                <td class="py-1.5 pr-3">{{ $contact->name }}{{ $contact->is_primary ? ' (primary)' : '' }}</td>
                                <td class="py-1.5 pr-3">{{ $contact->designation ?: '—' }}</td>
                                <td class="py-1.5 pr-3">{{ $contact->email ?: '—' }}</td>
                                <td class="py-1.5">{{ $contact->phone ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </section>
    </main>
</body>
</html>
