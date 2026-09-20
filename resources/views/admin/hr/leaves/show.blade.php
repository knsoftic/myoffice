@extends('layouts.admin')

@section('title', $request->request_number)

@php
    $user = auth()->user();
    $canApprove = $user?->can('approve', $request) === true;
    $canReject = $user?->can('reject', $request) === true;
    $canCancel = $user?->can('changeStatus', $request) === true;
    $counted = $request->days->where('is_counted', true);
    $skipped = $request->days->where('is_counted', false);
@endphp

@section('header')
    <x-ui.page-header
        :title="$request->request_number"
        :subtitle="$request->employee?->name . ' · ' . $request->leaveType?->name"
        icon="calendar">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.leaves.index')">Back to requests</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="The request">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Status</dt>
                        <dd class="mt-1"><x-ui.badge :color="$request->status->color()" size="xs">{{ $request->status->label() }}</x-ui.badge></dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Dates</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">
                            {{ app_date($request->from_date) }} – {{ app_date($request->to_date) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Counted days</dt>
                        <dd class="mt-1 text-sm tabular-nums font-semibold text-slate-900 dark:text-white">
                            {{ app_number((float) $request->total_days, 2) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Applied on</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ app_date($request->applied_on) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Balance when applied</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">
                            {{ $request->balance_snapshot_days === null ? '—' : app_number((float) $request->balance_snapshot_days, 2) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Available now</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ app_number((float) $available, 2) }}</dd>
                    </div>
                </dl>

                @if ($showReason)
                    <div class="mt-4 border-t border-slate-200 pt-4 dark:border-slate-700">
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Reason</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $request->reason }}</dd>
                    </div>
                @else
                    <p class="mt-4 border-t border-slate-200 pt-4 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                        The reason is visible to the employee, the approval chain and holders of leave oversight only —
                        a leave reason is often medical.
                    </p>
                @endif
            </x-ui.card>

            <x-ui.card title="Day by day" subtitle="Every date in the range, including the ones that cost no quota.">
                <x-ui.table>
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Date</th>
                        <th class="px-4 py-3 text-left font-semibold">Portion</th>
                        <th class="px-4 py-3 text-right font-semibold">Counts</th>
                        <th class="px-4 py-3 text-left font-semibold">Paid</th>
                    </x-slot:head>

                    @foreach ($request->days->sortBy('leave_date') as $day)
                        <tr class="{{ $day->is_counted ? '' : 'opacity-60' }}">
                            <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                                <span class="block">{{ app_date($day->leave_date) }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ app_date($day->leave_date, 'l') }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $day->day_portion->label() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                {{ $day->is_counted ? app_number((float) $day->day_fraction, 2) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                                @if (! $day->is_counted)
                                    non-working, so it costs no quota
                                @else
                                    {{ $day->is_paid ? 'paid' : 'unpaid' }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>

                @if ($skipped->isNotEmpty())
                    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                        {{ $request->days->count() }} calendar day(s) cost {{ app_number((float) $request->total_days, 2) }}
                        quota day(s): {{ $skipped->count() }} fell on a non-working day this leave type excludes.
                    </p>
                @endif
            </x-ui.card>

            <x-ui.card title="Approval chain">
                <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                    @foreach ($request->approvals->sortBy('level') as $approval)
                        <li class="flex items-center justify-between gap-4 py-3">
                            <div>
                                <span class="block text-sm font-medium text-slate-900 dark:text-white">Level {{ $approval->level }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $approval->expectedApprover?->name ?? 'Anybody holding '.$approval->fallback_permission }}
                                    @if ($approval->acted_at) · acted {{ app_datetime($approval->acted_at) }} @endif
                                    @if ($approval->comment) · {{ $approval->comment }} @endif
                                </span>
                            </div>
                            <x-ui.badge :color="$approval->status->color()" size="xs">{{ $approval->status->label() }}</x-ui.badge>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            @if ($request->status->value === 'pending' && ($canApprove || $canReject))
                <x-ui.card title="Decide">
                    @if ($canApprove)
                        <form method="POST" action="{{ route('admin.leaves.approve', $request) }}" class="space-y-3">
                            @csrf
                            <x-ui.form.input name="comment" label="Comment" maxlength="255" />
                            <x-ui.button type="submit" class="w-full" icon="check">Approve this level</x-ui.button>
                        </form>
                    @endif

                    @if ($canReject)
                        <form method="POST" action="{{ route('admin.leaves.reject', $request) }}" class="mt-4 space-y-3 border-t border-slate-200 pt-4 dark:border-slate-700">
                            @csrf
                            <x-ui.form.textarea name="reason" label="Reason for refusing" rows="2" required />
                            <x-ui.button type="submit" variant="danger" class="w-full" icon="x-mark">Refuse</x-ui.button>
                        </form>
                    @endif
                </x-ui.card>
            @endif

            @if ($canCancel && ! $request->status->isTerminal())
                <x-ui.card title="Cancel">
                    <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                        The days go back to the balance and every date it touched is re-resolved from its punches.
                        A date already locked by payroll refuses the whole cancellation.
                    </p>
                    <form method="POST" action="{{ route('admin.leaves.cancel', $request) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.textarea name="reason" label="Reason" rows="2" required />
                        <x-ui.button type="submit" variant="secondary" class="w-full">Cancel this leave</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            <x-ui.card title="Employee">
                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $request->employee?->name }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $request->employee?->employee_code }}</p>
                <x-ui.button variant="ghost" size="sm" class="mt-3"
                    :href="route('admin.leave-balances.show', $request->employee)">Leave statement</x-ui.button>
            </x-ui.card>
        </div>
    </div>
@endsection
