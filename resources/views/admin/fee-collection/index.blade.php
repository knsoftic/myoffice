@extends('layouts.admin')

@section('title', 'Fee collection')

@section('header')
    <x-ui.page-header title="Fee collection"
                      subtitle="Who owes what today. Collecting happens through the payment dialog, so the one money path stays the one money path."
                      icon="calculator">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="banknotes" :href="route('admin.student-fees.index')">All charges</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat-card label="Expected today" :value="money($stats['expected_today'])" icon="calendar-days" color="sky" />
        <x-ui.stat-card label="Collected today" :value="money($stats['collected_today'])" icon="banknotes" color="emerald"
                        :delta-label="app_number($stats['collected_count']).' receipts'" />
        <x-ui.stat-card label="Overdue" :value="money($stats['overdue_total'])" icon="exclamation-triangle"
                        :color="(float) $stats['overdue_total'] > 0 ? 'rose' : 'slate'"
                        :delta-label="app_number($stats['overdue_count']).' charges'" />
    </div>

    <x-ui.tabs :tabs="[
        ['label' => 'Due today', 'url' => route('admin.fee-collection.index', ['tab' => 'today'] + request()->except('tab', 'page')), 'active' => $tab === 'today'],
        ['label' => 'Next 7 days', 'url' => route('admin.fee-collection.index', ['tab' => 'upcoming'] + request()->except('tab', 'page')), 'active' => $tab === 'upcoming'],
        ['label' => 'Overdue', 'url' => route('admin.fee-collection.index', ['tab' => 'overdue'] + request()->except('tab', 'page')), 'active' => $tab === 'overdue'],
        ['label' => 'Advances', 'url' => route('admin.fee-collection.index', ['tab' => 'advances'] + request()->except('tab', 'page')), 'active' => $tab === 'advances'],
    ]" />

    <x-ui.card class="my-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <input type="hidden" name="tab" value="{{ $tab }}">

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($pickers['courses'] as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('course_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="batch_id" label="Batch" placeholder="Any batch">
                @foreach ($pickers['batches'] as $id => $code)
                    <option value="{{ $id }}" @selected((int) request('batch_id') === (int) $id)>{{ $code }}</option>
                @endforeach
            </x-ui.form.select>

            @if ($tab === 'overdue')
                <x-ui.form.select name="overdue_bucket" label="How late" placeholder="Any">
                    <option value="1-7" @selected(request('overdue_bucket') === '1-7')>1–7 days</option>
                    <option value="8-30" @selected(request('overdue_bucket') === '8-30')>8–30 days</option>
                    <option value="30+" @selected(request('overdue_bucket') === '30+')>Over 30 days</option>
                </x-ui.form.select>
            @endif

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.fee-collection.index', ['tab' => $tab])">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        @if ($tab === 'advances')
            {{-- A separate table, because an advance is not a debt with a minus sign in front of it.
                 Folding it into the outstanding figure is how a desk ends up chasing the wrong students. --}}
            <x-ui.table :is-empty="$rows->isEmpty()">
                <x-slot:head>
                    <th class="px-4 py-3 text-left font-semibold">Fee #</th>
                    <th class="px-4 py-3 text-left font-semibold">Student</th>
                    <th class="px-4 py-3 text-left font-semibold">Head</th>
                    <th class="px-4 py-3 text-right font-semibold">Net</th>
                    <th class="px-4 py-3 text-right font-semibold">Received</th>
                    <th class="px-4 py-3 text-right font-semibold">In advance</th>
                </x-slot:head>

                @foreach ($rows as $charge)
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.student-fees.show', $charge) }}"
                               class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $charge->fee_number }}</a>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $charge->student?->name }}</td>
                        <td class="px-4 py-3"><x-ui.badge :color="$charge->fee_type->color()" size="xs">{{ $charge->fee_type->label() }}</x-ui.badge></td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($charge->net_amount) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($charge->paid_amount) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium text-emerald-600 dark:text-emerald-400">
                            {{ money(\App\Support\Money::abs($charge->balance_amount)) }}
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state icon="arrow-trending-up" title="No unapplied advances"
                                      description="Nobody has paid more than they were charged." />
                </x-slot:empty>
            </x-ui.table>
        @else
            <x-ui.table :is-empty="$rows->isEmpty()">
                <x-slot:head>
                    <th class="px-4 py-3 text-left font-semibold">Student</th>
                    <th class="px-4 py-3 text-left font-semibold">Charge</th>
                    <th class="px-4 py-3 text-left font-semibold">Installment</th>
                    <th class="px-4 py-3 text-left font-semibold">Due</th>
                    <th class="px-4 py-3 text-right font-semibold">Still owed</th>
                    <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </x-slot:head>

                @foreach ($rows as $line)
                    @php($owed = \App\Support\Money::sub(\App\Support\Money::sub((string) $line->amount, (string) $line->paid_amount), (string) $line->waived_amount))
                    @php($late = $line->due_date->isPast())
                    <tr>
                        <td class="px-4 py-3">
                            <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $line->fee?->student?->name ?? '—' }}</div>
                            <div class="text-xs text-slate-400">{{ $line->fee?->student?->student_code }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.student-fees.show', $line->fee) }}"
                               class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $line->fee?->fee_number }}</a>
                            <div class="text-xs text-slate-400">
                                {{ $line->fee?->course?->name }} {{ $line->fee?->batch?->code ? '· '.$line->fee->batch->code : '' }}
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                            #{{ app_number($line->installment_no) }}
                            <x-ui.badge :color="$line->status->color()" size="xs">{{ $line->status->label() }}</x-ui.badge>
                        </td>
                        <td class="px-4 py-3 text-sm {{ $late ? 'text-rose-600 dark:text-rose-400' : 'text-slate-600 dark:text-slate-300' }}">
                            {{ app_date($line->due_date) }}
                            @if ($late)
                                <div class="text-xs">{{ app_number($line->due_date->diffInDays(now())) }} days late</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium text-slate-700 dark:text-slate-200">{{ money($owed) }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end gap-1">
                                @can('fee_reminders.create')
                                    <x-ui.icon-button icon="bell-alert" label="Remind"
                                                      x-on:click="$dispatch('open-modal', 'remind-{{ $line->id }}')" />
                                @endcan
                                @can('student_fees.print')
                                    <x-ui.icon-button icon="printer" label="Fee slip" :href="route('admin.student-fees.slip', $line->fee)" />
                                @endcan
                                <x-ui.icon-button icon="eye" label="Open" :href="route('admin.student-fees.show', $line->fee)" />
                            </div>
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state icon="check-circle"
                                      :title="match ($tab) {
                                          'upcoming' => 'Nothing due in the next seven days',
                                          'overdue' => 'No overdue fees — the institute is current',
                                          default => 'Nothing due today',
                                      }"
                                      description="A charge only appears here once it has a due date and something still owed against it." />
                </x-slot:empty>
            </x-ui.table>
        @endif

        <x-ui.pagination-summary :paginator="$rows" :label="$tab === 'advances' ? 'charges' : 'installments'" />
    </x-ui.card>

    @can('fee_reminders.create')
        @if ($tab !== 'advances')
            @foreach ($rows as $line)
                <x-ui.modal name="remind-{{ $line->id }}" :title="'Remind '.($line->fee?->student?->name ?? 'this student').'?'" icon="bell-alert">
                    <form method="POST" action="{{ route('admin.fee-reminders.store', $line->fee) }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="student_fee_installment_id" value="{{ $line->id }}">
                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            About installment {{ app_number($line->installment_no) }}, due
                            {{ app_date($line->due_date) }}. If they have already been told about this today,
                            nothing is sent twice.
                        </p>
                        <x-ui.form.select name="type" label="Which reminder">
                            @foreach (\App\Enums\FeeReminderType::cases() as $type)
                                <option value="{{ $type->value }}"
                                        @selected($line->due_date->isPast() ? $type === \App\Enums\FeeReminderType::Overdue : $type === \App\Enums\FeeReminderType::DueToday)>
                                    {{ $type->label() }}
                                </option>
                            @endforeach
                        </x-ui.form.select>
                        <div class="flex justify-end gap-2">
                            <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'remind-{{ $line->id }}')">Cancel</x-ui.button>
                            <x-ui.button type="submit" variant="primary">Send it</x-ui.button>
                        </div>
                    </form>
                </x-ui.modal>
            @endforeach
        @endif
    @endcan
@endsection
