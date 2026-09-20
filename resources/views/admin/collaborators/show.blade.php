@extends('layouts.admin')

@section('title', $collaborator->displayName())

@php
    $canDelete = (bool) auth()->user()?->can('delete', $collaborator);
    $canRestore = (bool) auth()->user()?->can('restore', $collaborator);
    $canCreateUser = $canEdit && (bool) auth()->user()?->can('users.create');
@endphp

@section('header')
    <x-ui.page-header :title="$collaborator->displayName()" :subtitle="$collaborator->collaborator_code" icon="user-group">
        <x-slot:actions>
            @if ($canSeeLogs)
                <x-ui.button variant="ghost" :href="route('admin.collaborators.activity', $collaborator)" icon="clock">Activity</x-ui.button>
            @endif
            <x-ui.button variant="ghost" :href="route('admin.collaborators.referral-links', $collaborator)" icon="link">Referral links</x-ui.button>
            @if ($canEdit)
                <x-ui.button variant="secondary" :href="route('admin.collaborators.edit', $collaborator)" icon="pencil">Edit</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="The record">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Status</dt>
                        <dd class="mt-1">
                            <x-ui.badge :color="$collaborator->status->color()" size="xs">{{ $collaborator->status->label() }}</x-ui.badge>
                            @if ($collaborator->trashed())
                                <x-ui.badge color="slate" size="xs">removed</x-ui.badge>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Type</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $collaborator->collaboration_type->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Joined</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">
                            {{ $collaborator->joining_date ? app_date($collaborator->joining_date) : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Contact</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $collaborator->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Email</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $collaborator->email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Phone</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">
                            {{ $collaborator->phone ?? '—' }}
                            @if ($collaborator->whatsapp) · WhatsApp {{ $collaborator->whatsapp }} @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Country</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $collaborator->country ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Address</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $collaborator->address ?? '—' }}</dd>
                    </div>
                    @if ($collaborator->status_reason)
                        <div class="sm:col-span-3">
                            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                {{ $collaborator->status->requiresReason() ? 'Why they are ' . $collaborator->status->label() : 'Note' }}
                            </dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $collaborator->status_reason }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            <x-ui.card title="What they do">
                @if ($collaborator->skills->isEmpty() && (! isset($collaborator->services) || $collaborator->services->isEmpty()))
                    <x-ui.empty-state icon="sparkles" title="Nothing recorded yet"
                        description="Skills and services make a partner findable when work comes in." />
                @else
                    @if ($collaborator->skills->isNotEmpty())
                        <div class="mb-3">
                            <span class="block text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Skills</span>
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach ($collaborator->skills as $skill)
                                    <x-ui.badge color="slate" size="xs">{{ $skill->name }}</x-ui.badge>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($collaborator->relationLoaded('services') && $collaborator->services->isNotEmpty())
                        <div>
                            <span class="block text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Services offered</span>
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach ($collaborator->services as $service)
                                    <x-ui.badge color="sky" size="xs">{{ $service->name }}</x-ui.badge>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif
            </x-ui.card>

            @if ($collaborator->notes)
                <x-ui.card title="Internal notes" subtitle="Never shown in the collaborator's own panel.">
                    <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $collaborator->notes }}</p>
                </x-ui.card>
            @endif

            @unless ($spineInstalled)
                <x-ui.card title="Commission, wallet and payouts">
                    <x-ui.empty-state icon="wallet" title="Not installed yet"
                        description="The commission engine ships with a later phase. Nothing is missing from this record — there is simply nothing yet to show, and a figure of 0.00 here would be a statement about money this screen has no right to make." />
                </x-ui.card>
            @endunless
        </div>

        <div class="space-y-4">
            <x-ui.card title="Referral code">
                <p class="font-mono text-lg font-semibold text-slate-900 dark:text-white">{{ $collaborator->referral_code }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    @if ($codeIsLocked)
                        Fixed — a visit, a referral or a commission entry already names it.
                    @elseif (! $codeIsEditable)
                        Fixed for this installation.
                    @else
                        Can still be changed; nothing references it yet.
                    @endif
                </p>

                @if ($canEdit && $codeIsEditable && ! $codeIsLocked)
                    <form method="POST" action="{{ route('admin.collaborators.referral-code', $collaborator) }}" class="mt-3 space-y-3">
                        @csrf
                        <x-ui.form.input name="referral_code" label="New code" maxlength="32" required />
                        <x-ui.form.input name="reason" label="Why" maxlength="255" required />
                        <x-ui.button type="submit" variant="secondary" class="w-full">Change code</x-ui.button>
                    </form>
                @endif
            </x-ui.card>

            <x-ui.card title="Panel account">
                @if ($collaborator->account)
                    <p class="text-sm text-slate-900 dark:text-white">{{ $collaborator->account->name }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $collaborator->account->email }}</p>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        {{ $collaborator->canLogin() ? 'Can sign in.' : 'Cannot sign in while they are ' . $collaborator->status->label() . '.' }}
                    </p>
                @elseif ($canCreateUser && $collaborator->email)
                    <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                        No login yet. Creating one sends an invitation to {{ $collaborator->email }} — nobody here ever
                        sees or sets their password.
                    </p>
                    <form method="POST" action="{{ route('admin.collaborators.user.store', $collaborator) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" class="w-full" icon="key">Create panel account</x-ui.button>
                    </form>
                @else
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        No login. {{ $collaborator->email ? 'A record without one is perfectly legal.' : 'An email address is needed first.' }}
                    </p>
                @endif
            </x-ui.card>

            @if ($canApprove && $collaborator->status === \App\Enums\CollaboratorStatus::Pending)
                <x-ui.card title="Approve">
                    <form method="POST" action="{{ route('admin.collaborators.approve', $collaborator) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.input type="date" name="joining_date" label="Joining date"
                            :value="app_date($collaborator->joining_date ?? now(), 'Y-m-d')" />
                        <x-ui.form.textarea name="comment" label="Note" rows="2" maxlength="255" />
                        <x-ui.button type="submit" class="w-full" icon="check">Approve</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($canChangeStatus && $transitions !== [])
                <x-ui.card title="Change status">
                    <form method="POST" action="{{ route('admin.collaborators.status', $collaborator) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.select name="status" label="New status" required>
                            @foreach ($transitions as $target)
                                <option value="{{ $target->value }}">{{ $target->label() }}</option>
                            @endforeach
                        </x-ui.form.select>
                        <x-ui.form.textarea name="reason" label="Why" rows="2" maxlength="255"
                            help="Mandatory when making somebody inactive or suspended — both stop new business flowing to them." />
                        <x-ui.button type="submit" variant="secondary" class="w-full">Save status</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($collaborator->trashed() && $canRestore)
                <x-ui.card title="Restore">
                    <form method="POST" action="{{ route('admin.collaborators.restore', $collaborator) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" class="w-full">Bring this record back</x-ui.button>
                    </form>
                </x-ui.card>
            @elseif ($canDelete)
                <x-ui.card title="Remove">
                    <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                        The record and its history are kept — it only leaves the lists. A partner with commission or a
                        payout still in flight is refused, because removing them would hide a debt rather than clear it.
                    </p>
                    <form method="POST" action="{{ route('admin.collaborators.destroy', $collaborator) }}" class="space-y-3">
                        @csrf
                        @method('DELETE')
                        <x-ui.form.textarea name="reason" label="Why" rows="2" required maxlength="255" />
                        <x-ui.button type="submit" variant="danger" class="w-full">Remove collaborator</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
