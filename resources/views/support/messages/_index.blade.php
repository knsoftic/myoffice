{{--
    A portal's threads (phase-19-23 §6.18, §9.4, requirement §94).

    **The recipient picker is the permission check.** It asks `{panel}.messages.recipients`, which
    runs every candidate through `MessagingMatrix::mayStart()` — so a student sees institute staff
    and their own teachers, and never learns that a client exists. A picker built from the user table
    with a search box would offer everybody, refuse each one on submit, and hand out a directory of
    names on the way.

    **Direct threads only from a portal.** A group needs every pair among its members to be allowed
    (PH22-26), which is an adjudication staff make; the box here writes to one person.
--}}

@section('content')
    @unless ($messagingEnabled)
        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
            Messaging is switched off at the moment. Your existing threads stay readable.
        </div>
    @endunless

    <x-ui.card :padded="false">
        <div class="flex items-center justify-between gap-2 border-b border-slate-200 p-3 dark:border-slate-700">
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ app_number($unread) }} unread</p>

            @if ($canCreate && $messagingEnabled)
                <x-ui.button size="sm" icon="pencil-square" x-on:click="$dispatch('open-modal', 'portal-new-thread')">
                    New message
                </x-ui.button>
            @endif
        </div>

        @if ($conversations->isEmpty())
            <x-ui.empty-state icon="chat-bubble-left-ellipsis" title="No messages"
                              description="Start one with the button above. Who you can write to is set by the institute, not by a search box." />
        @else
            <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                @foreach ($conversations as $conversation)
                    @php($mine = $conversation->participants->firstWhere('user_id', auth()->id()))

                    <li>
                        <a href="{{ route($panel.'.messages.show', $conversation) }}"
                           class="flex items-start gap-3 p-4 hover:bg-slate-50 dark:hover:bg-slate-800/60">
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium text-slate-700 dark:text-slate-200">
                                    {{ $conversation->participants
                                        ->where('user_id', '!=', auth()->id())
                                        ->map(fn ($p) => $p->user?->name)
                                        ->filter()
                                        ->join(', ') ?: ($conversation->subject ?? 'Message') }}
                                </div>

                                <p class="mt-0.5 truncate text-sm text-slate-500 dark:text-slate-400">
                                    {{ \Illuminate\Support\Str::limit(strip_tags((string) $conversation->lastMessage?->body), 80) ?: '—' }}
                                </p>

                                <p class="mt-1 text-xs text-slate-400">{{ $conversation->last_message_at?->diffForHumans() }}</p>
                            </div>

                            @if ($mine && $mine->unread_count > 0)
                                <x-ui.badge color="brand" size="sm">{{ app_number($mine->unread_count) }}</x-ui.badge>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-ui.pagination-summary :paginator="$conversations" label="threads" />
    </x-ui.card>

    @if ($canCreate && $messagingEnabled)
        <x-ui.modal name="portal-new-thread" title="New message">
            <form method="POST" action="{{ route($panel.'.messages.store') }}" class="space-y-3"
                  x-data="{ people: [], q: '',
                            async search() {
                                const r = await fetch(`{{ route($panel.'.messages.recipients') }}?q=${encodeURIComponent(this.q)}`, { headers: { 'Accept': 'application/json' } });
                                this.people = (await r.json()).recipients;
                            } }"
                  x-init="search()">
                @csrf

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">To</label>

                    <input type="search" x-model="q" x-on:input.debounce.300ms="search()" placeholder="Search by name"
                           {{-- A placeholder is not a label: it disappears the moment somebody types. --}}
                           aria-label="Search conversations by name"
                           class="mb-2 h-10 w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">

                    <select name="user_id" required size="6"
                            class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                        <template x-for="person in people" :key="person.id">
                            <option :value="person.id" x-text="person.name + ' — ' + (person.scope_label ?? '')"></option>
                        </template>
                    </select>

                    <p x-show="people.length === 0" class="mt-1 text-xs text-slate-400">
                        There is nobody here you can start a thread with.
                    </p>
                </div>

                <x-ui.form.textarea name="body" label="Message" rows="5" required />

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'portal-new-thread')">Cancel</x-ui.button>
                    <x-ui.button type="submit" icon="paper-airplane">Send</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
