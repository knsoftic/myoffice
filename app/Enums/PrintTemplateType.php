<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which printable document a template designs (phase-19-23 §3.3, [D-21-1]).
 *
 * **One table for three documents, and this is what distinguishes them.** §84 and §85 each demand an
 * admin-controlled template and §82 a printable result card; three tables would have been three
 * editors, three token lists and three sanitisers, drifting apart on their own schedules. One table
 * with a `type` gives one editor, one sanitiser and one token registry.
 *
 * **The permission is the same for all three**, because the risk is the same for all three:
 * `body_html` is powerful whatever it prints, which is why `print_templates` is separately grantable
 * (§4.1) — a designer can be given it with no sight of a student record at all.
 */
enum PrintTemplateType: string
{
    use HasOptions;

    case Certificate = 'certificate';
    case StudentIdCard = 'student_id_card';
    case ResultCard = 'result_card';

    public function label(): string
    {
        return match ($this) {
            self::Certificate => 'Certificate',
            self::StudentIdCard => 'Student ID card',
            self::ResultCard => 'Result card',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Certificate => 'emerald',
            self::StudentIdCard => 'indigo',
            self::ResultCard => 'sky',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Certificate => 'Issued on completion, numbered once, and verifiable from its QR code.',
            self::StudentIdCard => 'Printed on a plastic card. The size is fixed by the card, not by taste.',
            self::ResultCard => 'One exam or a whole course, depending on the institute’s setting.',
        };
    }

    /**
     * The size a new template of this type starts at.
     *
     * An ID card is `cr80` because that is the only size a card printer accepts — offering A4 as the
     * default would produce a first template nobody can print.
     */
    public function defaultPaperSize(): PaperSize
    {
        return match ($this) {
            self::Certificate => PaperSize::A4,
            self::StudentIdCard => PaperSize::Cr80,
            self::ResultCard => PaperSize::A4,
        };
    }

    public function defaultOrientation(): PageOrientation
    {
        return match ($this) {
            // A certificate reads across the page; a card and a result sheet read down it.
            self::Certificate => PageOrientation::Landscape,
            self::StudentIdCard, self::ResultCard => PageOrientation::Portrait,
        };
    }

    /**
     * The ability that gates **printing a document of this type** — not editing the template, which
     * is always `print_templates.edit`. Printing a certificate and printing a result card are
     * different jobs held by different people.
     */
    public function permission(): string
    {
        return match ($this) {
            self::Certificate => 'certificates.print',
            self::StudentIdCard => 'student_id_cards.print',
            self::ResultCard => 'results.print',
        };
    }

    /** Whether a document of this type carries a QR code at all (§84, §85). */
    public function supportsQr(): bool
    {
        return $this !== self::ResultCard;
    }
}
