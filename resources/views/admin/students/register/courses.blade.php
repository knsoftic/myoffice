@extends('layouts.admin')

@section('title', 'Register a student — step 2')

@section('header')
    <x-ui.page-header :title="$student->name"
                      :subtitle="'Step 2 of 2 — ' . $student->student_code . ' · courses and billing'"
                      icon="academic-cap"
                      :back="route('admin.students.show', $student)" />
@endsection

@section('content')
    {{--
        Every figure here is a preview. The server recomputes all of it through `App\Support\Money`
        (bcmath) when the form is submitted, because JavaScript numbers are floats and money in this
        application never touches one. The reason the two agree is that neither the course fee nor the
        one-off fees nor the tax rate is posted: the catalogue and the settings decide them, and this
        screen only shows what they come to.

        One admission is created per ticked course (D172), because a batch, a certificate and a
        completion date all belong to one course. The bill is the basket; the records are per course.
    --}}
    <ol class="mb-4 flex items-center gap-3 text-sm">
        <li class="flex items-center gap-2 text-emerald-600 dark:text-emerald-400">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-600 text-xs text-white">
                <x-ui.icon name="check" class="h-3.5 w-3.5" />
            </span>
            Student information
        </li>
        <li aria-hidden="true" class="h-px w-8 bg-slate-200 dark:bg-slate-700"></li>
        <li class="flex items-center gap-2 font-medium text-brand-600 dark:text-brand-400">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white">2</span>
            Courses &amp; billing
        </li>
    </ol>

    <form method="POST" action="{{ route('admin.students.register.complete', $student) }}" class="space-y-4"
          x-data="{
              courses: {{ Illuminate\Support\Js::from($courses->keyBy('id')) }},
              picked: {{ Illuminate\Support\Js::from(array_values(array_map('intval', (array) old('course_ids', [])))) }},
              search: '',
              discount: {{ (float) old('discount_amount', 0) }},
              payNow: {{ (float) old('payment_amount', 0) }},
              feesOnce: {{ old('fees_once', '1') ? 'true' : 'false' }},

              extraFee: {{ (float) $fees['extra_fee'] }},
              taxRate: {{ (float) $fees['tax_rate'] }},

              get visible() {
                  const q = this.search.trim().toLowerCase();
                  return Object.values(this.courses).filter(c =>
                      q === '' || String(c.name).toLowerCase().includes(q)
                          || String(c.short_description ?? '').toLowerCase().includes(q));
              },

              toggle(id) {
                  id = Number(id);
                  const at = this.picked.indexOf(id);
                  at === -1 ? this.picked.push(id) : this.picked.splice(at, 1);
              },

              n(v) { const x = Number(v); return Number.isFinite(x) ? x : 0; },

              courseFees() { return this.picked.reduce((s, id) => s + this.n(this.courses[id]?.course_fee), 0); },

              /** One-off heads ride the first ticked course unless the operator says per course. */
              admissionFees() {
                  if (!this.picked.length) return 0;
                  return this.feesOnce
                      ? this.n(this.courses[this.picked[0]]?.admission_fee)
                      : this.picked.reduce((s, id) => s + this.n(this.courses[id]?.admission_fee), 0);
              },
              registrationFees() {
                  if (!this.picked.length) return 0;
                  return this.feesOnce
                      ? this.n(this.courses[this.picked[0]]?.registration_fee)
                      : this.picked.reduce((s, id) => s + this.n(this.courses[id]?.registration_fee), 0);
              },
              extra() { return this.picked.length ? this.extraFee : 0; },

              subtotal() { return this.courseFees() + this.admissionFees() + this.registrationFees() + this.extra(); },
              taxable() { return Math.max(0, this.subtotal() - this.n(this.discount)); },
              tax() { return this.taxRate > 0 ? this.taxable() * this.taxRate / 100 : 0; },
              total() { return this.taxable() + this.tax(); },
              balance() { return Math.max(0, this.total() - this.n(this.payNow)); },
              overpaid() { return this.n(this.payNow) > this.total(); },

              money(v) {
                  return this.n(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
              },
          }">
        @csrf
        {{-- Minted once with the form, never at submit: that is the whole point of the guard. --}}
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::ulid()) }}">

        <div class="grid gap-4 lg:grid-cols-3">
            {{-- ----------------------------------------------------------- courses --}}
            <div class="lg:col-span-2 space-y-4">
                <x-ui.card title="Courses" subtitle="Tick everything the student is taking.">
                    @error('course_ids')
                        <p class="mb-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">{{ $message }}</p>
                    @enderror

                    <label class="sr-only" for="course-search">Search courses</label>
                    <input id="course-search" type="search" x-model="search" autocomplete="off"
                           placeholder="Search courses…"
                           class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100" />

                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        <template x-for="c in visible" :key="c.id">
                            <label class="flex cursor-pointer gap-3 rounded-lg border p-3 transition hover:border-brand-400"
                                   :class="picked.includes(Number(c.id))
                                       ? 'border-brand-500 bg-brand-50/60 dark:border-brand-500 dark:bg-brand-900/20'
                                       : 'border-slate-200 dark:border-slate-700'">
                                <input type="checkbox" :value="c.id" :checked="picked.includes(Number(c.id))"
                                       x-on:change="toggle(c.id)"
                                       class="mt-1 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600" />
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-baseline justify-between gap-2">
                                        <span class="truncate text-sm font-medium text-slate-800 dark:text-slate-100" x-text="c.name"></span>
                                        <span class="shrink-0 text-sm font-semibold tabular-nums text-slate-700 dark:text-slate-200" x-text="money(c.course_fee)"></span>
                                    </span>
                                    {{-- What will be taught, in the one sentence the catalogue holds for it. --}}
                                    <span class="mt-1 block text-xs leading-relaxed text-slate-500"
                                          x-text="c.short_description || 'No description on this course yet.'"></span>
                                    <span class="mt-1 block text-xs text-slate-400"
                                          x-text="[c.code, c.level, (c.duration_value ? c.duration_value + ' ' + c.duration_unit : null)].filter(Boolean).join(' · ')"></span>
                                </span>
                            </label>
                        </template>
                    </div>

                    <p x-show="visible.length === 0" class="py-6 text-center text-sm text-slate-500">No course matches that.</p>

                    {{-- Tick order decides which course carries the one-off fees, and a browser posts
                         checkboxes in DOM order — so these hidden inputs are what actually posts. --}}
                    <template x-for="id in picked" :key="'pick-' + id">
                        <input type="hidden" name="course_ids[]" :value="id" />
                    </template>

                    <p class="mt-3 text-xs text-slate-500">
                        <span x-text="picked.length"></span> selected. Each becomes its own admission,
                        with its own batch, register and certificate.
                    </p>
                </x-ui.card>

                <x-ui.card title="Discount">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.form.input name="discount_amount" label="Discount" type="number" step="0.01" min="0"
                                         x-model="discount" :value="old('discount_amount', 0)"
                                         help="Split across the courses in proportion to each one's share." />
                        <x-ui.form.input name="discount_reason" label="Reason" :value="old('discount_reason')"
                                         help="Required when there is a discount." />
                    </div>

                    <label class="mt-4 flex items-start gap-2 text-sm">
                        <input type="hidden" name="fees_once" value="0" />
                        <input type="checkbox" name="fees_once" value="1" x-model="feesOnce"
                               class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600" />
                        <span>
                            <span class="font-medium text-slate-700 dark:text-slate-200">Charge the admission and registration fee once</span>
                            <span class="block text-xs text-slate-500">A student registers once, however many courses they take.</span>
                        </span>
                    </label>
                </x-ui.card>
            </div>

            {{-- ----------------------------------------------------------- the bill --}}
            <div class="space-y-4">
                <x-ui.card title="Bill">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Course fees (<span x-text="picked.length"></span>)</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200" x-text="money(courseFees())"></dd>
                        </div>
                        <div class="flex justify-between" x-show="admissionFees() > 0">
                            <dt class="text-slate-500">Admission fee</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200" x-text="money(admissionFees())"></dd>
                        </div>
                        <div class="flex justify-between" x-show="registrationFees() > 0">
                            <dt class="text-slate-500">Registration fee</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200" x-text="money(registrationFees())"></dd>
                        </div>
                        {{-- Only if configured: a zero setting means no line, not a line reading zero. --}}
                        <div class="flex justify-between" x-show="extra() > 0">
                            <dt class="text-slate-500">Extra fee</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200" x-text="money(extra())"></dd>
                        </div>

                        <div class="flex justify-between border-t border-slate-200 pt-2 dark:border-slate-700">
                            <dt class="font-medium text-slate-600 dark:text-slate-300">Subtotal</dt>
                            <dd class="font-medium tabular-nums text-slate-800 dark:text-slate-100" x-text="money(subtotal())"></dd>
                        </div>

                        <div class="flex justify-between" x-show="n(discount) > 0">
                            <dt class="text-slate-500">Less discount</dt>
                            <dd class="tabular-nums text-rose-600 dark:text-rose-400" x-text="'− ' + money(discount)"></dd>
                        </div>
                        <div class="flex justify-between" x-show="taxRate > 0">
                            <dt class="text-slate-500">{{ $fees['tax_label'] }} (<span x-text="taxRate"></span>%)</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200" x-text="money(tax())"></dd>
                        </div>

                        <div class="flex justify-between border-t border-slate-200 pt-2 dark:border-slate-700">
                            <dt class="font-semibold text-slate-700 dark:text-slate-200">Total payable</dt>
                            <dd class="text-lg font-semibold tabular-nums text-slate-900 dark:text-white" x-text="money(total())"></dd>
                        </div>

                        <div class="flex justify-between" x-show="n(payNow) > 0">
                            <dt class="text-slate-500">Paying now</dt>
                            <dd class="tabular-nums text-emerald-600 dark:text-emerald-400" x-text="'− ' + money(payNow)"></dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-2 dark:border-slate-700" x-show="n(payNow) > 0">
                            <dt class="font-medium text-slate-600 dark:text-slate-300">Balance</dt>
                            <dd class="font-semibold tabular-nums text-slate-800 dark:text-slate-100" x-text="money(balance())"></dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-xs text-slate-400">
                        Fees come from each course and from Settings → Institute. The stored figures are
                        recomputed on the server through the money helper — this is the same sum.
                    </p>
                </x-ui.card>

                @if ($canTakeMoney)
                    <x-ui.card title="Payment now" subtitle="Leave blank if they are paying later.">
                        <div class="space-y-4">
                            <x-ui.form.input name="payment_amount" label="Amount received" type="number" step="0.01" min="0"
                                             inputmode="decimal" x-model="payNow" :value="old('payment_amount')" />

                            <p x-show="overpaid()" x-cloak class="text-xs font-medium text-rose-600 dark:text-rose-400">
                                That is more than the bill. An advance has to be recorded from the fee screen
                                against a charge that exists — the server will refuse this.
                            </p>

                            <x-ui.form.select name="payment_method" label="How" placeholder="Choose…">
                                @foreach ($methods as $value => $label)
                                    <option value="{{ $value }}" @selected(old('payment_method') === $value)>{{ $label }}</option>
                                @endforeach
                            </x-ui.form.select>

                            <x-ui.form.input name="paid_on" label="Received on" type="date"
                                             :value="old('paid_on', now(\App\Support\Format::timezone())->toDateString())"
                                             :max="now(\App\Support\Format::timezone())->toDateString()" />

                            <x-ui.form.input name="reference_no" label="Reference" :value="old('reference_no')"
                                             help="Cheque number, transfer reference, wallet transaction." />

                            <p class="text-xs text-slate-500">
                                Split across the courses in proportion to what each one costs, so every
                                course's balance stays true. One receipt per charge it touches.
                            </p>
                        </div>
                    </x-ui.card>
                @endif

                <x-ui.card title="Counsellor &amp; date">
                    <div class="space-y-4">
                        <x-ui.form.select name="counselor_id" label="Counsellor" placeholder="Me">
                            @foreach ($counselors as $id => $name)
                                <option value="{{ $id }}" @selected((int) old('counselor_id') === (int) $id)>{{ $name }}</option>
                            @endforeach
                        </x-ui.form.select>
                        <x-ui.form.input name="admission_date" label="Admission date" type="date"
                                         :value="old('admission_date', now()->toDateString())" />
                    </div>
                </x-ui.card>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="primary" icon="check" ::disabled="picked.length === 0">
                <span x-text="picked.length > 1 ? 'Register on ' + picked.length + ' courses' : 'Complete registration'"></span>
            </x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.students.show', $student)">Finish later</x-ui.button>
        </div>
    </form>
@endsection
