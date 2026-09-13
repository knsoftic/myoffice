<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Services\Cms\Exceptions\UnknownRichTextProfileException;
use App\Support\RichText;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use Tests\TestCase;

/**
 * phase-03 FT-36b (ND-5, D25): the rich-text profiles are a closed map of exactly two named profiles.
 *
 *   · `cms` (website content) strips `<div>`, `style=`, `align=` and a `data:` image;
 *   · `material` (documents and course material) keeps all four — only a raster `data:image/*` — and
 *     still loses every construct of the common core and every unsafe CSS construct;
 *   · any other profile name throws before a byte reaches the purifier engine;
 *   · one sanitiser class in `app/`, and exactly the same two profiles in `config/purifier.php`.
 */
final class RichTextProfilesTest extends TestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** FT-36b */
    public function test_rich_text_profiles_are_a_closed_map(): void
    {
        $this->assertSame(['cms', 'material'], RichText::profiles());
        $this->assertSame(['cms', 'material'], array_keys(RichText::PROFILES));

        $this->assertCmsProfileStripsTheMaterialConcessions();
        $this->assertMaterialProfileKeepsItsConcessionsButNotTheCore();
        $this->assertAnUnknownProfileNeverReachesTheEngine();
        $this->assertOneSanitiserAndTwoPurifierProfiles();
    }

    private function assertCmsProfileStripsTheMaterialConcessions(): void
    {
        $html = '<div class="lead">FT36b div text</div>'
            .'<p style="color: red" align="center">FT36b styled paragraph</p>'
            .'<img src="'.self::PNG.'" alt="FT36b inline image">';

        $clean = RichText::sanitize($html, 'cms');

        $this->assertStringNotContainsString('<div', $clean, 'cms must unwrap <div>.');
        $this->assertStringContainsString('FT36b div text', $clean, 'cms keeps the text of an unwrapped <div>.');
        $this->assertStringNotContainsString('style=', $clean, 'cms must strip style=.');
        $this->assertStringNotContainsString('align=', $clean, 'cms must strip align=.');
        $this->assertStringContainsString('FT36b styled paragraph', $clean);
        $this->assertStringNotContainsString('data:', $clean, 'cms must strip a data: image.');
    }

    private function assertMaterialProfileKeepsItsConcessionsButNotTheCore(): void
    {
        $kept = RichText::sanitize(
            '<div align="center" style="color: #123456">FT36b material div</div>'
            .'<img src="'.self::PNG.'" alt="FT36b raster">',
            'material',
        );

        $this->assertStringContainsString('<div', $kept, 'material keeps <div>.');
        $this->assertMatchesRegularExpression('~style="[^"]*color:\s*#123456~i', $kept, 'material keeps an allowlisted style declaration.');
        $this->assertMatchesRegularExpression('~align="center"~i', $kept, 'material keeps align=.');
        $this->assertStringContainsString('data:image/png;base64,', $kept, 'material keeps a raster data: image.');

        $hostile = RichText::sanitize(
            '<p>FT36b safe material</p>'
            .'<img src="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==" alt="FT36b html payload">'
            .'<script>alert("ft36b-script")</script>'
            .'<p onclick="alert(\'ft36b-onclick\')">FT36b handler</p>'
            .'<a href="javascript:alert(\'ft36b-js\')">FT36b js link</a>'
            .'<p>FT36b blade {{ 7*7 }} end</p>'
            .'<p>FT36b directive @php echo 1; @endphp end</p>'
            .'<p>FT36b tag <?php echo 2; ?> end</p>'
            .'<p style="@import url(https://evil.test/ft36b.css); color: blue">FT36b import</p>'
            .'<p style="width: expression(alert(1)); color: green">FT36b expression</p>'
            .'<p style="background-image: url(https://evil.test/ft36b-pixel.png); color: purple">FT36b external url</p>',
            'material',
        );

        $this->assertStringContainsString('FT36b safe material', $hostile);
        $this->assertStringNotContainsString('data:text/html', $hostile, 'material must refuse a non-raster data: URI.');
        $this->assertStringNotContainsString('<script', $hostile);
        $this->assertStringNotContainsString('ft36b-script', $hostile);
        $this->assertDoesNotMatchRegularExpression('~\son[a-z]+\s*=~i', $hostile, 'material must strip on* handlers.');
        $this->assertStringNotContainsString('javascript:', $hostile);
        $this->assertStringNotContainsString('{{', $hostile);
        $this->assertStringNotContainsString('@php', $hostile);
        $this->assertStringNotContainsString('<?php', $hostile);
        $this->assertStringNotContainsString('@import', $hostile);
        $this->assertStringNotContainsString('expression(', $hostile);
        $this->assertStringNotContainsString('evil.test', $hostile, 'material must strip an external url().');
        // The safe declarations beside the hostile ones survive: only the unsafe construct is removed.
        $this->assertMatchesRegularExpression('~color:\s*blue~i', $hostile);
        $this->assertMatchesRegularExpression('~color:\s*green~i', $hostile);
        $this->assertMatchesRegularExpression('~color:\s*purple~i', $hostile);
    }

    private function assertAnUnknownProfileNeverReachesTheEngine(): void
    {
        $engines = new ReflectionProperty(RichText::class, 'purifiers');
        $before = $engines->getValue();

        foreach (['print', 'default', 'CMS', ''] as $name) {
            try {
                RichText::sanitize('<p>FT36b unknown profile</p>', $name);
                $this->fail(sprintf('The profile name [%s] is not committed and must throw.', $name));
            } catch (UnknownRichTextProfileException) {
                // expected
            }

            $this->assertFalse(RichText::hasProfile($name));
        }

        $after = $engines->getValue();
        $this->assertArrayNotHasKey('print', $after, 'An unknown profile name must never build a purifier engine.');
        $this->assertSame(array_keys($before), array_keys($after), 'Refused profile names must not touch the engine cache.');
    }

    private function assertOneSanitiserAndTwoPurifierProfiles(): void
    {
        $sanitisers = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            // A sanitiser engine: HTMLPurifier built directly, or mews/purifier's facade or class used.
            if (preg_match('~new\s+\\\\?HTMLPurifier\s*\(|HTMLPurifier_Config::create|Purifier::clean\s*\(|Mews\\\\Purifier~', $code) === 1) {
                $sanitisers[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }

        $this->assertSame(['app/Support/RichText.php'], $sanitisers, 'Exactly one HTML sanitiser class may exist (D25).');

        $config = require config_path('purifier.php');
        $this->assertIsArray($config['settings'] ?? null);
        $this->assertSame(RichText::profiles(), array_keys($config['settings']), 'config/purifier.php must mirror exactly the two committed profiles.');
    }
}
