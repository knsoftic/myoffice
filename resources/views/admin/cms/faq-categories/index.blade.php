@extends('layouts.admin')

@section('title', 'FAQ categories')

{{--
    FAQ categories — admin.website.faq-categories.index (phase-03 §7.4, §8.11, §2.9).

    Controller variables (Admin\Cms\FaqCategoryController@index):
      $categories  LengthAwarePaginator<App\Models\Cms\FaqCategory>  sort_order asc, with faqs_count, filtered
      $filters     array<string, mixed>
      $canReorder  bool     unfiltered and on a single page (reorder posts every category id)
      $can         array{create: bool, edit: bool, delete: bool}
    Query string (CmsListRequest): search (name, slug, description), enabled (enabled|disabled), page.

    Writes:
      POST   admin.website.faq-categories.store               name, slug, description, icon, is_enabled, _category = new
      PUT    admin.website.faq-categories.update  {category}  same fields, _category = {id}
      POST   admin.website.faq-categories.reorder             order[] (JSON)
      DELETE admin.website.faq-categories.destroy {category}  its FAQs become uncategorised (nullOnDelete)
--}}

@php
    use Illuminate\Support\Facades\Route as RouteFacade;

    $can = array_merge(['create' => false, 'edit' => false, 'delete' => false], $can ?? []);
    $paginator = $categories;
    $total = method_exists($paginator, 'total') ? (int) $paginator->total() : collect($paginator)->count();
    $filtered = ! empty($filters ?? []);
    $canCreate = (bool) $can['create'];
    $canEdit = (bool) $can['edit'];
    $canDelete = (bool) $can['delete'];
    $sortable = (bool) ($canReorder ?? false) && collect($paginator->items())->count() > 1;

    $blank = ['id' => null, 'name' => '', 'slug' => '', 'description' => '', 'icon' => '', 'is_enabled' => true, 'action' => route('admin.website.faq-categories.store')];
    $old = old('_category') === null ? null : [
        'id' => old('_category') === 'new' ? null : (int) old('_category'),
        'name' => (string) old('name'),
        'slug' => (string) old('slug'),
        'description' => (string) old('description'),
        'icon' => (string) old('icon'),
        'is_enabled' => (bool) old('is_enabled', true),
        'action' => old('_category') === 'new' ? route('admin.website.faq-categories.store') : route('admin.website.faq-categories.update', (int) old('_category')),
    ];

    $iconPath = resource_path('data/icons.php');
    $iconNames = is_file($iconPath) ? \Illuminate\Support\Arr::flatten((array) require $iconPath) : [
        'academic-cap', 'banknotes', 'briefcase', 'calendar-days', 'chat-bubble-left-right', 'computer-desktop', 'credit-card',
        'document-text', 'globe-alt', 'information-circle', 'lifebuoy', 'question-mark-circle', 'shield-check', 'user-group',
    ];
    sort($iconNames);
@endphp

