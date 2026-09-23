<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which way up a printable document is laid out (phase-19-23 §3.3).
 *
 * Two cases and no cleverness. It exists rather than being a boolean because `is_landscape = false`
 * on a template row tells a reader nothing about what it prints, and because dompdf wants the word.
 */
enum PageOrientation: string
{
    use HasOptions;

    case Portrait = 'portrait';
    case Landscape = 'landscape';

    public function label(): string
    {
        return match ($this) {
            self::Portrait => 'Portrait',
            self::Landscape => 'Landscape',
        };
    }

    public function color(): string
    {
        return 'slate';
    }

    public function isLandscape(): bool
    {
        return $this === self::Landscape;
    }

    /**
     * Swap a portrait width and height when the page is turned.
     *
     * The `PaperSize` cases give portrait dimensions, so every consumer that renders a landscape
     * template would otherwise have to remember to swap them — and one that forgot would produce a
     * certificate cropped down its long edge.
     *
     * @return array{0: float, 1: float}
     */
    public function orient(float $widthMm, float $heightMm): array
    {
        return $this === self::Landscape ? [$heightMm, $widthMm] : [$widthMm, $heightMm];
    }

    public function dompdfOrientation(): string
    {
        return $this->value;
    }
}
