{{--
    The one grade-scale form, shared by create and edit (phase-19-23 §6.8, INV-20-3).

    **The band rows are an Alpine list, and the geometry is checked on the server.** Nothing here
    validates contiguity: `GradeBandValidator` owns that, because it is a fact about the whole set and
    a second copy in JavaScript is a second opinion that will eventually disagree with the first. What
    the browser does is add and remove rows and keep the indices contiguous so `bands.*` binds.

    **Band edges go to two decimals.** The columns hold four, but a percentage is worked out to two,
    so an edge at `39.9950` would leave `40.00` in no band at all. The Form Request refuses it and says
    so; the step attribute here just stops the spinner offering it.
--}}
@php($editing = isset($scale))
@php($bandsLocked = $bandsLocked ?? false)
@php(
    $existingBands = old('bands', $editing
        ? $scale->bands->map(fn ($band) => [
            'grade' => $band->grade,
            'title' => $band->title,
            'min_percentage' => $band->min_percentage,
            'max_percentage' => $band->max_percentage,
            'grade_point' => $band->grade_point,
            'is_pass' => (bool) $band->is_pass,
            'color' => $band->color,
            'remark_template' => $band->remark_template,
        ])->values()->all()
        : [
            ['grade' => 'F', 'title' => 'Fail', 'min_percentage' => '0.00', 'max_percentage' => '39.99', 'grade_point' => '0.00', 'is_pass' => false, 'color' => 'rose', 'remark_template' => null],
            ['grade' => 'P', 'title' => 'Pass', 'min_percentage' => '40.00', 'max_percentage' => '100.00', 'grade_point' => '1.00', 'is_pass' => true, 'color' => 'emerald', 'remark_template' => null],
        ])
)

<div class="grid gap-6">
    <x-ui.card>
        <x-ui.section-heading title="The scale" />

        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.form.input name="code" label="Code" required
                             :value="old('code', $editing ? $scale->code : '')"
                             placeholder="DEFAULT"
                             help="Letters, digits, dashes and underscores." />

            <div class="sm:col-span-2">
                <x-ui.form.input name="name" label="Name" required
                                 :value="old('name', $editing ? $scale->name : '')"
                                 placeholder="Standard percentage scale" />
            </div>

            <x-ui.form.input type="number" step="0.0001" min="0" max="100"
                             name="pass_percentage" label="Pass line" suffix="%" required
                             :value="old('pass_percentage', $editing ? $scale->pass_percentage : '40.0000')"
                             help="Used when an exam sets no pass mark of its own." />

            <x-ui.form.input type="number" name="sort_order" label="Order" min="0"
                             :value="old('sort_order', $editing ? $scale->sort_order : 0)" />

            <div class="flex items-end">
                <x-ui.form.toggle name="is_active" label="In use"
                                  :checked="(bool) old('is_active', $editing ? $scale->is_active : true)" />
            </div>

            <div class="sm:col-span-3">
                <x-ui.form.textarea name="description" label="Description" rows="2"
                                    :value="old('description', $editing ? $scale->description : '')" />
            </div>
        </div>
    </x-ui.card>

    <x-ui.card x-data="{
        bands: @js($existingBands),
        add() {
            this.bands.push({ grade: '', title: '', min_percentage: '', max_percentage: '', grade_point: '', is_pass: true, color: 'slate', remark_template: '' });
        },
        remove(index) {
            if (this.bands.length > 2) { this.bands.splice(index, 1); }
        },
    }">
        <x-ui.section-heading title="Bands"
                              description="Contiguous, non-overlapping and covering exactly 0–100, with one crossing from fail to pass. The server checks all of it and names the offending pair." />

        @if ($bandsLocked)
            <div class="mb-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                <p class="font-medium">These bands have graded somebody.</p>
                <p class="mt-1">
                    Saving replaces the whole set, and a band behind a result that was printed refuses to be
                    removed — so an edit here will fail rather than rewrite what a student was told. Retire this
                    scale and create its successor instead.
                </p>
            </div>
        @endif

        @error('bands')
            <p class="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ $message }}</p>
        @enderror

        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem] text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                        <th class="pb-2 pr-3">Grade</th>
                        <th class="pb-2 pr-3">Title</th>
                        <th class="pb-2 pr-3 text-right">From %</th>
                        <th class="pb-2 pr-3 text-right">To %</th>
                        <th class="pb-2 pr-3 text-right">Points</th>
                        <th class="pb-2 pr-3">Colour</th>
                        <th class="pb-2 pr-3">Pass</th>
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(band, index) in bands" :key="index">
                        <tr class="border-t border-slate-100 dark:border-slate-700/60">
                            <td class="py-2 pr-3">
                                <input type="text" maxlength="8" x-model="band.grade"
                                       :name="`bands[${index}][grade]`" required
                                       class="w-16 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                            </td>
                            <td class="py-2 pr-3">
                                <input type="text" maxlength="80" x-model="band.title"
                                       :name="`bands[${index}][title]`"
                                       class="w-40 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                            </td>
                            <td class="py-2 pr-3">
                                <input type="number" step="0.01" min="0" max="100" x-model="band.min_percentage"
                                       :name="`bands[${index}][min_percentage]`" required
                                       class="w-24 rounded-lg border-slate-300 text-right text-sm tabular-nums dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                            </td>
                            <td class="py-2 pr-3">
                                <input type="number" step="0.01" min="0" max="100" x-model="band.max_percentage"
                                       :name="`bands[${index}][max_percentage]`" required
                                       class="w-24 rounded-lg border-slate-300 text-right text-sm tabular-nums dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                            </td>
                            <td class="py-2 pr-3">
                                <input type="number" step="0.01" min="0" max="10" x-model="band.grade_point"
                                       :name="`bands[${index}][grade_point]`" placeholder="—"
                                       class="w-20 rounded-lg border-slate-300 text-right text-sm tabular-nums dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                            </td>
                            <td class="py-2 pr-3">
                                <select x-model="band.color" :name="`bands[${index}][color]`"
                                        class="w-28 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                                    @foreach (['slate', 'rose', 'red', 'orange', 'amber', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'brand'] as $token)
                                        <option value="{{ $token }}">{{ ucfirst($token) }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="py-2 pr-3">
                                <input type="hidden" :name="`bands[${index}][is_pass]`" :value="band.is_pass ? 1 : 0">
                                <input type="checkbox" x-model="band.is_pass"
                                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                            </td>
                            <td class="py-2 text-right">
                                <button type="button" x-on:click="remove(index)" x-show="bands.length > 2"
                                        class="text-xs text-rose-500 hover:underline">Remove</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="add()">Add a band</x-ui.button>
        </div>
    </x-ui.card>
</div>
