@extends('layouts.admin')

@section('title', 'Backup — '.($run->filename ?? $run->uuid))

{{--
    One backup's evidence (route admin.backups.show, phase-24-25 section 8.2).

    This page answers one question: can this archive be trusted to bring the system back? So it shows
    the identity of the file, what it claims to contain, whether anybody has proved that claim, where
    the second copy is, and which restores have used it.

    Four rules this file follows without exception:

    1.  **The archive password is never rendered.** It is not passed to this view and never will be.
        The only fact about it anybody needs is the padlock: this archive is encrypted, so the file on
        the offsite disk is not a copy of the system in readable form.

    2.  **The path and the dump output are `backups.view_logs`, not `backups.view`** (section 4.2).
        `$log` arrives as null when the reader may not read them, so there is nothing on this page for
        a later refactor to leak — and null is not the same as empty: the Log tab says a different
        sentence for "there is no output" than for "not yours to read".

    3.  **No figure is computed here.** The counts are what the run recorded. The per-table numbers of
        section 6.10.4 are deliberately absent, because `backup_runs` stores `table_count` and
        `row_count_total` and no breakdown — and filling that gap with counts from the live database
        would put today's figures under an archive's heading. The breakdown exists where it means
        something: `backup_restores.row_counts_before` / `row_counts_after`, captured either side of a
        restore, and rendered on the restore wizard.

    4.  **Every destructive control is a confirm dialog over a backend check.** `$canPrune`,
        `$canVerify` and `$canRestore` are `BackupRunPolicy` answers computed in the controller; the
        route re-checks the permission and the controller asks the policy again on the request.
--}}

@php
    use App\Enums\BackupStatus;
    use App\Support\Format;
    use App\Support\Ops\RetentionPlan;

    $bytes = static fn (?int $value): string => $value === null
        ? '—'
        : RetentionPlan::humanBytes((string) $value);

    $ago = static fn (mixed $value): string => $value === null ? 'never' : Format::forHumans($value);

    $yesNo = static fn (bool $value): string => $value ? 'Yes' : 'No';

    // The identity block. Built here rather than repeated as a dozen <div> pairs in the markup.
    $identity = [
        'Reference' => $run->uuid,
        'Filename' => $run->filename ?? '— (no archive was written)',
        'Disk' => $run->disk,
        'Size' => $bytes($run->size_bytes),
        'Database' => $run->database_name ?? '— (files only)',
        'Encrypted' => $yesNo($run->is_encrypted),
        'Holds .env' => $run->includes_env ? 'Yes — inside the encrypted archive' : 'No',
        'App version' => $run->app_version ?? '—',
        'PHP' => $run->php_version ?? '—',
    ];

    $timings = [
        'Started' => $run->started_at === null ? '—' : app_datetime($run->started_at),
        'Finished' => $run->finished_at === null ? '—' : app_datetime($run->finished_at),
        'Duration' => $run->duration_seconds === null ? '—' : app_number($run->duration_seconds).' s',
        'Trigger' => $run->trigger->label(),
        'Taken by' => $run->creator?->name ?? 'the scheduler',
        'Reason' => $run->reason ?? ($run->trigger === \App\Enums\BackupTrigger::Manual ? '—' : 'not required for this trigger'),
    ];

    $retention = [
        'Class' => ucfirst($run->retention_class),
        'Keep until' => $run->retention_until === null ? '—' : app_date($run->retention_until),
        'Protected from pruning' => $yesNo($run->isProtectedFromPruning()),
        'File removed' => $run->file_pruned_at === null ? 'No' : app_datetime($run->file_pruned_at),
    ];

    $offsite = [
        'Offsite disk' => $run->offsite_disk ?? 'not copied',
        'Copied at' => $run->offsite_copied_at === null ? '—' : app_datetime($run->offsite_copied_at),
    ];
@endphp

