<?php

declare(strict_types=1);

namespace Tests\Unit\Institute;

use App\Enums\PageOrientation;
use App\Enums\PaperSize;
use App\Enums\PrintTemplateType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Paper arithmetic, with no database and no framework (phase-19-23 §3.3).
 *
 * **`cr80` is why `PaperSize` is an enum rather than two columns.** An ID card is 85.60 × 53.98 mm —
 * the ISO/IEC 7810 ID-1 size a card printer expects — and a template a millimetre out produces cards
 * that do not sit in a lanyard holder. Naming the size means nobody types those two numbers, and this
 * file is what stops somebody "tidying" them.
 */
final class PaperSizeTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | The ID-card size, which is the one that must not drift
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function cr80_is_the_iso_id1_card_size(): void
    {
        $this->assertSame(85.60, PaperSize::Cr80->widthMm());
        $this->assertSame(53.98, PaperSize::Cr80->heightMm());
    }

    /**
     * dompdf has no name for a card, so it gets a points array. 1 pt = 1/72 inch, 1 inch = 25.4 mm:
     * 85.60 × 72 ÷ 25.4 = 242.65 and 53.98 × 72 ÷ 25.4 = **153.01**.
     *
     * The second figure is 153.0141…, and I first wrote this assertion as `153.0` because a card is
     * "about 153 points tall". Computing it separately is what caught it. Two hundredths of a point
     * is a fortieth of a millimetre and would have changed nothing on a printer — but a test that
     * asserts a number nobody checked is a test that will one day be "fixed" to match whatever the
     * code does.
     */
    #[Test]
    public function cr80_converts_to_the_points_dompdf_wants(): void
    {
        $this->assertSame([0.0, 0.0, 242.65, 153.01], PaperSize::Cr80->dompdfPaper());
    }

    #[Test]
    public function the_four_named_sizes_pass_their_name_through(): void
    {
        $this->assertSame('a4', PaperSize::A4->dompdfPaper());
        $this->assertSame('a5', PaperSize::A5->dompdfPaper());
        $this->assertSame('letter', PaperSize::Letter->dompdfPaper());
        $this->assertSame('legal', PaperSize::Legal->dompdfPaper());
    }

    #[Test]
    public function a4_is_a4(): void
    {
        $this->assertSame(210.0, PaperSize::A4->widthMm());
        $this->assertSame(297.0, PaperSize::A4->heightMm());
    }

    /**
     * **`custom` carries no dimensions of its own**, deliberately: the row supplies them and
     * `chk_pt_custom` refuses a custom template that does not. An enum case holding per-row state
     * would be an enum pretending to be a value object.
     */
    #[Test]
    public function custom_has_no_dimensions_and_says_it_needs_them(): void
    {
        $this->assertNull(PaperSize::Custom->widthMm());
        $this->assertNull(PaperSize::Custom->heightMm());
        $this->assertTrue(PaperSize::Custom->needsExplicitDimensions());

        foreach ([PaperSize::A4, PaperSize::A5, PaperSize::Letter, PaperSize::Legal, PaperSize::Cr80] as $size) {
            $this->assertFalse($size->needsExplicitDimensions(), $size->value.' should know its own size.');
        }
    }

    #[Test]
    public function the_points_helper_rounds_to_two_places(): void
    {
        $this->assertSame([0.0, 0.0, 595.28, 841.89], PaperSize::points(210.0, 297.0));
    }

    /*
    |--------------------------------------------------------------------------
    | Orientation — the swap every consumer would otherwise have to remember
    |--------------------------------------------------------------------------
    */

    /**
     * The `PaperSize` cases give **portrait** figures, so a landscape template has to swap them. If
     * each consumer did it, one would forget, and the symptom would be a certificate cropped down its
     * long edge rather than an error.
     */
    #[Test]
    public function landscape_swaps_the_dimensions_and_portrait_does_not(): void
    {
        $this->assertSame([297.0, 210.0], PageOrientation::Landscape->orient(210.0, 297.0));
        $this->assertSame([210.0, 297.0], PageOrientation::Portrait->orient(210.0, 297.0));
    }

    #[Test]
    public function orientation_reports_itself_to_dompdf_by_name(): void
    {
        $this->assertSame('landscape', PageOrientation::Landscape->dompdfOrientation());
        $this->assertSame('portrait', PageOrientation::Portrait->dompdfOrientation());
        $this->assertTrue(PageOrientation::Landscape->isLandscape());
        $this->assertFalse(PageOrientation::Portrait->isLandscape());
    }

    /*
    |--------------------------------------------------------------------------
    | What each kind of template starts as
    |--------------------------------------------------------------------------
    */

    /**
     * An ID card defaults to `cr80` because that is the only size a card printer accepts. Offering A4
     * would produce a first template nobody can print.
     */
    #[Test]
    public function each_template_type_starts_at_a_sensible_size(): void
    {
        $this->assertSame(PaperSize::Cr80, PrintTemplateType::StudentIdCard->defaultPaperSize());
        $this->assertSame(PaperSize::A4, PrintTemplateType::Certificate->defaultPaperSize());
        $this->assertSame(PaperSize::A4, PrintTemplateType::ResultCard->defaultPaperSize());
    }

    /** A certificate reads across the page; a card and a result sheet read down it. */
    #[Test]
    public function a_certificate_starts_landscape_and_the_others_portrait(): void
    {
        $this->assertSame(PageOrientation::Landscape, PrintTemplateType::Certificate->defaultOrientation());
        $this->assertSame(PageOrientation::Portrait, PrintTemplateType::StudentIdCard->defaultOrientation());
        $this->assertSame(PageOrientation::Portrait, PrintTemplateType::ResultCard->defaultOrientation());
    }

    /**
     * A result card carries no QR code — it is not a document anybody verifies, it is a report of
     * marks. The template form forces the flag off rather than offering a switch that does nothing.
     */
    #[Test]
    public function only_verifiable_documents_carry_a_qr_code(): void
    {
        $this->assertTrue(PrintTemplateType::Certificate->supportsQr());
        $this->assertTrue(PrintTemplateType::StudentIdCard->supportsQr());
        $this->assertFalse(PrintTemplateType::ResultCard->supportsQr());
    }

    /**
     * Each type's print ability is a permission that actually exists. A `permission()` returning a
     * hopeful string would gate nothing and fail open.
     */
    #[Test]
    public function each_type_names_a_real_print_permission(): void
    {
        $this->assertSame('certificates.print', PrintTemplateType::Certificate->permission());
        $this->assertSame('student_id_cards.print', PrintTemplateType::StudentIdCard->permission());
        $this->assertSame('results.print', PrintTemplateType::ResultCard->permission());
    }
}
