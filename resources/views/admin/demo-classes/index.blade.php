@extends('layouts.admin')

@section('title', 'Demo classes')

@section('header')
    <x-ui.page-header title="Demo classes"
                      subtitle="A demo holds a teacher and a room, so it is booked like a class and clashes like one."
                      icon="video-camera">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="calendar-days" :href="route('admin.demo-classes.calendar')">Calendar</x-ui.button>
            @if ($booking !== null)
                <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'book-demo')">Book a demo</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Upcoming" :value="app_number($counts['upcoming'])" icon="calendar-days" color="sky" />
        <x-ui.stat-card label="Today" :value="app_number($counts['today'])" icon="clock" color="brand" />
        <x-ui.stat-card label="Attended" :value="app_number($counts['attended'])" icon="check" color="emerald" />
        <x-ui.stat-card label="Converted" :value="app_number($counts['converted'])" icon="user-plus" color="violet" />
    </div>

    @if ($unmarked->isNotEmpty())
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <div class="flex items-start gap-3">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div>
                    <p class="font-medium text-slate-700 dark:text-slate-200">
                        {{ $unmarked->count() }} {{ \Illuminate\Support\Str::plural('demo', $unmarked->count()) }} finished without being marked
                    </p>
                    <p class="mt-1 text-sm text-slate-500">
                        Whether somebody turned up is a fact only the person in the room has, so nothing is
                        marked automatically. Mark them attended or missed below.
                    </p>
                </div>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Attendee name or phone" />

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($courses as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('course_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.input name="from" label="From" type="date" :value="request('from')" />
            <x-ui.form.input name="to" label="To" type="date" :value="request('to')" />

            <div class="flex items-end gap-2 sm:col-span-2 xl:col-span-5">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.demo-classes.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <x-ui.table :is-empty="$demos->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">When</th>
                <th class="px-4 py-3 text-left font-semibold">Attendee</th>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-left font-semibold">Mode</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3 text-left font-semibold">Remarks</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($demos as $demo)
                <tr>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ app_date($demo->scheduled_on) }}</div>
                        <div class="text-xs text-slate-400">{{ app_clock($demo->startsAt()) }} – {{ app_clock($demo->endsAt()) }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ $demo->attendee_name }}</div>
                        <div class="flex items-center gap-2">
                            <x-ui.badge :color="$demo->subject_type->color()" size="xs">{{ $demo->subject_type->label() }}</x-ui.badge>
                            @if (filled($demo->attendee_phone))
                                <a href="tel:{{ $demo->attendee_phone }}" class="text-xs text-slate-500 hover:underline">{{ $demo->attendee_phone }}</a>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $demo->course?->name ?? '—' }}</td>
                    <td class="px-4 py-3"><x-ui.badge :color="$demo->delivery_mode->color()" size="xs">{{ $demo->delivery_mode->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3"><x-ui.badge :color="$demo->status->color()">{{ $demo->status->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-xs text-slate-500">{{ \Illuminate\Support\Str::limit((string) $demo->attendance_remarks, 60) ?: '—' }}</td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex flex-wrap justify-end gap-1">
                            @if ($demo->status === \App\Enums\DemoClassStatus::Scheduled)
                                @can('changeStatus', $demo)
                                    <x-ui.button variant="ghost" size="sm" icon="check"
                                                 x-on:click="$dispatch('open-modal', 'mark-demo-{{ $demo->id }}')">Mark</x-ui.button>
                                @endcan
                                @can('reschedule', $demo)
                                    <x-ui.button variant="ghost" size="sm" icon="arrow-path"
                                                 x-on:click="$dispatch('open-modal', 'move-demo-{{ $demo->id }}')">Reschedule</x-ui.button>
                                @endcan
                            @endif
                            @can('print', $demo)
                                <x-ui.button variant="ghost" size="sm" icon="printer" :href="route('admin.demo-classes.slip', $demo)">Slip</x-ui.button>
                            @endcan
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="video-camera" title="No demo classes scheduled"
                                  description="A demo is booked against an inquiry, an applicant or a student." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$demos" label="demos" />
    </x-ui.card>

    @foreach ($demos as $demo)
        @if ($demo->status === \App\Enums\DemoClassStatus::Scheduled)
            @can('changeStatus', $demo)
                <x-ui.modal name="mark-demo-{{ $demo->id }}" :title="'What happened with '.$demo->attendee_name.'’s demo?'" icon="check"
                            :show="old('_form') === 'mark-'.$demo->id && $errors->any()">
                    <form method="POST" action="{{ route('admin.demo-classes.status', $demo) }}" class="space-y-4"
                          x-data="{ status: @js(old('_form') === 'mark-'.$demo->id ? old('status', 'attended') : 'attended') }">
                        @csrf
                        <input type="hidden" name="_form" value="mark-{{ $demo->id }}">
                        <x-ui.form.select name="status" label="What happened?" required x-model="status">
                            <option value="attended">They attended</option>
                            <option value="missed">They did not turn up</option>
                            <option value="cancelled">Cancel this demo</option>
                        </x-ui.form.select>

                        <div x-show="status !== 'cancelled'">
                            <x-ui.form.textarea name="remarks" label="Anything worth noting?" rows="2"
                                                help="The counsellor following this up reads it." />
                        </div>

                        <div x-show="status === 'cancelled'" x-cloak>
                            <x-ui.form.input name="reason" label="Why is it cancelled?"
                                             help="Required when cancelling — the slot goes back to the teacher and room." />
                        </div>

                        <div class="flex justify-end gap-2">
                            <x-ui.button type="button" variant="ghost"
                                         x-on:click="$dispatch('close-modal', 'mark-demo-{{ $demo->id }}')">Close</x-ui.button>
                            <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                        </div>
                    </form>
                </x-ui.modal>
            @endcan

            @can('reschedule', $demo)
                <x-ui.modal name="move-demo-{{ $demo->id }}" :title="'Move '.$demo->attendee_name.'’s demo'" icon="arrow-path" size="lg"
                            :show="old('_form') === 'move-'.$demo->id && $errors->any()">
                    <form method="POST" action="{{ route('admin.demo-classes.reschedule', $demo) }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="_form" value="move-{{ $demo->id }}">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <x-ui.form.input name="scheduled_on" label="Date" type="date" required :value="$demo->scheduled_on->toDateString()" />
                            <x-ui.form.input name="start_time" label="From" type="time" required :value="substr((string) $demo->start_time, 0, 5)" />
                            <x-ui.form.input name="end_time" label="To" type="time" :value="substr((string) $demo->end_time, 0, 5)" />
                        </div>
                        <x-ui.form.input name="reason" label="Why is it moving?" required />

                        <div class="flex justify-end gap-2">
                            <x-ui.button type="button" variant="ghost"
                                         x-on:click="$dispatch('close-modal', 'move-demo-{{ $demo->id }}')">Close</x-ui.button>
                            <x-ui.button type="submit" variant="primary">Move it</x-ui.button>
                        </div>
                    </form>
                </x-ui.modal>
            @endcan
        @endif
    @endforeach

    @if ($booking !== null)
        @php
            $bookType = old('subject_type', request('subject_type', 'inquiry'));
            $bookId = old('subject_id', request('subject_id'));
        @endphp
        <x-ui.modal name="book-demo" title="Book a demo class" icon="video-camera" size="lg"
                    :show="(old('_form') === 'book' && $errors->any()) || request()->filled('subject_id')">
            <form method="POST" action="{{ route('admin.demo-classes.store') }}" class="space-y-4"
                  x-data="{ type: @js($bookType), mode: @js(old('delivery_mode', 'physical')) }">
                @csrf
                <input type="hidden" name="_form" value="book">
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    A demo is booked for somebody who enquired, applied or is already a student. It holds the
                    teacher and the room, so it is clash-checked like a class.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.select name="subject_type" label="Who is it for?" required x-model="type">
                        <option value="inquiry">An enquiry</option>
                        <option value="application">An applicant</option>
                        <option value="student">A student</option>
                    </x-ui.form.select>

                    <template x-if="type === 'inquiry'">
                        <x-ui.form.select name="subject_id" label="Enquiry" required placeholder="Pick an enquiry"
                                          :options="$booking['inquiries']->pluck('label', 'id')" :selected="$bookType === 'inquiry' ? $bookId : null" />
                    </template>
                    <template x-if="type === 'application'">
                        <x-ui.form.select name="subject_id" label="Applicant" required placeholder="Pick an applicant"
                                          :options="$booking['applications']->pluck('label', 'id')" :selected="$bookType === 'application' ? $bookId : null" />
                    </template>
                    <template x-if="type === 'student'">
                        <x-ui.form.select name="subject_id" label="Student" required placeholder="Pick a student"
                                          :options="$booking['students']->pluck('label', 'id')" :selected="$bookType === 'student' ? $bookId : null" />
                    </template>

                    <x-ui.form.select name="course_id" label="Course" required placeholder="Pick a course"
                                      :options="$booking['courses']" :selected="old('course_id', request('course_id'))" />

                    <x-ui.form.select name="teacher_id" label="Teacher" placeholder="Not decided yet"
                                      :options="$booking['teachers']" :selected="old('teacher_id')" />

                    <x-ui.form.select name="delivery_mode" label="How" required x-model="mode"
                                      :options="$deliveryModes" :selected="old('delivery_mode', 'physical')" />

                    <div x-show="mode !== 'online'">
                        <x-ui.form.select name="classroom_id" label="Classroom" placeholder="No room"
                                          :options="$booking['classrooms']" :selected="old('classroom_id')" />
                    </div>

                    <div x-show="mode !== 'physical'" x-cloak class="sm:col-span-2">
                        <x-ui.form.input name="meeting_url" label="Meeting link" type="url" :value="old('meeting_url')"
                                         placeholder="https://meet.google.com/…" />
                    </div>

                    <x-ui.form.input name="scheduled_on" label="Date" type="date" required
                                     :value="old('scheduled_on', now()->toDateString())" />
                    <div class="grid grid-cols-2 gap-4">
                        <x-ui.form.input name="start_time" label="From" type="time" required :value="old('start_time')" />
                        <x-ui.form.input name="end_time" label="To" type="time" :value="old('end_time')"
                                         help="Blank uses the default length." />
                    </div>
                </div>

                <x-ui.form.textarea name="notes" label="Notes" rows="2" :value="old('notes')" />

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'book-demo')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Book the demo</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
