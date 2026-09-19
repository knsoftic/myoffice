{{--
    InquiryRoutingBacklogWidget body (phase-04 §8.12) — App\Dashboard\Cms\InquiryRoutingBacklogWidget, key
    `inquiry_routing_backlog`, module contact_inquiries, permission .view_any. The early warning that leads are piling up
    while the CRM or institute target is missing, disabled or failing.

    $data (the widget's data()):
      available  bool
      total      int   routing_status IN (pending, failed), spam excluded — a standing total, not range-scoped
      failed     int
      targets    list<array{key: string, label: string ("Awaiting CRM" / "Awaiting Institute"), count: int}>
      reasons    list<array{reason: string ("Lead module not installed yet", …), count: int}>  most frequent first
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="arrow-path" title="Routing backlog unavailable" message="The inquiry table could not be read." :compact="true" />
@elseif ((int) ($data['total'] ?? 0) === 0)
    <x-ui.empty-state icon="check-circle" title="Nothing waiting to be routed" message="Every service and course inquiry has reached its module." :compact="true" />
@else
    <div class="space-y-4">
        <div class="flex items-end justify-between gap-3">
            <div>
                <p class="text-3xl font-semibold tracking-tight text-amber-600 tabular-nums dark:text-amber-400">{{ app_number((int) $data['total']) }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    waiting to be routed{{ (int) ($data['failed'] ?? 0) > 0 ? ' · '.app_number((int) $data['failed']).' failed' : '' }}
                </p>
            </div>
            @if (\Illuminate\Support\Facades\Route::has('admin.contact-inquiries.index'))
                <x-ui.button variant="secondary" size="sm" :href="route('admin.contact-inquiries.index', ['tab' => 'awaiting'])" icon-trailing="arrow-right">Review</x-ui.button>
            @endif
        </div>

        @if (! empty($data['targets']))
            <div class="flex flex-wrap gap-2">
                @foreach ((array) $data['targets'] as $target)
                    <x-ui.badge color="amber" size="sm" icon="clock">{{ $target['label'] ?? 'Awaiting routing' }} · {{ app_number((int) ($target['count'] ?? 0)) }}</x-ui.badge>
                @endforeach
            </div>
        @endif

        <ul class="space-y-2">
            @foreach ((array) ($data['reasons'] ?? []) as $reason)
                <li class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
                    <span class="truncate text-slate-700 dark:text-slate-200">{{ $reason['reason'] ?? '' }}</span>
                    <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((int) ($reason['count'] ?? 0)) }}</span>
                </li>
            @endforeach
        </ul>

        <p class="text-2xs text-slate-400 dark:text-slate-500">Nothing is lost while inquiries wait; they route themselves once their module is installed and enabled.</p>
    </div>
@endif
