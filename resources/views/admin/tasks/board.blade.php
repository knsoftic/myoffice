@extends('layouts.admin')

@section('title', 'Task board')

{{--
    The Kanban board — admin.tasks.board / admin.projects.board (phase-06 §8.4, §6.5).

    A drop POSTs to admin.tasks.move with the ids either side of where the card landed, and the server
    answers with the **whole affected column**. The client resyncs from that answer rather than trusting
    its optimistic order, because §6.5 lets the service renumber a column when the gap between two cards
    runs out — and a client that assumed otherwise would drift.

    An illegal move comes back 422 with the allowed statuses, and the card springs back to where it was.
--}}
@php
    $canMove = (bool) ($canMove ?? false);
@endphp

@section('header')
    <x-ui.page-header
        :title="$project ? 'Board — ' . $project->name : 'Task board'"
        :subtitle="$project?->code ?? 'Every task you can see, by column.'"
        icon="view-columns">
        <x-slot:actions>
            @if ($project)
                <x-ui.button variant="secondary" :href="route('admin.projects.show', $project)">Back to project</x-ui.button>
            @endif
            <x-ui.button variant="secondary" icon="list-bullet" :href="route('admin.tasks.index')">List view</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    {{--
        The board is bounded (phase-24-25 section 6.4, PRF-05) and says so when the bound bites: a card
        that is silently missing from a Kanban column reads as a task that does not exist.
    --}}
    @if ($truncated ?? false)
        <p role="status"
           class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200">
            Showing the first {{ app_number((float) $cardCeiling, 0) }} cards. Narrow the board to one project, or use the
            <a href="{{ route('admin.tasks.index') }}" class="font-medium underline">list view</a> to page through everything.
        </p>
    @endif

    <div
        x-data="taskBoard({ moveUrl: '{{ url('admin/tasks') }}', csrf: '{{ csrf_token() }}', canMove: {{ $canMove ? 'true' : 'false' }} })"
        class="flex gap-4 overflow-x-auto pb-4">
        @foreach ($columns as $key => $column)
            <section
                class="flex w-72 shrink-0 flex-col rounded-xl bg-slate-100/70 p-3 dark:bg-slate-900/40"
                data-status="{{ $key }}"
                x-on:dragover.prevent
                x-on:drop.prevent="drop($event, '{{ $key }}')">
                <header class="mb-3 flex items-center justify-between">
                    <span class="flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
                        <x-ui.badge :color="$column['status']->color()" size="xs">{{ $column['status']->label() }}</x-ui.badge>
                    </span>
                    <span class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ $column['cards']->count() }}</span>
                </header>

                <div class="space-y-2" data-column="{{ $key }}">
                    @forelse ($column['cards'] as $card)
                        <article
                            class="cursor-grab rounded-lg border border-slate-200 bg-white p-3 shadow-sm transition hover:shadow dark:border-slate-700 dark:bg-slate-800"
                            draggable="{{ $canMove ? 'true' : 'false' }}"
                            data-id="{{ $card->getKey() }}"
                            x-on:dragstart="dragStart($event, {{ $card->getKey() }})">
                            <a href="{{ route('admin.tasks.show', $card) }}" class="block text-sm font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $card->title }}</a>

                            @unless ($project)
                                <span class="mt-1 block truncate text-xs text-slate-500 dark:text-slate-400">{{ $card->project?->code }} · {{ $card->project?->name }}</span>
                            @endunless

                            <div class="mt-2 flex items-center justify-between gap-2">
                                <x-ui.badge :color="$card->priority->color()" size="xs" variant="outline">{{ $card->priority->label() }}</x-ui.badge>
                                <span class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ app_number((float) $card->progress_percent, 0) }}%</span>
                            </div>

                            <div class="mt-2 flex items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400">
                                <span class="truncate">{{ $card->assignee?->name ?? 'Unassigned' }}</span>
                                <span class="flex shrink-0 items-center gap-2">
                                    @if ($card->checklist_total > 0)
                                        <span title="Checklist">{{ $card->checklist_done }}/{{ $card->checklist_total }}</span>
                                    @endif
                                    @if ($card->comment_count > 0)
                                        <span title="Comments">💬 {{ $card->comment_count }}</span>
                                    @endif
                                </span>
                            </div>

                            @if ($card->due_date)
                                <p @class(['mt-2 text-xs', 'text-rose-600 dark:text-rose-400' => $card->due_date->isPast() && $card->status->isOpen(), 'text-slate-500 dark:text-slate-400' => ! ($card->due_date->isPast() && $card->status->isOpen())])>Due {{ app_date($card->due_date) }}</p>
                            @endif
                        </article>
                    @empty
                        <p class="rounded-lg border border-dashed border-slate-300 px-3 py-6 text-center text-xs text-slate-400 dark:border-slate-700 dark:text-slate-500">Nothing here</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            function taskBoard(config) {
                return {
                    dragging: null,
                    dragStart(event, id) {
                        if (! config.canMove) { event.preventDefault(); return; }
                        this.dragging = id;
                        event.dataTransfer.effectAllowed = 'move';
                    },
                    async drop(event, status) {
                        if (! config.canMove || this.dragging === null) { return; }

                        const column = event.currentTarget.querySelector('[data-column]');
                        const cards = [...column.querySelectorAll('[data-id]')]
                            .map((node) => Number(node.dataset.id))
                            .filter((id) => id !== this.dragging);

                        // Where the pointer landed decides the neighbours the server places between.
                        const y = event.clientY;
                        let index = cards.length;
                        [...column.querySelectorAll('[data-id]')].forEach((node, i) => {
                            const box = node.getBoundingClientRect();
                            if (y < box.top + box.height / 2 && index === cards.length) { index = i; }
                        });

                        const payload = {
                            status,
                            after_id: index > 0 ? cards[index - 1] : null,
                            before_id: index < cards.length ? cards[index] : null,
                        };

                        const response = await fetch(`${config.moveUrl}/${this.dragging}/move`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': config.csrf,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify(payload),
                        });

                        this.dragging = null;

                        // The server is the authority on both the status move and the order, so the
                        // simplest honest resync is to re-read the board.
                        window.location.reload();
                    },
                };
            }
        </script>
    @endpush
@endsection
