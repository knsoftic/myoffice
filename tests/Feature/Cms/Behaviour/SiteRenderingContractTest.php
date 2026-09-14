<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\FaqCategory;
use App\Services\Cms\MediaService;
use App\Services\Cms\SeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Review round 2 regressions — the public rendering names and behaviours the contracts publish for later
 * phases (phase-03 §6.5, §6.8, §8.14, §12.2 Q3; phase-04 §6.6; phase-19-23 §1.2).
 *
 *   · The footer renders `contact.map_embed`, only through the sanitiser's map-iframe allowlist.
 *   · `<x-site.image :asset="$asset" profile="card" />` — the contract's signature — renders a model.
 *   · `@extends('layouts.site')` is the public layout.
 *   · `SeoService::for()` on a model with no public path of its own is canonical at the path being
 *     rendered on `seo.canonical_base_url`, never on the request's host.
 */
final class SiteRenderingContractTest extends TestCase
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

    public function test_the_footer_renders_the_map_embed_only_through_the_sanitiser(): void
    {
        $this->assertNotNull($this->snapshotOf($this->seededSection('footer', SectionPlacement::GlobalFooter)), 'The seeded footer is published.');

        // No map set: no iframe anywhere on the page.
        $this->get('/')->assertOk()->assertDontSee('<iframe', false);

        // The setting's "embed URL" form is wrapped in an iframe, then sanitised.
        $this->setSetting('contact.map_embed', 'https://www.google.com/maps/embed?pb=FT-map-url');
        $this->bumpPublicCache('map embed set');

        $footer = $this->footerOf((string) $this->get('/')->assertOk()->getContent());

        $this->assertStringContainsString('<iframe', $footer, 'The footer shows the map (§8.14).');
        $this->assertStringContainsString('https://www.google.com/maps/embed?pb=FT-map-url', $footer);

        // The "iframe" form, pasted from the provider.
        $this->setSetting('contact.map_embed', '<iframe src="https://www.google.com/maps/embed?pb=FT-map-iframe" width="600" height="450" onload="alert(1)"></iframe>');
        $this->bumpPublicCache('map embed pasted');

        $footer = $this->footerOf((string) $this->get('/')->assertOk()->getContent());

        $this->assertStringContainsString('https://www.google.com/maps/embed?pb=FT-map-iframe', $footer);
        $this->assertStringNotContainsString('onload', $footer, 'The sanitiser strips every on* attribute.');

        // Anything that is not an allowlisted map renders nothing: no foreign frame, no script, no empty box.
        foreach ([
            '<iframe src="https://evil.example/frame"></iframe><script>alert("FT-map")</script>',
            'https://evil.example/maps/embed?pb=FT-map-evil',
            'javascript:alert(1)',
        ] as $hostile) {
            $this->setSetting('contact.map_embed', $hostile);
            $this->bumpPublicCache('hostile map embed');

            $html = (string) $this->get('/')->assertOk()->getContent();

            $this->assertStringNotContainsString('<iframe', $html, 'Only an allowlisted map iframe ever renders: '.$hostile);
            $this->assertStringNotContainsString('evil.example', $html);
            $this->assertStringNotContainsString('alert(', $html);
        }
    }

    public function test_site_image_accepts_the_contract_asset_signature(): void
    {
        $asset = $this->makeImageAsset(['alt_text' => 'FT asset alt']);
        $snapshot = app(MediaService::class)->toSnapshot($asset, ImageProfile::Card);

        $this->assertNotSame('', $snapshot['url']);

        foreach ([
            '<x-site.image :asset="$asset" profile="card" />',
            '<x-site.image :media="$asset" profile="card" />',
        ] as $template) {
            $html = Blade::render($template, ['asset' => $asset]);

            $this->assertStringContainsString('<img', $html, $template.' renders an image.');
            $this->assertStringContainsString('src="'.e($snapshot['url']).'"', $html, $template.' renders the asset URL.');
            $this->assertStringContainsString('alt="FT asset alt"', $html);

            if ($snapshot['srcset'] !== '') {
                $this->assertStringContainsString('srcset="'.e($snapshot['srcset']).'"', $html, $template.' renders the responsive srcset.');
            }
        }

        // The snapshot array path is unchanged, and no media renders nothing.
        $this->assertStringContainsString('src="'.e($snapshot['url']).'"', Blade::render('<x-site.image :media="$media" profile="card" />', ['media' => $snapshot]));
        $this->assertStringNotContainsString('<img', Blade::render('<x-site.image :asset="null" profile="card" />'));
    }

    public function test_layouts_site_is_the_public_layout(): void
    {
        $this->assertTrue(view()->exists('layouts.site'), 'phase-03 §8.14 / phase-19-23 §1.2 name layouts/site.blade.php.');

        $viaAlias = Blade::render("@extends('layouts.site')\n@section('content')<p>FT layout alias body</p>@endsection");
        $direct = Blade::render("@extends('site.layouts.public')\n@section('content')<p>FT layout alias body</p>@endsection");

        $this->assertStringContainsString('<p>FT layout alias body</p>', $viaAlias);
        $this->assertStringContainsString('id="content"', $viaAlias, 'The alias renders the public shell with its <main id="content">.');
        $this->assertSame($this->withoutNonces($direct), $this->withoutNonces($viaAlias), 'The alias adds nothing of its own.');
    }

    public function test_a_model_without_a_public_path_is_canonical_on_the_configured_base(): void
    {
        // A FAQ category stands in for a later phase's model (a service, a course): saved, SEO-addressable,
        // and not a Page, so it has no public path of its own.
        $this->assertTrue(FaqCategory::query()->exists());

        $this->setSetting('seo.canonical_base_url', 'https://www.ft-seo-base.example');

        Route::middleware('web')->get('ft-seo-canonical/service-detail', static fn () => response(
            app(SeoService::class)->for(FaqCategory::query()->firstOrFail())->canonicalUrl,
        ));

        $this->get('/ft-seo-canonical/service-detail')
            ->assertOk()
            ->assertSeeText('https://www.ft-seo-base.example/ft-seo-canonical/service-detail', false);

        // With no configured base the chain ends at the current URL, as §6.5 says.
        $this->setSetting('seo.canonical_base_url', null);

        $this->assertSame(url('/ft-seo-canonical/service-detail'), (string) $this->get('/ft-seo-canonical/service-detail')->assertOk()->getContent());
    }

    /**
     * The rendered `<footer>` element (the layout's own head legitimately carries an `onload` on the font
     * stylesheet, so the footer is inspected on its own).
     */
    private function footerOf(string $html): string
    {
        $start = strpos($html, '<footer');
        $this->assertNotFalse($start, 'The page renders its footer.');

        return substr($html, (int) $start, (int) strpos($html, '</footer>', (int) $start) - (int) $start);
    }

    private function withoutNonces(string $html): string
    {
        return (string) preg_replace('~\s(?:nonce|data-csp-nonce)="[^"]*"~', '', $html);
    }
}
