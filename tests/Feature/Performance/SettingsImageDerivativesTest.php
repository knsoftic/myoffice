<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Services\Cms\SettingsImageService;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The Settings logo goes through the public image pipeline, or the header pays for it.
 *
 * The bug this covers was measured on the live site, not imagined. `branding.logo_light` is a path on
 * the public disk, so `<x-site.brand>` handed it to `<x-site.image>` as `['url' => $url]` — no
 * `srcset`, no WebP `<source>`, and **no `width`/`height`**. The uploaded file was 4167 x 1571 and
 * 141,736 bytes; it was painted 36 pixels tall, twice per page (light and dark are both in the
 * markup), with nothing telling the browser how much header space to reserve. That is ~277 KB of
 * logo — the entire image weight of the home page — plus a layout shift in the one element that is
 * above the fold on every single page.
 *
 * Four things are asserted, and each of them is a different way the fix could rot:
 *
 * **The `<img>` carries width and height.** Cumulative Layout Shift is a Core Web Vital and the
 * header logo is the element most able to spend it. This is the assertion that would fail first if
 * somebody simplified the snapshot back down to a url.
 *
 * **Every url the srcset names exists.** A srcset pointing at a file that is not there is worse than
 * no srcset: the browser fetches, 404s, and falls back — slower than never having tried.
 *
 * **The original is never in a srcset.** It is the `src` fallback and nothing else. If the 141 KB
 * file reappears as a candidate the whole exercise is undone while every other assertion still
 * passes.
 *
 * **A logo smaller than the profile's widths is not upscaled.** A 90-pixel-wide logo must not gain a
 * 480w "derivative" that is a bigger file showing less. `GdImageProcessor::derivative()` already
 * refuses to upscale; this proves the refusal survives the trip through the service, because the
 * failure is invisible — an upscaled derivative looks like a working one.
 */
