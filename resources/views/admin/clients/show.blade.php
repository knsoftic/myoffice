@extends('layouts.admin')

@section('title', $client->display_name ?? $client->name)

{{--
    Client detail — admin.clients.show (phase-05 §8.8). ClientPolicy::view has passed.

    Controller variables (Admin\ClientController@show):
      $client                   App\Models\Crm\Client with accountManager, portalUser (id, name, email, status,
                                last_login_at), originLead (id, lead_no, name, status), creator
      $contacts                 Collection<ClientContact> primary first, with user (id, name, email, status, last_login_at)
      $documents                Collection<ClientDocument> the latest ten, with creator, sharedBy ([] without documents)
      $documentCount            ?int   all of this client's documents (null without client_documents.view_any)
      $canSeeDocuments          bool   module client_documents on AND client_documents.view_any
      $documentCategoryOptions  array<string, string>  ClientDocumentCategory::options()
      $documentUpload           array{extensions: list<string>, max_kb: int, visible_default: bool}
      $conversions              Collection<LeadConversion> with lead (id, lead_no, name), convertedBy
      $activities               Collection<Spatie\Activitylog\Models\Activity> for this client, newest first, with causer
      $canViewActivity          bool   clients.view_logs
      $canViewFinancial         bool   clients.view_financial
      $financialSummary         ClientFinancialSummary — present ONLY when $canViewFinancial (test 58)
      $projectsAvailable        bool   the projects capability is bound; otherwise the Projects tab is an empty state
      $projects                 optional list<array{name, code, status_label, status_color, url}> once it is bound
      $statusOptions            array<string, string>  ClientStatus::options()
      $accountManagerOptions    array<int, string>     empty without clients.assign
      $contactOptions           optional array<int, string>  contacts with an email; derived from $contacts when absent
      $whatsappTemplate         ?string crm.whatsapp_link_template
    Query: ?tab=overview|contacts|documents|projects|financials|portal|activity (the document writes redirect to
    ?tab=documents, the portal writes to ?tab=portal).

    Writes from this page: the dialogs of admin/clients/partials/dialogs and admin/clients/documents/partials/edit-dialog,
    the upload card; PATCH admin.clients.contacts.primary {client, contact}; DELETE admin.clients.contacts.destroy.
--}}

