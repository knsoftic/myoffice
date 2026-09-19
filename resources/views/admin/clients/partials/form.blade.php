{{--
    Shared client form — admin/clients/create and admin/clients/edit (phase-05 §2.7, §6.7, §19).

    Expects:
      $client                    App\Models\Crm\Client  the row, or a fresh instance on create
      $clientTypeOptions         array<string, string>  ClientType::options()
      $sourceOptions             array<string, string>  InquirySource::options()
      $accountManagerOptions     array<int, string>     create only, and only when the actor holds clients.assign ([] otherwise)
      $defaultCurrency           ?string  localization.currency — the placeholder when the client has none
      $defaultPaymentTermsDays   ?int     finance.payment_terms_days
      $defaultTaxRate            ?string  finance.default_tax_rate

    Fields posted (StoreClientRequest / UpdateClientRequest): client_type, name, company_name, industry, website, about,
    email, phone, whatsapp, address, city, state, postal_code, country, country_code,
    billing_same_as_address, billing_address, tax_registered, tax_number, sales_tax_number, cnic, tax_exempt,
    tax_rate_override, withholding_tax_rate (both 0-100, decimal(8,4)), tax_notes, currency, payment_terms_days, source,
    notes; on create also account_manager_id and primary_contact[name|designation|email|phone].
    Never posted: client_code, user_id, status, portal_enabled (each has its own method, §6.7). On edit the account manager
    is changed from the client page (admin.clients.account-manager).
--}}

@php
    use Illuminate\Support\Facades\Storage;

    $isEdit = $client->exists;
    $clientTypeOptions = (array) ($clientTypeOptions ?? (enum_exists(\App\Enums\ClientType::class) ? \App\Enums\ClientType::options() : []));
    $sourceOptions = (array) ($sourceOptions ?? (enum_exists(\App\Enums\InquirySource::class) ? \App\Enums\InquirySource::options() : []));
    $accountManagerOptions = collect($accountManagerOptions ?? [])->all();
    $typeValue = old('client_type', $client->client_type instanceof \BackedEnum ? $client->client_type->value : ($client->client_type ?: 'company'));
    $sourceValue = $client->source instanceof \BackedEnum ? $client->source->value : $client->source;
    $currentLogo = filled($client->logo_path) ? Storage::disk('public')->url((string) $client->logo_path) : null;
    $billingSame = (bool) old('billing_same_as_address', $isEdit ? $client->billing_same_as_address : true);
    $action = $isEdit ? route('admin.clients.update', $client) : route('admin.clients.store');
@endphp

