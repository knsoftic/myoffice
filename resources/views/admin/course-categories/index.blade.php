@extends('layouts.admin')

@section('title', 'Course categories')

@section('header')
    <x-ui.page-header title="Course categories"
                      subtitle="The catalogue's top level. A category groups courses on the public site — it never decides whether they are on sale."
                      icon="rectangle-stack">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button variant="primary" icon="plus"
                             x-on:click.prevent="$dispatch('open-modal', 'add-category')">Add category</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Name or address" />

            <x-ui.form.select name="state" label="State" placeholder="Active and inactive">
                <option value="active" @selected(request('state') === 'active')>Active only</option>
                <option value="inactive" @selected(request('state') === 'inactive')>Inactive only</option>
            </x-ui.form.select>

            <div class="flex items-end gap-2 sm:col-span-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.course-categories.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="$categories->count() . ' ' . \Illuminate\Support\Str::plural('category', $categories->count())"
               subtitle="Drag a row to reorder, or use the arrows — then save.">
        <div x-data="courseCategoryOrder()">
            <x-ui.table :is-empty="$categories->isEmpty()">
                <x-slot:head>
                    <th class="px-4 py-3 text-left font-semibold">Category</th>
                    <th class="px-4 py-3 text-left font-semibold">Address</th>
                    <th class="px-4 py-3 text-right font-semibold">Courses</th>
                    <th class="px-4 py-3 text-left font-semibold">State</th>
                    <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </x-slot:head>

                @foreach ($categories as $category)
                    <tr draggable="true"
                        data-category-row
                        data-category-id="{{ $category->id }}"
                        x-on:dragstart="pick($event)"
                        x-on:dragover.prevent="over($event)"
                        x-on:drop.prevent="drop($event)"
                        class="cursor-grab">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                @if (filled($category->icon))
                                    <x-ui.icon :name="$category->icon" class="h-5 w-5 shrink-0 text-slate-400" />
                                @endif
                                <div class="min-w-0">
                                    <span class="block font-medium text-slate-900 dark:text-white">{{ $category->name }}</span>
                                    @if (filled($category->description))
                                        <span class="block truncate text-xs text-slate-500 dark:text-slate-400">
                                            {{ \Illuminate\Support\Str::limit($category->description, 70) }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </td>

                        <td class="px-4 py-3 font-mono text-xs text-slate-500 dark:text-slate-400">/{{ $category->slug }}</td>

                        <td class="px-4 py-3 text-right">
                            @if ($category->courses_count > 0)
                                <a href="{{ route('admin.courses.index', ['category' => $category->id]) }}"
                                   class="tabular-nums font-semibold text-brand-700 hover:underline dark:text-brand-300">
                                    {{ $category->courses_count }}
                                </a>
                            @else
                                <span class="tabular-nums text-slate-400">0</span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <x-ui.badge :color="$category->is_active ? 'emerald' : 'slate'" size="xs">
                                {{ $category->is_active ? 'Active' : 'Hidden' }}
                            </x-ui.badge>
                        </td>

                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                @if ($canEdit)
                                    <x-ui.icon-button icon="chevron-up" label="Move {{ $category->name }} up"
                                                      x-on:click="move($event, -1)" />
                                    <x-ui.icon-button icon="chevron-down" label="Move {{ $category->name }} down"
                                                      x-on:click="move($event, 1)" />
                                    <x-ui.icon-button icon="pencil" label="Edit {{ $category->name }}"
                                                      :href="route('admin.course-categories.edit', $category)" />
                                @endif

                                @if ($canChangeStatus)
                                    @if ($category->is_active)
                                        <x-ui.button size="sm" variant="ghost"
                                                     x-on:click.prevent="$dispatch('open-modal', 'hide-category-{{ $category->id }}')">
                                            Hide
                                        </x-ui.button>
                                    @else
                                        <form method="POST" action="{{ route('admin.course-categories.toggle', $category) }}">
                                            @csrf
                                            <input type="hidden" name="active" value="1">
                                            <x-ui.button type="submit" size="sm" variant="ghost">Show</x-ui.button>
                                        </form>
                                    @endif
                                @endif

                                @if ($canDelete)
                                    @if ($category->courses_count > 0)
                                        <span title="{{ $category->courses_count }} courses are filed under it — move them first">
                                            <x-ui.button size="sm" variant="ghost" :disabled="true">Delete</x-ui.button>
                                        </span>
                                    @else
                                        <x-ui.confirm :action="route('admin.course-categories.destroy', $category)"
                                                      title="Remove {{ $category->name }}?"
                                                      message="Nothing is filed under it, so nothing is lost."
                                                      confirm-label="Remove it">
                                            <x-slot:trigger>
                                                <x-ui.button size="sm" variant="ghost">Delete</x-ui.button>
                                            </x-slot:trigger>
                                        </x-ui.confirm>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state icon="rectangle-stack"
                                      title="No categories yet — courses need one"
                                      message="Every course sits in a category; it is how the public catalogue is navigated." />
                </x-slot:empty>
            </x-ui.table>

            @if ($canEdit && $categories->isNotEmpty())
                <form method="POST" action="{{ route('admin.course-categories.reorder') }}"
                      class="mt-3 flex items-center justify-end gap-3"
                      x-on:submit.prevent="submitOrder($event)">
                    @csrf
                    <p class="text-xs text-slate-500 dark:text-slate-400" x-show="dirty" x-cloak>
                        The order on screen has changed.
                    </p>
                    <x-ui.button type="submit" size="sm" variant="secondary" icon="bars-3">Save the order</x-ui.button>
                </form>
            @endif
        </div>
    </x-ui.card>

    {{-- Hiding a category is two decisions, and the second one is opt-in. --}}
    @if ($canChangeStatus)
        @foreach ($categories->where('is_active', true) as $category)
            @php $published = $category->courses()->where('status', 'published')->count(); @endphp

            <x-ui.modal name="hide-category-{{ $category->id }}" :title="'Hide '.$category->name" icon="eye-slash">
                <form method="POST" action="{{ route('admin.course-categories.toggle', $category) }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="active" value="0">

                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        This takes the category off the public site and out of the course form. Every course
                        inside it keeps the status it has — unless you ask for the second thing below.
                    </p>

                    @if ($published > 0)
                        <label class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-900/50 dark:bg-amber-950/40">
                            <input type="checkbox" name="cascade_courses" value="1"
                                   class="mt-0.5 rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                            <span class="text-amber-900 dark:text-amber-200">
                                Also move {{ $published }} published
                                {{ \Illuminate\Support\Str::plural('course', $published) }} to draft.
                                They come off the site too, and each move is recorded separately.
                            </span>
                        </label>
                    @else
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Nothing published is filed under it, so nothing else changes.
                        </p>
                    @endif

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost"
                                     x-on:click="$dispatch('close-modal', 'hide-category-{{ $category->id }}')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Hide it</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endforeach
    @endif

    @if ($canCreate)
        <x-ui.modal name="add-category" title="Add a category" icon="rectangle-stack">
            <form method="POST" action="{{ route('admin.course-categories.store') }}" class="space-y-4">
                @csrf
                @include('admin.course-categories._fields')

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'add-category')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Add it</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection

