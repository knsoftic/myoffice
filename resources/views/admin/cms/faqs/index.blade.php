@extends('layouts.admin')

@section('title', 'FAQs')

{{--
    FAQs — admin.website.faqs.index (phase-03 §7.4, §8.11; requirement §100). Two panes: the category
    rail on the left, the selected category's questions on the right.

    Controller variables (Admin\Cms\FaqController@index):
      $questions           LengthAwarePaginator<App\Models\Cms\Faq>  filtered, sort_order asc
      $categories          Collection<App\Models\Cms\FaqCategory>     every live category, sort_order asc, with faqs_count
      $uncategorisedCount  int
      $selectedCategory    ?App\Models\Cms\FaqCategory
      $category            ?string                                    a category id, "uncategorised" or null (all)
      $statusOptions       array<string, string>
      $filters             array<string, mixed>
      $canReorder          bool     one bucket selected, no other filter, a single page (INV-5 exact set)
      $can                 array{create, edit, toggle, delete, categories: bool}
    Query string (CmsListRequest): category (id | uncategorised), search (question + answer), status,
    featured (1), attached (1 = attached to a course through faqable_*, empty until Phase 14), page.

    Writes:
      POST   admin.website.faqs.store            question, answer, faq_category_id, is_featured, _faq = new (status is the toggle route)
      PUT    admin.website.faqs.update  {faq}    same fields, _faq = {id}
      POST   admin.website.faqs.toggle  {faq}    status
      DELETE admin.website.faqs.destroy {faq}
      POST   admin.website.faqs.reorder          faq_category_id (id or null), order[] (JSON; the category's full
                                                 set — offered only when one category is selected, unfiltered,
                                                 on a single page)
      POST   admin.website.faq-categories.store    name (rail quick add; module faq_categories)
      PUT    admin.website.faq-categories.update   {category} name, slug, description, icon, is_enabled (rail toggle)
      POST   admin.website.faq-categories.reorder  order[] (JSON, every category)
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use Illuminate\Support\Facades\Route as RouteFacade;

    $categories = collect($categories ?? []);
    $can = array_merge(['create' => false, 'edit' => false, 'toggle' => false, 'delete' => false, 'categories' => false], $can ?? []);
    $faqs = $questions;
    $categoryFilter = $category ?? null;
    $selectedCategory = $selectedCategory ?? null;
    $otherFilters = collect($filters ?? [])->except(['category'])->isNotEmpty();
    $canCreate = (bool) $can['create'];
    $canEdit = (bool) $can['edit'];
    $canToggle = (bool) $can['toggle'];
    $canDelete = (bool) $can['delete'];
    $allCount = $categories->sum(fn ($c) => (int) ($c->faqs_count ?? 0)) + (int) ($uncategorisedCount ?? 0);
    $categoriesEnabled = \App\Support\Modules::enabled('faq_categories');
    $canCategoryCreate = $categoriesEnabled && (auth()->user()?->can('faq_categories.create') ?? false);
    $canCategoryEdit = $categoriesEnabled && (auth()->user()?->can('faq_categories.edit') ?? false);
    $faqSortable = (bool) ($canReorder ?? false) && $faqs->count() > 1;

    $blankFaq = [
        'id' => null, 'question' => '', 'answer' => '', 'faq_category_id' => $selectedCategory?->id,
        'is_featured' => false,
        'action' => route('admin.website.faqs.store'),
    ];
    $oldFaq = old('_faq') === null ? null : [
        'id' => old('_faq') === 'new' ? null : (int) old('_faq'),
        'question' => (string) old('question'),
        'answer' => (string) old('answer'),
        'faq_category_id' => old('faq_category_id'),
        'is_featured' => (bool) old('is_featured'),
        'action' => old('_faq') === 'new' ? route('admin.website.faqs.store') : route('admin.website.faqs.update', (int) old('_faq')),
    ];

    $railLink = static fn (?string $value): string => route('admin.website.faqs.index', array_filter(array_merge(request()->except(['category', 'page']), ['category' => $value]), fn ($v) => filled($v)));
@endphp

@section('header')
    <x-ui.page-header title="FAQs" subtitle="Questions and answers for the public site. Only published questions render." icon="question-mark-circle">
        <x-slot:actions>
            @if ($categoriesEnabled && RouteFacade::has('admin.website.faq-categories.index'))
                @can('faq_categories.view_any')
                    <x-ui.button variant="secondary" icon="rectangle-stack" :href="route('admin.website.faq-categories.index')">Categories</x-ui.button>
                @endcan
            @endif
            @if ($canCreate)
                <x-ui.button icon="plus" x-on:click="$dispatch('faq-edit', {{ \Illuminate\Support\Js::from($blankFaq) }})">New question</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        {{-- ── Category rail ─────────────────────────────────────────────────── --}}
        <aside class="space-y-3 lg:col-span-1" aria-label="FAQ categories">
            <nav class="rounded-xl bg-white p-2 shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800">
                <a href="{{ $railLink(null) }}" @class([
                    'flex items-center justify-between rounded-lg px-3 py-2 text-sm',
                    'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => blank($categoryFilter),
                    'text-slate-700 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800' => filled($categoryFilter),
                ])>
                    <span>All questions</span>
                    <span class="text-xs tabular-nums text-slate-500">{{ app_number($allCount) }}</span>
                </a>

                @if ($categories->isNotEmpty())
                    <div x-data="cmsSortable(@js(['url' => route('admin.website.faq-categories.reorder'), 'key' => 'order', 'noun' => 'Category', 'disabled' => ! $canCategoryEdit || $categories->count() < 2]))" class="mt-1">
                        <p class="sr-only" aria-live="polite" x-text="announcement"></p>
                        <ul data-sortable-list class="space-y-0.5">
                            @foreach ($categories as $category)
                                <li
                                    data-sortable-id="{{ $category->id }}"
                                    data-sortable-label="{{ $category->name }}"
                                    x-on:dragstart="dragStart($event)"
                                    x-on:dragover.prevent="dragOver($event)"
                                    x-on:dragend="dragEnd($event)"
                                    class="group flex items-center gap-1 rounded-lg"
                                >
                                    @if ($canCategoryEdit)
                                        <button type="button" x-on:pointerdown="arm($event)" aria-roledescription="drag handle" aria-label="Reorder {{ $category->name }}" class="inline-flex h-7 w-4 shrink-0 cursor-grab items-center justify-center text-slate-300 hover:text-slate-600 dark:text-slate-600 dark:hover:text-slate-300">
                                            <x-ui.icon name="ellipsis-vertical" class="h-4 w-4" />
                                        </button>
                                    @endif

                                    <a href="{{ $railLink((string) $category->id) }}" @class([
                                        'flex min-w-0 flex-1 items-center justify-between gap-2 rounded-lg px-2 py-2 text-sm',
                                        'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => (int) $categoryFilter === (int) $category->id && $categoryFilter !== 'uncategorised',
                                        'text-slate-700 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800' => ! ((int) $categoryFilter === (int) $category->id && $categoryFilter !== 'uncategorised'),
                                        'opacity-60' => ! $category->is_enabled,
                                    ])>
                                        <span class="flex min-w-0 items-center gap-2">
                                            @if (filled($category->icon))
                                                <x-ui.icon :name="(string) $category->icon" class="h-4 w-4 shrink-0" />
                                            @endif
                                            <span class="truncate">{{ $category->name }}</span>
                                        </span>
                                        <span class="text-xs tabular-nums text-slate-500">{{ app_number((int) ($category->faqs_count ?? 0)) }}</span>
                                    </a>

                                    @if ($canCategoryEdit)
                                        <form method="POST" action="{{ route('admin.website.faq-categories.update', $category) }}" class="shrink-0">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="name" value="{{ $category->name }}">
                                            <input type="hidden" name="slug" value="{{ $category->slug }}">
                                            <input type="hidden" name="description" value="{{ $category->description }}">
                                            <input type="hidden" name="icon" value="{{ $category->icon }}">
                                            <input type="hidden" name="is_enabled" value="{{ $category->is_enabled ? 0 : 1 }}">
                                            <x-ui.icon-button type="submit" size="xs" :icon="$category->is_enabled ? 'eye' : 'eye-slash'" :label="($category->is_enabled ? 'Disable ' : 'Enable ').$category->name" />
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <a href="{{ $railLink('uncategorised') }}" @class([
                    'mt-1 flex items-center justify-between rounded-lg px-3 py-2 text-sm',
                    'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => $categoryFilter === 'uncategorised',
                    'text-slate-500 hover:bg-slate-50 dark:text-slate-400 dark:hover:bg-slate-800' => $categoryFilter !== 'uncategorised',
                ])>
                    <span class="italic">Uncategorised</span>
                    <span class="text-xs tabular-nums">{{ app_number((int) ($uncategorisedCount ?? 0)) }}</span>
                </a>
            </nav>

            @if ($categories->isEmpty())
                <p class="px-1 text-xs text-slate-500 dark:text-slate-400">No categories yet. Questions can stay uncategorised.</p>
            @endif

            @if ($canCategoryCreate)
                <form method="POST" action="{{ route('admin.website.faq-categories.store') }}" class="flex gap-2">
                    @csrf
                    <input type="hidden" name="is_enabled" value="1">
                    <label for="rail-new-category" class="sr-only">New category name</label>
                    <input id="rail-new-category" name="name" required maxlength="150" placeholder="New category…" class="block w-full min-w-0 rounded-lg border-slate-300 py-1.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                    <x-ui.icon-button type="submit" icon="plus" variant="secondary" size="sm" label="Add category" />
                </form>
            @endif
        </aside>

        {{-- ── Questions ────────────────────────────────────────────────────── --}}
        <section class="space-y-4 lg:col-span-3" x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true">
            <x-ui.filter-bar placeholder="Search questions and answers…" :reset="route('admin.website.faqs.index', array_filter(['category' => $categoryFilter]))">
                @if (filled($categoryFilter))
                    <input type="hidden" name="category" value="{{ $categoryFilter }}">
                @endif
                <x-ui.form.select name="status" :options="$statusOptions ?? ContentStatus::options()" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
                <x-ui.form.select name="featured" :options="['1' => 'Featured only']" :selected="request('featured')" placeholder="Featured or not" size="sm" aria-label="Filter featured" />
                <x-ui.form.select name="attached" :options="['1' => 'Attached to a course', '0' => 'Not attached']" :selected="request('attached')" placeholder="Any attachment" size="sm" aria-label="Filter by course attachment" />
            </x-ui.filter-bar>

            @if ($canEdit && filled($categoryFilter) && ! $faqSortable && $faqs->count() > 1)
                <p class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                    <x-ui.icon name="information-circle" class="h-4 w-4" />
                    Reordering is available when no filter is active and the whole category fits on one page.
                </p>
            @elseif ($canEdit && blank($categoryFilter) && $faqs->count() > 1)
                <p class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                    <x-ui.icon name="information-circle" class="h-4 w-4" />
                    Pick a category on the left to drag its questions into order.
                </p>
            @endif

            <div x-show="navigating" x-cloak class="space-y-2" aria-hidden="true">
                <x-ui.skeleton variant="text" :count="6" />
            </div>

            <div x-show="! navigating">
                @if ($faqs->isEmpty())
                    <x-ui.card>
                        <x-ui.empty-state
                            icon="question-mark-circle"
                            :title="$otherFilters ? 'No questions match those filters' : ($selectedCategory ? 'No questions in '.$selectedCategory->name.' yet' : 'No questions yet')"
                            :message="$otherFilters ? 'Clear the filters to see every question here.' : 'Add the questions visitors ask most. A draft question never renders.'"
                        >
                            @if ($canCreate && ! $otherFilters)
                                <x-slot:action>
                                    <x-ui.button icon="plus" x-on:click="$dispatch('faq-edit', {{ \Illuminate\Support\Js::from($blankFaq) }})">New question</x-ui.button>
                                </x-slot:action>
                            @endif
                        </x-ui.empty-state>
                    </x-ui.card>
                @else
                    <div
                        x-data="cmsSortable(@js([
                            'url' => route('admin.website.faqs.reorder'),
                            'payload' => ['faq_category_id' => $categoryFilter === 'uncategorised' ? null : ($selectedCategory?->id)],
                            'key' => 'order',
                            'noun' => 'Question',
                            'disabled' => ! $faqSortable,
                        ]))"
                        class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800"
                    >
                        <p class="sr-only" aria-live="polite" x-text="announcement"></p>
                        <ul data-sortable-list class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($faqs as $faq)
                                @php
                                    $isPublished = $faq->status === ContentStatus::Published;
                                    $faqCategory = $faq->relationLoaded('category') ? $faq->category : null;
                                    $payload = [
                                        'id' => $faq->id,
                                        'question' => $faq->question,
                                        'answer' => (string) $faq->answer,
                                        'faq_category_id' => $faq->faq_category_id,
                                        'is_featured' => (bool) $faq->is_featured,
                                        'action' => route('admin.website.faqs.update', $faq),
                                    ];
                                @endphp
                                <li
                                    data-sortable-id="{{ $faq->id }}"
                                    data-sortable-label="{{ \Illuminate\Support\Str::limit($faq->question, 60) }}"
                                    x-data="{ open: false }"
                                    x-on:dragstart="dragStart($event)"
                                    x-on:dragover.prevent="dragOver($event)"
                                    x-on:dragend="dragEnd($event)"
                                    class="px-4 py-3"
                                >
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($faqSortable)
                                            <button type="button" x-on:pointerdown="arm($event)" aria-roledescription="drag handle" aria-label="Reorder this question" class="inline-flex h-7 w-5 cursor-grab items-center justify-center text-slate-400 hover:text-slate-700 dark:text-slate-500 dark:hover:text-slate-200">
                                                <x-ui.icon name="ellipsis-vertical" class="h-4 w-4" />
                                            </button>
                                        @endif

                                        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                                            <x-ui.icon name="chevron-right" class="h-4 w-4 shrink-0 text-slate-400 transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
                                            <span class="text-sm font-medium text-slate-900 dark:text-white">{{ $faq->question }}</span>
                                        </button>

                                        <div class="flex flex-wrap items-center gap-1.5">
                                            @if ($faq->is_featured)
                                                <x-ui.badge color="amber" size="sm" icon="star">Featured</x-ui.badge>
                                            @endif
                                            @if (blank($categoryFilter) && $faqCategory)
                                                <x-ui.badge color="slate" variant="outline" size="sm">{{ $faqCategory->name }}</x-ui.badge>
                                            @endif
                                            @if (filled($faq->faqable_type))
                                                <x-ui.badge color="indigo" size="sm" icon="academic-cap">Course</x-ui.badge>
                                            @endif
                                            <x-ui.badge :color="$faq->status->color()" size="sm" :dot="true">{{ $faq->status->label() }}</x-ui.badge>
                                        </div>

                                        <div class="ml-auto flex items-center gap-1">
                                            @if ($faqSortable)
                                                <x-ui.icon-button icon="chevron-up" size="xs" label="Move up" x-on:click="move($el, -1)" />
                                                <x-ui.icon-button icon="chevron-down" size="xs" label="Move down" x-on:click="move($el, 1)" />
                                            @endif
                                            @if ($canEdit && blank($faq->faqable_type))
                                                <x-ui.icon-button icon="pencil" size="xs" label="Edit this question" x-on:click="$dispatch('faq-edit', {{ \Illuminate\Support\Js::from($payload) }})" />
                                            @endif
                                            @if ($canToggle)
                                                <form method="POST" action="{{ route('admin.website.faqs.toggle', $faq) }}">
                                                    @csrf
                                                    <input type="hidden" name="status" value="{{ $isPublished ? ContentStatus::Draft->value : ContentStatus::Published->value }}">
                                                    <x-ui.icon-button type="submit" size="xs" :icon="$isPublished ? 'eye-slash' : 'check-circle'" :label="$isPublished ? 'Unpublish this question' : 'Publish this question'" />
                                                </form>
                                            @endif
                                            @if ($canDelete)
                                                <x-ui.confirm
                                                    :action="route('admin.website.faqs.destroy', $faq)"
                                                    title="Delete this question?"
                                                    :message="'“'.\Illuminate\Support\Str::limit($faq->question, 80).'” leaves every FAQ section that shows it. It moves to the trash.'"
                                                    confirm-label="Delete question"
                                                >
                                                    <x-slot:trigger>
                                                        <x-ui.icon-button icon="trash" variant="danger" size="xs" label="Delete this question" />
                                                    </x-slot:trigger>
                                                </x-ui.confirm>
                                            @endif
                                        </div>
                                    </div>

                                    <div x-show="open" x-cloak class="mt-2 border-l-2 border-slate-200 pl-4 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300">
                                        {{-- The stored answer is already sanitised on write; it is sanitised again here (INV-13). --}}
                                        <div class="space-y-2 [&_a]:text-brand-600 [&_a]:underline [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5">
                                            {!! \App\Support\RichText::sanitize((string) $faq->answer) !!}
                                        </div>
                                        @if (filled($faq->faqable_type))
                                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Managed from its course — edit it there.</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <x-ui.card :compact="true" class="mt-4">
                        <x-ui.pagination-summary :paginator="$faqs" label="questions" />
                    </x-ui.card>
                @endif
            </div>
        </section>
    </div>

    {{-- ── Add / edit dialog ───────────────────────────────────────────────── --}}
    @if ($canCreate || $canEdit)
        <div
            x-data="{
                open: @js($oldFaq !== null),
                faq: @js($oldFaq ?? $blankFaq),
                blank: @js($blankFaq),
                show(detail) { this.faq = Object.assign({}, this.blank, detail || {}); this.open = true; },
            }"
            x-on:faq-edit.window="show($event.detail)"
            x-on:keydown.escape.window="open = false"
        >
            <div x-show="open" x-cloak class="fixed inset-0 z-modal overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="faq-dialog-title" style="display: none">
                <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm dark:bg-slate-950/70" x-on:click="open = false" aria-hidden="true"></div>
                <div class="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-6">
                    <form method="POST" x-bind:action="faq.action" x-trap.noscroll="open" class="relative w-full overflow-hidden rounded-xl bg-white shadow-modal ring-1 ring-slate-200 sm:max-w-2xl dark:bg-slate-900 dark:ring-slate-800">
                        @csrf
                        <template x-if="faq.id"><input type="hidden" name="_method" value="PUT"></template>
                        <input type="hidden" name="_faq" x-bind:value="faq.id ? faq.id : 'new'">

                        <div class="flex items-center gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                            <h2 id="faq-dialog-title" class="flex-1 text-base font-semibold text-slate-900 dark:text-white" x-text="faq.id ? 'Edit question' : 'New question'"></h2>
                            <x-ui.icon-button icon="x-mark" label="Close" size="sm" x-on:click="open = false" />
                        </div>

                        <div class="max-h-[70vh] space-y-4 overflow-y-auto px-5 py-4">
                            @if ($oldFaq !== null && $errors->any())
                                <div class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25" role="alert">{{ $errors->first() }}</div>
                            @endif

                            <div>
                                <x-ui.form.label for="faq-question" :required="true" class="mb-1.5">Question</x-ui.form.label>
                                <input id="faq-question" name="question" x-model="faq.question" required maxlength="300" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                @include('admin.cms.partials.length-meter', ['for' => 'faq-question', 'max' => 300])
                                <x-ui.form.error for="question" />
                            </div>

                            <div>
                                <x-ui.form.label for="faq-answer" :required="true" class="mb-1.5">Answer</x-ui.form.label>
                                <textarea id="faq-answer" name="answer" x-model="faq.answer" rows="8" required class="block w-full rounded-lg border-slate-300 font-mono text-xs leading-relaxed shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"></textarea>
                                <p class="mt-1 text-2xs text-slate-400 dark:text-slate-500">Plain text or simple HTML (paragraphs, bold, lists, links). Anything else is removed on save.</p>
                                <x-ui.form.error for="answer" />
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <x-ui.form.label for="faq-category" class="mb-1.5">Category</x-ui.form.label>
                                    <select id="faq-category" name="faq_category_id" x-model="faq.faq_category_id" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                        <option value="">Uncategorised</option>
                                        @foreach ($categories as $category)
                                            <option value="{{ $category->id }}" @disabled(! $category->is_enabled)>{{ $category->name }}{{ $category->is_enabled ? '' : ' (disabled)' }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.form.error for="faq_category_id" />
                                </div>
                                <label class="flex items-center gap-2 pt-6 text-sm text-slate-700 dark:text-slate-200">
                                    <input type="hidden" name="is_featured" value="0">
                                    <input type="checkbox" name="is_featured" value="1" x-model="faq.is_featured" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800">
                                    Featured
                                </label>
                            </div>
                        </div>

                        <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50/70 px-5 py-4 sm:flex-row sm:justify-end dark:border-slate-800 dark:bg-slate-900/60">
                            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                            <x-ui.button type="submit" icon="check"><span x-text="faq.id ? 'Save question' : 'Add question'">Save</span></x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endsection
