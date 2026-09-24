{{--
    One ticket, from the requester's side (phase-19-23 §8, §9.4).

    **Only public replies reach this page**, filtered in the controller's query rather than hidden
    here — a note the browser merely does not render is a note that was still sent to it.

    The composer disappears on a closed ticket rather than submitting into a refusal, and the reopen
    box takes its place: §2.28.7 gives a portal exactly one status move, and this is it.
--}}

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card>
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <x-ui.badge :color="$ticket->status->color()" size="sm">{{ $ticket->status->label() }}</x-ui.badge>
                    @if ($ticket->awaitingUs())
                        <span class="text-xs text-slate-500 dark:text-slate-400">waiting on the team</span>
                    @endif
                </div>

                <div class="prose prose-sm max-w-none dark:prose-invert">{!! $ticket->description !!}</div>

                <p class="mt-4 text-xs text-slate-400">Raised {{ $ticket->created_at?->diffForHumans() }}</p>
            </x-ui.card>

            @foreach ($replies as $reply)
                @php($mine = (int) $reply->user_id === (int) auth()->id())

                <x-ui.card @class(['border-l-4 border-brand-400' => ! $mine])>
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-200">
                            {{ $mine ? 'You' : ($reply->author?->name ?? 'The team') }}
                        </span>
                        <span class="text-xs text-slate-400">{{ $reply->created_at?->diffForHumans() }}</span>
                    </div>

                    <div class="prose prose-sm max-w-none dark:prose-invert">{!! $reply->body !!}</div>
                </x-ui.card>
            @endforeach

            @if ($canReply)
                <x-ui.card>
                    <form method="POST" action="{{ route($panel.'.tickets.replies.store', $ticket) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.textarea name="body" label="Reply" rows="5" required />
                        <div class="flex justify-end">
                            <x-ui.button type="submit" icon="paper-airplane">Send</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @elseif ($canReopen)
                <x-ui.card>
                    <x-ui.section-heading title="Still not right?"
                                          description="Reopening puts it back on the desk with your note at the top." />
                    <form method="POST" action="{{ route($panel.'.tickets.reopen', $ticket) }}" class="mt-3 space-y-3">
                        @csrf
                        <x-ui.form.textarea name="reason" label="What is still wrong?" rows="4" required />
                        <div class="flex justify-end">
                            <x-ui.button type="submit" variant="secondary">Reopen</x-ui.button>
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

        <div>
            <x-ui.card>
                <x-ui.section-heading title="Details" />
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-500 dark:text-slate-400">Reference</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $ticket->ticket_number }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-500 dark:text-slate-400">Desk</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $ticket->department?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-500 dark:text-slate-400">Raised</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ app_date($ticket->created_at) }}</dd>
                    </div>
                    {{--
                        No assignee and no targets. Who is holding it and how long the desk has
                        promised to take are the desk's business; showing a name invites the
                        requester to chase a person rather than the ticket.
                    --}}
                </dl>
            </x-ui.card>
        </div>
    </div>
@endsection
