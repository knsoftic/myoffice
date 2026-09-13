<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Http\Requests\Account\UpdateAvatarRequest;
use App\Models\User;
use App\Services\Core\SettingsService;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Phase 2 closing pass — the profile photo obeys the Security settings, and the settings can only
 * ever narrow it.
 *
 *   · `security.allowed_file_types` decides which image types a photo may be (gif is allowed by
 *     default), and `security.max_upload_mb` lowers the size cap;
 *   · neither can widen anything: the form's own 2 MB cap stands whatever the setting says, a script
 *     name or a script disguised as an image is refused even when a raw row lists `php`, and a file
 *     larger than PHP's own upload limit is refused however high the setting is.
 *
 * `AvatarTest` (Phase 1) proves the photo flow itself; this class is only about the settings.
 */
final class AvatarUploadPolicyTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** UpdateAvatarRequest::MAX_KILOBYTES. */
    private const OWN_CAP_KILOBYTES = 2048;

    /** A raw row an older release or a console edit could leave behind — the registry refuses it. */
    private const HOSTILE_ALLOWED_TYPES = 'php,phtml,phar,pht,php7,png,jpg,jpeg,gif,webp';

    /**
     * Temporary files written by realUpload() / temporaryImage(), removed at tear-down.
     *
     * @var list<string>
     */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Types
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_gif_photo_is_accepted_with_the_default_allowed_types(): void
    {
        $this->assertContains('gif', explode(',', (string) SettingsRegistry::field('security.allowed_file_types')['default']));
        $this->assertContains('gif', explode(',', (string) setting('security.allowed_file_types')), 'A fresh install is seeded with gif allowed.');

        $user = User::factory()->create(['avatar_path' => null]);

        $this->upload($user, UploadedFile::fake()->image('me.gif', 120, 120))->assertSessionHasNoErrors();

        $path = (string) $user->fresh()->avatar_path;

        $this->assertStringEndsWith('.gif', $path);
        Storage::disk('public')->assertExists($path);
    }

    #[Test]
    public function the_allowed_types_setting_decides_which_image_types_a_photo_may_be(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->saveSecurity(['allowed_file_types' => 'pdf,docx,jpg,jpeg']);

        foreach (['me.png' => 'png', 'me.gif' => 'gif', 'me.webp' => 'webp'] as $name => $type) {
            $this->upload($user, $this->fakeImage($name))->assertSessionHasErrors('avatar');
            $this->assertNull($user->fresh()->avatar_path, sprintf('A %s photo was stored although the setting no longer allows %s.', $type, $type));
        }

        $this->upload($user, UploadedFile::fake()->image('me.jpg', 120, 120))->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.jpg', (string) $user->fresh()->avatar_path);

        // The other way round: png only.
        $this->saveSecurity(['allowed_file_types' => 'png']);

        $before = (string) $user->fresh()->avatar_path;

        $this->upload($user, UploadedFile::fake()->image('again.jpg', 120, 120))->assertSessionHasErrors('avatar');
        $this->assertSame($before, (string) $user->fresh()->avatar_path);

        $this->upload($user, UploadedFile::fake()->image('again.png', 120, 120))->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.png', (string) $user->fresh()->avatar_path);

        // The profile screen offers exactly what the rules enforce.
        $this->actingAs($user)
            ->get('/account/profile')
            ->assertOk()
            ->assertSee('accept="image/png"', false)
            ->assertDontSee('image/jpeg', false);
    }

    #[Test]
    public function a_setting_that_allows_no_image_type_refuses_every_photo_with_a_sentence_that_says_so(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->saveSecurity(['allowed_file_types' => 'pdf,docx,zip']);

        $this->upload($user, UploadedFile::fake()->image('me.png', 120, 120))
            ->assertSessionHasErrors(['avatar' => 'Profile photos are not among the upload types currently allowed.']);

        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /*
    |--------------------------------------------------------------------------
    | Size
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_size_setting_lowers_the_photo_cap(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->saveSecurity(['max_upload_mb' => 1]);

        $this->upload($user, UploadedFile::fake()->image('big.png', 120, 120)->size(1536))
            ->assertSessionHasErrors(['avatar' => 'The image may not be larger than 1 MB.']);
        $this->assertNull($user->fresh()->avatar_path, 'A 1.5 MB photo was stored under a 1 MB setting.');

        $this->upload($user, UploadedFile::fake()->image('small.png', 120, 120)->size(900))->assertSessionHasNoErrors();
        $this->assertNotNull($user->fresh()->avatar_path);

        // Raised back to 2 MB, the same 1.5 MB photo fits.
        $this->saveSecurity(['max_upload_mb' => 2]);

        $this->upload($user, UploadedFile::fake()->image('big-again.png', 120, 120)->size(1536))->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_size_setting_can_never_raise_the_photo_cap(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->saveSecurity(['max_upload_mb' => SettingsRegistry::MAX_UPLOAD_MB_MAX]);

        $this->assertSame(self::OWN_CAP_KILOBYTES, UpdateAvatarRequest::maxKilobytes());

        $this->upload($user, UploadedFile::fake()->image('huge.png', 120, 120)->size(3 * 1024))->assertSessionHasErrors('avatar');
        $this->assertNull($user->fresh()->avatar_path);

        // A raw row far above the registry's own ceiling changes nothing either.
        $this->putRaw('security.max_upload_mb', '999999');

        $this->assertSame(self::OWN_CAP_KILOBYTES, UpdateAvatarRequest::maxKilobytes());
        $this->upload($user, UploadedFile::fake()->image('huge.png', 120, 120)->size(3 * 1024))->assertSessionHasErrors('avatar');
        $this->assertNull($user->fresh()->avatar_path);
    }

    /**
     * PHP itself refuses a file above `upload_max_filesize` and hands the application an upload with
     * `UPLOAD_ERR_INI_SIZE`. However generous the setting, that upload is refused rather than stored.
     */
    #[Test]
    public function an_upload_php_refused_for_its_ini_size_is_refused_even_when_the_setting_is_higher(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->saveSecurity(['max_upload_mb' => SettingsRegistry::MAX_UPLOAD_MB_MAX]);

        $path = $this->temporaryImage(64);

        try {
            $refusedByPhp = new UploadedFile($path, 'photo.png', 'image/png', UPLOAD_ERR_INI_SIZE, true);

            $this->upload($user, $refusedByPhp)->assertSessionHasErrors('avatar');

            $this->assertNull($user->fresh()->avatar_path);
            $this->assertSame([], Storage::disk('public')->allFiles());
        } finally {
            @unlink($path);
        }
    }

    /**
     * The rule's own ceiling is never above PHP's upload limit: with `upload_max_filesize = 1M` a
     * 1.5 MB photo is refused by the form — under the 2 MB cap and far under a 512 MB setting.
     *
     * `upload_max_filesize` cannot be changed at runtime (PHP_INI_PERDIR), so the rules are evaluated in
     * a child PHP process started with `-d upload_max_filesize=1M`, booted against the test schema. The
     * child cannot see this test's transaction, so it raises `max_upload_mb` inside a transaction of its
     * own and rolls it back. The same file is then uploaded here, under this process's larger limit, and
     * accepted — so the refusal in the child is the PHP limit and nothing else.
     */
    #[Test]
    public function a_photo_above_the_php_upload_limit_is_refused_even_when_the_setting_is_higher(): void
    {
        $directory = storage_path('framework/testing');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $token = bin2hex(random_bytes(6));
        $script = $directory.'/avatar-ini-probe-'.$token.'.php';
        $image = $this->temporaryImage(1536);

        file_put_contents($script, self::childProbe());

        try {
            $process = new Process(
                [PHP_BINARY, '-d', 'upload_max_filesize=1M', '-d', 'post_max_size=8M', $script, base_path(), $image],
                base_path(),
                [
                    'APP_ENV' => 'testing',
                    // Never read a configuration cached by the dev install: it would point at the dev database.
                    'APP_CONFIG_CACHE' => 'storage/framework/testing/avatar-ini-probe-'.$token.'-config.php',
                    'DB_CONNECTION' => 'mysql',
                    'DB_HOST' => (string) config('database.connections.mysql.host'),
                    'DB_PORT' => (string) config('database.connections.mysql.port'),
                    'DB_DATABASE' => 'my_office_test',
                    'DB_USERNAME' => (string) config('database.connections.mysql.username'),
                    'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
                    'DB_URL' => '',
                    'CACHE_STORE' => 'array',
                    'SESSION_DRIVER' => 'array',
                    'QUEUE_CONNECTION' => 'sync',
                    'MAIL_MAILER' => 'array',
                ],
            );
            $process->setTimeout(120);
            $process->run();

            $this->assertTrue($process->isSuccessful(), 'The child probe failed: '.$process->getErrorOutput().$process->getOutput());

            $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];
            $result = json_decode((string) end($lines), true);

            $this->assertIsArray($result, 'The child probe printed no result: '.$process->getOutput());

            $this->assertSame('1M', $result['upload_max_filesize'], 'The child runs under the lowered PHP limit.');
            $this->assertSame(SettingsRegistry::MAX_UPLOAD_MB_MAX, $result['setting'], 'The setting is far above the PHP limit.');
            $this->assertSame(1024, $result['max_kilobytes'], 'The photo cap follows PHP’s limit when it is the lowest.');
            $this->assertTrue($result['fails'], 'A 1.5 MB photo passed the rules under a 1 MB PHP upload limit.');
            $this->assertContains('The image may not be larger than 1 MB.', $result['errors']);
            $this->assertStringContainsString('up to 1 MB', (string) $result['hint'], 'The screen promises no more than PHP will accept.');

            // Control: under this process's own, larger limit the very same file is a valid photo.
            $this->assertGreaterThan(1536, (int) SettingsRegistry::phpUploadLimitKilobytes(), 'Precondition: this process accepts a 1.5 MB upload.');

            $user = User::factory()->create(['avatar_path' => null]);

            $this->upload($user, new UploadedFile($image, 'photo.png', 'image/png', UPLOAD_ERR_OK, true))->assertSessionHasNoErrors();
            $this->assertNotNull($user->fresh()->avatar_path);
        } finally {
            @unlink($script);
            @unlink($image);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Script names and script payloads, whatever the setting says
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_registry_refuses_to_save_a_script_type_in_the_first_place(): void
    {
        try {
            $this->saveSecurity(['allowed_file_types' => 'png,jpg,php']);
            $this->fail('SettingsService saved php as an allowed upload type.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allowed_file_types', $exception->errors());
        }

        $this->assertStringNotContainsString('php', (string) setting('security.allowed_file_types'));
    }

    #[Test]
    public function a_php_family_file_name_is_refused_even_when_a_raw_row_allows_it(): void
    {
        $this->putRaw('security.allowed_file_types', self::HOSTILE_ALLOWED_TYPES);

        $allowed = UpdateAvatarRequest::allowedExtensions();

        foreach (['php', 'phtml', 'phar', 'pht', 'php7'] as $script) {
            $this->assertNotContains($script, $allowed, sprintf('%s reached the photo rules from the raw setting.', $script));
        }

        $user = User::factory()->create(['avatar_path' => null]);

        // A genuine image — only the name is hostile — so the name alone must be what refuses it.
        foreach (['avatar.php', 'avatar.phtml', 'AVATAR.PHP', 'avatar.phar', 'avatar.php7'] as $name) {
            $this->upload($user, $this->realUpload($name, $this->pngBytes()))->assertSessionHasErrors('avatar');

            $this->assertNull($user->fresh()->avatar_path, $name.' was stored.');
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function a_php_payload_disguised_as_an_image_is_refused_even_when_a_raw_row_allows_php(): void
    {
        $this->putRaw('security.allowed_file_types', self::HOSTILE_ALLOWED_TYPES);

        $user = User::factory()->create(['avatar_path' => null]);

        // Deliberately inert PHP: what matters is that the bytes are PHP, not what they would do. A
        // working web-shell body is picked up by endpoint antivirus the moment it touches the disk,
        // which would make the file unreadable and the test about the antivirus instead of the app.
        $payloads = [
            'avatar.png' => '<?php echo "avatar"; ?>',
            'avatar.gif' => "<?php\n\$greeting = 'hello';\necho \$greeting;\n",
            'avatar.jpg' => '<?php phpversion(); ?>',
            'avatar.webp' => '<?= date("Y") ?>',
            'header-only.gif' => 'GIF89a<?php echo "avatar"; ?>',
        ];

        foreach ($payloads as $name => $content) {
            $this->upload($user, $this->realUpload($name, $content))->assertSessionHasErrors('avatar');

            $this->assertNull($user->fresh()->avatar_path, $name.' carrying PHP was stored.');
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function an_accepted_photo_is_stored_under_a_generated_name_whatever_name_it_arrived_under(): void
    {
        $this->putRaw('security.allowed_file_types', self::HOSTILE_ALLOWED_TYPES);

        $user = User::factory()->create(['avatar_path' => null]);

        $this->upload($user, $this->realUpload('shell.php.png', $this->pngBytes()))->assertSessionHasNoErrors();

        $path = (string) $user->fresh()->avatar_path;

        $this->assertMatchesRegularExpression('#^avatars/[A-Za-z0-9]{40}\.png$#', $path, 'The stored name is random and its extension comes from the content.');
        $this->assertStringNotContainsStringIgnoringCase('php', $path);
        Storage::disk('public')->assertExists($path);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function upload(User $user, UploadedFile $file): TestResponse
    {
        return $this->actingAs($user)
            ->from('/account/profile')
            ->post('/account/avatar', ['avatar' => $file]);
    }

    /**
     * Save Security settings through the real write path.
     *
     * @param  array<string, mixed>  $values
     */
    private function saveSecurity(array $values): void
    {
        app(SettingsService::class)->update('security', $values, $this->createSuperAdmin());
    }

    private function putRaw(string $dotted, string $value): void
    {
        [$group, $key] = explode('.', $dotted, 2);

        $this->assertSame(1, DB::table('settings')->where('group', $group)->where('key', $key)->update(['value' => $value]));

        app(SettingsRepository::class)->flush();
    }

    private function fakeImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 120, 120);
    }

    /**
     * An upload whose MIME type is sniffed from its bytes, the way a real request's is.
     *
     * `UploadedFile::fake()` reports a MIME type derived from the file NAME, which would let a
     * `.png`-named script look like a PNG to every rule but `dimensions`; a file disguised as an image
     * has to be judged by its content, so these are real files handed over as a browser would.
     */
    private function realUpload(string $name, string $bytes): UploadedFile
    {
        $path = storage_path('framework/testing/avatar-policy-'.bin2hex(random_bytes(6)).'.upload');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, null, UPLOAD_ERR_OK, true);
    }

    private function pngBytes(int $width = 64, int $height = 64): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * A genuine 64 x 64 PNG padded with trailing bytes to the given size, on disk.
     */
    private function temporaryImage(int $kilobytes): string
    {
        $png = $this->pngBytes();
        $path = storage_path('framework/testing/avatar-policy-'.bin2hex(random_bytes(6)).'.png');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $png.str_repeat("\0", max(0, $kilobytes * 1024 - strlen($png))));
        $this->temporaryFiles[] = $path;

        $this->assertSame([64, 64], array_slice((array) getimagesize($path), 0, 2), 'The padded file is still a readable image.');

        return $path;
    }

    /**
     * The child process: boot the application against the test schema, raise the setting inside a
     * rolled-back transaction, and run the photo rules on the file under the lowered PHP limit.
     */
    private static function childProbe(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

[, $base, $image] = $argv;

require $base.'/vendor/autoload.php';

$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = Illuminate\Support\Facades\DB::connection();

if ($db->getDatabaseName() !== 'my_office_test') {
    fwrite(STDERR, 'Refusing to run against '.$db->getDatabaseName());
    exit(2);
}

$db->beginTransaction();

try {
    $db->table('settings')->where('group', 'security')->where('key', 'max_upload_mb')->update(['value' => '512']);
    settings_repo()->flush();

    $request = new App\Http\Requests\Account\UpdateAvatarRequest();
    $file = new Illuminate\Http\UploadedFile($image, 'photo.png', 'image/png', UPLOAD_ERR_OK, true);
    $validator = Illuminate\Support\Facades\Validator::make(['avatar' => $file], $request->rules(), $request->messages(), $request->attributes());

    echo PHP_EOL.json_encode([
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'setting' => settings_repo()->get('security.max_upload_mb'),
        'max_kilobytes' => App\Http\Requests\Account\UpdateAvatarRequest::maxKilobytes(),
        'fails' => $validator->fails(),
        'errors' => $validator->errors()->get('avatar'),
        'hint' => App\Http\Requests\Account\UpdateAvatarRequest::hint(),
    ]).PHP_EOL;
} finally {
    $db->rollBack();
}
PHP;
    }
}