@php
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $contacts = collect($contacts ?? []);
    $activity = collect($activities ?? ($activity ?? []));
    $canViewActivity = (bool) ($canViewActivity ?? $user?->can('clients.view_logs'));
    $projectsAvailable = (bool) ($projectsAvailable ?? false);
    $projects = collect($projects ?? []);
    $conversions = collect($conversions ?? []);
    $canFinancial = (bool) ($canViewFinancial ?? $user?->can('viewFinancial', $client)) && isset($financialSummary);
    $canDocuments = (bool) ($canSeeDocuments ?? $user?->can('client_documents.view_any'));
    $recentDocuments = collect($documents ?? []);
    $documentTotal = $documentCount ?? ($documentsCount ?? null);
    $documentCan = [
        'upload' => (bool) $user?->can('client_documents.upload'),
        'download' => (bool) $user?->can('client_documents.download'),
        'edit' => (bool) $user?->can('client_documents.edit'),
        'share' => (bool) $user?->can('client_documents.change_status'),
        'delete' => (bool) $user?->can('client_documents.delete'),
    ];
    $canManagePortal = (bool) $user?->can('managePortal', $client);
    $canAddContact = (bool) $user?->can('update', $client) && Route::has('admin.clients.contacts.store');
    $portalUser = $client->relationLoaded('portalUser') ? $client->portalUser : null;
    $originLead = $client->relationLoaded('originLead') ? $client->originLead : null;
    $contactOptions = $contactOptions ?? $contacts
        ->filter(static fn ($contact): bool => filled($contact->email))
        ->mapWithKeys(static fn ($contact): array => [(int) $contact->getKey() => $contact->name.' · '.$contact->email])
        ->all();

    $tabKeys = ['overview', 'contacts', 'projects', 'portal'];
    if ($canDocuments) {
        $tabKeys[] = 'documents';
    }
    if ($canFinancial) {
        $tabKeys[] = 'financials';
    }
    if ($canViewActivity) {
        $tabKeys[] = 'activity';
    }
    $initialTab = in_array(request('tab'), $tabKeys, true) ? request('tab') : 'overview';

    $tabs = [
        ['label' => 'Overview', 'key' => 'overview', 'icon' => 'building-office'],
        ['label' => 'Contacts', 'key' => 'contacts', 'icon' => 'user-group', 'count' => app_number($contacts->count())],
    ];
    if ($canDocuments) {
        $tabs[] = ['label' => 'Documents', 'key' => 'documents', 'icon' => 'paper-clip', 'count' => $documentTotal !== null ? app_number((int) $documentTotal) : null];
    }
    $tabs[] = ['label' => 'Projects', 'key' => 'projects', 'icon' => 'folder'];
    if ($canFinancial) {
        $tabs[] = ['label' => 'Financials', 'key' => 'financials', 'icon' => 'banknotes'];
    }
    $tabs[] = ['label' => 'Portal access', 'key' => 'portal', 'icon' => 'lock-open'];
    if ($canViewActivity) {
        $tabs[] = ['label' => 'Activity', 'key' => 'activity', 'icon' => 'clock'];
    }

    $yesNo = static fn ($value): string => $value ? 'Yes' : 'No';
    $percent = static fn ($rate): string => $rate === null || $rate === '' ? 'Default' : \App\Support\Format::percentage((string) $rate);
    $addressLines = collect([$client->address, collect([$client->city, $client->state, $client->postal_code])->filter()->implode(', '), $client->country])->filter();
@endphp

@section('header')
    @include('admin.clients.partials.header', ['client' => $client])
@endsection

