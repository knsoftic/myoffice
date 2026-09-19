{{--
    Printable lead sheet — admin.leads.print (phase-05 §7, §8.3 "Print"). A standalone document: no sidebar, no topbar,
    light theme forced, A4-friendly. Phase 13 ships layouts/print later; this page does not depend on it.

    Controller variables (Admin\LeadController@print), LeadPolicy::print / leads.print has passed:
      $lead          App\Models\Crm\Lead with assignee, service, client
      $activities    Collection<LeadActivity> the most recent entries (the controller bounds it, e.g. 25), with creator
      $followUps     Collection<LeadFollowUp> with assignee
      $companyName   ?string  the business name for the heading (company.name, passed in — views read no setting)
--}}

@php
    $assignee = $lead->relationLoaded('assignee') ? $lead->assignee : null;
    $service = $lead->relationLoaded('service') ? $lead->service : null;
    $client = $lead->relationLoaded('client') ? $lead->client : null;
    $activities = collect($activities ?? []);
    $followUps = collect($followUps ?? []);
    $label = static fn ($value): string => $value instanceof \BackedEnum
        ? (method_exists($value, 'label') ? (string) $value->label() : \Illuminate\Support\Str::headline((string) $value->value))
        : (filled($value) ? \Illuminate\Support\Str::headline((string) $value) : '—');

    $rows = [
        'Lead #' => $lead->lead_no,
        'Name' => $lead->name,
        'Company' => $lead->company,
        'Email' => $lead->email,
        'Phone' => $lead->phone,
        'WhatsApp' => $lead->whatsapp,
        'Country' => $lead->country,
        'Interested service' => $service?->name ?? $lead->interested_service,
        'Budget' => $lead->budget_amount !== null ? money((string) $lead->budget_amount) : null,
        'Source' => $label($lead->source),
        'Source detail' => $lead->source_detail,
        'Status' => $label($lead->status),
        'Assigned to' => $assignee?->name,
        'Next follow-up' => $lead->follow_up_at ? app_datetime($lead->follow_up_at) : null,
        'Last activity' => $lead->last_activity_at ? app_datetime($lead->last_activity_at) : null,
        'Referral code' => $lead->referral_code_captured,
        'Client' => $client ? (($client->display_name ?? $client->name).' ('.$client->client_code.')') : null,
        'Created' => app_datetime($lead->created_at),
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
        <a href="{{ route('admin.leads.show', $lead) }}" class="text-sm font-semibold text-slate-600 hover:text-slate-900">← Back to the lead</a>
        <button type="button" onclick="window.print()" class="inline-flex h-9 items-center gap-2 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-700">
            Print
        </button>
    </div>

    <main class="mx-auto my-6 max-w-3xl bg-white p-8 shadow-sm ring-1 ring-slate-200 print:my-0 print:max-w-none print:p-0 print:shadow-none print:ring-0">
        <header class="flex items-start justify-between gap-6 border-b border-slate-200 pb-4">
            <div>
                @if (filled($companyName ?? null))
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $companyName }}</p>
                @endif
                <h1 class="mt-1 text-2xl font-semibold tracking-tight">{{ $lead->name }}</h1>
                <p class="text-sm text-slate-600">{{ collect([$lead->lead_no, $lead->company])->filter()->implode(' · ') }}</p>
            </div>
            <p class="text-right text-xs text-slate-500">Lead sheet<br>Printed {{ app_datetime(now()) }}</p>
        </header>

        <section class="mt-6">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Details</h2>
            <dl class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2 text-sm">
                @foreach ($rows as $rowLabel => $rowValue)
                    <div class="flex justify-between gap-4 border-b border-slate-100 py-1">
                        <dt class="text-slate-500">{{ $rowLabel }}</dt>
                        <dd class="text-right font-medium">{{ filled($rowValue) ? $rowValue : '—' }}</dd>
                    </div>
                @endforeach
            </dl>
            @if (filled($lead->notes))
                <p class="mt-4 whitespace-pre-line rounded border border-slate-200 p-3 text-sm">{{ $lead->notes }}</p>
            @endif
            @if (filled($lead->lost_reason))
                <p class="mt-3 text-sm"><span class="font-semibold">Lost reason:</span> {{ $lead->lost_reason }}</p>
            @endif
        </section>

        <section class="mt-8 break-inside-avoid">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Follow-ups</h2>
            @if ($followUps->isEmpty())
                <p class="mt-2 text-sm text-slate-500">None.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-300 text-xs uppercase tracking-wider text-slate-500">
                            <th class="py-1.5 pr-3">Due</th><th class="py-1.5 pr-3">Type</th><th class="py-1.5 pr-3">Status</th><th class="py-1.5 pr-3">Outcome</th><th class="py-1.5">Who</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($followUps as $followUp)
                            <tr class="border-b border-slate-100 align-top">
                                <td class="py-1.5 pr-3 whitespace-nowrap">{{ app_datetime($followUp->scheduled_at) }}</td>
                                <td class="py-1.5 pr-3">{{ $label($followUp->type) }}</td>
                                <td class="py-1.5 pr-3">{{ $label($followUp->status) }}</td>
                                <td class="py-1.5 pr-3">{{ $followUp->outcome ? $label($followUp->outcome) : '—' }}</td>
                                <td class="py-1.5">{{ ($followUp->relationLoaded('assignee') ? $followUp->assignee?->name : null) ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </section>

        <section class="mt-8">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Recent activity</h2>
            @if ($activities->isEmpty())
                <p class="mt-2 text-sm text-slate-500">None.</p>
            @else
                <ol class="mt-3 space-y-2 text-sm">
                    @foreach ($activities as $activity)
                        <li class="break-inside-avoid border-b border-slate-100 pb-2">
                            <p class="flex justify-between gap-4">
                                <span class="font-medium">{{ $label($activity->type) }}{{ filled($activity->subject) ? ' — '.$activity->subject : '' }}</span>
                                <span class="whitespace-nowrap text-xs text-slate-500">{{ app_datetime($activity->occurred_at) }} · {{ ($activity->relationLoaded('creator') ? $activity->creator?->name : null) ?? 'System' }}</span>
                            </p>
                            @if (filled($activity->body))
                                <p class="mt-0.5 whitespace-pre-line text-slate-700">{{ $activity->body }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </main>
</body>
</html>
