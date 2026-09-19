{{--
    The client page dialogs (phase-05 §8.8): change status, assign account manager, enable / resend / disable the portal,
    add or edit a contact. Rendered once per page; each opens with $dispatch('open-modal', name) — the contact dialog with
    { name: 'client-contact', url, method: 'POST'|'PUT', contact: {...} }.

    Variables:
      $client                   App\Models\Crm\Client with portalUser
      $statusOptions            array<string, string>  ClientStatus::options()
      $accountManagerOptions    array<int, string>
      $contactOptions           array<int, string>     the client's contacts (id => "Name · email") for a contact invite;
                                                       derived from $contacts (in scope on the client page) when absent

    Posts:
      PATCH admin.clients.status {client}            status, reason (required for suspended and closed)
      PATCH admin.clients.account-manager {client}   user_id (empty = none), reason
      POST  admin.clients.portal.enable {client}     client_contact_id (a contact's login; empty = the client's main
                                                     login), name, email, send_invitation (1) — never a password: the
                                                     user gets a password-set invitation (ClientPortalInvitation)
      POST  admin.clients.portal.disable {client}    reason
      POST  admin.clients.contacts.store {client} / PUT admin.clients.contacts.update {client, contact}
                                                     name, designation, department, email, phone, whatsapp, is_primary,
                                                     is_billing_contact, receives_notifications, notes (portal access for
                                                     a contact is granted by the portal invitation, not by this form)
--}}

@php
    use Illuminate\Support\Facades\Route;

    $dialogUser = auth()->user();
    $dialogStatusOptions = (array) ($statusOptions ?? (enum_exists(\App\Enums\ClientStatus::class) ? \App\Enums\ClientStatus::options() : []));
    $dialogStatus = $client->status instanceof \BackedEnum ? $client->status->value : (string) $client->status;
    $dialogManagers = collect($accountManagerOptions ?? [])->all();
    $dialogContactModels = collect($contacts ?? [])->filter(static fn ($contact): bool => is_object($contact) && filled($contact->email ?? null));
    $dialogContacts = collect($contactOptions ?? $dialogContactModels->mapWithKeys(static fn ($contact): array => [(int) $contact->getKey() => $contact->name.' · '.$contact->email]))->all();
    // Name and email of each invitable contact, so choosing one fills the invitation fields.
    $dialogContactRecords = $dialogContactModels
        ->mapWithKeys(static fn ($contact): array => [(string) $contact->getKey() => ['name' => (string) $contact->name, 'email' => (string) $contact->email]])
        ->all();
    $dialogPortalUser = $client->relationLoaded('portalUser') ? $client->portalUser : null;
    $fieldClass = 'mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white';
    $labelClass = 'block text-xs font-medium text-slate-600 dark:text-slate-300';
@endphp

@if ($dialogUser?->can('changeStatus', $client) && Route::has('admin.clients.status'))
    <x-ui.modal name="client-status" title="Change client status" icon="arrow-path" size="sm">
        <form id="client-status-form" method="POST" action="{{ route('admin.clients.status', $client) }}" class="space-y-3" x-data="{ status: {{ \Illuminate\Support\Js::from($dialogStatus) }} }">
            @csrf
            @method('PATCH')
            <div>
                <label for="client-status-select" class="{{ $labelClass }}">Status</label>
                <select id="client-status-select" name="status" x-model="status" class="{{ $fieldClass }}">
                    @foreach ($dialogStatusOptions as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
                    @endforeach
                </select>
            </div>
            <p x-show="status !== 'active'" x-cloak class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                The client's portal stops working on their very next request. Their portal switch is left as it is, so setting the status back to active restores access.
            </p>
            <div>
                <label for="client-status-reason" class="{{ $labelClass }}">Reason <span x-show="['suspended', 'closed'].includes(status)" class="text-rose-500">*</span></label>
                <input id="client-status-reason" type="text" name="reason" maxlength="255" x-bind:required="['suspended', 'closed'].includes(status)" class="{{ $fieldClass }}">
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'client-status')">Cancel</x-ui.button>
            <x-ui.button type="submit" form="client-status-form" icon="check">Save status</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endif

@if ($dialogUser?->can('assign', $client) && Route::has('admin.clients.account-manager'))
    <x-ui.modal name="client-account-manager" title="Account manager" icon="user-plus" size="sm">
        <form id="client-account-manager-form" method="POST" action="{{ route('admin.clients.account-manager', $client) }}" class="space-y-3">
            @csrf
            @method('PATCH')
            <div>
                <label for="client-account-manager-select" class="{{ $labelClass }}">Who looks after this client?</label>
                <select id="client-account-manager-select" name="user_id" class="{{ $fieldClass }}">
                    <option value="">Nobody</option>
                    @foreach ($dialogManagers as $managerId => $managerName)
                        <option value="{{ $managerId }}" @selected((string) $client->account_manager_id === (string) $managerId)>{{ $managerName }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="client-account-manager-reason" class="{{ $labelClass }}">Reason <span class="font-normal text-slate-400">(optional, kept in the audit log)</span></label>
                <input id="client-account-manager-reason" type="text" name="reason" maxlength="255" class="{{ $fieldClass }}">
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'client-account-manager')">Cancel</x-ui.button>
            <x-ui.button type="submit" form="client-account-manager-form" icon="check">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endif

