@extends('layouts.admin')

@section('title', $template->name)

@section('header')
    <x-ui.page-header :title="$template->name"
                      :subtitle="$template->description ?: $template->type->description()"
                      icon="document-duplicate">
        <x-slot:actions>
            @if ($canPreview)
                <x-ui.button variant="ghost" icon="eye" target="_blank"
                             :href="route('admin.print-templates.preview', $template)">Preview</x-ui.button>
            @endif
            <x-ui.button variant="ghost" icon="code-bracket"
                         :href="route('admin.print-templates.tokens', $template)">Tokens</x-ui.button>
            @if ($canEdit)
                <x-ui.button icon="pencil-square" :href="route('admin.print-templates.edit', $template)">Edit</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card>
                <x-ui.section-heading title="The layout" subtitle="Exactly what is stored, after sanitising." />

                <pre class="mt-3 max-h-96 overflow-auto rounded-lg bg-slate-50 p-3 font-mono text-xs text-slate-700 dark:bg-slate-900 dark:text-slate-300">{{ $template->body_html }}</pre>

                @if (filled($template->custom_css))
                    <h3 class="mt-4 text-2xs font-semibold uppercase tracking-wide text-slate-400">Stylesheet</h3>
                    <pre class="mt-1 max-h-60 overflow-auto rounded-lg bg-slate-50 p-3 font-mono text-xs text-slate-700 dark:bg-slate-900 dark:text-slate-300">{{ $template->custom_css }}</pre>
                @endif
            </x-ui.card>

            <x-ui.card>
                <x-ui.section-heading title="Tokens it may use"
                                      subtitle="Anything else in braces prints nothing. The ones this template actually uses are marked." />

                @php($used = (array) ($template->tokens_used ?? []))

                <div class="mt-3 space-y-4">
                    @foreach ($tokens as $group => $groupTokens)
                        <div>
                            <h3 class="mb-1 text-2xs font-semibold uppercase tracking-wide text-slate-400">
                                {{ \App\Support\PrintTokenRegistry::GROUPS[$group] ?? $group }}
                            </h3>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($groupTokens as $token => $spec)
                                    <span @class([
                                        'rounded border px-1.5 py-0.5 font-mono text-2xs',
                                        'border-brand-300 bg-brand-50 text-brand-700 dark:border-brand-500/40 dark:bg-brand-500/10 dark:text-brand-300' => in_array($token, $used, true),
                                        'border-slate-200 bg-slate-50 text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400' => ! in_array($token, $used, true),
                                    ]) title="{{ $spec['label'] }} — e.g. {{ $spec['example'] }}">{{ '{'.$token.'}' }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card>
                <x-ui.section-heading title="Details" />

                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Code</dt>
                        <dd class="font-mono text-xs text-slate-700 dark:text-slate-200">{{ $template->code }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Kind</dt>
                        <dd><x-ui.badge :color="$template->type->color()" size="xs">{{ $template->type->label() }}</x-ui.badge></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Paper</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            @php([$w, $h] = $template->dimensionsMm())
                            {{ $template->paper_size->label() }} · {{ app_number($w, 0) }} × {{ app_number($h, 0) }} mm
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Margin</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ app_number($template->margin_mm, 2) }} mm</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">QR code</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            {{ $template->show_qr ? app_number($template->qr_size_mm, 2).' mm' : 'Not printed' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Branch</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $template->branch?->name ?? 'Every branch' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Status</dt>
                        <dd class="flex gap-1">
                            @if ($template->is_default)
                                <x-ui.badge color="brand" size="xs">Default</x-ui.badge>
                            @endif
                            <x-ui.badge :color="$template->is_active ? 'emerald' : 'slate'" size="xs">
                                {{ $template->is_active ? 'In use' : 'Retired' }}
                            </x-ui.badge>
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card>
                <x-ui.section-heading title="Printed with it"
                                      subtitle="A template with documents behind it is retired, never deleted — they have to stay reprintable exactly as they were." />

                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Certificates</dt>
                        <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($usedBy['certificates']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Student cards</dt>
                        <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($usedBy['cards']) }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            @if ($canChangeStatus || $canDelete || $canCreate)
                <x-ui.card>
                    <x-ui.section-heading title="Actions" />

                    <div class="mt-3 space-y-3">
                        {{-- Duplicating is how a redesign starts: the copy is retired and is nobody's
                             default, so nothing it prints reaches a student until somebody says so. --}}
                        @if ($canCreate)
                            <form method="POST" action="{{ route('admin.print-templates.duplicate', $template) }}">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" icon="document-duplicate" class="w-full">
                                    Duplicate it
                                </x-ui.button>
                            </form>
                        @endif

                        @if ($canChangeStatus && ! $template->is_default && $template->is_active)
                            <form method="POST" action="{{ route('admin.print-templates.default', $template) }}">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" icon="star" class="w-full">
                                    Make this the default
                                </x-ui.button>
                            </form>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Documents that name no template of their own will print on it. Whichever
                                {{ mb_strtolower($template->type->label()) }} template is the default now stops being it.
                            </p>
                        @endif

                        @if ($canChangeStatus && $template->is_active)
                            <x-ui.button type="button" variant="secondary" icon="archive-box" class="w-full"
                                         x-on:click="$dispatch('open-modal', 'retire-template')">Retire</x-ui.button>
                        @endif

                        @if ($canDelete)
                            <x-ui.confirm :action="route('admin.print-templates.destroy', $template)"
                                          title="Delete {{ $template->name }}?"
                                          message="Only a template nothing has been printed with can be deleted. If anything has, retire it instead."
                                          confirm-label="Delete template">
                                <x-slot:trigger>
                                    <x-ui.button type="button" variant="danger" icon="trash" class="w-full">Delete</x-ui.button>
                                </x-slot:trigger>
                            </x-ui.confirm>
                        @endif
                    </div>
                </x-ui.card>

                @if ($canChangeStatus && $template->is_active)
                    <x-ui.modal name="retire-template" title="Retire this template?" icon="archive-box">
                        <form method="POST" action="{{ route('admin.print-templates.deactivate', $template) }}">
                            @csrf

                            <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">
                                It stops being offered for new documents. Everything already printed with it can
                                still be reprinted exactly as it was — that is the whole reason this is a retirement
                                and not a delete.
                            </p>

                            <x-ui.form.input name="reason" label="Why" required
                                             placeholder="Replaced by the 2027 design" />

                            <div class="mt-4 flex justify-end gap-2">
                                <x-ui.button type="button" variant="ghost"
                                             x-on:click="$dispatch('close-modal', 'retire-template')">Keep it</x-ui.button>
                                <x-ui.button type="submit" variant="danger" icon="archive-box">Retire it</x-ui.button>
                            </div>
                        </form>
                    </x-ui.modal>
                @endif
            @endif
        </div>
    </div>
@endsection
