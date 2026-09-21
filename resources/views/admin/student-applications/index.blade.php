@extends('layouts.admin')

@section('title', 'Admission applications')

@section('header')
    <x-ui.page-header title="Admission applications"
                      subtitle="The public form, triaged before anybody becomes a student record."
                      icon="inbox-arrow-down">
        <x-slot:actions>
            @can('student_applications.export')
                <x-ui.button variant="ghost" icon="arrow-down-tray"
                             :href="route('admin.student-applications.export', ['format' => 'csv', ...request()->query()])">Export</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    {{-- Link tabs: each status is its own page, so a reviewer can bookmark the queue they work. --}}
    <x-ui.tabs :tabs="collect($statuses)->map(fn ($label, $value) => [
                    'label' => $label,
                    'count' => $counts[$value] ?? 0,
                    'url' => route('admin.student-applications.index', ['status' => $value]),
                    'active' => request('status') === $value,
                ])->values()->all()"
               variant="pill"
               class="mb-4" />

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <input type="hidden" name="status" value="{{ request('status') }}">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Name, phone or application #" />

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($courses as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('course_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="referral" label="Referral" placeholder="Any">
                <option value="valid" @selected(request('referral') === 'valid')>Valid code</option>
                <option value="invalid" @selected(request('referral') === 'invalid')>Code not recognised</option>
                <option value="none" @selected(request('referral') === 'none')>No code</option>
            </x-ui.form.select>

            <div class="flex items-end gap-2 sm:col-span-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.student-applications.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <x-ui.table :is-empty="$applications->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Application</th>
                <th class="px-4 py-3 text-left font-semibold">Applicant</th>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-left font-semibold">Preference</th>
                <th class="px-4 py-3 text-left font-semibold">Referral</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3 text-left font-semibold">Reviewer</th>
            </x-slot:head>

            @foreach ($applications as $application)
                @php($duplicates = $application->possibleDuplicates()->count())
                <tr @class([
                    'border-l-4',
                    'border-l-amber-400' => $duplicates > 0,
                    'border-l-transparent' => $duplicates === 0,
                ])>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.student-applications.show', $application) }}"
                           class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $application->application_number }}</a>
                        <div class="text-xs text-slate-400">{{ app_datetime($application->created_at) }}</div>
                        @if ($duplicates > 0)
                            <div class="mt-1 text-xs font-medium text-amber-600 dark:text-amber-400">
                                {{ $duplicates }} possible {{ \Illuminate\Support\Str::plural('duplicate', $duplicates) }}
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ $application->name }}</div>
                        <a href="tel:{{ $application->phone }}" class="text-xs text-slate-500 hover:underline">{{ $application->phone }}</a>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $application->course?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-xs text-slate-500">
                        {{ $application->preferred_timing?->label() ?? 'Any time' }} ·
                        {{ $application->preferred_delivery_mode?->label() ?? 'Any mode' }}
                    </td>
                    <td class="px-4 py-3">
                        @if (filled($application->referral_code))
                            <x-ui.badge :color="$application->hasValidReferral() ? 'emerald' : 'amber'" size="xs">
                                {{ $application->referral_code }}
                            </x-ui.badge>
                        @else
                            <span class="text-xs text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3"><x-ui.badge :color="$application->status->color()">{{ $application->status->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $application->reviewer?->name ?? '—' }}</td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="inbox-arrow-down"
                                  title="Nothing waiting"
                                  :description="$formOpen
                                      ? 'The public form is open — applications land here as they arrive.'
                                      : 'The public form is currently closed, so nothing can arrive.'">
                    @can('settings.edit')
                        <x-ui.button variant="ghost" :href="route('admin.settings.index', ['group' => 'institute'])">Institute settings</x-ui.button>
                    @endcan
                </x-ui.empty-state>
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$applications" label="applications" />
    </x-ui.card>
@endsection
