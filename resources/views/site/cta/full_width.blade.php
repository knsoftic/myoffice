{{--
    CTA variant `full_width` (CtaVariant::FullWidth) — an edge-to-edge band with the copy on the left and
    the buttons on the right from `lg` up. Variables as prepared by <x-site.cta> (see banner).

    The section partial renders this variant without the page container, so the band owns its own
    container and vertical rhythm. Surfaces follow the banner's rules (image, colour, or deep slate with
    brand glows).
--}}

<div @class(['dark' => $tone === 'dark'])>
    <div
        @class([
            'relative isolate overflow-hidden',
            'bg-slate-950' => $color === null,
            'dark:!bg-slate-900' => $color !== null && $tone === 'light',
        ])
        @if ($color !== null) style="background-color: {{ $color }}" @endif
    >
        @if ($background !== null)
            <div class="absolute inset-0 -z-10" aria-hidden="true">
                <x-site.image :media="$background" profile="hero" class="absolute inset-0 h-full w-full" />
                <div class="absolute inset-0 bg-gradient-to-r from-slate-950/90 via-slate-950/75 to-slate-950/40"></div>
            </div>
        @elseif ($color === null)
            <div class="pointer-events-none absolute -left-40 top-0 -z-10 h-96 w-96 rounded-full bg-brand-600/30 blur-3xl" aria-hidden="true" data-fx-parallax style="--fx-far: 60px"></div>
            <div class="pointer-events-none absolute -bottom-40 right-0 -z-10 h-96 w-[40rem] rounded-full bg-brand-500/20 blur-3xl" aria-hidden="true" data-fx-parallax style="--fx-far: -90px"></div>
        @endif

        <div class="mx-auto flex max-w-screen-xl flex-col gap-10 px-4 py-16 sm:px-6 sm:py-20 lg:flex-row lg:items-center lg:justify-between lg:px-8 lg:py-24">
            <div class="max-w-2xl">
                <{{ $headingTag }} class="text-balance text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl lg:text-5xl dark:text-white">{{ $heading }}</{{ $headingTag }}>

                @if ($subheading !== '')
                    <p class="mt-4 text-pretty text-lg text-slate-700 sm:text-xl dark:text-slate-200">{{ $subheading }}</p>
                @endif

                @if ($description !== '')
                    <p class="mt-4 whitespace-pre-line text-base leading-relaxed text-slate-600 dark:text-slate-300">{{ $description }}</p>
                @endif
            </div>

            @if ($buttons !== [])
                <div data-fx="rise" data-fx-delay="2" class="flex shrink-0 flex-col gap-3 sm:flex-row sm:items-center">
                    @foreach ($buttons as $button)
                        <x-site.button :link="$button" size="lg" />
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
