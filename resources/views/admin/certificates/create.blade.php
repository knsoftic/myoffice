@extends('layouts.admin')

@section('title', 'Draft a certificate')

@section('header')
    <x-ui.page-header title="Draft a certificate"
                      subtitle="A draft has no number and nobody has been given it. Eligibility is checked again when it is issued."
                      icon="document-plus">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.certificates.eligible')">Who is eligible?</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($enrollment === null)
        {{--
            No enrolment chosen yet. The eligibility list is the screen that answers "which student?"
            properly — it shows the verdict beside every name — so this one points at it rather than
            offering a bare dropdown of every enrolment in the institute.
        --}}
        <x-ui.card>
            <x-ui.empty-state icon="check-badge" title="Which student?"
                              message="Certificates are drafted from the eligibility list, where every enrolment shows the rules it does and does not meet.">
                <x-slot:action>
                    <x-ui.button icon="arrow-right" :href="route('admin.certificates.eligible')">Open the list</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <form method="POST" action="{{ route('admin.certificates.store') }}">
            @csrf
            <input type="hidden" name="student_batch_enrollment_id" value="{{ $enrollment->getKey() }}">

            <div class="grid gap-4 lg:grid-cols-3">
                <div class="space-y-4 lg:col-span-2">
                    <x-ui.card>
                        <x-ui.section-heading title="What it will say"
                                              subtitle="Left empty, each of these is taken from the record when the draft is created." />

                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <x-ui.form.input type="date" name="completion_date" label="Completed on"
                                             :value="old('completion_date')"
                                             help="Defaults to the batch's end date." />

                            <x-ui.form.select name="print_template_id" label="Template" placeholder="The institute's default">
                                @foreach ($templates as $template)
                                    <option value="{{ $template->id }}" @selected((int) old('print_template_id') === (int) $template->id)>
                                        {{ $template->name }}
                                    </option>
                                @endforeach
                            </x-ui.form.select>

                            <x-ui.form.input name="grade" label="Grade" maxlength="8" :value="old('grade')"
                                             help="Only used when the institute grades certificates by hand." />

                            <x-ui.form.input type="number" step="0.01" min="0" max="10"
                                             name="grade_point" label="Grade points" :value="old('grade_point')" />

                            <x-ui.form.input type="number" step="0.0001" min="0" max="100"
                                             name="percentage" label="Percentage" suffix="%" :value="old('percentage')" />

                            <div class="sm:col-span-2">
                                <x-ui.form.textarea name="notes" label="Notes" rows="2" :value="old('notes')"
                                                    help="For the office. Never printed." />
                            </div>
                        </div>
                    </x-ui.card>

                    <x-ui.card>
                        <x-ui.section-heading title="Eligibility, as of right now" />

                        <div class="mt-3">
                            @include('admin.certificates._eligibility', ['report' => $report])
                        </div>
                    </x-ui.card>
                </div>

                <div class="space-y-4">
                    <x-ui.card>
                        <x-ui.section-heading title="The student" />

                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500 dark:text-slate-400">Name</dt>
                                <dd class="text-slate-700 dark:text-slate-200">{{ $enrollment->student?->name ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500 dark:text-slate-400">Roll number</dt>
                                <dd class="text-slate-700 dark:text-slate-200">{{ $enrollment->student?->student_code ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500 dark:text-slate-400">Course</dt>
                                <dd class="text-slate-700 dark:text-slate-200">{{ $enrollment->batch?->course?->name ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500 dark:text-slate-400">Batch</dt>
                                <dd class="text-slate-700 dark:text-slate-200">{{ $enrollment->batch?->code ?? '—' }}</dd>
                            </div>
                        </dl>
                    </x-ui.card>

                    <x-ui.card>
                        <div class="flex flex-col gap-2">
                            <x-ui.button type="submit" icon="check">Create the draft</x-ui.button>
                            <x-ui.button variant="ghost" :href="route('admin.certificates.eligible')">Cancel</x-ui.button>
                        </div>
                    </x-ui.card>
                </div>
            </div>
        </form>
    @endif
@endsection
