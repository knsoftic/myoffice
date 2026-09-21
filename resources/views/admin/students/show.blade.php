@extends('layouts.admin')

@section('title', $student->name)

@section('header')
    <x-ui.page-header :title="$student->name"
                      :subtitle="$student->student_code . ($student->registration_number ? ' · ' . $student->registration_number : '')"
                      icon="users"
                      :back="route('admin.students.index')">
        <x-slot:actions>
            <x-ui.badge :color="$student->status->color()" size="lg">{{ $student->status->label() }}</x-ui.badge>

            @can('update', $student)
                <x-ui.button variant="secondary" icon="pencil" :href="route('admin.students.edit', $student)">Edit</x-ui.button>
            @endcan

            @can('admissions.create')
                <x-ui.button variant="primary" icon="user-plus"
                             :href="route('admin.admissions.create', ['student_id' => $student->id])">Start admission</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4">
            <x-ui.card title="Profile">
                <div class="mb-4 flex items-center gap-3">
                    <x-ui.avatar :name="$student->name" :src="$student->photo_path" size="lg" />
                    <div>
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ $student->name }}</div>
                        @if (filled($student->father_name))
                            <div class="text-sm text-slate-500">s/o {{ $student->father_name }}</div>
                        @endif
                    </div>
                </div>

                <dl class="space-y-2 text-sm">
                    @foreach ([
                        'Phone' => $student->phone,
                        'WhatsApp' => $student->whatsapp,
                        'Email' => $student->email,
                        'CNIC' => $student->formattedCnic(),
                        'City' => $student->city,
                        'Education' => $student->education,
                        'Guardian' => $student->guardian_name,
                        'Branch' => $student->branch?->name,
                    ] as $label => $value)
                        @if (filled($value))
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500">{{ $label }}</dt>
                                <dd class="text-right text-slate-700 dark:text-slate-200">{{ $value }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card title="Panel login">
                @if ($student->user)
                    <p class="text-sm text-slate-600 dark:text-slate-300">{{ $student->user->email }}</p>
                    <x-ui.badge :color="$student->user->status->color()" size="xs" class="mt-2">
                        {{ $student->user->status->label() }}
                    </x-ui.badge>
                    <p class="mt-2 text-xs text-slate-500">
                        Suspending or dropping the student puts this account out of action, and reinstating
                        them brings it back.
                    </p>
                @else
                    <p class="text-sm text-slate-500">No login yet.</p>
                    @can('createLogin', $student)
                        <form method="POST" action="{{ route('admin.students.login.store', $student) }}" class="mt-3">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" icon="key" :disabled="blank($student->email)">
                                Create login
                            </x-ui.button>
                        </form>
                        @if (blank($student->email))
                            <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                                Needs an email address — that is where the password goes.
                            </p>
                        @endif
                    @endcan
                @endif
            </x-ui.card>

            @if (filled($student->referral_code))
                <x-ui.card title="Referred by">
                    <div class="flex items-center gap-2">
                        <x-ui.badge color="emerald">{{ $student->referral_code }}</x-ui.badge>
                        <span class="text-sm text-slate-600 dark:text-slate-300">{{ $student->collaborator?->name }}</span>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">
                        A snapshot of the attribution row, kept up to date by the referral service. Changing
                        it is done on the referral, not here.
                    </p>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4 lg:col-span-2">
            <x-ui.card :title="'Admissions (' . $student->admissions->count() . ')'">
                <x-ui.table :is-empty="$student->admissions->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Admission</th>
                        <th class="px-4 py-3 text-left font-semibold">Course</th>
                        <th class="px-4 py-3 text-left font-semibold">Stage</th>
                        @if ($canSeeAdmissionMoney)
                            <th class="px-4 py-3 text-right font-semibold">Net payable</th>
                            <th class="px-4 py-3 text-right font-semibold">Balance</th>
                        @endif
                    </x-slot:head>

                    @foreach ($student->admissions as $admission)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.admissions.show', $admission) }}"
                                   class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $admission->admission_number }}</a>
                                <div class="text-xs text-slate-400">{{ app_date($admission->admission_date) }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $admission->course?->name }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$admission->stage->color()">{{ $admission->stage->label() }}</x-ui.badge></td>
                            @if ($canSeeAdmissionMoney)
                                <td class="px-4 py-3 text-right tabular-nums">{{ money($admission->net_payable) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ money($admission->balance_amount) }}</td>
                            @endif
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="user-plus" title="No admission yet"
                                          description="An admission is where the course, the agreed fee and the batch live.">
                            @can('admissions.create')
                                <x-ui.button variant="primary" icon="plus"
                                             :href="route('admin.admissions.create', ['student_id' => $student->id])">Start admission</x-ui.button>
                            @endcan
                        </x-ui.empty-state>
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>

            @can('changeStatus', $student)
                <x-ui.card title="Change status"
                           subtitle="Suspending or dropping takes a reason, and it goes on the record.">
                    <form method="POST" action="{{ route('admin.students.status', $student) }}"
                          class="grid gap-3 sm:grid-cols-3">
                        @csrf
                        <x-ui.form.select name="status" label="Move to" required>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}" @selected($student->status->value === $value)>{{ $label }}</option>
                            @endforeach
                        </x-ui.form.select>
                        <x-ui.form.input name="reason" label="Reason" />
                        <div class="flex items-end">
                            <x-ui.button type="submit" variant="secondary" icon="arrow-path">Update</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endcan

            @if ($hasHistory)
                <x-ui.card>
                    <div class="flex items-start gap-3">
                        <x-ui.icon name="lock-closed" class="mt-0.5 h-5 w-5 shrink-0 text-slate-400" />
                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            This student has fee, enrolment or attendance rows against them, so the record
                            cannot be deleted — the history is the point of keeping it. Marking them
                            <strong>dropped</strong> with a reason is what ends the relationship.
                        </p>
                    </div>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
