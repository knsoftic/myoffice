{{--
    The invoice form, shared by create and edit.

    **The totals column is computed by the server**, through the same `InvoiceCalculator` that stores
    them — so what somebody watches while typing is the figure that will be saved, rather than a
    JavaScript approximation that agrees most of the time.
--}}

@php
    $existing = $invoice ?? null;
    $lines = old('lines') ?? ($existing?->items->map(fn ($item) => [
        'description' => $item->description,
        'details' => $item->details,
        'unit' => $item->unit,
        'quantity' => (string) $item->quantity,
        'unit_price' => (string) $item->unit_price,
        'discount_mode' => $item->discount_mode->value,
        'discount_rate' => $item->discount_rate,
        'discount_fixed' => $item->discount_fixed,
        'is_taxable' => (bool) $item->is_taxable,
        'tax_rate' => (string) $item->tax_rate,
    ])->values()->all() ?? []);

    if ($lines === []) {
        $lines = [[
            'description' => '', 'quantity' => '1', 'unit_price' => '',
            'discount_mode' => 'none', 'is_taxable' => $taxEnabled, 'tax_rate' => $defaultTaxRate,
        ]];
    }
@endphp

<div
    x-data="invoiceForm({
        lines: @js($lines),
        discountMode: @js(old('discount_mode', $existing?->discount_mode->value ?? 'none')),
        discountRate: @js(old('discount_rate', $existing?->discount_rate)),
        discountFixed: @js(old('discount_fixed', $existing?->discount_fixed)),
        previewUrl: @js(route('admin.invoices.totals.preview')),
        taxEnabled: @js($taxEnabled),
        defaultTaxRate: @js($defaultTaxRate),
    })"
    class="grid gap-4 lg:grid-cols-3"
