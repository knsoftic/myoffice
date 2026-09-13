<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use App\Support\ImageSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A polyglot (a valid image header followed by a script) passes every validation rule. The stored
 * avatar must therefore be re-encoded pixels, never the uploaded bytes.
 */
final class AvatarReencodingTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = '<?php echo "polyglot-marker"; ?>';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    #[Test]
    public function a_gif_with_an_appended_script_is_stored_without_the_script(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        $response = $this->actingAs($user)->post('/account/avatar', [
            'avatar' => $this->polyglot('gif'),
        ]);

        $response->assertSessionHasNoErrors();

        $path = (string) $user->fresh()->avatar_path;
        $this->assertMatchesRegularExpression('#^avatars/[A-Za-z0-9]{40}\.gif$#', $path);

        $stored = Storage::disk('public')->get($path);
        $this->assertStringNotContainsString('polyglot-marker', $stored, 'The appended script must not survive re-encoding.');
        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertNotFalse(@getimagesizefromstring($stored), 'The stored file is still a valid image.');
    }

    #[Test]
    public function a_png_with_an_appended_script_is_stored_without_the_script(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($user)->post('/account/avatar', ['avatar' => $this->polyglot('png')])
            ->assertSessionHasNoErrors();

        $stored = Storage::disk('public')->get((string) $user->fresh()->avatar_path);
        $this->assertStringNotContainsString('polyglot-marker', $stored);
        $this->assertNotFalse(@getimagesizefromstring($stored));
    }

    #[Test]
    public function an_oversized_image_is_downscaled_to_the_maximum_edge(): void
    {
        $file = UploadedFile::fake()->image('big.png', ImageSanitizer::MAX_EDGE * 2, ImageSanitizer::MAX_EDGE);

        $clean = ImageSanitizer::reencode($file);
        [$width, $height] = getimagesizefromstring($clean['bytes']);

        $this->assertSame('png', $clean['extension']);
        $this->assertSame(ImageSanitizer::MAX_EDGE, $width);
        $this->assertSame(intdiv(ImageSanitizer::MAX_EDGE, 2), $height);
    }

    #[Test]
    public function bytes_that_do_not_decode_as_an_image_are_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, "GIF89a\x01\x00\x01\x00".str_repeat("\x00", 8));

        $this->expectException(ValidationException::class);

        ImageSanitizer::reencode(new UploadedFile($path, 'broken.gif', 'image/gif', null, true));
    }

    private function polyglot(string $format): UploadedFile
    {
        $image = imagecreatetruecolor(64, 64);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));

        ob_start();
        $format === 'gif' ? imagegif($image) : imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        $path = tempnam(sys_get_temp_dir(), 'poly');
        file_put_contents($path, $bytes.self::MARKER);

        return new UploadedFile($path, 'photo.'.$format, 'image/'.$format, null, true);
    }
}