@push('scripts')
    <script nonce="{{ csp_nonce() }}">
        /*
         * The same rehearsal-then-commit shape the finance categories use: dragging moves rows on
         * screen and nothing else, and `order[]` is read off the DOM at submit time. A mis-drop costs
         * a refresh rather than a wrong order in the database.
         */
        function courseCategoryOrder() {
            return {
                dirty: false,
                dragged: null,

                rows() {
                    return Array.from(this.$el.querySelectorAll('[data-category-row]'));
                },

                pick(event) {
                    this.dragged = event.target.closest('[data-category-row]');
                    event.dataTransfer.effectAllowed = 'move';
                },

                over(event) {
                    const row = event.target.closest('[data-category-row]');
                    if (! row || ! this.dragged || row === this.dragged) return;

                    const before = row.compareDocumentPosition(this.dragged) & Node.DOCUMENT_POSITION_FOLLOWING;
                    row.parentNode.insertBefore(this.dragged, before ? row : row.nextSibling);
                    this.dirty = true;
                },

                drop() {
                    this.dragged = null;
                },

                /* Keyboard-reachable, so reordering is not mouse-only. */
                move(event, direction) {
                    const row = event.target.closest('[data-category-row]');
                    if (! row) return;

                    const sibling = direction < 0 ? row.previousElementSibling : row.nextElementSibling;
                    if (! sibling || ! sibling.hasAttribute('data-category-row')) return;

                    direction < 0
                        ? row.parentNode.insertBefore(row, sibling)
                        : row.parentNode.insertBefore(sibling, row);

                    this.dirty = true;
                },

                submitOrder(event) {
                    const form = event.target;

                    form.querySelectorAll('input[name="order[]"]').forEach((node) => node.remove());

                    this.rows().forEach((row) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'order[]';
                        input.value = row.dataset.categoryId;
                        form.appendChild(input);
                    });

                    form.submit();
                },
            };
        }
    </script>
@endpush
