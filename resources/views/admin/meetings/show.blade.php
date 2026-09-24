@extends('layouts.admin')

@section('title', $meeting->title)

{{--
    One meeting — admin.meetings.show (phase-19-23 §6.17, §9.4).

    Notes are behind `viewNotes`, which is not the same question as `view`: a portal participant
    reads the minutes only once the meeting is `completed`, because notes taken during one are
    working notes and a client reading half a sentence about their own project is worse than waiting
    a day.

    Cancelling and rescheduling both take a reason, and both say so on the form rather than after it.
--}}

@section('header')
    <x-ui.page-header :title="$meeting->title" :subtitle="app_datetime($meeting->scheduled_at)" icon="video-camera">
        <x-slot:actions>
            @if ($canDownloadIcs)
                <x-ui.button variant="secondary" icon="calendar-days" :href="route('admin.meetings.ics', $meeting)">Add to calendar</x-ui.button>
            @endif
            <x-ui.button variant="ghost" :href="route('admin.meetings.index')">Back to the diary</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if (session('clashes'))
        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
            <p class="font-medium">This booking overlaps something else:</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach (session('clashes') as $clash)
                    <li>{{ $clash }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card>
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <x-ui.badge :color="$meeting->status->color()" size="sm">{{ $meeting->status->label() }}</x-ui.badge>
                    <x-ui.badge color="slate" size="sm">{{ $meeting->delivery_mode->label() }}</x-ui.badge>
                    @if ($meeting->is_private)
                        <x-ui.badge color="amber" size="sm">Private</x-ui.badge>
                    @endif
                </div>

                <dl class="grid gap-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">When</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            {{ app_datetime($meeting->scheduled_at) }} · {{ app_number($meeting->duration_minutes) }} minutes
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Where</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            {{ $meeting->classroom?->name ?? $meeting->location ?? '—' }}
                        </dd>
                    </div>
                    @if ($meeting->meeting_url)
                        <div class="sm:col-span-2">
                            <dt class="text-slate-500 dark:text-slate-400">Joining link</dt>
                            <dd><a href="{{ $meeting->meeting_url }}" rel="noopener noreferrer" target="_blank"
                                   class="text-brand-600 hover:underline dark:text-brand-400">{{ $meeting->meeting_url }}</a></dd>
                        </div>
                    @endif
                    @if ($meeting->cancellation_reason)
                        <div class="sm:col-span-2">
                            <dt class="text-slate-500 dark:text-slate-400">Reason</dt>
                            <dd class="text-slate-700 dark:text-slate-200">{{ $meeting->cancellation_reason }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($meeting->agenda)
                    <div class="prose prose-sm mt-4 max-w-none dark:prose-invert">{!! \App\Support\RichText::sanitize((string) $meeting->agenda) !!}</div>
                @endif
            </x-ui.card>

            <x-ui.card :padded="false">
                <div class="px-4 pt-4">
                    <x-ui.section-heading title="Who is coming"
                                          :description="app_number($quorum['accepted']).' of '.app_number($quorum['needed']).' required people have accepted'" />
                </div>

                <form method="POST" action="{{ route('admin.meetings.attendance', $meeting) }}">
                    @csrf
                    <x-ui.table :is-empty="$meeting->participants->isEmpty()">
                        <x-slot:head>
                            <th class="px-4 py-3 text-left font-semibold">Person</th>
                            <th class="px-4 py-3 text-left font-semibold">Role</th>
                            <th class="px-4 py-3 text-left font-semibold">Answer</th>
                            @if ($canMarkAttendance)
                                <th class="px-4 py-3 text-center font-semibold">Came</th>
                            @endif
                            @if ($canAssign)
                                <th class="px-4 py-3 text-right font-semibold"></th>
                            @endif
                        </x-slot:head>

                        @foreach ($meeting->participants as $participant)
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="text-sm text-slate-700 dark:text-slate-200">{{ $participant->displayName() }}</div>
                                    <div class="text-xs text-slate-400">
                                        {{ $participant->displayEmail() ?? '—' }} ·
                                        {{ $participant->participant_type->label() }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $participant->role->label() }}</td>
                                <td class="px-4 py-3">
                                    <x-ui.badge :color="$participant->response->color()" size="sm">
                                        {{ $participant->response->label() }}
                                    </x-ui.badge>
                                </td>
                                @if ($canMarkAttendance)
                                    <td class="px-4 py-3 text-center">
                                        <input type="checkbox" name="attended[{{ $participant->id }}]" value="1"
                                               @checked($participant->attended)
                                               class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 dark:border-slate-600 dark:bg-slate-800">
                                    </td>
                                @endif
                                @if ($canAssign)
                                    <td class="px-4 py-3 text-right">
                                        @if ($participant->role->value !== 'organizer')
                                            <x-ui.confirm :action="route('admin.meetings.participants.destroy', [$meeting, $participant])"
                                                          method="DELETE"
                                                          title="Take {{ $participant->displayName() }} off the guest list?"
                                                          message="They lose access to this meeting immediately. A reason is required and is kept on the record."
                                                          require-text=""
                                                          confirm-label="Remove">
                                                <x-slot:trigger>
                                                    <x-ui.button size="sm" variant="ghost">Remove</x-ui.button>
                                                </x-slot:trigger>
                                                <x-ui.form.input name="reason" label="Reason" required />
                                            </x-ui.confirm>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach

                        <x-slot:empty>
                            <x-ui.empty-state icon="user-group" title="Nobody invited yet" />
                        </x-slot:empty>
                    </x-ui.table>

                    @if ($canMarkAttendance)
                        <div class="flex justify-end border-t border-slate-200 p-3 dark:border-slate-700">
                            <x-ui.button type="submit" variant="secondary">Save attendance</x-ui.button>
                        </div>
                    @endif
                </form>
            </x-ui.card>

            @if ($canViewNotes)
                <x-ui.card>
                    <x-ui.section-heading title="Notes" />

                    @if ($canSaveNotes)
                        <form method="POST" action="{{ route('admin.meetings.notes', $meeting) }}" class="mt-3 space-y-3">
                            @csrf
                            @method('PUT')
                            <x-ui.form.textarea name="notes" label="Minutes" :value="old('notes', $meeting->notes)" rows="8" />
                            <x-ui.form.input name="outcome_summary" label="Outcome in one line"
                                             :value="old('outcome_summary', $meeting->outcome_summary)" />
                            <div class="flex justify-end">
                                <x-ui.button type="submit" variant="secondary">Save notes</x-ui.button>
                            </div>
                        </form>
                    @elseif ($meeting->notes)
                        <div class="prose prose-sm mt-3 max-w-none dark:prose-invert">{!! \App\Support\RichText::sanitize((string) $meeting->notes) !!}</div>
                    @else
                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Nothing written up yet.</p>
                    @endif
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4">
            @if ($canRespond)
                <x-ui.card>
                    <x-ui.section-heading title="Your answer" />
                    <form method="POST" action="{{ route('admin.meetings.respond', $meeting) }}" class="mt-3 flex gap-2">
                        @csrf
                        <x-ui.button type="submit" name="response" value="accepted" variant="success" size="sm" class="flex-1">Accept</x-ui.button>
                        <x-ui.button type="submit" name="response" value="tentative" variant="secondary" size="sm" class="flex-1">Maybe</x-ui.button>
                        <x-ui.button type="submit" name="response" value="declined" variant="ghost" size="sm" class="flex-1">Decline</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($canAssign && $meeting->status->isLive())
                <x-ui.card>
                    <x-ui.section-heading title="Invite somebody else" />
                    <form method="POST" action="{{ route('admin.meetings.participants.store', $meeting) }}" class="mt-3 space-y-3">
                        @csrf
                        <x-ui.form.select name="participants[0][user_id]" label="Person" placeholder="Choose">
                            @foreach ($people as $person)
                                <option value="{{ $person->id }}">{{ $person->name }}</option>
                            @endforeach
                        </x-ui.form.select>
                        <x-ui.form.select name="participants[0][role]" label="Role">
                            @foreach ($roles as $role)
                                @continue($role->value === 'organizer')
                                <option value="{{ $role->value }}">{{ $role->label() }}</option>
                            @endforeach
                        </x-ui.form.select>
                        <x-ui.button type="submit" variant="secondary" class="w-full">Invite</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($canUpdate)
                <x-ui.card>
                    <x-ui.section-heading title="Move it" />
                    <form method="POST" action="{{ route('admin.meetings.reschedule', $meeting) }}" class="mt-3 space-y-3">
                        @csrf
                        <x-ui.form.input type="datetime-local" name="scheduled_at" label="New time" required />
                        <x-ui.form.input name="reason" label="Reason" required
                                         help="Everybody invited is told, and their answers are reset — accepting Tuesday was never accepting Thursday." />
                        <x-ui.button type="submit" variant="secondary" class="w-full">Reschedule</x-ui.button>
                    </form>
                </x-ui.card>

                <x-ui.card>
                    <x-ui.section-heading title="Call it off" />
                    <form method="POST" action="{{ route('admin.meetings.status', $meeting) }}" class="mt-3 space-y-3">
                        @csrf
                        <x-ui.form.input name="reason" label="Reason" required
                                         help="This is the whole of the message everybody receives." />
                        <x-ui.button type="submit" variant="danger" class="w-full">Cancel meeting</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
