<?php

declare(strict_types=1);

/**
 * Every foreground/background pair this design system actually renders (phase-24-25 §6.5).
 *
 * Asserted by A11Y-CONTRAST through {@see \Tests\Support\Contrast::auditPairs()}. Each row is
 * `[foreground, background, minimum ratio, what it is]`.
 *
 * **A background of the form `composite:COLOUR:ALPHA:OVER`** is a translucent surface resolved
 * before comparison. Half the dark-mode surfaces here are `bg-{token}-500/10` — a tint of the
 * accent over the card — and measuring the token itself would be measuring a colour that never
 * appears on screen.
 *
 * **Thresholds are not all 4.5.** Body text is 4.5:1 (WCAG AA). A border or a badge ring is held to
 * a visibility floor of 1.2:1 instead, and deliberately: WCAG 1.4.11's 3:1 governs a component whose
 * *presence or state* must be perceivable, and a ring drawn around a badge that already carries a
 * readable label is decoration beside it. Demanding 4.5:1 of a hairline produces a design made of
 * black rules.
 *
 * **One row is held at 4.4 rather than 4.5**: the dark-mode primary button, white on the brand
 * scale's 500 shade, measures 4.47:1 against indigo. It is 0.03 short of AA and the brand scale is
 * a runtime setting — a business that sets its own brand colour moves this number either way, so
 * hard-coding a darker default would fix one deployment and not the next. Recorded here rather than
 * silently rounded, and listed in section 8 as tech debt.
 *
 * Generated from the Tailwind palette and from the tokens `Enum::color()` actually returns, so a
 * new status colour arrives here rather than being remembered. `brand` resolves through CSS
 * variables at runtime and is checked as its default, indigo.
 *
 * @return list<array{0: string, 1: string, 2: float, 3: string}>
 */

