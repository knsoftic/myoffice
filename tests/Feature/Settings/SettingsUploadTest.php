<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Activity;
use App\Models\User;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\SettingsService;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * phase-02 §6 "Uploads": a logo upload stores on the public disk, replaces and deletes the
 * previous file, rejects a non-image and an oversized file, and a disguised `.php` upload is
 * refused.
 *
 * Both disks are faked, so "nothing was written" is asserted by listing the disk rather than by
 * trusting the response.
 */
final class SettingsUploadTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();

        Storage::fake('public');
        Storage::fake('local');
    }

    #[Test]
    public function a_logo_is_stored_on_the_public_disk_under_a_generated_name(): void
    {
        $admin = $this->createSuperAdmin();

        $this->uploadLogo($admin, UploadedFile::fake()->image('Our Company Logo.png', 240, 64))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'success');

        $path = $this->rawSetting('branding.logo_light');

        $this->assertNotNull($path);
        $this->assertStringStartsWith(SettingsService::DIRECTORY.'/', $path);
        $this->assertStringEndsWith('.png', $path);
        $this->assertStringNotContainsString('Our Company Logo', $path, 'The browser-supplied name is never used.');

        Storage::disk('public')->assertExists($path);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'A public logo belongs on the public disk only.');

        $this->assertSame($path, $this->freshSetting('branding.logo_light'));
    }

    #[Test]
    public function uploading_a_replacement_deletes_the_previous_file(): void
    {
        $admin = $this->createSuperAdmin();

        $this->uploadLogo($admin, UploadedFile::fake()->image('first.png', 200, 60))->assertSessionHasNoErrors();
        $first = (string) $this->rawSetting('branding.logo_light');

        $this->uploadLogo($admin, UploadedFile::fake()->image('second.webp', 200, 60))->assertSessionHasNoErrors();
        $second = (string) $this->rawSetting('branding.logo_light');

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        $this->assertCount(1, Storage::disk('public')->allFiles(SettingsService::DIRECTORY), 'Replacing a logo leaves exactly one file behind.');

        $entry = Activity::query()->where('properties->key', 'branding.logo_light')->latest('id')->firstOrFail();
        $this->assertSame($first, $entry->properties['old']['value']);
        $this->assertSame($second, $entry->properties['attributes']['value']);
    }

    #[Test]
    public function removing_a_logo_deletes_the_file_and_clears_the_setting(): void
    {
        $admin = $this->createSuperAdmin();

        $this->uploadLogo($admin, UploadedFile::fake()->image('logo.png', 200, 60))->assertSessionHasNoErrors();
        $path = (string) $this->rawSetting('branding.logo_light');

        $this->actingAs($admin)
            ->from('/admin/settings/branding')
            ->delete('/admin/settings/branding/file/logo_light')
            ->assertRedirect('/admin/settings/branding')
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'success');

        $this->assertNull($this->rawSetting('branding.logo_light'));
        Storage::disk('public')->assertMissing($path);

        $entry = Activity::query()->where('properties->key', 'branding.logo_light')->latest('id')->firstOrFail();
        $this->assertSame('Setting file removed', $entry->description);
    }

    #[Test]
    public function the_remove_endpoint_refuses_a_key_that_is_not_a_file(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)->delete('/admin/settings/branding/file/brand_color')->assertNotFound();
        $this->actingAs($admin)->delete('/admin/settings/branding/file/not_a_key')->assertNotFound();

        $this->assertSame('#4f46e5', $this->rawSetting('branding.brand_color'));
    }

    #[Test]
    public function resetting_branding_removes_the_uploaded_file_from_disk(): void
    {
        $admin = $this->createSuperAdmin();

        $this->uploadLogo($admin, UploadedFile::fake()->image('logo.png', 200, 60))->assertSessionHasNoErrors();
        $path = (string) $this->rawSetting('branding.logo_light');

        $this->actingAs($admin)->post('/admin/settings/branding/reset')->assertRedirect();

        $this->assertNull($this->rawSetting('branding.logo_light'));
        Storage::disk('public')->assertMissing($path);
    }

    /**
     * Each case is a file name plus the bytes a browser would actually send. The uploads are real
     * files on disk (not `UploadedFile::fake()`, whose MIME type is guessed from the *name*), so the
     * validator sniffs the content exactly as it does in production.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedUploadProvider(): array
    {
        return [
            'a PDF' => ['logo.pdf', 'pdf'],
            'a plain text file named .png' => ['logo.png', 'text'],
            'an SVG (can carry script; raster only on the public disk)' => ['logo.svg', 'svg'],
            'an image over the 2 MB limit' => ['huge.png', 'oversized-png'],
            'PHP source disguised with an image extension' => ['logo.png', 'php'],
            'PHP source disguised as a JPEG' => ['avatar.jpg', 'php'],
            'real image bytes with a .php extension' => ['shell.php', 'png'],
            'real image bytes with a .phtml extension' => ['shell.phtml', 'png'],
            'real image bytes with a .phar extension' => ['shell.phar', 'png'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedUploadProvider')]
    public function a_file_that_is_not_an_acceptable_image_is_rejected_and_nothing_is_written(string $name, string $content): void
    {
        $admin = $this->createSuperAdmin();
        $since = $this->lastActivityId();

        $this->uploadLogo($admin, $this->realUpload($name, $this->bytes($content)))
            ->assertRedirect('/admin/settings/branding')
            ->assertSessionHasErrors('settings.logo_light');

        $this->assertNull($this->rawSetting('branding.logo_light'));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertCount(0, $this->settingsActivitySince($since));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function doubleExtensionProvider(): array
    {
        return [
            'logo.php.png' => ['logo.php.png'],
            'logo.phtml.jpg' => ['logo.phtml.jpg'],
            'LOGO.PHP.webp' => ['LOGO.PHP.webp'],
            'logo.png.php.jpeg' => ['logo.png.php.jpeg'],
        ];
    }

    #[Test]
    #[DataProvider('doubleExtensionProvider')]
    public function a_real_image_hiding_php_in_its_name_is_refused_on_the_screen(string $name): void
    {
        $admin = $this->createSuperAdmin();
        $since = $this->lastActivityId();

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $this->uploadLogo($admin, $this->realUpload($name, $this->bytes($extension === 'png' ? 'png' : 'jpeg')))
            ->assertRedirect('/admin/settings/branding')
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error');

        $this->assertNull($this->rawSetting('branding.logo_light'));
        $this->assertSame([], Storage::disk('public')->allFiles(), 'Nothing may reach the public disk.');
        $this->assertCount(0, $this->settingsActivitySince($since));
    }

    #[Test]
    public function the_service_refuses_an_executable_name_before_it_writes_anything(): void
    {
        $admin = $this->createSuperAdmin();

        try {
            app(SettingsService::class)->update('branding', ['logo_light' => UploadedFile::fake()->image('logo.php.png', 80, 40)], $admin);
            $this->fail('An upload named logo.php.png was accepted by the service.');
        } catch (ActionNotAllowedException $exception) {
            $this->assertStringContainsString('php', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function an_image_carrying_php_after_its_pixels_is_stored_only_as_an_image(): void
    {
        $admin = $this->createSuperAdmin();

        $bytes = $this->bytes('png')."\n<?php echo 'polyglot'; ?>";

        $this->uploadLogo($admin, $this->realUpload('logo.png', $bytes))->assertSessionHasNoErrors();

        $path = (string) $this->rawSetting('branding.logo_light');

        $this->assertStringEndsWith('.png', $path, 'The stored extension comes from the content, so the file can never be executed by name.');
        $this->assertStringNotContainsString('php', strtolower($path));
    }

    #[Test]
    public function a_file_field_is_never_cleared_by_posting_an_empty_value_over_it(): void
    {
        $admin = $this->createSuperAdmin();

        $this->uploadLogo($admin, UploadedFile::fake()->image('logo.png', 200, 60))->assertSessionHasNoErrors();
        $path = (string) $this->rawSetting('branding.logo_light');

        $payload = $this->browserPayload($admin, 'branding');
        $payload['settings']['logo_light'] = '';

        $this->actingAs($admin)->put('/admin/settings/branding', $payload);

        $this->assertSame($path, $this->rawSetting('branding.logo_light'));
        Storage::disk('public')->assertExists($path);
    }

    private function uploadLogo(User $admin, UploadedFile $file): TestResponse
    {
        $payload = $this->browserPayload($admin, 'branding');
        $payload['settings']['logo_light'] = $file;

        return $this->actingAs($admin)
            ->from('/admin/settings/branding')
            ->put('/admin/settings/branding', $payload);
    }

    /**
     * A genuine uploaded file: real bytes in a real temporary file, flagged as a test upload so
     * `move()` works outside a POST, and sniffed by content like any production upload.
     */
    private function realUpload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $bytes);

        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return new UploadedFile($path, $name, null, null, true);
    }

    private function bytes(string $kind): string
    {
        $image = static function (string $format): string {
            $canvas = imagecreatetruecolor(64, 32);
            imagefilledrectangle($canvas, 0, 0, 63, 31, (int) imagecolorallocate($canvas, 79, 70, 229));

            ob_start();
            $format === 'png' ? imagepng($canvas) : imagejpeg($canvas);

            return (string) ob_get_clean();
        };

        return match ($kind) {
            'png' => $image('png'),
            'jpeg' => $image('jpeg'),
            'oversized-png' => $image('png').str_repeat("\0", 3 * 1024 * 1024),
            'pdf' => "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n",
            'svg' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(document.cookie)</script></svg>',
            'php' => "<?php\nsystem(\$_GET['cmd'] ?? 'id');\n",
            default => 'just some text, not pixels',
        };
    }
}
