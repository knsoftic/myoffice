{{--
    BlogActivityWidget body (phase-04 §8.12) — App\Dashboard\Cms\BlogActivityWidget, key `blog_activity`, module
    blog_posts, permission .view_any. Counts go through BlogPost::visibleTo(), so an author sees their own numbers.

    $data (the widget's data()):
      available    bool
      published    int     published in the range
      scheduled    int     scheduled ahead (standing)
      drafts       int     drafts (standing)
      range_label  string
      links        array{published: ?string, scheduled: ?string, drafts: ?string}
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="newspaper" title="Blog activity unavailable" :compact="true" />
@else
    <div class="space-y-4">
        <div class="grid grid-cols-3 gap-3">
            @foreach ([['published', 'Published', 'emerald'], ['scheduled', 'Scheduled', 'amber'], ['drafts', 'Drafts', 'slate']] as [$key, $label, $tone])
                @php $href = $data['links'][$key] ?? null; @endphp
                <{{ filled($href) ? 'a' : 'div' }} @if (filled($href)) href="{{ $href }}" @endif class="block rounded-xl bg-slate-50 p-3 ring-1 ring-inset ring-slate-200 transition hover:ring-slate-300 dark:bg-slate-800/40 dark:ring-slate-700 dark:hover:ring-slate-600">
                    <p @class([
                        'text-2xl font-semibold tabular-nums',
                        'text-emerald-700 dark:text-emerald-300' => $tone === 'emerald',
                        'text-amber-700 dark:text-amber-300' => $tone === 'amber',
                        'text-slate-900 dark:text-white' => $tone === 'slate',
                    ])>{{ app_number((int) ($data[$key] ?? 0)) }}</p>
                    <p class="text-xs text-slate-600 dark:text-slate-300">{{ $label }}</p>
                </{{ filled($href) ? 'a' : 'div' }}>
            @endforeach
        </div>
        <p class="text-2xs text-slate-400 dark:text-slate-500">Published counts {{ $data['range_label'] ?? 'this period' }}; scheduled and drafts are standing totals.</p>

        @if (\Illuminate\Support\Facades\Route::has('admin.blog-posts.calendar'))
            <x-ui.button variant="ghost" size="sm" icon="calendar-days" :href="route('admin.blog-posts.calendar')">Open the calendar</x-ui.button>
        @endif
    </div>
@endif
