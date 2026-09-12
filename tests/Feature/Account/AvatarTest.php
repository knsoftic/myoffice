<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use App\Services\Auth\AvatarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Profile photo upload (phase-01 §7, §10 "Profile": the avatar upload rejects a non-image and a
 * 5 MB file).
 *
 * The type is decided by the file's **content**, not by its name, so a renamed script is refused
 * as well; replacing or removing a photo always deletes the file it replaced, so nothing is
 * orphaned on disk.
 */
final class AvatarTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** UpdateAvatarRequest::MAX_KILOBYTES. */
    private const MAX_KILOBYTES = 2048;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();

        Storage::fake('public');
    }

    #[Test]
    public function a_user_can_upload_a_profile_photo(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->actingAs($user)
            ->from('/account/profile')
            ->post('/account/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 200, 200)])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/account/profile')
            ->assertSessionHas('toast.type', 'success');

        $path = (string) $user->fresh()->avatar_path;

        $this->assertNotSame('', $path);
        $this->assertStringStartsWith(AvatarService::DIRECTORY.'/', $path, 'Avatars live under avatars/.');
        Storage::disk('public')->assertExists($path);

        $this->assertStringNotContainsString(
            'me.jpg',
            $path,
            'The stored name must be generated, never the name the browser sent.'
        );
    }

    #[Test]
    public function the_avatar_url_falls_back_to_a_generated_initials_avatar(): void
    {
        $user = User::factory()->create(['avatar_path' => null, 'name' => 'Ayesha Khan']);

        $this->assertStringStartsWith('data:image/svg+xml', $user->avatar_url);

        $this->actingAs($user)->post('/account/avatar', [
            'avatar' => UploadedFile::fake()->image('me.png', 120, 120),
        ]);

        $this->assertStringNotContainsString('data:image/svg+xml', $user->fresh()->avatar_url);
    }

    /*
    |--------------------------------------------------------------------------
    | Refusals
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_non_image_is_rejected(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->actingAs($user)
            ->from('/account/profile')
            ->post('/account/avatar', [
                'avatar' => UploadedFile::fake()->create('notes.txt', 16, 'text/plain'),
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /**
     * The dangerous case: a script renamed to look like a photo. `image` reads the bytes
     * (getimagesize), so the extension never decides.
     */
    #[Test]
    public function a_script_renamed_to_look_like_an_image_is_rejected(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $payload = UploadedFile::fake()->createWithContent('avatar.jpg', '<?php echo "pwned"; ?>');

        $this->actingAs($user)
            ->from('/account/profile')
            ->post('/account/avatar', ['avatar' => $payload])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function a_file_over_two_megabytes_is_rejected(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        // 5 MB, as the contract's acceptance test words it.
        $oversized = UploadedFile::fake()->image('huge.jpg', 64, 64)->size(5 * 1024);

        $this->assertGreaterThan(self::MAX_KILOBYTES, $oversized->getSize() / 1024);

        $this->actingAs($user)
            ->from('/account/profile')
            ->post('/account/avatar', ['avatar' => $oversized])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    #[Test]
    public function an_image_that_is_too_small_to_be_a_photo_is_rejected(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->actingAs($user)
            ->from('/account/profile')
            ->post('/account/avatar', ['avatar' => UploadedFile::fake()->image('tiny.png', 8, 8)])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    #[Test]
    public function an_empty_submission_is_rejected(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->actingAs($user)
            ->from('/account/profile')
            ->post('/account/avatar', [])
            ->assertSessionHasErrors('avatar');
    }

    /*
    |--------------------------------------------------------------------------
    | Replacement and removal
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function replacing_a_photo_deletes_the_file_it_replaced(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->actingAs($user)->post('/account/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg', 200, 200),
        ]);

        $first = (string) $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($user)->post('/account/avatar', [
            'avatar' => UploadedFile::fake()->image('second.jpg', 200, 200),
        ]);

        $second = (string) $user->fresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);

        $this->assertCount(
            1,
            Storage::disk('public')->allFiles(AvatarService::DIRECTORY),
            'No orphan avatar may be left on disk.'
        );
    }

    #[Test]
    public function a_rejected_replacement_leaves_the_existing_photo_alone(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->actingAs($user)->post('/account/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg', 200, 200),
        ]);

        $first = (string) $user->fresh()->avatar_path;

        $this->actingAs($user)
            ->from('/account/profile')
            ->post('/account/avatar', ['avatar' => UploadedFile::fake()->create('notes.txt', 16, 'text/plain')])
            ->assertSessionHasErrors('avatar');

        $this->assertSame($first, (string) $user->fresh()->avatar_path);
        Storage::disk('public')->assertExists($first);
    }

    #[Test]
    public function a_photo_can_be_removed_and_its_file_is_deleted(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->actingAs($user)->post('/account/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 200, 200),
        ]);

        $path = (string) $user->fresh()->avatar_path;

        $this->actingAs($user)
            ->from('/account/profile')
            ->delete('/account/avatar')
            ->assertRedirect('/account/profile')
            ->assertSessionHas('toast.type', 'success');

        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    #[Test]
    public function removing_a_photo_that_is_not_there_is_a_no_op(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->actingAs($user)
            ->from('/account/profile')
            ->delete('/account/avatar')
            ->assertRedirect('/account/profile')
            ->assertSessionHas('toast.type', 'info');

        $this->assertNull($user->fresh()->avatar_path);
    }

    /**
     * One user's upload must never touch another user's row or file.
     */
    #[Test]
    public function uploading_a_photo_only_ever_writes_the_authenticated_user(): void
    {
        $me = User::factory()->create(['avatar_path' => null]);
        $someoneElse = User::factory()->create(['avatar_path' => 'avatars/not-mine.jpg']);

        $this->actingAs($me)->post('/account/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 200, 200),
            'user_id' => $someoneElse->getKey(),
        ]);

        $this->assertNotNull($me->fresh()->avatar_path);
        $this->assertSame('avatars/not-mine.jpg', (string) $someoneElse->fresh()->avatar_path);
    }

    #[Test]
    public function a_guest_cannot_upload_a_photo(): void
    {
        $this->post('/account/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 200, 200)])
            ->assertRedirect(route('login', absolute: false));
    }
}
