{{--
    TopViewedPostsWidget body (phase-04 §8.12) — App\Dashboard\Cms\TopViewedPostsWidget, key `top_viewed_posts`, module
    blog_posts, permission .view_reports. Counts come from blog_post_views in the range, not from the lifetime cache.

    $data (the widget's data()):
      available    bool
      range_label  string
      posts        list<array{id: int, title: string, views: int, status: ?string, stats_url: ?string}>  top 5
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="chart-bar" title="View counts unavailable" :compact="true" />
@elseif (empty($data['posts']))
    <x-ui.empty-state icon="chart-bar" :title="'No views in '.($data['range_label'] ?? 'this period')" message="Views are counted once per reader per day." :compact="true" />
@else
    @php $top = max(1, (int) collect($data['posts'])->max('views')); @endphp
    <ol class="space-y-3">
        @foreach ($data['posts'] as $post)
            @php $share = (int) round(((int) ($post['views'] ?? 0) / $top) * 100); @endphp
            <li>
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="flex min-w-0 items-center gap-2">
                        <span class="w-4 shrink-0 text-right text-xs tabular-nums text-slate-400">{{ $loop->iteration }}</span>
                        @if (filled($post['stats_url'] ?? null))
                            <a href="{{ $post['stats_url'] }}" class="truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $post['title'] ?? '' }}</a>
                        @else
                            <span class="truncate font-medium text-slate-900 dark:text-white">{{ $post['title'] ?? '' }}</span>
                        @endif
                        @if (($post['status'] ?? 'published') !== 'published')
                            <x-ui.badge color="slate" size="xs">{{ \Illuminate\Support\Str::headline((string) $post['status']) }}</x-ui.badge>
                        @endif
                    </span>
                    <span class="shrink-0 font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((int) ($post['views'] ?? 0)) }}</span>
                </div>
                <div class="ml-6 mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" aria-hidden="true">
                    <div class="h-full rounded-full bg-brand-500 dark:bg-brand-400" style="width: {{ $share }}%"></div>
                </div>
            </li>
        @endforeach
    </ol>
@endif
