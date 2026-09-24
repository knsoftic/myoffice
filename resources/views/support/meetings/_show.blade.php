{{--
    One meeting, from a participant's side (phase-19-23 §6.17, §9.4, PH22-38).

    **Accept, decline, maybe — and the `.ics`.** A portal user does not reschedule, cancel, invite
    or take the register; what they need is to say whether they are coming and to get it into their
    own calendar.

    **The minutes appear only on a completed meeting.** `MeetingPolicy::viewNotes()` decides and
    this only asks: notes taken during a meeting are working notes, and a client reading half a
    sentence about their own project is worse than waiting a day.
--}}

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card>
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <x-ui.badge :color="$meeting->status->color()" size="sm">{{ $meeting->status->label() }}</x-ui.badge>
                    <x-ui.badge color="slate" size="sm">{{ $meeting->delivery_mode->label() }}</x-ui.badge>
                </div>

                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">When</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            {{ app_datetime($meeting->scheduled_at) }} · {{ app_number($meeting->duration_minutes) }} minutes
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Where</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $meeting->classroom?->name ?? $meeting->location ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Organiser</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $meeting->organizer?->name ?? '—' }}</dd>
                    </div>
                    @if ($meeting->meeting_url && $meeting->status->isLive())
                        <div>
                            <dt class="text-slate-500 dark:text-slate-400">Joining link</dt>
                            <dd><a href="{{ $meeting->meeting_url }}" target="_blank" rel="noopener noreferrer"
                                   class="text-brand-600 hover:underline dark:text-brand-400">Join the meeting</a></dd>
                        </div>
                    @endif
                </dl>

                @if ($meeting->cancellation_reason)
                    <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
                        {{ $meeting->cancellation_reason }}
                    </div>
                @endif

                @if ($meeting->agenda)
                    <div class="prose prose-sm mt-4 max-w-none dark:prose-invert">{!! \App\Support\RichText::sanitize((string) $meeting->agenda) !!}</div>
                @endif
            </x-ui.card>

            @if ($canViewNotes && $meeting->notes)
                <x-ui.card>
                    <x-ui.section-heading title="Notes" />
                    <div class="prose prose-sm mt-3 max-w-none dark:prose-invert">{!! \App\Support\RichText::sanitize((string) $meeting->notes) !!}</div>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4">
            @if ($canRespond)
                <x-ui.card>
                    <x-ui.section-heading title="Are you coming?"
                                          :description="$mine ? 'You said: '.$mine->response->label() : null" />

                    <form method="POST" action="{{ route($panel.'.meetings.respond', $meeting) }}" class="mt-3 space-y-2">
                        @csrf
                        <x-ui.button type="submit" name="response" value="accepted" variant="success" class="w-full">Accept</x-ui.button>
                        <x-ui.button type="submit" name="response" value="tentative" variant="secondary" class="w-full">Maybe</x-ui.button>
                        <x-ui.button type="submit" name="response" value="declined" variant="ghost" class="w-full">Decline</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($canDownloadIcs)
                <x-ui.card>
                    <x-ui.button variant="secondary" icon="calendar-days" class="w-full"
                                 :href="route($panel.'.meetings.ics', $meeting)">Add to my calendar</x-ui.button>
                </x-ui.card>
            @endif

            <x-ui.card>
                <x-ui.section-heading title="Who else is coming" />
                <ul class="mt-3 space-y-1 text-sm text-slate-600 dark:text-slate-300">
                    @foreach ($meeting->participants as $participant)
                        <li class="flex items-center justify-between gap-2">
                            <span>{{ $participant->displayName() }}</span>
                            <x-ui.badge :color="$participant->response->color()" size="sm">
                                {{ $participant->response->label() }}
                            </x-ui.badge>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        </div>
    </div>
@endsection
