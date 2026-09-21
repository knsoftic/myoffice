@extends('layouts.admin')

@section('title', 'Payment methods')

@php
    $contextLabels = [
        'invoice' => 'Invoices',
        'project_payment' => 'Project payments',
        'student_fee' => 'Student fees',
        'expense' => 'Expenses',
        'income' => 'Other income',
        'payout' => 'Partner payouts',
    ];

    $grouped = $methods->groupBy(fn ($method) => $method->type->value);
@endphp

@section('header')
    <x-ui.page-header title="Payment methods"
                      subtitle="How money moves, and which forms may offer it. The enum on a payment row is the snapshot of record — this is the presentation around it."
                      icon="credit-card">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button variant="primary" :href="route('admin.payment-methods.create')" icon="plus">Add a method</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
        Renaming a method changes every dropdown and no history: a payment made last year by a method the
        business has since stopped offering is still a payment made by that method. A method with money
        recorded against it cannot be deleted — switch it off instead.
    </div>

    @forelse ($grouped as $typeValue => $group)
        @php $type = \App\Enums\PaymentMethodType::from($typeValue); @endphp

        <x-ui.section-heading :title="$type->label()" class="mt-6 first:mt-0" />

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($group as $method)
                @php
                    $usage = (int) $method->project_payments_count + (int) $method->student_fee_payments_count
                        + (int) $method->expenses_count + (int) $method->incomes_count;
                @endphp

                <x-ui.card :hover="true">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="truncate font-semibold text-slate-900 dark:text-white">{{ $method->name }}</h3>
                            <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $method->code->value }}</span>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center justify-end gap-1">
                            @if ($method->is_default)
                                <x-ui.badge color="brand" size="xs">default</x-ui.badge>
                            @endif
                            <x-ui.badge :color="$method->is_active ? 'emerald' : 'slate'" size="xs">
                                {{ $method->is_active ? 'active' : 'off' }}
                            </x-ui.badge>
                        </div>
                    </div>

                    @if (filled($method->description))
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $method->description }}</p>
                    @endif

                    <div class="mt-3 flex flex-wrap gap-1">
                        @foreach ((array) $method->usable_for as $context)
                            <x-ui.badge color="slate" size="xs">{{ $contextLabels[$context] ?? $context }}</x-ui.badge>
                        @endforeach
                    </div>

                    <div class="mt-3 flex flex-wrap gap-1">
                        @if ($method->requires_reference)
                            <x-ui.badge color="amber" size="xs">reference required</x-ui.badge>
                        @endif
                        @if ($method->supports_refund)
                            <x-ui.badge color="sky" size="xs">refundable</x-ui.badge>
                        @endif
                        @if ($method->is_online)
                            <x-ui.badge color="violet" size="xs">
                                {{ $method->gateway_driver ?: 'gateway' }}{{ $method->is_test_mode ? ' · test' : '' }}
                            </x-ui.badge>
                        @endif
                    </div>

                    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                        {{ $usage === 0
                            ? 'Nothing recorded under it yet.'
                            : $usage.' '.\Illuminate\Support\Str::plural('row', $usage).' recorded under it.' }}
                    </p>

                    <x-slot:footer>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($canEdit)
                                <x-ui.button size="sm" variant="secondary"
                                             :href="route('admin.payment-methods.edit', $method)">Edit</x-ui.button>
                            @endif

                            @if ($canChangeStatus)
                                <form method="POST" action="{{ route('admin.payment-methods.toggle', $method) }}"
                                      class="flex items-center gap-2">
                                    @csrf
                                    <input type="hidden" name="active" value="{{ $method->is_active ? 0 : 1 }}">
                                    @if ($method->is_active)
                                        <input type="text" name="reason" required maxlength="255"
                                               placeholder="Why switch it off"
                                               class="w-36 rounded-lg border-slate-300 text-xs dark:border-slate-700 dark:bg-slate-900">
                                    @endif
                                    <x-ui.button type="submit" size="sm" variant="ghost">
                                        {{ $method->is_active ? 'Switch off' : 'Switch on' }}
                                    </x-ui.button>
                                </form>
                            @endif

                            @if ($canEdit && ! $method->is_default && $method->is_active)
                                <form method="POST" action="{{ route('admin.payment-methods.default', $method) }}">
                                    @csrf
                                    <x-ui.button type="submit" size="sm" variant="ghost">Make default</x-ui.button>
                                </form>
                            @endif

                            @can('payment_methods.delete')
                                @if ($usage > 0)
                                    <span title="{{ $usage }} rows are recorded under it — switch it off instead">
                                        <x-ui.button size="sm" variant="ghost" :disabled="true">Delete</x-ui.button>
                                    </span>
                                @else
                                    <x-ui.confirm :action="route('admin.payment-methods.destroy', $method)"
                                                  title="Remove {{ $method->name }}?"
                                                  message="Nothing has been recorded under it, so nothing is lost."
                                                  confirm-label="Remove it">
                                        <x-slot:trigger>
                                            <x-ui.button size="sm" variant="ghost">Delete</x-ui.button>
                                        </x-slot:trigger>
                                    </x-ui.confirm>
                                @endif
                            @endcan
                        </div>
                    </x-slot:footer>
                </x-ui.card>
            @endforeach
        </div>
    @empty
        <x-ui.card>
            <x-ui.empty-state icon="credit-card"
                              title="No payment methods configured"
                              message="The seeder ships four. If this screen is empty, run the finance seeders." />
        </x-ui.card>
    @endforelse
@endsection
