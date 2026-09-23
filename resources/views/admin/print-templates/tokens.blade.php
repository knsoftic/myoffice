@extends('layouts.admin')

@section('title', 'Tokens — '.$template->name)

@section('header')
    <x-ui.page-header title="Tokens"
                      :subtitle="$template->name.' — every {token} a '.mb_strtolower($template->type->label()).' may use.'"
                      icon="code-bracket">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.print-templates.edit', $template)">Back to the editor</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($unknown !== [])
        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
            <p class="font-medium">This template uses tokens that do not exist, so they print nothing:</p>
            <p class="mt-1 font-mono text-xs">
                {{ collect($unknown)->map(fn (string $token): string => '{'.$token.'}')->implode('  ') }}
            </p>
        </div>
    @endif

    <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
        A token is replaced once, by exact text. Anything else in single braces is left alone and prints
        nothing. The highlighted ones are the tokens this template already uses.
    </p>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($tokens as $group => $groupTokens)
            <x-ui.card>
                <x-ui.section-heading :title="$groups[$group] ?? $group" />

                <x-ui.table :is-empty="false" class="mt-2">
                    <x-slot:head>
                        <th class="px-3 py-2 text-left font-semibold">Token</th>
                        <th class="px-3 py-2 text-left font-semibold">What it prints</th>
                        <th class="px-3 py-2 text-left font-semibold">Example</th>
                    </x-slot:head>

                    @foreach ($groupTokens as $token => $spec)
                        <tr @class(['bg-brand-50/60 dark:bg-brand-500/5' => in_array($token, $used, true)])>
                            <td class="px-3 py-2 font-mono text-xs text-slate-700 dark:text-slate-200">
                                {{ '{'.$token.'}' }}
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-600 dark:text-slate-300">
                                {{ $spec['label'] }}
                                @if ($spec['formatter'] === 'raw')
                                    {{-- Worth saying on the reference page: these are images and tables
                                         this application builds, never anything anybody typed. --}}
                                    <span class="text-slate-400">— an image or a table, inserted as-is</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">{{ $spec['example'] }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endforeach
    </div>
@endsection
