{{--
    The moderation queue shared by testimonials and student reviews (phase-04 §8.5, §6.5). Each index view
    sets `$queue` and `$records` and includes this partial, which fills the title, header and content sections.

    Controller variables (both index actions):
      $records (passed by the view as testimonials / reviews)
                        LengthAwarePaginator of the moderatable model with the photo relation and the approver
                        (approver) eager-loaded; trashed rows only on the trashed tab
      $tab              string  pending (default) | approved | rejected | featured | all | trashed
      $counts           array{pending: int, approved: int, rejected: int, featured: int, all: int, trashed: int}
      $filters          array<string, mixed>
      $sort             string  created_at | name | rating | status | approved_at | sort_order  (default created_at)
      $direction        string  asc | desc (default desc)
      $sourceOptions    array<string, string>   ContentSource::options()
      plus the resource-specific option lists named in $queue['filters']
    Query: tab, search, rating (1-5), source, featured (1|0), from, to (Y-m-d, submitted date), and the
    resource filters (type for testimonials, course for reviews), sort, direction, page.

    $queue (set by the index view): resource, module, routePrefix, title, subtitle, icon, noun, nounPlural,
      addLabel, photoRelations, photoColumn, present (Closure(Model): array{name, meta, badge?, video?}),
      filters (list of [name, label, options]), publicAnchor (Closure(Model): ?string)

    Writes: POST {prefix}.approve / .reject (reason) / .featured through <x-cms.moderation-actions>;
    POST {prefix}.bulk-approve ids[] (flash reports "N approved, M already approved"); DELETE {prefix}.destroy.
--}}

@php
    use Illuminate\Support\Facades\Route;

    $q = array_merge([
        'resource' => 'testimonials',
        'module' => 'testimonials',
        'routePrefix' => 'admin.testimonials',
        'title' => 'Testimonials',
        'subtitle' => null,
        'icon' => 'chat-bubble-left-right',
        'noun' => 'testimonial',
        'nounPlural' => 'testimonials',
        'addLabel' => 'Add testimonial',
        'photoRelations' => [],
        'photoColumn' => null,
        'present' => null,
        'filters' => [],
        'publicAnchor' => null,
    ], $queue ?? []);

    $prefix = $q['routePrefix'];
    $module = $q['module'];
    $user = auth()->user();
    $canCreate = (bool) $user?->can($module.'.create') && Route::has($prefix.'.create');
    $canEdit = (bool) $user?->can($module.'.edit');
    $canDelete = (bool) $user?->can($module.'.delete');
    $canApprove = (bool) $user?->can($module.'.approve');
    $canBulk = $canApprove && Route::has($prefix.'.bulk-approve');
    $canRestore = (bool) $user?->can($module.'.restore') && Route::has($prefix.'.restore');

    $tab = in_array($tab ?? 'pending', ['pending', 'approved', 'rejected', 'featured', 'all', 'trashed'], true) ? ($tab ?? 'pending') : 'pending';
    $counts = $counts ?? [];
    $sort = $sort ?? 'created_at';
    $direction = $direction ?? 'desc';
    $filtered = collect(request()->except(['tab', 'page', 'sort', 'direction']))->filter(fn ($v) => filled($v))->isNotEmpty();
    $isTrash = $tab === 'trashed';
    $selectable = $canBulk && in_array($tab, ['pending', 'rejected', 'all'], true);
    $mediaService = app(\App\Services\Cms\MediaService::class);

    $tabDefs = [
        'pending' => ['Pending', 'clock'],
        'approved' => ['Approved', 'check-circle'],
        'rejected' => ['Rejected', 'x-circle'],
        'featured' => ['Featured', 'star'],
        'all' => ['All', null],
        'trashed' => ['Trashed', 'trash'],
    ];
    $tabs = [];
    foreach ($tabDefs as $key => [$label, $tabIcon]) {
        if ($key === 'trashed' && ! $user?->can($module.'.restore')) {
            continue;
        }
        $tabs[] = [
            'label' => $label,
            'url' => route($prefix.'.index', $key === 'pending' ? [] : ['tab' => $key]),
            'active' => $tab === $key,
            'count' => isset($counts[$key]) ? app_number((int) $counts[$key]) : null,
            'icon' => $tabIcon,
        ];
    }
    $pendingCount = (int) ($counts['pending'] ?? 0);
@endphp

@section('title', $q['title'])

