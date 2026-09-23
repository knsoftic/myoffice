@extends('layouts.admin')

@section('title', 'Print templates')

@section('header')
    <x-ui.page-header title="Print templates"
                      subtitle="The layout of every certificate, student card and result card. One of each is the default, and a document that names no template of its own prints on it."
                      icon="document-duplicate">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.print-templates.create')">New template</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Name or code" />

            <x-ui.form.select name="type" label="Kind" placeholder="Any kind">
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected(request('type') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                <option value="active" @selected(request('status') === 'active')>In use</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Retired</option>
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.print-templates.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$templates->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Template</th>
                <th class="px-4 py-3 text-left font-semibold">Kind</th>
                <th class="px-4 py-3 text-left font-semibold">Paper</th>
                <th class="px-4 py-3 text-left font-semibold">Branch</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($templates as $template)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.print-templates.show', $template) }}"
                           class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $template->name }}</a>
                        <div class="font-mono text-xs text-slate-400">{{ $template->code }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$template->type->color()" size="xs">{{ $template->type->label() }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $template->paper_size->label() }}
                        <div class="text-xs text-slate-400">
                            {{ $template->orientation->label() }} ·
                            @php([$w, $h] = $template->dimensionsMm())
                            {{ app_number($w, 0) }} × {{ app_number($h, 0) }} mm
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $template->branch?->name ?? 'Every branch' }}
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-1">
                            @if ($template->is_default)
                                <x-ui.badge color="brand" size="xs">Default</x-ui.badge>
                            @endif

                            <x-ui.badge :color="$template->is_active ? 'emerald' : 'slate'" size="xs">
                                {{ $template->is_active ? 'In use' : 'Retired' }}
                            </x-ui.badge>

                            @if ($template->trashed())
                                <x-ui.badge color="rose" size="xs">Deleted</x-ui.badge>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="document-duplicate" title="No templates yet"
                                  message="A template is HTML with {tokens} in it. Create one for each document you print, and mark one of each kind as the default.">
                    @if ($canCreate)
                        <x-slot:action>
                            <x-ui.button icon="plus" :href="route('admin.print-templates.create')">New template</x-ui.button>
                        </x-slot:action>
                    @endif
                </x-ui.empty-state>
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$templates" label="templates" />
    </x-ui.card>
@endsection