>
    <div class="space-y-4 lg:col-span-2">
        <x-ui.card title="Who and when">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.form.select name="client_id" label="Client" required>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}"
                            @selected(old('client_id', $existing?->client_id) == $client->id)>
                            {{ $client->company_name ?: $client->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="project_id" label="Project" placeholder="Not against a project">
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" data-client="{{ $project->client_id }}"
                            @selected(old('project_id', $existing?->project_id) == $project->id)>
                            {{ $project->code }} — {{ $project->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.input name="title" label="Title" :value="old('title', $existing?->title)"
                                 placeholder="Phase 2 development — April 2026" />
                <x-ui.form.input name="reference" label="Client reference"
                                 :value="old('reference', $existing?->reference)" placeholder="Their PO number" />

                <x-ui.form.input type="date" name="issue_date" label="Issue date" required
                                 :value="old('issue_date', app_date($existing?->issue_date ?? now(), 'Y-m-d'))" />
                <x-ui.form.input type="date" name="due_date" label="Due date"
                                 :value="old('due_date', $existing?->due_date ? app_date($existing->due_date, 'Y-m-d') : null)"
                                 :help="'Left blank, it is ' . $paymentTermsDays . ' days after the issue date.'" />
            </div>
        </x-ui.card>

        <x-ui.card title="What is being charged for">
            <div class="space-y-3">
                <template x-for="(line, index) in lines" :key="index">
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                        <div class="grid gap-2 sm:grid-cols-12">
                            <div class="sm:col-span-5">
                                <label class="block text-xs font-medium text-slate-600 dark:text-slate-400">Description</label>
                                <input type="text" :name="`lines[${index}][description]`" x-model="line.description" required maxlength="255"
                                       class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900">
                            </div>

                            <div class="sm:col-span-2">
                                <label class="block text-xs font-medium text-slate-600 dark:text-slate-400">Quantity</label>
                                <input type="number" step="0.0001" min="0.0001" :name="`lines[${index}][quantity]`"
                                       x-model="line.quantity" x-on:input.debounce.400ms="preview()" required
                                       class="mt-1 w-full rounded-lg border-slate-300 text-sm tabular-nums dark:border-slate-700 dark:bg-slate-900">
                            </div>

                            <div class="sm:col-span-3">
                                <label class="block text-xs font-medium text-slate-600 dark:text-slate-400">Rate</label>
                                <input type="number" step="0.01" min="0" :name="`lines[${index}][unit_price]`"
                                       x-model="line.unit_price" x-on:input.debounce.400ms="preview()" required
                                       class="mt-1 w-full rounded-lg border-slate-300 text-sm tabular-nums dark:border-slate-700 dark:bg-slate-900">
                            </div>

                            <div class="flex items-end justify-between gap-2 sm:col-span-2">
                                <span class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white"
                                      x-text="lineTotal(index)"></span>
                                <button type="button" x-on:click="removeLine(index)" x-show="lines.length > 1"
                                        class="text-xs text-rose-600 hover:underline dark:text-rose-400">Remove</button>
                            </div>
                        </div>

                        <div class="mt-2 grid gap-2 sm:grid-cols-12">
                            <div class="sm:col-span-3">
                                <label class="block text-xs font-medium text-slate-600 dark:text-slate-400">Unit</label>
                                <input type="text" :name="`lines[${index}][unit]`" x-model="line.unit" maxlength="24"
                                       placeholder="hour, page, month"
                                       class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900">
                            </div>

                            <div class="sm:col-span-3">
                                <label class="block text-xs font-medium text-slate-600 dark:text-slate-400">Line discount</label>
                                <select :name="`lines[${index}][discount_mode]`" x-model="line.discount_mode"
                                        x-on:change="preview()"
                                        class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900">
                                    <option value="none">None</option>
                                    <option value="percentage">Percentage</option>
                                    <option value="fixed">Fixed amount</option>
                                </select>
                            </div>

                            <div class="sm:col-span-3" x-show="line.discount_mode === 'percentage'">
                                <label class="block text-xs font-medium text-slate-600 dark:text-slate-400">Rate %</label>
                                <input type="number" step="0.0001" min="0" max="100" :name="`lines[${index}][discount_rate]`"
                                       x-model="line.discount_rate" x-on:input.debounce.400ms="preview()"
                                       class="mt-1 w-full rounded-lg border-slate-300 text-sm tabular-nums dark:border-slate-700 dark:bg-slate-900">
                            </div>

                            <div class="sm:col-span-3" x-show="line.discount_mode === 'fixed'">
                                <label class="block text-xs font-medium text-slate-600 dark:text-slate-400">Amount off</label>
                                <input type="number" step="0.01" min="0" :name="`lines[${index}][discount_fixed]`"
                                       x-model="line.discount_fixed" x-on:input.debounce.400ms="preview()"
                                       class="mt-1 w-full rounded-lg border-slate-300 text-sm tabular-nums dark:border-slate-700 dark:bg-slate-900">
                            </div>

                            @if ($taxEnabled)
                                <div class="flex items-end gap-2 sm:col-span-3">
                                    <label class="flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-400">
                                        <input type="hidden" :name="`lines[${index}][is_taxable]`" :value="line.is_taxable ? 1 : 0">
                                        <input type="checkbox" x-model="line.is_taxable" x-on:change="preview()"
                                               class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                                        {{ $taxLabel }}
                                    </label>
                                    <input type="number" step="0.0001" min="0" max="100" :name="`lines[${index}][tax_rate]`"
                                           x-model="line.tax_rate" x-on:input.debounce.400ms="preview()" x-show="line.is_taxable"
                                           class="w-20 rounded-lg border-slate-300 text-sm tabular-nums dark:border-slate-700 dark:bg-slate-900">
                                </div>
                            @endif
                        </div>
                    </div>
                </template>

                <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="addLine()">
                    Add a line
                </x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card title="Notes">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.form.textarea name="notes" label="Notes on the invoice" rows="3"
                                    :value="old('notes', $existing?->notes)"
                                    help="The client reads this." />
                <x-ui.form.textarea name="internal_notes" label="Internal notes" rows="3"
                                    :value="old('internal_notes', $existing?->internal_notes)"
                                    help="Never rendered to a client and never in the PDF." />
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-4">
        <x-ui.card title="Discount on the whole invoice">
            <div class="space-y-3">
                <x-ui.form.select name="discount_mode" label="Mode" x-model="discountMode" x-on:change="preview()">
                    @foreach ($discountModes as $mode)
                        <option value="{{ $mode->value }}" @selected(old('discount_mode', $existing?->discount_mode->value) === $mode->value)>
                            {{ $mode->label() }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <div x-show="discountMode === 'percentage'">
                    <x-ui.form.input type="number" step="0.0001" min="0" max="100" name="discount_rate" label="Rate %"
                                     x-model="discountRate" x-on:input.debounce.400ms="preview()"
                                     :value="old('discount_rate', $existing?->discount_rate)" />
                </div>

                <div x-show="discountMode === 'fixed'">
                    <x-ui.form.input type="number" step="0.01" min="0" name="discount_fixed" label="Amount off"
                                     x-model="discountFixed" x-on:input.debounce.400ms="preview()"
                                     :value="old('discount_fixed', $existing?->discount_fixed)" />
                </div>

                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Shared across the lines in proportion to their value, with the residual on the last one — so the
                    shares add up to the discount exactly.
                </p>
            </div>
        </x-ui.card>

        <x-ui.card title="Totals" subtitle="Computed by the server, so this is the figure that will be saved.">
            <dl class="space-y-2 text-sm">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Subtotal</dt>
                    <dd class="tabular-nums text-slate-900 dark:text-white" x-text="fmt(totals.subtotal_amount)">—</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Discount</dt>
                    <dd class="tabular-nums text-slate-600 dark:text-slate-300" x-text="fmt(totalDiscount)">—</dd>
                </div>
                @if ($taxEnabled)
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">{{ $taxLabel }}</dt>
                        <dd class="tabular-nums text-slate-600 dark:text-slate-300" x-text="fmt(totals.tax_amount)">—</dd>
                    </div>
                @endif
                <template x-if="totals.round_off_amount && totals.round_off_amount !== '0.00'">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Rounding</dt>
                        <dd class="tabular-nums text-slate-600 dark:text-slate-300" x-text="fmt(totals.round_off_amount)"></dd>
                    </div>
                </template>
                <div class="flex items-baseline justify-between gap-3 border-t border-slate-200 pt-2 dark:border-slate-800">
                    <dt class="font-medium text-slate-900 dark:text-white">Total</dt>
                    <dd class="text-lg font-semibold tabular-nums text-slate-900 dark:text-white"
                        x-text="fmt(totals.total_amount)">—</dd>
                </div>
            </dl>

            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="ghost" :href="route('admin.invoices.index')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" icon="check">
                        {{ $existing ? 'Save changes' : 'Save as draft' }}
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.card>
    </div>
</div>

@push('scripts')
    <script>
        function invoiceForm(config) {
            return {
                lines: config.lines,
                discountMode: config.discountMode,
                discountRate: config.discountRate,
                discountFixed: config.discountFixed,
                totals: {},
                totalDiscount: null,
                lineResults: [],

                init() {
                    this.preview();
                },

                addLine() {
                    this.lines.push({
                        description: '', quantity: '1', unit_price: '',
                        discount_mode: 'none', is_taxable: config.taxEnabled, tax_rate: config.defaultTaxRate,
                    });
                },

                removeLine(index) {
                    this.lines.splice(index, 1);
                    this.preview();
                },

                lineTotal(index) {
                    const result = this.lineResults.find((r) => r.index === index);

                    return result ? this.fmt(result.line_total) : '—';
                },

                fmt(value) {
                    if (value === null || value === undefined) {
                        return '—';
                    }

                    return Number(value).toLocaleString(undefined, {
                        minimumFractionDigits: 2, maximumFractionDigits: 2,
                    });
                },

                // The server's own calculator, so the number being watched is the number that gets
                // stored. A local reimplementation would agree until it did not.
                async preview() {
                    try {
                        const response = await fetch(config.previewUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                            },
                            body: JSON.stringify({
                                lines: this.lines,
                                discount_mode: this.discountMode,
                                discount_rate: this.discountRate,
                                discount_fixed: this.discountFixed,
                            }),
                        });

                        if (! response.ok) {
                            return;
                        }

                        const payload = await response.json();

                        this.totals = payload.totals ?? {};
                        this.totalDiscount = payload.total_discount_amount ?? null;
                        this.lineResults = payload.lines ?? [];
                    } catch (error) {
                        // A preview that fails leaves the last good figures on screen; the save still
                        // computes them server-side, so nothing can be stored from a stale preview.
                    }
                },
            };
        }
    </script>
@endpush
