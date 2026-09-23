@extends('layouts.admin')

@section('title', 'Who needs a card')

@section('header')
    <x-ui.page-header title="Who needs a card"
                      subtitle="Students on a batch with no live card. A student whose card was lost last term appears here again — that is the point of the screen."
                      icon="identification">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.student-id-cards.index')">Back to the register</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($photoRequired)
        <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
            This institute requires a photograph on every card. A student with none cannot be issued
            one until a photo is on their record — the card is copied its own copy of the file at
            issue time, so it never changes afterwards.
        </div>
    @endif

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.form.select name="batch_id" label="Batch" placeholder="Any batch">
                @foreach ($batches as $batch)
                    <option value="{{ $batch->id }}" @selected((int) request('batch_id') === (int) $batch->id)>{{ $batch->code }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.student-id-cards.create')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <form method="POST" action="{{ route('admin.student-id-cards.bulk-issue') }}">
        @csrf

        <x-ui.card :padded="false">
        <x-ui.table :is-empty="$enrollments->isEmpty()">
            <x-slot:head>
                <th class="w-10 px-4 py-3"><span class="sr-only">Select</span></th>
                <th class="px-4 py-3 text-left font-semibold">Student</th>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-left font-semibold">Photograph</th>
                <th class="px-4 py-3 text-right font-semibold"><span class="sr-only">Issue</span></th>
            </x-slot:head>

            @foreach ($enrollments as $enrollment)
                @php($hasPhoto = filled($enrollment->student?->photo_path))
                @php($blocked = $photoRequired && ! $hasPhoto)

                <tr>
                    <td class="px-4 py-3">
                        {{-- Only offered where a card can actually be issued, so nobody ticks a row
                             and then reads that it was skipped. --}}
                        @unless ($blocked)
                            <input type="checkbox" name="enrollment_ids[]" value="{{ $enrollment->getKey() }}"
                                   aria-label="Select this student"
                                   class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                        @endunless
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $enrollment->student?->name ?? 'Unknown student' }}
                        <div class="text-xs text-slate-400">{{ $enrollment->student?->student_code }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $enrollment->batch?->course?->name ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $enrollment->batch?->code ?? '—' }}
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$hasPhoto ? 'emerald' : ($photoRequired ? 'rose' : 'slate')" size="xs">
                            {{ $hasPhoto ? 'On file' : 'None' }}
                        </x-ui.badge>
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if ($blocked)
                            <span class="text-xs text-slate-400">Needs a photograph first</span>
                        @else
                            <x-ui.button type="button" size="sm" icon="identification"
                                         x-on:click="$dispatch('open-modal', 'issue-{{ $enrollment->getKey() }}')">
                                Issue a card
                            </x-ui.button>

                            <x-ui.modal name="issue-{{ $enrollment->getKey() }}"
                                        title="Issue a card to {{ $enrollment->student?->name }}"
                                        icon="identification">
                                <form method="POST" action="{{ route('admin.student-id-cards.store') }}">
                                    @csrf
                                    <input type="hidden" name="student_batch_enrollment_id" value="{{ $enrollment->getKey() }}">

                                    <p class="mb-4 text-left text-sm text-slate-600 dark:text-slate-300">
                                        The card is numbered and its details frozen straight away, and the photograph is
                                        copied so a later profile picture cannot change a card in somebody's wallet.
                                        Any card this student already holds is retired by the same step.
                                    </p>

                                    <div class="grid gap-3 text-left">
                                        <x-ui.form.input type="date" name="valid_until" label="Valid until"
                                                         help="Leave empty to use the institute's validity setting." />
                                        <x-ui.form.textarea name="notes" label="Notes" rows="2"
                                                            help="For the office. Not printed on the card." />
                                    </div>

                                    <div class="mt-4 flex justify-end gap-2">
                                        <x-ui.button type="button" variant="ghost"
                                                     x-on:click="$dispatch('close-modal', 'issue-{{ $enrollment->getKey() }}')">Cancel</x-ui.button>
                                        <x-ui.button type="submit" icon="check">Issue the card</x-ui.button>
                                    </div>
                                </form>
                            </x-ui.modal>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="identification" title="Everybody has a card"
                                  message="Every student on the chosen batches holds a live card. Pick another batch, or clear the filter." />
            </x-slot:empty>
        </x-ui.table>

        @unless ($enrollments->isEmpty())
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 dark:border-slate-800">
                <x-ui.button type="submit" size="sm" variant="secondary" icon="identification">
                    Issue cards to the students ticked
                </x-ui.button>
                <x-ui.pagination-summary :paginator="$enrollments" label="students" class="border-0 p-0" />
            </div>
        @else
            <x-ui.pagination-summary :paginator="$enrollments" label="students" />
        @endunless
        </x-ui.card>
    </form>
@endsection