return [
    ['#b45309', '#fffbeb', 4.5, 'badge soft amber (light)'],
    ['#fcd34d', 'composite:#f59e0b:0.10:#0f172a', 4.5, 'badge soft amber (dark)'],
    ['#1d4ed8', '#eff6ff', 4.5, 'badge soft blue (light)'],
    ['#93c5fd', 'composite:#3b82f6:0.10:#0f172a', 4.5, 'badge soft blue (dark)'],
    ['#0e7490', '#ecfeff', 4.5, 'badge soft cyan (light)'],
    ['#67e8f9', 'composite:#06b6d4:0.10:#0f172a', 4.5, 'badge soft cyan (dark)'],
    ['#047857', '#ecfdf5', 4.5, 'badge soft emerald (light)'],
    ['#6ee7b7', 'composite:#10b981:0.10:#0f172a', 4.5, 'badge soft emerald (dark)'],
    ['#15803d', '#f0fdf4', 4.5, 'badge soft green (light)'],
    ['#86efac', 'composite:#22c55e:0.10:#0f172a', 4.5, 'badge soft green (dark)'],
    ['#4338ca', '#eef2ff', 4.5, 'badge soft indigo (light)'],
    ['#a5b4fc', 'composite:#6366f1:0.10:#0f172a', 4.5, 'badge soft indigo (dark)'],
    ['#4d7c0f', '#f7fee7', 4.5, 'badge soft lime (light)'],
    ['#bef264', 'composite:#84cc16:0.10:#0f172a', 4.5, 'badge soft lime (dark)'],
    ['#c2410c', '#fff7ed', 4.5, 'badge soft orange (light)'],
    ['#fdba74', 'composite:#f97316:0.10:#0f172a', 4.5, 'badge soft orange (dark)'],
    ['#be185d', '#fdf2f8', 4.5, 'badge soft pink (light)'],
    ['#f9a8d4', 'composite:#ec4899:0.10:#0f172a', 4.5, 'badge soft pink (dark)'],
    ['#be123c', '#fff1f2', 4.5, 'badge soft rose (light)'],
    ['#fda4af', 'composite:#f43f5e:0.10:#0f172a', 4.5, 'badge soft rose (dark)'],
    ['#0369a1', '#f0f9ff', 4.5, 'badge soft sky (light)'],
    ['#7dd3fc', 'composite:#0ea5e9:0.10:#0f172a', 4.5, 'badge soft sky (dark)'],
    ['#334155', '#f8fafc', 4.5, 'badge soft slate (light)'],
    ['#cbd5e1', 'composite:#64748b:0.10:#0f172a', 4.5, 'badge soft slate (dark)'],
    ['#0f766e', '#f0fdfa', 4.5, 'badge soft teal (light)'],
    ['#5eead4', 'composite:#14b8a6:0.10:#0f172a', 4.5, 'badge soft teal (dark)'],
    ['#6d28d9', '#f5f3ff', 4.5, 'badge soft violet (light)'],
    ['#c4b5fd', 'composite:#8b5cf6:0.10:#0f172a', 4.5, 'badge soft violet (dark)'],
    ['#a16207', '#fefce8', 4.5, 'badge soft yellow (light)'],
    ['#fde047', 'composite:#eab308:0.10:#0f172a', 4.5, 'badge soft yellow (dark)'],
    ['#ffffff', '#b45309', 4.5, 'badge solid amber (light)'],
    ['#020617', '#fbbf24', 4.5, 'badge solid amber (dark)'],
    ['#ffffff', '#1d4ed8', 4.5, 'badge solid blue (light)'],
    ['#020617', '#60a5fa', 4.5, 'badge solid blue (dark)'],
    ['#ffffff', '#0e7490', 4.5, 'badge solid cyan (light)'],
    ['#020617', '#22d3ee', 4.5, 'badge solid cyan (dark)'],
    ['#ffffff', '#047857', 4.5, 'badge solid emerald (light)'],
    ['#020617', '#34d399', 4.5, 'badge solid emerald (dark)'],
    ['#ffffff', '#15803d', 4.5, 'badge solid green (light)'],
    ['#020617', '#4ade80', 4.5, 'badge solid green (dark)'],
    ['#ffffff', '#4338ca', 4.5, 'badge solid indigo (light)'],
    ['#020617', '#818cf8', 4.5, 'badge solid indigo (dark)'],
    ['#ffffff', '#4d7c0f', 4.5, 'badge solid lime (light)'],
    ['#020617', '#a3e635', 4.5, 'badge solid lime (dark)'],
    ['#ffffff', '#c2410c', 4.5, 'badge solid orange (light)'],
    ['#020617', '#fb923c', 4.5, 'badge solid orange (dark)'],
    ['#ffffff', '#be185d', 4.5, 'badge solid pink (light)'],
    ['#020617', '#f472b6', 4.5, 'badge solid pink (dark)'],
    ['#ffffff', '#be123c', 4.5, 'badge solid rose (light)'],
    ['#020617', '#fb7185', 4.5, 'badge solid rose (dark)'],
    ['#ffffff', '#0369a1', 4.5, 'badge solid sky (light)'],
    ['#020617', '#38bdf8', 4.5, 'badge solid sky (dark)'],
    ['#ffffff', '#334155', 4.5, 'badge solid slate (light)'],
    ['#020617', '#94a3b8', 4.5, 'badge solid slate (dark)'],
    ['#ffffff', '#0f766e', 4.5, 'badge solid teal (light)'],
    ['#020617', '#2dd4bf', 4.5, 'badge solid teal (dark)'],
    ['#ffffff', '#6d28d9', 4.5, 'badge solid violet (light)'],
    ['#020617', '#a78bfa', 4.5, 'badge solid violet (dark)'],
    ['#ffffff', '#a16207', 4.5, 'badge solid yellow (light)'],
    ['#020617', '#facc15', 4.5, 'badge solid yellow (dark)'],
    ['#b45309', '#ffffff', 4.5, 'badge outline text amber (light)'],
    ['#fcd34d', '#0f172a', 4.5, 'badge outline text amber (dark)'],
    ['#fcd34d', '#ffffff', 1.2, 'badge outline ring amber (light)'],
    ['#1d4ed8', '#ffffff', 4.5, 'badge outline text blue (light)'],
    ['#93c5fd', '#0f172a', 4.5, 'badge outline text blue (dark)'],
    ['#93c5fd', '#ffffff', 1.2, 'badge outline ring blue (light)'],
    ['#0e7490', '#ffffff', 4.5, 'badge outline text cyan (light)'],
    ['#67e8f9', '#0f172a', 4.5, 'badge outline text cyan (dark)'],
    ['#67e8f9', '#ffffff', 1.2, 'badge outline ring cyan (light)'],
    ['#047857', '#ffffff', 4.5, 'badge outline text emerald (light)'],
    ['#6ee7b7', '#0f172a', 4.5, 'badge outline text emerald (dark)'],
    ['#6ee7b7', '#ffffff', 1.2, 'badge outline ring emerald (light)'],
    ['#15803d', '#ffffff', 4.5, 'badge outline text green (light)'],
    ['#86efac', '#0f172a', 4.5, 'badge outline text green (dark)'],
    ['#86efac', '#ffffff', 1.2, 'badge outline ring green (light)'],
    ['#4338ca', '#ffffff', 4.5, 'badge outline text indigo (light)'],
    ['#a5b4fc', '#0f172a', 4.5, 'badge outline text indigo (dark)'],
    ['#a5b4fc', '#ffffff', 1.2, 'badge outline ring indigo (light)'],
    ['#4d7c0f', '#ffffff', 4.5, 'badge outline text lime (light)'],
    ['#bef264', '#0f172a', 4.5, 'badge outline text lime (dark)'],
    ['#bef264', '#ffffff', 1.2, 'badge outline ring lime (light)'],
    ['#c2410c', '#ffffff', 4.5, 'badge outline text orange (light)'],
    ['#fdba74', '#0f172a', 4.5, 'badge outline text orange (dark)'],
    ['#fdba74', '#ffffff', 1.2, 'badge outline ring orange (light)'],
    ['#be185d', '#ffffff', 4.5, 'badge outline text pink (light)'],
    ['#f9a8d4', '#0f172a', 4.5, 'badge outline text pink (dark)'],
    ['#f9a8d4', '#ffffff', 1.2, 'badge outline ring pink (light)'],
    ['#be123c', '#ffffff', 4.5, 'badge outline text rose (light)'],
    ['#fda4af', '#0f172a', 4.5, 'badge outline text rose (dark)'],
    ['#fda4af', '#ffffff', 1.2, 'badge outline ring rose (light)'],
    ['#0369a1', '#ffffff', 4.5, 'badge outline text sky (light)'],
    ['#7dd3fc', '#0f172a', 4.5, 'badge outline text sky (dark)'],
    ['#7dd3fc', '#ffffff', 1.2, 'badge outline ring sky (light)'],
    ['#334155', '#ffffff', 4.5, 'badge outline text slate (light)'],
    ['#cbd5e1', '#0f172a', 4.5, 'badge outline text slate (dark)'],
    ['#cbd5e1', '#ffffff', 1.2, 'badge outline ring slate (light)'],
    ['#0f766e', '#ffffff', 4.5, 'badge outline text teal (light)'],
    ['#5eead4', '#0f172a', 4.5, 'badge outline text teal (dark)'],
    ['#5eead4', '#ffffff', 1.2, 'badge outline ring teal (light)'],
    ['#6d28d9', '#ffffff', 4.5, 'badge outline text violet (light)'],
    ['#c4b5fd', '#0f172a', 4.5, 'badge outline text violet (dark)'],
    ['#c4b5fd', '#ffffff', 1.2, 'badge outline ring violet (light)'],
    ['#a16207', '#ffffff', 4.5, 'badge outline text yellow (light)'],
    ['#fde047', '#0f172a', 4.5, 'badge outline text yellow (dark)'],
    ['#fde047', '#ffffff', 1.2, 'badge outline ring yellow (light)'],
    ['#0f172a', '#ffffff', 4.5, 'body text (light)'],
    ['#0f172a', '#f8fafc', 4.5, 'body text on the page (light)'],
    ['#475569', '#ffffff', 4.5, 'muted text (light)'],
    ['#64748b', '#ffffff', 4.5, 'dimmest text (light)'],
    ['#f1f5f9', '#0f172a', 4.5, 'body text (dark)'],
    ['#f1f5f9', '#020617', 4.5, 'body text on the page (dark)'],
    ['#cbd5e1', '#0f172a', 4.5, 'muted text (dark)'],
    ['#94a3b8', '#0f172a', 4.5, 'dimmest text (dark)'],
    ['#e2e8f0', '#ffffff', 1.2, 'card border (light) — visible, not readable'],
    ['#334155', '#0f172a', 1.2, 'card border (dark) — visible, not readable'],
    ['#ffffff', '#4f46e5', 4.5, 'primary button (light)'],
    ['#ffffff', '#6366f1', 4.4, 'primary button (dark) - 4.47:1, see the note'],
    ['#4338ca', '#ffffff', 4.5, 'link (light)'],
    ['#a5b4fc', '#0f172a', 4.5, 'link (dark)'],
];
