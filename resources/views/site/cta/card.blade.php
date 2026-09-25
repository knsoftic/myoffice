{{--
    CTA variant `card` (CtaVariant::Card) — a quiet, centred card on the page surface, for a call to
    action that should invite rather than shout. Variables as prepared by <x-site.cta> (see banner).

    The card is always a light surface in the light theme and a slate surface in the dark theme. A
    background image becomes a wide media strip across its top; a background colour becomes its top
    accent rule.
--}}

<div class="mx-auto max-w-3xl">
    <div
        class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card dark:border-white/10 dark:bg-slate-900"
        @if ($color !== null) style="border-top: 4px solid {{ $color }}" @endif
    >
        @if ($background !== null)
            <div class="aspect-[21/9] bg-slate-100 dark:bg-slate-800">
                <x-site.image :media="$background" profile="banner" class="h-full w-full" />
            </div>
        @endif

        <div class="px-6 py-10 text-center sm:px-12 sm:py-12">
            @if ($background === null)
                <span class="mx-auto mb-6 inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20" aria-hidden="true">
                    <x-ui.icon name="sparkles" class="h-6 w-6" />
                </span>
            @endif

            <{{ $headingTag }} class="text-balance text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-white">{{ $heading }}</{{ $headingTag }}>

            @if ($subheading !== '')
                <p class="mt-3 text-pretty text-base text-slate-700 sm:text-lg dark:text-slate-200">{{ $subheading }}</p>
            @endif

            @if ($description !== '')
                <p class="mx-auto mt-3 max-w-xl whitespace-pre-line text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ $description }}</p>
            @endif

            @if ($buttons !== [])
                <div data-fx="rise" data-fx-delay="2" class="mt-8 flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:items-center">
                    @foreach ($buttons as $button)
                        <x-site.button :link="$button" />
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
