@extends('layouts.panel')

@section('title', 'Company profile')

{{--
    Client panel profile — client.profile.edit → PUT client.profile.update (phase-05 §8.10 "Profile", §6.9, test 81).
    The editable fields are exactly ClientPortalService::updateProfile()'s whitelist; UpdateClientProfileRequest drops
    anything else. Tax numbers, status, terms, currency and the account manager are shown read-only.

    Controller variables (Client\ProfileController@edit):
      $client          App\Models\Crm\Client  ClientContext::client(), with accountManager (id, name, email)
      $contact         ?App\Models\Crm\ClientContact  ClientContext::contact() when the user signed in as a contact
      $isContact       bool
      $readOnly        array  the controller's read-only snapshot (this view lists the same facts itself)
      $canEditCompany  optional bool (default true — the §6.9 whitelist covers a contact login too)
      $clientName, $portalSections   the shared portal data

    Fields posted (UpdateClientProfileRequest): name, phone, whatsapp, website, address, city, postal_code, about, and for a
    contact login contact_name, contact_designation, contact_phone. The logo is managed by staff: the portal request
    accepts no file.
--}}

@php
    $contact = $contact ?? null;
    $isContact = $contact !== null;
    $canEditCompany = (bool) ($canEditCompany ?? true);
    $manager = $client->relationLoaded('accountManager') ? $client->accountManager : null;
    $rate = static fn ($value): string => $value === null || $value === '' ? 'Standard' : \App\Support\Format::percentage((string) $value);
    $readOnly = [
        'Client ID' => $client->client_code,
        'Company name' => $client->company_name,
        'Email on file' => $client->email,
        'Status' => $client->status instanceof \BackedEnum && method_exists($client->status, 'label') ? $client->status->label() : \Illuminate\Support\Str::headline((string) $client->status),
        'NTN' => $client->tax_number,
        'STRN / GST' => $client->sales_tax_number,
        'CNIC' => $client->cnic,
        'Tax rate' => $rate($client->tax_rate_override),
        'Payment terms' => $client->payment_terms_days !== null ? app_number((int) $client->payment_terms_days).' days' : 'Standard',
        'Currency' => $client->currency ?: 'Standard',
        'Account manager' => $manager?->name,
    ];
@endphp

@section('header')
    @include('client.partials.header', ['client' => $client, 'title' => 'Company profile', 'subtitle' => 'Keep the details we use to reach you right.', 'icon' => 'building-office'])
@endsection

@section('content')
    <form method="POST" action="{{ route('client.profile.update') }}" class="space-y-6" x-data="{ busy: false }" x-on:submit="busy = true">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                @if ($isContact)
                    <x-ui.card title="Your details" subtitle="How we address you." icon="user">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-ui.form.input name="contact_name" label="Your name" :value="$contact->name" required maxlength="150" />
                            <x-ui.form.input name="contact_designation" label="Designation" :value="$contact->designation" optional maxlength="96" />
                            <x-ui.form.input name="contact_phone" label="Phone" :value="$contact->phone" optional maxlength="32" inputmode="tel" />
                        </div>
                    </x-ui.card>
                @endif

                @if ($canEditCompany)
                    <x-ui.card title="Company details" icon="building-office">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-ui.form.input name="name" label="Contact person" :value="$client->name" required maxlength="150" />
                            <x-ui.form.input name="website" type="url" label="Website" :value="$client->website" optional maxlength="255" placeholder="https://" />
                            <x-ui.form.input name="phone" label="Phone" :value="$client->phone" optional maxlength="32" inputmode="tel" />
                            <x-ui.form.input name="whatsapp" label="WhatsApp" :value="$client->whatsapp" optional maxlength="32" inputmode="tel" />
                            <x-ui.form.input name="address" label="Address" :value="$client->address" optional maxlength="255" class="sm:col-span-2" />
                            <x-ui.form.input name="city" label="City" :value="$client->city" optional maxlength="96" />
                            <x-ui.form.input name="postal_code" label="Postal code" :value="$client->postal_code" optional maxlength="24" />
                            <x-ui.form.textarea name="about" label="About your company" :value="$client->about" :rows="4" optional maxlength="5000" class="sm:col-span-2" />
                        </div>
                    </x-ui.card>
                @else
                    <x-ui.card title="Company details" subtitle="Your company's main login keeps these up to date." icon="building-office">
                        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                            @foreach (['Contact person' => $client->name, 'Website' => $client->website, 'Phone' => $client->phone, 'WhatsApp' => $client->whatsapp, 'Address' => collect([$client->address, $client->city, $client->postal_code])->filter()->implode(', ')] as $fieldLabel => $fieldValue)
                                <div><dt class="text-xs text-slate-500 dark:text-slate-400">{{ $fieldLabel }}</dt><dd class="mt-0.5 text-slate-900 dark:text-white">{{ filled($fieldValue) ? $fieldValue : '—' }}</dd></div>
                            @endforeach
                        </dl>
                    </x-ui.card>
                @endif
            </div>

            <x-ui.card title="On our records" subtitle="To change any of these, contact your account manager." icon="lock-closed">
                <dl class="space-y-3 text-sm">
                    @foreach ($readOnly as $fieldLabel => $fieldValue)
                        <div class="flex justify-between gap-3 border-b border-slate-100 pb-2 last:border-0 dark:border-slate-800">
                            <dt class="text-slate-500 dark:text-slate-400">{{ $fieldLabel }}</dt>
                            <dd class="text-right font-medium text-slate-900 dark:text-white">{{ filled($fieldValue) ? $fieldValue : '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
                @if ($manager && filled($manager->email))
                    <x-slot:footer>
                        <a href="mailto:{{ $manager->email }}" class="font-semibold text-brand-700 hover:underline dark:text-brand-300">Email {{ $manager->name }}</a>
                    </x-slot:footer>
                @endif
            </x-ui.card>
        </div>

        @if ($isContact || $canEditCompany)
            <div class="flex justify-end">
                <x-ui.button type="submit" icon="check" x-bind:disabled="busy">Save changes</x-ui.button>
            </div>
        @endif
    </form>
@endsection
