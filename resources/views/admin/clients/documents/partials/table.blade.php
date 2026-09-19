{{--
    The client documents table (phase-05 §8.9): title, category badge, size, type icon, uploaded by, date, expiry badge
    (amber within the warning window, rose when past), the "Visible to client" switch, and Download / Edit / Delete.
    Turning visibility ON is wrapped in x-ui.confirm, whose text says the client will see and be notified about the file
    immediately. Used by admin/clients/documents/index (paginated, sortable) and the Documents tab of admin/clients/show.

    @include('admin.clients.documents.partials.table', [
        'client' => $client,
        'documents' => $documents,            // LengthAwarePaginator or Collection<ClientDocument> (creator, sharedBy)
        'can' => $can,                        // array{upload, download, edit, share, delete}; each row still asks the policy
        'expiryWarningDays' => 30,
        'sortable' => true, 'sort' => $sort, 'direction' => $direction,
        'filtered' => $filtered,              // "no match" vs "no documents"
        'loading' => 'navigating',            // optional Alpine expression for the skeleton
    ])
    The edit dialog is admin/clients/documents/partials/edit-dialog (include it once on the page).
--}}

@php
    use Illuminate\Support\Facades\Route;

    $tableUser = auth()->user();
    $tableCan = array_merge(['upload' => false, 'download' => false, 'edit' => false, 'share' => false, 'delete' => false], (array) ($can ?? []));
    $tableSortable = (bool) ($sortable ?? false);
    $tableSort = $sort ?? 'created_at';
    $tableDirection = $direction ?? 'desc';
    $tableWarningDays = max(1, (int) ($expiryWarningDays ?? 30));
    $tableDocuments = $documents ?? collect();
    $tableIsPaginator = $tableDocuments instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator;
    $tableToday = \App\Support\Format::carbon(\App\Support\Format::instantDate(now(), 'Y-m-d'));
    $humanSize = static function ($bytes): string {
        $bytes = max(0, (int) $bytes);

        return match (true) {
            $bytes >= 1048576 => app_number($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => app_number($bytes / 1024, 0).' KB',
            default => app_number($bytes).' B',
        };
    };
    $typeIcon = static fn (?string $extension): string => match (strtolower((string) $extension)) {
        'jpg', 'jpeg', 'png', 'webp', 'gif' => 'photo',
        'xls', 'xlsx', 'csv' => 'table-cells',
        'pdf' => 'document-text',
        default => 'document',
    };
@endphp

<x-ui.table :loading="$loading ?? false" :is-empty="$tableDocuments->isEmpty()" :columns="7">
    <x-slot:head>
        @if ($tableSortable)
            <x-ui.th-sortable column="title" :sort="$tableSort" :direction="$tableDirection">Document</x-ui.th-sortable>
            <x-ui.th-sortable column="category" :sort="$tableSort" :direction="$tableDirection">Category</x-ui.th-sortable>
            <x-ui.th-sortable column="size_bytes" :sort="$tableSort" :direction="$tableDirection" align="right" :numeric="true">Size</x-ui.th-sortable>
            <x-ui.th-sortable column="created_at" :sort="$tableSort" :direction="$tableDirection" default="desc">Uploaded</x-ui.th-sortable>
            <x-ui.th-sortable column="expires_at" :sort="$tableSort" :direction="$tableDirection">Expiry</x-ui.th-sortable>
        @else
            <th scope="col" class="px-4 py-3">Document</th>
            <th scope="col" class="px-4 py-3">Category</th>
            <th scope="col" class="px-4 py-3 text-right">Size</th>
            <th scope="col" class="px-4 py-3">Uploaded</th>
            <th scope="col" class="px-4 py-3">Expiry</th>
        @endif
        <th scope="col" class="px-4 py-3">Visible to client</th>
        <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
    </x-slot:head>

    @foreach ($tableDocuments as $document)
        @php
            $creator = $document->relationLoaded('creator') ? $document->creator : null;
            $expires = $document->expires_at ? \App\Support\Format::carbon($document->expires_at) : null;
            $expiryState = match (true) {
                $expires === null => null,
                $expires->lt($tableToday) => 'past',
                $expires->lte($tableToday->addDays($tableWarningDays)) => 'soon',
                default => 'valid',
            };
            $canToggle = $tableCan['share'] && (bool) $tableUser?->can('changeVisibility', $document) && Route::has('admin.clients.documents.visibility');
            $canEditDocument = $tableCan['edit'] && (bool) $tableUser?->can('update', $document) && Route::has('admin.clients.documents.update');
            $canDeleteDocument = $tableCan['delete'] && (bool) $tableUser?->can('delete', $document) && Route::has('admin.clients.documents.destroy');
            $canDownloadDocument = $tableCan['download'] && (bool) $tableUser?->can('download', $document) && Route::has('admin.client-documents.download');
        @endphp
        <tr>
            <td class="min-w-[14rem]">
                <div class="flex items-start gap-3">
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                        <x-ui.icon :name="$typeIcon($document->extension)" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-medium text-slate-900 dark:text-white">{{ $document->title }}</p>
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $document->original_name }}{{ filled($document->description) ? ' · '.$document->description : '' }}</p>
                    </div>
                </div>
            </td>
            <td class="whitespace-nowrap">@include('admin.crm.partials.enum-badge', ['value' => $document->category, 'dot' => false])</td>
            <td class="whitespace-nowrap text-right text-sm tabular-nums">{{ $humanSize($document->size_bytes) }}</td>
            <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                {{ app_date($document->created_at) }}
                @if ($creator)
                    <span class="block">{{ $creator->name }}</span>
                @endif
            </td>
            <td class="whitespace-nowrap">
                @if ($expiryState === 'past')
                    <x-ui.badge color="rose" size="sm" icon="exclamation-circle">Expired {{ app_date($expires) }}</x-ui.badge>
                @elseif ($expiryState === 'soon')
                    <x-ui.badge color="amber" size="sm" icon="clock">Expires {{ app_date($expires) }}</x-ui.badge>
                @elseif ($expiryState === 'valid')
                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ app_date($expires) }}</span>
                @else
                    <span class="text-xs text-slate-400 dark:text-slate-500">—</span>
                @endif
            </td>
            <td class="whitespace-nowrap">
                @if ($canToggle)
                    @if ($document->visible_to_client)
                        <form method="POST" action="{{ route('admin.clients.documents.visibility', [$client, $document]) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="visible_to_client" value="0">
                            <button type="submit" role="switch" aria-checked="true" class="inline-flex items-center gap-2 text-xs font-medium text-emerald-700 dark:text-emerald-400" aria-label="Hide {{ $document->title }} from the client">
                                <span class="relative inline-flex h-5 w-9 items-center rounded-full bg-brand-600 transition dark:bg-brand-500"><span class="absolute left-[3px] h-3.5 w-3.5 translate-x-4 rounded-full bg-white shadow-sm transition dark:bg-white"></span></span>
                                Visible
                            </button>
                        </form>
                    @else
                        <x-ui.confirm
                            :action="route('admin.clients.documents.visibility', [$client, $document])"
                            method="PATCH"
                            :title="'Share '.$document->title.' with the client?'"
                            message="The client will see this document in their portal and be notified about it immediately."
                            confirm-label="Share with client"
                            variant="warning"
                            icon="eye"
                        >
                            <x-slot:trigger>
                                <button type="button" role="switch" aria-checked="false" class="inline-flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400" aria-label="Share {{ $document->title }} with the client">
                                    <span class="relative inline-flex h-5 w-9 items-center rounded-full bg-slate-200 transition dark:bg-slate-700"><span class="absolute left-[3px] h-3.5 w-3.5 rounded-full bg-white shadow-sm transition dark:bg-white"></span></span>
                                    Staff only
                                </button>
                            </x-slot:trigger>
                            <x-slot:fields>
                                <input type="hidden" name="visible_to_client" value="1">
                            </x-slot:fields>
                        </x-ui.confirm>
                    @endif
                @else
                    <x-ui.badge :color="$document->visible_to_client ? 'emerald' : 'slate'" size="sm">{{ $document->visible_to_client ? 'Visible' : 'Staff only' }}</x-ui.badge>
                @endif
                @if ($document->shared_at)
                    <span class="mt-0.5 block text-2xs text-slate-400 dark:text-slate-500">First shared {{ app_date($document->shared_at) }}</span>
                @endif
            </td>
            <td>
                <div class="flex items-center justify-end gap-1">
                    @if ($canDownloadDocument)
                        <x-ui.icon-button icon="arrow-down-tray" size="sm" :href="route('admin.client-documents.download', $document)" :label="'Download '.$document->title" />
                    @endif
                    @if ($canEditDocument)
                        <x-ui.icon-button
                            icon="pencil"
                            size="sm"
                            :label="'Edit '.$document->title"
                            x-on:click="$dispatch('open-modal', { name: 'client-document-edit', url: {{ \Illuminate\Support\Js::from(route('admin.clients.documents.update', [$client, $document])) }}, document: {{ \Illuminate\Support\Js::from([
                                'title' => $document->title,
                                'category' => $document->category instanceof \BackedEnum ? $document->category->value : $document->category,
                                'description' => $document->description,
                                'valid_from' => $document->valid_from ? app_date($document->valid_from, 'Y-m-d') : '',
                                'expires_at' => $document->expires_at ? app_date($document->expires_at, 'Y-m-d') : '',
                            ]) }} })"
                        />
                    @endif
                    @if ($canDeleteDocument)
                        <x-ui.confirm
                            :action="route('admin.clients.documents.destroy', [$client, $document])"
                            :title="'Delete '.$document->title.'?'"
                            :message="$document->visible_to_client ? 'It leaves the client portal immediately. The file is kept so the document can be restored.' : 'The file is kept so the document can be restored.'"
                            confirm-label="Delete document"
                        >
                            <x-slot:trigger>
                                <x-ui.icon-button icon="trash" size="sm" variant="danger" :label="'Delete '.$document->title" />
                            </x-slot:trigger>
                        </x-ui.confirm>
                    @endif
                </div>
            </td>
        </tr>
    @endforeach

    <x-slot:empty>
        @if ($filtered ?? false)
            <x-ui.empty-state icon="paper-clip" title="No documents match these filters">
                <x-slot:action>
                    <x-ui.button variant="secondary" :href="route('admin.clients.documents.index', $client)">Clear filters</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state icon="paper-clip" title="No documents" message="Contracts, NDAs, quotations and tax certificates for this client belong here.">
                @if ($tableCan['upload'])
                    <x-slot:action>
                        <x-ui.button icon="arrow-up-tray" x-on:click="document.getElementById('file-file')?.scrollIntoView({ behavior: 'smooth', block: 'center' }); document.getElementById('file-file')?.focus()">Upload</x-ui.button>
                    </x-slot:action>
                @endif
            </x-ui.empty-state>
        @endif
    </x-slot:empty>

    @if ($tableIsPaginator)
        <x-slot:footer>
            <x-ui.pagination-summary :paginator="$tableDocuments" label="documents" />
        </x-slot:footer>
    @endif
</x-ui.table>
