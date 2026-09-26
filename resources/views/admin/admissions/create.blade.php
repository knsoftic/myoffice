@extends('layouts.admin')

@section('title', 'New admission')

@section('header')
    <x-ui.page-header title="New admission"
                      subtitle="Tick every course the student is taking. Each one becomes its own admission; the figures below are the one total you quote."
                      icon="user-plus"
                      :back="route('admin.admissions.index')" />
@endsection

@section('content')
    {{--
        One student, one or more courses, one agreed figure (D172).

        The basket totals here; the database does not. Each ticked course becomes its own admission,
        because everything downstream of one is per course — a batch belongs to exactly one course, an
        admission holds one `batch_id` and one `completed_on`, and a certificate is drafted from one
        enrolment. So this screen's job is to make three admissions feel like one conversation, and
        `net()` below is the number read out to the student.

        The server recomputes every figure through `App\Support\Money` (bcmath). Alpine here is a
        preview and nothing else: JavaScript numbers are floats, and a float is the one thing money in
        this application never touches.
    --}}
    <form method="POST" action="{{ route('admin.admissions.store') }}" class="space-y-4"
          x-data="{
              courses: {{ Illuminate\Support\Js::from($courses->keyBy('id')) }},
              picked: {{ Illuminate\Support\Js::from(array_values(array_map('intval', (array) old('course_ids', [])))) }},
              lines: {{ Illuminate\Support\Js::from((array) old('lines', [])) }},
              search: '',
              discount: {{ (float) old('discount_amount', 0) }},
              scholarship: {{ (float) old('scholarship_amount', 0) }},
              feesOnce: {{ old('fees_once', '1') ? 'true' : 'false' }},

              get visible() {
                  const q = this.search.trim().toLowerCase();
                  return Object.values(this.courses).filter(c =>
                      q === '' || String(c.name).toLowerCase().includes(q)
                          || String(c.category_name ?? '').toLowerCase().includes(q));
              },

              toggle(id) {
                  id = Number(id);
                  const at = this.picked.indexOf(id);
                  // Order is load-bearing: the basket's one-off admission and registration fees land
                  // on the FIRST ticked course, both here and in the service.
                  at === -1 ? this.picked.push(id) : this.picked.splice(at, 1);
              },

              /**
               * What goes IN the box: the operator's own keystrokes, verbatim, else the catalogue price.
               *
               * Verbatim matters. Coercing through Number() here would rewrite the field mid-edit --
               * type '1500.' and Number() gives 1500, so the binding deletes the dot that was just
               * pressed. Only num() coerces, and only for arithmetic.
               */
              val(id, field) {
                  const typed = this.lines?.[id]?.[field];
                  if (typed !== undefined && typed !== null && typed !== '') return typed;
                  return this.courses[id]?.[field] ?? 0;
              },

              num(id, field) {
                  const n = Number(this.val(id, field));
                  return Number.isFinite(n) ? n : 0;
              },

              /** Charged once for the basket unless the operator says otherwise. */
              oneOff(id, field) {
                  if (this.feesOnce && this.picked.indexOf(Number(id)) > 0) return 0;
                  return this.val(id, field);
              },

              oneOffNum(id, field) {
                  if (this.feesOnce && this.picked.indexOf(Number(id)) > 0) return 0;
                  return this.num(id, field);
              },

              lineTotal(id) {
                  return this.num(id, 'course_fee')
                      + this.oneOffNum(id, 'admission_fee')
                      + this.oneOffNum(id, 'registration_fee');
              },

              total() { return this.picked.reduce((sum, id) => sum + this.lineTotal(id), 0); },
              reductions() { return Number(this.discount || 0) + Number(this.scholarship || 0); },
              net() { return Math.max(0, this.total() - this.reductions()); },
              over() { return this.reductions() > this.total(); },

              /** What this course's share of the basket discount will be stored as, pro-rata. */
              share(id, amount) {
                  const t = this.total();
                  if (t <= 0) return 0;
                  return Number(amount || 0) * this.lineTotal(id) / t;
              },

              money(n) {
                  return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
              },
          }">
        @csrf

        <x-ui.card title="Student">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.select name="student_id" label="Student" required>
                    @foreach ($students as $student)
                        <option value="{{ $student->id }}"
                                @selected((int) old('student_id', $selectedStudent?->id) === (int) $student->id)>
                            {{ $student->name }} ({{ $student->student_code }}) — {{ $student->phone }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.input name="admission_date" label="Admission date" type="date"
                                 :value="old('admission_date', now()->toDateString())" />

                <x-ui.form.select name="counselor_id" label="Counsellor" placeholder="Me">
                    @foreach ($counselors as $id => $name)
                        <option value="{{ $id }}" @selected((int) old('counselor_id') === (int) $id)>{{ $name }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="delivery_mode" label="Agreed mode" placeholder="As the course is taught">
                    @foreach ($modes as $value => $label)
                        <option value="{{ $value }}" @selected(old('delivery_mode') === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="preferred_timing" label="Preferred timing" placeholder="Not stated">
                    @foreach ($timings as $value => $label)
                        <option value="{{ $value }}" @selected(old('preferred_timing') === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.form.select>
            </div>
        </x-ui.card>

        <x-ui.card title="Courses"
                   subtitle="Every ticked course becomes its own admission, with its own number, batch, register and certificate.">
            @error('course_ids')
                <p class="mb-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">
                    {{ $message }}
                </p>
            @enderror
            @error('lines')
                <p class="mb-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">
                    {{ $message }}
                </p>
            @enderror

            <label class="sr-only" for="course-search">Search courses</label>
            <input id="course-search" type="search" x-model="search" autocomplete="off"
                   placeholder="Search by course or category…"
                   class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100" />

            <div class="mt-3 max-h-72 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-700">
                <template x-for="c in visible" :key="c.id">
                    <label class="flex cursor-pointer items-center gap-3 border-b border-slate-100 px-3 py-2 last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/60">
                        {{-- Deliberately unnamed: see the hidden inputs below for why. --}}
                        <input type="checkbox" :value="c.id"
                               :checked="picked.includes(Number(c.id))"
                               x-on:change="toggle(c.id)"
                               class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-slate-600" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-100" x-text="c.name"></span>
                            <span class="block truncate text-xs text-slate-500" x-text="c.category_name ?? 'Uncategorised'"></span>
                        </span>
                        <span class="shrink-0 text-sm tabular-nums text-slate-500" x-text="money(c.course_fee)"></span>
                    </label>
                </template>

                <p x-show="visible.length === 0" class="px-3 py-6 text-center text-sm text-slate-500">
                    No course matches that.
                </p>
            </div>

            {{--
                What actually posts, and in tick order.

                The checkboxes above carry no `name` on purpose. A browser submits checkboxes in DOM
                order -- here, alphabetically by category -- while the server reads the ORDER of
                `course_ids` to decide which line carries the basket's one-off admission and
                registration fees. Posting the checkboxes would mean the course the operator ticked
                first and the course the server charged those fees to were different courses, the
                preview on this screen would disagree with what was stored, and nothing anywhere
                would say so. These hidden inputs are rendered from `picked`, so the two agree by
                construction.
            --}}
            <template x-for="id in picked" :key="'pick-' + id">
                <input type="hidden" name="course_ids[]" :value="id" />
            </template>

            <p class="mt-2 text-xs text-slate-500">
                <span x-text="picked.length"></span> selected.
                The first one ticked carries the admission and registration fee for the basket.
            </p>
        </x-ui.card>

        <x-ui.card title="Agreed figures"
                   subtitle="Snapshotted per course. Changing the catalogue price later moves what the next student is quoted, and nothing already sold.">
            <div x-show="picked.length === 0" class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500 dark:bg-slate-800/60">
                Tick a course above and its fees appear here, prefilled and negotiable.
            </div>

            <div x-show="picked.length > 0" class="-mx-4 overflow-x-auto sm:mx-0">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                            <th class="px-3 py-2 font-medium">Course</th>
                            <th class="px-3 py-2 font-medium">Course fee</th>
                            <th class="px-3 py-2 font-medium">Admission fee</th>
                            <th class="px-3 py-2 font-medium">Registration fee</th>
                            <th class="px-3 py-2 font-medium">Monthly fee</th>
                            <th class="px-3 py-2 text-right font-medium">Line total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <template x-for="(id, i) in picked" :key="id">
                            <tr>
                                <td class="px-3 py-2">
                                    <span class="block font-medium text-slate-800 dark:text-slate-100" x-text="courses[id]?.name"></span>
                                    <span class="block text-xs text-slate-400" x-text="i === 0 ? 'carries the one-off fees' : ''"></span>
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" step="0.01" min="0"
                                           :name="'lines[' + id + '][course_fee]'"
                                           :aria-label="'Course fee for ' + (courses[id]?.name ?? 'this course')"
                                           :value="val(id, 'course_fee')"
                                           x-on:input="lines[id] = { ...(lines[id] ?? {}), course_fee: $event.target.value }"
                                           class="w-28 rounded-lg border-slate-300 text-sm tabular-nums shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100" />
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" step="0.01" min="0"
                                           :name="'lines[' + id + '][admission_fee]'"
                                           :aria-label="'Admission fee for ' + (courses[id]?.name ?? 'this course')"
                                           :value="oneOff(id, 'admission_fee')"
                                           :disabled="feesOnce && i > 0"
                                           x-on:input="lines[id] = { ...(lines[id] ?? {}), admission_fee: $event.target.value }"
                                           class="w-28 rounded-lg border-slate-300 text-sm tabular-nums shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-slate-100 disabled:text-slate-400 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 dark:disabled:bg-slate-800" />
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" step="0.01" min="0"
                                           :name="'lines[' + id + '][registration_fee]'"
                                           :aria-label="'Registration fee for ' + (courses[id]?.name ?? 'this course')"
                                           :value="oneOff(id, 'registration_fee')"
                                           :disabled="feesOnce && i > 0"
                                           x-on:input="lines[id] = { ...(lines[id] ?? {}), registration_fee: $event.target.value }"
                                           class="w-28 rounded-lg border-slate-300 text-sm tabular-nums shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-slate-100 disabled:text-slate-400 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 dark:disabled:bg-slate-800" />
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" step="0.01" min="0"
                                           :name="'lines[' + id + '][monthly_fee]'"
                                           :aria-label="'Monthly fee for ' + (courses[id]?.name ?? 'this course')"
                                           :value="val(id, 'monthly_fee')"
                                           x-on:input="lines[id] = { ...(lines[id] ?? {}), monthly_fee: $event.target.value }"
                                           class="w-28 rounded-lg border-slate-300 text-sm tabular-nums shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100" />
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <span class="font-medium tabular-nums text-slate-800 dark:text-slate-100" x-text="money(lineTotal(id))"></span>
                                    <span class="block text-xs text-slate-400"
                                          x-show="reductions() > 0"
                                          x-text="'− ' + money(share(id, discount) + share(id, scholarship)) + ' share'"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <label class="mt-4 flex items-start gap-2 text-sm">
                {{-- An unchecked box posts nothing, so the hidden 0 is what makes unticking mean false. --}}
                <input type="hidden" name="fees_once" value="0" />
                <input type="checkbox" name="fees_once" value="1" x-model="feesOnce"
                       class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-slate-600" />
                <span>
                    <span class="font-medium text-slate-700 dark:text-slate-200">Charge the admission and registration fee once for the whole basket</span>
                    <span class="block text-xs text-slate-500">
                        They are per-student in practice — a student registers once, however many courses they take.
                        Untick this to charge them per course instead.
                    </span>
                </span>
            </label>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <x-ui.form.input name="discount_amount" label="Discount (whole basket)" type="number" step="0.01"
                                 x-model="discount" :value="old('discount_amount', 0)" />
                <x-ui.form.input name="scholarship_amount" label="Scholarship (whole basket)" type="number" step="0.01"
                                 x-model="scholarship" :value="old('scholarship_amount', 0)" />
                <x-ui.form.input name="discount_reason" label="Reason for the discount" :value="old('discount_reason')"
                                 help="The answer to «why is this student paying less than that one»." />
            </div>

            <p class="mt-2 text-xs text-slate-500">
                Agreed on the basket and stored per course, split by each line's share of the total —
                so the shares add back to exactly what you typed.
            </p>

            <div class="mt-4 rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-slate-500">Total charged</span>
                    <span class="tabular-nums text-slate-700 dark:text-slate-200" x-text="money(total())"></span>
                </div>
                <div class="mt-1 flex items-center justify-between text-sm" x-show="reductions() > 0">
                    <span class="text-slate-500">Less discount and scholarship</span>
                    <span class="tabular-nums text-slate-700 dark:text-slate-200" x-text="'− ' + money(reductions())"></span>
                </div>
                <div class="mt-2 flex items-center justify-between border-t border-slate-200 pt-2 text-sm dark:border-slate-700">
                    <span class="text-slate-500">Net payable (live preview)</span>
                    <span class="text-lg font-semibold tabular-nums text-slate-800 dark:text-slate-100" x-text="money(net())"></span>
                </div>

                <p x-show="over()" class="mt-2 text-xs font-medium text-rose-600 dark:text-rose-400">
                    The discount and scholarship come to more than this basket charges. Nothing is sold below free —
                    the server will refuse this.
                </p>

                <p class="mt-1 text-xs text-slate-400">
                    The figures that are stored are computed on the server through the money helper — this is
                    the same sum, shown before you submit.
                </p>
            </div>
        </x-ui.card>

        <x-ui.card title="Notes">
            <x-ui.form.textarea name="notes" label="Notes" rows="3" :value="old('notes')" />
        </x-ui.card>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="primary" icon="check" ::disabled="picked.length === 0">
                <span x-text="picked.length > 1 ? 'Start ' + picked.length + ' admissions' : 'Start the admission'"></span>
            </x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.admissions.index')">Cancel</x-ui.button>
        </div>
    </form>
@endsection
