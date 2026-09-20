@extends('layouts.admin')

@section('title', $request->request_number)

@section('header')
    <x-ui.page-header :title="$request->request_number" :subtitle="$request->leaveType?->name" icon="calendar">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.my.leave.index')">My leave</x-ui.button>
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
                        <dd class="mt-1 text-sm tabular-nums font-semibold text-slate-900 dark:text-white">{{ app_number((float) $request->total_days, 2) }}</dd>
                    </div>
                    <div class="sm:col-span-3">
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Reason</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $request->reason }}</dd>
                    </div>
                    @if ($request->rejection_reason)
                        <div class="sm:col-span-3">
                            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Why it was refused</dt>
                            <dd class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $request->rejection_reason }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            <x-ui.card title="Day by day">
                <x-ui.table>
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Date</th>
                        <th class="px-4 py-3 text-left font-semibold">Portion</th>
                        <th class="px-4 py-3 text-right font-semibold">Counts</th>
                    </x-slot:head>

                    @foreach ($request->days->sortBy('leave_date') as $day)
                        <tr class="{{ $day->is_counted ? '' : 'opacity-60' }}">
                            <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                                {{ app_date($day->leave_date) }}
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ app_date($day->leave_date, 'l') }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $day->day_portion->label() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                {{ $day->is_counted ? app_number((float) $day->day_fraction, 2) : 'non-working' }}
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Who decides">
                <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                    @foreach ($request->approvals->sortBy('level') as $approval)
                        <li class="flex items-center justify-between gap-4 py-3">
                            <div>
                                <span class="block text-sm font-medium text-slate-900 dark:text-white">Level {{ $approval->level }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $approval->expectedApprover?->name ?? 'HR' }}
                                    @if ($approval->comment) · {{ $approval->comment }} @endif
                                </span>
                            </div>
                            <x-ui.badge :color="$approval->status->color()" size="xs">{{ $approval->status->label() }}</x-ui.badge>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        </div>

        @unless ($request->status->isTerminal())
            <x-ui.card title="Withdraw">
                <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                    The days go back to your balance. A date already behind a paid payroll refuses the whole
                    withdrawal — ask HR in that case.
                </p>
                <form method="POST" action="{{ route('admin.my.leave.cancel', $request) }}" class="space-y-3">
                    @csrf
                    <x-ui.form.textarea name="reason" label="Why" rows="2" required />
                    <x-ui.button type="submit" variant="secondary" class="w-full">Withdraw this request</x-ui.button>
                </form>
            </x-ui.card>
        @endunless
    </div>
@endsection
