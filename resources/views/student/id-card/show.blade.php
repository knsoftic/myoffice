@extends('layouts.panel')

@section('title', 'Your student card')

@section('header')
    <x-ui.page-header title="Your student card"
                      subtitle="One card is live at a time. A replacement retires the one before it."
                      icon="identification" />
@endsection

@section('content')
    @if ($card)
        <x-ui.card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-lg font-semibold text-slate-800 dark:text-slate-100">
                        {{ $card->card_number }}
                    </p>
                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                        {{ $card->course_name_snapshot ?? 'No course on the card' }}
                    </p>
                </div>

                <x-ui.badge :color="$card->status->color()">{{ $card->status->label() }}</x-ui.badge>
            </div>

            <dl class="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                @foreach ([
                    'Name' => $card->student_name_snapshot,
                    'Roll number' => $card->student_code_snapshot,
                    'Batch' => $card->batch_name_snapshot,
                    'Issued' => app_date($card->issued_on),
                    'Valid until' => $card->valid_until ? app_date($card->valid_until) : 'No expiry',
                ] as $label => $value)
                    <div class="flex justify-between gap-3 border-b border-slate-100 py-1 dark:border-slate-800">
                        <dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                        <dd class="text-right text-slate-700 dark:text-slate-200">{{ $value ?: '—' }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($card->valid_until && $card->valid_until->isPast())
                <p class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                    This card is past its date. Ask the office for a replacement.
                </p>
            @endif

            <div class="mt-4 flex justify-end">
                <x-ui.button icon="document-arrow-down" target="_blank" :href="route('student.id-card.pdf')">
                    Download it
                </x-ui.button>
            </div>
        </x-ui.card>
    @elseif ($previous)
        {{--
            No live card, but there has been one. Showing the last one is the honest answer: somebody
            who reported theirs lost needs to see that the institute knows, not an empty page.
        --}}
        <x-ui.card>
            <x-ui.section-heading title="You have no card at the moment"
                                  subtitle="The last one on your record is below. The office issues a replacement." />

            <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-3 border-b border-slate-100 py-1 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">Card</dt>
                    <dd class="font-mono text-xs text-slate-700 dark:text-slate-200">{{ $previous->card_number }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-slate-100 py-1 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">What happened</dt>
                    <dd><x-ui.badge :color="$previous->status->color()" size="xs">{{ $previous->status->label() }}</x-ui.badge></dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-slate-100 py-1 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">Issued</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ app_date($previous->issued_on) }}</dd>
                </div>
            </dl>
        </x-ui.card>
    @else
        <x-ui.card>
            <x-ui.empty-state icon="identification" title="No card yet"
                              message="The office issues cards from your enrolment. If yours is overdue, a photograph on your profile is usually what is missing." />
        </x-ui.card>
    @endif
@endsection