@section('header')
    <x-ui.page-header
        title="FAQ categories"
        subtitle="Group questions so an FAQ section can show one topic. Drag to set the order visitors see."
        icon="rectangle-stack"
        :badge="app_number($total).' '.\Illuminate\Support\Str::plural('category', $total)"
    >
        <x-slot:actions>
            @if (RouteFacade::has('admin.website.faqs.index'))
                @can('faqs.view_any')
                    <x-ui.button variant="secondary" icon="question-mark-circle" :href="route('admin.website.faqs.index')">Questions</x-ui.button>
                @endcan
            @endif
            @if ($canCreate)
                <x-ui.button icon="plus" x-on:click="$dispatch('faq-category-edit', {{ \Illuminate\Support\Js::from($blank) }})">New category</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')

    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        <x-ui.filter-bar placeholder="Search categories…" :reset="route('admin.website.faq-categories.index')">
            <x-ui.form.select name="enabled" :options="['enabled' => 'Enabled', 'disabled' => 'Disabled']" :selected="request('enabled')" placeholder="Enabled or not" size="sm" aria-label="Filter by state" />
        </x-ui.filter-bar>

        @if ($filtered && $canEdit)
            <p class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                <x-ui.icon name="information-circle" class="h-4 w-4" /> Reordering is off while a filter is active.
            </p>
        @endif

        <div x-show="navigating" x-cloak aria-hidden="true"><x-ui.skeleton variant="text" :count="5" /></div>

        <div x-show="! navigating">
            @if ($categories->isEmpty())
                <x-ui.card>
                    <x-ui.empty-state
                        icon="rectangle-stack"
                        :title="$filtered ? 'No categories match those filters' : 'No categories yet'"
                        :message="$filtered ? 'Clear the filters to see every category.' : 'Questions work without categories; add one when a page needs a topic of its own.'"
                    >
                        @if ($canCreate && ! $filtered)
                            <x-slot:action>
                                <x-ui.button icon="plus" x-on:click="$dispatch('faq-category-edit', {{ \Illuminate\Support\Js::from($blank) }})">New category</x-ui.button>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <div
                    x-data="cmsSortable(@js(['url' => route('admin.website.faq-categories.reorder'), 'key' => 'order', 'noun' => 'Category', 'disabled' => ! $sortable]))"
                    class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800"
                >
                    <p class="sr-only" aria-live="polite" x-text="announcement"></p>
                    <ul data-sortable-list class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($categories as $category)
                            @php
                                $payload = [
                                    'id' => $category->id,
                                    'name' => $category->name,
                                    'slug' => $category->slug,
                                    'description' => (string) $category->description,
                                    'icon' => (string) $category->icon,
                                    'is_enabled' => (bool) $category->is_enabled,
                                    'action' => route('admin.website.faq-categories.update', $category),
                                ];
                                $faqCount = (int) ($category->faqs_count ?? 0);
                            @endphp
                            <li
                                data-sortable-id="{{ $category->id }}"
                                data-sortable-label="{{ $category->name }}"
                                x-on:dragstart="dragStart($event)"
                                x-on:dragover.prevent="dragOver($event)"
                                x-on:dragend="dragEnd($event)"
                                @class(['flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center', 'opacity-60' => ! $category->is_enabled])
                            >
                                <div class="flex min-w-0 flex-1 items-center gap-3">
                                    <button type="button" x-on:pointerdown="arm($event)" @disabled(! $sortable) aria-roledescription="drag handle" aria-label="Reorder {{ $category->name }}" @class(['inline-flex h-8 w-5 shrink-0 items-center justify-center text-slate-400 dark:text-slate-500', 'cursor-grab hover:text-slate-700 dark:hover:text-slate-200' => $sortable, 'cursor-not-allowed opacity-40' => ! $sortable])>
                                        <x-ui.icon name="ellipsis-vertical" class="h-5 w-5" />
                                    </button>
                                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                                        <x-ui.icon :name="filled($category->icon) ? (string) $category->icon : 'question-mark-circle'" class="h-5 w-5" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium text-slate-900 dark:text-white">{{ $category->name }}</span>
                                            <span class="font-mono text-2xs text-slate-400 dark:text-slate-500">{{ $category->slug }}</span>
                                            @unless ($category->is_enabled)
                                                <x-ui.badge color="slate" variant="outline" size="sm" icon="eye-slash">Disabled</x-ui.badge>
                                            @endunless
                                        </p>
                                        @if (filled($category->description))
                                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $category->description }}</p>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex items-center gap-1">
                                    <a href="{{ route('admin.website.faqs.index', ['category' => $category->id]) }}" class="mr-2 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                        {{ app_number($faqCount) }} {{ \Illuminate\Support\Str::plural('question', $faqCount) }}
                                    </a>
                                    @if ($sortable)
                                        <x-ui.icon-button icon="chevron-up" size="xs" label="Move {{ $category->name }} up" x-on:click="move($el, -1)" />
                                        <x-ui.icon-button icon="chevron-down" size="xs" label="Move {{ $category->name }} down" x-on:click="move($el, 1)" />
                                    @endif
                                    @if ($canEdit)
                                        <x-ui.icon-button icon="pencil" size="sm" label="Edit {{ $category->name }}" x-on:click="$dispatch('faq-category-edit', {{ \Illuminate\Support\Js::from($payload) }})" />
                                    @endif
                                    @if ($canDelete)
                                        <x-ui.confirm
                                            :action="route('admin.website.faq-categories.destroy', $category)"
                                            :title="'Delete '.$category->name.'?'"
                                            :message="$faqCount > 0
                                                ? 'Its '.$faqCount.' '.\Illuminate\Support\Str::plural('question', $faqCount).' are kept and become uncategorised. An FAQ section showing this category renders nothing until another is chosen.'
                                                : 'It has no questions. It moves to the trash.'"
                                            confirm-label="Delete category"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="trash" variant="danger" size="sm" label="Delete {{ $category->name }}" />
                                            </x-slot:trigger>
                                        </x-ui.confirm>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>

                @if ($paginator->hasPages())
                    <x-ui.card :compact="true" class="mt-4">
                        <x-ui.pagination-summary :paginator="$paginator" label="categories" />
                    </x-ui.card>
                @endif
            @endif
        </div>
    </div>

    @if ($canCreate || $canEdit)
        <div
            x-data="{
                open: @js($old !== null),
                category: @js($old ?? $blank),
                blank: @js($blank),
                show(detail) { this.category = Object.assign({}, this.blank, detail || {}); this.open = true; },
            }"
            x-on:faq-category-edit.window="show($event.detail)"
            x-on:keydown.escape.window="open = false"
        >
            <div x-show="open" x-cloak class="fixed inset-0 z-modal overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="faq-category-title" style="display: none">
                <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm dark:bg-slate-950/70" x-on:click="open = false" aria-hidden="true"></div>
                <div class="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-6">
                    <form method="POST" x-bind:action="category.action" x-trap.noscroll="open" class="relative w-full overflow-hidden rounded-xl bg-white shadow-modal ring-1 ring-slate-200 sm:max-w-lg dark:bg-slate-900 dark:ring-slate-800">
                        @csrf
                        <template x-if="category.id"><input type="hidden" name="_method" value="PUT"></template>
                        <input type="hidden" name="_category" x-bind:value="category.id ? category.id : 'new'">

                        <div class="flex items-center gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                            <h2 id="faq-category-title" class="flex-1 text-base font-semibold text-slate-900 dark:text-white" x-text="category.id ? 'Edit category' : 'New category'"></h2>
                            <x-ui.icon-button icon="x-mark" label="Close" size="sm" x-on:click="open = false" />
                        </div>

                        <div class="space-y-4 px-5 py-4">
                            @if ($old !== null && $errors->any())
                                <div class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25" role="alert">{{ $errors->first() }}</div>
                            @endif

                            <div>
                                <x-ui.form.label for="fc-name" :required="true" class="mb-1.5">Name</x-ui.form.label>
                                <input id="fc-name" name="name" x-model="category.name" required maxlength="150" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                <x-ui.form.error for="name" />
                            </div>
                            <div>
                                <x-ui.form.label for="fc-slug" class="mb-1.5">Slug</x-ui.form.label>
                                <input id="fc-slug" name="slug" x-model="category.slug" maxlength="150" placeholder="Generated from the name when empty" class="block w-full rounded-lg border-slate-300 font-mono text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">FAQ sections point at a category by its slug; changing it detaches those sections.</p>
                                <x-ui.form.error for="slug" />
                            </div>
                            <div>
                                <x-ui.form.label for="fc-description" class="mb-1.5">Description</x-ui.form.label>
                                <input id="fc-description" name="description" x-model="category.description" maxlength="300" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                <x-ui.form.error for="description" />
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <x-ui.form.label for="fc-icon" class="mb-1.5">Icon</x-ui.form.label>
                                    <select id="fc-icon" name="icon" x-model="category.icon" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                        <option value="">No icon</option>
                                        @foreach ($iconNames as $iconName)
                                            <option value="{{ $iconName }}">{{ \Illuminate\Support\Str::headline($iconName) }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.form.error for="icon" />
                                </div>
                                <label class="flex items-center gap-2 pt-6 text-sm text-slate-700 dark:text-slate-200">
                                    <input type="hidden" name="is_enabled" value="0">
                                    <input type="checkbox" name="is_enabled" value="1" x-model="category.is_enabled" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800">
                                    Enabled
                                </label>
                            </div>
                        </div>

                        <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50/70 px-5 py-4 sm:flex-row sm:justify-end dark:border-slate-800 dark:bg-slate-900/60">
                            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                            <x-ui.button type="submit" icon="check"><span x-text="category.id ? 'Save category' : 'Add category'">Save</span></x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endsection