@section('content')
    <div class="space-y-6" x-data="uiTabs({{ \Illuminate\Support\Js::from($initialTab) }})">
        <x-ui.tabs :tabs="$tabs" />

        {{-- Overview --}}
        <div x-show="is('overview')" @if ($initialTab !== 'overview') x-cloak @endif role="tabpanel" class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <x-ui.card title="Identity" icon="identification">
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">Client ID</dt><dd class="mt-0.5 font-mono text-slate-900 dark:text-white">{{ $client->client_code }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">Type</dt><dd class="mt-0.5">@include('admin.crm.partials.enum-badge', ['value' => $client->client_type, 'dot' => false])</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">Company</dt><dd class="mt-0.5 text-slate-900 dark:text-white">{{ $client->company_name ?: '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">Contact person</dt><dd class="mt-0.5 text-slate-900 dark:text-white">{{ $client->name }}</dd></div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Reach them</dt>
                            <dd class="mt-0.5">@include('admin.crm.partials.contact-links', ['phone' => $client->phone, 'whatsapp' => $client->whatsapp, 'email' => $client->email, 'name' => $client->display_name ?? $client->name, 'whatsappTemplate' => $whatsappTemplate ?? null, 'labels' => true])</dd>
                        </div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">Industry</dt><dd class="mt-0.5 text-slate-900 dark:text-white">{{ $client->industry ?: '—' }}</dd></div>
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Website</dt>
                            <dd class="mt-0.5">
                                @if (filled($client->website) && preg_match('~^https?://~i', (string) $client->website) === 1)
                                    <a href="{{ $client->website }}" target="_blank" rel="noopener noreferrer" class="break-all text-brand-700 hover:underline dark:text-brand-300">{{ $client->website }}</a>
                                @else
                                    <span class="text-slate-900 dark:text-white">{{ $client->website ?: '—' }}</span>
                                @endif
                            </dd>
                        </div>
                        @if (filled($client->about))
                            <div class="sm:col-span-2"><dt class="text-xs text-slate-500 dark:text-slate-400">About</dt><dd class="mt-0.5 whitespace-pre-line text-slate-700 dark:text-slate-200">{{ $client->about }}</dd></div>
                        @endif
                    </dl>
                </x-ui.card>

                <x-ui.card title="Address" icon="globe-alt">
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Address</dt>
                            <dd class="mt-0.5 text-slate-900 dark:text-white">
                                @forelse ($addressLines as $line)
                                    <span class="block">{{ $line }}</span>
                                @empty
                                    —
                                @endforelse
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Billing address</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-slate-900 dark:text-white">{{ $client->billing_same_as_address ? 'Same as the address' : ($client->billing_address ?: '—') }}</dd>
                        </div>
                    </dl>
                </x-ui.card>

                <x-ui.card title="Tax details" icon="receipt-percent">
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">Registered</dt><dd class="mt-0.5 text-slate-900 dark:text-white">{{ $yesNo($client->tax_registered) }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">NTN</dt><dd class="mt-0.5 font-mono text-slate-900 dark:text-white">{{ $client->tax_number ?: '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">STRN / GST</dt><dd class="mt-0.5 font-mono text-slate-900 dark:text-white">{{ $client->sales_tax_number ?: '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">CNIC</dt><dd class="mt-0.5 font-mono text-slate-900 dark:text-white">{{ $client->cnic ?: '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">Exempt</dt><dd class="mt-0.5 text-slate-900 dark:text-white">{{ $yesNo($client->tax_exempt) }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">Tax rate</dt><dd class="mt-0.5 tabular-nums text-slate-900 dark:text-white">{{ $percent($client->tax_rate_override) }}</dd></div>
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">Withholding</dt><dd class="mt-0.5 tabular-nums text-slate-900 dark:text-white">{{ $percent($client->withholding_tax_rate) }}</dd></div>
                        @if (filled($client->tax_notes))
                            <div class="col-span-2"><dt class="text-xs text-slate-500 dark:text-slate-400">Notes</dt><dd class="mt-0.5 text-slate-900 dark:text-white">{{ $client->tax_notes }}</dd></div>
                        @endif
                    </dl>
                </x-ui.card>
            </div>

            <div class="space-y-6">
                <x-ui.card title="Commercial terms" icon="banknotes">
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">Currency</dt><dd class="font-medium text-slate-900 dark:text-white">{{ $client->currency ?: 'Business default' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">Payment terms</dt><dd class="font-medium text-slate-900 dark:text-white">{{ $client->payment_terms_days !== null ? app_number((int) $client->payment_terms_days).' days' : 'Business default' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">Source</dt><dd>@include('admin.crm.partials.enum-badge', ['value' => $client->source, 'dot' => false, 'variant' => 'outline'])</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">Client since</dt><dd class="font-medium text-slate-900 dark:text-white">{{ app_date($client->created_at) }}</dd></div>
                    </dl>
                </x-ui.card>

                <x-ui.card title="Origin" icon="funnel">
                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Converted from</dt>
                            <dd class="mt-0.5">
                                @if ($originLead)
                                    @if ($user?->can('view', $originLead))
                                        <a href="{{ route('admin.leads.show', $originLead) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-300">{{ $originLead->name }}</a>
                                    @else
                                        <span class="text-slate-900 dark:text-white">{{ $originLead->name }}</span>
                                    @endif
                                    <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $originLead->lead_no }}</span>
                                @else
                                    <span class="text-slate-500 dark:text-slate-400">Added directly</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Referral code</dt>
                            <dd class="mt-0.5 font-mono text-slate-900 dark:text-white">{{ $client->referral_code_captured ?: '—' }}</dd>
                            @if (filled($client->referral_code_captured) && ! $client->referral_recorded_at)
                                <dd class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Attached automatically once referral tracking is available.</dd>
                            @endif
                        </div>
                        @if ($conversions->isNotEmpty())
                            <div>
                                <dt class="text-xs text-slate-500 dark:text-slate-400">Lead conversions</dt>
                                @foreach ($conversions as $conversion)
                                    @php $convertedLead = $conversion->relationLoaded('lead') ? $conversion->lead : null; @endphp
                                    <dd class="mt-1 text-xs text-slate-600 dark:text-slate-300">
                                        @if ($convertedLead && $user?->can('view', $convertedLead))
                                            <a href="{{ route('admin.leads.show', ['lead' => $convertedLead, 'tab' => 'conversion']) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-300">{{ $convertedLead->lead_no }}</a>
                                        @else
                                            <span class="font-mono">{{ $convertedLead?->lead_no ?? 'A lead' }}</span>
                                        @endif
                                        · {{ app_date($conversion->converted_at) }}
                                        @if ($conversion->superseded_at)
                                            <x-ui.badge color="amber" size="xs" variant="outline">Superseded</x-ui.badge>
                                        @endif
                                    </dd>
                                @endforeach
                            </div>
                        @endif
                    </dl>
                </x-ui.card>

                <x-ui.card title="Internal notes" subtitle="Never shown in the portal." icon="lock-closed">
                    @if (filled($client->notes))
                        <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{{ $client->notes }}</p>
                    @else
                        <p class="text-sm text-slate-400 dark:text-slate-500">No notes.</p>
                    @endif
                </x-ui.card>
            </div>
        </div>

        {{-- Contacts --}}
        <div x-show="is('contacts')" @if ($initialTab !== 'contacts') x-cloak @endif role="tabpanel" class="space-y-3">
            @if ($canAddContact)
                <div class="flex justify-end">
                    <x-ui.button icon="plus" size="sm" x-on:click="$dispatch('open-modal', { name: 'client-contact', url: {{ \Illuminate\Support\Js::from(route('admin.clients.contacts.store', $client)) }}, method: 'POST', contact: {} })">Add contact</x-ui.button>
                </div>
            @endif
            <x-ui.table :is-empty="$contacts->isEmpty()" :columns="5">
                <x-slot:head>
                    <th scope="col" class="px-4 py-3">Name</th>
                    <th scope="col" class="px-4 py-3">Reach</th>
                    <th scope="col" class="px-4 py-3">Roles</th>
                    <th scope="col" class="px-4 py-3">Portal</th>
                    <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                </x-slot:head>
                @foreach ($contacts as $contact)
                    @php
                        $contactUser = $contact->relationLoaded('user') ? $contact->user : null;
                        $canEditContact = (bool) $user?->can('update', $contact);
                        $canDeleteContact = (bool) $user?->can('delete', $contact) && Route::has('admin.clients.contacts.destroy');
                    @endphp
                    <tr>
                        <td class="min-w-[12rem]">
                            <span class="flex items-center gap-2">
                                @if ($contact->is_primary)
                                    <x-ui.icon name="star" class="h-4 w-4 fill-amber-400 text-amber-500" label="Primary contact" />
                                @endif
                                <span class="font-medium text-slate-900 dark:text-white">{{ $contact->name }}</span>
                            </span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ collect([$contact->designation, $contact->department])->filter()->implode(' · ') ?: '—' }}</span>
                        </td>
                        <td>@include('admin.crm.partials.contact-links', ['phone' => $contact->phone, 'whatsapp' => $contact->whatsapp, 'email' => $contact->email, 'name' => $contact->name, 'whatsappTemplate' => $whatsappTemplate ?? null, 'size' => 'xs'])</td>
                        <td class="whitespace-nowrap">
                            <span class="flex flex-wrap gap-1">
                                @if ($contact->is_primary) <x-ui.badge color="amber" size="xs">Primary</x-ui.badge> @endif
                                @if ($contact->is_billing_contact) <x-ui.badge color="sky" size="xs">Billing</x-ui.badge> @endif
                                @unless ($contact->receives_notifications) <x-ui.badge color="slate" size="xs" variant="outline">No notifications</x-ui.badge> @endunless
                            </span>
                        </td>
                        <td class="whitespace-nowrap">
                            @if ($contact->portal_access && $contactUser)
                                <x-ui.badge :color="$contactUser->last_login_at ? 'emerald' : 'amber'" size="xs" icon="lock-open">{{ $contactUser->last_login_at ? 'Portal access' : 'Invited' }}</x-ui.badge>
                            @elseif ($contact->portal_access)
                                <x-ui.badge color="amber" size="xs" variant="outline">Access allowed, not invited</x-ui.badge>
                            @else
                                <span class="text-xs text-slate-400 dark:text-slate-500">No access</span>
                            @endif
                        </td>
                        <td>
                            <div class="flex items-center justify-end gap-1">
                                @if ($canEditContact && ! $contact->is_primary && Route::has('admin.clients.contacts.primary'))
                                    <form method="POST" action="{{ route('admin.clients.contacts.primary', [$client, $contact]) }}">
                                        @csrf
                                        @method('PATCH')
                                        <x-ui.icon-button type="submit" icon="star" size="sm" :label="'Make '.$contact->name.' the primary contact'" />
                                    </form>
                                @endif
                                @if ($canEditContact && Route::has('admin.clients.contacts.update'))
                                    <x-ui.icon-button
                                        icon="pencil"
                                        size="sm"
                                        :label="'Edit '.$contact->name"
                                        x-on:click="$dispatch('open-modal', { name: 'client-contact', url: {{ \Illuminate\Support\Js::from(route('admin.clients.contacts.update', [$client, $contact])) }}, method: 'PUT', contact: {{ \Illuminate\Support\Js::from($contact->only(['name', 'designation', 'department', 'email', 'phone', 'whatsapp', 'notes', 'is_primary', 'is_billing_contact', 'portal_access', 'receives_notifications'])) }} })"
                                    />
                                @endif
                                @if ($canDeleteContact)
                                    <x-ui.confirm
                                        :action="route('admin.clients.contacts.destroy', [$client, $contact])"
                                        :title="'Remove '.$contact->name.'?'"
                                        :message="$contact->is_primary ? 'This is the primary contact: promote another contact first, or the removal is refused.' : 'Their portal access, if any, ends with the contact.'"
                                        confirm-label="Remove contact"
                                    >
                                        <x-slot:trigger>
                                            <x-ui.icon-button icon="trash" size="sm" variant="danger" :label="'Remove '.$contact->name" />
                                        </x-slot:trigger>
                                    </x-ui.confirm>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                <x-slot:empty>
                    <x-ui.empty-state icon="user-group" title="No contacts yet" message="Add the people you deal with: who signs, who pays, who uses the portal." :compact="true" />
                </x-slot:empty>
            </x-ui.table>
        </div>

        {{-- Documents --}}
        @if ($canDocuments)
            <div x-show="is('documents')" @if ($initialTab !== 'documents') x-cloak @endif role="tabpanel" class="space-y-4">
                @if ($documentCan['upload'] && Route::has('admin.clients.documents.store'))
                    @include('admin.clients.documents.partials.upload', ['client' => $client, 'categoryOptions' => $documentCategoryOptions ?? null, 'upload' => $documentUpload ?? []])
                @endif
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <x-ui.section-heading title="Latest documents" :subtitle="$documentTotal !== null ? app_number((int) $documentTotal).' on file' : null" icon="paper-clip" />
                    @if (Route::has('admin.clients.documents.index'))
                        <x-ui.button variant="secondary" size="sm" icon-trailing="arrow-right" :href="route('admin.clients.documents.index', $client)">All documents and filters</x-ui.button>
                    @endif
                </div>
                @include('admin.clients.documents.partials.table', ['client' => $client, 'documents' => $recentDocuments, 'can' => $documentCan, 'sortable' => false, 'filtered' => false])
                @include('admin.clients.documents.partials.edit-dialog', ['categoryOptions' => $documentCategoryOptions ?? null])
            </div>
        @endif

        {{-- Projects --}}
        <div x-show="is('projects')" @if ($initialTab !== 'projects') x-cloak @endif role="tabpanel">
            @if (! $projectsAvailable)
                <x-ui.card :padded="false">
                    <x-ui.empty-state icon="folder" title="Projects arrive with the projects module" message="Once projects are in use (phase 6), this client's projects are listed here." />
                </x-ui.card>
            @else
                <x-ui.table :is-empty="$projects->isEmpty()" :columns="3">
                    <x-slot:head>
                        <th scope="col" class="px-4 py-3">Project</th>
                        <th scope="col" class="px-4 py-3">Code</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                    </x-slot:head>
                    @foreach ($projects as $project)
                        <tr>
                            <td class="font-medium">
                                @if (filled(data_get($project, 'url')))
                                    <a href="{{ data_get($project, 'url') }}" class="text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ data_get($project, 'name') }}</a>
                                @else
                                    {{ data_get($project, 'name') }}
                                @endif
                            </td>
                            <td class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ data_get($project, 'code') ?: '—' }}</td>
                            <td>
                                @if (filled(data_get($project, 'status_label')))
                                    <x-ui.badge :color="data_get($project, 'status_color') ?: 'slate'" size="sm" :dot="true">{{ data_get($project, 'status_label') }}</x-ui.badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <x-slot:empty>
                        <x-ui.empty-state icon="folder" title="No projects for this client yet" :compact="true" />
                    </x-slot:empty>
                </x-ui.table>
            @endif
        </div>

        {{-- Financials --}}
        @if ($canFinancial)
            <div x-show="is('financials')" @if ($initialTab !== 'financials') x-cloak @endif role="tabpanel">
                @include('admin.clients.partials.financials', ['summary' => $financialSummary])
            </div>
        @endif

        {{-- Portal access --}}
        <div x-show="is('portal')" @if ($initialTab !== 'portal') x-cloak @endif role="tabpanel" class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <x-ui.card title="Main portal login" icon="lock-open" class="xl:col-span-2">
                <x-slot:actions>
                    @include('admin.clients.partials.portal-chip', ['client' => $client])
                </x-slot:actions>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Login</dt><dd class="mt-0.5 text-slate-900 dark:text-white">{{ $portalUser ? $portalUser->name.' · '.$portalUser->email : 'No login yet' }}</dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Invited</dt><dd class="mt-0.5 text-slate-900 dark:text-white">{{ $client->portal_invited_at ? app_datetime($client->portal_invited_at) : '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Last sign-in</dt><dd class="mt-0.5 text-slate-900 dark:text-white">{{ $portalUser?->last_login_at ? app_datetime($portalUser->last_login_at) : 'Never' }}</dd></div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Can use the portal now</dt>
                        <dd class="mt-0.5 text-slate-900 dark:text-white">
                            @php
                                $statusAllows = $client->status instanceof \BackedEnum && method_exists($client->status, 'canUsePortal') ? $client->status->canUsePortal() : ((string) ($client->status->value ?? $client->status) === 'active');
                            @endphp
                            @if ($client->portal_enabled && $portalUser && $statusAllows)
                                Yes
                            @elseif (! $statusAllows)
                                No — the client's status does not allow it
                            @else
                                No
                            @endif
                        </dd>
                    </div>
                </dl>
                @if ($canManagePortal && Route::has('admin.clients.portal.enable'))
                    <x-slot:footer>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($client->portal_enabled)
                                <x-ui.button size="sm" variant="secondary" icon="envelope" x-on:click="$dispatch('open-modal', 'client-portal-enable')">Resend invite</x-ui.button>
                                <x-ui.button size="sm" variant="danger" icon="lock-closed" x-on:click="$dispatch('open-modal', 'client-portal-disable')">Disable</x-ui.button>
                            @else
                                <x-ui.button size="sm" icon="envelope" x-on:click="$dispatch('open-modal', 'client-portal-enable')">Send invite</x-ui.button>
                            @endif
                        </div>
                    </x-slot:footer>
                @endif
            </x-ui.card>

            <x-ui.card title="Contacts with portal access" icon="user-group">
                @php $portalContacts = $contacts->filter(static fn ($contact): bool => (bool) $contact->portal_access); @endphp
                @forelse ($portalContacts as $contact)
                    @php $contactUser = $contact->relationLoaded('user') ? $contact->user : null; @endphp
                    <div class="flex items-center justify-between gap-2 border-b border-slate-100 py-2 text-sm last:border-0 dark:border-slate-800">
                        <span class="min-w-0">
                            <span class="block truncate font-medium text-slate-900 dark:text-white">{{ $contact->name }}</span>
                            <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $contactUser?->email ?? $contact->email ?? 'No email' }}</span>
                        </span>
                        <x-ui.badge :color="$contactUser ? ($contactUser->last_login_at ? 'emerald' : 'amber') : 'slate'" size="xs">{{ $contactUser ? ($contactUser->last_login_at ? 'Active' : 'Invited') : 'Not invited' }}</x-ui.badge>
                    </div>
                @empty
                    <p class="text-sm text-slate-500 dark:text-slate-400">No contact may use the portal.</p>
                @endforelse
            </x-ui.card>
        </div>

        {{-- Activity --}}
        @if ($canViewActivity)
        <div x-show="is('activity')" @if ($initialTab !== 'activity') x-cloak @endif role="tabpanel">
            <x-ui.card title="Activity" subtitle="Every change to this client, with who made it and why." icon="clock">
                @if ($activity->isEmpty())
                    <x-ui.empty-state icon="clock" title="No activity recorded yet" :compact="true" />
                @else
                    <ol class="relative space-y-5 border-l border-slate-200 pl-5 dark:border-slate-800">
                        @foreach ($activity as $entry)
                            @php
                                $causer = $entry->relationLoaded('causer') ? $entry->causer : null;
                                $properties = $entry->properties instanceof \Illuminate\Support\Collection ? $entry->properties->all() : (array) ($entry->properties ?? []);
                                $old = (array) ($properties['old'] ?? []);
                                $new = (array) ($properties['attributes'] ?? []);
                                $changedKeys = array_keys($new);
                            @endphp
                            <li class="relative">
                                <span class="absolute -left-[1.6rem] top-1 flex h-2.5 w-2.5 rounded-full bg-brand-500 ring-4 ring-white dark:bg-brand-400 dark:ring-slate-900" aria-hidden="true"></span>
                                <p class="text-sm text-slate-900 dark:text-white">{{ \Illuminate\Support\Str::ucfirst((string) ($entry->description ?: \Illuminate\Support\Str::headline((string) $entry->event))) }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $causer?->name ?? 'System' }} · {{ app_datetime($entry->created_at) }}</p>
                                @if (filled($entry->reason ?? null))
                                    <p class="mt-1 text-xs italic text-slate-600 dark:text-slate-300">“{{ $entry->reason }}”</p>
                                @endif
                                @if ($changedKeys !== [])
                                    <dl class="mt-2 space-y-1 text-xs">
                                        @foreach (array_slice($changedKeys, 0, 8) as $key)
                                            @php
                                                $render = static fn ($value): string => $value === null || $value === '' ? '—' : (is_scalar($value) ? (is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value) : (string) json_encode($value));
                                            @endphp
                                            <div class="flex flex-wrap gap-1.5">
                                                <dt class="text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::headline((string) $key) }}:</dt>
                                                <dd class="text-slate-700 dark:text-slate-200">
                                                    @if (array_key_exists($key, $old))
                                                        <span class="line-through opacity-70">{{ \Illuminate\Support\Str::limit($render($old[$key]), 60) }}</span> →
                                                    @endif
                                                    {{ \Illuminate\Support\Str::limit($render($new[$key]), 60) }}
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </div>
        @endif
    </div>

    @include('admin.clients.partials.dialogs', ['client' => $client, 'contactOptions' => $contactOptions])
@endsection