@if ($dialogUser?->can('managePortal', $client) && Route::has('admin.clients.portal.enable'))
    <x-ui.modal name="client-portal-enable" :title="$client->portal_enabled ? 'Resend the portal invitation' : 'Enable the client portal'" icon="lock-open">
        <form
            id="client-portal-enable-form"
            method="POST"
            action="{{ route('admin.clients.portal.enable', $client) }}"
            class="space-y-4"
            x-data="{
                target: {{ \Illuminate\Support\Js::from(filled(old('client_contact_id')) ? 'contact' : 'client') }},
                contacts: {{ \Illuminate\Support\Js::from((object) $dialogContactRecords) }},
                contactId: {{ \Illuminate\Support\Js::from((string) old('client_contact_id', '')) }},
                name: {{ \Illuminate\Support\Js::from((string) old('name', $dialogPortalUser?->name ?? $client->name)) }},
                email: {{ \Illuminate\Support\Js::from((string) old('email', $dialogPortalUser?->email ?? $client->email)) }},
                clientName: {{ \Illuminate\Support\Js::from((string) ($dialogPortalUser?->name ?? $client->name)) }},
                clientEmail: {{ \Illuminate\Support\Js::from((string) ($dialogPortalUser?->email ?? $client->email)) }},
                choose(target) {
                    this.target = target;
                    if (target === 'client') { this.contactId = ''; this.name = this.clientName; this.email = this.clientEmail; }
                },
                pick() {
                    const record = this.contacts[this.contactId];
                    if (record) { this.name = record.name || ''; this.email = record.email || ''; }
                },
            }"
        >
            @csrf
            <input type="hidden" name="send_invitation" value="1">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                The person receives an email with a link to set their own password. No password is ever set or sent by staff,
                and they must choose a new one on first sign-in.
            </p>

            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Who gets the login">
                <label class="flex cursor-pointer items-start gap-2 rounded-lg p-3 text-sm ring-1 ring-slate-200 has-[:checked]:bg-brand-50 has-[:checked]:ring-brand-300 dark:ring-slate-700 dark:has-[:checked]:bg-brand-500/10 dark:has-[:checked]:ring-brand-500/40">
                    <input type="radio" value="client" x-bind:checked="target === 'client'" x-on:change="choose('client')" class="mt-0.5 h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                    <span><span class="block font-semibold text-slate-900 dark:text-white">The client's main login</span><span class="text-slate-500 dark:text-slate-400">One per client</span></span>
                </label>
                <label @class([
                    'flex items-start gap-2 rounded-lg p-3 text-sm ring-1 ring-slate-200 has-[:checked]:bg-brand-50 has-[:checked]:ring-brand-300 dark:ring-slate-700 dark:has-[:checked]:bg-brand-500/10 dark:has-[:checked]:ring-brand-500/40',
                    'cursor-pointer' => $dialogContacts !== [],
                    'cursor-not-allowed opacity-60' => $dialogContacts === [],
                ])>
                    <input type="radio" value="contact" x-bind:checked="target === 'contact'" x-on:change="choose('contact')" @disabled($dialogContacts === []) class="mt-0.5 h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                    <span><span class="block font-semibold text-slate-900 dark:text-white">A named contact</span><span class="text-slate-500 dark:text-slate-400">{{ $dialogContacts === [] ? 'Add a contact with an email first' : 'An extra login for the same client' }}</span></span>
                </label>
            </div>

            <div x-show="target === 'contact'" x-cloak>
                <label for="client-portal-contact" class="{{ $labelClass }}">Contact <span class="text-rose-500">*</span></label>
                <select id="client-portal-contact" name="client_contact_id" x-model="contactId" x-on:change="pick()" x-bind:disabled="target !== 'contact'" x-bind:required="target === 'contact'" class="{{ $fieldClass }}">
                    <option value="">Choose a contact</option>
                    @foreach ($dialogContacts as $contactId => $contactLabel)
                        <option value="{{ $contactId }}">{{ $contactLabel }}</option>
                    @endforeach
                </select>
                <x-ui.form.error for="client_contact_id" />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">A person already bound to another client or contact cannot be invited.</p>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="client-portal-name" class="{{ $labelClass }}">Name</label>
                    <input id="client-portal-name" type="text" name="name" x-model="name" maxlength="150" class="{{ $fieldClass }}">
                </div>
                <div>
                    <label for="client-portal-email" class="{{ $labelClass }}">Email <span class="text-rose-500">*</span></label>
                    <input id="client-portal-email" type="email" name="email" x-model="email" maxlength="150" required x-bind:readonly="target === 'client' && {{ $dialogPortalUser !== null ? 'true' : 'false' }}" class="{{ $fieldClass }}">
                    <x-ui.form.error for="email" />
                    @if ($dialogPortalUser)
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" x-show="target === 'client'">Already bound to this login; the invitation is sent again.</p>
                    @endif
                </div>
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'client-portal-enable')">Cancel</x-ui.button>
            <x-ui.button type="submit" form="client-portal-enable-form" icon="envelope">{{ $client->portal_enabled ? 'Send the invitation again' : 'Enable and send invitation' }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    @if (Route::has('admin.clients.portal.disable'))
        <x-ui.modal name="client-portal-disable" title="Disable the client portal?" icon="lock-closed" size="sm">
            <form id="client-portal-disable-form" method="POST" action="{{ route('admin.clients.portal.disable', $client) }}" class="space-y-3">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Everyone signed in to this client's portal is signed out on their next request. Their logins and history are kept, so the portal can be enabled again later.
                </p>
                <div>
                    <label for="client-portal-disable-reason" class="{{ $labelClass }}">Reason <span class="text-rose-500">*</span></label>
                    <input id="client-portal-disable-reason" type="text" name="reason" required maxlength="255" class="{{ $fieldClass }}">
                </div>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'client-portal-disable')">Keep it on</x-ui.button>
                <x-ui.button type="submit" form="client-portal-disable-form" variant="danger" icon="lock-closed">Disable portal</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
