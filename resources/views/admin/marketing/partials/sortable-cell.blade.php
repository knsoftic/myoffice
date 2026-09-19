{{--
    The drag handle cell of a reorderable admin table row (phase-04 §8.1 "rows are drag-reorderable
    (Alpine + a single reorder POST of the id order)", §6.2 ContentOrderService::reorder).

    The row itself carries the cmsSortable wiring; this cell only renders the handle and the keyboard
    Move up / Move down pair:

        <tr data-sortable-id="{{ $row->id }}" data-sortable-label="{{ $row->name }}"
            x-on:dragstart="dragStart($event)" x-on:dragover.prevent="dragOver($event)"
            x-on:drop.prevent="drop()" x-on:dragend="dragEnd($event)">
            @include('admin.marketing.partials.sortable-cell', ['enabled' => $canReorder, 'label' => $row->name])

    `enabled` false renders an empty, same-width cell, so the columns never shift between a sortable and a
    filtered view.
--}}

<td class="w-16 whitespace-nowrap">
    @if ($enabled ?? false)
        <div class="flex items-center gap-0.5">
            <button
                type="button"
                data-sortable-handle
                x-on:pointerdown="arm($event)"
                class="inline-flex h-8 w-6 cursor-grab items-center justify-center rounded text-slate-400 hover:bg-slate-100 hover:text-slate-700 active:cursor-grabbing dark:text-slate-500 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                aria-label="Drag to reorder {{ $label ?? 'row' }}"
                title="Drag to reorder"
            >
                <x-ui.icon name="bars-3" class="h-4 w-4" />
            </button>
            <div class="flex flex-col">
                <button type="button" x-on:click="move($el, -1)" class="inline-flex h-4 w-5 items-center justify-center rounded text-slate-400 hover:text-slate-800 dark:text-slate-500 dark:hover:text-slate-200" aria-label="Move {{ $label ?? 'row' }} up">
                    <x-ui.icon name="chevron-up" class="h-3 w-3" />
                </button>
                <button type="button" x-on:click="move($el, 1)" class="inline-flex h-4 w-5 items-center justify-center rounded text-slate-400 hover:text-slate-800 dark:text-slate-500 dark:hover:text-slate-200" aria-label="Move {{ $label ?? 'row' }} down">
                    <x-ui.icon name="chevron-down" class="h-3 w-3" />
                </button>
            </div>
        </div>
    @else
        <span class="sr-only">Not reorderable in this view</span>
    @endif
</td>