@section('header')
    <x-ui.page-header
        :title="$run->filename ?? 'Backup '.\Illuminate\Support\Str::limit($run->uuid, 10, '')"
        :subtitle="$run->type->description()"
        icon="server-stack"
        :back="route('admin.backups.index')"
        :badge="$run->status->label()"
        :badge-color="$run->status->color()"
        :breadcrumbs="[
            ['label' => 'Backups', 'url' => route('admin.backups.index')],
            ['label' => $run->started_at ? app_datetime($run->started_at) : 'Run'],
        ]"
    >
        <x-slot:actions>
            @if ($canDownload && $downloadUrl !== null)
                <x-ui.button variant="secondary" icon="arrow-down-tray" :href="$downloadUrl">
                    Download
                </x-ui.button>
            @endif

            @if ($canVerify)
                <x-ui.confirm
                    :action="route('admin.backups.verify', $run)"
                    method="POST"
                    variant="warning"
                    icon="shield-check"
                    title="Verify this archive now?"
                    message="The file is re-read from start to finish and re-hashed, then the verdict is written onto this run. On a large archive that takes a while."
                    confirm-label="Verify now"
                >
                    <x-slot:trigger>
                        <x-ui.button variant="secondary" icon="shield-check">Verify</x-ui.button>
                    </x-slot:trigger>
                </x-ui.confirm>
            @endif

            @if ($canRestore)
                <x-ui.button variant="danger" icon="arrow-path" :href="route('admin.backups.restore.create', $run)">
                    Restore
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="space-y-4">

        @if ($run->status === BackupStatus::Failed)
            <div class="flex items-start gap-2.5 rounded-xl bg-rose-50 p-4 text-sm text-rose-800 ring-1 ring-rose-200/70 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/20">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <p class="font-semibold">This backup failed.</p>
                    <p class="mt-0.5">
                        The row was written before the dump started, which is why it exists at all — a night
                        with no row is a night nobody knows about. There is nothing here to restore from.
                        @if (! $canReadLogs)
                            The error detail needs the <code>backups.view_logs</code> permission.
                        @endif
                    </p>
                </div>
            </div>
        @elseif (! $fileOnDisk && $run->file_pruned_at === null && $run->path !== null)
            {{--
                The row says there is an archive and the disk says there is not. Worth its own banner:
                this is what a half-finished copy, a manual deletion or a network drive that went away
                leaves behind, and it is the state that makes a register lie.
            --}}
            <div class="flex items-start gap-2.5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
                <x-ui.icon name="exclamation-circle" class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <p class="font-semibold">The record says there is an archive; the disk does not.</p>
                    <p class="mt-0.5">
                        Nothing was pruned — the file is simply not where this row says it is. Somebody
                        removed it by hand, or the disk it was written to is not mounted.
                    </p>
                </div>
            </div>
        @elseif ($run->file_pruned_at !== null)
            <div class="flex items-start gap-2.5 rounded-xl bg-slate-50 p-4 text-sm text-slate-700 ring-1 ring-slate-200/70 dark:bg-slate-800/60 dark:text-slate-200 dark:ring-slate-700">
                <x-ui.icon name="information-circle" class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <p class="font-semibold">The archive file was removed by retention.</p>
                    <p class="mt-0.5">
                        Everything on this page survives it — the size, the checksum, the proof counts and
                        the verification history. The record of a backup outlives the bytes on purpose, so
                        "did a copy of that night exist, and what was in it" stays answerable for ever.
                    </p>
                </div>
            </div>
        @endif

        {{-- ── The figures ───────────────────────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ui.stat-card
                label="Status"
                :value="$run->status->label()"
                :delta-label="$run->status->description()"
                icon="check-badge"
                :color="$run->status->color()"
            />

            <x-ui.stat-card
                label="Verification"
                :value="$run->verification_status->label()"
                :delta-label="$run->verification_status->description()"
                icon="shield-check"
                :color="$run->verification_status->color()"
            />

            <x-ui.stat-card
                label="Rows dumped"
                :value="$run->row_count_total === null ? '—' : app_number($run->row_count_total)"
                :delta-label="($run->table_count === null ? 'tables not counted' : app_number($run->table_count).' tables')
                    .($run->file_count === null ? '' : ', '.app_number($run->file_count).' files')"
                icon="table-cells"
                color="slate"
            />

            <x-ui.stat-card
                label="Size"
                :value="$bytes($run->size_bytes)"
                :delta-label="$fileOnDisk ? 'on '.$run->disk : 'no file on disk'"
                icon="server-stack"
                :color="$fileOnDisk ? 'slate' : 'amber'"
            />
        </div>

        {{--
            Local tabs (section 8.2). Four panels, one page load: everything here came out of the row,
            so splitting it across four requests would buy nothing and lose the browser's back button.
        --}}
        <div x-data="uiTabs('overview')">
            <x-ui.tabs :tabs="[
                ['label' => 'Overview', 'key' => 'overview', 'icon' => 'identification'],
                ['label' => 'Verification', 'key' => 'verification', 'icon' => 'shield-check'],
                ['label' => 'Restores', 'key' => 'restores', 'icon' => 'arrow-path', 'count' => $restores->count()],
                ['label' => 'Log', 'key' => 'log', 'icon' => 'document-text'],
            ]" />

            {{-- ══ Overview ══════════════════════════════════════════════════════════ --}}
            <div x-show="is('overview')" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <x-ui.card title="Identity" subtitle="What this file is, and what it is not." icon="identification" level="h3">
                        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                            @foreach ($identity as $label => $value)
                                <div class="min-w-0">
                                    <dt class="text-2xs font-medium uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                        {{ $label }}
                                    </dt>
                                    <dd class="mt-0.5 break-words text-sm text-slate-900 dark:text-white">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>

                        @if ($run->is_encrypted)
                            <p class="mt-4 flex items-start gap-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                                <x-ui.icon name="lock-closed" class="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    This archive is encrypted. The password is not stored on this record and is
                                    never shown on any screen — it lives in the backup settings, encrypted, and
                                    <strong>an archive whose password is lost is not a backup</strong>. Keep a copy
                                    of it somewhere that is not this server.
                                </span>
                            </p>
                        @endif

                        {{--
                            The checksum, in full, with a copy button (section 8.2). Full rather than
                            truncated: the only use for it is comparing it against the hash of a file
                            somebody is holding, and a shortened hash cannot do that.
                        --}}
                        <div class="mt-4" x-data="{ copied: false }">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-2xs font-medium uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                    SHA-256
                                </span>

                                @if ($run->checksum_sha256 !== null)
                                    <button
                                        type="button"
                                        x-on:click="navigator.clipboard.writeText($refs.checksum.textContent.trim()).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                                        class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-2xs font-medium text-brand-700 transition-colors hover:bg-brand-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:text-brand-300 dark:hover:bg-brand-500/10"
                                    >
                                        <x-ui.icon name="clipboard" class="h-3 w-3" />
                                        <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                    </button>
                                @endif
                            </div>

                            <p x-ref="checksum" class="mt-1 break-all rounded-lg bg-slate-50 p-2.5 font-mono text-2xs text-slate-700 dark:bg-slate-950/40 dark:text-slate-300">{{ $run->checksum_sha256 ?? 'not computed' }}</p>
                        </div>
                    </x-ui.card>

                    <div class="space-y-4">
                        <x-ui.card title="Timings" subtitle="When it ran, who asked, and why." icon="clock" level="h3">
                            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                                @foreach ($timings as $label => $value)
                                    <div class="min-w-0">
                                        <dt class="text-2xs font-medium uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                            {{ $label }}
                                        </dt>
                                        <dd class="mt-0.5 break-words text-sm text-slate-900 dark:text-white">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </x-ui.card>

                        <x-ui.card title="Retention" subtitle="How long the file is kept — the record is kept for ever." icon="calendar-days" level="h3">
                            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                                @foreach ($retention as $label => $value)
                                    <div class="min-w-0">
                                        <dt class="text-2xs font-medium uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                            {{ $label }}
                                        </dt>
                                        <dd class="mt-0.5 text-sm text-slate-900 dark:text-white">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>

                            @if ($canPrune)
                                <div class="mt-4 border-t border-slate-200/80 pt-4 dark:border-slate-800">
                                    <x-ui.confirm
                                        :action="route('admin.backups.file.destroy', $run)"
                                        method="DELETE"
                                        :require-text="$run->filename ?? (string) $run->uuid"
                                        title="Remove this archive file?"
                                        message="The file is deleted now, ahead of its retention date. This record — its size, its checksum, its proof counts and its verification history — is kept for ever. Type the filename to confirm."
                                        confirm-label="Remove the file"
                                    >
                                        <x-slot:trigger>
                                            <x-ui.button variant="danger" size="sm" icon="trash">
                                                Remove the archive file
                                            </x-ui.button>
                                        </x-slot:trigger>
                                    </x-ui.confirm>
                                </div>
                            @elseif ($pruneBlockedReason !== null)
                                <p class="mt-4 flex items-start gap-2 border-t border-slate-200/80 pt-4 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                                    <x-ui.icon name="lock-closed" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                    <span>{{ $pruneBlockedReason }}</span>
                                </p>
                            @endif
                        </x-ui.card>

                        <x-ui.card title="Offsite copy" subtitle="The 3-2-1 half: a copy that is not on this machine." icon="arrow-up-tray" level="h3">
                            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                                @foreach ($offsite as $label => $value)
                                    <div class="min-w-0">
                                        <dt class="text-2xs font-medium uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                            {{ $label }}
                                        </dt>
                                        <dd class="mt-0.5 text-sm text-slate-900 dark:text-white">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>

                            @unless ($run->isCopiedOffsite())
                                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                                    A backup that only exists on the server it backs up is not a backup of that
                                    server — it shares the disk, the power supply and the ransomware.
                                </p>
                            @endunless
                        </x-ui.card>
                    </div>
                </div>

                {{--
                    Section 6.10.4's proof list: the tables the recorded counts cover. Names only, and
                    see rule 3 in the file header for why there are no numbers beside them.
                --}}
                <x-ui.card
                    title="What the counts cover"
                    subtitle="The financial and identity spine of section 6.10.4 — the tables a restore is proved against."
                    icon="table-cells"
                    level="h3"
                >
                    <ul role="list" class="grid grid-cols-1 gap-1.5 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($proofTables as $table)
                            <li class="truncate rounded-lg bg-slate-50 px-2.5 py-1.5 font-mono text-2xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                                {{ $table }}
                            </li>
                        @endforeach
                    </ul>

                    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                        Per-table figures are captured either side of a restore
                        (<code>row_counts_before</code> / <code>row_counts_after</code>) and are shown on the
                        restore record. They are not stored on a backup run, so nothing on this page invents
                        them from today's database.
                    </p>
                </x-ui.card>
            </div>

            {{-- ══ Verification ══════════════════════════════════════════════════════ --}}
            <div x-show="is('verification')" x-cloak class="mt-4 space-y-4">
                <x-ui.card
                    :title="$run->verification_status->label()"
                    :subtitle="$run->verified_at === null ? 'Never verified.' : 'Last checked '.$ago($run->verified_at).' — '.app_datetime($run->verified_at)"
                    icon="shield-check"
                    level="h3"
                >
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {{ $run->verification_status->description() }}
                    </p>

                    @if ($run->verification_notes !== null && trim($run->verification_notes) !== '')
                        <div class="mt-4">
                            <h4 class="text-2xs font-medium uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                What the last check proved
                            </h4>
                            <p class="mt-1 whitespace-pre-line rounded-lg bg-slate-50 p-3 text-xs text-slate-700 dark:bg-slate-950/40 dark:text-slate-300">{{ $run->verification_notes }}</p>
                        </div>
                    @endif

                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="rounded-xl p-3 ring-1 ring-slate-200/70 dark:ring-slate-800">
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">Checksum</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                The bytes on disk are identical to what was written, and the archive opens as a
                                valid zip. Runs daily at 04:00.
                            </p>
                        </div>

                        <div class="rounded-xl p-3 ring-1 ring-slate-200/70 dark:ring-slate-800">
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">Deep proof</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                The archive is restored into the scratch database, migrations come out clean, the
                                counts match and the money proves out. Weekly, Sunday 04:30 — and
                                <strong>a run that never reached this is not a backup for go-live</strong>.
                            </p>
                        </div>
                    </div>

                    <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                        This row keeps the latest verdict; the history of every check that produced one is in the
                        activity log against this backup. The deep proof creates and drops a database, so it is
                        run by <code>backup:verify --deep</code> and by the scheduler, never from a browser.
                    </p>

                    @if ($canVerify)
                        <div class="mt-4 border-t border-slate-200/80 pt-4 dark:border-slate-800">
                            <x-ui.confirm
                                :action="route('admin.backups.verify', $run)"
                                method="POST"
                                variant="warning"
                                icon="shield-check"
                                title="Verify this archive now?"
                                message="The file is re-read from start to finish and re-hashed, then the verdict is written onto this run."
                                confirm-label="Verify now"
                            >
                                <x-slot:trigger>
                                    <x-ui.button variant="secondary" size="sm" icon="shield-check">
                                        Verify the checksum now
                                    </x-ui.button>
                                </x-slot:trigger>
                            </x-ui.confirm>
                        </div>
                    @endif
                </x-ui.card>
            </div>

            {{-- ══ Restores ══════════════════════════════════════════════════════════ --}}
            <div x-show="is('restores')" x-cloak class="mt-4 space-y-4">
                <x-ui.table
                    :is-empty="$restores->isEmpty()"
                    :columns="6"
                    caption="Restores that used this archive, newest first"
                >
                    <x-slot:head>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Requested</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Role</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Target</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Ledger rows</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Reason</th>
                    </x-slot:head>

                    @foreach ($restores as $restore)
                        <tr>
                            <td class="px-4 py-3.5 align-top">
                                <time datetime="{{ $restore->created_at?->toIso8601String() }}">
                                    {{ app_datetime($restore->created_at) }}
                                </time>
                                <div class="mt-0.5 text-2xs text-slate-400 dark:text-slate-500">
                                    {{ $restore->requester?->name ?? 'unknown' }}
                                </div>
                            </td>

                            <td class="px-4 py-3.5 align-top text-slate-600 dark:text-slate-300">
                                {{-- Which side of the restore this archive was on. Both are facts about
                                     this file, and they mean opposite things. --}}
                                @if ((int) $restore->backup_run_id === (int) $run->getKey())
                                    restored from it
                                @else
                                    its safety copy
                                @endif
                            </td>

                            <td class="px-4 py-3.5 align-top">
                                <x-ui.badge :color="$restore->target->color()" size="sm">
                                    {{ $restore->target->label() }}
                                </x-ui.badge>
                                <div class="mt-0.5 font-mono text-2xs text-slate-400 dark:text-slate-500">
                                    {{ $restore->database_name }}
                                </div>
                            </td>

                            <td class="px-4 py-3.5 align-top">
                                <x-ui.badge :color="$restore->status->color()" size="sm">
                                    {{ $restore->status->label() }}
                                </x-ui.badge>
                                @unless ($restore->gatesSatisfied())
                                    <div class="mt-0.5 text-2xs text-amber-600 dark:text-amber-400">
                                        gates not all stamped
                                    </div>
                                @endunless
                            </td>

                            <td class="px-4 py-3.5 align-top text-right tabular-nums">
                                @php($delta = $restore->ledgerDelta())
                                @if ($delta === null)
                                    <span class="text-slate-400 dark:text-slate-500">—</span>
                                @else
                                    {{-- A negative number is the figure this table exists to surface. --}}
                                    <span @class([
                                        'font-semibold' => true,
                                        'text-rose-600 dark:text-rose-400' => $delta < 0,
                                        'text-slate-700 dark:text-slate-200' => $delta >= 0,
                                    ])>
                                        {{ $delta > 0 ? '+' : '' }}{{ app_number($delta) }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 align-top text-slate-600 dark:text-slate-300">
                                <span class="line-clamp-3">{{ $restore->reason }}</span>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state
                            icon="arrow-path"
                            title="This archive has never been restored"
                            message="Which is the answer you want on most days. An archive that has never been restored anywhere, though, is also an archive nobody has proved — the deep verification is what does that without touching the live database."
                            level="h3"
                        />
                    </x-slot:empty>
                </x-ui.table>
            </div>

            {{-- ══ Log ═══════════════════════════════════════════════════════════════ --}}
            <div x-show="is('log')" x-cloak class="mt-4 space-y-4">
                @if (! $canReadLogs)
                    {{--
                        Not "no log output" — "not yours to read". The two are different sentences and
                        the controller sends null precisely so this page can tell them apart (section
                        4.2): the log names tables, paths and the database, which is a map of the
                        installation rather than a fact about a backup.
                    --}}
                    <x-ui.card title="Log output" icon="document-text" level="h3">
                        <x-ui.empty-state
                            icon="lock-closed"
                            title="This needs the backups.view_logs permission"
                            message="The dump and prune output names tables, paths and the database itself. Reading the register is one permission; reading the map underneath it is another."
                            level="h3"
                            compact
                        />
                    </x-ui.card>
                @elseif ($log === null)
                    <x-ui.card title="Log output" icon="document-text" level="h3">
                        <x-ui.empty-state
                            icon="document-text"
                            title="Nothing was recorded"
                            message="This run produced no error output and no verification notes. For a completed backup that is exactly what you want to see."
                            level="h3"
                            compact
                        />
                    </x-ui.card>
                @else
                    <x-ui.card
                        title="Log output"
                        subtitle="Scrubbed before it was stored — a raw dump log can carry the database password."
                        icon="document-text"
                        level="h3"
                    >
                        <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-950 p-3 font-mono text-2xs leading-relaxed text-slate-200 dark:bg-slate-950">{{ $log }}</pre>

                        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                            The archive password never appears here, in a notification or in the manifest. It is
                            read once, used once, and removed from any exception message before it reaches this
                            column.
                        </p>
                    </x-ui.card>
                @endif
            </div>
        </div>

        @if (! $canRestore && $restoreBlockedReason !== null)
            <div class="flex items-start gap-2.5 rounded-xl bg-slate-50 p-3 text-xs text-slate-600 ring-1 ring-slate-200/70 dark:bg-slate-800/60 dark:text-slate-300 dark:ring-slate-700">
                <x-ui.icon name="lock-closed" class="mt-0.5 h-4 w-4 shrink-0" />
                <p>{{ $restoreBlockedReason }}</p>
            </div>
        @endif
    </div>
@endsection
