{{--
    The hero's product preview, drawn rather than photographed: shown by hero.blade.php only when the
    editor has uploaded neither a hero image nor a background, so an uploaded screenshot always wins.

    Everything inside the frame is shape, not text — a sidebar, tiles, an area chart, a donut and table
    rows — so it can never state a figure the business has not given. The figures on it are the hero's own
    statistics, floating on the frame as chips (<x-site.stats variant="chips">) and resolved exactly as the
    statistics strip resolves them.

    Receives: $statistics (the hero's `statistic` items).
--}}

<div data-fx="deck" data-fx-delay="4" data-hero-preview class="relative mx-auto mt-16 max-w-5xl sm:mt-20 lg:px-6">
    <div class="pointer-events-none absolute -inset-x-6 -bottom-8 -top-10 -z-10 rounded-[3rem] bg-gradient-to-tr from-brand-500/25 via-violet-500/15 to-cyan-400/20 blur-3xl dark:from-brand-500/30 dark:via-violet-600/20 dark:to-cyan-500/15" aria-hidden="true"></div>

    <div class="rounded-2xl border border-slate-200/80 bg-white/60 p-1.5 shadow-2xl shadow-brand-900/10 ring-1 ring-slate-900/5 backdrop-blur-xl sm:p-2 dark:border-white/10 dark:bg-white/[0.04] dark:shadow-black/40 dark:ring-white/5" aria-hidden="true">
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-slate-50 dark:border-white/10 dark:bg-slate-950">
            {{-- Window bar --}}
            <div class="flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-2.5 dark:border-white/10 dark:bg-slate-900/80">
                <span class="flex gap-1.5">
                    <span class="h-2.5 w-2.5 rounded-full bg-rose-400/80"></span>
                    <span class="h-2.5 w-2.5 rounded-full bg-amber-400/80"></span>
                    <span class="h-2.5 w-2.5 rounded-full bg-emerald-400/80"></span>
                </span>
                <span class="mx-auto h-5 w-2/5 max-w-xs rounded-md bg-slate-100 dark:bg-white/5"></span>
                <span class="h-6 w-6 rounded-full bg-gradient-to-br from-brand-400 to-violet-500"></span>
            </div>

            <div class="flex">
                {{-- Sidebar --}}
                <div class="hidden w-40 shrink-0 space-y-2.5 border-r border-slate-200 p-4 sm:block dark:border-white/10">
                    <span class="mb-4 block h-6 w-24 rounded-md bg-gradient-to-r from-brand-500/80 to-violet-500/80"></span>
                    @foreach ([20, 28, 16, 24, 18, 22] as $i => $width)
                        <span @class([
                            'flex items-center gap-2 rounded-md px-2 py-1.5',
                            'bg-brand-500/10 dark:bg-brand-500/15' => $i === 0,
                        ])>
                            <span @class(['h-3 w-3 rounded', 'bg-brand-500' => $i === 0, 'bg-slate-300 dark:bg-white/15' => $i !== 0])></span>
                            <span class="h-2 rounded-full bg-slate-200 dark:bg-white/10" style="width: {{ $width * 4 }}px"></span>
                        </span>
                    @endforeach
                </div>

                {{-- Main --}}
                <div class="min-w-0 flex-1 space-y-4 p-4 sm:p-5">
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        @foreach ([['from-brand-500', 'to-violet-500', 'M0 18 L10 14 L20 16 L30 9 L40 11 L50 4 L60 6'], ['from-cyan-400', 'to-sky-500', 'M0 14 L10 16 L20 10 L30 12 L40 6 L50 8 L60 3'], ['from-emerald-400', 'to-teal-500', 'M0 16 L10 12 L20 13 L30 8 L40 10 L50 7 L60 5'], ['from-violet-400', 'to-fuchsia-500', 'M0 12 L10 15 L20 9 L30 11 L40 5 L50 9 L60 4']] as $tile)
                            <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-white/[0.03]">
                                <span class="block h-2 w-14 rounded-full bg-slate-200 dark:bg-white/10"></span>
                                <span class="mt-2.5 block h-4 w-20 rounded bg-gradient-to-r {{ $tile[0] }} {{ $tile[1] }} opacity-80"></span>
                                <svg viewBox="0 0 60 20" class="mt-2 h-5 w-full" preserveAspectRatio="none">
                                    <path d="{{ $tile[2] }}" fill="none" stroke="currentColor" stroke-width="1.5" class="text-brand-500 dark:text-brand-300" />
                                </svg>
                            </div>
                        @endforeach
                    </div>

                    <div class="grid gap-3 lg:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 bg-white p-4 lg:col-span-2 dark:border-white/10 dark:bg-white/[0.03]">
                            <div class="flex items-center justify-between">
                                <span class="h-2.5 w-28 rounded-full bg-slate-200 dark:bg-white/10"></span>
                                <span class="flex gap-1.5">
                                    <span class="h-4 w-10 rounded bg-brand-500/15"></span>
                                    <span class="h-4 w-10 rounded bg-slate-100 dark:bg-white/5"></span>
                                </span>
                            </div>
                            <svg viewBox="0 0 300 100" class="mt-4 h-28 w-full sm:h-36" preserveAspectRatio="none">
                                <defs>
                                    <linearGradient id="hero-mock-area" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="rgb(var(--brand-500))" stop-opacity="0.35" />
                                        <stop offset="100%" stop-color="rgb(var(--brand-500))" stop-opacity="0" />
                                    </linearGradient>
                                </defs>
                                <g class="text-slate-200 dark:text-white/10" stroke="currentColor" stroke-width="0.5">
                                    <line x1="0" y1="25" x2="300" y2="25" /><line x1="0" y1="50" x2="300" y2="50" /><line x1="0" y1="75" x2="300" y2="75" />
                                </g>
                                <path d="M0 78 C 25 70, 40 74, 60 62 S 100 48, 125 52 S 165 34, 190 38 S 235 20, 260 24 S 290 12, 300 10 L300 100 L0 100 Z" fill="url(#hero-mock-area)" />
                                <path d="M0 78 C 25 70, 40 74, 60 62 S 100 48, 125 52 S 165 34, 190 38 S 235 20, 260 24 S 290 12, 300 10" fill="none" stroke="rgb(var(--brand-500))" stroke-width="2" />
                                <path d="M0 88 C 30 84, 50 86, 75 80 S 120 72, 150 74 S 200 60, 230 62 S 275 50, 300 46" fill="none" stroke="rgb(34 211 238)" stroke-width="1.5" stroke-dasharray="4 4" opacity="0.7" />
                            </svg>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-white/[0.03]">
                            <span class="block h-2.5 w-20 rounded-full bg-slate-200 dark:bg-white/10"></span>
                            <div class="mt-4 flex items-center gap-4">
                                <svg viewBox="0 0 36 36" class="h-20 w-20 shrink-0 -rotate-90">
                                    <circle cx="18" cy="18" r="14" fill="none" stroke-width="5" class="stroke-slate-100 dark:stroke-white/5" />
                                    <circle cx="18" cy="18" r="14" fill="none" stroke-width="5" stroke-dasharray="50 88" stroke="rgb(var(--brand-500))" />
                                    <circle cx="18" cy="18" r="14" fill="none" stroke-width="5" stroke-dasharray="22 88" stroke-dashoffset="-50" stroke="rgb(34 211 238)" />
                                    <circle cx="18" cy="18" r="14" fill="none" stroke-width="5" stroke-dasharray="10 88" stroke-dashoffset="-72" stroke="rgb(167 139 250)" />
                                </svg>
                                <div class="flex-1 space-y-2.5">
                                    @foreach (['bg-brand-500', 'bg-cyan-400', 'bg-violet-400'] as $dot)
                                        <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ $dot }}"></span><span class="h-2 flex-1 rounded-full bg-slate-200 dark:bg-white/10"></span></span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="hidden rounded-lg border border-slate-200 bg-white sm:block dark:border-white/10 dark:bg-white/[0.03]">
                        @foreach ([['emerald', 70], ['amber', 55], ['brand', 62]] as $row)
                            <div class="flex items-center gap-4 border-b border-slate-100 px-4 py-3 last:border-0 dark:border-white/5">
                                <span class="h-6 w-6 rounded-full bg-slate-200 dark:bg-white/10"></span>
                                <span class="h-2 rounded-full bg-slate-200 dark:bg-white/10" style="width: {{ $row[1] * 2 }}px"></span>
                                <span class="ml-auto h-2 w-16 rounded-full bg-slate-100 dark:bg-white/5"></span>
                                <span @class([
                                    'h-5 w-16 rounded-full',
                                    'bg-emerald-500/15' => $row[0] === 'emerald',
                                    'bg-amber-500/15' => $row[0] === 'amber',
                                    'bg-brand-500/15' => $row[0] === 'brand',
                                ])></span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($statistics !== [])
        <x-site.stats :items="$statistics" variant="chips" />
    @endif
</div>