final class SettingsImageDerivativesTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        Storage::fake('public');
        Cache::flush();
    }

    #[Test]
    public function the_brand_renders_a_picture_with_a_srcset_and_intrinsic_dimensions(): void
    {
        $this->uploadLogo('branding.logo_light', 'brand-light.png', 1600, 600);

        $html = Blade::render('<x-site.brand />');

        $this->assertStringContainsString('<picture', $html, 'The logo is not rendering through <x-site.image>.');
        $this->assertStringContainsString('type="image/webp"', $html, 'No WebP source: the browser has only the PNG to choose from.');
        $this->assertMatchesRegularExpression('/<img[^>]*\ssrcset="/', $html, 'The <img> has no srcset, so one size is served to every screen.');

        // The CLS assertion. Without these the browser cannot reserve header space before the logo
        // arrives, and the whole page moves once it does.
        $this->assertMatchesRegularExpression('/<img[^>]*\swidth="1600"/', $html);
        $this->assertMatchesRegularExpression('/<img[^>]*\sheight="600"/', $html);
    }

    #[Test]
    public function every_derivative_the_srcset_names_is_a_file_that_exists(): void
    {
        $snapshot = $this->snapshotFor('brand-light.png', 1600, 600);

        $candidates = $this->candidatesIn((string) $snapshot['srcset'])
            + $this->candidatesIn((string) $snapshot['webp_srcset']);

        $this->assertNotEmpty($candidates, 'Neither srcset named anything at all.');

        $missing = array_values(array_filter(
            array_keys($candidates),
            static fn (string $path): bool => ! Storage::disk('public')->exists($path),
        ));

        $this->assertSame(
            [],
            $missing,
            "The srcset names files that are not on disk, so the browser will 404 and fall back:\n  - "
                .implode("\n  - ", $missing),
        );
    }

    /**
     * The original stays the `src` fallback and never becomes a candidate.
     */
    #[Test]
    public function the_full_size_original_is_never_offered_as_a_candidate(): void
    {
        $snapshot = $this->snapshotFor('brand-light.png', 4167, 1571);

        foreach (['srcset', 'webp_srcset'] as $which) {
            foreach (array_keys($this->candidatesIn((string) $snapshot[$which])) as $path) {
                $this->assertStringContainsString(
                    'settings/derivatives/',
                    $path,
                    sprintf('%s offers [%s], which is not a derivative — the original is back in the candidate list.', $which, $path),
                );
            }
        }

        // And the fallback src is still the original, so a browser with no srcset support sees a logo.
        $this->assertStringContainsString('settings/brand-light.png', (string) $snapshot['url']);
    }

    /**
     * A small logo is not inflated into widths it does not have.
     */
    #[Test]
    public function a_logo_smaller_than_the_profile_widths_is_not_upscaled(): void
    {
        // The `logo` profile declares 120, 240 and 480. A 300-pixel-wide original can honestly supply
        // the first two and must refuse the third -- so this proves the refusal without also proving
        // that nothing is produced at all.
        $snapshot = $this->snapshotFor('small.png', 300, 120);

        $widths = [];

        foreach ($this->candidatesIn((string) $snapshot['webp_srcset']) as $width) {
            $widths[] = $width;
        }

        sort($widths);

        $this->assertSame([120, 240], $widths, 'A 200px logo was given a width it cannot honestly fill.');
    }

    /**
     * A logo setting pointing at a file that is not on the disk degrades to the company name.
     *
     * `<x-site.brand>` also renders the holding and maintenance pages, so a logo deleted from the disk
     * — or a settings row pointing at a path that was never uploaded — must never cost the page. It
     * used to degrade to the dead URL, which was tolerable while the name was printed beside the logo
     * anyway. Since the brand shows the logo **or** the name, a dead URL would leave a broken image and
     * no name at all, so a missing file now counts as no logo.
     */
    #[Test]
    public function a_missing_file_degrades_to_the_name_instead_of_a_broken_image(): void
    {
        $this->writeSetting('branding.logo_light', 'settings/was-deleted.png');

        $this->assertNull(app(SettingsImageService::class)->snapshot('settings/was-deleted.png', 'logo'));

        $name = (string) site_setting('company.name', '');
        $this->assertNotSame('', $name, 'No company name is seeded, so this test cannot see the fallback.');

        $html = Blade::render('<x-site.brand />');

        $this->assertStringNotContainsString('was-deleted.png', $html, 'A logo URL for a file that does not exist was rendered — a broken image.');
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString(e($name), $html, 'With no usable logo the company name must take its place.');
    }

    /**
     * Replacing or removing a logo takes its derivatives with it.
     *
     * A settings upload gets a fresh random filename every time, and a derivative's name is built
     * from that filename, so derivatives of a replaced logo can never be reached again. Six files per
     * logo, every time somebody changes it, on the public disk forever.
     *
     * The second half matters as much as the first: `forget()` must delete the derivatives of the
     * file it was given and nobody else's. A prefix match would let a logo called `mark` delete the
     * derivatives of one called `mark-wide`, which is a data-loss bug that only shows up on a site
     * with two similarly-named uploads.
     */
    #[Test]
    public function deleting_the_original_deletes_its_derivatives_and_only_its_own(): void
    {
        $mine = $this->uploadLogo('branding.logo_light', 'mark.png', 800, 300);
        app(SettingsImageService::class)->snapshot($mine, 'logo');

        $neighbour = $this->uploadLogo('branding.logo_dark', 'mark-wide.png', 800, 300);
        app(SettingsImageService::class)->snapshot($neighbour, 'logo');

        $this->assertNotEmpty($this->derivativesOf('mark'), 'Nothing was generated, so this proves nothing.');
        $this->assertNotEmpty($this->derivativesOf('mark-wide'));

        app(SettingsImageService::class)->forget($mine);

        $this->assertSame([], $this->derivativesOf('mark'), 'The derivatives of the deleted logo are still on the disk.');
        $this->assertNotEmpty(
            $this->derivativesOf('mark-wide'),
            'forget() matched on a prefix and deleted a different upload\'s derivatives.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * The derivative files belonging to one original basename, `{base}-{width}.{ext}` exactly.
     *
     * @return list<string>
     */
    private function derivativesOf(string $base): array
    {
        return array_values(array_filter(
            Storage::disk('public')->files('settings/derivatives'),
            static fn (string $file): bool => preg_match(
                '/^'.preg_quote($base, '/').'-\d+\.[a-z]+$/i',
                basename($file),
            ) === 1,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotFor(string $filename, int $width, int $height): array
    {
        $path = $this->uploadLogo('branding.logo_light', $filename, $width, $height);

        $snapshot = app(SettingsImageService::class)->snapshot($path, 'logo');

        $this->assertIsArray($snapshot, 'snapshot() returned null, so the original is being served at full size.');

        return $snapshot;
    }

    /** Put a genuine PNG on the public disk and point the setting at it. Returns the path. */
    private function uploadLogo(string $key, string $filename, int $width, int $height): string
    {
        $path = 'settings/'.$filename;

        Storage::disk('public')->put($path, $this->pngBytes($width, $height));
        $this->writeSetting($key, $path);

        return $path;
    }

    /**
     * Written straight to the table: what is under test is the render path, not the upload form, and
     * going through `SettingsService` would drag its validation and its own disk handling in with it.
     */
    private function writeSetting(string $key, string $value): void
    {
        [$group, $name] = explode('.', $key, 2);

        DB::table('settings')->updateOrInsert(
            ['group' => $group, 'key' => $name],
            ['value' => $value, 'type' => 'image', 'created_at' => now(), 'updated_at' => now()],
        );

        // The repository memoises its rows, and the view reads through it.
        Cache::flush();
        $this->app->forgetInstance(SettingsRepository::class);
    }

    /**
     * A srcset parsed into `[disk path => width]`.
     *
     * @return array<string, int>
     */
    private function candidatesIn(string $srcset): array
    {
        $candidates = [];

        foreach (array_filter(array_map('trim', explode(',', $srcset))) as $entry) {
            $parts = preg_split('/\s+/', $entry) ?: [];
            $url = (string) ($parts[0] ?? '');
            $width = (int) rtrim((string) ($parts[1] ?? '0'), 'w');

            $path = (string) preg_replace(
                '~^/?storage/~',
                '',
                ltrim((string) (parse_url($url, PHP_URL_PATH) ?: $url), '/'),
            );

            if ($path !== '') {
                $candidates[$path] = $width;
            }
        }

        return $candidates;
    }

    /** Genuine PNG bytes drawn with GD, never a fake header. */
    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 20, 80, 170));
        imagefilledrectangle($image, 0, 0, (int) ($width / 3), (int) ($height / 3), (int) imagecolorallocate($image, 250, 200, 20));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
