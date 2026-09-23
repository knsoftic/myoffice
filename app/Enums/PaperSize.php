<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a printable document is printed on (phase-19-23 §3.3).
 *
 * **`cr80` is why this is an enum rather than a width and a height.** An ID card is 85.60 × 53.98 mm —
 * the ISO/IEC 7810 ID-1 standard a card printer expects — and a template that got it wrong by a
 * millimetre would produce cards that do not fit a lanyard holder. Naming the size means nobody types
 * those two numbers.
 *
 * **`custom` carries no dimensions of its own**, deliberately: `widthMm()` and `heightMm()` return
 * null and the template row supplies them, guarded by `chk_pt_custom`. An enum case that needed
 * per-row state would be an enum pretending to be a value object.
 */
enum PaperSize: string
{
    case A4 = 'a4';
    case A5 = 'a5';
    case Letter = 'letter';
    case Legal = 'legal';

    /** ISO/IEC 7810 ID-1 — the plastic-card size (§85). */
    case Cr80 = 'cr80';

    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::A4 => 'A4',
            self::A5 => 'A5',
            self::Letter => 'US Letter',
            self::Legal => 'US Legal',
            self::Cr80 => 'ID card (CR80)',
            self::Custom => 'Custom size',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cr80 => 'indigo',
            self::Custom => 'amber',
            default => 'slate',
        };
    }

    /** Portrait width in millimetres. Null for `custom`, which the row supplies. */
    public function widthMm(): ?float
    {
        return match ($this) {
            self::A4 => 210.0,
            self::A5 => 148.0,
            self::Letter => 215.9,
            self::Legal => 215.9,
            self::Cr80 => 85.60,
            self::Custom => null,
        };
    }

    public function heightMm(): ?float
    {
        return match ($this) {
            self::A4 => 297.0,
            self::A5 => 210.0,
            self::Letter => 279.4,
            self::Legal => 355.6,
            self::Cr80 => 53.98,
            self::Custom => null,
        };
    }

    public function needsExplicitDimensions(): bool
    {
        return $this === self::Custom;
    }

    /**
     * What dompdf is given. A named size for the four it knows; **a points array** for `cr80` and
     * `custom`, because dompdf has no name for either and millimetres are not its unit.
     *
     * @return string|array{0: float, 1: float, 2: float, 3: float}
     */
    public function dompdfPaper(): string|array
    {
        return match ($this) {
            self::A4 => 'a4',
            self::A5 => 'a5',
            self::Letter => 'letter',
            self::Legal => 'legal',
            self::Cr80 => self::points(85.60, 53.98),
            // A custom size cannot answer from the enum alone; the caller builds it from the row.
            self::Custom => 'a4',
        };
    }

    /**
     * Millimetres to PostScript points, which is dompdf's unit: 1 pt = 1/72 inch, 1 inch = 25.4 mm.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function points(float $widthMm, float $heightMm): array
    {
        return [0.0, 0.0, round($widthMm * 72 / 25.4, 2), round($heightMm * 72 / 25.4, 2)];
    }
}
