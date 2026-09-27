{{--
    Collect a payment — the dialog the "Collect payment" button has been dispatching to nobody.

    `show.blade.php:19` has rendered a button firing `$dispatch('open-modal', 'collect-payment')` for
    as long as this screen has existed, and `grep -rn "collect-payment" resources/` matched exactly
    that one line. Nothing listened. Meanwhile the whole write path behind it was finished: eight
    routes, a Form Request, a DTO, five ordered guards, a unique-indexed idempotency column, a CHECK
    set, a BEFORE DELETE trigger and a passing test file. You could refund a payment and void a
    payment; you could not take one.

    It matters beyond this screen. `institute.require_fee_before_activation` defaults to
    `any_payment`, so with no way to record a receipt no student could be activated through the admin
    panel at all.

    **Every figure here is a string.** Money never touches a float (CLAUDE.md §4) — the amount is
    rendered from the column, posted as text, and compared with bcmath on the server. There is no
    arithmetic in this file, and the "what this earns" line comes from `fee-payments.preview` rather
    than from anything computed here, because that endpoint runs the real guard chain and the real
    calculator.
--}}

@props(['charge', 'installments'])

@can('student_fee_payments.create')
    @php
        $bag = $errors->getBag(\App\Http\Requests\Admin\Finance\RecordFeePaymentRequest::ERROR_BAG);

        // Payable lines only, from the collection the controller already loaded. A query here would be
        // charged to this screen's measured budget for a dropdown.
        $payableLines = $installments
            ->filter(fn ($line) => $line->status !== \App\Enums\InstallmentStatus::Paid)
            ->values();

        // Suggested, never enforced. Over-payment is a first-class state in this system: the charge
        // takes the surplus as an advance, `deriveStatus()` has an `Overpaid` arm, and the collection
        // screen has a whole tab for it. A `max` here would make that unreachable from the till.
        $suggested = (string) $charge->balance_amount;
        $suggested = \App\Support\Money::isPositive($suggested) ? $suggested : '';
    @endphp

    <x-ui.modal name="collect-payment" title="Collect payment" icon="banknotes" size="lg"
                :show="$bag->any()">
        {{--
            `:show` is load-bearing. Five service guards and all nine request rules come back as a
            redirect with an errors bag, and a modal that defaults to closed would swallow every one
            of them — the page would simply reload and the cashier would be told nothing at all.
        --}}
        <form method="POST" action="{{ route('admin.fee-payments.store', $charge) }}" class="space-y-4"
              x-data="{
                  duplicate: {{ $bag->has('amount') ? 'true' : 'false' }},
                  method: '{{ old('payment_method', \App\Enums\PaymentMethod::Cash->value) }}',
                  get needsReference() {
                      return ['bank_transfer', 'cheque', 'easypaisa', 'jazzcash', 'online_gateway'].includes(this.method);
                  },
              }">
            @csrf

            {{--
                Minted once, here, when the dialog is rendered — copied from `_discount-modal.blade.php`.
                The Form Request's own docblock says why it cannot be generated server-side at submit
                time: that would mint a fresh one per request and guard nothing. `RecordPaymentData::key()`
                substitutes a random ULID when the field is blank, so a dialog that forgets this field
                fails OPEN — it takes the money twice and writes two commission ledger rows.
            --}}
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::ulid()) }}">

            <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
                <div class="flex items-center justify-between">
                    <span class="text-slate-500">{{ $charge->fee_number }} · {{ $charge->title }}</span>
                    <span class="font-medium tabular-nums text-slate-700 dark:text-slate-200">{{ money($charge->net_amount) }}</span>
                </div>
                <div class="mt-1 flex items-center justify-between">
                    <span class="text-slate-500">Outstanding</span>
                    <span class="font-semibold tabular-nums text-slate-800 dark:text-slate-100">{{ money($charge->balance_amount) }}</span>
                </div>
                @if (\App\Support\Money::isNegative((string) $charge->balance_amount))
                    <p class="mt-1 text-xs text-emerald-600 dark:text-emerald-400">
                        This charge is already paid in advance. Anything taken now adds to the advance.
                    </p>
                @endif
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.input name="amount" label="Amount received" type="number" step="0.01" min="0.01"
                                 inputmode="decimal" required
                                 :value="$suggested"
                                 error-bag="collectPayment"
                                 help="Prefilled with what is outstanding. More is allowed — the surplus becomes an advance." />

                <x-ui.form.input name="paid_on" label="Received on" type="date" required
                                 :value="old('paid_on', now(\App\Support\Format::timezone())->toDateString())"
                                 :max="now(\App\Support\Format::timezone())->toDateString()"
                                 error-bag="collectPayment"
                                 help="A receipt cannot be dated forward. A back-dated one is read by the commission rules effective that day." />

                <x-ui.form.select name="payment_method" label="How it was paid" required
                                  x-model="method" error-bag="collectPayment">
                    @foreach (\App\Enums\PaymentMethod::cases() as $method)
                        <option value="{{ $method->value }}"
                                @selected(old('payment_method', \App\Enums\PaymentMethod::Cash->value) === $method->value)>
                            {{ $method->label() }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                @if ($payableLines->isNotEmpty())
                    <x-ui.form.select name="student_fee_installment_id" label="Against which installment"
                                      placeholder="The charge as a whole" error-bag="collectPayment">
                        @foreach ($payableLines as $line)
                            @php
                                // There is no `balance_amount` on an installment -- reading one would
                                // throw under `preventAccessingMissingAttributes`. Outstanding is what
                                // is scheduled less what has been allocated and waived, through Money.
                                $outstanding = \App\Support\Money::sub(
                                    (string) $line->amount,
                                    \App\Support\Money::add((string) $line->paid_amount, (string) $line->waived_amount),
                                );
                            @endphp
                            <option value="{{ $line->id }}"
                                    @selected((int) old('student_fee_installment_id') === (int) $line->id)>
                                #{{ $line->installment_no }} · due {{ \App\Support\Format::date($line->due_date) }} · {{ money($outstanding) }} left
                            </option>
                        @endforeach
                    </x-ui.form.select>
                @endif
            </div>

            <div x-show="needsReference" x-cloak>
                <x-ui.form.input name="reference_no" label="Reference number" :value="old('reference_no')"
                                 error-bag="collectPayment"
                                 help="The cheque number, the transfer reference, the wallet transaction — whatever the student can quote back." />
            </div>

            <x-ui.form.textarea name="notes" label="Note" rows="2" :value="old('notes')"
                                error-bag="collectPayment" />

            @if ($bag->any())
                <div class="rounded-lg bg-rose-50 p-3 text-sm text-rose-700 dark:bg-rose-900/30 dark:text-rose-300">
                    <ul class="space-y-1">
                        @foreach ($bag->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>

                {{--
                    Two-step by design, and only reachable after a refusal.
                    The service refuses a second receipt matching an earlier one on the same charge,
                    amount and day, and names the earlier receipt. Sometimes that is a double click and
                    sometimes a student really did pay the same amount twice in one day — only the
                    person at the counter knows which. Showing this from the start would turn the guard
                    into a checkbox people tick out of habit.
                --}}
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
            @endif

            <p class="text-xs text-slate-400">
                Recording a receipt does not notify the student — nothing is sent automatically. Print
                the receipt for them from the register afterwards.
            </p>

            {{--
                Inside the form, not in the modal's `footer` slot: that slot renders outside the body,
                so a submit button there is not in this form and would do nothing at all. Every other
                money modal on this page does the same.
            --}}
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                <x-ui.button type="button" variant="ghost"
                             x-on:click="$dispatch('close-modal', 'collect-payment')">Cancel</x-ui.button>
                <x-ui.button type="submit" variant="primary" icon="banknotes">Record the receipt</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
@endcan
