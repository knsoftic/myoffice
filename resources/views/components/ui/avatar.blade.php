@props([
    'src' => null,
    'name' => null,
    'size' => 'md',
    'ring' => false,
    'status' => null,
])

{{--
    x-ui.avatar — image with a deterministic initials fallback.

        <x-ui.avatar :src="$user->avatar_url" :name="$user->name" size="lg" />
        <x-ui.avatar name="Ayesha Khan" status="emerald" />

    The fallback tint is derived from the name, so the same person always gets the same
    colour across the product.
--}}

@php
    $sizes = [
        'xs' => ['h-6 w-6', 'text-[10px]', 'h-1.5 w-1.5'],
        'sm' => ['h-8 w-8', 'text-xs', 'h-2 w-2'],
        'md' => ['h-9 w-9', 'text-xs', 'h-2.5 w-2.5'],
        'lg' => ['h-11 w-11', 'text-sm', 'h-3 w-3'],
        'xl' => ['h-16 w-16', 'text-lg', 'h-3.5 w-3.5'],
        '2xl' => ['h-24 w-24', 'text-2xl', 'h-4 w-4'],
    ];

    [$box, $type, $dot] = $sizes[$size] ?? $sizes['md'];

    $clean = trim(preg_replace('/\s+/', ' ', (string) $name));
    $words = $clean === '' ? [] : explode(' ', $clean);

    $initials = strtoupper(implode('', array_map(
        static fn (string $word): string => mb_substr($word, 0, 1),
        count($words) > 1 ? [$words[0], $words[count($words) - 1]] : $words,
    )));

    $initials = $initials === '' ? '?' : mb_substr($initials, 0, 2);

    $tints = [
        'bg-brand-100 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300',
        'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
        'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
        'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
        'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300',
        'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300',
    ];

    $tint = $tints[array_sum(array_map('ord', str_split($clean === '' ? 'x' : $clean))) % count($tints)];

    $statusTints = [
        'emerald' => 'bg-emerald-500',
        'amber' => 'bg-amber-500',
        'rose' => 'bg-rose-500',
        'slate' => 'bg-slate-400',
        'sky' => 'bg-sky-500',
    ];
@endphp

<span {{ $attributes->class('relative inline-flex shrink-0') }}>
    @if ($src)
        <img
            src="{{ $src }}"
            alt="{{ $clean !== '' ? $clean : 'Avatar' }}"
            class="{{ $box }} rounded-full bg-slate-100 object-cover dark:bg-slate-800 {{ $ring ? 'ring-2 ring-white dark:ring-slate-900' : '' }}"
            loading="lazy"
        />
    @else
        <span
            class="{{ $box }} {{ $type }} {{ $tint }} inline-flex select-none items-center justify-center rounded-full font-semibold uppercase tracking-tight {{ $ring ? 'ring-2 ring-white dark:ring-slate-900' : '' }}"
            @if ($clean !== '') title="{{ $clean }}" @endif
            aria-hidden="true"
        >{{ $initials }}</span>

        @if ($clean !== '')
            <span class="sr-only">{{ $clean }}</span>
        @endif
    @endif

    @if ($status)
        <span class="absolute bottom-0 right-0 block {{ $dot }} rounded-full ring-2 ring-white dark:ring-slate-900 {{ $statusTints[$status] ?? $statusTints['slate'] }}" aria-hidden="true"></span>
    @endif
</span>
