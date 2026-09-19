{{--
    The client panel page header (phase-05 §8.10): every screen shows the client's own company name, so the scope of what
    is on the page is visible to the person reading it.

    @include('client.partials.header', [
        'client' => $client,               // App\Models\Crm\Client — from ClientContext, never from the request
        'title' => 'Documents',
        'subtitle' => 'Contracts and papers shared with you',
        'icon' => 'paper-clip',
    ])
    An `actions` string of rendered HTML is not accepted; put page actions in the page body.
--}}

@php
    $scopeClient = $client ?? null;
    $scopeName = $scopeClient ? ($scopeClient->display_name ?? ($scopeClient->company_name ?: $scopeClient->name)) : null;
@endphp

<x-ui.page-header :title="$title ?? 'Client portal'" :subtitle="$subtitle ?? null" :icon="$icon ?? null">
    @if ($scopeName)
        <p class="mt-2 inline-flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 px-2.5 py-1 text-xs text-slate-600 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/60 dark:text-slate-300 dark:ring-slate-700">
            <x-ui.icon name="building-office" class="h-3.5 w-3.5" />
            <span>Showing <span class="font-semibold text-slate-900 dark:text-white">{{ $scopeName }}</span></span>
            @if (filled($scopeClient->client_code ?? null))
                <span class="font-mono text-slate-500 dark:text-slate-400">{{ $scopeClient->client_code }}</span>
            @endif
        </p>
    @endif
</x-ui.page-header>
