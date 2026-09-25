{{--
    CTA variant `banner` (CtaVariant::Banner, the default) — a centred, rounded panel inside the page
    container. Included by <x-site.cta>, which prepares every variable:

      $heading $subheading $description  plain text          $buttons     0-2 button arrays
      $background  media array or null                        $color       '#rrggbb' or null
      $tone        'dark' | 'light'                           $headingTag  h2 (or h3)

    Surfaces: a background image under a slate overlay; else the editor's colour; else the default deep
    slate panel with brand glows. A dark surface puts its content in a forced-dark scope so the copy and
    every ButtonStyle read correctly in both site themes; a light custom colour keeps light copy in the
    light theme and yields to a dark panel in the dark theme.
--}}

<div @class(['dark' => $tone === 'dark'])>
    <div
        @class([
            'relative isolate overflow-hidden rounded-3xl px-6 py-16 text-center shadow-2xl sm:px-12 sm:py-20 lg:px-20',
            'bg-slate-900 ring-1 ring-white/10' => $color === null,
            'ring-1 ring-white/10' => $color !== null && $tone === 'dark',
            'ring-1 ring-slate-900/10 dark:!bg-slate-900 dark:ring-white/10' => $color !== null && $tone === 'light',
        ])
        @if ($color !== null) style="background-color: {{ $color }}" @endif
    >
        @if ($background !== null)
            <div class="absolute inset-0 -z-10" aria-hidden="true">
                <x-site.image :media="$background" profile="hero" class="absolute inset-0 h-full w-full" />
                <div class="absolute inset-0 bg-slate-950/70"></div>
            </div>
        @elseif ($color === null)
            <div class="pointer-events-none absolute -top-24 left-1/2 -z-10 h-72 w-[48rem] -translate-x-1/2 rounded-full bg-brand-500/30 blur-3xl" aria-hidden="true" data-fx-parallax style="--fx-far: 50px"></div>
            <div class="pointer-events-none absolute -bottom-32 -right-24 -z-10 h-72 w-72 rounded-full bg-brand-400/20 blur-3xl" aria-hidden="true" data-fx-parallax style="--fx-far: -80px"></div>
        @endif

        <div class="mx-auto max-w-2xl">
            <{{ $headingTag }} class="text-balance text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white">{{ $heading }}</{{ $headingTag }}>

            @if ($subheading !== '')
                <p class="mt-4 text-pretty text-lg text-slate-700 dark:text-slate-200">{{ $subheading }}</p>
            @endif

            @if ($description !== '')
                <p class="mt-4 whitespace-pre-line text-base leading-relaxed text-slate-600 dark:text-slate-300">{{ $description }}</p>
            @endif

            @if ($buttons !== [])
                <div data-fx="rise" data-fx-delay="2" class="mt-10 flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:items-center">
                    @foreach ($buttons as $button)
                        <x-site.button :link="$button" size="lg" />
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
