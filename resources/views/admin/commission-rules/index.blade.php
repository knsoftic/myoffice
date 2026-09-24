@extends('layouts.admin')

@section('title', 'Commission rules · ' . $collaborator->displayName())

@php
    $canCreate = auth()->user()?->can('collaborator_commission_settings.create');
@endphp

@section('header')
    <x-ui.page-header :title="'Commission rules · ' . $collaborator->displayName()"
                      subtitle="A rate is never edited. Every change is a new version with its own start date."
                      icon="adjustments-horizontal">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.collaborators.show', $collaborator)" icon="arrow-left">
                Back to partner
            </x-ui.button>
            @if ($canCreate)
                <x-ui.button variant="primary" icon="plus" x-on:click="$dispatch('open-modal', 'new-rule-version')">
                    Add a version
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($scopes as $scope)
            @php
                $versions = $timelines[$scope->value];
                $open = $current[$scope->value];
            @endphp

            <x-ui.card :title="$scope->label() . ' commission'"
                       :subtitle="$open ? 'Version ' . $open->version . ' is in force' : 'No rule in force'">
                @if ($versions->isEmpty())
                    <x-ui.empty-state icon="adjustments-horizontal"
                        title="No commission rule yet"
                        description="This partner earns nothing on {{ $scope->value }} payments until a version exists. There is deliberately no fallback to a global default rate — paying a partner nobody configured is the one mistake that cannot be explained to a client." />
                @else
                    <ol class="relative space-y-4 border-l border-slate-200 pl-6 dark:border-slate-700">
                        @foreach ($versions as $version)
                            <li class="relative">
                                <span @class([
                                    'absolute -left-[1.6875rem] mt-1.5 h-3 w-3 rounded-full ring-4 ring-white dark:ring-slate-900',
                                    'bg-emerald-500' => $version->status->isCurrent(),
                                    'bg-slate-300 dark:bg-slate-600' => ! $version->status->isCurrent(),
                                ])></span>

                                <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-semibold text-slate-900 dark:text-white">Version {{ $version->version }}</span>
                                        <x-ui.badge :color="$version->status->color()" size="xs">{{ $version->status->label() }}</x-ui.badge>
                                        @unless ($version->is_enabled)
                                            <x-ui.badge color="rose" size="xs">commission off</x-ui.badge>
                                        @endunless
                                        @if ($version->status->isCurrent())
                                            <span class="ml-auto inline-flex items-center gap-1 text-xs text-slate-500 dark:text-slate-400"
                                                  title="A rate change is a new version — every entry that quoted this one has to keep meaning what it said.">
                                                <x-ui.icon name="lock-closed" class="h-3.5 w-3.5" /> locked
                                            </span>
                                        @endif
                                    </div>

                                    <p class="mt-2 text-sm text-slate-700 dark:text-slate-200">
                                        @if ($version->calculation_type === \App\Enums\CommissionCalculationType::Percentage)
                                            <strong>{{ rtrim(rtrim((string) $version->rate, '0'), '.') }}%</strong>
                                        @else
                                            <strong>{{ money($version->fixed_amount) }}</strong>
                                            <span class="text-slate-500">({{ $version->resolvedRelease()->label() }})</span>
                                        @endif
                                        on <strong>{{ $version->resolvedBase()->label() }}</strong>
                                    </p>

                                    <dl class="mt-2 space-y-1 text-xs text-slate-500 dark:text-slate-400">
                                        <div>In force {{ app_date($version->effective_from) }} →
                                            {{ $version->effective_to ? app_date($version->effective_to) : 'open' }}</div>
                                        @if ($version->min_payment_amount !== null)
                                            <div>Minimum payment {{ money($version->min_payment_amount) }}</div>
                                        @endif
                                        @if ($version->max_commission_amount !== null)
                                            <div>Capped at {{ money($version->max_commission_amount) }}</div>
                                        @endif
                                        @if (! empty($version->applies_to_fee_types))
                                            <div>Fee types: {{ implode(', ', $version->applies_to_fee_types) }}</div>
                                        @endif
                                        @if ($version->change_reason)
                                            <div class="text-slate-600 dark:text-slate-300">“{{ $version->change_reason }}”</div>
                                        @endif
                                    </dl>

                                    @if ($canCreate && $version->status->isCurrent() && $version->effective_to === null)
                                        <x-ui.button class="mt-3" variant="ghost" size="sm"
                                                     x-on:click="$dispatch('open-modal', 'close-rule-{{ $version->id }}')">
                                            Close this version
                                        </x-ui.button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        @endforeach
    </div>

    @if ($canCreate)
        @foreach ($scopes as $scope)
            @php $open = $current[$scope->value]; @endphp
            @if ($open)
                <x-ui.modal name="close-rule-{{ $open->id }}" :title="'Close version ' . $open->version">
                    <form method="POST" action="{{ route('admin.commission-rules.close', $open) }}">
                        @csrf
                        <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                            Closing ends this rule on a date. Payments dated after it earn nothing until a
                            new version starts. <strong>Nothing already posted changes.</strong>
                        </p>
                        <x-ui.form.input type="date" name="effective_to" label="Ends on" required
                                         :value="app_date(now(), 'Y-m-d')" />
                        <x-ui.form.textarea name="reason" label="Reason" required rows="2" />
                        <div class="mt-4 flex justify-end gap-2">
                            <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-modal', 'close-rule-{{ $open->id }}')">Cancel</x-ui.button>
                            <x-ui.button variant="danger" type="submit">Close it</x-ui.button>
                        </div>
                    </form>
                </x-ui.modal>
            @endif
        @endforeach

        <x-ui.modal name="new-rule-version" title="Add a commission rule version" size="lg">
            <form method="POST" action="{{ route('admin.commission-rules.store', $collaborator) }}"
                  x-data="rulePreview('{{ route('admin.commission-rules.preview', $collaborator) }}')">
                @csrf

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.form.select name="commission_for" label="Applies to" required x-model="form.commission_for">
                        @foreach ($scopes as $scope)
                            <option value="{{ $scope->value }}">{{ $scope->label() }} commission</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.select name="calculation_type" label="Type" required x-model="form.calculation_type">
                        @foreach ($calculationTypes as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <div x-show="form.calculation_type === 'percentage'">
                        <x-ui.form.input name="rate" label="Rate (%)" placeholder="10" x-model="form.rate" />
                    </div>

                    <div x-show="form.calculation_type === 'fixed'" x-cloak>
                        <x-ui.form.input name="fixed_amount" label="Fixed amount" placeholder="2000.00" x-model="form.fixed_amount" />
                    </div>

                    <div x-show="form.calculation_type === 'fixed'" x-cloak>
                        <x-ui.form.select name="fixed_release" label="Released" x-model="form.fixed_release"
                                          placeholder="Use the business default">
                            @foreach ($releases as $release)
                                <option value="{{ $release->value }}">{{ $release->label() }}</option>
                            @endforeach
                        </x-ui.form.select>
                    </div>

                    <x-ui.form.select name="base_override" label="Base" placeholder="Use the business default"
                                      x-model="form.base_override">
                        @foreach ($bases as $base)
                            <option value="{{ $base->value }}">{{ $base->label() }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.input name="min_payment_amount" label="Minimum payment" placeholder="optional" />
                    <x-ui.form.input name="max_commission_amount" label="Cap" placeholder="optional"
                                     x-model="form.max_commission_amount" />

                    <x-ui.form.input type="date" name="effective_from" label="Starts on" required
                                     :value="app_date(now(), 'Y-m-d')" />

                    <div class="sm:col-span-2">
                        <x-ui.form.textarea name="reason" label="Reason" required rows="2"
                                            placeholder="Why the rate is changing — this is what the timeline shows beside it, for ever" />
                    </div>
                </div>

                {{-- Step 4: the same receipt priced both ways, from the engine's own calculator. --}}
                <div class="mt-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="w-40">
                            <x-ui.form.input name="preview_amount" label="Preview a receipt of" value="10000.00"
                                             x-model="form.amount" />
                        </div>
                        <x-ui.button variant="secondary" type="button" size="sm" x-on:click="run()">Compare</x-ui.button>
                    </div>

                    <template x-if="result">
                        <div class="mt-3 space-y-1 text-sm">
                            <p class="text-slate-700 dark:text-slate-200">
                                Under the rule in force today:
                                <strong x-text="result.current ? (result.current.formatted ?? result.current.detail) : 'there is no rule yet'"></strong>
                            </p>
                            <p class="text-slate-700 dark:text-slate-200">
                                Under this version:
                                <strong x-text="result.proposed.formatted ?? result.proposed.detail"></strong>
                            </p>
                            <p class="text-xs text-slate-500 dark:text-slate-400" x-text="result.note"></p>
                        </div>
                    </template>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('close-modal', 'new-rule-version')">Cancel</x-ui.button>
                    <x-ui.button variant="primary" type="submit">Save version</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection

@push('scripts')
    <script nonce="{{ csp_nonce() }}">
        function rulePreview(url) {
            return {
                result: null,
                form: {
                    commission_for: 'student',
                    calculation_type: 'percentage',
                    rate: '10',
                    fixed_amount: null,
                    fixed_release: null,
                    base_override: null,
                    max_commission_amount: null,
                    amount: '10000.00',
                },

                async run() {
                    const params = new URLSearchParams();

                    Object.entries(this.form).forEach(([key, value]) => {
                        if (value !== null && value !== '') params.append(key, value);
                    });

                    const response = await fetch(url + '?' + params.toString(), {
                        headers: { 'Accept': 'application/json' },
                    });

                    this.result = response.ok ? await response.json() : null;
                },
            };
        }
    </script>
@endpush
