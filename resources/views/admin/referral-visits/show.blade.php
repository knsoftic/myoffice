@extends('layouts.admin')

@section('title', 'Referral visit')

@php
    $controller = app(\App\Http\Controllers\Admin\Collaborator\ReferralVisitController::class);
@endphp

@section('header')
    <x-ui.page-header title="Referral visit" :subtitle="$visit->referral_code . ' · ' . app_datetime($visit->first_seen_at)" icon="cursor-arrow-rays">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.referral-visits.index')">Back to the register</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="What happened">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Outcome</dt>
                        <dd class="mt-1">
                            <x-ui.badge :color="$visit->outcome->color()" size="xs">{{ $visit->outcome->label() }}</x-ui.badge>
                            @if ($visit->outcome_detail)
                                <span class="mt-1 block text-sm text-slate-600 dark:text-slate-300">{{ $visit->outcome_detail }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Code used</dt>
                        <dd class="mt-1 font-mono text-sm text-slate-900 dark:text-white">{{ $visit->referral_code }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Collaborator</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">
                            @if ($visit->collaborator)
                                <a href="{{ route('admin.collaborators.show', $visit->collaborator) }}" class="hover:text-brand-700 dark:hover:text-brand-300">
                                    {{ $visit->collaborator->displayName() }}
                                </a>
                            @else
                                Nobody — the code resolved to no partner.
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Clicks</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $visit->visits_count }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">First seen</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ app_datetime($visit->first_seen_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Last seen</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ app_datetime($visit->last_seen_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Attribution ends</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">
                            {{ app_datetime($visit->expires_at) }}
                            @if ($visit->hasExpired())
                                <x-ui.badge color="slate" size="xs">expired</x-ui.badge>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Converted</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">
                            @if ($visit->converted_at)
                                {{ $visit->converted_subject_type?->label() }} #{{ $visit->converted_subject_id }}
                                · {{ app_datetime($visit->converted_at) }}
                            @else
                                Not yet.
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Where they landed">
                <dl class="space-y-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">URL</dt>
                        <dd class="mt-1 break-all font-mono text-xs text-slate-700 dark:text-slate-300">{{ $visit->landing_url }}</dd>
                    </div>
                    @if ($visit->referer_url)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Came from</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-slate-700 dark:text-slate-300">{{ $visit->referer_url }}</dd>
                        </div>
                    @endif
                    @if ($visit->query_string)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Query string</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-slate-700 dark:text-slate-300">{{ $visit->query_string }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card title="The visitor">
                <dl class="space-y-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Device</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">
                            {{ $visit->device }} · {{ $visit->platform }} · {{ $visit->browser }}
                            @if ($visit->is_bot)
                                <x-ui.badge color="slate" size="xs">crawler</x-ui.badge>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">IP</dt>
                        <dd class="mt-1 font-mono text-sm text-slate-900 dark:text-white">
                            {{ $controller->ip($visit, $seesRawIp) ?? '—' }}
                        </dd>
                        @unless ($seesRawIp)
                            <dd class="text-xs text-slate-500 dark:text-slate-400">Masked to a /24 range.</dd>
                        @endunless
                    </div>
                    @if ($visit->user)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Signed in as</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $visit->user->name }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            <x-ui.card title="Why there are no actions">
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    A click is evidence. Nothing on this screen can be edited or removed, because a register
                    somebody could correct would stop being worth anything in the one conversation it exists
                    for — a partner asking why a student was not credited to them.
                </p>
            </x-ui.card>
        </div>
    </div>
@endsection
