@extends('layouts.admin')

@section('title', 'Finance categories')

@php
    $isExpense = $type === \App\Enums\FinanceCategoryType::Expense;
@endphp

@section('header')
    <x-ui.page-header title="Finance categories"
                      subtitle="What the reports group by. The code is fixed once it exists — renaming a category is safe, re-coding one would split a year of history into two halves that no longer add up."
                      icon="tag" />
@endsection

@section('content')
    <x-ui.tabs class="mb-4" :tabs="collect($types)->map(fn ($case) => [
        'label' => $case->label(),
        'url' => route('admin.finance-categories.index', ['type' => $case->value]),
        'active' => $case === $type,
    ])->all()" />

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.card :title="$type->label().' categories'"
                       :subtitle="$categories->count().' in the list · drag a row, or use the arrows, then save the order'">
                <div x-data="financeCategoryOrder()">
                    <x-ui.table :is-empty="$categories->isEmpty()">
                        <x-slot:head>
                            <th class="px-4 py-3 text-left font-semibold">Category</th>
                            <th class="px-4 py-3 text-left font-semibold">Code</th>
                            <th class="px-4 py-3 text-left font-semibold">Business</th>
                            <th class="px-4 py-3 text-right font-semibold">Used by</th>
                            <th class="px-4 py-3 text-left font-semibold">State</th>
                            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </x-slot:head>

                        @foreach ($categories as $category)
                            @php
                                $usage = (int) $category->expenses_count + (int) $category->incomes_count;
                            @endphp
                            <tr draggable="true"
                                data-category-row
                                data-category-id="{{ $category->id }}"
                                x-on:dragstart="pick($event)"
                                x-on:dragover.prevent="over($event)"
                                x-on:drop.prevent="drop($event)"
                                class="cursor-grab">
                                <td class="px-4 py-3">
                                    <span class="block font-medium text-slate-900 dark:text-white">{{ $category->name }}</span>
                                    @if (filled($category->description))
                                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $category->description }}</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 font-mono text-xs text-slate-500 dark:text-slate-400">
                                    {{ $category->code }}
                                    @if ($category->isReserved())
                                        <x-ui.badge color="amber" size="xs">reserved</x-ui.badge>
                                    @endif
                                </td>

                                <td class="px-4 py-3">
                                    @if ($category->context)
                                        <x-ui.badge :color="$category->context->color()" size="xs">{{ $category->context->label() }}</x-ui.badge>
                                    @else
                                        <span class="text-xs text-slate-500 dark:text-slate-400">either</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $usage }}</td>

                                <td class="px-4 py-3">
                                    <x-ui.badge :color="$category->is_active ? 'emerald' : 'slate'" size="xs">
                                        {{ $category->is_active ? 'active' : 'off' }}
                                    </x-ui.badge>
                                </td>

                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-ui.icon-button icon="chevron-up" label="Move {{ $category->name }} up"
                                                          x-on:click="move($event, -1)" />
                                        <x-ui.icon-button icon="chevron-down" label="Move {{ $category->name }} down"
                                                          x-on:click="move($event, 1)" />

                                        @if ($canEdit)
                                            <x-ui.icon-button icon="pencil" label="Rename {{ $category->name }}"
                                                              x-on:click="$dispatch('open-modal', 'edit-category-{{ $category->id }}')" />
                                        @endif

                                        @if ($canChangeStatus && $category->isDeactivatable())
                                            <form method="POST" action="{{ route('admin.finance-categories.toggle', $category) }}">
                                                @csrf
                                                <input type="hidden" name="active" value="{{ $category->is_active ? 0 : 1 }}">
                                                <x-ui.button type="submit" size="sm" variant="ghost">
                                                    {{ $category->is_active ? 'Off' : 'On' }}
                                                </x-ui.button>
                                            </form>
                                        @endif

                                        @if ($canDelete)
                                            @if ($category->isReserved() || $usage > 0)
                                                <span title="{{ $category->isReserved()
                                                    ? 'Every paid payroll run posts here — it cannot be removed'
                                                    : $usage.' rows are filed under it. Switch it off instead.' }}">
                                                    <x-ui.button size="sm" variant="ghost" :disabled="true">Delete</x-ui.button>
                                                </span>
                                            @else
                                                <x-ui.confirm :action="route('admin.finance-categories.destroy', $category)"
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
                            <x-ui.empty-state icon="tag"
                                              title="No categories yet"
                                              message="Add one on the right. A report groups by the code, so give it one you will still recognise next year." />
                        </x-slot:empty>
                    </x-ui.table>

                    @if ($canEdit && $categories->isNotEmpty())
                        <form method="POST" action="{{ route('admin.finance-categories.reorder') }}"
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
        </div>

        <div class="space-y-4">
            @if ($canCreate)
                <x-ui.card :title="'Add a '.strtolower($type->label()).' category'">
                    <form method="POST" action="{{ route('admin.finance-categories.store') }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="type" value="{{ $type->value }}">

                        <x-ui.form.input name="name" label="Name" required maxlength="100" :value="old('name')" />

                        <x-ui.form.input name="code" label="Code" maxlength="32" :value="old('code')"
                                         help="Lowercase letters, numbers and underscores. Left blank it is made from the name. It cannot be changed later." />

                        <x-ui.form.select name="context" label="Business" placeholder="Either business">
                            @foreach ($contexts as $context)
                                <option value="{{ $context->value }}" @selected(old('context') === $context->value)>
                                    {{ $context->label() }}
                                </option>
                            @endforeach
                        </x-ui.form.select>

                        <x-ui.form.input name="description" label="Description" maxlength="255" :value="old('description')" />

                        <x-ui.button type="submit" variant="primary" icon="plus" block>Add it</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
                <p class="font-semibold text-slate-900 dark:text-white">This module holds no amount.</p>
                <p class="mt-1">
                    Somebody who maintains the list never has to be given sight of a figure to do it —
                    there is no <code>view_financial</code> here to hand out.
                </p>
                @if ($isExpense)
                    <p class="mt-2">
                        <strong>Salaries</strong> is where every paid payroll run posts. It cannot be
                        deleted or switched off: the cost would vanish from the profit-and-loss statement
                        rather than fail loudly.
                    </p>
                @endif
            </div>
        </div>
    </div>

    @if ($canEdit)
        @foreach ($categories as $category)
            <x-ui.modal name="edit-category-{{ $category->id }}" :title="'Rename '.$category->name" icon="pencil">
                <form method="POST" action="{{ route('admin.finance-categories.update', $category) }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <x-ui.form.input name="name" label="Name" required maxlength="100" :value="$category->name" />
                    <x-ui.form.input name="description" label="Description" maxlength="255" :value="$category->description" />

                    <x-ui.form.select name="context" label="Business" placeholder="Either business">
                        @foreach ($contexts as $context)
                            <option value="{{ $context->value }}" @selected($category->context === $context)>
                                {{ $context->label() }}
                            </option>
                        @endforeach
                    </x-ui.form.select>

                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        The code <code>{{ $category->code }}</code> stays as it is. A year of reports groups
                        by it.
                    </p>

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost"
                                     x-on:click="$dispatch('close-modal', 'edit-category-{{ $category->id }}')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endforeach
    @endif
@endsection

@push('scripts')
    <script nonce="{{ csp_nonce() }}">
        /*
         * Reordering is a local rehearsal that only becomes real when the form is submitted: the rows
         * move on screen, and `order[]` is read off the DOM at submit time. Nothing is saved by a drag,
         * so a mis-drop costs a page refresh rather than a wrong sort order in the database.
         */
        function financeCategoryOrder() {
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

                /*
                 * The ids are written into the form at submit time, imperatively. A reactive
                 * `x-for` would not have rendered them before the browser submitted, and the
                 * request would have carried an empty order — the kind of bug that looks like a
                 * server problem.
                 */
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
