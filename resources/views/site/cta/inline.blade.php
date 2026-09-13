{{--
    CTA variant `inline` (CtaVariant::Inline) — a compact strip: the message on the left, the buttons on
    the right (stacked on small screens). Variables as prepared by <x-site.cta> (see banner).

    Meant to sit between two content sections without breaking their rhythm, so it carries no media; a
    background colour becomes the strip's leading accent bar.
--}}

<div
    class="relative flex flex-col gap-6 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 px-6 py-7 sm:px-10 lg:flex-row lg:items-center lg:justify-between lg:gap-10 dark:border-white/10 dark:bg-white/[0.03]"
>
    <span
        @class(['absolute inset-y-0 left-0 w-1', 'bg-brand-500' => $color === null])
        @if ($color !== null) style="background-color: {{ $color }}" @endif
        aria-hidden="true"
    ></span>

    <div class="min-w-0">
        <{{ $headingTag }} class="text-xl font-semibold tracking-tight text-slate-900 sm:text-2xl dark:text-white">{{ $heading }}</{{ $headingTag }}>

        @if ($subheading !== '')
            <p class="mt-1.5 text-base text-slate-700 dark:text-slate-300">{{ $subheading }}</p>
        @endif

        @if ($description !== '')
            <p class="mt-2 max-w-2xl whitespace-pre-line text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ $description }}</p>
        @endif
    </div>

    @if ($buttons !== [])
        <div class="flex shrink-0 flex-col gap-3 sm:flex-row sm:items-center">
            @foreach ($buttons as $button)
                <x-site.button :link="$button" />
            @endforeach
        </div>
    @endif
</div>
