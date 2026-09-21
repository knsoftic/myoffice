{{--
    The other-income form, shared by create and edit (phase-13 §8.9).

    The same shape as the expense form without the approval workflow: there is nothing to agree about
    money that has already arrived.

    Expects: $categories · $contexts · $methods · $paymentMethods · $projects · $clients ·
             optionally $income.
--}}

@php
    $income = $income ?? null;
    $value = static fn (string $field, $fallback = null) => old($field, $income?->{$field} ?? $fallback);
@endphp

<div class="grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <x-ui.card title="What came in">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.select name="finance_category_id" label="Category" required placeholder="Choose a category">
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((int) $value('finance_category_id') === $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="context" label="Which business" required>
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
                                     placeholder="Equipment sale, supplier credit, rented desk…" />
                </div>

                <x-ui.form.input name="received_from" label="Received from" maxlength="150"
                                 :value="$value('received_from')" />

                <x-ui.form.select name="client_id" label="Client" placeholder="Not a client">
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((int) $value('client_id') === $client->id)>
                            {{ $client->company_name ?: $client->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="project_id" label="Project" placeholder="Not project-specific">
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

        <x-ui.card title="How it arrived">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.select name="payment_method" label="Instrument" required>
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

                <x-ui.form.input name="reference_no" label="Reference" maxlength="64" :value="$value('reference_no')" />

                <x-ui.form.input type="date" name="received_on" label="Received on" required
                                 :value="old('received_on', $income?->received_on?->toDateString() ?? app_date(now(), 'Y-m-d'))"
                                 help="When the money arrived, not when it was typed in." />
            </div>
        </x-ui.card>

        <x-ui.card title="Evidence">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.file name="receipt" label="Receipt"
                                accept=".pdf,.jpg,.jpeg,.png,.webp"
                                hint="PDF, JPG, PNG or WEBP up to 8 MB"
                                :current="$income?->receipt_path ? route('admin.income.receipt', $income) : null" />

                <x-ui.form.input name="notes" label="Note" maxlength="255" :value="$value('notes')" />
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-4">
        <x-ui.card title="Amount">
            <x-ui.form.input type="number" step="0.01" min="0.01" name="amount" label="Amount" required
                             :value="$value('amount')" :prefix="setting('localization.currency_symbol', 'Rs')" />

            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                It counts in the income report from the moment it is saved. If it later goes back, record
                a refund against it rather than editing this figure.
            </p>
        </x-ui.card>

        <x-ui.card>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button type="submit" variant="primary" icon="check">
                    {{ $income?->exists ? 'Save changes' : 'Record the income' }}
                </x-ui.button>
                <x-ui.button variant="ghost"
                             :href="$income?->exists ? route('admin.income.show', $income) : route('admin.income.index')">
                    Cancel
                </x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
