@extends('layouts.panel')

@section('title', 'Documents')

{{--
    Client panel documents — client.documents.index (phase-05 §8.10 "Documents", §9.2). The query is exactly
    client_documents.client_id = ClientContext::clientId() AND visible_to_client = 1 AND deleted_at IS NULL; a staff-only
    document never reaches this page. Downloads re-check ClientDocumentPolicy::downloadAsClient (another client's id = 404).

    Controller variables (Client\DocumentController@index → ServesClientPortal::sectionList('documents'); this is
    App\Support\Portal\Sections\DocumentsSection::view()):
      $client            App\Models\Crm\Client
      $section           App\Contracts\Portal\ClientPortalSection (DocumentsSection)
      $items             LengthAwarePaginator<ClientDocument>  DocumentsSection::paginate() — DocumentsSection::COLUMNS only,
                         newest shared first ($documents is accepted too)
      $filters           array<string, mixed>
      $canDownload       bool  client_portal.download
      $categoryOptions   optional array<string, string>  ClientDocumentCategory::options()
      $clientName, $portalSections   the shared portal data
    Query: search (title, original name), category, page.
--}}

@php
    $documents = $documents ?? ($items ?? new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20));
    $sort = $sort ?? 'shared_at';
    $direction = $direction ?? 'desc';
    $categoryOptions = (array) ($categoryOptions ?? (enum_exists(\App\Enums\ClientDocumentCategory::class) ? \App\Enums\ClientDocumentCategory::options() : []));
    $canDownload = (bool) ($canDownload ?? auth()->user()?->can('client_portal.download')) && \Illuminate\Support\Facades\Route::has('client.documents.download');
    $filtered = collect(request()->except(['page', 'sort', 'direction']))->filter(fn ($value) => filled($value))->isNotEmpty();
    $today = \App\Support\Format::carbon(\App\Support\Format::instantDate(now(), 'Y-m-d'));
    $humanSize = static function ($bytes): string {
        $bytes = max(0, (int) $bytes);

        return match (true) {
            $bytes >= 1048576 => app_number($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => app_number($bytes / 1024, 0).' KB',
            default => app_number($bytes).' B',
        };
    };
@endphp

@section('header')
    @include('client.partials.header', ['client' => $client, 'title' => 'Documents', 'subtitle' => 'Contracts, proposals and papers we have shared with you.', 'icon' => 'paper-clip'])
@endsection

@section('content')
    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        <x-ui.filter-bar placeholder="Search documents…" :reset="route('client.documents.index')">
            <x-ui.form.select name="category" :options="$categoryOptions" :selected="request('category')" placeholder="Any category" size="sm" aria-label="Filter by category" />
        </x-ui.filter-bar>

        <x-ui.table loading="navigating" :is-empty="$documents->isEmpty()" :columns="6">
            <x-slot:head>
                <x-ui.th-sortable column="title" :sort="$sort" :direction="$direction">Title</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3">Category</th>
                <th scope="col" class="px-4 py-3 text-right">Size</th>
                <x-ui.th-sortable column="shared_at" :sort="$sort" :direction="$direction" default="desc">Shared</x-ui.th-sortable>
                <x-ui.th-sortable column="expires_at" :sort="$sort" :direction="$direction">Expiry</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Download</span></th>
            </x-slot:head>

            @foreach ($documents as $document)
                @php
                    $expires = $document->expires_at ? \App\Support\Format::carbon($document->expires_at) : null;
                @endphp
                <tr>
                    <td class="min-w-[14rem] font-medium text-slate-900 dark:text-white">{{ $document->title }}</td>
                    <td class="whitespace-nowrap">@include('admin.crm.partials.enum-badge', ['value' => $document->category, 'dot' => false])</td>
                    <td class="whitespace-nowrap text-right tabular-nums text-sm">{{ $humanSize($document->size_bytes) }}</td>
                    <td class="whitespace-nowrap text-sm">{{ app_date($document->shared_at ?? $document->created_at) }}</td>
                    <td class="whitespace-nowrap">
                        @if ($expires === null)
                            <span class="text-xs text-slate-400 dark:text-slate-500">—</span>
                        @elseif ($expires->lt($today))
                            <x-ui.badge color="rose" size="sm">Expired {{ app_date($expires) }}</x-ui.badge>
                        @elseif ($expires->lte($today->addDays(30)))
                            <x-ui.badge color="amber" size="sm">Expires {{ app_date($expires) }}</x-ui.badge>
                        @else
                            <span class="text-sm text-slate-600 dark:text-slate-300">{{ app_date($expires) }}</span>
                        @endif
                    </td>
                    <td class="text-right">
                        @if ($canDownload)
                            <x-ui.button size="sm" variant="secondary" icon="arrow-down-tray" :href="route('client.documents.download', $document)">Download</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                @if ($filtered)
                    <x-ui.empty-state icon="paper-clip" title="No documents match your search">
                        <x-slot:action>
                            <x-ui.button variant="secondary" :href="route('client.documents.index')">Show all documents</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="paper-clip" title="No documents shared yet" message="When we share a contract, proposal or other paper with you, it appears here." />
                @endif
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$documents" label="documents" />
            </x-slot:footer>
        </x-ui.table>
    </div>
@endsection
