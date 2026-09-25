{{--
    CTA variant `split` (CtaVariant::Split) — text and buttons on one half, a visual on the other.
    Variables as prepared by <x-site.cta> (see banner).

    The visual half is the background image when there is one; otherwise the editor's colour, or the
    brand gradient with a faint grid. On small screens the visual becomes a short band above the copy.
--}}

<div class="grid overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl lg:grid-cols-2 dark:border-white/10 dark:bg-slate-900">
    <div data-fx="left" class="order-2 flex flex-col justify-center px-6 py-12 sm:px-12 lg:order-1 lg:py-16">
        <{{ $headingTag }} class="text-balance text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white">{{ $heading }}</{{ $headingTag }}>

        @if ($subheading !== '')
            <p class="mt-4 text-pretty text-lg text-slate-700 dark:text-slate-200">{{ $subheading }}</p>
        @endif

        @if ($description !== '')
            <p class="mt-4 whitespace-pre-line text-base leading-relaxed text-slate-600 dark:text-slate-400">{{ $description }}</p>
        @endif

        @if ($buttons !== [])
            <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                @foreach ($buttons as $button)
                    <x-site.button :link="$button" size="lg" />
                @endforeach
            </div>
        @endif
    </div>

    <div data-fx="right" data-fx-delay="1" class="relative order-1 min-h-48 overflow-hidden lg:order-2 lg:min-h-full" aria-hidden="true">
        @if ($background !== null)
            <x-site.image :media="$background" profile="card" class="absolute inset-0 h-full w-full" />
        @elseif ($color !== null)
            <div class="absolute inset-0" style="background-color: {{ $color }}"></div>
        @else
            <div class="absolute inset-0 bg-gradient-to-br from-brand-500 via-brand-600 to-brand-800"></div>
            <div
                class="absolute inset-0 opacity-40"
                style="background-image: linear-gradient(to right, rgb(var(--brand-50) / 0.14) 1px, transparent 1px), linear-gradient(to bottom, rgb(var(--brand-50) / 0.14) 1px, transparent 1px); background-size: 2.5rem 2.5rem;"
            ></div>
            <div class="absolute -bottom-16 -right-16 h-64 w-64 rounded-full bg-white/10 blur-2xl"></div>
            <div class="absolute inset-0 flex items-center justify-center">
                <span class="inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-white/15 text-white shadow-lg ring-1 ring-inset ring-white/25 backdrop-blur">
                    <x-ui.icon name="sparkles" class="h-10 w-10" />
                </span>
            </div>
        @endif
    </div>
</div>
