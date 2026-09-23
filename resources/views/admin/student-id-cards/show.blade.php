@extends('layouts.admin')

@section('title', $card->card_number)

@section('header')
    <x-ui.page-header :title="$card->card_number"
                      :subtitle="$card->student_name_snapshot.' · '.($card->course_name_snapshot ?? 'No course on the card')"
                      icon="identification">
        <x-slot:actions>
            @if ($canPrint)
                <x-ui.button variant="ghost" icon="printer" target="_blank"
                             :href="route('admin.student-id-cards.print', $card)">Print</x-ui.button>
            @endif
            <x-ui.button variant="ghost" :href="route('admin.student-id-cards.index')">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($card->status === \App\Enums\IdCardStatus::Revoked && $card->revocation_reason)
        <div class="mb-4 rounded-lg border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-200">
            <p class="font-medium">
                Revoked {{ app_datetime($card->revoked_at) }}@if ($card->revoker), by {{ $card->revoker->name }}@endif.
            </p>
            <p class="mt-1">{{ $card->revocation_reason }}</p>
        </div>
    @elseif ($card->status === \App\Enums\IdCardStatus::Replaced && $card->replacedBy)
        <div class="mb-4 rounded-lg border border-slate-300 bg-slate-50 p-3 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
            Superseded by
            <a href="{{ route('admin.student-id-cards.show', $card->replacedBy) }}"
               class="font-mono font-medium underline">{{ $card->replacedBy->card_number }}</a>,
            which is the live card.
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card>
                <x-ui.section-heading title="What is printed on it"
                                      subtitle="Snapshots, frozen when the card was issued. A card in a wallet must not change because a record did (INV-21-4)." />

                <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    @foreach ([
                        'Student' => $card->student_name_snapshot,
                        'Roll number' => $card->student_code_snapshot,
                        'Father’s name' => $card->father_name_snapshot,
                        'Registration' => $card->registration_number_snapshot,
                        'Course' => $card->course_name_snapshot,
                        'Batch' => $card->batch_name_snapshot,
                        'Joined' => app_date($card->joining_date_snapshot),
                        'Guardian’s phone' => $card->guardian_phone_snapshot,
                    ] as $label => $value)
                        <div class="flex justify-between gap-3 border-b border-slate-100 py-1 dark:border-slate-800">
                            <dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-200">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($card->notes)
                    <p class="mt-3 rounded-lg bg-slate-50 p-2 text-xs text-slate-600 dark:bg-slate-900 dark:text-slate-300">
                        {{ $card->notes }}
                    </p>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card>
                <x-ui.section-heading title="The record" />

                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Status</dt>
                        <dd><x-ui.badge :color="$card->status->color()" size="xs">{{ $card->status->label() }}</x-ui.badge></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Verification code</dt>
                        <dd class="font-mono text-xs text-slate-700 dark:text-slate-200">{{ $displayCode }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Issued</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ app_date($card->issued_on) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Valid until</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            {{ $card->valid_until ? app_date($card->valid_until) : 'No expiry' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Branch</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $card->branch?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Template</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $card->template?->name ?? 'The default' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Photograph</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $card->photo_path ? 'Its own copy' : 'None' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Printed</dt>
                        <dd class="tabular-nums text-slate-700 dark:text-slate-200">
                            {{ app_number($card->print_count) }}×
                            @if ($card->lastPrinter)
                                <div class="text-xs text-slate-400">last by {{ $card->lastPrinter->name }}</div>
                            @endif
                        </dd>
                    </div>
                    @if ($card->replacementOf)
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Replaces</dt>
                            <dd class="font-mono text-xs text-slate-700 dark:text-slate-200">
                                {{ $card->replacementOf->card_number }}
                            </dd>
                        </div>
                    @endif
                </dl>

                @if ($card->replacement_reason)
                    <p class="mt-3 rounded-lg bg-slate-50 p-2 text-xs text-slate-600 dark:bg-slate-900 dark:text-slate-300">
                        {{ $card->replacement_reason }}
                    </p>
                @endif
            </x-ui.card>

            <x-ui.card>
                <x-ui.section-heading title="Actions"
                                      subtitle="A card is never deleted. Every row here is a card that existed in the world." />

                <div class="mt-3 space-y-2">
                    @if ($canChangeStatus && $card->status === \App\Enums\IdCardStatus::Active)
                        <x-ui.button type="button" variant="secondary" icon="exclamation-triangle" class="w-full"
                                     x-on:click="$dispatch('open-modal', 'card-status')">Mark it lost, damaged or revoked</x-ui.button>
                    @endif

                    @if ($canReplace)
                        <x-ui.button type="button" icon="arrow-path" class="w-full"
                                     x-on:click="$dispatch('open-modal', 'replace-card')">Issue a replacement</x-ui.button>
                    @endif

                    @if (! $canChangeStatus && ! $canReplace)
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            You can read this card but not change it.
                        </p>
                    @endif
                </div>
            </x-ui.card>
        </div>
    </div>

    @if ($canChangeStatus && $card->status === \App\Enums\IdCardStatus::Active)
        <x-ui.modal name="card-status" title="What has happened to this card?" icon="exclamation-triangle">
            <form method="POST" action="{{ route('admin.student-id-cards.status', $card) }}">
                @csrf

                <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">
                    The card stops being the student's live card. A replacement can then be issued —
                    except after a revocation, which is a decision not to hold one.
                </p>

                <x-ui.form.select name="status" label="New status" required>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }} — {{ $status->description() }}</option>
                    @endforeach
                </x-ui.form.select>

                <div class="mt-3">
                    <x-ui.form.input name="reason" label="Reason" optional
                                     placeholder="Reported lost on the bus" />
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost"
                                 x-on:click="$dispatch('close-modal', 'card-status')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="danger" icon="check">Record it</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($canReplace)
        <x-ui.modal name="replace-card" title="Issue a replacement card?" icon="arrow-path">
            <form method="POST" action="{{ route('admin.student-id-cards.replace', $card) }}">
                @csrf

                <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">
                    A new card is numbered and issued, pointing back at this one, and this one is marked
                    replaced. Both stay on the register — the register is a history, not a list of what is
                    currently in wallets.
                </p>

                <x-ui.form.input name="reason" label="Why" required
                                 placeholder="Card snapped in half" />

                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost"
                                 x-on:click="$dispatch('close-modal', 'replace-card')">Cancel</x-ui.button>
                    <x-ui.button type="submit" icon="arrow-path">Issue the replacement</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
