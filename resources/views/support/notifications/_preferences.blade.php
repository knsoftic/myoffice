{{--
    The §97 preference screen, shared by every panel (phase-19-23 §6.19).

    **Only events this person could actually receive are listed.** `NotificationRegistry::forUser()`
    filters on the module being enabled, the panels the event reaches and the permissions it needs —
    so a student is never offered "a wallet disagrees with its ledger" to mute, and never has to
    wonder why a switch they set does nothing.

    **A locked row is a mandatory event**, and the list of those is three or four long on purpose: a
    reversed commission, a paid payout, a breached target, a revoked certificate. Those are the cases
    where "I was never told" is a dispute rather than an inconvenience. A screen where most rows were
    locked would be a screen nobody believed.

    **The mail column is disabled until the installation has a mail server.** A checkbox that saves
    and then never sends is worse than one that is greyed out with a sentence saying why.
--}}

@section('content')
    <form method="POST" action="{{ route($panel.'.notifications.preferences.save') }}" class="space-y-4">
        @csrf
        @method('PUT')

        @unless ($matrix->mailAvailable)
            <div class="rounded-lg border border-slate-300 bg-slate-50 p-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
                Email is not switched on for this installation, so the mail column is disabled. Everything still
                arrives in the bell.
            </div>
        @endunless

        @forelse ($matrix->grouped() as $group => $rows)
            <x-ui.card :padded="false">
                <div class="border-b border-slate-200 p-4 dark:border-slate-700">
                    <x-ui.section-heading :title="\App\Enums\NotificationGroup::from($group)->label()"
                                          :description="\App\Enums\NotificationGroup::from($group)->description()" />
                </div>

                <x-ui.table :is-empty="false">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Event</th>
                        <th class="px-4 py-3 text-center font-semibold">Bell</th>
                        <th class="px-4 py-3 text-center font-semibold">Email</th>
                        <th class="px-4 py-3 text-left font-semibold">How often</th>
                    </x-slot:head>

                    @foreach ($rows as $key => $row)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="text-sm font-medium text-slate-700 dark:text-slate-200">
                                    {{ $row['event']->title }}
                                    @if ($row['locked'])
                                        <x-ui.badge color="rose" size="sm">Always on</x-ui.badge>
                                    @endif
                                </div>
                                <p class="text-xs text-slate-400">{{ $row['event']->description }}</p>
                            </td>

                            <td class="px-4 py-3 text-center">
                                <input type="hidden" name="events[{{ $key }}][database]" value="0">
                                <input type="checkbox" name="events[{{ $key }}][database]" value="1"
                                       {{-- The column header names the channel and the row names the event, and a
                                            screen reader carries neither into the cell. --}}
                                       aria-label="In-app notifications for {{ $row['event']->label() }}"
                                       @checked($row['database']) @disabled($row['locked'])
                                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 disabled:opacity-50 dark:border-slate-600 dark:bg-slate-800">
                            </td>

                            <td class="px-4 py-3 text-center">
                                <input type="hidden" name="events[{{ $key }}][mail]" value="0">
                                <input type="checkbox" name="events[{{ $key }}][mail]" value="1"
                                       {{-- The column header names the channel and the row names the event, and a
                                            screen reader carries neither into the cell. --}}
                                       aria-label="Email notifications for {{ $row['event']->label() }}"
                                       @checked($row['mail']) @disabled(! $matrix->mailAvailable)
                                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 disabled:opacity-50 dark:border-slate-600 dark:bg-slate-800">
                            </td>

                            <td class="px-4 py-3">
                                <select name="events[{{ $key }}][digest]"
                                        aria-label="Email digest for {{ $row['event']->label() }}"
                                        @disabled(! $matrix->mailAvailable)
                                        class="h-9 rounded-lg border-slate-300 text-sm disabled:opacity-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                                    @foreach (\App\Enums\NotificationDigest::cases() as $digest)
                                        <option value="{{ $digest->value }}" @selected($row['digest'] === $digest)>{{ $digest->label() }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @empty
            <x-ui.card>
                <x-ui.empty-state icon="bell-slash" title="Nothing to set"
                                  description="No notifiable event reaches this account yet." />
            </x-ui.card>
        @endforelse

        @if (! $matrix->isEmpty())
            <div class="flex items-center justify-between gap-2">
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ app_number($matrix->customisedCount()) }} of {{ app_number($matrix->count()) }} changed from the defaults.
                </p>

                <div class="flex gap-2">
                    <x-ui.button variant="ghost" size="sm" :href="route($panel.'.notifications.index')">Back</x-ui.button>
                    <x-ui.button type="submit">Save preferences</x-ui.button>
                </div>
            </div>
        @endif
    </form>

    @if ($matrix->customisedCount() > 0)
        <form method="POST" action="{{ route($panel.'.notifications.preferences.reset') }}" class="mt-3 flex justify-end">
            @csrf
            <x-ui.button type="submit" variant="ghost" size="sm">Back to the defaults</x-ui.button>
        </form>
    @endif
@endsection
