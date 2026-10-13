{{--
    Collect a payment from the list — the row's "Collect payment" button, one dialog for the page.

    It posts to the same `admin.fee-payments.store` as the charge screen's dialog
    (`_payment-modal.blade.php`), with the same fields, so the one money path stays the one money
    path: the Form Request, the five service guards, the idempotency key and the commission listener
    are all the existing ones. Nothing here computes money — every figure is a string from the column.

    **One dialog, not one per row.** Each row's button sends its charge id; the dialog swaps in that
    charge's action URL, figures, installment lines and idempotency key. A dialog per row would also
    fill every row's `amount` from the same `old()` value after a refusal.

    **One idempotency key per charge, minted when the page renders**, so a double click on one charge
    carries one key and takes the money once, while two different charges never share one.

    **A refusal reopens the dialog on the charge it was about.** `collect_fee_id` rides along in the
    post (the request ignores it) so that `old()` can say which row the errors belong to; that charge
    comes back with what the cashier typed and the same key, and the "second, separate payment"
    checkbox appears only there. Opening any other row starts clean.
--}}

@props(['charges'])

@can('student_fee_payments.create')
    @php
        $bag = $errors->getBag(\App\Http\Requests\Admin\Finance\RecordFeePaymentRequest::ERROR_BAG);
        $retryId = $bag->any() ? (int) old('collect_fee_id') : 0;
        $today = now(\App\Support\Format::timezone())->toDateString();
        $cash = \App\Enums\PaymentMethod::Cash->value;

        $collectable = $charges->getCollection()
            ->filter(static fn ($charge): bool => $charge->isOpen() && $charge->relationLoaded('installments'))
            ->mapWithKeys(static function ($charge) use ($retryId, $today, $cash): array {
                $balance = (string) $charge->balance_amount;
                $retry = (int) $charge->getKey() === $retryId;

                $fields = $retry
                    ? [
                        'amount' => (string) old('amount', ''),
                        'paid_on' => (string) old('paid_on', $today),
                        'payment_method' => (string) old('payment_method', $cash),
                        'student_fee_installment_id' => (string) old('student_fee_installment_id', ''),
                        'reference_no' => (string) old('reference_no', ''),
                        'notes' => (string) old('notes', ''),
                        'idempotency_key' => (string) old('idempotency_key', (string) \Illuminate\Support\Str::ulid()),
                    ]
                    : [
                        // Suggested, never enforced — an overpayment is an advance, not an error.
                        'amount' => \App\Support\Money::isPositive($balance) ? $balance : '',
                        'paid_on' => $today,
                        'payment_method' => $cash,
                        'student_fee_installment_id' => '',
                        'reference_no' => '',
                        'notes' => '',
                        'idempotency_key' => (string) \Illuminate\Support\Str::ulid(),
                    ];

                return [$charge->getKey() => [
                    'id' => (int) $charge->getKey(),
                    'action' => route('admin.fee-payments.store', $charge),
                    'fee_number' => (string) $charge->fee_number,
                    'student' => (string) ($charge->student?->name ?? ''),
                    'head' => $charge->fee_type->label(),
                    'net' => money($charge->net_amount),
                    'balance' => money($balance),
                    'advance' => \App\Support\Money::isNegative($balance),
                    'retry' => $retry,
                    'fields' => $fields,
                    // The installment relation carries only unpaid lines (the controller loads it so).
                    // Outstanding is scheduled less allocated and waived, through Money — there is no
                    // `balance_amount` column on an installment.
                    'installments' => $charge->installments->map(static fn ($line): array => [
                        'id' => (string) $line->getKey(),
                        'label' => sprintf('#%s · due %s · %s left',
                            $line->installment_no,
                            \App\Support\Format::date($line->due_date),
                            money(\App\Support\Money::sub(
                                (string) $line->amount,
                                \App\Support\Money::add((string) $line->paid_amount, (string) $line->waived_amount),
                            )),
                        ),
                    ])->values()->all(),
                ]];
            });

        $retrying = $collectable->has($retryId);
    @endphp

    @if ($collectable->isNotEmpty())
        <x-ui.modal name="quick-collect" title="Collect payment" icon="banknotes" size="lg" :show="$retrying">
            <form method="POST" class="space-y-4"
                  x-bind:action="charge ? charge.action : ''"
                  x-data="{
                      charges: @js($collectable),
                      retryId: {{ $retrying ? $retryId : 0 }},
                      charge: null,
                      form: {},
                      init() {
                          if (this.retryId) this.load(this.retryId);
                      },
                      load(id) {
                          const charge = this.charges[id];
                          if (! charge) return;
                          this.charge = charge;
                          this.form = { ...charge.fields, student_fee_installment_id: '' };
                          // The options are drawn by x-for, so the saved pick waits for them.
                          this.$nextTick(() => { this.form.student_fee_installment_id = charge.fields.student_fee_installment_id; });
                      },
                      get needsReference() {
                          return ['bank_transfer', 'cheque', 'easypaisa', 'jazzcash', 'online_gateway'].includes(this.form.payment_method);
                      },
                  }"
                  x-on:open-modal.window="$event.detail?.name === 'quick-collect' && load($event.detail.fee)">
                @csrf
                <input type="hidden" name="idempotency_key" x-bind:value="form.idempotency_key">
                <input type="hidden" name="collect_fee_id" x-bind:value="charge ? charge.id : ''">

                <template x-if="charge">
                    <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-medium text-slate-700 dark:text-slate-200" x-text="charge.student || '—'"></div>
                                <div class="text-xs text-slate-500" x-text="charge.fee_number + ' · ' + charge.head"></div>
                            </div>
                            <span class="shrink-0 font-medium tabular-nums text-slate-700 dark:text-slate-200" x-text="charge.net"></span>
                        </div>
                        <div class="mt-2 flex items-center justify-between border-t border-slate-200 pt-2 dark:border-slate-700">
                            <span class="text-slate-500">Outstanding</span>
                            <span class="font-semibold tabular-nums text-slate-800 dark:text-slate-100" x-text="charge.balance"></span>
                        </div>
                        <p x-show="charge.advance" class="mt-1 text-xs text-emerald-600 dark:text-emerald-400">
                            This charge is already paid in advance. Anything taken now adds to the advance.
                        </p>
                    </div>
                </template>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.input name="amount" id="quick-collect-amount" label="Amount received" type="number" step="0.01" min="0.01"
                                     inputmode="decimal" required x-model="form.amount"
                                     help="Prefilled with what is outstanding. More is allowed — the surplus becomes an advance." />

                    <x-ui.form.input name="paid_on" id="quick-collect-paid-on" label="Received on" type="date" required
                                     :max="$today" x-model="form.paid_on"
                                     help="A receipt cannot be dated forward." />

                    <x-ui.form.select name="payment_method" id="quick-collect-method" label="How it was paid" required
                                      x-model="form.payment_method">
                        @foreach (\App\Enums\PaymentMethod::cases() as $method)
                            <option value="{{ $method->value }}">{{ $method->label() }}</option>
                        @endforeach
                    </x-ui.form.select>

                    {{-- Only for a charge with unpaid installment lines; otherwise the field is not in
                         the form at all and the receipt is against the charge as a whole. --}}
                    <template x-if="charge && charge.installments.length">
                        <x-ui.form.select name="student_fee_installment_id" id="quick-collect-installment"
                                          label="Against which installment" placeholder="The charge as a whole"
                                          x-model="form.student_fee_installment_id">
                            <template x-for="line in charge.installments" x-bind:key="line.id">
                                <option x-bind:value="line.id" x-text="line.label"></option>
                            </template>
                        </x-ui.form.select>
                    </template>
                </div>

                <div x-show="needsReference" x-cloak>
                    <x-ui.form.input name="reference_no" id="quick-collect-reference" label="Reference number" x-model="form.reference_no"
                                     help="The cheque number, the transfer reference, the wallet transaction — whatever the student can quote back." />
                </div>

                <x-ui.form.textarea name="notes" id="quick-collect-notes" label="Note" rows="2" x-model="form.notes" />

                @if ($retrying)
                    {{-- The refusal, and the override for it, only on the charge it was about. --}}
                    <template x-if="charge && charge.retry">
                        <div class="space-y-4">
                            <div class="rounded-lg bg-rose-50 p-3 text-sm text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">
                                <ul class="space-y-1">
                                    @foreach ($bag->all() as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </div>

                            <label class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-900/20">
                                <input type="checkbox" name="confirm_duplicate" value="1"
                                       class="mt-0.5 rounded border-amber-400 text-amber-600 focus:ring-amber-500" />
                                <span class="text-amber-800 dark:text-amber-300">
                                    <span class="font-medium">This really is a second, separate payment.</span>
                                    <span class="block text-xs">
                                        Tick only if the student has genuinely handed over this amount again today.
                                        Both receipts will stand, and both will earn commission.
                                    </span>
                                </span>
                            </label>
                        </div>
                    </template>
                @endif

                <p class="text-xs text-slate-400">
                    Recording a receipt does not notify the student — nothing is sent automatically. Print
                    the receipt for them from the register afterwards.
                </p>

                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <x-ui.button type="button" variant="ghost"
                                 x-on:click="$dispatch('close-modal', 'quick-collect')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" icon="banknotes">Record the receipt</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endcan
