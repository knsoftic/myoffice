{{--
    The installment plan wizard (phase-18 §8.4, §77).

    **The preview comes from the server, through the same calculator the submit uses.** Computing the
    lines in JavaScript would be a second implementation of the one piece of arithmetic in this phase
    that has to be exact to the paisa — and the two would agree right up until somebody changed one.

    The banner is the plan's own invariant on screen: the lines sum to the net fee, or the submit
    button is disabled. The Form Request asserts it again and `StudentFeeService` asserts PI-1 a third
    time inside the transaction. Three statements of one rule, on purpose — the screen's is a courtesy,
    the other two are guards.
--}}
@php($planRoute = $isRebuild ? route('admin.student-fees.installments.rebuild', $charge) : route('admin.student-fees.installments.store', $charge))
@php($defaultDue = ($charge->due_date ?? now()->addDays((int) setting('institute.fee_due_days', 7)))->toDateString())
@php($previewUrl = route('admin.installments.preview', $charge))

<x-ui.modal :name="$isRebuild ? 'rebuild-plan' : 'build-plan'"
            :title="$isRebuild ? 'Rebuild the installment plan' : 'Build an installment plan'"
            icon="calendar-days" size="xl">
    <div x-data="feePlanWizard({
            previewUrl: @js($previewUrl),
            firstDueOn: @js($defaultDue),
            placement: @js(setting('institute.installment_remainder_placement', 'last')),
            net: @js((string) $charge->net_amount),
         })" x-init="preview()">

        <form method="POST" action="{{ $planRoute }}" class="space-y-4">
            @csrf
            @if ($isRebuild)
                @method('PUT')
            @endif

            <div class="grid gap-4 sm:grid-cols-4">
                <x-ui.form.input name="_count" label="Installments" type="number" min="2" max="60"
                                 x-model.number="count" x-on:change="preview()" />

                <x-ui.form.select name="_interval" label="Every" x-model="interval" x-on:change="preview()">
                    @foreach (\App\Enums\InstallmentInterval::cases() as $interval)
                        @continue($interval === \App\Enums\InstallmentInterval::Custom)
                        <option value="{{ $interval->value }}">{{ $interval->label() }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.input name="_first_due_on" label="First due on" type="date"
                                 x-model="firstDueOn" x-on:change="preview()" />

                <x-ui.form.select name="_placement" label="Odd paisa go to" x-model="placement" x-on:change="preview()">
                    <option value="last">The last installment</option>
                    <option value="first">The first installment</option>
                </x-ui.form.select>
            </div>

            @if ($isRebuild)
                <x-ui.form.textarea name="reason" label="Why rebuild?" required rows="2"
                                    help="Rebuilding cancels the current unpaid installments. Lines that hold money keep their amounts and their numbers, and the new ones continue from where the old plan left off — a number is never reused, so the third installment keeps meaning one thing." />
            @endif

            <div class="overflow-x-auto rounded-lg border border-slate-200/70 dark:border-slate-800">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-900/60 dark:text-slate-400">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">#</th>
                            <th class="px-3 py-2 text-left font-semibold">Due date</th>
                            <th class="px-3 py-2 text-right font-semibold">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200/70 dark:divide-slate-800">
                        <template x-for="(line, index) in lines" :key="index">
                            <tr>
                                <td class="px-3 py-2 text-slate-500" x-text="line.installment_no"></td>
                                <td class="px-3 py-2">
                                    <input type="date" class="w-full rounded border-slate-200 bg-transparent px-2 py-1 text-sm dark:border-slate-700"
                                           :name="'lines[' + index + '][due_date]'" x-model="line.due_date">
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <input type="number" step="0.01" min="0.01"
                                           class="w-32 rounded border-slate-200 bg-transparent px-2 py-1 text-right text-sm tabular-nums dark:border-slate-700"
                                           :name="'lines[' + index + '][amount]'" x-model="line.amount">
                                    <input type="hidden" :name="'lines[' + index + '][installment_no]'" :value="line.installment_no">
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="rounded-lg px-4 py-3 text-sm"
                 :class="balances
                     ? 'bg-emerald-50/60 text-emerald-800 dark:bg-emerald-500/5 dark:text-emerald-300'
                     : 'bg-rose-50/60 text-rose-800 dark:bg-rose-500/5 dark:text-rose-300'">
                <span>Total</span>
                <span class="tabular-nums font-medium" x-text="total.toFixed(2)"></span>
                <span x-text="balances ? '=' : '≠'"></span>
                <span>net fee</span>
                <span class="tabular-nums font-medium" x-text="parseFloat(net).toFixed(2)"></span>
                <span x-show="! balances" x-cloak>
                    — a difference of <span class="tabular-nums" x-text="Math.abs(total - parseFloat(net)).toFixed(2)"></span>.
                    A plan that does not sum to the fee leaves a charge that can never reach paid.
                </span>
            </div>

            <p class="text-xs text-rose-600 dark:text-rose-400" x-show="error" x-cloak x-text="error"></p>

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="ghost"
                             x-on:click="$dispatch('close-modal', '{{ $isRebuild ? 'rebuild-plan' : 'build-plan' }}')">Cancel</x-ui.button>
                <x-ui.button type="submit" variant="primary" ::disabled="! balances || lines.length === 0">
                    {{ $isRebuild ? 'Rebuild the plan' : 'Create the plan' }}
                </x-ui.button>
            </div>
        </form>
    </div>
</x-ui.modal>

@once
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            // Registered once per page rather than inlined per modal: the rebuild and build dialogs
            // are the same wizard and there is no version of this that should differ between them.
            window.feePlanWizard = (config) => ({
                count: 3,
                interval: 'monthly',
                firstDueOn: config.firstDueOn,
                placement: config.placement,
                net: config.net,
                lines: [],
                loading: false,
                error: null,

                get total() {
                    return this.lines.reduce((sum, line) => sum + (parseFloat(line.amount) || 0), 0);
                },

                // Half a paisa, because the inputs are decimal strings and an exact float comparison
                // would call 3333.33 + 3333.33 + 3333.34 unbalanced.
                get balances() {
                    return Math.abs(this.total - parseFloat(this.net)) < 0.005;
                },

                async preview() {
                    this.loading = true;
                    this.error = null;

                    try {
                        const url = new URL(config.previewUrl, window.location.origin);
                        url.searchParams.set('count', this.count);
                        url.searchParams.set('interval', this.interval);
                        url.searchParams.set('first_due_on', this.firstDueOn);
                        url.searchParams.set('placement', this.placement);

                        const response = await fetch(url, { headers: { Accept: 'application/json' } });
                        const body = await response.json();

                        if (! response.ok) {
                            // The server's sentence, not a generic one: it names the two numbers that
                            // disagree, which is what the person is being asked to fix.
                            this.error = Object.values(body.errors ?? { m: [body.message ?? 'That plan was refused.'] })
                                .flat().join(' ');
                            this.lines = [];

                            return;
                        }

                        this.lines = body.lines;
                        this.net = body.net;
                    } catch (e) {
                        this.error = 'The preview could not be loaded.';
                    } finally {
                        this.loading = false;
                    }
                },
            });
        </script>
    @endpush
@endonce
