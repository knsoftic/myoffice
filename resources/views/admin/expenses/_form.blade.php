{{--
    The expense form, shared by create and edit (phase-13 §8.7).

    Expects: $categories · $contexts · $methods (configured options) · $paymentMethods (the enum,
    which is what the row stores) · $projects · $receiptRequired · $receiptThreshold ·
    optionally $expense.
--}}

@php
    $expense = $expense ?? null;

    $approvalRequired = (bool) setting('finance.expense_approval_required', true);
    $approvalThreshold = (string) setting('finance.expense_approval_threshold', '0.00');
    $thresholdApplies = bccomp($approvalThreshold, '0.00', 2) === 1;

    $value = static fn (string $field, $fallback = null) => old($field, $expense?->{$field} ?? $fallback);
@endphp

<div class="grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <x-ui.card title="What was spent">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.select name="finance_category_id" label="Category" required
                                  placeholder="Choose a category">
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}"
                                @selected((int) $value('finance_category_id') === $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="context" label="Which business" required
                                  help="What this cost belongs to. The reports split on it.">
                    @foreach ($contexts as $context)
                        <option value="{{ $context->value }}"
                                @selected(($value('context') instanceof \BackedEnum ? $value('context')->value : $value('context', 'software_house')) === $context->value)>
                            {{ $context->label() }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <div class="sm:col-span-2">
                    <x-ui.form.input name="title" label="What it was for" required maxlength="150"
                                     :value="$value('title')"
                                     placeholder="Domain renewal, office electricity, contractor invoice…" />
                </div>

                <x-ui.form.input name="paid_to" label="Paid to" maxlength="150"
                                 :value="$value('paid_to')" placeholder="The supplier or person" />

                <x-ui.form.select name="project_id" label="Project" placeholder="Not project-specific"
                                  help="Only for a cost the software house can attribute to one job.">
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected((int) $value('project_id') === $project->id)>
                            {{ $project->code }} — {{ $project->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <div class="sm:col-span-2">
                    <x-ui.form.textarea name="description" label="Description" rows="3" maxlength="5000"
                                        :value="$value('description')" />
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="How it was paid">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.select name="payment_method" label="Instrument" required
                                  help="Stored on the row as a snapshot; renaming a method later changes no history.">
                    @foreach ($paymentMethods as $method)
                        <option value="{{ $method->value }}"
                                @selected(($value('payment_method') instanceof \BackedEnum ? $value('payment_method')->value : $value('payment_method', 'cash')) === $method->value)>
                            {{ $method->label() }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="payment_method_id" label="Configured method" placeholder="None">
                    @foreach ($methods as $option)
                        <option value="{{ $option->id }}" @selected((int) $value('payment_method_id') === $option->id)>
                            {{ $option->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.input name="reference_no" label="Reference" maxlength="64"
                                 :value="$value('reference_no')"
                                 help="Cheque number, transfer reference — whatever proves it left the account." />

                <x-ui.form.input type="date" name="expense_date" label="Date of the cost" required
                                 :value="old('expense_date', $expense?->expense_date?->toDateString() ?? app_date(now(), 'Y-m-d'))"
                                 help="When the money went, not when it was typed in." />
            </div>
        </x-ui.card>

        <x-ui.card title="Evidence"
                   subtitle="The receipt lands on the private disk and is only ever served by a route that re-runs the permission chain.">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.file name="receipt" label="Receipt"
                                accept=".pdf,.jpg,.jpeg,.png,.webp"
                                hint="PDF, JPG, PNG or WEBP up to 8 MB"
                                :required="$receiptRequired && $expense === null"
                                :current="$expense?->receipt_path ? route('admin.expenses.receipt', $expense) : null" />

                <x-ui.form.input name="notes" label="Note" maxlength="255" :value="$value('notes')" />
            </div>

            @if ($receiptRequired)
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    A receipt is required
                    @if (bccomp((string) $receiptThreshold, '0.00', 2) === 1)
                        above {{ money($receiptThreshold) }}.
                    @else
                        for every expense.
                    @endif
                </p>
            @endif
        </x-ui.card>
    </div>

    <div class="space-y-4">
        <x-ui.card title="Amount">
            <x-ui.form.input type="number" step="0.01" min="0.01" name="amount" label="Amount" required
                             :value="$value('amount')" :prefix="setting('localization.currency_symbol', 'Rs')" />

            <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
                @if (! $approvalRequired)
                    <p class="font-semibold text-slate-900 dark:text-white">This will be approved straight away.</p>
                    <p class="mt-1">Approval is switched off in the finance settings, so the cost counts in the reports from the moment it is saved.</p>
                @elseif ($thresholdApplies)
                    <p class="font-semibold text-slate-900 dark:text-white">Approval kicks in above {{ money($approvalThreshold) }}.</p>
                    <p class="mt-1">Below that it is approved on save. Above it the claim waits, and nothing counts in a report until somebody agrees it.</p>
                @else
                    <p class="font-semibold text-slate-900 dark:text-white">This will wait for approval.</p>
                    <p class="mt-1">Every expense is approved by somebody, and nothing counts in a report until then.</p>
                @endif

                @unless ((bool) setting('finance.expense_self_approval_allowed', false))
                    <p class="mt-2">You cannot approve your own claim.</p>
                @endunless
            </div>
        </x-ui.card>

        @if ($expense?->exists && $expense->status !== \App\Enums\ExpenseStatus::Pending)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
                This expense is already {{ strtolower($expense->status->label()) }}. Editing it changes a
                figure somebody has already agreed to — the change is recorded with your name on it.
            </div>
        @endif

        <x-ui.card>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button type="submit" variant="primary" icon="check">
                    {{ $expense?->exists ? 'Save changes' : 'Record the expense' }}
                </x-ui.button>
                <x-ui.button variant="ghost"
                             :href="$expense?->exists ? route('admin.expenses.show', $expense) : route('admin.expenses.index')">
                    Cancel
                </x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
