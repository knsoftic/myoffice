@extends('layouts.admin')

@section('title', 'Restore from backup')

{{--
    Restore wizard (route admin.backups.restore.create, phase-24-25 section 8.3).

    The most consequential screen in the system. Three steps, one page, stacked vertically on a phone:
    review what will be overwritten, say where it is going and why, then type the phrase.

    What this page is for, stated plainly because it is easy to lose in the markup: **making somebody
    read the number of records they are about to destroy before they destroy them.** Step 1 is not
    decoration. It counts the rows recorded since this archive was taken and says them out loud —
    "47 fee receipts and 12 commission entries" is a sentence somebody stops for, and "data may be
    lost" is not.

    The four gates of section 6.10.5 are enforced elsewhere and none of them is in this file:

      1. `backups.restore` — the route, and `BackupRunPolicy::restore()`, which also refuses an
         unverified archive, a files-only archive and an archive whose file is gone.
      2. `password.confirm` — Laravel's middleware, on both this form and the submit. Reaching this
         page means the operator re-authenticated within the confirmation window.
      3. The typed phrase — compared byte for byte, case included, in `RestoreBackupRequest`. The
         browser check below is a courtesy; the server's is the rule.
      4. The written reason — `min:20` in the same Form Request, stored NOT NULL and immutable.

    The `{database}` substitution in the browser and the one on the server come from a single
    implementation (`RestoreBackupRequest::phraseTemplate()`), because two implementations of a
    confirmation phrase is either a phrase nobody can pass or one anybody can.
--}}

@php
    use App\Support\Format;
    use App\Support\Ops\RetentionPlan;

    $bytes = static fn (?int $value): string => $value === null
        ? '—'
        : RetentionPlan::humanBytes((string) $value);

    $archiveTakenAt = $run->started_at ?? $run->created_at;

    // The live total over section 6.10.4's proof list. `-1` means "that table is not there", which is a
    // finding rather than a count, so it is never added in.
    $liveTotal = 0;
    $missingTables = [];

    foreach ($liveCounts as $table => $count) {
        if ($count < 0) {
            $missingTables[] = $table;

            continue;
        }

        $liveTotal += $count;
    }

    $lossTotal = array_sum(array_column($losses, 'count'));

    $suggestedDatabase = $isProduction ? ($liveDatabase ?? '') : $scratchDatabase;
@endphp

@section('header')
    <x-ui.page-header
        title="Restore from backup"
        :subtitle="'Overwrites a database with the archive taken '.Format::forHumans($archiveTakenAt).'.'"
        icon="arrow-path"
        :back="route('admin.backups.show', $run)"
        badge="Four gates"
        badge-color="rose"
        :breadcrumbs="[
            ['label' => 'Backups', 'url' => route('admin.backups.index')],
            ['label' => $run->filename ?? 'Backup', 'url' => route('admin.backups.show', $run)],
            ['label' => 'Restore'],
        ]"
    />
@endsection

