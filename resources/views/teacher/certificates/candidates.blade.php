@extends('layouts.panel')

@section('title', 'Certificate candidates — '.$batch->code)

@section('header')
    <x-ui.page-header :title="'Who can be certified — '.$batch->code"
                      subtitle="Read only. Issuing is the office's; what is here is which of your students have met the rules, and by how much."
                      icon="check-badge" />
@endsection

@section('content')
    @unless ($batch->course?->certificate_available)
        <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
            This course does not award a certificate, so nobody on it is eligible whatever else they do.
            That is a setting on the course, not something about these students.
        </div>
    @endunless

    @if ($batches->count() > 1)
        <x-ui.card class="mb-4">
            <x-ui.section-heading title="Your batches" />

            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($batches as $option)
                    <x-ui.button size="sm"
                                 :variant="(int) $option->id === (int) $batch->id ? 'primary' : 'ghost'"
                                 :href="route('teacher.certificates.candidates', $option)">
                        {{ $option->code }}
                    </x-ui.button>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    <div class="space-y-3">
        @forelse ($enrollments as $enrollment)
            @php($report = $reports[$enrollment->getKey()] ?? null)
            @php($eligible = $report?->eligible() ?? false)

            <x-ui.card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">
                            {{ $enrollment->student?->name ?? 'Unknown student' }}
                        </h3>
                        <p class="text-xs text-slate-400">{{ $enrollment->student?->student_code }}</p>
                    </div>

                    <x-ui.badge :color="$eligible ? 'emerald' : 'amber'" size="xs">
                        {{ $eligible ? 'Eligible' : 'Not yet' }}
                    </x-ui.badge>
                </div>

                @if ($report)
                    <ul class="mt-3 grid gap-1.5 sm:grid-cols-2">
                        @foreach ($report->rules as $rule)
                            <li class="flex items-start gap-2 text-xs">
                                <x-ui.icon :name="$rule->skipped ? 'minus-circle' : ($rule->passed ? 'check-circle' : 'x-circle')"
                                           @class([
                                               'mt-px h-4 w-4 shrink-0',
                                               'text-slate-300 dark:text-slate-600' => $rule->skipped,
                                               'text-emerald-500' => $rule->passed && ! $rule->skipped,
                                               'text-rose-500' => ! $rule->passed,
                                           ]) />
                                <span class="text-slate-600 dark:text-slate-300">
                                    {{ $rule->message }}
                                    @if ($rule->required !== null && $rule->actual !== null)
                                        <span class="text-slate-400">({{ $rule->actual }} of {{ $rule->required }})</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @empty
            <x-ui.card>
                <x-ui.empty-state icon="check-badge" title="Nobody is enrolled on this batch"
                                  message="Once students are seated, each one appears here with the rules they meet and the ones they do not." />
            </x-ui.card>
        @endforelse
    </div>

    <div class="mt-4">
        <x-ui.pagination-summary :paginator="$enrollments" label="students" />
    </div>
@endsection
