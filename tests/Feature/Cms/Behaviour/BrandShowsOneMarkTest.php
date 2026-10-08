<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The site's header, mobile drawer and footer show the logo **or** the company name — never both.
 *
 * An uploaded logo is almost always a wordmark: knsoftic.com's says "KN Softic" in the image, and the
 * brand printed "KN Softic" beside it, so the name was on screen twice in every header and footer. The
 * owner asked for one: the logo when there is one, otherwise the name.
 *
 * Three states, each asserted on the rendered component rather than on its source:
 *
 *   logo uploaded               → the image only; the name is its alt text, not a second label
 *   no logo, show name on       → the name only; no monogram beside it either
 *   no logo, show name off      → the monogram only, with the name as the link's aria-label
 *
 * The accessibility half matters as much as the visual one: hiding the name must not hide it from a
 * screen reader, so with a logo the name has to arrive through `alt`.
 */
final class BrandShowsOneMarkTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    private string $name;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        Storage::fake('public');
        Cache::flush();

        $this->name = (string) site_setting('company.name', '');
        $this->assertNotSame('', $this->name, 'No company name is seeded, so these tests cannot tell the states apart.');
    }

    #[Test]
    public function with_a_logo_only_the_logo_renders_and_the_name_is_its_alt_text(): void
    {
        $this->uploadLogo();

        $html = Blade::render('<x-site.brand />');

        $this->assertStringContainsString('<img', $html, 'The uploaded logo did not render.');
        $this->assertMatchesRegularExpression(
            '/<img[^>]*\salt="'.preg_quote(e($this->name), '/').'"/',
            $html,
            'With the name no longer printed, the logo must carry it as alt text or a screen reader hears nothing.',
        );
        $this->assertSame(
            [],
            $this->visibleNameSpans($html),
            'The company name is printed beside the logo — the name is on screen twice.',
        );
    }

    #[Test]
    public function the_show_name_switch_cannot_put_the_name_back_beside_a_logo(): void
    {
        $this->uploadLogo();

        $html = Blade::render('<x-site.brand :show-name="true" />');

        $this->assertSame([], $this->visibleNameSpans($html));
    }

    #[Test]
    public function without_a_logo_only_the_name_renders_and_no_monogram(): void
    {
        $html = Blade::render('<x-site.brand :show-name="true" />');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertNotSame([], $this->visibleNameSpans($html), 'With no logo the company name must show.');
        $this->assertStringNotContainsString(
            'aria-hidden="true"',
            $html,
            'A monogram rendered beside the name — that is two marks again.',
        );
    }

    #[Test]
    public function without_a_logo_and_with_the_name_off_only_the_monogram_renders(): void
    {
        $html = Blade::render('<x-site.brand :show-name="false" />');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertSame([], $this->visibleNameSpans($html));
        $this->assertStringContainsString('aria-hidden="true"', $html, 'Nothing marks the brand at all.');
        $this->assertStringContainsString(
            'aria-label="'.e($this->name).'"',
            $html,
            'The monogram is decorative, so the link needs the name as its accessible label.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /** A genuine PNG on the fake public disk, pointed at by `branding.logo_light`. */
    private function uploadLogo(): void
    {
        $image = imagecreatetruecolor(600, 200);
        imagefilledrectangle($image, 0, 0, 599, 199, (int) imagecolorallocate($image, 20, 80, 170));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put('settings/brand-test.png', $bytes);

        DB::table('settings')->updateOrInsert(
            ['group' => 'branding', 'key' => 'logo_light'],
            ['value' => 'settings/brand-test.png', 'type' => 'image', 'created_at' => now(), 'updated_at' => now()],
        );

        // The repository memoises its rows, and the component reads through it.
        Cache::flush();
        settings_repo()->flush();
    }

    /**
     * The `<span>` elements whose text is exactly the company name — the printed label.
     *
     * Matched on the element's text rather than on a class, so a restyle cannot make this pass by
     * accident; an alt or aria-label attribute is not a span's text and does not count.
     *
     * @return list<string>
     */
    private function visibleNameSpans(string $html): array
    {
        preg_match_all('/<span\b[^>]*>\s*'.preg_quote(e($this->name), '/').'\s*<\/span>/', $html, $matches);

        return $matches[0];
    }
}
