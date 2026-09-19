{{--
    NewInquiriesWidget body (phase-04 §8.12) — App\Dashboard\Cms\NewInquiriesWidget, key `new_inquiries`,
    module contact_inquiries, permission .view_any.

    $data (the widget's data()):
      available       bool
      total           int     new inquiries received in the range (spam excluded)
      types           list<array{value: string, label: string, color: string, count: int, href: ?string}>  per InquiryType
      delta           array   ComparesRanges::delta() against the previous window
      range_label     string
      previous_label  ?string
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="inbox-stack" title="Inquiries unavailable" message="The inquiry table could not be read." :compact="true" />
@elseif ((int) ($data['total'] ?? 0) === 0)
    <x-ui.empty-state icon="inbox-stack" title="No new inquiries" :message="'Nothing new in '.($data['range_label'] ?? 'this period').'.'" :compact="true" />
@else
    <div class="space-y-4">
        <div class="flex items-end justify-between gap-3">
            <div>
                <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">{{ app_number((int) $data['total']) }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">new in {{ $data['range_label'] ?? 'this period' }}</p>
            </div>
            @if (\Illuminate\Support\Facades\Route::has('admin.contact-inquiries.index'))
                <x-ui.button variant="secondary" size="sm" :href="route('admin.contact-inquiries.index', ['tab' => 'new'])" icon-trailing="arrow-right">Open queue</x-ui.button>
            @endif
        </div>

        <dl class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ((array) ($data['types'] ?? []) as $type)
                <div class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                    <dt><x-ui.badge :color="$type['color'] ?? 'slate'" size="sm" :dot="true">{{ $type['label'] ?? '' }}</x-ui.badge></dt>
                    <dd class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">
                        @if (filled($type['href'] ?? null) && (int) ($type['count'] ?? 0) > 0)
                            <a href="{{ $type['href'] }}" class="hover:text-brand-700 dark:hover:text-brand-300">{{ app_number((int) $type['count']) }}</a>
                        @else
                            {{ app_number((int) ($type['count'] ?? 0)) }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>

        @if (isset($data['delta']))
            <div class="border-t border-slate-100 pt-3 dark:border-slate-800">
                @include('admin.dashboard.partials.delta', ['delta' => $data['delta'], 'against' => $data['previous_label'] ?? null])
            </div>
        @endif
    </div>
@endif
