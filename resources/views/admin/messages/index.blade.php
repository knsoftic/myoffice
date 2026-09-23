@extends('layouts.admin')

@section('title', 'Messages')

{{--
    The thread list — admin.messages.index (phase-19-23 §6.18, §9.4).

    Always the viewer's own participant rows, never a client id and never a branch. A conversation is
    personal, not corporate: a colleague at the same firm does not see your threads, and that is the
    rule phase-05 §9.2 set and this restates.

    The composer's recipient picker is fed by `admin.messages.recipients`, which asks the §94 matrix
    rather than the user table — so the list is who you may write to, and nobody else. A picker built
    from the user table would offer a student every client in the system, refuse each one on submit,
    and hand out a directory of names on the way.
--}}

@section('header')
    <x-ui.page-header title="Messages"
                      subtitle="Threads you are in. Nothing here is ever deleted — a wrong message is corrected by another message."
                      icon="chat-bubble-left-ellipsis">
        <x-slot:actions>
            @if ($canCreate && $messagingEnabled)
                <x-ui.button icon="pencil-square" x-on:click="$dispatch('open-modal', 'new-thread')">New message</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @unless ($messagingEnabled)
        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
            Messaging is switched off for this installation. Existing threads stay readable.
        </div>
    @endunless

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-4">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Subject" />

            <x-ui.form.select name="scope" label="Kind" placeholder="Any">
                @foreach ($scopes as $scope)
                    <option value="{{ $scope->value }}" @selected(request('scope') === $scope->value)>{{ $scope->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <label class="flex items-end gap-2 pb-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="unread" value="1" @checked(request()->boolean('unread'))
                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                Unread only ({{ app_number($unread) }})
            </label>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.messages.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$conversations->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Thread</th>
                <th class="px-4 py-3 text-left font-semibold">With</th>
                <th class="px-4 py-3 text-left font-semibold">Last message</th>
                <th class="px-4 py-3 text-right font-semibold">Unread</th>
            </x-slot:head>

            @foreach ($conversations as $conversation)
                @php($mine = $conversation->participants->firstWhere('user_id', auth()->id()))

                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.messages.show', $conversation) }}"
                           class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">
                            {{ $conversation->subject ?? 'Direct message' }}
                        </a>
                        @if ($conversation->closed_at)
                            <x-ui.badge color="slate" size="sm">Closed</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $conversation->participants
                            ->where('user_id', '!=', auth()->id())
                            ->map(fn ($p) => $p->user?->name)
                            ->filter()
                            ->take(3)
                            ->join(', ') ?: '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                        {{ \Illuminate\Support\Str::limit(strip_tags((string) $conversation->lastMessage?->body), 60) ?: '—' }}
                        <div>{{ $conversation->last_message_at?->diffForHumans() }}</div>
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if ($mine && $mine->unread_count > 0)
                            <x-ui.badge color="brand" size="sm">{{ app_number($mine->unread_count) }}</x-ui.badge>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="chat-bubble-left-ellipsis" title="No threads"
                                  description="Start one with the button above. Who you may write to is decided by the messaging rules, not by a search box." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$conversations" label="threads" />
    </x-ui.card>

    @if ($canCreate && $messagingEnabled)
        <x-ui.modal name="new-thread" title="New message">
            <form method="POST" action="{{ route('admin.messages.store') }}" class="space-y-3"
                  x-data="{ people: [], q: '', chosen: [],
                            async search() {
                                const r = await fetch(`{{ route('admin.messages.recipients') }}?q=${encodeURIComponent(this.q)}`, { headers: { 'Accept': 'application/json' } });
                                this.people = (await r.json()).recipients;
                            } }"
                  x-init="search()">
                @csrf

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">To</label>
                    <input type="search" x-model="q" x-on:input.debounce.300ms="search()" placeholder="Search by name"
                           class="mb-2 h-10 w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">

                    <div class="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2 dark:border-slate-700">
                        <template x-for="person in people" :key="person.id">
                            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                <input type="checkbox" name="user_ids[]" :value="person.id" x-model="chosen"
                                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                                <span x-text="person.name"></span>
                                <span class="text-xs text-slate-400" x-text="person.scope_label"></span>
                            </label>
                        </template>

                        <p x-show="people.length === 0" class="text-xs text-slate-400">
                            Nobody here you may start a thread with.
                        </p>
                    </div>
                </div>

                <div x-show="chosen.length > 1">
                    <x-ui.form.input name="subject" label="Subject"
                                     help="A group needs one, or it is indistinguishable from every other group with the same people in it." />
                </div>

                <x-ui.form.textarea name="body" label="Message" rows="5" required />

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'new-thread')">Cancel</x-ui.button>
                    <x-ui.button type="submit" icon="paper-airplane">Send</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
