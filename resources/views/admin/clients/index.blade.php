@extends('layouts.admin')

@section('title', 'Clients')

{{--
    Clients index — admin.clients.index (phase-05 §8.7). "Client ID" is the human-readable client_code; the surrogate id is
    never shown.

    Controller variables (Admin\ClientController@index):
      $clients                LengthAwarePaginator<App\Models\Crm\Client> with accountManager and portalUser
                              (id, last_login_at); withTrashed() only for ?trashed=1 with clients.restore
      $filters                array<string, mixed>
      $sort                   string  client_code | company_name (default) | country | status | created_at
      $direction              string  asc | desc
      $statusOptions          array<string, string>  ClientStatus::options()
      $accountManagerOptions  array<int, string>
      $countryOptions         array<string, string>  the distinct countries on file
      $projectsAvailable      bool    the Phase 6 capability is bound; the Projects column renders only then
      $projectCounts          array<int, int>  client id => project count (only when $projectsAvailable)
      $showFinancial          bool    clients.view_financial — when false the Outstanding column is NOT in the HTML and
                                      the controller does not compute it (test 58)
      $outstanding            array<int, ?string>  client id => outstanding amount; null = the figure is unavailable
      $whatsappTemplate       ?string crm.whatsapp_link_template
    Query: search (client_code, name, company, email, phone), status, account_manager, country, portal (enabled |
    invited | off), created_from, created_to (Y-m-d), trashed (1), sort, direction, page.
--}}

@php
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Facades\Storage;

    $user = auth()->user();
    $sort = $sort ?? 'company_name';
    $direction = $direction ?? 'asc';
    $statusOptions = (array) ($statusOptions ?? (enum_exists(\App\Enums\ClientStatus::class) ? \App\Enums\ClientStatus::options() : []));
    $projectsAvailable = (bool) ($projectsAvailable ?? false);
    $projectCounts = (array) ($projectCounts ?? []);
    $showFinancial = (bool) ($showFinancial ?? false) && (bool) $user?->can('clients.view_financial');
    $outstanding = (array) ($outstanding ?? []);
    $isTrash = request()->boolean('trashed') && (bool) $user?->can('clients.restore');
    $filtered = collect(request()->except(['page', 'sort', 'direction']))->filter(fn ($value) => filled($value))->isNotEmpty();
    $canCreate = (bool) $user?->can('clients.create');
    $canExport = (bool) $user?->can('clients.export') && Route::has('admin.clients.export');
    $columnCount = 7 + ($projectsAvailable ? 1 : 0) + ($showFinancial ? 1 : 0);
    $logoUrl = static fn ($client): ?string => filled($client->logo_path ?? null) ? Storage::disk('public')->url((string) $client->logo_path) : null;
@endphp

