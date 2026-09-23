@extends('layouts.admin')

@section('title', 'Who can be certified')

@section('header')
    <x-ui.page-header title="Who can be certified"
                      subtitle="Every rule, with the number behind it. “Not eligible” is never the whole answer — attendance 68.50% against 75.00% needed is."
                      icon="check-badge">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.certificates.index')">Back to the register</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
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
                <x-ui.button variant="ghost" :href="route('admin.certificates.eligible')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="space-y-3">
        @forelse ($enrollments as $enrollment)
            @php($report = $reports[$enrollment->getKey()] ?? null)
            @php($eligible = $report?->eligible() ?? false)

            <x-ui.card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">
                                {{ $enrollment->student?->name ?? 'Unknown student' }}
                            </h3>
                            <span class="text-xs text-slate-400">{{ $enrollment->student?->student_code }}</span>
                            <x-ui.badge :color="$eligible ? 'emerald' : 'amber'" size="xs">
                                {{ $eligible ? 'Eligible' : 'Not yet' }}
                            </x-ui.badge>
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            {{ $enrollment->batch?->course?->name ?? '—' }}
                            @if ($enrollment->batch)
                                · {{ $enrollment->batch->code }}
                            @endif
                        </p>
                    </div>

                    <div class="shrink-0">
                        {{--
                            Drafting is offered whatever the verdict. Eligibility is re-run at issue
                            time, and an override there is a separate, recorded decision — refusing
                            the draft would only mean the office prepares it somewhere this system
                            cannot see.
                        --}}
                        <x-ui.button type="button" size="sm"
                                     :variant="$eligible ? 'primary' : 'secondary'"
                                     icon="document-plus"
                                     x-on:click="$dispatch('open-modal', 'draft-{{ $enrollment->getKey() }}')">
                            Draft a certificate
                        </x-ui.button>
                    </div>
                </div>

                <div class="mt-3">
                    @include('admin.certificates._eligibility', ['report' => $report])
                </div>

                <x-ui.modal name="draft-{{ $enrollment->getKey() }}"
                            title="Draft a certificate for {{ $enrollment->student?->name }}"
                            icon="document-plus">
                    <form method="POST" action="{{ route('admin.certificates.store') }}">
                        @csrf
                        <input type="hidden" name="student_batch_enrollment_id" value="{{ $enrollment->getKey() }}">

                        <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">
                            A draft has no number and nobody has been given it. The grade and the dates are taken
                            from the record unless you set them here, and eligibility is checked again when it is issued.
                        </p>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.form.input type="date" name="completion_date" label="Completed on"
                                             help="Leave empty to use the batch's end date." />
                            <x-ui.form.input name="grade" label="Grade" maxlength="8"
                                             help="Leave empty to take it from the results." />
                            <x-ui.form.input type="number" step="0.01" min="0" max="100"
                                             name="percentage" label="Percentage" suffix="%" />
                            <x-ui.form.input type="number" step="0.01" min="0" max="10"
                                             name="grade_point" label="Grade points" />
                            <div class="sm:col-span-2">
                                <x-ui.form.textarea name="notes" label="Notes" rows="2"
                                                    help="For the office. Not printed." />
                            </div>
                        </div>

                        <div class="mt-4 flex justify-end gap-2">
                            <x-ui.button type="button" variant="ghost"
                                         x-on:click="$dispatch('close-modal', 'draft-{{ $enrollment->getKey() }}')">Cancel</x-ui.button>
                            <x-ui.button type="submit" icon="check">Create the draft</x-ui.button>
                        </div>
                    </form>
                </x-ui.modal>
            </x-ui.card>
        @empty
            <x-ui.card>
                <x-ui.empty-state icon="check-badge" title="No enrolments match"
                                  message="Choose a batch, or clear the filters. Every enrolment appears here — whether or not it meets the rules yet." />
            </x-ui.card>
        @endforelse
    </div>

    <div class="mt-4">
        <x-ui.pagination-summary :paginator="$enrollments" label="enrolments" />
    </div>
@endsection
