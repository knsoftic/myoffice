<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PageOrientation;
use App\Enums\PaperSize;
use App\Enums\PrintTemplateType;
use App\Models\Institute\PrintTemplate;
use App\Support\PrintTokenRegistry;
use App\Support\RichText;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One default template per printable kind (phase-19-23 §6.13).
 *
 * **Idempotent, and it never overwrites.** A template an institute has redesigned is theirs: re-running
 * the seeders after a deploy must not restore the layout they deliberately changed. Each one is created
 * if its code is missing and left entirely alone if it is not — the same discipline `SettingSeeder` and
 * `GradeScaleSeeder` use, and for the same reason.
 *
 * **Every token these use is checked against `PrintTokenRegistry` before anything is written.** A
 * seeded template with a typo would print a blank line on the institute's very first certificate, and
 * the warning that normally names an unknown token is a *screen* warning — nobody is watching a seeder.
 * So here it is an exception: a default that cannot render is a broken install, not a note.
 *
 * **The HTML goes through the same sanitiser as anything a person types, and that constrains the
 * markup — D137.** `RichText` keeps `class` only for the tokens in `RichText::CLASSES`; a template
 * styled on invented class names (`.doc`, `.card`, `.hdr`) loses every one of them on save, and its
 * stylesheet then selects nothing. A default that ships looking unstyled teaches the first person who
 * opens the editor that the editor is broken. So these use the allowlisted tokens — `text-center`,
 * `lead`, `note`, `muted`, `highlight` — and element selectors, which survive. Running our own strings
 * through the sanitiser costs nothing and proves something: if the `material` profile ever tightens
 * past what a real template needs, this seeder is the first thing that notices.
 */
final class PrintTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $definition) {
            if (PrintTemplate::query()->where('code', $definition['code'])->exists()) {
                $this->command?->info(sprintf('Print template %s already exists — left as it is.', $definition['code']));

                continue;
            }

            $type = $definition['type'];
            $html = $definition['body_html'];

            $unknown = PrintTokenRegistry::unknownIn($type, $html);

            if ($unknown !== []) {
                throw new RuntimeException(sprintf(
                    'The seeded %s template uses tokens that do not exist: %s. A default nobody can '
                    .'render is a broken install.',
                    $type->value,
                    implode(', ', $unknown),
                ));
            }

            $sanitised = RichText::sanitize($html, 'material');
            $css = RichText::sanitizeCss($definition['custom_css'], 'material');

            // The layout is only as good as what survives sanitising, and what survives is checked
            // here rather than discovered on a printed certificate. A token the sanitiser ate is a
            // blank line in the middle of somebody's name.
            $lost = array_diff(
                PrintTokenRegistry::mentionedIn($html),
                PrintTokenRegistry::mentionedIn($sanitised),
            );

            if ($lost !== []) {
                throw new RuntimeException(sprintf(
                    'Sanitising the seeded %s template dropped these tokens: %s.',
                    $type->value,
                    implode(', ', $lost),
                ));
            }

            if (trim($definition['custom_css']) !== '' && trim($css) === '') {
                throw new RuntimeException(sprintf(
                    'Sanitising the seeded %s stylesheet left nothing behind, so the template would '
                    .'print unstyled. See D137.',
                    $type->value,
                ));
            }

            DB::transaction(function () use ($definition, $type, $sanitised, $css): void {
                $template = new PrintTemplate;
                $template->forceFill([
                    'type' => $type->value,
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'paper_size' => $definition['paper_size']->value,
                    'orientation' => $definition['orientation']->value,
                    'margin_mm' => $definition['margin_mm'],
                    'body_html' => $sanitised,
                    'custom_css' => $css !== '' ? $css : null,
                    'tokens_used' => PrintTokenRegistry::mentionedIn($sanitised),
                    'show_qr' => $type->supportsQr(),
                    'qr_size_mm' => $definition['qr_size_mm'],
                    // The seeder makes each one the default for its type, because an institute's very
                    // first certificate needs a template and `CertificateService` falls through to
                    // whichever row carries the flag. One per type, so `uq_pt_default` is satisfied.
                    'is_default' => true,
                    'is_active' => true,
                    'sort_order' => 0,
                ]);
                $template->save();
            });