@section('header')
    <x-ui.page-header title="Clients" subtitle="Every company and person you work for, with their contacts, documents and portal access." icon="building-office">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('admin.clients.export', request()->except('page'))">Export</x-ui.button>
            @endif
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.clients.create')">Add client</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        <x-ui.filter-bar placeholder="Search client ID, name, company, email or phone…" :reset="route('admin.clients.index')">
            <x-ui.form.select name="status" :options="$statusOptions" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            <x-ui.form.select name="account_manager" :options="collect($accountManagerOptions ?? [])->all()" :selected="request('account_manager')" placeholder="Any account manager" size="sm" aria-label="Filter by account manager" />
            <x-ui.form.select name="country" :options="(array) ($countryOptions ?? [])" :selected="request('country')" placeholder="Any country" size="sm" aria-label="Filter by country" />
            <x-ui.form.select name="portal" :options="['enabled' => 'Portal enabled', 'invited' => 'Portal invited', 'off' => 'Portal off']" :selected="request('portal')" placeholder="Any portal state" size="sm" aria-label="Filter by portal access" />
            <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <span class="shrink-0">From</span>
                <input type="date" name="created_from" value="{{ request('created_from') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </label>
            <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <span class="shrink-0">To</span>
                <input type="date" name="created_to" value="{{ request('created_to') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </label>
            @if ($user?->can('clients.restore'))
                <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="trashed" value="1" @checked($isTrash) class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                    Trashed
                </label>
            @endif
        </x-ui.filter-bar>

        <x-ui.table loading="navigating" :is-empty="$clients->isEmpty()" :columns="$columnCount">
            <x-slot:head>
                <x-ui.th-sortable column="client_code" :sort="$sort" :direction="$direction">Client ID</x-ui.th-sortable>
                <x-ui.th-sortable column="company_name" :sort="$sort" :direction="$direction">Client</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3">Contact</th>
                <x-ui.th-sortable column="country" :sort="$sort" :direction="$direction">Country</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3">Account manager</th>
                @if ($projectsAvailable)
                    <th scope="col" class="px-4 py-3 text-right">Projects</th>
                @endif
                @if ($showFinancial)
                    <th scope="col" class="px-4 py-3 text-right">Outstanding</th>
                @endif
                <th scope="col" class="px-4 py-3">Portal</th>
                <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($clients as $client)
                @php
                    $manager = $client->relationLoaded('accountManager') ? $client->accountManager : null;
                    $trashed = method_exists($client, 'trashed') && $client->trashed();
                    $displayName = $client->display_name ?? ($client->company_name ?: $client->name);
                    $clientKey = (int) $client->getKey();
                @endphp
                <tr>
                    <td class="whitespace-nowrap font-mono text-xs text-slate-500 dark:text-slate-400">{{ $client->client_code }}</td>
                    <td class="min-w-[14rem]">
                        <div class="flex items-center gap-3">
                            <x-ui.avatar :src="$logoUrl($client)" :name="$displayName" size="sm" />
                            <div class="min-w-0">
                                <a href="{{ route('admin.clients.show', $client) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $displayName }}</a>
                                @if (filled($client->company_name) && $client->name !== $displayName)
                                    <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $client->name }}</span>
                                @endif
                                @if ($trashed)
                                    <x-ui.badge color="rose" size="xs" variant="outline">Trashed</x-ui.badge>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td>@include('admin.crm.partials.contact-links', ['phone' => $client->phone, 'whatsapp' => $client->whatsapp, 'email' => $client->email, 'name' => $displayName, 'whatsappTemplate' => $whatsappTemplate ?? null, 'size' => 'xs'])</td>
                    <td class="whitespace-nowrap text-sm">{{ $client->country ?: '—' }}</td>
                    <td class="whitespace-nowrap">
                        @if ($manager)
                            <span class="flex items-center gap-2"><x-ui.avatar :src="$manager->avatar_url ?? null" :name="$manager->name" size="xs" /> <span class="max-w-[8rem] truncate text-sm">{{ $manager->name }}</span></span>
                        @else
                            <span class="text-xs text-slate-400 dark:text-slate-500">—</span>
                        @endif
                    </td>
                    @if ($projectsAvailable)
                        <td class="whitespace-nowrap text-right tabular-nums">{{ app_number((int) ($projectCounts[$clientKey] ?? 0)) }}</td>
                    @endif
                    @if ($showFinancial)
                        <td class="whitespace-nowrap text-right tabular-nums">
                            @if (array_key_exists($clientKey, $outstanding) && $outstanding[$clientKey] !== null)
                                {{ money((string) $outstanding[$clientKey]) }}
                            @else
                                <span class="text-slate-400 dark:text-slate-500" title="Available once invoicing is in use">—</span>
                            @endif
                        </td>
                    @endif
                    <td class="whitespace-nowrap">@include('admin.clients.partials.portal-chip', ['client' => $client, 'size' => 'xs'])</td>
                    <td class="whitespace-nowrap">@include('admin.crm.partials.enum-badge', ['value' => $client->status])</td>
                    <td>
                        <div class="flex items-center justify-end gap-1">
                            @if ($trashed)
                                @if ($user?->can('clients.restore') && Route::has('admin.clients.restore'))
                                    <form method="POST" action="{{ route('admin.clients.restore', $client) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" variant="secondary" icon="arrow-path">Restore</x-ui.button>
                                    </form>
                                @endif
                            @else
                                <x-ui.icon-button icon="eye" size="sm" :href="route('admin.clients.show', $client)" :label="'Open '.$displayName" />
                                @if ($user?->can('update', $client))
                                    <x-ui.icon-button icon="pencil" size="sm" :href="route('admin.clients.edit', $client)" :label="'Edit '.$displayName" />
                                @endif
                                @if ($user?->can('delete', $client))
                                    <x-ui.confirm
                                        :action="route('admin.clients.destroy', $client)"
                                        :title="'Delete '.$displayName.'?'"
                                        message="The client moves to the trash and its portal is switched off first. A client with a project, invoice or payment cannot be deleted. The client ID is never reused."
                                        confirm-label="Delete client"
                                        id="client-delete-{{ $clientKey }}"
                                    >
                                        <x-slot:trigger>
                                            <x-ui.icon-button icon="trash" variant="danger" size="sm" :label="'Delete '.$displayName" />
                                        </x-slot:trigger>
                                        <div class="mt-3">
                                            <label for="client-delete-reason-{{ $clientKey }}" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Reason <span class="text-rose-500">*</span></label>
                                            <input id="client-delete-reason-{{ $clientKey }}" type="text" name="reason" form="client-delete-{{ $clientKey }}" required maxlength="255" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                        </div>
                                    </x-ui.confirm>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                @if ($filtered)
                    <x-ui.empty-state icon="building-office" title="No clients match these filters">
                        <x-slot:action>
                            <x-ui.button variant="secondary" :href="route('admin.clients.index')">Clear filters</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="building-office" title="No clients yet" message="Winning a lead and converting it creates a client, or you can add one by hand.">
                        @if ($canCreate)
                            <x-slot:action>
                                <x-ui.button icon="plus" :href="route('admin.clients.create')">Add client</x-ui.button>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                @endif
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$clients" label="clients" />
            </x-slot:footer>
        </x-ui.table>
    </div>
@endsection
