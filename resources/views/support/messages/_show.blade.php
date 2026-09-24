{{--
    One thread, from a portal (phase-19-23 §6.18, INV-22-4).

    **The composer disappears rather than refusing.** `ConversationPolicy::send()` re-asks the §94
    matrix on every request, so removing a pair from the institute's settings closes the box on an
    existing thread here and now — which is exactly what that setting promises, and only works
    because the answer is asked again rather than cached on the row.
--}}

@section('content')
    <div class="space-y-4">
        <x-ui.card>
            <div class="space-y-3">
                @forelse ($messages as $message)
                    @if ($message->is_system)
                        <p class="text-center text-xs italic text-slate-400">
                            {{ strip_tags((string) $message->body) }} · {{ $message->created_at?->diffForHumans() }}
                        </p>
                    @else
                        @php($mine = (int) $message->user_id === (int) auth()->id())

                        <div @class(['flex', 'justify-end' => $mine])>
                            <div @class([
                                'max-w-lg rounded-lg px-3 py-2',
                                'bg-brand-600 text-white' => $mine,
                                'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200' => ! $mine,
                            ])>
                                @unless ($mine)
                                    <div class="mb-1 text-xs font-medium opacity-70">{{ $message->author?->name ?? 'Somebody' }}</div>
                                @endunless

                                <div class="prose prose-sm max-w-none dark:prose-invert">{!! $message->body !!}</div>

                                <div @class(['mt-1 text-right text-[10px]', 'text-white/70' => $mine, 'text-slate-400' => ! $mine])>
                                    {{ $message->created_at?->diffForHumans() }}
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
                <form method="POST" action="{{ route($panel.'.messages.send', $conversation) }}" class="space-y-3">
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
                        This thread is closed. It stays readable, and nobody can add to it.
                    @else
                        You can read this thread but not write in it.
                    @endif
                </p>
            </x-ui.card>
        @endif

        @if ($canLeave)
            <div class="flex justify-end">
                <x-ui.confirm :action="route($panel.'.messages.leave', $conversation)"
                              method="POST"
                              variant="warning"
                              title="Leave this thread?"
                              message="Your messages stay in its history. You will stop receiving it and stop being able to open it."
                              confirm-label="Leave">
                    <x-slot:trigger>
                        <x-ui.button variant="ghost" size="sm">Leave thread</x-ui.button>
                    </x-slot:trigger>
                </x-ui.confirm>
            </div>
        @endif
    </div>
@endsection
