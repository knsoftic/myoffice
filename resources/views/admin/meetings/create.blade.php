@extends('layouts.admin')

@section('title', 'New meeting')

{{--
    Booking one — admin.meetings.create (phase-19-23 §6.17, §8).

    The guest list is a repeater: a row is a person **or** an outside guest's name and email, never
    both. There is no "type" field — `participant_type` is derived from the person's own profiles,
    because a field on this form is a student filing themselves as staff and §9.4 reads that column.

    The room field is offered whatever the delivery mode, and the service ignores it for an online
    meeting: a form that hid it would lose what somebody typed the moment they changed their mind
    about the mode.
--}}

@section('header')
    <x-ui.page-header title="New meeting" subtitle="Who, when, where, and what it is about." icon="video-camera">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.meetings.index')">Back to the diary</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.meetings.store') }}" class="space-y-4"
          x-data="{ rows: [{ user_id: '', external_name: '', external_email: '', role: 'required' }] }">
        @csrf

        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-ui.form.input name="title" label="Title" :value="old('title')" required />
                </div>

                <x-ui.form.input type="datetime-local" name="scheduled_at" label="Starts" :value="old('scheduled_at')" required />
                <x-ui.form.input type="number" name="duration_minutes" label="Minutes"
                                 :value="old('duration_minutes', $defaultDuration)" required
                                 help="Between 5 and 1440. Longer than a day is two meetings." />

                <x-ui.form.select name="delivery_mode" label="How" required>
                    <option value="online" @selected(old('delivery_mode', 'online') === 'online')>Online</option>
                    <option value="physical" @selected(old('delivery_mode') === 'physical')>In person</option>
                    <option value="hybrid" @selected(old('delivery_mode') === 'hybrid')>Both</option>
                </x-ui.form.select>

                <x-ui.form.input type="url" name="meeting_url" label="Joining link" :value="old('meeting_url')"
                                 help="Required for an online meeting — without one the invitation tells people to be nowhere." />

                <x-ui.form.select name="classroom_id" label="Room" placeholder="No room">
                    @foreach ($classrooms as $classroom)
                        <option value="{{ $classroom->id }}" @selected((int) old('classroom_id') === (int) $classroom->id)>
                            {{ $classroom->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.input name="location" label="Or an address" :value="old('location')" />

                <x-ui.form.input type="number" name="reminder_minutes_before" label="Remind everybody (minutes before)"
                                 :value="old('reminder_minutes_before', $defaultReminder)" />

                <div class="sm:col-span-2">
                    <x-ui.form.textarea name="agenda" label="Agenda" :value="old('agenda')" rows="6" />
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <x-ui.section-heading title="Who is coming"
                                  description="Somebody with an account, or an outside guest's name and email. The organiser is added automatically." />

            <div class="mt-3 space-y-3">
                <template x-for="(row, index) in rows" :key="index">
                    <div class="grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-4 dark:border-slate-700">
                        <select :name="`participants[${index}][user_id]`" x-model="row.user_id"
                                class="h-10 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                            <option value="">An outside guest</option>
                            @foreach ($people as $person)
                                <option value="{{ $person->id }}">{{ $person->name }}</option>
                            @endforeach
                        </select>

                        @if ($allowExternal)
                            <input type="text" :name="`participants[${index}][external_name]`" x-model="row.external_name"
                                   :disabled="row.user_id !== ''" placeholder="Guest name"
                                   class="h-10 rounded-lg border-slate-300 text-sm disabled:opacity-40 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">

                            <input type="email" :name="`participants[${index}][external_email]`" x-model="row.external_email"
                                   :disabled="row.user_id !== ''" placeholder="guest@example.com"
                                   class="h-10 rounded-lg border-slate-300 text-sm disabled:opacity-40 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                        @else
                            <p class="col-span-2 self-center text-xs text-slate-400">
                                Outside guests are switched off for this installation.
                            </p>
                        @endif

                        <div class="flex gap-2">
                            <select :name="`participants[${index}][role]`" x-model="row.role"
                                    class="h-10 w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                                @foreach ($roles as $role)
                                    @continue($role->value === 'organizer')
                                    <option value="{{ $role->value }}">{{ $role->label() }}</option>
                                @endforeach
                            </select>

                            <button type="button" x-on:click="rows.splice(index, 1)" x-show="rows.length > 1"
                                    class="rounded-lg px-2 text-slate-400 hover:text-rose-500">&times;</button>
                        </div>
                    </div>
                </template>

                <x-ui.button type="button" variant="ghost" icon="plus"
                             x-on:click="rows.push({ user_id: '', external_name: '', external_email: '', role: 'required' })">
                    Add somebody
                </x-ui.button>
            </div>

            <x-ui.form.error name="participants" />
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.meetings.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="calendar-days">Schedule</x-ui.button>
        </div>
    </form>
@endsection
