@extends('layouts.admin')

@section('title', 'Fee reminders')

@section('header')
    <x-ui.page-header title="Fee reminders"
                      subtitle="Who was told what, and when. A sent reminder is a log row — it is never edited and never deleted."
                      icon="bell-alert">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="calculator" :href="route('admin.fee-collection.index')">Collection desk</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.select name="type" label="Type" placeholder="Any type">
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected(request('type') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="source" label="Sent by" placeholder="Anyone">
                <option value="scheduler" @selected(request('source') === 'scheduler')>The scheduler</option>
                <option value="manual" @selected(request('source') === 'manual')>A member of staff</option>
            </x-ui.form.select>

            <x-ui.form.input name="from" label="From" type="date" :value="request('from')" />
            <x-ui.form.input name="to" label="To" type="date" :value="request('to')" />

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.fee-reminders.index')">Clear</x-ui.button>
            </div>
        </form>

        @if (request()->filled('run_uuid'))
            {{-- One run's whole output. `run_uuid` is on every row the scheduler wrote precisely so
                 that "who did the 09:00 run reach" has an answer. --}}
            <x-ui.form.help class="mt-3">
                Showing one scheduled run: <span class="font-mono">{{ request('run_uuid') }}</span>.
                <a href="{{ route('admin.fee-reminders.index') }}" class="underline">Show everything</a>
            </x-ui.form.help>
        @endif
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$reminders->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Sent</th>
                <th class="px-4 py-3 text-left font-semibold">Student</th>
                <th class="px-4 py-3 text-left font-semibold">About</th>
                <th class="px-4 py-3 text-left font-semibold">Type</th>
                <th class="px-4 py-3 text-right font-semibold">Owed then</th>
                <th class="px-4 py-3 text-left font-semibold">By</th>
            </x-slot:head>

            @foreach ($reminders as $reminder)
                <tr>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ app_datetime($reminder->sent_at) }}
                        @if ($reminder->run_uuid)
                            <div class="text-xs">
                                <a href="{{ route('admin.fee-reminders.index', ['run_uuid' => $reminder->run_uuid]) }}"
                                   class="text-slate-400 hover:underline">this run</a>
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $reminder->student?->name ?? '—' }}</div>
                        <div class="text-xs text-slate-400">{{ $reminder->student?->student_code }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        <a href="{{ route('admin.student-fees.show', $reminder->student_fee_id) }}" class="hover:underline">
                            {{ $reminder->fee?->fee_number ?? '—' }}
                        </a>
                        <div class="text-xs text-slate-400">
                            {{ $reminder->installment ? 'installment '.app_number($reminder->installment->installment_no) : 'the charge' }}
                            · due {{ app_date($reminder->due_date) }} · {{ $reminder->offsetCaption() }}
                        </div>
                    </td>
                    <td class="px-4 py-3"><x-ui.badge :color="$reminder->type->color()" size="xs">{{ $reminder->type->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($reminder->amount_due) }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $reminder->senderCaption() }}</td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="bell-alert" title="No reminders sent"
                                  description="The nightly run sends one ahead of the due date, one on the day, and a weekly chase after it. Nothing here yet means nothing has fallen due." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$reminders" label="reminders" />
    </x-ui.card>
@endsection
