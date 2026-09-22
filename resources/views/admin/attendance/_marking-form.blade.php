{{--
    The register, shared by the admin screen and the teacher panel (§8.15).

    **Designed for a phone at a classroom door.** One row per roster member, a four-state control that
    shows a letter as well as a colour, and four bulk buttons at the top. The keyboard shortcuts —
    p / a / l / t to mark the focused row and advance — mean a teacher with a laptop never needs the
    mouse, and `enter` submits.

    **A colour is never the only signal.** Every state carries its letter, so the register can be read
    by somebody who cannot distinguish the colours and survives being photocopied.

    Expects: $session, $roster, $statuses, $grace, $canBeMarked, $action (the POST route),
    $bulkAction (optional).
--}}

@php
    $marked = $roster->filter(fn ($e) => $e->attendanceForSession !== null);
    $lockedRows = $marked->filter(fn ($e) => ! $e->attendanceForSession->isWithinLockWindow());
@endphp

@unless ($canBeMarked)
    <x-ui.card class="mb-4 border-rose-200 dark:border-rose-500/30">
        <div class="flex items-start gap-3">
            <x-ui.icon name="x-mark" class="mt-0.5 h-5 w-5 shrink-0 text-rose-500" />
            <div>
                <p class="font-medium text-slate-700 dark:text-slate-200">
                    This class was {{ mb_strtolower($session->status->label()) }} — attendance cannot be recorded against it.
                </p>
                <p class="mt-1 text-sm text-slate-500">
                    A cancelled class is not an absence for anybody, and a rescheduled one happened
                    somewhere else. Neither counts in a percentage.
                </p>
            </div>
        </div>
    </x-ui.card>
@endunless

@if ($marked->isNotEmpty())
    <x-ui.card class="mb-4 border-sky-200 dark:border-sky-500/30">
        <p class="text-sm text-slate-600 dark:text-slate-300">
            @php($first = $marked->first()->attendanceForSession)
            Marked by {{ $first->marker?->name ?? 'the system' }}
            at {{ app_time($first->marked_at) }} on {{ app_date($first->marked_at) }}.
            @if ($lockedRows->isNotEmpty())
                <span class="font-medium">
                    {{ $lockedRows->count() }} {{ \Illuminate\Support\Str::plural('row', $lockedRows->count()) }}
                    {{ $lockedRows->count() === 1 ? 'is' : 'are' }} past the correction window,
                </span>
                so changing {{ $lockedRows->count() === 1 ? 'it' : 'them' }} needs a reason — the old value is kept.
            @endif
        </p>
    </x-ui.card>
@endif

@if ($roster->isEmpty())
    <x-ui.card>
        <x-ui.empty-state icon="users" title="No students enrolled on this date"
                          description="The roster is read as it stood on the day of the class, so somebody who joined later is not expected here — and was never absent for it." />
    </x-ui.card>
