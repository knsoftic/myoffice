{{--
    The payment-method form, shared by create and edit (phase-13 §8.10).

    **The gateway credentials are never echoed back.** No permission reveals them — the field is empty
    on every render, and leaving it empty keeps whatever is stored. A screen that could show a stored
    secret would make encrypting it decorative.

    Expects: $codes (the PaymentMethod enum) · $types · $contexts (plain strings) · optionally $method.
--}}

@php
    $method = $method ?? null;

    $contextLabels = [
        'invoice' => 'Invoices',
        'project_payment' => 'Project payments',
        'student_fee' => 'Student fees',
        'expense' => 'Expenses',
        'income' => 'Other income',
        'payout' => 'Partner payouts',
    ];

    $selectedContexts = old('usable_for', $method?->usable_for ?? ['invoice', 'project_payment']);
    $value = static fn (string $field, $fallback = null) => old($field, $method?->{$field} ?? $fallback);
    $currentType = $value('type') instanceof \BackedEnum ? $value('type')->value : $value('type', 'bank');
    $currentCode = $value('code') instanceof \BackedEnum ? $value('code')->value : $value('code');
@endphp

<div x-data="{ type: @js($currentType), online: @js((bool) $value('is_online', false)), replaceConfig: false }"
     class="grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <x-ui.card title="The method">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.select name="code" label="Instrument" required
                                  help="Which enum value a payment row stores. It cannot be changed later without rewriting history.">
                    @foreach ($codes as $code)
                        <option value="{{ $code->value }}" @selected($currentCode === $code->value)>{{ $code->label() }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="type" label="Kind" required x-model="type">
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.input name="name" label="Name" required maxlength="100" :value="$value('name')"
                                 placeholder="Bank transfer — HBL" />

                <x-ui.form.input type="number" min="0" name="sort_order" label="Order"
                                 :value="$value('sort_order', 0)" />

                <div class="sm:col-span-2">
                    <x-ui.form.input name="description" label="Description" maxlength="255"
                                     :value="$value('description')" />
                </div>

                <div class="sm:col-span-2">
                    <x-ui.form.textarea name="instructions" label="Instructions for the payer" rows="4" maxlength="2000"
                                        :value="$value('instructions')"
                                        help="Printed on the invoice under “How to pay”. The client reads this, so write it for them." />
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="Where it may be chosen"
                   subtitle="A method nobody can choose on any form is not a method.">
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($contexts as $context)
                    <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-800">
                        <input type="checkbox" name="usable_for[]" value="{{ $context }}"
                               @checked(in_array($context, (array) $selectedContexts, true))
                               class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                        <span class="text-slate-700 dark:text-slate-200">{{ $contextLabels[$context] ?? $context }}</span>
                    </label>
                @endforeach
            </div>
        </x-ui.card>

        <div x-show="type === 'gateway'" x-cloak>
            <x-ui.card title="Gateway"
                       subtitle="No gateway is wired in this release. The driver is recorded so the switch-on is configuration rather than a migration.">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.input name="gateway_driver" label="Driver" maxlength="32"
                                     :value="$value('gateway_driver')" placeholder="stripe, payfast, easypaisa…" />

                    <x-ui.form.toggle name="is_test_mode" label="Test mode"
                                      description="Leave this on until the live keys are in place."
                                      :checked="(bool) $value('is_test_mode', true)" />

                    <div class="sm:col-span-2">
                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                            <input type="checkbox" x-model="replaceConfig"
                                   class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                            Replace the stored credentials
                        </label>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            What is stored is never shown — not to you, not to a Super Admin. Leaving this
                            alone keeps the existing keys; the activity log records that they changed, never
                            what they changed to.
                        </p>
                    </div>

                    <div x-show="replaceConfig" x-cloak class="sm:col-span-2 grid gap-4 sm:grid-cols-2">
                        <x-ui.form.input name="config[public_key]" type="password" label="Public key"
                                         autocomplete="new-password" />
                        <x-ui.form.input name="config[secret_key]" type="password" label="Secret key"
                                         autocomplete="new-password" />
                        <x-ui.form.input name="config[webhook_secret]" type="password" label="Webhook secret"
                                         autocomplete="new-password" />
                        <x-ui.form.input name="config[merchant_id]" label="Merchant id" />
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>

    <div class="space-y-4">
        <x-ui.card title="Behaviour">
            <div class="space-y-4">
                <x-ui.form.toggle name="is_active" label="Active"
                                  description="An inactive method disappears from every dropdown and changes no history."
                                  :checked="(bool) $value('is_active', true)" />

                <x-ui.form.toggle name="is_default" label="Default"
                                  description="Pre-selected on the forms. Only one method can hold it."
                                  :checked="(bool) $value('is_default', false)" />

                <x-ui.form.toggle name="requires_reference" label="Requires a reference"
                                  description="A cheque number or transfer reference is demanded on save."
                                  :checked="(bool) $value('requires_reference', false)" />

                <x-ui.form.toggle name="supports_refund" label="Supports refunds"
                                  description="Money can be sent back by the same instrument."
                                  :checked="(bool) $value('supports_refund', false)" />

                <div x-show="type === 'gateway'" x-cloak>
                    <x-ui.form.toggle name="is_online" label="Online"
                                      description="Only a gateway may be online — ticking it on anything else simply has no effect."
                                      :checked="(bool) $value('is_online', false)" />
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button type="submit" variant="primary" icon="check">
                    {{ $method?->exists ? 'Save changes' : 'Add the method' }}
                </x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.payment-methods.index')">Cancel</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
