{{--
    The brand block: logo from settings with an initials fallback.

    Included with variables:
        @include('layouts.partials.brand', ['href' => route('admin.dashboard'), 'size' => 'md'])

    Settings read: company.name, company.short_name, company.logo_path, company.tagline.
--}}

@php
    $brandName = setting('company.name', config('app.name', 'My Office'));
    $brandShort = setting('company.short_name');
    $brandTagline = ($showTagline ?? false) ? setting('company.tagline') : null;
    $logoPath = setting('company.logo_path');

    $logoUrl = null;

    if (filled($logoPath)) {
        $logoUrl = str_starts_with((string) $logoPath, 'http')
            ? $logoPath
            : \Illuminate\Support\Facades\Storage::disk('public')->url((string) $logoPath);
    }

    // "My Office ERP" => "MO"; single word => first two letters.
    $words = preg_split('/\s+/', trim((string) $brandName)) ?: [];
    $words = array_values(array_filter($words));

    if (count($words) > 1) {
        $brandInitials = mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
    } else {
        $brandInitials = mb_strtoupper(mb_substr((string) ($words[0] ?? 'M'), 0, 2));
    }

    $href = $href ?? url('/');
    $markSize = ($size ?? 'md') === 'lg' ? 'h-11 w-11 text-base' : 'h-9 w-9 text-sm';
@endphp

<a
    href="{{ $href }}"
    class="rail-trigger rail-center group relative flex min-w-0 items-center gap-3 rounded-lg px-1 py-1 transition-colors hover:bg-slate-100/70 dark:hover:bg-slate-800/60"
>
    @if ($logoUrl)
        <img
            src="{{ $logoUrl }}"
            alt="{{ $brandName }}"
            class="{{ $markSize }} shrink-0 rounded-lg object-contain"
        />
    @else
        <span
            class="{{ $markSize }} inline-flex shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 font-bold tracking-tight text-white shadow-sm ring-1 ring-inset ring-white/10"
            aria-hidden="true"
        >{{ $brandInitials }}</span>
    @endif

    <span class="rail-hide min-w-0">
        <span class="block truncate text-sm font-semibold tracking-tight text-slate-900 dark:text-white">
            {{ $brandShort ?: $brandName }}
        </span>

        @if (filled($brandTagline))
            <span class="block truncate text-2xs text-slate-500 dark:text-slate-400">{{ $brandTagline }}</span>
        @endif
    </span>

    {{-- Tooltip shown only while the sidebar is a rail. --}}
    <span class="rail-tooltip absolute left-full top-1/2 z-dropdown ml-2 -translate-y-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white shadow-lg dark:bg-slate-700">
        {{ $brandName }}
    </span>
</a>
