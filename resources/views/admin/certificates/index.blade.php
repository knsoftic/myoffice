@extends('layouts.admin')

@section('title', 'Certificates')

@section('header')
    <x-ui.page-header title="Certificates"
                      subtitle="The register. A draft has no number; an issued certificate is fixed and verifiable from its QR code; a revoked one still resolves, and says why."
                      icon="check-badge">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="arrow-down-tray"
                         :href="route('admin.certificates.export', ['format' => 'csv'] + request()->query())">Export</x-ui.button>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.certificates.eligible')">Who is eligible?</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        @foreach ($statuses as $status)
            <x-ui.stat-card :label="$status->label()"
                            :value="app_number($counts[$status->value] ?? 0)"
                            :color="$status->color()" />
        @endforeach
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.input name="q" label="Search" :value="request('q')"
                             placeholder="Number, name, roll or code" />

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
                <x-ui.button variant="ghost" :href="route('admin.certificates.index')">Clear</x-ui.button>
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2 dark:text-slate-300">
                <input type="checkbox" name="trashed" value="1" @checked(request()->boolean('trashed'))
                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                Only deleted drafts
            </label>
        </form>
    </x-ui.card>

    {{--
        The register doubles as the bulk-issue screen, and the tick box only appears on a draft:
        issuing is the only bulk action, and it is the only status it can apply to. A row that
        cannot be part of the action does not offer a control that pretends it can.
    --}}
    <form method="POST" action="{{ route('admin.certificates.bulk-issue') }}">
        @csrf

        <x-ui.card :padded="false">
        <x-ui.table :is-empty="$certificates->isEmpty()">
            <x-slot:head>
                <th class="w-10 px-4 py-3"><span class="sr-only">Select</span></th>
                <th class="px-4 py-3 text-left font-semibold">Certificate</th>
                <th class="px-4 py-3 text-left font-semibold">Student</th>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-left font-semibold">Completed</th>
                <th class="px-4 py-3 text-right font-semibold">Result</th>
                <th class="px-4 py-3 text-right font-semibold">Prints / scans</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($certificates as $certificate)
                <tr>
                    <td class="px-4 py-3">
                        @if ($certificate->status === \App\Enums\CertificateStatus::Draft)
                            <input type="checkbox" name="certificate_ids[]" value="{{ $certificate->id }}"
                                   aria-label="Select this draft"
                                   class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.certificates.show', $certificate) }}"
                           class="font-mono text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">
                            {{ $certificate->certificate_number ?? 'Not numbered yet' }}
                        </a>
                        <div class="text-xs text-slate-400">
                            {{ $certificate->issued_on ? 'issued '.app_date($certificate->issued_on) : 'draft' }}
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $certificate->student_name_snapshot }}
                        <div class="text-xs text-slate-400">{{ $certificate->student_code_snapshot }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $certificate->course_name_snapshot }}
                        @if ($certificate->batch_name_snapshot)
                            <div class="text-xs text-slate-400">{{ $certificate->batch_name_snapshot }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ app_date($certificate->completion_date) }}
                    </td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        {{ $certificate->grade ?? '—' }}
                        @if ($certificate->percentage !== null)
                            <div class="text-xs text-slate-400">{{ app_number($certificate->percentage, 2) }}%</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_number($certificate->print_count) }} / {{ app_number($certificate->verification_count) }}
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$certificate->status->color()" size="xs">{{ $certificate->status->label() }}</x-ui.badge>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="check-badge" title="No certificates yet"
                                  message="Certificates are drafted from the eligibility screen, which shows every enrolment with the rules it does and does not meet.">
                    @if ($canCreate)
                        <x-slot:action>
                            <x-ui.button icon="plus" :href="route('admin.certificates.eligible')">Who is eligible?</x-ui.button>
                        </x-slot:action>
                    @endif
                </x-ui.empty-state>
            </x-slot:empty>
        </x-ui.table>

        @unless ($certificates->isEmpty())
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 dark:border-slate-800">
                <div>
                    <x-ui.button type="submit" size="sm" variant="secondary" icon="check-badge">
                        Issue the drafts ticked
                    </x-ui.button>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Each one is checked on its own. A draft that does not meet the rules is left alone
                        and named back to you — there is no override in bulk.
                    </p>
                </div>
                <x-ui.pagination-summary :paginator="$certificates" label="certificates" class="border-0 p-0" />
            </div>
        @else
            <x-ui.pagination-summary :paginator="$certificates" label="certificates" />
        @endunless
        </x-ui.card>
    </form>
@endsection
