{{--
    PendingModerationWidget body (phase-04 §8.12) — App\Dashboard\Cms\PendingModerationWidget, key `pending_moderation`,
    module testimonials, permission testimonials.view_any. Student reviews are listed only when that module is enabled and
    the user may see them.

    $data (the widget's data()):
      available  bool
      total      int
      queues     list<array{key: string (testimonials | student_reviews), label: string, count: int, href: ?string}>
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="chat-bubble-left-right" title="Moderation queue unavailable" :compact="true" />
@elseif ((int) ($data['total'] ?? 0) === 0)
    <x-ui.empty-state icon="check-badge" title="Nothing waiting for approval" message="Every testimonial and review has been moderated." :compact="true" />
@else
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        @foreach ((array) ($data['queues'] ?? []) as $queue)
            @php
                $count = (int) ($queue['count'] ?? 0);
                $icon = ($queue['key'] ?? '') === 'student_reviews' ? 'star' : 'chat-bubble-left-right';
                $tag = filled($queue['href'] ?? null) ? 'a' : 'div';
            @endphp
            <{{ $tag }}
                @if ($tag === 'a') href="{{ $queue['href'] }}" @endif
                @class([
                    'flex items-center gap-3 rounded-xl p-3 ring-1 ring-inset transition',
                    'bg-rose-50 ring-rose-200 hover:bg-rose-100 dark:bg-rose-500/10 dark:ring-rose-500/25 dark:hover:bg-rose-500/15' => $count > 0,
                    'bg-slate-50 ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700' => $count === 0,
                ])
            >
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700">
                    <x-ui.icon :name="$icon" class="h-[1.125rem] w-[1.125rem]" />
                </span>
                <span class="min-w-0">
                    <span class="block text-2xl font-semibold tabular-nums {{ $count > 0 ? 'text-rose-700 dark:text-rose-300' : 'text-slate-900 dark:text-white' }}">{{ app_number($count) }}</span>
                    <span class="block truncate text-xs text-slate-600 dark:text-slate-300">{{ $queue['label'] ?? '' }} pending</span>
                </span>
            </{{ $tag }}>
        @endforeach
    </div>
@endif
