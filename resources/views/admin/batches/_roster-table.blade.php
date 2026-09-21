{{--
    The roster, shared by the batch detail screen and the standalone roster page.

    Every row's actions are the enrolment's, not the batch's: transferring is `batches.assign` and
    dropping is `students.change_status`, because one is a decision about the class and the other is a
    decision about the person.
--}}

<x-ui.table :is-empty="$roster->isEmpty()">
    <x-slot:head>
        <th class="px-4 py-3 text-left font-semibold">Roll</th>
        <th class="px-4 py-3 text-left font-semibold">Student</th>
        <th class="px-4 py-3 text-left font-semibold">Enrolled</th>
        <th class="px-4 py-3 text-left font-semibold">Status</th>
        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
    </x-slot:head>

    @foreach ($roster as $enrollment)
        <tr>
            <td class="px-4 py-3 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $enrollment->roll_number ?: '—' }}</td>
            <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                    <x-ui.avatar :name="$enrollment->student?->name" size="sm" />
                    <div>
                        <a href="{{ route('admin.students.show', $enrollment->student_id) }}"
                           class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $enrollment->student?->name ?? 'Unknown' }}</a>
                        <div class="text-xs text-slate-400">{{ $enrollment->student?->student_code }}</div>
                    </div>
                </div>
            </td>
            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                {{ app_date($enrollment->enrolled_on) }}
                @if ($enrollment->left_on)
                    <div class="text-xs text-slate-400">left {{ app_date($enrollment->left_on) }}</div>
                @endif
            </td>
            <td class="px-4 py-3">
                <x-ui.badge :color="$enrollment->status->color()" size="xs">{{ $enrollment->status->label() }}</x-ui.badge>
                @if ($enrollment->is_overbooked)
                    <x-ui.badge color="amber" size="xs" class="ml-1" title="{{ $enrollment->overbook_reason }}">Over capacity</x-ui.badge>
                @endif
            </td>
            <td class="px-4 py-3 text-right">
                @if ($enrollment->status->countsInCapacity())
                    <div class="flex justify-end gap-1">
                        @can('batches.assign')
                            <x-ui.button variant="ghost" size="sm" icon="arrows-right-left"
                                         x-on:click="$dispatch('open-modal', 'transfer-{{ $enrollment->id }}')">Transfer</x-ui.button>
                        @endcan
                        @can('students.change_status')
                            <x-ui.button variant="ghost" size="sm" icon="arrow-path"
                                         x-on:click="$dispatch('open-modal', 'enrollment-status-{{ $enrollment->id }}')">Status</x-ui.button>
                        @endcan
                    </div>
                @endif
            </td>
        </tr>
    @endforeach

    <x-slot:empty>
        <x-ui.empty-state icon="users" title="Nobody enrolled yet"
                          description="Seat a student from an admission, or add one directly from this screen." />
    </x-slot:empty>
</x-ui.table>

@foreach ($roster as $enrollment)
    @if ($enrollment->status->countsInCapacity())
        @can('batches.assign')
            <x-ui.modal name="transfer-{{ $enrollment->id }}" title="Transfer to another batch" icon="arrows-right-left">
                <form method="POST" action="{{ route('admin.enrollments.transfer', $enrollment) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {{ $enrollment->student?->name }} keeps the attendance they earned here — it stays with
                        this batch, where it happened, and the new seat starts empty. Both rows point at each other.
                    </p>

                    <x-ui.form.select name="batch_id" label="Move to" required placeholder="Pick a batch">
                        @foreach ($otherBatches as $id => $code)
                            <option value="{{ $id }}">{{ $code }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.textarea name="reason" label="Why?" required rows="2"
                                        help="It goes on both enrolments — it is what explains the gap in this batch's register." />

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost"
                                     x-on:click="$dispatch('close-modal', 'transfer-{{ $enrollment->id }}')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Transfer</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endcan

        @can('students.change_status')
            <x-ui.modal name="enrollment-status-{{ $enrollment->id }}" title="Change this enrolment" icon="arrow-path">
                <form method="POST" action="{{ route('admin.enrollments.status', $enrollment) }}" class="space-y-4">
                    @csrf
                    <x-ui.form.select name="status" label="Move to" required>
                        @foreach ($enrollmentStatuses as $value => $label)
                            <option value="{{ $value }}" @selected($enrollment->status->value === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.textarea name="reason" label="Reason" rows="2"
                                        help="Required for dropped and suspended." />

                    <p class="text-xs text-slate-400">
                        Cancelled means "enrolled in error" and is refused once a register carries this
                        student's name. Dropped keeps the history.
                    </p>

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost"
                                     x-on:click="$dispatch('close-modal', 'enrollment-status-{{ $enrollment->id }}')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endcan
    @endif
@endforeach
