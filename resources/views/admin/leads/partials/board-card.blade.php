{{--
    One Kanban card (phase-05 §8.2 "Card"). Rendered by admin/leads/board for the first page of every column, and by the
    controller into JSON for "Load more" (admin.leads.board.column → `html`) and after a move (admin.leads.board.move →
    `card_html`), so the markup exists exactly once.

    Variables:
      $lead          App\Models\Crm\Lead with `assignee` eager-loaded (select: id, lead_no, name, company, budget_amount,
                     source, status, assigned_to, follow_up_at, last_activity_at, created_at, duplicate_of_lead_id,
                     converted_at)
      $transitions   array<string, list<string>>  LeadStatus value => allowedTransitions() values
      $statusLabels  array<string, string>        LeadStatus::options()
      $staleDays     int                          crm.stale_lead_days
      $canMove       bool                         leads.change_status (the card still asks the policy)

    The data-* attributes are the board's only contract with the card: data-lead-id, data-status, data-name,
    data-budget (the raw decimal string, empty when no budget) and data-open-follow-up (1 | 0).
--}}

@php
    $cardUser = auth()->user();
    $cardStatus = $lead->status instanceof \BackedEnum ? $lead->status->value : (string) $lead->status;
    $cardTransitions = array_values((array) (($transitions ?? [])[$cardStatus] ?? []));
    $cardLabels = (array) ($statusLabels ?? []);
    $cardMovable = (bool) ($canMove ?? false) && (bool) $cardUser?->can('changeStatus', $lead);
    $cardAssignee = $lead->relationLoaded('assignee') ? $lead->assignee : null;
    $cardBudget = $lead->budget_amount !== null ? (string) $lead->budget_amount : '';
    $cardStaleDays = max(1, (int) ($staleDays ?? 7));
    $cardLastTouch = $lead->last_activity_at ?? $lead->created_at;
    $cardStale = $cardLastTouch !== null
        && \Illuminate\Support\Carbon::parse($cardLastTouch)->lt(now()->subDays($cardStaleDays))
        && ! in_array($cardStatus, ['won', 'lost'], true);
@endphp

<article
    data-lead-id="{{ $lead->getKey() }}"
    data-status="{{ $cardStatus }}"
    data-name="{{ $lead->name }}"
    data-budget="{{ $cardBudget }}"
    data-open-follow-up="{{ filled($lead->follow_up_at) ? '1' : '0' }}"
    @if ($cardMovable)
        draggable="true"
        x-on:dragstart="dragStart($event)"
        x-on:dragend="dragEnd($event)"
    @endif
    aria-label="{{ $lead->name }}{{ filled($lead->company) ? ', '.$lead->company : '' }}"
    @class([
        'group rounded-lg bg-white p-3 shadow-sm ring-1 ring-slate-200 transition hover:ring-slate-300 dark:bg-slate-900 dark:ring-slate-700 dark:hover:ring-slate-600',
        'cursor-grab active:cursor-grabbing' => $cardMovable,
    ])
>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <a href="{{ route('admin.leads.show', $lead) }}" class="block truncate text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300" draggable="false">
                {{ $lead->name }}
            </a>
            @if (filled($lead->company))
                <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $lead->company }}</p>
            @endif
        </div>

        <span class="shrink-0 font-mono text-2xs text-slate-400 dark:text-slate-500">{{ $lead->lead_no }}</span>
    </div>

    <div class="mt-2 flex flex-wrap items-center gap-1.5">
        @if ($cardBudget !== '')
            <span class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($cardBudget) }}</span>
        @endif
        @include('admin.crm.partials.enum-badge', ['value' => $lead->source, 'dot' => false, 'variant' => 'outline', 'size' => 'xs', 'empty' => ''])
    </div>

    <div class="mt-2 flex flex-wrap items-center gap-1.5">
        @if (filled($lead->follow_up_at))
            @include('admin.crm.partials.follow-up-chip', ['at' => $lead->follow_up_at, 'compact' => true])
        @endif
        @if ($cardStale)
            <x-ui.badge color="orange" size="xs" icon="clock" :title="'No activity for more than '.$cardStaleDays.' days'">Stale</x-ui.badge>
        @endif
        @if (filled($lead->duplicate_of_lead_id))
            <x-ui.badge color="amber" size="xs" icon="link">Duplicate</x-ui.badge>
        @endif
        @if (filled($lead->converted_at))
            <x-ui.badge color="emerald" size="xs" icon="check-badge">Converted</x-ui.badge>
        @endif
    </div>

    <div class="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-2 dark:border-slate-800">
        @if ($cardMovable && $cardTransitions !== [])
            {{-- The keyboard / touch path (R-3): a native select posts the identical endpoint as a drag, and is never
                 clipped by the horizontally scrolling board the way a popover menu would be. --}}
            <label for="board-move-{{ $lead->getKey() }}" class="sr-only">Move {{ $lead->name }} to</label>
            <select
                id="board-move-{{ $lead->getKey() }}"
                x-on:change="if ($el.value !== '') { moveVia($el, $el.value); $el.value = ''; }"
                draggable="false"
                class="block w-full min-w-0 max-w-[10rem] rounded-md border-slate-200 bg-white py-1 pl-2 pr-7 text-xs text-slate-600 focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-slate-300"
            >
                <option value="">Move to…</option>
                @foreach ($cardTransitions as $target)
                    <option value="{{ $target }}">{{ $cardLabels[$target] ?? \Illuminate\Support\Str::headline($target) }}</option>
                @endforeach
            </select>
        @else
            <span></span>
        @endif
        @if ($cardAssignee)
            <x-ui.avatar :src="$cardAssignee->avatar_url ?? null" :name="$cardAssignee->name" size="xs" />
        @else
            <span class="text-2xs text-slate-400 dark:text-slate-500">Unassigned</span>
        @endif
    </div>
</article>
