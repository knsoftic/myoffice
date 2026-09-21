@extends('layouts.admin')

@section('title', $inquiry->inquiry_number)

@section('header')
    <x-ui.page-header :title="$inquiry->name"
                      :subtitle="$inquiry->inquiry_number . ' · ' . $inquiry->source->label()"
                      icon="question-mark-circle"
                      :back="route('admin.course-inquiries.index')">
        <x-slot:actions>
            <x-ui.badge :color="$inquiry->status->color()" size="lg">{{ $inquiry->status->label() }}</x-ui.badge>

            @can('promote', $inquiry)
                <form method="POST" action="{{ route('admin.course-inquiries.promote', $inquiry) }}">
                    @csrf
                    <input type="hidden" name="course_id" value="{{ $inquiry->course_id }}">
                    <x-ui.button type="submit" variant="secondary" icon="inbox-arrow-down"
                                 :disabled="$inquiry->course_id === null">Create application</x-ui.button>
                </form>
            @endcan

            @can('convert', $inquiry)
                <form method="POST" action="{{ route('admin.course-inquiries.convert', $inquiry) }}">
                    @csrf
                    <input type="hidden" name="course_id" value="{{ $inquiry->course_id }}">
                    <x-ui.button type="submit" variant="primary" icon="user-plus"
                                 :disabled="$inquiry->course_id === null">Admit directly</x-ui.button>
                </form>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4">
            <x-ui.card title="Contact">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Phone</dt>
                        <dd><a href="tel:{{ $inquiry->phone }}" class="text-sky-700 hover:underline dark:text-sky-400">{{ $inquiry->phone }}</a></dd>
                    </div>
                    @if (filled($inquiry->whatsapp))
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">WhatsApp</dt>
                            <dd><a href="https://wa.me/{{ ltrim($inquiry->whatsapp, '+') }}" target="_blank" rel="noopener noreferrer"
                                   class="text-emerald-600 hover:underline dark:text-emerald-400">{{ $inquiry->whatsapp }}</a></dd>
                        </div>
                    @endif
                    @foreach (['Email' => $inquiry->email, 'City' => $inquiry->city, 'Education' => $inquiry->education] as $label => $value)
                        @if (filled($value))
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500">{{ $label }}</dt>
                                <dd class="text-right text-slate-700 dark:text-slate-200">{{ $value }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card title="Interested in">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Course</dt>
                        <dd class="text-right text-slate-700 dark:text-slate-200">{{ $inquiry->course?->name ?? 'Not chosen yet' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Timing</dt>
                        <dd class="text-right text-slate-700 dark:text-slate-200">{{ $inquiry->preferred_timing?->label() ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Mode</dt>
                        <dd class="text-right text-slate-700 dark:text-slate-200">{{ $inquiry->preferred_delivery_mode?->label() ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Source</dt>
                        <dd><x-ui.badge :color="$inquiry->source->color()" size="xs">{{ $inquiry->source->label() }}</x-ui.badge></dd>
                    </div>
                    @if (filled($inquiry->source_url))
                        <div class="pt-1 text-xs text-slate-400 break-all">{{ $inquiry->source_url }}</div>
                    @endif
                </dl>
            </x-ui.card>

            @if (filled($inquiry->referral_code))
                <x-ui.card title="Referral">
                    <div class="flex items-center gap-2">
                        <x-ui.badge :color="$inquiry->hasValidReferral() ? 'emerald' : 'amber'">
                            {{ $inquiry->referral_code }}
                        </x-ui.badge>
                        <span class="text-sm text-slate-600 dark:text-slate-300">
                            {{ $inquiry->collaborator?->name ?? 'not recognised' }}
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">
                        A snapshot for staff to see. The attribution that pays anybody is written when this
                        becomes a student, through the referral service.
                    </p>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4 lg:col-span-2">
            @can('logFollowUp', $inquiry)
                <x-ui.card title="Log a follow-up"
                           subtitle="The outcome moves the status, so the queue and its history cannot disagree.">
                    <form method="POST" action="{{ route('admin.course-inquiries.follow-ups.store', $inquiry) }}"
                          class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @csrf

                        <x-ui.form.select name="channel" label="Channel" required>
                            @foreach ($channels as $value => $label)
                                <option value="{{ $value }}" @selected($value === 'call')>{{ $label }}</option>
                            @endforeach
                        </x-ui.form.select>

                        <x-ui.form.select name="outcome" label="Outcome" required>
                            @foreach ($outcomes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-ui.form.select>

                        <x-ui.form.input name="next_follow_up_at" label="Next follow-up" type="date"
                                         :help="'Blank sets it ' . $followUpDays . ' days out.'" />

                        <div class="flex items-end">
                            <x-ui.button type="submit" variant="primary" icon="plus" class="w-full">Log it</x-ui.button>
                        </div>

                        <div class="sm:col-span-2 lg:col-span-4">
                            <x-ui.form.textarea name="notes" label="What happened" rows="2" />
                        </div>
                    </form>
                </x-ui.card>
            @endcan

            <x-ui.card :title="'Follow-ups (' . $inquiry->followUps->count() . ')'"
                       subtitle="Append-only. A mistake is corrected by another entry, never by removing one.">
                @forelse ($inquiry->followUps as $followUp)
                    <div class="flex gap-3 border-b border-slate-100 py-3 last:border-0 dark:border-slate-800">
                        <x-ui.icon :name="$followUp->channel->icon()" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.badge :color="$followUp->outcome->color()" size="xs">{{ $followUp->outcome->label() }}</x-ui.badge>
                                <span class="text-xs text-slate-400">{{ app_datetime($followUp->contacted_at) }}</span>
                                <span class="text-xs text-slate-500">by {{ $followUp->contactedBy() }}</span>
                            </div>
                            @if (filled($followUp->notes))
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $followUp->notes }}</p>
                            @endif
                            @if ($followUp->next_follow_up_at)
                                <p class="mt-1 text-xs text-slate-400">Next: {{ app_date($followUp->next_follow_up_at) }}</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state icon="phone" title="Nobody has called yet"
                                      description="Log the first contact and the status follows." />
                @endforelse
            </x-ui.card>

            <div class="grid gap-4 sm:grid-cols-2">
                @can('changeStatus', $inquiry)
                    <x-ui.card title="Change status">
                        <form method="POST" action="{{ route('admin.course-inquiries.status', $inquiry) }}" class="space-y-3">
                            @csrf
                            <x-ui.form.select name="status" label="Move to" required>
                                @foreach ($statuses as $value => $label)
                                    <option value="{{ $value }}" @selected($inquiry->status->value === $value)>{{ $label }}</option>
                                @endforeach
                            </x-ui.form.select>
                            <x-ui.form.input name="reason" label="Reason"
                                             help="Required when it is lost, or when a lost one is re-opened." />
                            <x-ui.button type="submit" variant="secondary" icon="arrow-path">Update</x-ui.button>
                        </form>
                    </x-ui.card>
                @endcan

                @can('assign', $inquiry)
                    <x-ui.card title="Assign">
                        <form method="POST" action="{{ route('admin.course-inquiries.assign', $inquiry) }}" class="space-y-3">
                            @csrf
                            <x-ui.form.select name="assigned_to" label="Counsellor" required>
                                @foreach ($assignees as $id => $name)
                                    <option value="{{ $id }}" @selected((int) $inquiry->assigned_to === (int) $id)>{{ $name }}</option>
                                @endforeach
                            </x-ui.form.select>
                            <x-ui.form.input name="note" label="Note" />
                            <x-ui.button type="submit" variant="secondary" icon="user">Reassign</x-ui.button>
                        </form>
                    </x-ui.card>
                @endcan
            </div>
        </div>
    </div>
@endsection
