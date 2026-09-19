{{--
    The client page header (phase-05 §8.8 "Header"), shared by admin/clients/show, admin/clients/documents/index and
    admin/clients/financials: Client ID, company, status badge, account manager, portal chip; actions Edit, Change status,
    Assign account manager, Enable / Disable portal, Print, Export, Delete — each rendered only when the policy allows.

    @include('admin.clients.partials.header', ['client' => $client])
    @include('admin.clients.partials.header', ['client' => $client, 'compact' => true])   // a sub-page: Edit, Print, Export only

    The dialogs these actions open live in admin/clients/partials/dialogs (include it once on the full page; a compact
    header opens no dialog). Delete: DELETE admin.clients.destroy {client} {reason}.
--}}

@php
    use Illuminate\Support\Facades\Route;

    $headerCompact = (bool) ($compact ?? false);
    $headerUser = auth()->user();
    $headerManager = $client->relationLoaded('accountManager') ? $client->accountManager : null;
    $headerName = $client->display_name ?? ($client->company_name ?: $client->name);
    $headerTrashed = method_exists($client, 'trashed') && $client->trashed();
    $headerLogo = filled($client->logo_path) ? \Illuminate\Support\Facades\Storage::disk('public')->url((string) $client->logo_path) : null;
    $headerStatus = $client->status instanceof \BackedEnum ? $client->status->value : (string) $client->status;

    $headerCanUpdate = ! $headerTrashed && (bool) $headerUser?->can('update', $client);
    $headerCanStatus = ! $headerCompact && ! $headerTrashed && (bool) $headerUser?->can('changeStatus', $client) && Route::has('admin.clients.status');
    $headerCanAssign = ! $headerCompact && ! $headerTrashed && (bool) $headerUser?->can('assign', $client) && Route::has('admin.clients.account-manager');
    $headerCanPortal = ! $headerCompact && ! $headerTrashed && (bool) $headerUser?->can('managePortal', $client) && Route::has('admin.clients.portal.enable');
    $headerCanPrint = (bool) $headerUser?->can('clients.print') && Route::has('admin.clients.print');
    $headerCanExport = (bool) $headerUser?->can('clients.export') && Route::has('admin.clients.export');
    $headerCanDelete = ! $headerCompact && ! $headerTrashed && (bool) $headerUser?->can('delete', $client);
    $headerCanRestore = ! $headerCompact && $headerTrashed && (bool) $headerUser?->can('clients.restore') && Route::has('admin.clients.restore');
@endphp

<x-ui.page-header :title="$headerName" :subtitle="collect([$client->client_code, filled($client->company_name) && $client->name !== $headerName ? $client->name : null])->filter()->implode(' · ')" icon="building-office" :back="route('admin.clients.index')">
    <div class="mt-2 flex flex-wrap items-center gap-2">
        @if ($headerLogo)
            <x-ui.avatar :src="$headerLogo" :name="$headerName" size="xs" />
        @endif
        @include('admin.crm.partials.enum-badge', ['value' => $client->status])
        @include('admin.clients.partials.portal-chip', ['client' => $client, 'size' => 'sm'])
        @if ($headerManager)
            <span class="inline-flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
                <x-ui.avatar :src="$headerManager->avatar_url ?? null" :name="$headerManager->name" size="xs" /> {{ $headerManager->name }}
            </span>
        @else
            <span class="text-xs text-slate-400 dark:text-slate-500">No account manager</span>
        @endif
        @if ($headerTrashed)
            <x-ui.badge color="rose" size="sm" icon="trash">In the trash</x-ui.badge>
        @endif
        @if (filled($client->status_reason) && $headerStatus !== 'active')
            <span class="text-xs text-slate-500 dark:text-slate-400">— {{ $client->status_reason }}</span>
        @endif
    </div>

    <x-slot:actions>
        @if ($headerCanRestore)
            <form method="POST" action="{{ route('admin.clients.restore', $client) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" icon="arrow-path">Restore</x-ui.button>
            </form>
        @endif
        @if ($headerCanUpdate)
            <x-ui.button variant="secondary" icon="pencil" :href="route('admin.clients.edit', $client)">Edit</x-ui.button>
        @endif
        @if ($headerCanStatus || $headerCanAssign || $headerCanPortal || $headerCanPrint || $headerCanExport)
            <x-ui.dropdown label="More actions">
                @if ($headerCanStatus)
                    <x-ui.dropdown-item icon="arrow-path" x-on:click="open = false; $dispatch('open-modal', 'client-status')">Change status</x-ui.dropdown-item>
                @endif
                @if ($headerCanAssign)
                    <x-ui.dropdown-item icon="user-plus" x-on:click="open = false; $dispatch('open-modal', 'client-account-manager')">Assign account manager</x-ui.dropdown-item>
                @endif
                @if ($headerCanPortal)
                    @if ($client->portal_enabled)
                        <x-ui.dropdown-item icon="envelope" x-on:click="open = false; $dispatch('open-modal', 'client-portal-enable')">Resend portal invite</x-ui.dropdown-item>
                        <x-ui.dropdown-item icon="lock-closed" variant="danger" x-on:click="open = false; $dispatch('open-modal', 'client-portal-disable')">Disable portal</x-ui.dropdown-item>
                    @else
                        <x-ui.dropdown-item icon="lock-open" x-on:click="open = false; $dispatch('open-modal', 'client-portal-enable')">Enable portal</x-ui.dropdown-item>
                    @endif
                @endif
                @if ($headerCanPrint)
                    <x-ui.dropdown-item icon="printer" :href="route('admin.clients.print', $client)" target="_blank" rel="noopener">Print</x-ui.dropdown-item>
                @endif
                @if ($headerCanExport)
                    <x-ui.dropdown-item icon="arrow-down-tray" :href="route('admin.clients.export', ['ids' => [$client->getKey()]])">Export</x-ui.dropdown-item>
                @endif
            </x-ui.dropdown>
        @endif
        @if ($headerCanDelete)
            <x-ui.confirm
                :action="route('admin.clients.destroy', $client)"
                :title="'Delete '.$headerName.'?'"
                message="The client moves to the trash and its portal is switched off first. A client with a project, invoice or payment cannot be deleted. The client ID is never reused."
                confirm-label="Delete client"
                id="client-delete-form"
            >
                <x-slot:trigger>
                    <x-ui.icon-button icon="trash" variant="danger" label="Delete client" />
                </x-slot:trigger>
                <div class="mt-3">
                    <label for="client-delete-reason" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Reason <span class="text-rose-500">*</span></label>
                    <input id="client-delete-reason" type="text" name="reason" form="client-delete-form" required maxlength="255" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                </div>
            </x-ui.confirm>
        @endif
    </x-slot:actions>
</x-ui.page-header>