@else
    <form method="POST" action="{{ $action }}" x-data="attendanceRegister()" @keydown.window="onKey($event)">
        @csrf

        <x-ui.card class="mb-4" :padded="false">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/80 px-4 py-3 dark:border-slate-800">
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="button" variant="secondary" size="sm" x-on:click="markAll('present')">All present</x-ui.button>
                    <x-ui.button type="button" variant="ghost" size="sm" x-on:click="markAll('absent')">All absent</x-ui.button>
                    <x-ui.button type="button" variant="ghost" size="sm" x-on:click="invert()">Invert</x-ui.button>
                    <x-ui.button type="button" variant="ghost" size="sm" x-on:click="reset()">Reset</x-ui.button>
                </div>

                <div class="flex flex-wrap items-center gap-3 text-sm">
                    @foreach ($statuses as $status)
                        <span class="flex items-center gap-1.5">
                            <x-ui.badge :color="$status->color()" size="xs">{{ $status->glyph() }}</x-ui.badge>
                            <span class="font-medium text-slate-700 dark:text-slate-200" x-text="counts['{{ $status->value }}']">0</span>
                        </span>
                    @endforeach
                    <span class="border-l border-slate-200 pl-3 text-slate-500 dark:border-slate-700">
                        <span class="font-semibold text-slate-800 dark:text-slate-100" x-text="percentage()">0</span>% present
                    </span>
                </div>
            </div>

            <div class="divide-y divide-slate-200/70 dark:divide-slate-800">
                @foreach ($roster as $index => $enrollment)
                    @php($existing = $enrollment->attendanceForSession)
                    @php($current = old('marks.'.$enrollment->student_id.'.status', $existing?->status?->value ?? 'present'))

                    <div class="flex flex-wrap items-center gap-3 px-4 py-3"
                         :class="focused === {{ $index }} ? 'bg-brand-50/60 dark:bg-brand-500/5' : ''"
                         x-on:click="focused = {{ $index }}">
                        <x-ui.avatar :name="$enrollment->student?->name" size="sm" />

                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium text-slate-700 dark:text-slate-200">
                                {{ $enrollment->roll_number ? $enrollment->roll_number.' · ' : '' }}{{ $enrollment->student?->name ?? 'Unknown' }}
                            </div>
                            <div class="text-xs text-slate-400">{{ $enrollment->student?->student_code }}</div>
                        </div>

                        <div class="flex overflow-hidden rounded-lg ring-1 ring-slate-200 dark:ring-slate-700">
                            @foreach ($statuses as $status)
                                <label class="cursor-pointer px-3 py-1.5 text-sm font-semibold transition"
                                       :class="marks[{{ $enrollment->student_id }}] === '{{ $status->value }}'
                                           ? '{{ match ($status->value) {
                                               'present' => 'bg-emerald-500 text-white',
                                               'absent' => 'bg-rose-500 text-white',
                                               'leave' => 'bg-sky-500 text-white',
                                               default => 'bg-amber-500 text-white',
                                           } }}'
                                           : 'bg-white text-slate-500 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-400 dark:hover:bg-slate-800'">
                                    <input type="radio" class="sr-only"
                                           name="marks[{{ $enrollment->student_id }}][status]"
                                           value="{{ $status->value }}"
                                           x-model="marks[{{ $enrollment->student_id }}]"
                                           @checked($current === $status->value)>
                                    {{ $status->glyph() }}
                                </label>
                            @endforeach
                        </div>

                        <input type="time" name="marks[{{ $enrollment->student_id }}][check_in_time]"
                               value="{{ old('marks.'.$enrollment->student_id.'.check_in_time', $existing?->check_in_time ? app_clock($existing->check_in_time, 'H:i') : '') }}"
                               class="w-28 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-950/40 dark:text-white"
                               title="Check-in time — past {{ $grace }} minutes this becomes late">

                        <input type="text" name="marks[{{ $enrollment->student_id }}][remarks]"
                               value="{{ old('marks.'.$enrollment->student_id.'.remarks', $existing?->remarks) }}"
                               placeholder="Note"
                               class="w-32 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-950/40 dark:text-white">

                        @if ($existing?->wasAmended())
                            <x-ui.badge color="amber" size="xs" :title="$existing->amendment_reason">Corrected</x-ui.badge>
                        @endif
                        @if ($existing && ! $existing->marked_via->isHumanJudgement())
                            <x-ui.badge color="slate" size="xs">By the system</x-ui.badge>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200/80 px-4 py-3 dark:border-slate-800">
                <p class="text-xs text-slate-400">
                    A check-in past {{ $grace }} minutes is stored as late, with the minutes recorded.
                    Keyboard: <kbd class="rounded bg-slate-100 px-1 dark:bg-slate-800">p</kbd>
                    <kbd class="rounded bg-slate-100 px-1 dark:bg-slate-800">a</kbd>
                    <kbd class="rounded bg-slate-100 px-1 dark:bg-slate-800">l</kbd>
                    <kbd class="rounded bg-slate-100 px-1 dark:bg-slate-800">t</kbd>
                    mark and advance.
                </p>

                <x-ui.button type="submit" variant="primary" icon="check" :disabled="! $canBeMarked">Save the register</x-ui.button>
            </div>
        </x-ui.card>
    </form>

    @push('scripts')
        <script>
            function attendanceRegister() {
                const students = @json($roster->pluck('student_id')->values());
                const initial = @json($roster->mapWithKeys(fn ($e) => [
                    $e->student_id => old('marks.'.$e->student_id.'.status', $e->attendanceForSession?->status?->value ?? 'present'),
                ]));

                return {
                    students,
                    marks: { ...initial },
                    original: { ...initial },
                    focused: 0,
                    counts: { present: 0, absent: 0, leave: 0, late: 0 },

                    init() {
                        this.recount();
                        this.$watch('marks', () => this.recount());
                    },

                    recount() {
                        const counts = { present: 0, absent: 0, leave: 0, late: 0 };
                        Object.values(this.marks).forEach((value) => { counts[value] = (counts[value] || 0) + 1; });
                        this.counts = counts;
                    },

                    percentage() {
                        const present = this.counts.present + this.counts.late;
                        const denominator = present + this.counts.absent;

                        return denominator === 0 ? 0 : Math.round((present * 1000) / denominator) / 10;
                    },

                    markAll(status) {
                        this.students.forEach((id) => { this.marks[id] = status; });
                    },

                    invert() {
                        this.students.forEach((id) => {
                            this.marks[id] = this.marks[id] === 'present' ? 'absent' : 'present';
                        });
                    },

                    reset() {
                        this.marks = { ...this.original };
                    },

                    onKey(event) {
                        if (event.target.tagName === 'INPUT' && event.target.type !== 'radio') {
                            return;
                        }

                        const map = { p: 'present', a: 'absent', l: 'leave', t: 'late' };
                        const status = map[event.key.toLowerCase()];

                        if (! status) {
                            return;
                        }

                        event.preventDefault();
                        this.marks[this.students[this.focused]] = status;
                        this.focused = Math.min(this.focused + 1, this.students.length - 1);
                    },
                };
            }
        </script>
    @endpush
@endif
