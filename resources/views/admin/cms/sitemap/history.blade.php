@extends('layouts.admin')

@section('title', 'Sitemap history')

{{--
    Sitemap build history — admin.website.seo.sitemap.history (phase-03 §7.5, §2.14 sitemap_generations).

    Controller variables (Admin\Cms\SitemapController@history):
      $generations    LengthAwarePaginator<App\Models\Cms\SitemapGeneration>   newest first
      $authors        array<int, string>                                     user id => name
      $enabled        bool                                                   seo.sitemap_enabled
      $filters        array<string, mixed>
      $canRegenerate  bool                                                   seo.edit
    Query string (CmsListRequest): status (ok|failed), page.

    An append-only log. The one write offered here is POST admin.website.seo.sitemap.regenerate (throttled).
--}}

@php
    use Illuminate\Support\Facades\Route as RouteFacade;

    $authors = $authors ?? [];
    $filtered = ! empty($filters ?? []);
@endphp

@section('header')
    <x-ui.page-header title="Sitemap history" subtitle="Every sitemap build: what triggered it, how many URLs each source contributed, and whether it succeeded." icon="clock" :back="route('admin.website.seo.index')">
        <x-slot:actions>
            @if (($canRegenerate ?? false) && RouteFacade::has('admin.website.seo.sitemap.regenerate'))
                <form method="POST" action="{{ route('admin.website.seo.sitemap.regenerate') }}">
                    @csrf
                    <x-ui.button type="submit" icon="arrow-path">Regenerate now</x-ui.button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        @unless ($enabled ?? true)
            <div class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" />
                <span>The sitemap is switched off in Settings → SEO, so /sitemap.xml answers 404. Builds are still recorded.</span>
            </div>
        @endunless

        <x-ui.filter-bar placeholder="Search…" :reset="route('admin.website.seo.sitemap.history')">
            <x-ui.form.select name="status" :options="['ok' => 'Succeeded', 'failed' => 'Failed']" :selected="request('status')" placeholder="Any outcome" size="sm" aria-label="Filter by outcome" />
        </x-ui.filter-bar>

        <x-ui.table loading="navigating" :is-empty="$generations->isEmpty()" :columns="6">
            <x-slot:head>
                <th scope="col" class="px-4 py-3">Built</th>
                <th scope="col" class="px-4 py-3">Trigger</th>
                <th scope="col" class="px-4 py-3 text-right">URLs</th>
                <th scope="col" class="px-4 py-3">Sources</th>
                <th scope="col" class="px-4 py-3 text-right">Size · time</th>
                <th scope="col" class="px-4 py-3">Outcome</th>
            </x-slot:head>

            @foreach ($generations as $generation)
                @php
                    $author = $generation->created_by ? ($authors[(int) $generation->created_by] ?? null) : null;
                    $providers = is_array($generation->providers) ? $generation->providers : [];
                    $failed = (string) $generation->status !== 'ok';
                @endphp
                <tr>
                    <td class="whitespace-nowrap">
                        <p class="text-slate-900 dark:text-white">{{ app_datetime($generation->created_at) }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $author ?? 'System' }}</p>
                    </td>
                    <td><x-ui.badge color="slate" variant="outline" size="sm">{{ \Illuminate\Support\Str::headline((string) $generation->trigger) }}</x-ui.badge></td>
                    <td class="text-right font-semibold tabular-nums">{{ app_number((int) $generation->url_count) }}</td>
                    <td>
                        <div class="flex flex-wrap gap-1">
                            @forelse ($providers as $provider => $count)
                                <x-ui.badge color="indigo" size="sm">{{ $provider }}: {{ app_number((int) $count) }}</x-ui.badge>
                            @empty
                                <span class="text-slate-400">—</span>
                            @endforelse
                        </div>
                    </td>
                    <td class="whitespace-nowrap text-right text-xs tabular-nums text-slate-500 dark:text-slate-400">
                        {{ app_number(round(((int) $generation->byte_size) / 1024, 1), 1) }} KB · {{ app_number((int) $generation->duration_ms) }} ms
                    </td>
                    <td>
                        <x-ui.badge :color="$failed ? 'rose' : 'emerald'" size="sm" :dot="true">{{ $failed ? 'Failed' : 'OK' }}</x-ui.badge>
                        @if ($failed && filled($generation->failure_reason))
                            <p class="mt-1 max-w-xs text-xs text-rose-600 dark:text-rose-400">{{ $generation->failure_reason }}</p>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state
                    icon="globe-alt"
                    :title="$filtered ? 'No builds match that filter' : 'No sitemap builds recorded'"
                    message="A build is logged each time the sitemap is regenerated — manually, after a publish, or on the nightly schedule."
                />
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$generations" label="builds" />
            </x-slot:footer>
        </x-ui.table>
    </div>
@endsection