@section('content')
    {{--
        One Alpine scope for the whole wizard. It holds the target, the database name and the typed
        phrase, and it computes the expected phrase from the same template the server will compare
        against. Nothing here authorises anything — see the file header.
    --}}
    <div
        x-data="{
            liveName: @js($liveDatabase ?? ''),
            scratchName: @js($scratchDatabase),
            template: @js($phraseTemplate),
            target: @js(old('target', $defaultTarget)),
            database: @js(old('database_name', $suggestedDatabase)),
            reason: @js(old('reason', '')),
            typed: '',
            acknowledged: {{ old('acknowledge_pre_backup') ? 'true' : 'false' }},
            get isProductionTarget() { return this.target === 'production' },
            get needsPhrase() { return this.target !== 'local' },
            get needsPreBackup() { return this.target !== 'local' },
            get expected() { return this.template.replace('{database}', this.database.trim()) },
            get phraseMatches() { return ! this.needsPhrase || this.typed === this.expected },
            get reasonLongEnough() { return this.reason.trim().length >= 20 },
            get ready() { return this.phraseMatches && this.reasonLongEnough && (! this.needsPreBackup || this.acknowledged) },
            suggestDatabase() { this.database = this.isProductionTarget ? this.liveName : this.scratchName },
        }"
        class="space-y-4"
    >

        {{-- ── The warning that is the point of the screen ────────────────────────────── --}}
        <div class="rounded-xl bg-rose-50 p-4 text-sm text-rose-800 ring-1 ring-rose-200/70 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/20">
            <div class="flex items-start gap-2.5">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0" />
                <div class="min-w-0">
                    <p class="font-semibold">A restore replaces a database. It does not merge.</p>
                    <p class="mt-1">
                        Everything recorded after
                        <strong>{{ app_datetime($archiveTakenAt) }}</strong>
                        is gone from the database you restore into — receipts, commission entries, admissions,
                        attendance, the lot. A safety copy is taken first and the restore aborts if that copy
                        fails, so there is always a way back; the way back is not the same as no loss.
                    </p>
                </div>
            </div>
        </div>

        {{-- ══════════════════ Step 1 — Review ══════════════════════════════════════════ --}}
        <x-ui.card
            title="Step 1 · What will be overwritten"
            subtitle="The archive on one side, the database as it stands on the other."
            icon="document-chart-bar"
        >
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                <x-ui.stat-card
                    label="Archive taken"
                    :value="Format::forHumans($archiveTakenAt)"
                    :delta-label="app_datetime($archiveTakenAt)"
                    icon="server-stack"
                    color="slate"
                />

                <x-ui.stat-card
                    label="Rows in the archive"
                    :value="$run->row_count_total === null ? 'not recorded' : app_number($run->row_count_total)"
                    :delta-label="$run->table_count === null ? 'tables not counted' : app_number($run->table_count).' tables, '.$bytes($run->size_bytes)"
                    icon="table-cells"
                    color="slate"
                />

                <x-ui.stat-card
                    label="Records added since"
                    :value="$lossesMeasured ? app_number($lossTotal) : 'could not be measured'"
                    :delta-label="$lossesMeasured
                        ? ($lossTotal === 0 ? 'nothing has been recorded since' : 'these rows will be gone')
                        : 'treat that as the alarming answer, not a zero'"
                    icon="exclamation-triangle"
                    :color="! $lossesMeasured ? 'amber' : ($lossTotal > 0 ? 'rose' : 'emerald')"
                />
            </div>

            @if ($lossesMeasured && $losses !== [])
                {{--
                    Section 8.3 step 1's plain-language warning, itemised. Biggest first, because the
                    number somebody needs to see is the one they will regret.
                --}}
                <div class="mt-4">
                    <x-ui.section-heading
                        title="What is in those records"
                        subtitle="Counted from created_at, per table. Only tables that could be counted appear — a table that could not be read is left out rather than shown as zero."
                        level="h3"
                        :divider="true"
                    />

                    <ul role="list" class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($losses as $loss)
                            <li class="flex items-baseline justify-between gap-3 rounded-lg bg-rose-50/70 px-3 py-2 ring-1 ring-rose-200/60 dark:bg-rose-500/10 dark:ring-rose-500/20">
                                <span class="min-w-0 truncate text-sm text-rose-900 dark:text-rose-200">
                                    {{ $loss['label'] }}
                                </span>
                                <span class="shrink-0 font-semibold tabular-nums text-rose-700 dark:text-rose-300">
                                    {{ app_number($loss['count']) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @elseif ($lossesMeasured)
                <p class="mt-4 flex items-start gap-2 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 ring-1 ring-emerald-200/70 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/20">
                    <x-ui.icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>
                        Nothing has been recorded in the tables that matter since this archive was taken. That
                        is the safest moment there is to restore — and it is unusual, so read it twice.
                    </span>
                </p>
            @else
                <p class="mt-4 flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200/70 dark:bg-amber-500/10 dark:ring-amber-500/20">
                    <x-ui.icon name="exclamation-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>
                        The "records added since" figure could not be measured. That is not a zero — proceed
                        only if you already know what has happened since {{ app_datetime($archiveTakenAt) }}.
                    </span>
                </p>
            @endif

            {{-- The live side, table by table, over section 6.10.4's proof list. --}}
            <div class="mt-5">
                <x-ui.section-heading
                    title="The database as it stands"
                    :subtitle="'Row counts over the proof list of section 6.10.4'
                        .($liveDatabase === null ? '.' : ' in '.$liveDatabase.'.')
                        .' The archive records a total ('.($run->row_count_total === null ? 'not recorded' : app_number($run->row_count_total))
                        .'), not a per-table breakdown, so no per-table delta is invented here.'"
                    level="h3"
                    :divider="true"
                />

                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[28rem] text-sm">
                        <caption class="sr-only">Live row counts per table, against the archive's recorded total</caption>
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-2xs uppercase tracking-wider text-slate-400 dark:border-slate-800 dark:text-slate-500">
                                <th scope="col" class="py-2 pr-4 font-semibold">Table</th>
                                <th scope="col" class="py-2 pl-4 text-right font-semibold">Rows now</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($liveCounts as $table => $count)
                                <tr class="border-b border-slate-100 last:border-0 dark:border-slate-800/70">
                                    <td class="py-2 pr-4 font-mono text-2xs text-slate-600 dark:text-slate-300">{{ $table }}</td>
                                    <td class="py-2 pl-4 text-right tabular-nums">
                                        @if ($count < 0)
                                            {{-- "Not in the database" and "empty" are different findings, and on
                                                 a restore screen the first one is the serious one. --}}
                                            <span class="font-medium text-rose-600 dark:text-rose-400">not present</span>
                                        @else
                                            <span class="text-slate-700 dark:text-slate-200">{{ app_number($count) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2" class="py-4">
                                        <x-ui.empty-state
                                            icon="table-cells"
                                            title="The live counts could not be read"
                                            message="Proceed only if you can account for what has changed since the archive was taken."
                                            level="h3"
                                            compact
                                        />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($liveCounts !== [])
                            <tfoot>
                                <tr class="border-t border-slate-200 font-semibold dark:border-slate-800">
                                    <td class="py-2 pr-4 text-slate-900 dark:text-white">
                                        Total{{ $missingTables === [] ? '' : ' (excluding '.count($missingTables).' missing table(s))' }}
                                    </td>
                                    <td class="py-2 pl-4 text-right tabular-nums text-slate-900 dark:text-white">
                                        {{ app_number($liveTotal) }}
                                    </td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </x-ui.card>

        {{-- ══════════════════ Steps 2 and 3 — one form ═════════════════════════════════ --}}
        <form method="POST" action="{{ route('admin.backups.restore.store', $run) }}" class="space-y-4">
            @csrf

            <x-ui.card
                title="Step 2 · Where it is going, and why"
                subtitle="The target decides which gates apply. Production is offered only when this really is production."
                icon="globe-alt"
            >
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <x-ui.form.select
                        name="target"
                        label="Target"
                        :options="$targetOptions"
                        :selected="old('target', $defaultTarget)"
                        required
                        x-model="target"
                        x-on:change="suggestDatabase()"
                        help="Local is a developer machine. Staging is treated like production, minus the audience. Production is the live database."
                    />

                    <x-ui.form.input
                        name="database_name"
                        label="Database to write to"
                        :value="old('database_name', $suggestedDatabase)"
                        required
                        x-model="database"
                        spellcheck="false"
                        autocomplete="off"
                        :help="$liveDatabase === null
                            ? 'Letters, digits and underscores only.'
                            : 'The live database is '.$liveDatabase.'. A production restore must name it; any other target must not.'"
                    />
                </div>

                {{--
                    Both directions of the database-name rule, said before the server says it. The
                    server is the rule (RestoreBackupRequest) — this is the sentence that stops somebody
                    finding out by submitting.
                --}}
                <p
                    x-show="isProductionTarget"
                    x-cloak
                    class="mt-3 flex items-start gap-2 rounded-lg bg-rose-50 p-3 text-xs text-rose-800 ring-1 ring-rose-200/70 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/20"
                >
                    <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <span>
                        This writes over the live database. Everybody is logged out, the site goes into
                        maintenance mode and the queue worker stops while it runs.
                    </span>
                </p>

                <p
                    x-show="! isProductionTarget"
                    class="mt-3 flex items-start gap-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-600 ring-1 ring-slate-200/70 dark:bg-slate-800/60 dark:text-slate-300 dark:ring-slate-700"
                >
                    <x-ui.icon name="information-circle" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <span>
                        A non-production restore must name a scratch database — never the live one. That is the
                        single mistake on this screen that a second restore cannot undo, because what it
                        overwrote was the original.
                    </span>
                </p>

                <div class="mt-4">
                    <x-ui.form.textarea
                        name="reason"
                        label="Why this restore is happening"
                        :value="old('reason')"
                        :rows="4"
                        :maxlength="500"
                        :counter="true"
                        required
                        x-model="reason"
                        placeholder="The 14:20 deployment dropped the fee installment rows for batch 12; restoring last night's archive into staging first to confirm the data is intact."
                        help="At least twenty characters. Stored permanently and never editable — this record outlives whoever writes it."
                    />

                    <p class="mt-1.5 text-xs" x-show="! reasonLongEnough" x-cloak>
                        <span class="text-amber-600 dark:text-amber-400">
                            <span x-text="Math.max(0, 20 - reason.trim().length)">20</span> more characters needed.
                        </span>
                    </p>
                </div>

                <div class="mt-4 border-t border-slate-200/80 pt-4 dark:border-slate-800">
                    <x-ui.form.checkbox
                        name="acknowledge_pre_backup"
                        :value="1"
                        :checked="(bool) old('acknowledge_pre_backup')"
                        label="A safety copy will be taken before anything is overwritten"
                        description="Automatic, recorded as a pre-restore backup, and never pruned by the schedule. If it fails, the restore aborts and nothing is written."
                        x-model="acknowledged"
                    />

                    <p x-show="needsPreBackup && ! acknowledged" x-cloak class="mt-1.5 text-xs text-amber-600 dark:text-amber-400">
                        Required for this target.
                    </p>
                </div>
            </x-ui.card>

            <x-ui.card
                title="Step 3 · Type the phrase"
                subtitle="Compared exactly, capitals included. A confirmation you can click through without reading is not a confirmation."
                icon="key"
            >
                <div x-show="needsPhrase">
                    <p class="text-sm text-slate-600 dark:text-slate-300">Type this, exactly:</p>

                    {{--
                        `x-text` rewrites this the moment the target or the database name changes. The
                        text between the tags is what stands before Alpine runs, and it is substituted
                        with `$suggestedDatabase` — the name actually pre-filled in the field above —
                        rather than the live database's. On the one screen whose whole job is making
                        somebody read which database they are overwriting, a fallback that names a
                        different database than the form holds is worse than no fallback. The
                        substitution is the same `str_replace` the server performs, over the same
                        template, so there is still one implementation of the phrase.
                    --}}
                    <p class="mt-2 select-all rounded-lg bg-slate-900 px-3 py-2.5 font-mono text-sm font-semibold text-white dark:bg-slate-950" x-text="expected">{{ str_replace('{database}', $suggestedDatabase, $phraseTemplate) }}</p>

                    <div class="mt-3">
                        <label for="restore-confirmation-phrase" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                            Confirmation phrase
                        </label>
                        <input
                            id="restore-confirmation-phrase"
                            name="confirmation_phrase"
                            type="text"
                            x-model="typed"
                            autocomplete="off"
                            spellcheck="false"
                            autocapitalize="off"
                            class="mt-1.5 block w-full rounded-lg border-slate-300 font-mono text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                            aria-describedby="restore-confirmation-help"
                        />

                        <p id="restore-confirmation-help" class="mt-1.5 text-xs">
                            <span x-show="typed.length === 0" class="text-slate-500 dark:text-slate-400">
                                It names the database being overwritten and the date of the snapshot going onto it.
                            </span>
                            <span x-show="typed.length > 0 && ! phraseMatches" x-cloak class="text-rose-600 dark:text-rose-400">
                                That is not the phrase yet.
                            </span>
                            <span x-show="typed.length > 0 && phraseMatches" x-cloak class="text-emerald-600 dark:text-emerald-400">
                                Matches.
                            </span>
                        </p>

                        @error('confirmation_phrase')
                            <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <p x-show="! needsPhrase" x-cloak class="text-sm text-slate-600 dark:text-slate-300">
                    A local restore does not need the phrase. Nothing on a developer machine is anybody's only
                    copy — the reason and the record are still required, because a restore is still a restore.
                </p>

                <div class="mt-5 flex flex-col-reverse gap-2 border-t border-slate-200/80 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Submitting records the request permanently. The restore itself is run from the server
                        console — it takes the site down and stops the queue worker, which a browser request
                        cannot do to the server that is answering it.
                    </p>

                    <div class="flex shrink-0 items-center gap-2">
                        <x-ui.button variant="secondary" :href="route('admin.backups.show', $run)">Cancel</x-ui.button>

                        {{-- Disabled until the phrase, the reason and the acknowledgement are all in. The
                             server re-checks every one of them — this is the courtesy, never the control. --}}
                        <x-ui.button
                            type="submit"
                            variant="danger"
                            icon="arrow-path"
                            x-bind:disabled="! ready"
                            x-bind:class="{ 'cursor-not-allowed opacity-60': ! ready }"
                        >
                            Record this restore
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        </form>

        {{-- ── What this archive has been used for before ─────────────────────────────── --}}
        @if ($previousRestores->isNotEmpty())
            <x-ui.card
                title="This archive has been restored before"
                subtitle="Every restore is a permanent record. These are the ones that used this file."
                icon="clock"
            >
                <ul role="list" class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($previousRestores as $previous)
                        <li class="flex flex-wrap items-baseline justify-between gap-2 py-2.5 text-sm">
                            <span class="min-w-0">
                                <span class="font-medium text-slate-900 dark:text-white">
                                    {{ app_datetime($previous->created_at) }}
                                </span>
                                <span class="text-slate-500 dark:text-slate-400">
                                    — {{ $previous->summary() }}
                                </span>
                                <span class="block text-2xs text-slate-400 dark:text-slate-500">
                                    {{ $previous->requester?->name ?? 'unknown' }}
                                </span>
                            </span>

                            <x-ui.badge :color="$previous->status->color()" size="sm">
                                {{ $previous->status->label() }}
                            </x-ui.badge>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
    </div>
@endsection
