@extends('layouts.admin')

@section('title', $conversation->subject ?? 'Message')

{{--
    One thread — admin.messages.show (phase-19-23 §6.18, §9.4).

    The composer disappears when the policy says no rather than submitting into a refusal. Three
    things close it: the thread being closed, the viewer having left, and the §94 matrix no longer
    allowing the pair — the last of those is re-asked on every request (INV-22-4), so removing a pair
    from the settings silences an existing thread here and now.

    Opening the page marks it read, which is done in the controller *after* the page is built so the
    badge in the topbar reflects this visit rather than clearing on the next click.
--}}

@section('header')
    <x-ui.page-header :title="$conversation->subject ?? 'Direct message'"
                      :subtitle="$conversation->participants->map(fn ($p) => $p->user?->name)->filter()->join(', ')"
                      icon="chat-bubble-left-ellipsis">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.messages.index')">Back to threads</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-4">
        <div class="space-y-4 lg:col-span-3">
            <x-ui.card>
                <div class="space-y-3">
                    @forelse ($messages as $message)
                        @if ($message->is_system)
                            <p class="text-center text-xs italic text-slate-400">
                                {{ strip_tags((string) $message->body) }} ·
                                {{ $message->created_at?->diffForHumans() }}
                            </p>
                        @else
                            @php($mine = (int) $message->user_id === (int) auth()->id())

                            <div @class(['flex', 'justify-end' => $mine])>
                                <div @class([
                                    'max-w-xl rounded-lg px-3 py-2',
                                    'bg-brand-600 text-white' => $mine,
                                    'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200' => ! $mine,
                                ])>
                                    @unless ($mine)
                                        <div class="mb-1 text-xs font-medium opacity-70">{{ $message->author?->name ?? 'Somebody' }}</div>
                                    @endunless

                                    <div class="prose prose-sm max-w-none dark:prose-invert">{!! nl2br(e((string) $message->body)) !!}</div>

                                    <div @class(['mt-1 text-right text-[10px]', 'text-white/70' => $mine, 'text-slate-400' => ! $mine])>
                                        {{ $message->created_at?->diffForHumans() }}
                                        @if ($mine && $message->reads_count > 0)
                                            · read by {{ app_number($message->reads_count) }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endif
                    @empty
                        <x-ui.empty-state icon="chat-bubble-left-ellipsis" title="Nothing said yet" />
                    @endforelse
                </div>

                @if ($messages->hasPages())
                    <div class="mt-4">{{ $messages->links() }}</div>
                @endif
            </x-ui.card>

            @if ($canSend)
                <x-ui.card>
                    <form method="POST" action="{{ route('admin.messages.send', $conversation) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.textarea name="body" label="Reply" rows="4" required />
                        <div class="flex justify-end">
                            <x-ui.button type="submit" icon="paper-airplane">Send</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @else
                <x-ui.card>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        @if ($conversation->closed_at)
                            This thread is closed. It stays readable for ever, and nobody can add to it.
                        @else
                            You can read this thread but not write in it.
                        @endif
                    </p>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.card>
                <x-ui.section-heading title="In this thread" />
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($conversation->participants as $participant)
                        <li class="flex items-center justify-between gap-2">
                            <span @class([
                                'text-slate-700 dark:text-slate-200',
                                'line-through text-slate-400' => $participant->left_at !== null,
                            ])>{{ $participant->user?->name ?? 'Removed user' }}</span>

                            @if ($participant->left_at)
                                <span class="text-xs text-slate-400">left</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>

            @if ($canAddParticipant)
                <x-ui.card>
                    <x-ui.section-heading title="Add somebody" />
                    <form method="POST" action="{{ route('admin.messages.participants.store', $conversation) }}" class="mt-3 space-y-3">
                        @csrf
                        <x-ui.form.input type="number" name="user_id" label="User id" required
                                         help="They are checked against everybody already here — a group can never be the back door a direct thread refuses." />
                        <x-ui.button type="submit" variant="secondary" class="w-full">Add</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            <x-ui.card>
                <div class="space-y-2">
                    @if ($canLeave)
                        <x-ui.confirm :action="route('admin.messages.leave', $conversation)"
                                      method="POST"
                                      variant="warning"
                                      title="Leave this thread?"
                                      message="Your messages stay in its history. You will stop receiving it and stop being able to open it."
                                      confirm-label="Leave">
                            <x-slot:trigger>
                                <x-ui.button variant="ghost" size="sm" class="w-full">Leave thread</x-ui.button>
                            </x-slot:trigger>
                        </x-ui.confirm>
                    @endif

                    @if ($canClose)
                        <form method="POST" action="{{ route('admin.messages.close', $conversation) }}" class="space-y-2">
                            @csrf
                            <x-ui.form.input name="reason" label="Reason" required />
                            <x-ui.button type="submit" variant="secondary" size="sm" class="w-full">Close thread</x-ui.button>
                        </form>
                    @endif
                </div>
            </x-ui.card>
        </div>
    </div>
@endsection
