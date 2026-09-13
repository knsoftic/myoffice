<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * Screens that promise what a setting enforces (Phase 2 final verification):
 *
 *   · `appearance.table_page_size` — the log screens' page-size select offers and selects the size
 *     the page was rendered at, and the filter form may post it back; the Appearance screen refuses a
 *     size per_page() would silently clamp;
 *   · `security.allowed_file_types` / `security.max_upload_mb` — the profile photo field's accept list
 *     and hint name the types and size the upload rules enforce, not the form's own ceiling.
 */
final class SettingsDrivenScreensTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function logScreens(): array
    {
        return [
            'activity log' => ['/admin/activity-log'],
            'login history' => ['/admin/login-history'],
        ];
    }

    #[Test]
    #[DataProvider('logScreens')]
    public function a_log_screen_selects_the_configured_page_size_and_accepts_it_back(string $path): void
    {
        settings_repo()->set('appearance.table_page_size', 20);

        $admin = $this->createSuperAdmin();

        $html = (string) $this->actingAs($admin)->get($path)->assertOk()->getContent();

        $selected = $this->xpath($html)->query('//select[@name="per_page"]/option[@selected]');

        $this->assertSame(1, $selected->length, 'Exactly one page size is selected.');
        $this->assertSame('20', $selected->item(0)?->getAttribute('value'), 'The select shows the size the page was rendered at.');

        // Submitting the filter form untouched posts that size back: it must be accepted, not refused.
        $this->actingAs($admin)
            ->get($path.'?per_page=20')
            ->assertOk()
            ->assertSessionHasNoErrors();

        // A size outside the offered list is still refused.
        $this->actingAs($admin)
            ->get($path.'?per_page=37')
            ->assertSessionHasErrors('per_page');
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function pageSizes(): array
    {
        return [
            'below the clamp' => ['5', false],
            'above the clamp' => ['200', false],
            'lowest allowed' => ['10', true],
            'highest allowed' => ['100', true],
        ];
    }

    #[Test]
    #[DataProvider('pageSizes')]
    public function the_appearance_screen_only_accepts_a_page_size_per_page_honours(string $size, bool $accepted): void
    {
        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin)
            ->put('/admin/settings/appearance', $this->browserPayload($admin, 'appearance', ['table_page_size' => $size]));

        if ($accepted) {
            $response->assertSessionHasNoErrors();
            $this->assertSame((int) $size, per_page());

            return;
        }

        $response->assertSessionHasErrors('settings.table_page_size');
        $this->assertSame('15', $this->rawSetting('appearance.table_page_size'));
    }

    #[Test]
    public function the_profile_photo_field_names_the_types_and_size_the_rules_enforce(): void
    {
        settings_repo()->set('security.allowed_file_types', 'pdf,jpg,jpeg,png');
        settings_repo()->set('security.max_upload_mb', 1);

        $user = $this->createSuperAdmin();

        $html = (string) $this->actingAs($user)->get('/account/profile')->assertOk()->getContent();

        $input = $this->xpath($html)->query('//input[@type="file"][@name="avatar"]');

        $this->assertSame(1, $input->length);
        $this->assertSame('image/jpeg,image/png', $input->item(0)?->getAttribute('accept'));
        $this->assertStringContainsString('JPG, JPEG or PNG, up to 1 MB', $html);
        $this->assertStringNotContainsString('WEBP or GIF', $html, 'The field must not promise types the Security settings refuse.');
    }
}
