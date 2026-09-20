@extends('layouts.admin')

@section('title', 'Collaborator applications')

@php
    $user = auth()->user();
    $canApprove = (bool) $user?->can('collaborators.approve');
    $canReject = (bool) $user?->can('collaborators.reject');
@endphp

@section('header')
    <x-ui.page-header title="Applications" subtitle="Partners waiting for a decision — oldest first." icon="inbox-arrow-down">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.collaborators.index')" icon="user-group">All collaborators</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card :title="$collaborators->total() . ' waiting'"
               subtitle="An application older than {{ $alertDays }} day(s) is flagged.">
        <x-ui.table :is-empty="$collaborators->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Applicant</th>
                <th class="px-4 py-3 text-left font-semibold">Type</th>
                <th class="px-4 py-3 text-left font-semibold">Contact</th>
                <th class="px-4 py-3 text-left font-semibold">Waiting since</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($collaborators as $collaborator)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.collaborators.show', $collaborator) }}"
                           class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                            {{ $collaborator->displayName() }}
                        </a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ $collaborator->collaborator_code }} · {{ $collaborator->skills_count }} skill(s)
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $collaborator->collaboration_type->label() }}</td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                        <span class="block">{{ $collaborator->email ?? 'no email — no panel account' }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $collaborator->phone }}</span>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                        <span class="block tabular-nums">{{ $collaborator->applied_at ? app_datetime($collaborator->applied_at) : '—' }}</span>
                        @if ($collaborator->isStaleApplication())
                            <x-ui.badge color="amber" size="xs">Waiting too long</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            @if ($canApprove)
                                <x-ui.button variant="ghost" size="sm" icon="check"
                                    x-on:click="$dispatch('open-modal', 'approve-{{ $collaborator->id }}')">Approve</x-ui.button>
                            @endif
                            @if ($canReject)
                                <x-ui.button variant="ghost" size="sm" icon="x-mark"
                                    x-on:click="$dispatch('open-modal', 'reject-{{ $collaborator->id }}')">Refuse</x-ui.button>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="inbox-arrow-down" title="Nothing waiting"
                    description="Every application has been decided." />
            </x-slot:empty>
        </x-ui.table>

        @if ($collaborators->hasPages())
            <div class="mt-4">{{ $collaborators->links() }}</div>
        @endif
    </x-ui.card>

    @foreach ($collaborators as $collaborator)
        @if ($canApprove)
            <x-ui.modal :name="'approve-' . $collaborator->id" title="Approve {{ $collaborator->displayName() }}" icon="check">
                <form method="POST" action="{{ route('admin.collaborators.approve', $collaborator) }}" class="space-y-3">
                    @csrf
                    <x-ui.form.input type="date" name="joining_date" label="Joining date"
                        :value="app_date($collaborator->joining_date ?? now(), 'Y-m-d')" />
                    <x-ui.form.textarea name="comment" label="Note" rows="2" maxlength="255" />
                    @if ($collaborator->email)
                        <x-ui.form.checkbox name="provision_login" label="Create a panel account and send the invitation" :checked="true" with-hidden />
                    @else
                        <x-ui.form.help>
                            No email address on the profile, so no panel account can be created. Add one first if this
                            partner should be able to sign in.
                        </x-ui.form.help>
                    @endif
                    <x-ui.button type="submit" class="w-full" icon="check">Approve</x-ui.button>
                </form>
            </x-ui.modal>
        @endif

        @if ($canReject)
            <x-ui.modal :name="'reject-' . $collaborator->id" title="Refuse {{ $collaborator->displayName() }}" icon="x-mark">
                <form method="POST" action="{{ route('admin.collaborators.reject', $collaborator) }}" class="space-y-3">
                    @csrf
                    <x-ui.form.textarea name="reason" label="Why" rows="2" required maxlength="255"
                        help="Mandatory. The record stays and becomes inactive — the reason is what explains it later." />
                    <x-ui.button type="submit" variant="secondary" class="w-full">Refuse application</x-ui.button>
                </form>
            </x-ui.modal>
        @endif
    @endforeach
@endsection
