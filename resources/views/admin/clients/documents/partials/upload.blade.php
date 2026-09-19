{{--
    The client document upload card (phase-05 §8.9): drop zone, category, title, description, valid-from / expires-at and
    the "Visible to client" switch. Used by admin/clients/documents/index and the Documents tab of admin/clients/show.

    @include('admin.clients.documents.partials.upload', ['client' => $client, 'categoryOptions' => $categoryOptions, 'upload' => $upload])

      $client            App\Models\Crm\Client
      $categoryOptions   array<string, string>  ClientDocumentCategory::options()
      $upload            array{extensions: list<string>, max_kb: int, visible_default: bool}  what StoreClientDocumentRequest
                         enforces (security.allowed_file_types narrowed by the document types, security.max_upload_mb,
                         crm.client_visible_documents_default)

    Posts (StoreClientDocumentRequest, multipart): file, title, category, description, valid_from, expires_at,
    visible_to_client. The default of the switch follows the category (ClientDocumentCategory::defaultVisibleToClient())
    and falls back to crm.client_visible_documents_default.
--}}

@php
    $uploadCategories = (array) ($categoryOptions ?? (enum_exists(\App\Enums\ClientDocumentCategory::class) ? \App\Enums\ClientDocumentCategory::options() : []));
    $uploadHints = (array) ($upload ?? []);
    $uploadExtensions = array_values(array_filter((array) ($uploadHints['extensions'] ?? []), static fn ($extension): bool => is_string($extension) && $extension !== ''));
    $uploadMaxKb = (int) ($uploadHints['max_kb'] ?? 0);
    $uploadVisibleDefault = (bool) ($uploadHints['visible_default'] ?? false);
    $uploadCategoryDefaults = [];

    if (enum_exists(\App\Enums\ClientDocumentCategory::class) && method_exists(\App\Enums\ClientDocumentCategory::class, 'defaultVisibleToClient')) {
        foreach (\App\Enums\ClientDocumentCategory::cases() as $case) {
            $uploadCategoryDefaults[$case->value] = (bool) $case->defaultVisibleToClient();
        }
    }

    $uploadHint = collect([
        $uploadExtensions !== [] ? strtoupper(implode(', ', $uploadExtensions)) : null,
        $uploadMaxKb > 0 ? 'up to '.($uploadMaxKb >= 1024 ? app_number($uploadMaxKb / 1024, 1).' MB' : app_number($uploadMaxKb).' KB') : null,
    ])->filter()->implode(' · ');
    $uploadAccept = $uploadExtensions !== [] ? collect($uploadExtensions)->map(static fn ($extension): string => '.'.ltrim((string) $extension, '.'))->implode(',') : null;
    $uploadFieldClass = 'mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white';
@endphp

<x-ui.card title="Upload a document" subtitle="Stored privately. Only people allowed to see this client's documents can download it." icon="arrow-up-tray">
    <form
        method="POST"
        action="{{ route('admin.clients.documents.store', $client) }}"
        enctype="multipart/form-data"
        class="space-y-4"
        x-data="{
            category: {{ \Illuminate\Support\Js::from((string) old('category', array_key_first($uploadCategories) ?? '')) }},
            defaults: {{ \Illuminate\Support\Js::from((object) $uploadCategoryDefaults) }},
            fallback: {{ \Illuminate\Support\Js::from($uploadVisibleDefault) }},
            visible: {{ \Illuminate\Support\Js::from(old('visible_to_client') !== null ? (bool) old('visible_to_client') : null) }},
            busy: false,
            init() { if (this.visible === null) { this.visible = this.fallback || (this.defaults[this.category] ?? false); } },
        }"
        x-on:submit="busy = true"
    >
        @csrf
        <x-ui.form.file name="file" label="File" required icon="paper-clip" :accept="$uploadAccept" :hint="$uploadHint" />
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.form.input name="title" label="Title" required maxlength="150" />
            <div>
                <label for="document-category" class="flex items-center gap-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">Category <span class="text-rose-500" aria-hidden="true">*</span></label>
                <select id="document-category" name="category" x-model="category" x-on:change="visible = fallback || (defaults[category] ?? false)" required class="{{ $uploadFieldClass }}">
                    @foreach ($uploadCategories as $categoryValue => $categoryLabel)
                        <option value="{{ $categoryValue }}">{{ $categoryLabel }}</option>
                    @endforeach
                </select>
                <x-ui.form.error for="category" />
            </div>
            <x-ui.form.input name="description" label="Description" optional maxlength="255" class="sm:col-span-2" />
            <x-ui.form.input name="valid_from" type="date" label="Valid from" optional />
            <x-ui.form.input name="expires_at" type="date" label="Expires" optional />
        </div>
        <div class="rounded-lg p-3 ring-1" x-bind:class="visible ? 'bg-amber-50 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/25' : 'ring-slate-200 dark:ring-slate-700'">
            <input type="hidden" name="visible_to_client" x-bind:value="visible ? 1 : 0" value="0">
            <label class="flex items-start gap-3 text-sm">
                <input type="checkbox" x-model="visible" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                <span>
                    <span class="block font-medium text-slate-900 dark:text-white">Visible to the client</span>
                    <span class="text-slate-600 dark:text-slate-300" x-show="visible">The client will see this document in their portal and be notified immediately.</span>
                    <span class="text-slate-500 dark:text-slate-400" x-show="! visible">Only staff can see it. You can share it later.</span>
                </span>
            </label>
        </div>
        <div class="flex justify-end">
            <x-ui.button type="submit" icon="arrow-up-tray" x-bind:disabled="busy">Upload</x-ui.button>
        </div>
    </form>
</x-ui.card>
