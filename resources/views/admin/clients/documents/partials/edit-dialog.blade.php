{{--
    The document edit dialog (phase-05 §8.9). Opened by the table's Edit action with
    $dispatch('open-modal', { name: 'client-document-edit', url, document: {title, category, description, valid_from, expires_at} }).

    @include('admin.clients.documents.partials.edit-dialog', ['categoryOptions' => $categoryOptions])

    Posts: PUT admin.clients.documents.update {client, document} (UpdateClientDocumentRequest): title, category, description,
    valid_from, expires_at. The file itself is never replaced; visibility has its own confirmed switch.
--}}

@php
    $editCategories = (array) ($categoryOptions ?? (enum_exists(\App\Enums\ClientDocumentCategory::class) ? \App\Enums\ClientDocumentCategory::options() : []));
    $editFieldClass = 'mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white';
@endphp

@if (\Illuminate\Support\Facades\Route::has('admin.clients.documents.update'))
    <x-ui.modal name="client-document-edit" title="Edit document" icon="pencil">
        <form
            id="client-document-edit-form"
            method="POST"
            x-data="{ url: '' }"
            x-on:open-modal.window="if ($event.detail?.name === 'client-document-edit') {
                const record = $event.detail.document || {};
                url = $event.detail.url;
                $nextTick(() => { ['title', 'category', 'description', 'valid_from', 'expires_at'].forEach((field) => { $refs[field].value = record[field] || ''; }); });
            }"
            x-bind:action="url"
            class="space-y-3"
        >
            @csrf
            @method('PUT')
            <div>
                <label for="document-edit-title" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Title <span class="text-rose-500">*</span></label>
                <input id="document-edit-title" x-ref="title" type="text" name="title" required maxlength="150" class="{{ $editFieldClass }}">
            </div>
            <div>
                <label for="document-edit-category" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Category</label>
                <select id="document-edit-category" x-ref="category" name="category" class="{{ $editFieldClass }}">
                    @foreach ($editCategories as $categoryValue => $categoryLabel)
                        <option value="{{ $categoryValue }}">{{ $categoryLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="document-edit-description" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Description</label>
                <input id="document-edit-description" x-ref="description" type="text" name="description" maxlength="255" class="{{ $editFieldClass }}">
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="document-edit-valid-from" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Valid from</label>
                    <input id="document-edit-valid-from" x-ref="valid_from" type="date" name="valid_from" class="{{ $editFieldClass }}">
                </div>
                <div>
                    <label for="document-edit-expires" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Expires</label>
                    <input id="document-edit-expires" x-ref="expires_at" type="date" name="expires_at" class="{{ $editFieldClass }}">
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">The file itself cannot be replaced; upload a new document instead.</p>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'client-document-edit')">Cancel</x-ui.button>
            <x-ui.button type="submit" form="client-document-edit-form" icon="check">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endif
