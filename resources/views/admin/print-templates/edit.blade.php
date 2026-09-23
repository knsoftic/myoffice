@extends('layouts.admin')

@section('title', 'Edit '.$template->name)

@section('header')
    <x-ui.page-header :title="$template->name"
                      subtitle="HTML with {tokens} in it. Preview it before anything is printed with it."
                      icon="document-duplicate">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="eye" target="_blank"
                         :href="route('admin.print-templates.preview', $template)">Preview</x-ui.button>
            <x-ui.button variant="ghost" icon="code-bracket" target="_blank"
                         :href="route('admin.print-templates.tokens', $template)">Tokens</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.print-templates.show', $template)">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($unknownTokens !== [])
        {{--
            Shown on the way in, not only after a save: a template that has been carrying a typo for
            a month is printing a blank line every time, and nobody sees the save-time warning again.
        --}}
        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
            <p class="font-medium">These print nothing, because this kind of template has no such token:</p>
            <p class="mt-1 font-mono text-xs">
                {{ collect($unknownTokens)->map(fn (string $token): string => '{'.$token.'}')->implode('  ') }}
            </p>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.print-templates.update', $template) }}">
        @csrf
        @method('PUT')

        @include('admin.print-templates._form')

        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.print-templates.show', $template)">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Save changes</x-ui.button>
        </div>
    </form>
@endsection
