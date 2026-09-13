{{--
    The hand-picked questions of a `faq` section (phase-03 §2.11 `faq_website_section`, §741 source
    `selected`; integration K-10).

    @include('admin.cms.sections.partials.faq-picker', [
        'choices' => $options['faqs'],          // Collection<Faq>: id, question, status, faq_category_id, category
        'selectedIds' => $draft['faqs'] ?? [],  // SectionService::canonicalPayload(): pivot order
        'source' => $valueOf('source', $fields['source']),
        'canEdit' => $can['edit'],
    ])

    Posted with the section form (PUT admin.website.sections.update) as `faqs[]` in order, after an empty
    `faqs` field so that the list can be cleared. The inputs stay disabled until the list is changed, so
    saving the heading never rewrites the picks. `SectionService::syncFaqs()` re-checks every id under the
    section's row lock; the picks are part of the draft and reach visitors only on publish. Only published
    questions render (SnapshotBuilder::faqs()).

    Error keys: faqs, faqs.{n}.
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use App\Enums\Cms\FaqSource;
    use App\Services\Cms\FaqService;

    $choices = collect($choices ?? []);
    $canEdit = (bool) ($canEdit ?? false);
    $selectedIds = array_values(array_map('intval', array_filter((array) ($selectedIds ?? []), 'is_numeric')));

    // After a refused save, show the list the editor had built rather than the stored one.
    $oldPicks = old('faqs');
    $restored = is_array($oldPicks);

    if ($restored) {
        $selectedIds = array_values(array_map('intval', array_filter($oldPicks, 'is_numeric')));
    }

    $usesPicks = (string) ($source ?? '') === FaqSource::Selected->value;

    $pickerOptions = $choices->map(static function ($faq): array {
        $status = $faq->status instanceof ContentStatus ? $faq->status : ContentStatus::tryFrom((string) $faq->status);

        return [
            'id' => (int) $faq->getKey(),
            'question' => (string) $faq->question,
            'category' => $faq->category?->name,
            'status' => $status?->label() ?? (string) $faq->status,
            'published' => $status === ContentStatus::Published,
        ];
    })->values()->all();

    $pickErrors = collect($errors->getMessages())
        ->filter(static fn (array $messages, string $key): bool => $key === 'faqs' || str_starts_with($key, 'faqs.'))
        ->flatten()
        ->unique()
        ->values();
@endphp

<div
    class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 sm:p-5 dark:bg-slate-900 dark:ring-slate-800"
    x-data="{
        options: @js($pickerOptions),
        chosen: @js($selectedIds),
        touched: @js($restored),
        adding: '',
        limit: @js(FaqService::SECTION_LIMIT),
        find(id) {
            return this.options.find((option) => option.id === id) || null;
        },
        get available() {
            return this.options.filter((option) => ! this.chosen.includes(option.id));
        },
        add() {
            const id = Number(this.adding);

            if (id > 0 && ! this.chosen.includes(id) && this.chosen.length < this.limit) {
                this.chosen.push(id);
                this.changed();
            }

            this.adding = '';
        },
        move(index, step) {
            const target = index + step;

            if (target < 0 || target >= this.chosen.length) {
                return;
            }

            const list = [...this.chosen];
            [list[index], list[target]] = [list[target], list[index]];
            this.chosen = list;
            this.changed();
        },
        remove(index) {
            this.chosen.splice(index, 1);
            this.changed();
        },
        changed() {
            this.touched = true;
            this.$nextTick(() => this.$el.dispatchEvent(new Event('input', { bubbles: true })));
        },
    }"
>
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Hand-picked questions</h3>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                Shown in this order when <span class="font-medium">Which questions</span> is “{{ FaqSource::Selected->label() }}”. Only published questions appear on the site.
            </p>
        </div>
        <span class="text-xs tabular-nums text-slate-500 dark:text-slate-400" x-text="chosen.length + ' / ' + limit">{{ count($selectedIds) }} / {{ FaqService::SECTION_LIMIT }}</span>
    </div>

    @unless ($usesPicks)
        <p class="mt-3 flex items-start gap-1.5 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800/50 dark:text-slate-300 dark:ring-slate-700">
            <x-ui.icon name="information-circle" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            This list is kept but not used while the section shows another set of questions.
        </p>
    @endunless

    @if ($pickErrors->isNotEmpty())
        <p class="mt-3 text-xs font-medium text-rose-600 dark:text-rose-400" role="alert">{{ $pickErrors->first() }}</p>
    @endif

    @if ($canEdit)
        <input type="hidden" name="faqs" value="" x-bind:disabled="! touched" disabled>
        <template x-for="id in chosen" x-bind:key="'faq-input-' + id">
            <input type="hidden" name="faqs[]" x-bind:value="id" x-bind:disabled="! touched">
        </template>
    @endif

    <p class="mt-4 text-sm text-slate-500 dark:text-slate-400" x-show="chosen.length === 0" @if ($selectedIds !== []) x-cloak @endif>
        No questions picked yet.
    </p>

    <ol class="mt-4 space-y-2" x-show="chosen.length > 0" @if ($selectedIds === []) x-cloak @endif>
        <template x-for="(id, index) in chosen" x-bind:key="'faq-row-' + id">
            <li class="flex items-center gap-3 rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-slate-200 dark:bg-slate-800/50 dark:ring-slate-700">
                <span class="w-6 shrink-0 text-right text-xs tabular-nums text-slate-400 dark:text-slate-500" x-text="index + 1"></span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100" x-text="find(id) ? find(id).question : ('Question #' + id)"></p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        <template x-if="find(id)">
                            <span>
                                <span x-text="find(id).category || 'Uncategorised'"></span>
                                ·
                                <span x-bind:class="find(id).published ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'" x-text="find(id).published ? find(id).status : find(id).status + ' — not shown until published'"></span>
                            </span>
                        </template>
                        <template x-if="! find(id)">
                            <span class="text-rose-600 dark:text-rose-400">Deleted or in the trash — remove it from the list.</span>
                        </template>
                    </p>
                </div>

                @if ($canEdit)
                    <div class="flex shrink-0 items-center gap-1">
                        <x-ui.icon-button icon="chevron-up" label="Move up" size="xs" variant="secondary" x-on:click="move(index, -1)" x-bind:disabled="index === 0" />
                        <x-ui.icon-button icon="chevron-down" label="Move down" size="xs" variant="secondary" x-on:click="move(index, 1)" x-bind:disabled="index === chosen.length - 1" />
                        <x-ui.icon-button icon="x-mark" label="Remove from the list" size="xs" variant="ghost" x-on:click="remove(index)" />
                    </div>
                @endif
            </li>
        </template>
    </ol>

    @if ($canEdit)
        <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center" data-dirty-ignore>
            <label for="faq-picker-add" class="sr-only">Add a question</label>
            <select
                id="faq-picker-add"
                x-model="adding"
                x-bind:disabled="available.length === 0 || chosen.length >= limit"
                class="block w-full min-w-0 flex-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
            >
                <option value="">{{ $choices->isEmpty() ? 'No questions exist yet — add them under FAQs' : 'Choose a question to add…' }}</option>
                <template x-for="option in available" x-bind:key="'faq-option-' + option.id">
                    <option x-bind:value="option.id" x-text="(option.category ? option.category + ' — ' : '') + option.question + (option.published ? '' : ' (' + option.status + ')')"></option>
                </template>
            </select>
            <x-ui.button type="button" variant="secondary" icon="plus" size="sm" x-on:click="add()" x-bind:disabled="adding === ''">Add</x-ui.button>
        </div>
    @endif
</div>
