@extends('layouts.admin')

@section('title', 'New print template')

@section('header')
    <x-ui.page-header title="New print template"
                      :subtitle="$type->description()"
                      icon="document-duplicate">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.print-templates.index')">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    {{--
        The kind is chosen here, before the form, because it decides which tokens exist. A select
        inside the form would either need the page to reload on change — losing whatever had been
        typed — or would show a token list belonging to a different document.
    --}}
    <x-ui.card class="mb-4">
        <x-ui.section-heading title="What are you designing?"
                              subtitle="This decides the token list and the paper the form starts on. It cannot be changed afterwards." />

        <div class="mt-3 grid gap-3 sm:grid-cols-3">
            @foreach ($types as $option)
                <a href="{{ route('admin.print-templates.create', ['type' => $option->value]) }}"
                   @class([
                       'block rounded-lg border p-3 transition',
                       'border-brand-500 bg-brand-50 ring-1 ring-brand-500 dark:bg-brand-500/10' => $option === $type,
                       'border-slate-200 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800' => $option !== $type,
                   ])>
                    <div class="flex items-center gap-2">
                        <x-ui.badge :color="$option->color()" size="xs">{{ $option->label() }}</x-ui.badge>
                        @if ($option === $type)
                            <span class="text-2xs font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-300">Chosen</span>
                        @endif
                    </div>
                    <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ $option->description() }}</p>
                </a>
            @endforeach
        </div>
    </x-ui.card>

    <form method="POST" action="{{ route('admin.print-templates.store') }}">
        @csrf

        @include('admin.print-templates._form')

        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.print-templates.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Create template</x-ui.button>
        </div>
    </form>
@endsection
