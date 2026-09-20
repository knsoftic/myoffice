@extends('layouts.admin')

@section('title', 'Leave types')

@php
    $user = auth()->user();
    $canCreate = (bool) $user?->can('leave_types.create');
    $canEdit = (bool) $user?->can('leave_types.edit');
    $canToggle = (bool) $user?->can('leave_types.change_status');
    $canDelete = (bool) $user?->can('leave_types.delete');
@endphp

@section('header')
    <x-ui.page-header title="Leave types" subtitle="Quotas, carry-forward and approval levels — all data, not code." icon="tag" />
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.card title="All leave types" :subtitle="$types->count() . ' defined'">
                <x-ui.table :is-empty="$types->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Type</th>
                        <th class="px-4 py-3 text-right font-semibold">Quota</th>
                        <th class="px-4 py-3 text-left font-semibold">Rules</th>
                        <th class="px-4 py-3 text-right font-semibold">Used by</th>
                        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </x-slot:head>

                    @foreach ($types as $type)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="block font-medium text-slate-900 dark:text-white">{{ $type->name }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $type->code }} · {{ $type->is_paid ? 'paid' : 'unpaid' }}
                                    @unless ($type->is_active) · retired @endunless
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                {{ app_number((float) $type->annual_quota_days, 2) }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                                <span class="block">{{ $type->accrual_method->label() }} · {{ $type->approval_levels }} level(s)</span>
                                <span class="block text-slate-500 dark:text-slate-400">
                                    @if ($type->excludes_weekends) skips weekends @endif
                                    @if ($type->excludes_holidays) · skips holidays @endif
                                    @if ($type->allow_half_day) · half days @endif
                                    @if ($type->carry_forward_enabled) · carries {{ app_number((float) $type->max_carry_forward_days, 2) }} @endif
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $type->requests_count }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($canEdit)
                                        <x-ui.button variant="ghost" size="sm" icon="pencil"
                                            x-on:click="$dispatch('open-modal', 'leave-type-{{ $type->id }}')">Edit</x-ui.button>
                                    @endif
                                    @if ($canToggle)
                                        <form method="POST" action="{{ route('admin.leave-types.toggle', $type) }}">
                                            @csrf
                                            <x-ui.button type="submit" variant="ghost" size="sm">{{ $type->is_active ? 'Retire' : 'Restore' }}</x-ui.button>
                                        </form>
                                    @endif
                                    @if ($canDelete)
                                        <x-ui.confirm
                                            :action="route('admin.leave-types.destroy', $type)"
                                            title="Remove {{ $type->name }}?"
                                            message="Refused once anybody has taken it — retire it instead so the history keeps naming what was taken."
                                            confirm-label="Remove type"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="trash" label="Remove leave type" variant="danger" />
                                            </x-slot:trigger>
                                        </x-ui.confirm>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="tag" title="No leave types yet"
                            description="Nobody can apply for leave until at least one exists." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        @if ($canCreate)
            <x-ui.card title="Add a leave type">
                <form method="POST" action="{{ route('admin.leave-types.store') }}" class="space-y-3">
                    @csrf
                    <x-ui.form.input name="code" label="Code" required maxlength="32" :value="old('code')" />
                    <x-ui.form.input name="name" label="Name" required maxlength="100" :value="old('name')" />
                    <x-ui.form.input type="number" step="0.01" min="0" name="annual_quota_days" label="Annual quota (days)"
                        required :value="old('annual_quota_days', '0.00')" />
                    <x-ui.form.select name="accrual_method" label="How it accrues" :options="$accrualMethods"
                        :selected="old('accrual_method', 'annual_grant')" required />
                    <x-ui.form.input type="number" step="0.01" min="0" name="accrual_days_per_month" label="Days per month"
                        :value="old('accrual_days_per_month', '0.00')" help="Only used by the monthly method." />
                    <x-ui.form.input type="number" name="approval_levels" label="Approval levels" required min="1" max="2"
                        :value="old('approval_levels', 1)" />
                    <x-ui.form.input name="color" label="Calendar colour" required maxlength="32" :value="old('color', 'sky')" />
                    <div class="space-y-1">
                        <x-ui.form.checkbox name="is_paid" label="Paid leave" :checked="(bool) old('is_paid', true)" />
                        <x-ui.form.checkbox name="allow_half_day" label="Half days allowed" :checked="(bool) old('allow_half_day', true)" />
                        <x-ui.form.checkbox name="excludes_weekends" label="Weekends inside a range do not count" :checked="(bool) old('excludes_weekends', true)" />
                        <x-ui.form.checkbox name="excludes_holidays" label="Holidays inside a range do not count" :checked="(bool) old('excludes_holidays', true)" />
                        <x-ui.form.checkbox name="carry_forward_enabled" label="Carries forward" :checked="(bool) old('carry_forward_enabled')" />
                        <x-ui.form.checkbox name="is_encashable" label="Encashed on exit" :checked="(bool) old('is_encashable')" />
                    </div>
                    <x-ui.form.input type="number" step="0.01" min="0" name="max_carry_forward_days" label="Carry-forward cap (days)"
                        :value="old('max_carry_forward_days', '0.00')" />
                    <x-ui.button type="submit" class="w-full" icon="plus">Add leave type</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>

    @if ($canEdit)
        @foreach ($types as $type)
            <x-ui.modal :name="'leave-type-' . $type->id" title="Edit {{ $type->name }}" icon="pencil" size="lg">
                <form method="POST" action="{{ route('admin.leave-types.update', $type) }}" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.form.input name="code" label="Code" required maxlength="32" :value="$type->code" />
                        <x-ui.form.input name="name" label="Name" required maxlength="100" :value="$type->name" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.form.input type="number" step="0.01" min="0" name="annual_quota_days" label="Annual quota"
                            required :value="(string) $type->annual_quota_days" />
                        <x-ui.form.input type="number" name="approval_levels" label="Approval levels" required min="1" max="2"
                            :value="$type->approval_levels" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.form.select name="accrual_method" label="How it accrues" :options="$accrualMethods"
                            :selected="$type->accrual_method->value" required />
                        <x-ui.form.input type="number" step="0.01" min="0" name="accrual_days_per_month" label="Days per month"
                            :value="(string) $type->accrual_days_per_month" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.form.input type="number" min="0" name="min_notice_days" label="Notice required (days)" :value="$type->min_notice_days" />
                        <x-ui.form.input type="number" min="0" name="max_consecutive_days" label="Max consecutive days" :value="$type->max_consecutive_days" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.form.input type="number" step="0.01" min="0" name="max_carry_forward_days" label="Carry-forward cap"
                            :value="(string) $type->max_carry_forward_days" />
                        <x-ui.form.input name="color" label="Calendar colour" required maxlength="32" :value="$type->color" />
                    </div>
                    <div class="space-y-1">
                        <x-ui.form.checkbox name="is_paid" label="Paid leave" :checked="$type->is_paid" />
                        <x-ui.form.checkbox name="allow_half_day" label="Half days allowed" :checked="$type->allow_half_day" />
                        <x-ui.form.checkbox name="allow_negative_balance" label="May go negative" :checked="$type->allow_negative_balance" />
                        <x-ui.form.checkbox name="requires_attachment" label="Needs an attachment" :checked="$type->requires_attachment" />
                        <x-ui.form.checkbox name="excludes_weekends" label="Weekends do not count" :checked="$type->excludes_weekends" />
                        <x-ui.form.checkbox name="excludes_holidays" label="Holidays do not count" :checked="$type->excludes_holidays" />
                        <x-ui.form.checkbox name="carry_forward_enabled" label="Carries forward" :checked="$type->carry_forward_enabled" />
                        <x-ui.form.checkbox name="accrue_from_joining" label="Pro-rate a joiner" :checked="$type->accrue_from_joining" />
                        <x-ui.form.checkbox name="allowed_on_probation" label="Available on probation" :checked="$type->allowed_on_probation" />
                        <x-ui.form.checkbox name="is_encashable" label="Encashed on exit" :checked="$type->is_encashable" />
                        <x-ui.form.checkbox name="is_active" label="Active" :checked="$type->is_active" with-hidden />
                    </div>
                    <x-ui.button type="submit" class="w-full">Save leave type</x-ui.button>
                </form>
            </x-ui.modal>
        @endforeach
    @endif
@endsection