<form method="POST" action="{{ $action }}" class="space-y-6" x-data="{ type: {{ \Illuminate\Support\Js::from($typeValue) }}, billingSame: {{ \Illuminate\Support\Js::from($billingSame) }}, busy: false }" x-on:submit="busy = true">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <x-ui.card title="Identity" icon="building-office">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">Client type</span>
                        <div class="mt-1.5 flex flex-wrap gap-2" role="radiogroup" aria-label="Client type">
                            @foreach ($clientTypeOptions as $optionValue => $optionLabel)
                                <label class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm ring-1 ring-slate-200 transition has-[:checked]:bg-brand-50 has-[:checked]:ring-brand-300 dark:ring-slate-700 dark:has-[:checked]:bg-brand-500/10 dark:has-[:checked]:ring-brand-500/40">
                                    <input type="radio" name="client_type" value="{{ $optionValue }}" x-model="type" class="h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                                    <span class="text-slate-700 dark:text-slate-200">{{ $optionLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-ui.form.error for="client_type" />
                    </div>
                    <x-ui.form.input name="company_name" label="Company name" :value="$client->company_name" maxlength="150" x-bind:required="type === 'company'" />
                    <x-ui.form.input name="name" label="Contact person" :value="$client->name" required maxlength="150" />
                    <x-ui.form.input name="industry" label="Industry" :value="$client->industry" optional maxlength="96" />
                    <x-ui.form.input name="website" type="url" label="Website" :value="$client->website" optional maxlength="255" placeholder="https://" />
                    <x-ui.form.textarea name="about" label="About" :value="$client->about" :rows="3" optional maxlength="5000" class="sm:col-span-2" />
                </div>
            </x-ui.card>

            <x-ui.card title="Contact" icon="phone">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-ui.form.input name="email" type="email" label="Email" :value="$client->email" icon="envelope" optional maxlength="150" />
                    <x-ui.form.input name="phone" label="Phone" :value="$client->phone" icon="phone" optional maxlength="32" inputmode="tel" />
                    <x-ui.form.input name="whatsapp" label="WhatsApp" :value="$client->whatsapp" icon="whatsapp" optional maxlength="32" inputmode="tel" />
                </div>
            </x-ui.card>

            <x-ui.card title="Address" icon="globe-alt">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.form.input name="address" label="Street address" :value="$client->address" optional maxlength="255" class="sm:col-span-2" />
                    <x-ui.form.input name="city" label="City" :value="$client->city" optional maxlength="96" />
                    <x-ui.form.input name="state" label="State / province" :value="$client->state" optional maxlength="96" />
                    <x-ui.form.input name="postal_code" label="Postal code" :value="$client->postal_code" optional maxlength="24" />
                    <div class="grid grid-cols-3 gap-3">
                        <x-ui.form.input name="country" label="Country" :value="$client->country" optional maxlength="64" class="col-span-2" />
                        <x-ui.form.input name="country_code" label="Code" :value="$client->country_code" maxlength="2" placeholder="PK" />
                    </div>
                    <div class="sm:col-span-2">
                        <input type="hidden" name="billing_same_as_address" value="0">
                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                            <input type="checkbox" name="billing_same_as_address" value="1" x-model="billingSame" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                            Invoices go to this address
                        </label>
                    </div>
                    <div class="sm:col-span-2" x-show="! billingSame" x-cloak>
                        <x-ui.form.textarea name="billing_address" label="Billing address" :value="$client->billing_address" :rows="2" maxlength="255" help="Printed on invoices." x-bind:disabled="billingSame" />
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card title="Tax details" subtitle="Used by invoices; a client can never change these from the portal." icon="receipt-percent">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="space-y-3 sm:col-span-2">
                        <x-ui.form.checkbox name="tax_registered" label="Registered for tax" :checked="(bool) $client->tax_registered" :with-hidden="true" />
                        <x-ui.form.checkbox name="tax_exempt" label="Tax exempt" :checked="(bool) $client->tax_exempt" :with-hidden="true" />
                    </div>
                    <x-ui.form.input name="tax_number" label="NTN" :value="$client->tax_number" optional maxlength="64" />
                    <x-ui.form.input name="sales_tax_number" label="STRN / GST number" :value="$client->sales_tax_number" optional maxlength="64" />
                    <x-ui.form.input name="cnic" label="CNIC" :value="$client->cnic" maxlength="24" placeholder="00000-0000000-0" x-bind:required="type === 'individual'" help="Required for an individual client." />
                    <div></div>
                    <x-ui.form.input name="tax_rate_override" label="Tax rate override" :value="$client->tax_rate_override" optional inputmode="decimal" suffix="%" pattern="^(100(\.0{1,4})?|\d{1,2}(\.\d{1,4})?)$" :help="filled($defaultTaxRate ?? null) ? 'Empty uses the default of '.\App\Support\Format::percentage($defaultTaxRate).'.' : 'Empty uses the default tax rate.'" />
                    <x-ui.form.input name="withholding_tax_rate" label="Withholding tax rate" :value="$client->withholding_tax_rate" optional inputmode="decimal" suffix="%" pattern="^(100(\.0{1,4})?|\d{1,2}(\.\d{1,4})?)$" help="Between 0 and 100." />
                    <x-ui.form.input name="tax_notes" label="Tax notes" :value="$client->tax_notes" optional maxlength="255" class="sm:col-span-2" />
                </div>
            </x-ui.card>

            @unless ($isEdit)
                <x-ui.card title="Primary contact" subtitle="Optional. More contacts can be added from the client page." icon="user">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.form.input name="primary_contact[name]" label="Name" optional maxlength="150" />
                        <x-ui.form.input name="primary_contact[designation]" label="Designation" optional maxlength="96" />
                        <x-ui.form.input name="primary_contact[email]" type="email" label="Email" optional maxlength="150" />
                        <x-ui.form.input name="primary_contact[phone]" label="Phone" optional maxlength="32" inputmode="tel" />
                    </div>
                </x-ui.card>
            @endunless
        </div>

        <div class="space-y-6">
            @if ($currentLogo)
                {{-- Shown only: ClientService publishes no logo upload yet, so this form offers none (a control that
                     silently does nothing would be a bug). The upload arrives with that service method. --}}
                <x-ui.card title="Logo" icon="photo">
                    <img src="{{ $currentLogo }}" alt="{{ $client->display_name ?? $client->name }} logo" class="h-20 w-20 rounded-xl object-cover ring-1 ring-slate-200 dark:ring-slate-700">
                </x-ui.card>
            @endif

            <x-ui.card title="Commercial terms" icon="banknotes">
                <div class="space-y-4">
                    <x-ui.form.input name="currency" label="Currency" :value="$client->currency" optional maxlength="3" :placeholder="$defaultCurrency ?? 'Default'" help="3-letter ISO code. Empty uses the business currency." />
                    <x-ui.form.input name="payment_terms_days" type="number" label="Payment terms (days)" :value="$client->payment_terms_days" optional min="0" max="365" step="1" inputmode="numeric" :placeholder="isset($defaultPaymentTermsDays) ? (string) $defaultPaymentTermsDays : null" />
                    <x-ui.form.select name="source" label="Source" :options="$sourceOptions" :selected="$sourceValue" placeholder="Unknown" optional />
                    @if (! $isEdit && $accountManagerOptions !== [])
                        <x-ui.form.select name="account_manager_id" label="Account manager" :options="$accountManagerOptions" :selected="old('account_manager_id')" placeholder="Nobody yet" />
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card title="Internal notes" subtitle="Never shown in the client portal." icon="lock-closed">
                <x-ui.form.textarea name="notes" :value="$client->notes" :rows="5" maxlength="10000" />
            </x-ui.card>
        </div>
    </div>

    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <x-ui.button variant="secondary" :href="$isEdit ? route('admin.clients.show', $client) : route('admin.clients.index')">Cancel</x-ui.button>
        <x-ui.button type="submit" icon="check" x-bind:disabled="busy">{{ $isEdit ? 'Save changes' : 'Create client' }}</x-ui.button>
    </div>
</form>
