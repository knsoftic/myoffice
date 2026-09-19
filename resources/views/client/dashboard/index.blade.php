@extends('layouts.panel')

@section('title', 'Dashboard')

{{--
    Client panel dashboard — client.dashboard (phase-05 §8.10 "Dashboard", §6.9, §9.2). "Where do we stand."
    Every card comes from ClientPortalService::dashboard(): a registered ClientPortalSection's badgeCount() for the client
    in ClientContext, plus Phase 5's own documents and notifications counts. A card whose section is not registered, whose
    module is off, or whose permission is not held is simply absent — never an invented zero (D28).
    (The Phase 1 view client/dashboard.blade.php stays untouched; Client\DashboardController renders this one.)

    Controller variables (Client\DashboardController@index, once it renders this view):
      $client             App\Models\Crm\Client  ClientContext::client()
      $dashboard          App\DataObjects\Crm\DashboardData  ClientPortalService::dashboard($client) — its `cards`:
                          list<array{key: string, label: string, icon: string, count: ?int, route: ?string}> in section
                          order; a null count renders as a link without a number
      $cards              optional — the same list when passed directly (also accepts value / href / hint / color)
      $recentDocuments    optional Collection<ClientDocument>  the latest shared documents (bounded, e.g. 5)
      $accountManager     optional ?App\Models\User  name and email only
      $greeting           optional ?string
      $portalSections, $clientName   the shared portal data (ServesClientPortal::portalViewData())
--}}

@php
    $cards = collect($cards ?? data_get($dashboard ?? null, 'cards', []))
        ->map(static fn ($card): array => [
            'label' => (string) data_get($card, 'label', ''),
            'icon' => (string) (data_get($card, 'icon') ?: 'squares-2x2'),
            'value' => data_get($card, 'value') ?? (data_get($card, 'count') !== null ? app_number((int) data_get($card, 'count')) : '—'),
            'href' => data_get($card, 'href') ?? data_get($card, 'route'),
            'hint' => data_get($card, 'hint'),
            'color' => data_get($card, 'color'),
        ]);
    $accountManager = $accountManager ?? ($client->relationLoaded('accountManager') ? $client->accountManager : null);
    $showRecentDocuments = isset($recentDocuments);
    $recentDocuments = collect($recentDocuments ?? []);
    $displayName = $client->display_name ?? ($client->company_name ?: $client->name);
    $canDownload = (bool) auth()->user()?->can('client_portal.download') && \Illuminate\Support\Facades\Route::has('client.documents.download');
    $tones = ['brand', 'emerald', 'amber', 'rose', 'sky', 'violet', 'slate'];
@endphp

@section('header')
    @include('client.partials.header', ['client' => $client, 'title' => $greeting ?? 'Welcome back', 'subtitle' => 'An overview of your work with us.', 'icon' => 'home'])
@endsection

@section('content')
    <div class="space-y-6">
        @if ($cards->isEmpty())
            <x-ui.card :padded="false">
                <x-ui.empty-state icon="sparkles" title="Your account is being set up" message="Your projects, invoices and documents will appear here as soon as they are shared with you." />
            </x-ui.card>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($cards as $card)
                    <x-ui.stat-card
                        :label="$card['label']"
                        :value="$card['value']"
                        :icon="$card['icon']"
                        :color="in_array($card['color'], $tones, true) ? $card['color'] : 'brand'"
                        :href="$card['href']"
                        :delta-label="$card['hint']"
                    />
                @endforeach
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            @if ($showRecentDocuments)
            <x-ui.card title="Recently shared documents" icon="paper-clip" class="xl:col-span-2" :padded="false">
                @if (\Illuminate\Support\Facades\Route::has('client.documents.index') && auth()->user()?->can('client_portal.documents'))
                    <x-slot:actions>
                        <x-ui.button size="sm" variant="ghost" icon-trailing="arrow-right" :href="route('client.documents.index')">All documents</x-ui.button>
                    </x-slot:actions>
                @endif
                @if ($recentDocuments->isEmpty())
                    <x-ui.empty-state icon="paper-clip" title="No documents shared yet" message="Contracts and proposals we share with you appear here." :compact="true" />
                @else
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($recentDocuments as $document)
                            <li class="flex items-center justify-between gap-3 px-4 py-3 sm:px-5">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $document->title }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        @include('admin.crm.partials.enum-badge', ['value' => $document->category, 'dot' => false, 'size' => 'xs'])
                                        · shared {{ app_date($document->shared_at ?? $document->created_at) }}
                                    </p>
                                </div>
                                @if ($canDownload)
                                    <x-ui.icon-button icon="arrow-down-tray" size="sm" :href="route('client.documents.download', $document)" :label="'Download '.$document->title" />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
            @endif

            <x-ui.card title="Your contact with us" icon="user">
                @if ($accountManager ?? null)
                    <div class="flex items-center gap-3">
                        <x-ui.avatar :src="$accountManager->avatar_url ?? null" :name="$accountManager->name" size="lg" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $accountManager->name }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Account manager</p>
                            @if (filled($accountManager->email))
                                <a href="mailto:{{ $accountManager->email }}" class="mt-1 block truncate text-sm text-brand-700 hover:underline dark:text-brand-300">{{ $accountManager->email }}</a>
                            @endif
                        </div>
                    </div>
                @else
                    <p class="text-sm text-slate-500 dark:text-slate-400">An account manager will be assigned to {{ $displayName }} shortly.</p>
                @endif
                @if (\Illuminate\Support\Facades\Route::has('client.profile.edit') && auth()->user()?->can('client_portal.profile'))
                    <x-slot:footer>
                        <a href="{{ route('client.profile.edit') }}" class="font-semibold text-brand-700 hover:underline dark:text-brand-300">Keep your company details up to date</a>
                    </x-slot:footer>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
