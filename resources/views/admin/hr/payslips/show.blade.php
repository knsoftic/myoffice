@extends('layouts.admin')

@section('title', $item->slip_number)

@php
    $canPrint = auth()->user()?->can('print', $item) === true;
    $canCorrect = auth()->user()?->can('correct', $item) === true;
@endphp

@section('header')
    <x-ui.page-header :title="$item->slip_number" :subtitle="$employee?->name . ' · ' . $run?->periodLabel()" icon="document-text">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.payslips.index')">All slips</x-ui.button>
            @if ($canPrint)
                <x-ui.button variant="ghost" :href="route('admin.payslips.print', $item)" icon="printer">Print</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($item->corrects_item_id)
        <x-ui.card class="mb-4">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                This is a <strong class="font-semibold text-slate-900 dark:text-white">correction</strong> to slip
                #{{ $item->corrects_item_id }}. The original is untouched, for ever — reporting sums both.
            </p>
        </x-ui.card>
    @endif

    <x-ui.card>
        @include('admin.hr.payslips._slip')
    </x-ui.card>

    @if ($canCorrect)
        <x-ui.card class="mt-4" title="Issue a correction" subtitle="A new slip on a correction run; this one never changes.">
            <form method="POST" action="{{ route('admin.payroll-items.correction', $item) }}" class="grid gap-3 sm:grid-cols-4">
                @csrf
                <x-ui.form.select name="salary_component_id" label="Component" required placeholder="Choose one">
                    @foreach (\App\Models\Hr\SalaryComponent::query()->where('is_active', true)->orderBy('name')->get() as $component)
                        <option value="{{ $component->id }}">{{ $component->name }}</option>
                    @endforeach
                </x-ui.form.select>
                <x-ui.form.select name="side" label="Direction" required
                    :options="['earning' => 'Pay more', 'deduction' => 'Take back']" selected="earning" />
                <x-ui.form.input type="number" step="0.01" min="0.01" name="amount" label="Amount" required />
                <x-ui.form.input name="reason" label="Reason" required maxlength="255" />
                <div class="sm:col-span-4">
                    <x-ui.button type="submit" variant="secondary" icon="plus">Issue correction</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif
@endsection
