@extends('layouts.admin')

@section('title', 'Backups')

{{--
    Backups register (route admin.backups.index, phase-24-25 section 8.1).

    The operator's one screen: what restore points exist, whether they are proven, and where they
    live. Three things this file is deliberately not doing:

    1.  It never decides who may prune, download or restore. Every row arrives with its answers
        already computed in `$abilities` — asked of `BackupRunPolicy` directly, never through the
        Gate, because `Gate::before` would hand a Super Admin the right to prune the only copy of
        the database (see the controller's class note). A disabled button here is the rendering of
        a backend refusal, never the refusal itself.

    2.  It never renders a credential. `backup.archive_password` is not passed to this view and has
        no business on a screen; the only fact about it anybody sees is the padlock that says the
        archive is encrypted at all (section 8.2). Paths and the raw dump output belong to
        `backups.view_logs` and live on the detail screen, not in this table.

    3.  It never computes a size. `RetentionPlan::humanBytes()` is bcmath on an integer string —
        `20.00 * 1024 ** 3` is the multiplication a float turns into 21474836479.999996, and a
        storage ceiling that reads 19.99 GB when it is 20 is a ceiling somebody argues with.

    Formatting goes through app_datetime() / app_number() so localization restyles the screen
    (D61: stored timestamps are UTC, the helpers convert). The raw toIso8601String() calls are
    inside <time datetime="..."> and are the machine value, never read by a person.
--}}

@php
    use App\Support\Format;
    use App\Support\Ops\RetentionPlan;

    $bytes = static fn (?int $value): string => $value === null
        ? '—'
        : RetentionPlan::humanBytes((string) $value);

    $ago = static fn (mixed $value): string => $value === null ? 'never' : Format::forHumans($value);

    // The three figures the stat cards need that are not a single column read.
    $latestDatabase = $summary['latest_database'];
    $latestProven = $summary['latest_proven'];
    $floorAtRisk = $summary['usable_database_archives'] <= $summary['minimum_copies'];
@endphp

@section('header')
    <x-ui.page-header
        title="Backups"
        subtitle="Every restore point this system has, and the evidence for trusting it."
        icon="server-stack"
        :badge="app_number($runs->total()).' runs'"
        :badge-color="$latestDatabase === null ? 'rose' : 'slate'"
    >
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'run-backup')">
                    Back up now
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    {{--
        Two pieces of local state, and nothing else on this page needs Alpine:

        `navigating` drives the table's skeleton while a filter change is in flight — the filter bar
        submits itself, so from that moment the rows on screen belong to the previous query.

        `polls` reloads the page every 5 s while a run on this page is still being written, and gives
        up after two minutes and says so (section 8.1 "Behaviour"). It is capped on purpose: a page
        that reloads for ever is how a browser tab becomes the thing keeping a server busy, and after
        two minutes "it is still running" has stopped being news and started being a symptom.
    --}}
    <div
        x-data="{
            navigating: false,
            ticks: 0,
            maxTicks: 24,
            gaveUp: false,
            poll() {
                if (this.ticks >= this.maxTicks) { this.gaveUp = true; return; }
                this.ticks++;
                window.setTimeout(() => window.location.reload(), 5000);
            },
        }"
        x-init="@js($isRunning) && poll()"
        x-on:submit.window="if ($event.target?.method === 'get') navigating = true"
        class="space-y-4"
    >

        @if ($isRunning)
            <div
                class="flex items-start gap-2.5 rounded-xl bg-sky-50 p-3 text-sm text-sky-800 ring-1 ring-sky-200/70 dark:bg-sky-500/10 dark:text-sky-200 dark:ring-sky-500/20"
                role="status"
            >
                <x-ui.icon name="arrow-path" class="mt-0.5 h-4 w-4 shrink-0 motion-safe:animate-spin" />
                <p>
                    <span x-show="! gaveUp">
                        A backup is being written. This page refreshes itself every few seconds until it finishes.
                    </span>
                    <span x-show="gaveUp" x-cloak>
                        This run has been going for more than two minutes, so the page has stopped refreshing
                        itself. A large archive legitimately takes that long — reload to check, and if the row
                        never leaves <strong>running</strong>, the process was killed and the row is the evidence.
                    </span>
                </p>
            </div>
        @endif

        @if ($latestDatabase === null)
            {{--
                No usable database archive at all. Said at the top of the screen rather than left to a
                stat card, because it is the only state of this page where nothing else on it matters.
            --}}
            <div class="flex items-start gap-2.5 rounded-xl bg-rose-50 p-4 text-sm text-rose-800 ring-1 ring-rose-200/70 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/20">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <p class="font-semibold">There is no database archive on disk.</p>
                    <p class="mt-0.5">
                        Right now this system has no way back from a mistake, a failed migration or a disk
                        that stops answering. Take one, and then verify it — an archive nobody has checked
                        is a hope, not a backup.
                    </p>
                </div>
            </div>
        @elseif ($summary['ceiling_breached'])
            <div class="flex items-start gap-2.5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
                <x-ui.icon name="exclamation-circle" class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <p class="font-semibold">Backup storage is over its ceiling.</p>
                    <p class="mt-0.5">
                        {{ $summary['stored_human'] }} of {{ $summary['ceiling_human'] }} used. The retention
                        job prunes what policy allows and then <strong>fails loudly</strong> rather than
                        deleting past policy — running out of disk is an operations problem, not a licence to
                        destroy history.
                    </p>
                </div>
            </div>
        @endif

        {{-- ── The four figures of section 8.1 ───────────────────────────────────────── --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ui.stat-card
                label="Newest database archive"
                :value="$ago($latestDatabase?->finished_at ?? $latestDatabase?->started_at)"
                :delta-label="$latestDatabase === null
                    ? 'nothing to restore from'
                    : app_datetime($latestDatabase->finished_at ?? $latestDatabase->started_at)"
                icon="table-cells"
                :color="$latestDatabase === null ? 'rose' : 'emerald'"
                :href="$latestDatabase === null ? null : route('admin.backups.show', $latestDatabase)"
            />

            <x-ui.stat-card
                label="Proven restore point"
                :value="$latestProven === null ? 'None' : $ago($latestProven->verified_at)"
                :delta-label="$latestProven === null
                    ? 'no archive has been restored to prove it'
                    : 'restored into the scratch database and proved'"
                icon="shield-check"
                :color="$latestProven === null ? 'amber' : 'emerald'"
                :href="$latestProven === null ? null : route('admin.backups.show', $latestProven)"
            />

            <x-ui.stat-card
                label="Archives on disk"
                :value="$summary['stored_human'].' / '.$summary['ceiling_human']"
                :delta-label="app_number($summary['on_disk_count']).' files, '
                    .app_number($summary['usable_database_archives']).' usable database copies (floor '
                    .app_number($summary['minimum_copies']).')'"
                icon="server-stack"
                :color="$summary['ceiling_breached'] ? 'amber' : ($floorAtRisk ? 'amber' : 'slate')"
            />

            <x-ui.stat-card
                label="Offsite copies"
                :value="$summary['offsite_disk'] === null ? 'Not configured' : app_number($summary['offsite_count'])"
                :delta-label="$summary['offsite_disk'] === null
                    ? ($summary['offsite_required'] ? 'required before go-live' : 'the 3-2-1 copy is not set up')
                    : 'on '.$summary['offsite_disk']"
                icon="arrow-up-tray"
                :color="$summary['offsite_disk'] === null && $summary['offsite_required'] ? 'rose' : ($summary['offsite_count'] > 0 ? 'emerald' : 'slate')"
            />
        </div>

        {{-- ── Filters ───────────────────────────────────────────────────────────────── --}}
        <x-ui.filter-bar placeholder="Search reference, filename, reason…">
            <x-ui.form.select
                name="type"
                :options="$typeOptions"
                :selected="request('type')"
                placeholder="Any type"
                size="sm"
                icon="table-cells"
                aria-label="Filter by backup type"
            />

            <x-ui.form.select
                name="status"
                :options="$statusOptions"
                :selected="request('status')"
                placeholder="Any status"
                size="sm"
                icon="check-badge"
                aria-label="Filter by status"
            />

            <x-ui.form.select
                name="trigger"
                :options="$triggerOptions"
                :selected="request('trigger')"
                placeholder="Any trigger"
                size="sm"
                icon="sparkles"
                aria-label="Filter by what started the backup"
            />

            <x-ui.form.select
                name="verification_status"
                :options="$verificationOptions"
                :selected="request('verification_status')"
                placeholder="Any verification"
                size="sm"
                icon="shield-check"
                aria-label="Filter by verification status"
            />

            <x-ui.form.select
                name="on_disk"
                :options="['1' => 'File on disk', '0' => 'File gone']"
                :selected="request('on_disk')"
                placeholder="On disk or not"
                size="sm"
                icon="folder"
                aria-label="Filter by whether the archive file is still on disk"
            />

            <x-ui.form.select
                name="offsite"
                :options="['1' => 'Copied offsite', '0' => 'No offsite copy']"
                :selected="request('offsite')"
                placeholder="Offsite or not"
                size="sm"
                icon="arrow-up-tray"
                aria-label="Filter by whether an offsite copy exists"
            />

            <x-ui.form.input
                type="date"
                name="from"
                :value="request('from')"
                size="sm"
                aria-label="From date"
                class="sm:max-w-[11rem]"
            />

            <x-ui.form.input
                type="date"
                name="to"
                :value="request('to')"
                size="sm"
                aria-label="To date"
                class="sm:max-w-[11rem]"
            />

            <x-ui.form.select
                name="per_page"
                :options="$perPageOptions"
                :selected="(string) request('per_page', $runs->perPage())"
                size="sm"
                icon="list-bullet"
                aria-label="Rows per page"
            />
        </x-ui.filter-bar>

        {{-- ── The register ──────────────────────────────────────────────────────────── --}}
        <x-ui.table
            loading="navigating"
            :is-empty="$runs->isEmpty()"
            :columns="9"
            caption="Backup runs, newest first"
        >
            <x-slot:head>
                <x-ui.th-sortable column="started_at" :sort="$sort" :direction="$direction" default="desc">
                    Taken
                </x-ui.th-sortable>

                <x-ui.th-sortable column="type" :sort="$sort" :direction="$direction">
                    Type
                </x-ui.th-sortable>

                <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">
                    Status
                </x-ui.th-sortable>

                <x-ui.th-sortable column="trigger" :sort="$sort" :direction="$direction">
                    Trigger
                </x-ui.th-sortable>

                <x-ui.th-sortable column="size_bytes" :sort="$sort" :direction="$direction" align="right" :numeric="true" default="desc">
                    Size
                </x-ui.th-sortable>

                <x-ui.th-sortable column="row_count_total" :sort="$sort" :direction="$direction" align="right" :numeric="true" default="desc">
                    Tables / rows
                </x-ui.th-sortable>

                <x-ui.th-sortable column="verification_status" :sort="$sort" :direction="$direction">
                    Verification
                </x-ui.th-sortable>

                <th scope="col" class="px-4 py-3 text-left font-semibold">Retention</th>

                <th scope="col" class="px-4 py-3 text-right font-semibold">
                    <span class="sr-only">Actions</span>
                </th>
            </x-slot:head>

            @foreach ($runs as $run)
                @php
                    $can = $abilities[$run->getKey()] ?? [];
                @endphp

                {{-- A failed night is tinted, so a bad run is visible without reading the column. --}}
                <tr @class(['bg-rose-50/60 dark:bg-rose-500/5' => $run->status === \App\Enums\BackupStatus::Failed])>
                    <td class="px-4 py-3.5 align-top">
                        <a
                            href="{{ route('admin.backups.show', $run) }}"
                            class="font-medium text-brand-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:text-brand-300"
                        >
                            <time datetime="{{ ($run->started_at ?? $run->created_at)?->toIso8601String() }}">
                                {{ $ago($run->started_at ?? $run->created_at) }}
                            </time>
                        </a>
                        <div class="mt-0.5 text-2xs text-slate-400 dark:text-slate-500">
                            {{ app_datetime($run->started_at ?? $run->created_at) }}
                        </div>
                        @if ($run->creator !== null)
                            <div class="mt-0.5 text-2xs text-slate-400 dark:text-slate-500">
                                by {{ $run->creator->name }}
                            </div>
                        @else
                            {{-- Null means the scheduler, and that null is information, not a gap. --}}
                            <div class="mt-0.5 text-2xs text-slate-400 dark:text-slate-500">scheduler</div>
                        @endif
                    </td>

                    <td class="px-4 py-3.5 align-top">
                        <x-ui.badge :color="$run->type->color()" size="sm">{{ $run->type->label() }}</x-ui.badge>
                        @if ($run->is_encrypted)
                            <div class="mt-1 flex items-center gap-1 text-2xs text-slate-500 dark:text-slate-400">
                                <x-ui.icon name="lock-closed" class="h-3 w-3" />
                                encrypted{{ $run->includes_env ? ', holds .env' : '' }}
                            </div>
                        @endif
                    </td>

                    <td class="px-4 py-3.5 align-top">
                        <x-ui.badge :color="$run->status->color()" size="sm">
                            {{ $run->status->label() }}
                        </x-ui.badge>
                        @if ($run->file_pruned_at !== null)
                            <div class="mt-0.5 text-2xs text-slate-400 dark:text-slate-500">
                                file removed {{ $ago($run->file_pruned_at) }}
                            </div>
                        @endif
                    </td>

                    <td class="px-4 py-3.5 align-top">
                        <span class="text-slate-700 dark:text-slate-200">{{ $run->trigger->label() }}</span>
                        @if ($run->reason !== null)
                            <div class="mt-0.5 max-w-[16rem] truncate text-2xs text-slate-400 dark:text-slate-500" title="{{ $run->reason }}">
                                {{ $run->reason }}
                            </div>
                        @endif
                    </td>

                    <td class="px-4 py-3.5 align-top text-right tabular-nums text-slate-700 dark:text-slate-200">
                        {{ $bytes($run->size_bytes) }}
                        @if ($run->duration_seconds !== null)
                            <div class="mt-0.5 text-2xs text-slate-400 dark:text-slate-500">
                                {{ app_number($run->duration_seconds) }} s
                            </div>
                        @endif
                    </td>

                    {{--
                        Section 8.1 "Row detail": the row count is on every row on purpose. A database
                        that suddenly dumps half as many rows as last night is the cheapest corruption
                        alarm there is, and it only works if the number is visible without clicking.
                    --}}
                    <td class="px-4 py-3.5 align-top text-right tabular-nums text-slate-700 dark:text-slate-200">
                        @if ($run->row_count_total === null && $run->table_count === null)
                            <span class="text-slate-400 dark:text-slate-500">—</span>
                        @else
                            {{ $run->table_count === null ? '—' : app_number($run->table_count) }}
                            <span class="text-slate-300 dark:text-slate-600">/</span>
                            {{ $run->row_count_total === null ? '—' : app_number($run->row_count_total) }}
                        @endif
                        @if ($run->file_count !== null)
                            <div class="mt-0.5 text-2xs text-slate-400 dark:text-slate-500">
                                {{ app_number($run->file_count) }} files
                            </div>
                        @endif
                    </td>

                    <td class="px-4 py-3.5 align-top">
                        {{-- The badge carries its label text, so status is never colour alone (A-h). --}}
                        <x-ui.badge :color="$run->verification_status->color()" size="sm">
                            {{ $run->verification_status->label() }}
                        </x-ui.badge>
                        @if ($run->verified_at !== null)
                            <div class="mt-0.5 text-2xs text-slate-400 dark:text-slate-500">
                                {{ app_datetime($run->verified_at) }}
                            </div>
                        @endif
                        @if ($run->isCopiedOffsite())
                            <div class="mt-0.5 flex items-center gap-1 text-2xs text-emerald-600 dark:text-emerald-400">
                                <x-ui.icon name="arrow-up-tray" class="h-3 w-3" />
                                offsite
                            </div>
                        @endif
                    </td>

                    <td class="px-4 py-3.5 align-top">
                        <span class="text-slate-700 dark:text-slate-200">{{ ucfirst($run->retention_class) }}</span>
                        <div class="mt-0.5 text-2xs text-slate-400 dark:text-slate-500">
                            @if ($run->isProtectedFromPruning())
                                never pruned
                            @elseif ($run->retention_until !== null)
                                until {{ app_date($run->retention_until) }}
                            @else
                                no date set
                            @endif
                        </div>
                    </td>

                    <td class="px-4 py-3.5 align-top text-right">
                        <div class="inline-flex items-center gap-1">
                            <x-ui.icon-button
                                icon="eye"
                                :href="route('admin.backups.show', $run)"
                                label="Open the backup taken {{ app_datetime($run->started_at ?? $run->created_at) }}"
                            />

                            @if ($can['verify'] ?? false)
                                {{--
                                    A POST, because verifying writes a verdict onto the row. Confirmed
                                    rather than fired on a click: it re-reads the whole archive and that
                                    is not a free action on a multi-gigabyte file.
                                --}}
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
                                        <x-ui.icon-button icon="shield-check" label="Verify this archive" />
                                    </x-slot:trigger>
                                </x-ui.confirm>
                            @endif

                            @if (($can['download'] ?? false) && isset($downloadUrls[$run->getKey()]))
                                <x-ui.icon-button
                                    icon="arrow-down-tray"
                                    :href="$downloadUrls[$run->getKey()]"
                                    label="Download {{ $run->filename ?? 'this archive' }} (link expires in 5 minutes)"
                                />
                            @endif

                            @if ($can['restore'] ?? false)
                                {{--
                                    Red, and a link rather than a form: the wizard behind it is where the
                                    four gates live (permission, password confirmation, typed phrase,
                                    written reason) and none of them can be satisfied from this table.
                                --}}
                                <x-ui.icon-button
                                    icon="arrow-path"
                                    variant="danger"
                                    :href="route('admin.backups.restore.create', $run)"
                                    label="Restore from this archive — asks for a password confirmation, a typed phrase and a written reason"
                                />
                            @elseif ($canRestoreAny && $run->type->includesDatabase() && ($can['restore_blocked_reason'] ?? null) !== null)
                                {{-- Disabled with the rule that refused it on the tooltip — icon-button
                                     renders `label` as both aria-label and title, so the mouse user and
                                     the screen-reader user get the same sentence. --}}
                                <x-ui.icon-button
                                    icon="arrow-path"
                                    variant="danger"
                                    :disabled="true"
                                    :label="$can['restore_blocked_reason']"
                                />
                            @endif

                            @if ($can['prune'] ?? false)
                                <x-ui.confirm
                                    :action="route('admin.backups.file.destroy', $run)"
                                    method="DELETE"
                                    :require-text="$run->filename ?? (string) $run->uuid"
                                    title="Remove this archive file?"
                                    message="The file is deleted now, ahead of its retention date. The record of the backup — its size, its checksum and its proof counts — is kept for ever. Type the filename to confirm."
                                    confirm-label="Remove the file"
                                >
                                    <x-slot:trigger>
                                        <x-ui.icon-button icon="trash" variant="danger" label="Remove this archive file" />
                                    </x-slot:trigger>
                                </x-ui.confirm>
                            @elseif (($can['prune_blocked_reason'] ?? null) !== null && $run->file_pruned_at === null && $run->path !== null)
                                {{--
                                    Disabled with the reason on it. Section 8.1 asks for the explanatory
                                    tooltip by name, and the sentence is the rule that actually refused —
                                    the newest usable database archive can never be pruned here, whoever
                                    is asking.
                                --}}
                                <x-ui.icon-button
                                    icon="trash"
                                    :disabled="true"
                                    :label="$can['prune_blocked_reason']"
                                />
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                @if ($hasFilters)
                    <x-ui.empty-state
                        icon="funnel"
                        title="No backups match these filters"
                        message="Clear the filters to see the whole register."
                    />
                @else
                    <x-ui.empty-state
                        icon="server-stack"
                        title="No backups yet"
                        message="The first one proves the second one. Take a backup now, then verify it — an archive nobody has restored is not yet a restore point."
                    >
                        <x-slot:action>
                            <div class="flex flex-wrap items-center justify-center gap-2">
                                @if ($canCreate)
                                    <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'run-backup')">
                                        Back up now
                                    </x-ui.button>
                                @endif

                                @if ($settingsUrl !== null)
                                    <x-ui.button variant="secondary" icon="cog-6-tooth" :href="$settingsUrl">
                                        Backup settings
                                    </x-ui.button>
                                @endif
                            </div>
                        </x-slot:action>
                    </x-ui.empty-state>
                @endif
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$runs" label="backups" />
            </x-slot:footer>
        </x-ui.table>
    </div>
@endsection

@if ($canCreate)
    @push('modals')
        {{--
            "Back up now" asks for a reason before it asks for anything else. `BackupTrigger::Manual`
            requires one (section 4.2), the Form Request enforces it, and the service refuses without
            it — three layers for one sentence, because in six months that sentence is the only thing
            telling somebody which of the night's archives to reach for.
        --}}
        <x-ui.modal name="run-backup" title="Take a backup now" icon="server-stack" size="md">
            <form id="run-backup-form" method="POST" action="{{ route('admin.backups.store') }}" class="space-y-4">
                @csrf

                <x-ui.form.select
                    name="type"
                    label="What to archive"
                    :options="$typeChoices"
                    :selected="old('type', \App\Enums\BackupType::Database->value)"
                    required
                    help="A database archive is the one a restore can work from. A files archive holds the uploads a dump cannot bring back."
                />

                <x-ui.form.textarea
                    name="reason"
                    label="Why"
                    :value="old('reason')"
                    :rows="3"
                    :maxlength="255"
                    :counter="true"
                    required
                    placeholder="Before the fee structure change — so there is a way back if the new rules are wrong."
                    help="Stored on the record and never editable. It is what distinguishes this archive from the nightly one beside it."
                />

                <p class="text-xs text-slate-500 dark:text-slate-400">
                    The archive is written now and the page waits for it. Two manual backups an hour are
                    allowed, and two never run at once — the second one is refused rather than queued, because
                    two dumps of the same database competing for the same rows is worse than waiting.
                </p>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="hide(true)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="run-backup-form" icon="server-stack">Back up now</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endpush
@endif
