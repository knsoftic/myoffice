@extends('layouts.admin')

@section('title', $ticket->ticket_number)

{{--
    One ticket — admin.tickets.show (phase-19-23 §8, §9.4).

    **An internal note is filtered out in the controller's query, not hidden here.** A note the
    browser merely does not render is a note that was still sent to it; the requester's page never
    receives one at all.

    The composer offers "internal note" only when the policy says so, and the whole reply box
    disappears once a closed ticket is past its reopen window — a form that submits into a refusal is
    a form that teaches people the system is broken.
--}}

@section('header')
    <x-ui.page-header :title="$ticket->subject" :subtitle="$ticket->ticket_number" icon="lifebuoy">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.tickets.index')">Back to the queue</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card>
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <x-ui.badge :color="$ticket->status->color()" size="sm">{{ $ticket->status->label() }}</x-ui.badge>
                    <x-ui.badge :color="$ticket->priority->color()" size="sm">{{ $ticket->priority->label() }}</x-ui.badge>
                    @if ($ticket->firstResponseOverdue() || $ticket->resolutionOverdue())
                        <x-ui.badge color="rose" size="sm">Past target</x-ui.badge>
                    @endif
                    @if ($ticket->is_private_to_creator)
                        <x-ui.badge color="slate" size="sm">Private to its creator</x-ui.badge>
                    @endif
                </div>

                <div class="prose prose-sm max-w-none dark:prose-invert">
                    {!! $ticket->description !!}
                </div>

                <p class="mt-4 text-xs text-slate-400">
                    Raised by {{ $ticket->requester?->name ?? 'somebody since removed' }}
                    {{ $ticket->created_at?->diffForHumans() }}
                </p>
            </x-ui.card>

            @foreach ($replies as $reply)
                <x-ui.card @class(['border-l-4 border-amber-400' => $reply->visibility->value === 'internal_note'])>
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <div class="text-sm font-medium text-slate-700 dark:text-slate-200">
                            {{ $reply->author?->name ?? 'System' }}
                            @if ($reply->visibility->value === 'internal_note')
                                <x-ui.badge color="amber" size="sm">Internal note</x-ui.badge>
                            @endif
                        </div>
                        <span class="text-xs text-slate-400">{{ $reply->created_at?->diffForHumans() }}</span>
                    </div>

                    <div class="prose prose-sm max-w-none dark:prose-invert">{!! $reply->body !!}</div>
                </x-ui.card>
            @endforeach

            @if ($canReply)
                <x-ui.card>
                    <form method="POST" action="{{ route('admin.tickets.replies.store', $ticket) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.textarea name="body" label="Reply" rows="5" required />

                        @if ($canNote)
                            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                <input type="checkbox" name="visibility" value="internal_note"
                                       class="rounded border-slate-300 text-amber-600 focus:ring-amber-500 dark:border-slate-600 dark:bg-slate-800">
                                Internal note — the requester never sees this
                            </label>
                        @endif

                        <div class="flex justify-end">
                            <x-ui.button type="submit" icon="paper-airplane">Send</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @else
                <x-ui.card>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        This ticket is {{ mb_strtolower($ticket->status->label()) }} and takes no further reply.
                    </p>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.card>
                <x-ui.section-heading title="Details" />
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-500 dark:text-slate-400">Desk</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $ticket->department?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-500 dark:text-slate-400">Assignee</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $ticket->assignee?->name ?? 'Nobody yet' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-500 dark:text-slate-400">First reply due</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $ticket->first_response_due_at?->diffForHumans() ?? 'No target' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-500 dark:text-slate-400">Resolution due</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $ticket->resolution_due_at?->diffForHumans() ?? 'No target' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-500 dark:text-slate-400">Replies</dt>
                        <dd class="text-slate-700 tabular-nums dark:text-slate-200">{{ app_number($ticket->replies_count) }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            @if ($canAssign)
                <x-ui.card>
                    <x-ui.section-heading title="Assign" />
                    <form method="POST" action="{{ route('admin.tickets.assign', $ticket) }}" class="mt-3 space-y-3">
                        @csrf
                        <x-ui.form.select name="assigned_to" label="Agent" placeholder="Return to the queue">
                            @foreach ($eligible as $agent)
                                <option value="{{ $agent->id }}" @selected((int) $ticket->assigned_to === (int) $agent->id)>{{ $agent->name }}</option>
                            @endforeach
                        </x-ui.form.select>
                        <x-ui.button type="submit" variant="secondary" class="w-full">Save</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($canChangeStatus)
                <x-ui.card>
                    <x-ui.section-heading title="Status" />
                    <form method="POST" action="{{ route('admin.tickets.status', $ticket) }}" class="mt-3 space-y-3">
                        @csrf
                        <x-ui.form.select name="status" label="Move to" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected($ticket->status === $status)>{{ $status->label() }}</option>
                            @endforeach
                        </x-ui.form.select>
                        <x-ui.form.input name="reason" label="Reason"
                                         help="Required when reopening a closed ticket — everybody on it is told, and the reason is the message." />
                        <x-ui.button type="submit" variant="secondary" class="w-full">Move</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($canChangePriority)
                <x-ui.card>
                    <x-ui.section-heading title="Priority" />
                    <form method="POST" action="{{ route('admin.tickets.priority', $ticket) }}" class="mt-3 space-y-3">
                        @csrf
                        <x-ui.form.select name="priority" label="Priority" required>
                            @foreach ($priorities as $priority)
                                <option value="{{ $priority->value }}" @selected($ticket->priority === $priority)>{{ $priority->label() }}</option>
                            @endforeach
                        </x-ui.form.select>
                        <x-ui.form.input name="reason" label="Reason" required />
                        <x-ui.button type="submit" variant="secondary" class="w-full">Change</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($canChangeDepartment)
                <x-ui.card>
                    <x-ui.section-heading title="Move desk" />
                    <form method="POST" action="{{ route('admin.tickets.department', $ticket) }}" class="mt-3 space-y-3">
                        @csrf
                        <x-ui.form.select name="ticket_department_id" label="Desk" required>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected((int) $ticket->ticket_department_id === (int) $department->id)>
                                    {{ $department->name }}
                                </option>
                            @endforeach
                        </x-ui.form.select>
                        <x-ui.form.input name="reason" label="Reason" required />
                        <x-ui.button type="submit" variant="secondary" class="w-full">Move</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
