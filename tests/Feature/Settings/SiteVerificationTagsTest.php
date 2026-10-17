<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Services\Cms\CacheVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * Site-ownership meta tags from Settings › SEO & analytics (`SiteVerificationTags`): Google Search
 * Console, Bing, Yandex, Pinterest, Meta and a box for any other service.
 *
 * The old Google field took only the `content` value; pasting the whole tag Search Console shows — what
 * everybody does — failed a regex in the view and the tag silently never appeared.
 */
final class SiteVerificationTagsTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    private const GOOGLE = 'AbC_dEf-123456789xyzAbC_dEf-123456789xyzAB';

    private const BING = '0123456789ABCDEF0123456789ABCDEF';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    #[Test]
    public function a_pasted_tag_or_a_bare_code_both_reach_the_public_head(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->put('/admin/settings/seo', $this->browserPayload($admin, 'seo', [
                'google_site_verification' => '<meta name="google-site-verification" content="'.self::GOOGLE.'" />',
                'bing_site_verification' => self::BING,
                'custom_meta_tags' => "<meta name=\"ahrefs-site-verification\" content=\"ahrefs123abc\">\n<meta property=\"fb:pages\" content=\"1234567890\" />",
            ]))
            ->assertSessionHasNoErrors();

        $this->flushPublicCache();
        auth()->logout();

        $this->get('/')->assertOk()
            ->assertSee('<meta name="google-site-verification" content="'.self::GOOGLE.'">', false)
            ->assertSee('<meta name="msvalidate.01" content="'.self::BING.'">', false)
            ->assertSee('<meta name="ahrefs-site-verification" content="ahrefs123abc">', false)
            ->assertSee('<meta property="fb:pages" content="1234567890">', false);
    }

    #[Test]
    public function a_value_that_is_not_a_verification_code_is_refused_on_save(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->put('/admin/settings/seo', $this->browserPayload($admin, 'seo', [
                // Bing's tag pasted into the Google field.
                'google_site_verification' => '<meta name="msvalidate.01" content="'.self::BING.'" />',
            ]))
            ->assertSessionHasErrors('settings.google_site_verification');

        $this->assertNull(setting('seo.google_site_verification'));
    }

    #[Test]
    public function the_custom_box_takes_meta_tags_and_nothing_else(): void
    {
        $admin = $this->createSuperAdmin();

        foreach ([
            '<script>alert(1)</script>',
            '<meta http-equiv="refresh" content="0;url=https://elsewhere.test">',
            '<meta name="robots" content="noindex">',
            '<meta name="ahrefs-site-verification" content="ok123"> and some text',
        ] as $bad) {
            $this->actingAs($admin)
                ->put('/admin/settings/seo', $this->browserPayload($admin, 'seo', ['custom_meta_tags' => $bad]))
                ->assertSessionHasErrors('settings.custom_meta_tags');
        }

        $this->assertNull(setting('seo.custom_meta_tags'));
    }

    #[Test]
    public function a_tag_is_rebuilt_and_escaped_never_printed_as_pasted(): void
    {
        settings_repo()->set('seo.custom_meta_tags', '<meta name="x-check" content="a&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;">');
        settings_repo()->flush();
        $this->flushPublicCache();

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('<meta name="x-check" content="a&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;">', $html);
    }

    #[Test]
    public function the_tags_are_on_the_maintenance_page_too(): void
    {
        settings_repo()->set('seo.google_site_verification', self::GOOGLE);
        settings_repo()->set('maintenance.maintenance_mode', true);
        settings_repo()->flush();
        $this->flushPublicCache();

        $this->get('/')->assertStatus(503)
            ->assertSee('<meta name="google-site-verification" content="'.self::GOOGLE.'">', false);
    }

    private function flushPublicCache(): void
    {
        app(CacheVersion::class)->bump('test: verification tags');
    }
}
