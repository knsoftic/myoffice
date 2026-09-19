@extends('layouts.admin')

@section('title', 'Post views')

{{--
    Post views — admin.blog-posts.stats (phase-04 §8.7 "Stats", §6.7.1, R6). Behind blog_posts.view_reports and
    BlogPost::visibleTo().

    Controller variables (Admin\BlogPostController@stats):
      $post            App\Models\Cms\BlogPost
      $range           App\Support\DateRange            from ?range= / ?from= / ?to= (default: last 30 days)
      $rangePresets    optional array<string, string>   DateRange::presets()
      $daily           array<string, int>               BlogViewCounter::dailyTotals($post, $range) — 'Y-m-d' => views,
                                                        one key per day of the range (zeros included)
      $totals          array{views: int, unique_visitors: int, lifetime: int}
                       views = rows in range, unique_visitors = distinct visitor_hash in range, lifetime = views_count
      $referrers       list<array{host: ?string, views: int}>   top referrer hosts in range (null host = direct)
--}}

@php
    use Illuminate\Support\Facades\Route;

    $daily = (array) ($daily ?? []);
    $totals = array_merge(['views' => array_sum($daily), 'unique_visitors' => 0, 'lifetime' => (int) ($post->views_count ?? 0)], $totals ?? []);
    $referrers = collect($referrers ?? []);
    $topReferrer = (int) ($referrers->max('views') ?? 0);
    $statsUrl = route('admin.blog-posts.stats', $post);
    $labels = array_map(static fn (string $key): string => app_date($key, 'j M'), array_keys($daily));
    $isPublished = ($post->status instanceof \BackedEnum ? $post->status->value : (string) $post->status) === 'published';
@endphp

@section('header')
    <x-ui.page-header :title="$post->title" subtitle="Views" icon="chart-bar" :back="route('admin.blog-posts.index')">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @include('admin.marketing.partials.enum-badge', ['value' => $post->status])
            @if ($post->published_at)
                <span class="text-xs text-slate-500 dark:text-slate-400">First published {{ app_datetime($post->published_at) }}</span>
            @endif
        </div>

        <x-slot:actions>
            @if (isset($range))
                @include('admin.dashboard.partials.range', ['range' => $range, 'rangePresets' => $rangePresets ?? null, 'dashboardUrl' => $statsUrl])
            @endif
            @can('update', $post)
                <x-ui.button variant="secondary" icon="pencil" :href="route('admin.blog-posts.edit', $post)">Edit post</x-ui.button>
            @endcan
            @if ($isPublished && Route::has('site.blog.show'))
                <x-ui.icon-button icon="arrow-top-right-on-square" variant="secondary" label="View on the website" :href="route('site.blog.show', $post->slug)" target="_blank" rel="noopener" />
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="space-y-6">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-ui.stat-card label="Views in range" :value="app_number((int) $totals['views'])" icon="eye" color="brand" :delta-label="isset($range) ? $range->label() : null" />
            <x-ui.stat-card label="Unique visitors" :value="app_number((int) $totals['unique_visitors'])" icon="users" color="emerald" delta-label="distinct visitors in range" />
            <x-ui.stat-card label="Lifetime views" :value="app_number((int) $totals['lifetime'])" icon="chart-bar" color="slate" delta-label="since the post was created" />
        </div>

        <x-ui.card title="Views per day" :subtitle="isset($range) ? $range->label() : null" icon="arrow-trending-up">
            <x-ui.chart
                id="blog-post-views"
                type="line"
                :labels="$labels"
                :series="[['label' => 'Views', 'data' => array_values(array_map('intval', $daily)), 'color' => 'brand', 'fill' => true]]"
                :height="280"
                :decimals="0"
                summary="Counted views of this post per day"
                table-label="Day"
                empty-title="No views in this range"
                empty-message="Views appear here the day after the post is read."
            />
        </x-ui.card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-ui.card title="Top referrers" subtitle="Where readers came from (host only, never the full address)" icon="link" :padded="false">
                @if ($referrers->isEmpty())
                    <x-ui.empty-state icon="link" title="No referrers in this range" :compact="true" />
                @else
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($referrers as $referrer)
                            @php $share = $topReferrer > 0 ? (int) round(((int) $referrer['views'] / $topReferrer) * 100) : 0; @endphp
                            <li class="px-4 py-3 sm:px-5">
                                <div class="flex items-center justify-between gap-3 text-sm">
                                    <span class="truncate font-medium text-slate-700 dark:text-slate-200">{{ filled($referrer['host'] ?? null) ? $referrer['host'] : 'Direct or unknown' }}</span>
                                    <span class="shrink-0 tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((int) $referrer['views']) }}</span>
                                </div>
                                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" aria-hidden="true">
                                    <div class="h-full rounded-full bg-brand-500 dark:bg-brand-400" style="width: {{ $share }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <x-ui.card title="How views are counted" icon="information-circle">
                <div class="space-y-3 text-sm text-slate-600 dark:text-slate-300">
                    <p>Each visitor is counted <span class="font-semibold text-slate-900 dark:text-white">once per post per day</span>, so refreshing the page, link previews and search-engine bots add nothing. The numbers are lower and truer than a raw hit counter.</p>
                    <p>Authors and editors reading their own posts, and signed preview links, are never counted.</p>
                    <p>Visitors are identified by a one-way fingerprint; no IP address is stored with a view. Daily detail is kept for a limited time, while the lifetime total keeps counting and is never reduced.</p>
                </div>
            </x-ui.card>
        </div>
    </div>
@endsection