@section('header')
    <x-ui.page-header :title="$q['title']" :subtitle="$q['subtitle']" :icon="$q['icon']" :badge="$pendingCount > 0 ? app_number($pendingCount).' waiting' : null" badge-color="rose">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route($prefix.'.create')">{{ $q['addLabel'] }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        <x-ui.tabs :tabs="$tabs" />

        <x-ui.filter-bar placeholder="Search name or review text…" :reset="route($prefix.'.index', $tab === 'pending' ? [] : ['tab' => $tab])">
            @if ($tab !== 'pending')
                <input type="hidden" name="tab" value="{{ $tab }}">
            @endif
            @foreach ($q['filters'] as $filter)
                <x-ui.form.select :name="$filter['name']" :options="$filter['options'] ?? []" :selected="request($filter['name'])" :placeholder="$filter['label']" size="sm" :aria-label="$filter['label']" />
            @endforeach
            <x-ui.form.select name="rating" :options="['5' => '5 stars', '4' => '4 stars', '3' => '3 stars', '2' => '2 stars', '1' => '1 star']" :selected="request('rating')" placeholder="Any rating" size="sm" aria-label="Filter by rating" />
            <x-ui.form.select name="source" :options="$sourceOptions ?? []" :selected="request('source')" placeholder="Any source" size="sm" aria-label="Filter by source" />
            @unless (in_array($tab, ['featured'], true))
                <x-ui.form.select name="featured" :options="['1' => 'Featured', '0' => 'Not featured']" :selected="request('featured')" placeholder="Featured or not" size="sm" aria-label="Filter by featured" />
            @endunless
            <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <span class="shrink-0">From</span>
                <input type="date" name="from" value="{{ request('from') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </label>
            <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <span class="shrink-0">To</span>
                <input type="date" name="to" value="{{ request('to') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </label>
        </x-ui.filter-bar>

        <x-ui.table loading="navigating" :is-empty="$records->isEmpty()" :columns="8" :selectable="$selectable" :selection-label="$q['noun']">
            @if ($selectable)
                <x-slot:bulk>
                    <form method="POST" action="{{ route($prefix.'.bulk-approve') }}">
                        @csrf
                        <template x-for="pickedId in selected" :key="pickedId">
                            <input type="hidden" name="ids[]" x-bind:value="pickedId">
                        </template>
                        <x-ui.button type="submit" size="sm" variant="success" icon="check">Approve selected</x-ui.button>
                    </form>
                </x-slot:bulk>
            @endif

            <x-slot:head>
                <x-ui.th-sortable column="name" :sort="$sort" :direction="$direction">Author</x-ui.th-sortable>
                <x-ui.th-sortable column="rating" :sort="$sort" :direction="$direction" default="desc">Rating</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3">Review</th>
                <th scope="col" class="px-4 py-3">Source</th>
                <x-ui.th-sortable column="created_at" :sort="$sort" :direction="$direction" default="desc">Submitted</x-ui.th-sortable>
                <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
                <x-ui.th-sortable column="approved_at" :sort="$sort" :direction="$direction" default="desc">Approved</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($records as $record)
                @php
                    $view = is_callable($q['present']) ? ($q['present'])($record) : ['name' => '#'.$record->getKey(), 'meta' => null];
                    $photoUrl = null;
                    foreach ((array) $q['photoRelations'] as $relationName) {
                        if ($record->relationLoaded($relationName) && $record->getRelation($relationName) instanceof \App\Models\Cms\MediaAsset) {
                            $photoUrl = rescue(fn () => $mediaService->url($record->getRelation($relationName), 192), null, false);
                            break;
                        }
                    }
                    $statusValue = $record->status instanceof \BackedEnum ? $record->status->value : (string) $record->status;
                    $approver = null;
                    foreach (['approver', 'approvedBy'] as $relationName) {
                        if ($record->relationLoaded($relationName)) {
                            $approver = $record->getRelation($relationName);
                            break;
                        }
                    }
                    $anchor = $statusValue === 'approved' && is_callable($q['publicAnchor']) ? ($q['publicAnchor'])($record) : null;
                    $reviewText = (string) ($record->review ?? '');
                @endphp
                <tr>
                    @if ($selectable)
                        <td class="w-10">
                            @if ($statusValue !== 'approved' && ! $isTrash)
                                <input
                                    type="checkbox"
                                    data-row-select
                                    value="{{ $record->getKey() }}"
                                    x-model="selected"
                                    aria-label="Select {{ $view['name'] }}"
                                    class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800"
                                />
                            @endif
                        </td>
                    @endif

                    <td class="min-w-[14rem]">
                        <div class="flex items-center gap-3">
                            <x-ui.avatar :src="$photoUrl" :name="$view['name']" size="md" />
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-900 dark:text-white">{{ $view['name'] }}</p>
                                @if (filled($view['meta'] ?? null))
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $view['meta'] }}</p>
                                @endif
                                <div class="mt-1 flex flex-wrap items-center gap-1">
                                    @if (! empty($view['badge']))
                                        @include('admin.marketing.partials.enum-badge', ['value' => $view['badge'], 'size' => 'xs', 'dot' => false, 'variant' => 'outline'])
                                    @endif
                                    @if ($record->is_featured)
                                        <x-ui.badge color="amber" size="xs" icon="star">Featured</x-ui.badge>
                                    @endif
                                    @if (! empty($view['video']))
                                        <x-ui.badge color="sky" size="xs" icon="video-camera">Video</x-ui.badge>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </td>

                    <td class="whitespace-nowrap">@include('admin.marketing.partials.stars', ['rating' => $record->rating])</td>

                    <td class="min-w-[18rem] max-w-md">
                        <p class="line-clamp-2 text-sm text-slate-600 dark:text-slate-300">{{ $reviewText }}</p>
                        @if (mb_strlen($reviewText) > 120)
                            <button
                                type="button"
                                class="mt-0.5 text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400"
                                x-on:click="$dispatch('open-modal', { name: 'review-read', title: @js($view['name']), meta: @js((string) ($view['meta'] ?? '')), text: @js($reviewText), rating: {{ (int) ($record->rating ?? 0) }} })"
                            >Read the full review</button>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">@include('admin.marketing.partials.enum-badge', ['value' => $record->source, 'dot' => false, 'variant' => 'outline'])</td>

                    <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                        {{ app_datetime($record->created_at) }}
                        @if (filled($record->getAttributes()['review_date'] ?? null))
                            <span class="block text-2xs">Given {{ app_date($record->getAttributes()['review_date']) }}</span>
                        @endif
                    </td>

                    <td>
                        <span @if ($statusValue === 'rejected' && filled($record->rejection_reason)) title="Rejected: {{ $record->rejection_reason }}" class="cursor-help" @endif>
                            @include('admin.marketing.partials.enum-badge', ['value' => $record->status])
                        </span>
                        @if ($statusValue === 'rejected' && filled($record->rejection_reason))
                            <p class="mt-1 line-clamp-1 max-w-[12rem] text-2xs text-rose-600 dark:text-rose-400">{{ $record->rejection_reason }}</p>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                        @if ($record->approved_at)
                            <span class="block text-slate-700 dark:text-slate-200">{{ $approver?->name ?? 'System' }}</span>
                            {{ app_datetime($record->approved_at) }}
                        @else
                            —
                        @endif
                    </td>

                    <td>
                        <div class="flex items-center justify-end gap-1">
                            @if ($isTrash)
                                @if ($canRestore)
                                    <form method="POST" action="{{ route($prefix.'.restore', $record) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" variant="secondary" icon="arrow-path">Restore</x-ui.button>
                                    </form>
                                @endif
                            @else
                                <x-cms.moderation-actions :record="$record" :module="$module" :route-prefix="$prefix" :label="$view['name']" />

                                @if ($anchor)
                                    <x-ui.icon-button icon="arrow-top-right-on-square" size="sm" label="Where {{ $view['name'] }} appears on the website" :href="$anchor" target="_blank" rel="noopener" />
                                @endif

                                @if ($canEdit && Route::has($prefix.'.edit'))
                                    <x-ui.icon-button icon="pencil" size="sm" label="Edit {{ $view['name'] }}" :href="route($prefix.'.edit', $record)" />
                                @endif

                                @if ($canDelete)
                                    <x-ui.confirm
                                        :action="route($prefix.'.destroy', $record)"
                                        :title="'Delete this '.$q['noun'].'?'"
                                        :message="'The '.$q['noun'].' by '.$view['name'].' moves to the trash and leaves every public page.'"
                                        :confirm-label="'Delete '.$q['noun']"
                                    >
                                        <x-slot:trigger>
                                            <x-ui.icon-button icon="trash" variant="danger" size="sm" label="Delete {{ $view['name'] }}" />
                                        </x-slot:trigger>
                                    </x-ui.confirm>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                @if ($filtered)
                    <x-ui.empty-state :icon="$q['icon']" :title="'No '.$q['nounPlural'].' match those filters'">
                        <x-slot:action>
                            <x-ui.button variant="secondary" :href="route($prefix.'.index', $tab === 'pending' ? [] : ['tab' => $tab])">Clear filters</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @elseif ($tab === 'pending')
                    <x-ui.empty-state icon="check-badge" title="Nothing waiting for approval" message="Every submission has been reviewed. New ones appear here first." />
                @elseif ($tab === 'trashed')
                    <x-ui.empty-state icon="trash" title="The trash is empty" />
                @elseif ($tab === 'all')
                    <x-ui.empty-state :icon="$q['icon']" :title="'No '.$q['nounPlural'].' yet'">
                        @if ($canCreate)
                            <x-slot:action>
                                <x-ui.button icon="plus" :href="route($prefix.'.create')">{{ $q['addLabel'] }}</x-ui.button>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state :icon="$q['icon']" :title="'No '.strtolower($tabDefs[$tab][0]).' '.$q['nounPlural']" :compact="true" />
                @endif
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$records" :label="$q['nounPlural']" />
            </x-slot:footer>
        </x-ui.table>
    </div>

    <x-ui.modal name="review-read" title="Full review" icon="chat-bubble-left-right" size="lg">
        <div x-data="{ title: '', meta: '', text: '', rating: 0 }" x-on:open-modal.window="if ($event.detail?.name === 'review-read') { title = $event.detail.title; meta = $event.detail.meta; text = $event.detail.text; rating = $event.detail.rating; }">
            <p class="font-semibold text-slate-900 dark:text-white" x-text="title"></p>
            <p class="text-xs text-slate-500 dark:text-slate-400" x-text="meta"></p>
            <p x-show="rating > 0" class="mt-2 text-sm text-amber-500 dark:text-amber-300" x-text="'★'.repeat(rating) + '☆'.repeat(5 - rating)" x-bind:aria-label="'Rated ' + rating + ' out of 5'"></p>
            <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-slate-700 dark:text-slate-200" x-text="text"></p>
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'review-read')">Close</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endsection
