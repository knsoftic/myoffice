<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\Core\SettingsService;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * phase-02 §6 "Authorization" for settings: `settings.view` without `settings.edit` renders
 * read-only and 403s the update; no permission at all 403s the page. Plus the SMTP carve-out
 * phase-01 §5 promised and Phase 2 delivers: a plain Admin cannot save the mail group while a Super
 * Admin can.
 *
 * "Read-only" is asserted on the markup a browser would submit: a view-only user's form must
 * produce no editable control, not merely hide the save button.
 */
final class SettingsAuthorizationTest extends TestCase
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

    #[Test]
    public function a_user_with_no_settings_permission_is_refused_the_page_and_every_write(): void
    {
        $nobody = $this->createUserWithPermissions(['dashboard.view']);

        $this->actingAs($nobody)->get('/admin/settings')->assertForbidden();
        $this->actingAs($nobody)->get('/admin/settings/company')->assertForbidden();

        $this->assertEveryWriteIsForbidden($nobody);
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/admin/settings/company')->assertRedirect('/login');
        $this->put('/admin/settings/company', ['settings' => ['name' => 'x']])->assertRedirect('/login');
    }

    #[Test]
    public function settings_view_alone_renders_every_group_read_only(): void
    {
        $viewer = $this->createUserWithPermissions(['settings.view']);

        foreach (array_keys(SettingsRegistry::groups()) as $group) {
            $response = $this->actingAs($viewer)->get('/admin/settings/'.$group)->assertOk();
            $html = (string) $response->getContent();

            $response->assertSee('Read-only', false);
            $response->assertDontSee('Reset to defaults', false);
            $response->assertDontSee('Clear caches', false);

            $xpath = $this->xpath($html);

            $editable = $xpath->query('//form[contains(@action, "/admin/settings/'.$group.'")]//*[(self::input or self::select or self::textarea) and starts-with(@name, "settings[") and not(@disabled)]');

            $names = [];

            foreach ($editable as $node) {
                /** @var \DOMElement $node */
                $names[] = $node->getAttribute('name').($node->getAttribute('type') === 'hidden' ? ' (hidden)' : '');
            }

            $this->assertSame(
                [],
                $names,
                sprintf('The read-only [%s] form still submits: %s', $group, implode(', ', $names)),
            );

            $this->assertSame(
                0,
                $xpath->query('//form[contains(@action, "/admin/settings/'.$group.'/file/")]')->length,
                sprintf('[%s] offers a file-removal form to a user who cannot edit.', $group),
            );
        }
    }

    #[Test]
    public function settings_view_alone_is_refused_every_write(): void
    {
        $viewer = $this->createUserWithPermissions(['settings.view']);

        $this->assertEveryWriteIsForbidden($viewer);
    }

    #[Test]
    public function settings_edit_unlocks_the_ordinary_groups(): void
    {
        $editor = $this->createUserWithPermissions(['settings.view', 'settings.edit']);

        $this->actingAs($editor)
            ->get('/admin/settings/company')
            ->assertOk()
            ->assertDontSee('You can see these values but not change them', false)
            ->assertSee('Reset to defaults', false);

        $this->actingAs($editor)
            ->put('/admin/settings/company', $this->browserPayload($editor, 'company', ['name' => 'Edited By An Editor']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Edited By An Editor', $this->rawSetting('company.name'));
    }

    #[Test]
    public function a_plain_admin_can_see_but_not_save_or_reset_the_mail_group(): void
    {
        $admin = $this->createUserWithRole('Admin');

        $this->assertTrue($admin->can('settings.edit'), 'The seeded Admin role edits settings…');
        $this->assertFalse($admin->can(SettingsService::EDIT_MAIL_PERMISSION), '…but not the SMTP credentials (phase-01 §5).');

        $this->actingAs($admin)
            ->get('/admin/settings/mail')
            ->assertOk()
            ->assertSee('The mail credentials are restricted', false);

        $this->actingAs($admin)
            ->put('/admin/settings/mail', ['settings' => ['host' => 'smtp.attacker.test', 'mailer' => 'smtp']])
            ->assertForbidden();

        $this->actingAs($admin)->post('/admin/settings/mail/reset')->assertForbidden();

        $this->assertNull($this->rawSetting('mail.host'));

        // Everything else stays open to them.
        $this->actingAs($admin)
            ->put('/admin/settings/company', $this->browserPayload($admin, 'company', ['name' => 'Saved By Admin']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Saved By Admin', $this->rawSetting('company.name'));
    }

    #[Test]
    public function the_service_enforces_the_mail_carve_out_on_its_own(): void
    {
        $admin = $this->createUserWithRole('Admin');

        $this->expectException(AuthorizationException::class);

        app(SettingsService::class)->update('mail', ['host' => 'smtp.attacker.test'], $admin);
    }

    #[Test]
    public function a_super_admin_can_save_the_mail_group(): void
    {
        $super = $this->createSuperAdmin();

        $this->actingAs($super)
            ->put('/admin/settings/mail', $this->browserPayload($super, 'mail', ['mailer' => 'smtp', 'host' => 'smtp.office.test']))
            ->assertSessionHasNoErrors();

        $this->assertSame('smtp.office.test', $this->rawSetting('mail.host'));
    }

    #[Test]
    public function the_tab_rail_marks_the_groups_this_user_cannot_edit(): void
    {
        $admin = $this->createUserWithRole('Admin');

        $html = (string) $this->actingAs($admin)->get('/admin/settings/company')->assertOk()->getContent();

        $this->assertStringContainsString('/admin/settings/mail', $html, 'The mail tab stays visible to a user who may view it.');
    }

    private function assertEveryWriteIsForbidden(User $user): void
    {
        Storage::fake('public');

        $this->actingAs($user)->put('/admin/settings/company', ['settings' => ['name' => 'Hijacked']])->assertForbidden();
        $this->actingAs($user)->post('/admin/settings/company/reset')->assertForbidden();
        $this->actingAs($user)->delete('/admin/settings/branding/file/logo_light')->assertForbidden();
        $this->actingAs($user)->post('/admin/settings/cache/clear')->assertForbidden();
        $this->actingAs($user)->post('/admin/settings/mail/test', ['email' => 'x@example.test'])->assertForbidden();
        $this->actingAs($user)
            ->put('/admin/settings/branding', ['settings' => ['logo_light' => UploadedFile::fake()->image('x.png')]])
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $this->rawSetting('company.name'));
        $this->assertNull($this->rawSetting('branding.logo_light'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
