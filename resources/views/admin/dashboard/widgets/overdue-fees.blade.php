{{--
    OverdueFeesWidget body (phase-18 §8.10, §8.1's buckets).

    Three buckets rather than one total: "410,000 overdue" and "410,000 overdue, of which 380,000 is
    more than a month old" call for completely different afternoons.

    It counts what the nightly sweep has actually MARKED, not what the dates imply — so the card and
    the list it links to always agree. The honest fix for a gap between them is to run
    `fees:mark-overdue`, which is what the empty state says.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="exclamation-triangle" title="Fees unavailable"
                      message="The charges could not be read." :compact="true" />
@elseif ((int) $data['count'] === 0)
    <x-ui.empty-state icon="check-circle" title="Nothing overdue"
                      message="The institute is current." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-rose-600 tabular-nums dark:text-rose-400">
                {{ money($data['total']) }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                across {{ app_number($data['count']) }} {{ (int) $data['count'] === 1 ? 'charge' : 'charges' }}
            </p>
        </div>

        <div class="space-y-1.5 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
            @foreach ($data['buckets'] as $bucket)
                @continue((int) $bucket['count'] === 0)
                <p class="flex items-center justify-between text-slate-600 dark:text-slate-300">
                    <span>{{ $bucket['label'] }} <span class="text-slate-400">({{ app_number($bucket['count']) }})</span></span>
                    <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($bucket['amount']) }}</span>
                </p>
            @endforeach
        </div>
    </div>
@endif
