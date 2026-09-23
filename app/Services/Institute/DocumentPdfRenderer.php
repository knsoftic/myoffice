<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Models\Institute\PrintTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;

/**
 * Turning a rendered template into a PDF (phase-19-23 §6.13).
 *
 * **One place where dompdf is touched, and it is deliberately small.** Everything above it deals in
 * strings: `PrintTemplateService::render()` produces sanitised HTML with tokens replaced, and this
 * wraps it in the print layout and hands it to the renderer with the template's paper. Keeping the
 * library behind one class means the security settings are asserted in one place — and asserted they
 * are, at runtime, rather than trusted to a config file nobody rereads.
 *
 * **`enable_remote` is re-checked here, not assumed.** `config/dompdf.php` sets it false and carries a
 * paragraph saying never to change it, but a config file is a thing somebody edits at 2am to make an
 * image appear. With it on, an `<img src="http://…">` inside a template makes the *server* fetch a URL
 * chosen by whoever holds `print_templates.edit` — SSRF with a WYSIWYG editor attached. So this
 * refuses to render rather than producing a document under a setting the phase does not permit.
 *
 * **Paper comes from the template**, including the two sizes dompdf has no name for: `cr80` (the
 * 85.60 × 53.98 mm ID-card standard) and `custom` both resolve to a points array, already turned the
 * right way up for a landscape template by `PrintTemplate::dimensionsMm()`.
 */
final class DocumentPdfRenderer
{
    /**
     * Render one document to PDF bytes.
     *
     * @param  string  $bodyHtml  already sanitised and token-replaced
     */
    public function render(PrintTemplate $template, string $bodyHtml, string $title = 'Document'): string
    {
        $this->assertRemoteAccessIsOff();

        $pdf = Pdf::loadHTML($this->wrap($template, $bodyHtml, $title));

        $pdf->setPaper($template->dompdfPaper(), $template->orientation->dompdfOrientation());

        // Belt and braces over the config: if something upstream flipped it, this call wins for
        // this render. There is no legitimate template that needs it.
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('isPhpEnabled', false);
        $pdf->setOption('isJavascriptEnabled', false);

        return (string) $pdf->output();
    }

    /**
     * The document shell: the template's own stylesheet inside a page box built from its paper.
     *
     * **`@page` rather than a CSS width.** dompdf sizes the page from `setPaper()`, and the margin is
     * the only thing the stylesheet decides — a template that tried to set its own page width would
     * produce content that overflows a page it did not choose.
     *
     * The body HTML is inserted verbatim because it arrived sanitised and escaped:
     * `PrintTemplateService::render()` escapes every non-`raw` token, and the `raw` ones are images
     * and tables this application built. Escaping again here would print the markup.
     */
    private function wrap(PrintTemplate $template, string $bodyHtml, string $title): string
    {
        $margin = (float) $template->getAttribute('margin_mm');
        $css = (string) $template->getAttribute('custom_css');

        return implode('', [
            '<!DOCTYPE html><html><head><meta charset="utf-8">',
            '<title>'.e($title).'</title>',
            '<style>',
            sprintf('@page { margin: %smm; }', number_format($margin, 2, '.', '')),
            'body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 11pt; }',
            '* { box-sizing: border-box; }',
            // The template's own stylesheet last, so it wins — it has already been through
            // `RichText::sanitizeCss()`, which removes `@import`, `expression(` and every external
            // `url()`, and guarantees the string contains no `<` that could close this element.
            $css,
            '</style></head><body>',
            $bodyHtml,
            '</body></html>',
        ]);
    }

    /**
     * Refuse to render at all while remote access is on.
     *
     * A refusal is the right response rather than a silent `setOption()` override: if the config says
     * remote is enabled, something in this installation believes it should be, and quietly producing
     * one safe PDF would leave that belief in place for whatever renders next.
     */
    private function assertRemoteAccessIsOff(): void
    {
        if ((bool) config('dompdf.options.enable_remote', false)) {
            throw new RuntimeException(
                'dompdf is configured with remote file access enabled. A print template could then '
                .'make this server fetch any URL its author typed, so no document is rendered until '
                .'`dompdf.options.enable_remote` is false. See INV-21-5.'
            );
        }
    }
}
