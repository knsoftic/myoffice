@extends('layouts.admin')

@section('title', 'Documents · '.($client->display_name ?? $client->name))

{{--
    Client documents — admin.clients.documents.index {client} (phase-05 §8.9, [D-P5-10]). The full, filterable document
    list of one client; the client page's Documents tab shows the latest ones and the same upload card. Files live on the
    private disk and are reachable only through admin.client-documents.download, which re-runs
    ClientDocumentPolicy::download. `visible_to_client` has no effect on staff; it is only the portal gate.

    Controller variables (Admin\ClientDocumentController@index):
      $client              App\Models\Crm\Client
      $documents           LengthAwarePaginator<ClientDocument> with creator, sharedBy
      $filters             array<string, mixed>
      $sort, $direction    created_at (default desc) | title | category | size_bytes | expires_at
      $categoryOptions     array<string, string>  ClientDocumentCategory::options()
      $expiryWarningDays   int    ClientDocument::EXPIRY_WARNING_DAYS
      $upload              array{extensions: list<string>, max_kb: int, visible_default: bool}
      $can                 array{upload: bool, download: bool, edit: bool, share: bool, delete: bool}
    Query: search (title, original name, description), category, visible (1 | 0), expiring (soon | past), sort, direction,
    page. With `Accept: application/json` the controller answers {data, meta} instead.

    Writes: see admin/clients/documents/partials/{upload,table,edit-dialog}.
--}}

@php
    $user = auth()->user();
    $sort = $sort ?? 'created_at';
    $direction = $direction ?? 'desc';
    $categoryOptions = (array) ($categoryOptions ?? (enum_exists(\App\Enums\ClientDocumentCategory::class) ? \App\Enums\ClientDocumentCategory::options() : []));
    $can = array_merge([
        'upload' => (bool) $user?->can('client_documents.upload'),
        'download' => (bool) $user?->can('client_documents.download'),
        'edit' => (bool) $user?->can('client_documents.edit'),
        'share' => (bool) $user?->can('client_documents.change_status'),
        'delete' => (bool) $user?->can('client_documents.delete'),
    ], (array) ($can ?? []));
    $filtered = collect(request()->except(['page', 'sort', 'direction']))->filter(fn ($value) => filled($value))->isNotEmpty();
    $canUpload = (bool) $can['upload'] && \Illuminate\Support\Facades\Route::has('admin.clients.documents.store');
@endphp

@section('header')
    @include('admin.clients.partials.header', ['client' => $client, 'compact' => true])
@endsection

@section('content')
    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-ui.section-heading title="Documents" :subtitle="app_number($documents->total()).' '.\Illuminate\Support\Str::plural('document', $documents->total()).' on file'" icon="paper-clip" />
            <x-ui.button variant="ghost" size="sm" icon="arrow-left" :href="route('admin.clients.show', ['client' => $client, 'tab' => 'documents'])">Back to the client</x-ui.button>
        </div>

        @if ($canUpload)
            @include('admin.clients.documents.partials.upload', ['client' => $client, 'categoryOptions' => $categoryOptions, 'upload' => $upload ?? []])
        @endif

        <x-ui.filter-bar placeholder="Search document titles…" :reset="route('admin.clients.documents.index', $client)">
            <x-ui.form.select name="category" :options="$categoryOptions" :selected="request('category')" placeholder="Any category" size="sm" aria-label="Filter by category" />
            <x-ui.form.select name="visible" :options="['1' => 'Visible to the client', '0' => 'Staff only']" :selected="request('visible')" placeholder="Any visibility" size="sm" aria-label="Filter by visibility" />
            <x-ui.form.select name="expiring" :options="['soon' => 'Expiring soon', 'past' => 'Expired']" :selected="request('expiring')" placeholder="Any expiry" size="sm" aria-label="Filter by expiry" />
        </x-ui.filter-bar>

        @include('admin.clients.documents.partials.table', [
            'client' => $client,
            'documents' => $documents,
            'can' => $can,
            'expiryWarningDays' => $expiryWarningDays ?? 30,
            'sortable' => true,
            'sort' => $sort,
            'direction' => $direction,
            'filtered' => $filtered,
            'loading' => 'navigating',
        ])
    </div>

    @include('admin.clients.documents.partials.edit-dialog', ['categoryOptions' => $categoryOptions])
@endsection
