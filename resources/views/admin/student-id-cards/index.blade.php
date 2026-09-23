@extends('layouts.admin')

@section('title', 'Student ID cards')

@section('header')
    <x-ui.page-header title="Student ID cards"
                      subtitle="The register. A card is numbered and printed in one step — there is no draft, because an unprinted card helps nobody. Issuing a replacement retires the one it replaces."
                      icon="identification">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="arrow-down-tray"
                         :href="route('admin.student-id-cards.export', ['format' => 'csv'] + request()->query())">Export</x-ui.button>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.student-id-cards.create')">Who needs one?</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($expiringSoon > 0)
        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
            {{ app_number($expiringSoon) }}
            {{ $expiringSoon === 1 ? 'card expires' : 'cards expire' }} within the next 30 days.
            <a href="{{ route('admin.student-id-cards.index', ['expiring' => 1]) }}" class="font-medium underline">Show them</a>
        </div>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        @foreach ($statuses as $status)
            <x-ui.stat-card :label="$status->label()"
                            :value="app_number($counts[$status->value] ?? 0)"
                            :color="$status->color()" />
        @endforeach
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.input name="q" label="Search" :value="request('q')"
                             placeholder="Card number, name, roll or code" />

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected((int) request('course_id') === (int) $course->id)>{{ $course->name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="batch_id" label="Batch" placeholder="Any batch">
                @foreach ($batches as $batch)
                    <option value="{{ $batch->id }}" @selected((int) request('batch_id') === (int) $batch->id)>{{ $batch->code }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.student-id-cards.index')">Clear</x-ui.button>
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2 dark:text-slate-300">
                <input type="checkbox" name="expiring" value="1" @checked(request()->boolean('expiring'))
                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                Only cards expiring within 30 days
            </label>
        </form>
    </x-ui.card>

    {{--
        The whole table is one form, so a sheet of cards prints in a single pass. The ceiling on how
        many is a setting, checked on the server — a tick box that let somebody render an entire
        institute into one document would be a very slow way to find that out.
    --}}
    <form method="POST" action="{{ route('admin.student-id-cards.batch-print') }}" target="_blank">
        @csrf

        <x-ui.card :padded="false">
            <x-ui.table :is-empty="$cards->isEmpty()">
                <x-slot:head>
                    <th class="w-10 px-4 py-3"><span class="sr-only">Select</span></th>
                    <th class="px-4 py-3 text-left font-semibold">Card</th>
                    <th class="px-4 py-3 text-left font-semibold">Student</th>
                    <th class="px-4 py-3 text-left font-semibold">Course</th>
                    <th class="px-4 py-3 text-left font-semibold">Valid until</th>
                    <th class="px-4 py-3 text-right font-semibold">Prints</th>
                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                </x-slot:head>

                @foreach ($cards as $card)
                    <tr>
                        <td class="px-4 py-3">
                            <input type="checkbox" name="card_ids[]" value="{{ $card->id }}"
                                   aria-label="Select card {{ $card->card_number }}"
                                   class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.student-id-cards.show', $card) }}"
                               class="font-mono text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">
                                {{ $card->card_number }}
                            </a>
                            <div class="text-xs text-slate-400">issued {{ app_date($card->issued_on) }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                            {{ $card->student_name_snapshot }}
                            <div class="text-xs text-slate-400">{{ $card->student_code_snapshot }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                            {{ $card->course_name_snapshot ?? '—' }}
                            @if ($card->batch_name_snapshot)
                                <div class="text-xs text-slate-400">{{ $card->batch_name_snapshot }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                            {{ $card->valid_until ? app_date($card->valid_until) : 'No expiry' }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                            {{ app_number($card->print_count) }}
                        </td>
                        <td class="px-4 py-3">
                            <x-ui.badge :color="$card->status->color()" size="xs">{{ $card->status->label() }}</x-ui.badge>
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state icon="identification" title="No cards yet"
                                      message="Cards are issued from the candidates screen, which lists every student on a batch who has no live card.">
                        @if ($canCreate)
                            <x-slot:action>
                                <x-ui.button icon="plus" :href="route('admin.student-id-cards.create')">Who needs one?</x-ui.button>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                </x-slot:empty>
            </x-ui.table>

            @unless ($cards->isEmpty())
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 dark:border-slate-800">
                    <x-ui.button type="submit" size="sm" variant="secondary" icon="printer">
                        Print the ones ticked
                    </x-ui.button>
                    <x-ui.pagination-summary :paginator="$cards" label="cards" class="border-0 p-0" />
                </div>
            @else
                <x-ui.pagination-summary :paginator="$cards" label="cards" />
            @endunless
        </x-ui.card>
    </form>
@endsection
