@extends('layouts.admin')

@section('title', 'Referral links · ' . $collaborator->displayName())

@section('header')
    <x-ui.page-header title="Referral links" :subtitle="$collaborator->displayName() . ' · ' . $collaborator->referral_code" icon="link">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.collaborators.show', $collaborator)">Back to the record</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="The two links" subtitle="Anyone arriving through one of these is attributed to this partner.">
                <div class="space-y-4" x-data="{ copied: null }">
                    @foreach ([['Student admission', $urls['admission']], ['Client / project inquiry', $urls['inquiry']]] as [$label, $url])
                        <div>
                            <span class="block text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $label }}</span>
                            <div class="mt-1 flex items-center gap-2">
                                <input type="text" readonly value="{{ $url }}"
                                    class="w-full rounded-lg border-slate-300 bg-slate-50 font-mono text-xs text-slate-800 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200"
                                    x-ref="url{{ $loop->index }}">
                                <x-ui.button variant="secondary" size="sm" icon="clipboard"
                                    x-on:click="navigator.clipboard.writeText($refs.url{{ $loop->index }}.value); copied = {{ $loop->index }}">
                                    <span x-text="copied === {{ $loop->index }} ? 'Copied' : 'Copy'">Copy</span>
                                </x-ui.button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card title="A link to any other page"
                       subtitle="Send people straight to a course, a service or the pricing page and keep the attribution.">
                <div x-data="{ path: '/courses' }" class="space-y-3">
                    <x-ui.form.input name="path" label="Path on this site" x-model="path" placeholder="/courses/php-basics" />
                    <div class="rounded-lg bg-slate-50 p-3 font-mono text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200"
                         x-text="'{{ $baseUrl }}' + (path.startsWith('/') ? path : '/' + path) + (path.includes('?') ? '&' : '?') + '{{ $queryParam }}={{ $collaborator->referral_code }}'">
                    </div>
                    <x-ui.form.help>
                        An existing query string is kept, so a campaign link such as
                        <code>/courses?utm_source=flyer</code> gains the referral alongside its own parameters.
                    </x-ui.form.help>
                </div>
            </x-ui.card>
        </div>

        <x-ui.card title="How attribution works">
            <ul class="space-y-3 text-sm text-slate-600 dark:text-slate-300">
                <li>A click is recorded as a <strong>visit</strong>, and the browser carries only an opaque token —
                    never the code itself, so a forged value can at worst name a visit that does not exist.</li>
                <li>The attribution lasts
                    <strong>{{ app_number((float) setting('collaborator.referral_cookie_days', 30), 0) }} days</strong>,
                    which is long enough to survive an admission that takes a few visits to finish.</li>
                <li>A staff member's explicit pick always outranks a captured code, and the losing candidate is kept
                    with its reason rather than discarded.</li>
                <li>Commission follows money actually received — never a click, and never a registration.</li>
            </ul>
        </x-ui.card>
    </div>
@endsection
