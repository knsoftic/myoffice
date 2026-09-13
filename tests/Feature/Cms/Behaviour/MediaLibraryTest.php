<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MediaCollection;
use App\Enums\Cms\MediaProcessingStatus;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\MediaAsset;
use App\Services\Cms\Exceptions\UnsupportedUploadException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-03 §11.7 — images and uploads (FT-33, FT-34, FT-35, FT-38).
 *
 * INV-11 and D24: an upload is judged by its bytes, never by its name or the browser's claim; SVG is
 * refused with its reason; the stored path is a ULID the user cannot influence; EXIF (GPS included) never
 * survives; derivatives follow the profile and never upscale; a failed derivative never produces a broken
 * `srcset`; an image in use cannot be deleted; and the same bytes uploaded twice are one row and one
 * directory.
 *
 * The `public` disk is faked, so nothing reaches `storage/app/public` and "nothing stored" is observable.
 */
final class MediaLibraryTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();

        Storage::fake('public');
    }

    /** FT-33 */
    public function test_upload_validates_by_content_not_extension(): void
    {
        $admin = $this->createSuperAdmin();
        $assets = DB::table('media_assets')->count();

        $refused = [
            'a PHP file renamed .jpg' => UploadedFile::fake()->createWithContent('shell.jpg', "<?php echo 'owned'; ?>\n"),
            'a text file renamed .png' => UploadedFile::fake()->createWithContent('notes.png', "Just some notes, not an image at all.\n"),
            'an SVG' => UploadedFile::fake()->createWithContent('logo.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>'),
        ];

        foreach ($refused as $case => $file) {
            $response = $this->actingAs($admin)
                ->withHeaders(['Accept' => 'application/json'])
                ->post(route('admin.website.media.store'), ['file' => $file])
                ->assertStatus(422)
                ->assertJsonValidationErrors('file');

            if ($case === 'an SVG') {
                $this->assertStringContainsString('SVG', (string) $response->json('errors.file.0'), 'An SVG is refused with its stated reason.');
            }

            $this->assertSame($assets, DB::table('media_assets')->count(), sprintf('%s wrote a media row.', ucfirst($case)));
            $this->assertSame([], Storage::disk('public')->allFiles(), sprintf('%s left a file on the disk.', ucfirst($case)));
        }

        // The service refuses the same bytes whatever the caller claims.
        $this->assertThrows(
            fn () => $this->media()->store(UploadedFile::fake()->createWithContent('shell.jpg', "<?php echo 'owned'; ?>\n"), MediaCollection::General),
            UnsupportedUploadException::class,
        );

        // Too large for security.max_upload_mb: the form refuses it…
        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('admin.website.media.store'), ['file' => UploadedFile::fake()->create('huge.jpg', 50 * 1024, 'image/jpeg')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        // …and so does the service, measuring the real bytes against the setting.
        $this->setSetting('security.max_upload_mb', 1);

        $this->assertThrows(
            fn () => $this->media()->store(UploadedFile::fake()->createWithContent('big.jpg', str_repeat('A', (int) (1.5 * 1024 * 1024))), MediaCollection::General),
            UnsupportedUploadException::class,
        );

        $this->setSetting('security.max_upload_mb', 10);

        $this->assertSame($assets, DB::table('media_assets')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        // A genuine JPEG is accepted.
        $response = $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('admin.website.media.store'), [
                'file' => UploadedFile::fake()->createWithContent('holiday.jpg', $this->jpegBytes(640, 480)),
                'alt_text' => 'FT33 holiday',
            ])
            ->assertOk();

        $id = (int) $response->json('asset.id');

        /** @var MediaAsset $asset */
        $asset = MediaAsset::query()->findOrFail($id);

        $this->assertSame('image/jpeg', $asset->mime_type);
        $this->assertSame($assets + 1, DB::table('media_assets')->count());
        Storage::disk('public')->assertExists($asset->path());
    }

    /** FT-34 */
    public function test_stored_file_is_safe_and_deterministic(): void
    {
        $bytes = $this->jpegWithGpsExif(1200, 800);

        $this->assertStringContainsString("Exif\0\0", $bytes, 'The fixture carries an EXIF block.');
        $this->assertStringContainsString('FT34-GPS-MARKER', $bytes, 'The fixture carries recognisable EXIF data.');

        $file = UploadedFile::fake()->createWithContent('../../../etc/passwd-holiday photo.jpg', $bytes);

        $asset = $this->media()->store($file, MediaCollection::Sections, ImageProfile::Hero, ['alt_text' => 'FT34 holiday']);
        $path = $asset->path();

        $this->assertSame('public', $asset->disk);
        $this->assertMatchesRegularExpression(
            '~^cms/'.Carbon::now()->format('Y').'/'.Carbon::now()->format('m').'/([0-9A-HJKMNP-TV-Z]{26})/\1\.jpg$~i',
            $path,
            'The stored path is cms/{Y}/{m}/{ulid}/{ulid}.jpg.',
        );

        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString('passwd', $path);
        $this->assertStringNotContainsString('holiday', $path, 'The user\'s filename never becomes part of the path.');
        $this->assertStringContainsString('holiday', (string) $asset->original_name, 'The user\'s filename is kept only in original_name.');
        $this->assertStringNotContainsString('/', (string) $asset->original_name);

        Storage::disk('public')->assertExists($path);

        foreach (Storage::disk('public')->allFiles() as $stored) {
            $this->assertStringStartsWith(trim((string) $asset->directory, '/').'/', $stored, 'Nothing is written outside the asset\'s own directory.');
        }

        $original = (string) Storage::disk('public')->get($path);

        $this->assertStringNotContainsString("Exif\0\0", $original, 'EXIF is stripped from the stored original.');
        $this->assertStringNotContainsString('FT34-GPS-MARKER', $original, 'No EXIF value (GPS included) survives.');
        $this->assertNotFalse(@getimagesizefromstring($original), 'The stored original is still a readable image.');
    }

    /** FT-35 */
    public function test_derivatives_match_the_profile_and_never_upscale(): void
    {
        $wide = $this->media()->store(
            UploadedFile::fake()->createWithContent('wide.jpg', $this->jpegBytes(3000, 1500)),
            MediaCollection::Sections,
            ImageProfile::Hero,
            ['alt_text' => 'FT35 wide alt'],
        )->fresh();

        $this->assertSame(MediaProcessingStatus::Ready, $wide->derivatives_status);
        $this->assertSame(2560, (int) $wide->width, 'An original wider than website.image_max_width is downscaled to it.');

        $variants = (array) $wide->variants;
        ksort($variants, SORT_NUMERIC);

        $this->assertSame(['640', '960', '1280', '1920', '2560'], array_map('strval', array_keys($variants)), 'Exactly the hero widths up to 2560.');

        $webp = function_exists('imagewebp') && (imagetypes() & IMG_WEBP) !== 0;

        foreach ($variants as $width => $variant) {
            $this->assertLessThanOrEqual(2560, (int) $variant['width']);
            $this->assertSame((int) $width, (int) $variant['width']);
            $this->assertGreaterThan(0, (int) $variant['size_bytes'], 'variants records each size.');
            Storage::disk('public')->assertExists((string) $variant['path']);

            if ($webp) {
                $this->assertIsArray($variant['webp'] ?? null, sprintf('The %spx derivative has a WebP sibling.', $width));
                Storage::disk('public')->assertExists((string) $variant['webp']['path']);
            }
        }

        $small = $this->media()->store(
            UploadedFile::fake()->createWithContent('small.jpg', $this->jpegBytes(500, 400, 200, 30, 30)),
            MediaCollection::General,
            ImageProfile::Card,
        )->fresh();

        $smallVariants = array_map('strval', array_keys((array) $small->variants));
        sort($smallVariants, SORT_NUMERIC);

        $this->assertSame(['320', '480'], $smallVariants, 'A 500px card upload is never upscaled to 640 or 960.');

        $html = Blade::render('<x-site.image :media="$media" profile="hero" />', ['media' => $this->media()->toSnapshot($wide, ImageProfile::Hero)]);

        $this->assertStringContainsString('<picture', $html);
        $this->assertMatchesRegularExpression('~<img[^>]+srcset="[^"]*2560w~', $html);
        $this->assertStringContainsString('sizes="100vw"', $html);
        $this->assertStringContainsString('width="2560"', $html);
        $this->assertStringContainsString('height="1280"', $html);
        $this->assertStringContainsString('alt="FT35 wide alt"', $html);

        if ($webp) {
            $this->assertMatchesRegularExpression('~<source[^>]+type="image/webp"[^>]+srcset="[^"]+\.webp~', $html);
        }

        // A failed asset renders its original with no srcset at all.
        DB::table('media_assets')->where('id', $wide->getKey())->update(['derivatives_status' => MediaProcessingStatus::Failed->value]);

        $failed = $wide->fresh();
        $html = Blade::render('<x-site.image :media="$media" profile="hero" />', ['media' => $this->media()->toSnapshot($failed, ImageProfile::Hero)]);

        $this->assertStringNotContainsString('srcset', $html, 'A failed asset never renders a broken srcset.');
        $this->assertStringContainsString((string) $failed->filename, $html, 'It renders the original instead.');
        $this->assertStringContainsString('width="2560"', $html);
    }

    /** FT-38 */
    public function test_media_in_use_cannot_be_deleted_and_reupload_deduplicates(): void
    {
        $admin = $this->createSuperAdmin();
        $bytes = $this->jpegBytes(800, 600, 10, 120, 60);

        $asset = $this->media()->store(UploadedFile::fake()->createWithContent('team.jpg', $bytes), MediaCollection::Sections, ImageProfile::Card, ['alt_text' => 'FT38 team photo']);
        $again = $this->media()->store(UploadedFile::fake()->createWithContent('team-copy.jpg', $bytes), MediaCollection::Sections, ImageProfile::Card);

        $this->assertSame((int) $asset->getKey(), (int) $again->getKey(), 'Re-uploading identical bytes returns the existing row.');
        $this->assertSame(1, DB::table('media_assets')->where('checksum', $asset->checksum)->count());
        $this->assertCount(1, Storage::disk('public')->directories(dirname(trim((string) $asset->directory, '/'))), 'No second directory is created.');

        $section = $this->sections()->place('rich_content', SectionPlacement::Home);
        $section = $this->sections()->saveDraft($section, ['heading' => 'FT38 Team Section'], ['image_1' => (int) $asset->getKey()]);

        $this->assertSame(1, (int) $asset->fresh()->usage_count);

        $response = $this->actingAs($admin)
            ->deleteJson(route('admin.website.media.destroy', $asset))
            ->assertForbidden();

        $usage = (array) $response->json('usage');
        $this->assertNotEmpty($usage, 'The refusal carries the list of places the asset is used.');
        $this->assertContains((int) $section->getKey(), array_map('intval', array_column($usage, 'id')));
        $this->assertNull(DB::table('media_assets')->where('id', $asset->getKey())->value('deleted_at'));

        // The section stops using it and the recount runs: the delete goes through.
        $this->sections()->saveDraft($section, [], ['image_1' => null]);
        $this->artisan('cms:media-recount')->assertSuccessful();

        $this->assertSame(0, (int) $asset->fresh()->usage_count);

        $this->actingAs($admin)
            ->deleteJson(route('admin.website.media.destroy', $asset))
            ->assertOk();

        $this->assertSoftDeleted('media_assets', ['id' => $asset->getKey()]);
    }

    /**
     * A genuine JPEG with an APP1 EXIF block holding an ImageDescription marker and a GPS IFD.
     */
    private function jpegWithGpsExif(int $width, int $height): string
    {
        $marker = "FT34-GPS-MARKER\0";

        // TIFF header (little endian), IFD0 at 8.
        $tiff = 'II'.pack('v', 42).pack('V', 8);

        // IFD0: ImageDescription (ASCII, stored at 56) and the GPS IFD pointer (GPS IFD at 38).
        $tiff .= pack('v', 2);
        $tiff .= pack('vvV', 0x010E, 2, strlen($marker)).pack('V', 56);
        $tiff .= pack('vvV', 0x8825, 4, 1).pack('V', 38);
        $tiff .= pack('V', 0);

        // GPS IFD: GPSLatitudeRef = "N".
        $tiff .= pack('v', 1);
        $tiff .= pack('vvV', 0x0001, 2, 2)."N\0\0\0";
        $tiff .= pack('V', 0);

        $tiff .= $marker;

        $payload = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        $jpeg = $this->jpegBytes($width, $height);

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }
}
