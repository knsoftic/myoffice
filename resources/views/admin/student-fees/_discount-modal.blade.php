{{--
    The discount modal (phase-18 §8.5).

    **Amount or percentage, never both.** The two inputs clear each other, because a percentage
    discount stores the percentage AND the amount it worked out to — accepting both would let the
    pair disagree before anything else got a look at them, and the row is append-only.

    The live preview is the point of the dialog: "gross − discount − scholarship = net" with the
    number that will actually be written, so nobody approves a figure they have not seen.
--}}
<x-ui.modal name="add-discount" title="Reduce this fee" icon="receipt-percent" size="lg">
    <form method="POST" action="{{ route('admin.student-fees.discounts.store', $charge) }}" class="space-y-4"
          x-data="{
              mode: 'amount',
              amount: '',
              percentage: '',
              gross: {{ (float) $charge->gross_amount }},
              currentDiscount: {{ (float) $charge->discount_amount }},
              currentScholarship: {{ (float) $charge->scholarship_amount }},
              type: 'fixed_discount',
              get magnitude() {
                  return this.mode === 'amount'
                      ? (parseFloat(this.amount) || 0)
                      : Math.round(this.gross * (parseFloat(this.percentage) || 0)) / 100;
              },
              get isScholarship() { return this.type === 'scholarship'; },
              get newNet() {
                  const d = this.currentDiscount + (this.isScholarship ? 0 : this.magnitude);
                  const s = this.currentScholarship + (this.isScholarship ? this.magnitude : 0);
                  return this.gross - d - s;
              },
              get overshoots() { return this.newNet < 0; },
          }">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::ulid() }}">

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.form.select name="type" label="Type" x-model="type">
                @foreach (\App\Enums\FeeDiscountType::cases() as $type)
                    @continue(in_array($type, [\App\Enums\FeeDiscountType::Waiver, \App\Enums\FeeDiscountType::Reversal], true))
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="approved_by" label="Approved by"
                              :placeholder="(bool) setting('institute.discount_approval_required', true) ? null : 'Nobody in particular'"
                              :required="(bool) setting('institute.discount_approval_required', true)">
                @foreach ($approvers ?? [] as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-ui.form.select>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.form.input name="amount" label="Amount" type="number" step="0.01" min="0.01"
                             x-model="amount" x-on:input="mode = 'amount'; percentage = ''" />
            <x-ui.form.input name="percentage" label="or percentage of gross" type="number" step="0.0001" min="0.0001"
                             :max="setting('institute.discount_max_percentage', '100.0000')"
                             x-model="percentage" x-on:input="mode = 'percentage'; amount = ''" />
        </div>

        <x-ui.form.input name="effective_on" label="Effective from" type="date"
                         :value="now()->toDateString()" :max="now()->toDateString()"
                         help="A discount takes effect on a date that has arrived — a future one would change a net fee that payments have already been measured against." />

        <x-ui.form.textarea name="reason" label="Why?" required rows="2" />

        <div class="rounded-lg border border-slate-200/70 bg-slate-50/60 px-4 py-3 text-sm dark:border-slate-800 dark:bg-slate-900/40">
            <span class="text-slate-400">Gross</span>
            <span class="tabular-nums text-slate-600 dark:text-slate-300">{{ money($charge->gross_amount) }}</span>
            <span class="text-slate-400">→ net</span>
            <span class="tabular-nums font-medium" :class="overshoots ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'"
                  x-text="newNet.toFixed(2)"></span>
            <p class="mt-1 text-xs text-rose-600 dark:text-rose-400" x-show="overshoots" x-cloak>
                That is more than the fee. A charge cannot be reduced below zero — if the student owes
                nothing, the remaining heads are what to reduce.
            </p>
            @if ($charge->has_installment_plan)
                <p class="mt-1 text-xs text-slate-500">
                    This charge has a live plan, so the reduction comes off the <span class="font-medium">latest
                    unpaid installments first</span> — the far end of the schedule, not the payment already arranged.
                </p>
            @endif
        </div>

        <div class="flex justify-end gap-2">
            <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'add-discount')">Cancel</x-ui.button>
            <x-ui.button type="submit" variant="primary" ::disabled="overshoots">Record it</x-ui.button>
        </div>
    </form>
</x-ui.modal>