            $this->command?->info(sprintf('Seeded the %s template.', $definition['code']));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            [
                'type' => PrintTemplateType::Certificate,
                'code' => 'DEFAULT-CERTIFICATE',
                'name' => 'Standard certificate',
                'description' => 'A landscape A4 certificate of completion with a verification QR code.',
                'paper_size' => PaperSize::A4,
                'orientation' => PageOrientation::Landscape,
                'margin_mm' => '14.00',
                'qr_size_mm' => '25.00',
                'custom_css' => 'body { text-align: center; }'
                    .' h1 { font-size: 34pt; margin: 0 0 4mm; }'
                    .' .lead { font-size: 26pt; margin: 6mm 0 2mm; }'
                    .' .note { font-size: 15pt; margin: 2mm 0; }'
                    .' .muted { font-size: 10pt; margin-top: 8mm; }'
                    .' table { margin-top: 14mm; }'
                    .' td { font-size: 10pt; padding-top: 2mm; border-top: 1px solid #333333; }',
                'body_html' => <<<'HTML'
                    <div class="text-center">
                        <p class="muted">{company_name}</p>
                        <h1>Certificate of Completion</h1>
                        <p class="note">This is to certify that</p>
                        <p class="lead"><strong>{student_name}</strong></p>
                        <p class="note">has successfully completed</p>
                        <p class="lead">{course_name}</p>
                        <p class="note">on {completion_date}, achieving grade <strong>{grade}</strong>.</p>
                        <p class="muted">Certificate no. {certificate_number} &middot; issued {issued_on}</p>
                        <div>{qr}</div>
                        <p class="muted">{footer_note}</p>
                        <table width="100%">
                            <tr>
                                <td width="45%">{signatory_1_name}<br>{signatory_1_title}</td>
                                <td width="10%"></td>
                                <td width="45%">{signatory_2_name}<br>{signatory_2_title}</td>
                            </tr>
                        </table>
                    </div>
                    HTML,
            ],
            [
                'type' => PrintTemplateType::StudentIdCard,
                'code' => 'DEFAULT-ID-CARD',
                'name' => 'Standard student card',
                // CR80 is the only size a card printer accepts; offering A4 would give an institute a
                // first template nobody can print.
                'description' => 'A CR80 student card, the size a card printer expects.',
                'paper_size' => PaperSize::Cr80,
                'orientation' => PageOrientation::Portrait,
                'margin_mm' => '3.00',
                'qr_size_mm' => '14.00',
                'custom_css' => 'body { font-size: 7pt; text-align: center; }'
                    .' .highlight { font-size: 8pt; font-weight: bold; margin: 0; }'
                    .' .lead { font-size: 9pt; font-weight: bold; margin: 1mm 0 0; }'
                    .' .muted { margin: 0; }'
                    .' img { margin-top: 1mm; }',
                'body_html' => <<<'HTML'
                    <div class="text-center">
                        <p class="highlight">{company_name}</p>
                        <p class="muted">{branch_name}</p>
                        <div>{photo}</div>
                        <p class="lead">{student_name}</p>
                        <p class="muted">{student_code}</p>
                        <p class="muted">{course_name}</p>
                        <p class="muted">Valid until {valid_until}</p>
                        <div>{qr}</div>
                    </div>
                    HTML,
            ],
            [
                'type' => PrintTemplateType::ResultCard,
                'code' => 'DEFAULT-RESULT-CARD',
                'name' => 'Standard result card',
                'description' => 'An A4 result card listing every published exam for the enrolment.',
                'paper_size' => PaperSize::A4,
                'orientation' => PageOrientation::Portrait,
                'margin_mm' => '12.00',
                'qr_size_mm' => '25.00',
                // `.note td` rather than a bare `td`, so the header's spacing does not also restyle
                // the results table the service builds inside {results_table}.
                'custom_css' => 'h1 { font-size: 18pt; margin: 0 0 4mm; }'
                    .' .note td { font-size: 10pt; padding: 1mm 0; }'
                    .' .lead { margin-top: 6mm; font-size: 11pt; }',
                'body_html' => <<<'HTML'
                    <div>
                        <h1>{company_name} &mdash; Result Card</h1>
                        <table class="note" width="100%">
                            <tr><td width="50%">Student: <strong>{student_name}</strong></td><td>Roll: {student_code}</td></tr>
                            <tr><td>Course: {course_name}</td><td>Batch: {batch_name}</td></tr>
                            <tr><td>Attendance: {attendance_percentage}</td><td>Printed: {issued_on}</td></tr>
                        </table>
                        {results_table}
                        <p class="lead">Overall: <strong>{aggregate_percentage}</strong> &middot; grade <strong>{aggregate_grade}</strong></p>
                        {grade_scale_legend}
                    </div>
                    HTML,
            ],
        ];
    }
}