@endif

@if (Route::has('admin.clients.contacts.store'))
    <x-ui.modal name="client-contact" title="Contact" icon="user" size="lg">
        <form
            id="client-contact-form"
            method="POST"
            x-data="{ url: '', method: 'POST', editing: false }"
            x-on:open-modal.window="if ($event.detail?.name === 'client-contact') {
                const contact = $event.detail.contact || {};
                url = $event.detail.url; method = $event.detail.method || 'POST'; editing = method !== 'POST';
                $nextTick(() => {
                    ['name', 'designation', 'department', 'email', 'phone', 'whatsapp', 'notes'].forEach((field) => { $refs[field].value = contact[field] || ''; });
                    ['is_primary', 'is_billing_contact'].forEach((field) => { $refs[field].checked = Boolean(contact[field]); });
                    $refs.receives_notifications.checked = contact.receives_notifications === undefined ? true : Boolean(contact.receives_notifications);
                });
            }"
            x-bind:action="url"
            class="space-y-4"
        >
            @csrf
            <input type="hidden" name="_method" x-bind:value="method">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="client-contact-name" class="{{ $labelClass }}">Name <span class="text-rose-500">*</span></label>
                    <input id="client-contact-name" x-ref="name" type="text" name="name" required maxlength="150" class="{{ $fieldClass }}">
                </div>
                <div>
                    <label for="client-contact-designation" class="{{ $labelClass }}">Designation</label>
                    <input id="client-contact-designation" x-ref="designation" type="text" name="designation" maxlength="96" class="{{ $fieldClass }}">
                </div>
                <div>
                    <label for="client-contact-department" class="{{ $labelClass }}">Department</label>
                    <input id="client-contact-department" x-ref="department" type="text" name="department" maxlength="96" class="{{ $fieldClass }}">
                </div>
                <div>
                    <label for="client-contact-email" class="{{ $labelClass }}">Email</label>
                    <input id="client-contact-email" x-ref="email" type="email" name="email" maxlength="150" class="{{ $fieldClass }}">
                </div>
                <div>
                    <label for="client-contact-phone" class="{{ $labelClass }}">Phone</label>
                    <input id="client-contact-phone" x-ref="phone" type="text" name="phone" maxlength="32" inputmode="tel" class="{{ $fieldClass }}">
                </div>
                <div>
                    <label for="client-contact-whatsapp" class="{{ $labelClass }}">WhatsApp</label>
                    <input id="client-contact-whatsapp" x-ref="whatsapp" type="text" name="whatsapp" maxlength="32" inputmode="tel" class="{{ $fieldClass }}">
                </div>
            </div>
            <fieldset class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <legend class="sr-only">Contact roles</legend>
                @foreach (['is_primary' => ['Primary contact', 'Replaces the current primary contact.'], 'is_billing_contact' => ['Billing contact', 'Receives invoices.'], 'receives_notifications' => ['Receives notifications', 'Emails about shared documents and updates.']] as $flag => [$flagLabel, $flagHelp])
                    <label class="flex items-start gap-2 rounded-lg p-2 text-sm ring-1 ring-slate-200 dark:ring-slate-700">
                        <input type="hidden" name="{{ $flag }}" value="0">
                        <input type="checkbox" x-ref="{{ $flag }}" name="{{ $flag }}" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                        <span><span class="block font-medium text-slate-800 dark:text-slate-100">{{ $flagLabel }}</span><span class="text-xs text-slate-500 dark:text-slate-400">{{ $flagHelp }}</span></span>
                    </label>
                @endforeach
            </fieldset>
            <div>
                <label for="client-contact-notes" class="{{ $labelClass }}">Notes</label>
                <input id="client-contact-notes" x-ref="notes" type="text" name="notes" maxlength="255" class="{{ $fieldClass }}">
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'client-contact')">Cancel</x-ui.button>
            <x-ui.button type="submit" form="client-contact-form" icon="check">Save contact</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endif
