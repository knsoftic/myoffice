@extends('layouts.admin')

@section('title', 'Support desks')

{{--
    The desks — admin.ticket-departments.index (phase-19-23 §7.6, §6.16).

    One screen carries the list and the form. A desk is five fields an administrator touches twice a
    year; a separate create page and edit page would be two more screens nobody reads. The row being
    edited comes back through ?edit=, so a validation failure returns with the right one open.

    **Delete is offered only for a desk that has never held a ticket.** The policy answers that, so
    the button is absent rather than present-and-failing — and "retire" sits beside it, which is what
    somebody reaching for delete almost always means.
--}}

@section('header')
    <x-ui.page-header title="Support desks"
                      subtitle="Where tickets are filed, who picks them up, and how long the desk has promised to take."
                      icon="building-office">
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.card class="mb-4">
                <form method="GET" class="grid gap-3 sm:grid-cols-3">
                    <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Name or slug" />

                    <x-ui.form.select name="status" label="Status" placeholder="Any">
                        <option value="active" @selected(request('status') === 'active')>Open</option>
                        <option value="retired" @selected(request('status') === 'retired')>Retired</option>
                    </x-ui.form.select>

                    <div class="flex items-end gap-2">
                        <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                        <x-ui.button variant="ghost" :href="route('admin.ticket-departments.index')">Clear</x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <x-ui.card :padded="false">
                <x-ui.table :is-empty="$departments->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Desk</th>
                        <th class="px-4 py-3 text-left font-semibold">Open to</th>
                        <th class="px-4 py-3 text-left font-semibold">Assignment</th>
                        <th class="px-4 py-3 text-right font-semibold">Tickets</th>
                        <th class="px-4 py-3 text-right font-semibold">Actions</th>
                    </x-slot:head>

                    @foreach ($departments as $department)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $department->name }}</div>
                                <div class="text-xs text-slate-400">
                                    {{ $department->slug }}
                                    @unless ($department->is_active)
                                        · <span class="text-amber-500">retired</span>
                                    @endunless
                                </div>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                                @foreach ($department->panels() as $panel)
                                    <x-ui.badge :color="$panel->color()" size="sm">{{ $panel->label() }}</x-ui.badge>
                                @endforeach
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                {{ $department->auto_assign_strategy->label() }}
                                @if ($department->defaultAssignee)
                                    <div class="text-xs text-slate-400">{{ $department->defaultAssignee->name }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                                {{ app_number($department->tickets_count) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-1">
                                    <x-ui.button size="sm" variant="ghost"
                                                 :href="route('admin.ticket-departments.index', ['edit' => $department->id])">Edit</x-ui.button>

                                    <form method="POST" action="{{ route('admin.ticket-departments.status', $department) }}">
                                        @csrf
                                        <input type="hidden" name="is_active" value="{{ $department->is_active ? 0 : 1 }}">
                                        <x-ui.button size="sm" variant="ghost" type="submit">
                                            {{ $department->is_active ? 'Retire' : 'Reopen' }}
                                        </x-ui.button>
                                    </form>

                                    @can('delete', $department)
                                        <x-ui.confirm :action="route('admin.ticket-departments.destroy', $department)"
                                                      method="DELETE"
                                                      title="Delete this desk?"
                                                      message="It has never held a ticket, so nothing points at it. A desk that has held one is retired instead."
                                                      confirm-label="Delete desk">
                                            <x-slot:trigger>
                                                <x-ui.button size="sm" variant="ghost">Delete</x-ui.button>
                                            </x-slot:trigger>
                                        </x-ui.confirm>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="building-office" title="No desks yet"
                                          description="Add one on the right. Every ticket is filed against a desk, so there has to be at least one." />
                    </x-slot:empty>
                </x-ui.table>

                <x-ui.pagination-summary :paginator="$departments" label="desks" />
            </x-ui.card>
        </div>

        <div>
            <x-ui.card>
                <x-ui.section-heading :title="$editing ? 'Edit '.$editing->name : 'New desk'" />

                <form method="POST"
                      action="{{ $editing ? route('admin.ticket-departments.update', $editing) : route('admin.ticket-departments.store') }}"
                      class="mt-3 space-y-3">
                    @csrf
                    @if ($editing)
                        @method('PUT')
                    @endif

                    <x-ui.form.input name="name" label="Name" :value="old('name', $editing?->name)" required />
                    <x-ui.form.input name="slug" label="Slug" :value="old('slug', $editing?->slug)"
                                     help="Left blank, it is derived from the name." />
                    <x-ui.form.input type="email" name="email" label="Inbox" :value="old('email', $editing?->email)" />

                    <fieldset>
                        <legend class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Open to</legend>
                        <div class="space-y-1">
                            @foreach ($panels as $panel)
                                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                    <input type="checkbox" name="allowed_panels[]" value="{{ $panel->value }}"
                                           @checked(in_array($panel->value, old('allowed_panels', $editing ? array_map(fn ($p) => $p->value, $editing->panels()) : []), true))
                                           class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                                    {{ $panel->label() }}
                                </label>
                            @endforeach
                        </div>
                        <x-ui.form.error name="allowed_panels" />
                    </fieldset>

                    <x-ui.form.select name="auto_assign_strategy" label="Assignment" required>
                        @foreach ($strategies as $strategy)
                            <option value="{{ $strategy->value }}"
                                    @selected(old('auto_assign_strategy', $editing?->auto_assign_strategy?->value) === $strategy->value)>
                                {{ $strategy->label() }}
                            </option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.select name="default_assignee_id" label="Default assignee" placeholder="Nobody">
                        @foreach ($agents as $agent)
                            <option value="{{ $agent->id }}"
                                    @selected((int) old('default_assignee_id', $editing?->default_assignee_id) === (int) $agent->id)>
                                {{ $agent->name }}
                            </option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.input type="number" name="sla_first_response_minutes" label="First reply within (minutes)"
                                     :value="old('sla_first_response_minutes', $editing?->sla_first_response_minutes)"
                                     help="Blank means no target." />

                    <x-ui.form.input type="number" name="sla_resolution_minutes" label="Resolve within (minutes)"
                                     :value="old('sla_resolution_minutes', $editing?->sla_resolution_minutes)" />

                    <div class="flex justify-end gap-2">
                        @if ($editing)
                            <x-ui.button variant="ghost" :href="route('admin.ticket-departments.index')">Cancel</x-ui.button>
                        @endif
                        <x-ui.button type="submit">{{ $editing ? 'Save' : 'Create desk' }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>
    </div>
@endsection
