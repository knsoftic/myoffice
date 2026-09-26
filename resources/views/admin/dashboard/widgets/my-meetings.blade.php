{{--
    MyMeetingsWidget body — the viewer's own diary, nobody else's.

    The next meeting is named because "two upcoming" is a statistic and "Design review at 3:00 PM"
    is something somebody can walk to. Only the meeting's own title, time and place appear: no
    organiser, no attendee, no count of who has accepted — those belong to other people, and this
    card is the viewer's.

    A render with no signed-in viewer reports an empty diary and lands in the empty branch, rather
    than putting the whole company's afternoon on a page labelled "my".
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="video-camera" title="Diary unavailable"
                      message="Your meetings could not be read." :compact="true" />
@elseif (($data['upcoming'] ?? 0) === 0)
    <x-ui.empty-state icon="calendar-days" title="Nothing in your diary"
                      message="No meeting is waiting for you. The day is yours." :compact="true" />
@else
    @php
        $today = $data['today'] ?? 0;
        $nextAt = $data['next_at'] ?? null;
        $nextTitle = $data['next_title'] ?? null;
    @endphp

    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p @class([
                'text-3xl font-semibold tracking-tight tabular-nums',
                'text-amber-600 dark:text-amber-400' => $today > 0,
                'text-slate-900 dark:text-white' => $today === 0,
            ])>
                {{ $today > 0 ? $today : $data['upcoming'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                @if ($today > 0)
                    still to come today · {{ $data['upcoming'] }} upcoming in all
                @else
                    upcoming · nothing left today
                @endif
            </p>
        </div>

        @if ($nextTitle !== null)
            <div class="border-t border-slate-100 pt-3 dark:border-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">Next</p>
                <p class="mt-0.5 truncate text-sm text-slate-700 dark:text-slate-200">
                    @if ($data['next_link'] ?? null)
                        <a href="{{ $data['next_link'] }}" class="font-medium hover:underline">{{ $nextTitle }}</a>
                    @else
                        <span class="font-medium">{{ $nextTitle }}</span>
                    @endif
                </p>
                @if ($nextAt !== null)
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        @if ($data['next_is_today'] ?? false)
                            today at {{ app_time($nextAt) }}
                        @else
                            {{ app_datetime($nextAt) }}
                        @endif
                        @if ($data['next_online'] ?? false)
                            · online
                        @elseif (filled($data['next_location'] ?? null))
                            · {{ $data['next_location'] }}
                        @endif
                    </p>
                @endif
            </div>
        @endif
    </div>
@endif
