<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Enums\Cms\RobotsDirective;
use App\Services\Cms\SeoService;
use App\Services\Cms\SitemapGenerator;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-03 §11.8 — SEO, sitemap, robots (FT-39, FT-40, FT-46, FT-47).
 *
 * D23: one SEO store, one fallback chain applied per field, and a robots rule where the strictest wins —
 * a site that is not indexable, in maintenance or previewing never tells a crawler "index". The sitemap
 * lists exactly the published, indexable, included pages (never a draft, a scheduled or trashed page, a
 * noindex target or an excluded row), and robots.txt answers in every mode — including while the site is
 * closed ([D-W3-13]).
 */
final class SeoSitemapRobotsTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    private const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    /** FT-39 */
    public function test_seo_fallback_chain_per_field(): void
    {
        $this->setSetting('company.name', 'FT39 Company');
        $this->setSetting('company.short_description', '');
        $this->setSetting('seo.meta_title', 'FT39 Site Meta Title');
        $this->setSetting('seo.meta_description', '');
        $this->setSetting('seo.og_image', '');
        $this->setSetting('branding.og_image', '');
        $this->setSetting('seo.canonical_base_url', 'https://www.ft39.example.test');

        // An empty seo_meta for the home route.
        $this->seo()->ensure(SeoService::HOME_ROUTE);
        DB::table('seo_meta')->where('route_key', SeoService::HOME_ROUTE)->update([
            'title' => null,
            'meta_description' => null,
            'canonical_url' => null,
            'og_image_media_id' => null,
        ]);

        $this->assertSame('FT39 Site Meta Title | FT39 Company', $this->seo()->for(SeoService::HOME_ROUTE)->title, 'An empty title falls back to seo.meta_title, suffixed with the company.');
        $this->get('/')->assertOk()->assertSee('<title>FT39 Site Meta Title | FT39 Company</title>', false);

        $this->setSetting('seo.meta_title', '');
        $this->bumpPublicCache('FT-39 meta title cleared');

        $this->assertSame('FT39 Company', $this->seo()->for(SeoService::HOME_ROUTE)->title, 'With no seo.meta_title the title is the company name.');
        $this->get('/')->assertOk()->assertSee('<title>FT39 Company</title>', false);

        // A page with only an excerpt uses it as the description.
        $page = $this->makePage('ft39-page', 'FT39 Page Title', '<p>FT39 page body</p>', true, ['excerpt' => 'FT39 excerpt used as the description.']);

        $payload = $this->seo()->for($page, false, '/ft39-page');
        $this->assertSame('FT39 excerpt used as the description.', $payload->metaDescription);
        $this->assertSame('https://www.ft39.example.test/ft39-page', $payload->canonicalUrl, 'The canonical defaults to the absolute URL built from seo.canonical_base_url.');

        $html = (string) $this->get('/ft39-page')->assertOk()->getContent();
        $this->assertStringContainsString('content="FT39 excerpt used as the description."', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://www.ft39.example.test/ft39-page">', $html);

        // OG image: page banner -> seo.og_image -> branding.og_image.
        $banner = $this->makeImageAsset(['alt_text' => 'FT39 banner']);
        $page = $this->pages()->saveDraft($page, ['banner_media_id' => (int) $banner->getKey()]);

        $this->assertStringContainsString(basename((string) $banner->directory), (string) $this->seo()->for($page)->ogImageUrl, 'The page banner is the first OG image fallback.');

        $page = $this->pages()->saveDraft($page, ['banner_media_id' => null]);
        $this->setSetting('seo.og_image', 'seo/ft39-site-og.png');
        $this->setSetting('branding.og_image', 'branding/ft39-brand-og.png');

        $this->assertStringContainsString('seo/ft39-site-og.png', (string) $this->seo()->for($page)->ogImageUrl, 'Without a banner, seo.og_image.');

        $this->setSetting('seo.og_image', '');

        $this->assertStringContainsString('branding/ft39-brand-og.png', (string) $this->seo()->for($page)->ogImageUrl, 'Without seo.og_image, branding.og_image.');
    }

    /** FT-40 */
    public function test_noindex_is_the_strictest_wins(): void
    {
        $page = $this->makePage('ft40-page', 'FT40 Page', '<p>FT40 page body</p>');
        $this->seo()->save($page, ['robots' => RobotsDirective::IndexFollow->value]);

        $this->setSetting('seo.robots_indexable', true);
        $this->bumpPublicCache('FT-40 baseline');

        $indexable = $this->get('/ft40-page')->assertOk();
        $this->assertMatchesRegularExpression('~<meta\s+name="robots"\s+content="index, follow"~i', (string) $indexable->getContent());

        // Site-wide not indexable.
        $this->setSetting('seo.robots_indexable', false);
        $this->bumpPublicCache('FT-40 robots_indexable off');

        $this->assertSame(RobotsDirective::NoindexNofollow, $this->seo()->for($page)->robots);

        $closed = $this->get('/ft40-page')->assertOk();
        $this->assertSame('noindex, nofollow', $closed->headers->get('X-Robots-Tag'));
        $this->assertMatchesRegularExpression('~<meta\s+name="robots"\s+content="noindex, nofollow"~i', (string) $closed->getContent());

        $this->setSetting('seo.robots_indexable', true);

        // Maintenance.
        $this->setSetting('maintenance.maintenance_mode', true);
        $this->bumpPublicCache('FT-40 maintenance on');

        $this->assertSame(RobotsDirective::NoindexNofollow, $this->seo()->for($page)->robots, 'Maintenance forces noindex, nofollow.');
        $this->get('/ft40-page')->assertStatus(503)->assertSee('noindex', false);

        $this->setSetting('maintenance.maintenance_mode', false);

        // Preview.
        $this->assertSame(RobotsDirective::NoindexNofollow, $this->seo()->for($page, true)->robots, 'A preview forces noindex, nofollow.');

        $preview = $this->actingAs($this->createUserWithPermissions(['pages.view']))
            ->get(route('site.preview.page', $page))
            ->assertOk();

        $this->assertSame('noindex, nofollow', $preview->headers->get('X-Robots-Tag'));
        $this->assertMatchesRegularExpression('~<meta\s+name="robots"\s+content="noindex, nofollow"~i', (string) $preview->getContent());
    }

    /** FT-46 */
    public function test_sitemap_contents_are_exactly_right(): void
    {
        $base = 'https://www.ft46.example.test';
        $this->setSetting('seo.canonical_base_url', $base);
        $this->setSetting('seo.sitemap_enabled', true);
        $this->setSetting('seo.robots_indexable', true);

        // Published at T1, edited at T2: lastmod is the later updated_at.
        $t1 = Carbon::parse('2026-09-01 08:00:00', 'UTC');
        $t2 = Carbon::parse('2026-09-05 09:30:00', 'UTC');
        $t3 = Carbon::parse('2026-09-08 14:15:00', 'UTC');

        $this->travelTo($t1);
        $included = $this->makePage('ft46-included', 'FT46 Included');

        $this->travelTo($t2);
        $this->pages()->saveDraft($included, ['excerpt' => 'FT46 edited after publishing']);

        // Published at T3, with an earlier updated_at: lastmod is published_at.
        $this->travelTo($t3);
        $publishedLater = $this->makePage('ft46-published-later', 'FT46 Published Later');
        DB::table('pages')->where('id', $publishedLater->getKey())->update(['updated_at' => Carbon::parse('2026-08-01 00:00:00', 'UTC')]);

        $this->makePage('ft46-draft', 'FT46 Draft', null, false);

        $scheduled = $this->makePage('ft46-scheduled', 'FT46 Scheduled', null, false);
        $this->publisher()->schedule($scheduled, $t3->copy()->addDays(2));

        $trashed = $this->makePage('ft46-trashed', 'FT46 Trashed');
        $this->pages()->delete($trashed);

        $noindex = $this->makePage('ft46-noindex', 'FT46 Noindex');
        $this->seo()->save($noindex, ['robots' => RobotsDirective::NoindexFollow->value]);

        $excluded = $this->makePage('ft46-excluded', 'FT46 Excluded');
        $this->seo()->save($excluded, ['sitemap_include' => false]);

        $this->bumpPublicCache('FT-46 sitemap');

        $xml = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

        $document = $this->validatedSitemap($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', self::SITEMAP_NS);

        $lastmods = [];

        foreach ($xpath->query('/s:urlset/s:url') ?: [] as $url) {
            $loc = trim((string) $xpath->evaluate('string(s:loc)', $url));
            $lastmods[$loc] = trim((string) $xpath->evaluate('string(s:lastmod)', $url));
        }

        $this->assertArrayHasKey($base.'/ft46-included', $lastmods);
        $this->assertArrayHasKey($base.'/ft46-published-later', $lastmods);
        $this->assertArrayHasKey($base.'/', $lastmods, 'The static site.home route is included.');

        foreach (['ft46-draft', 'ft46-scheduled', 'ft46-trashed', 'ft46-noindex', 'ft46-excluded'] as $slug) {
            $this->assertArrayNotHasKey($base.'/'.$slug, $lastmods, sprintf('/%s must not be in the sitemap.', $slug));
        }

        $this->assertSame($t2->toAtomString(), $lastmods[$base.'/ft46-included'], 'lastmod is the later updated_at.');
        $this->assertSame($t3->toAtomString(), $lastmods[$base.'/ft46-published-later'], 'lastmod is published_at when it is later.');

        // A generation row records the URL count and the per-provider breakdown.
        $generation = app(SitemapGenerator::class)->regenerate('manual');

        $row = DB::table('sitemap_generations')->where('id', $generation->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame('ok', $row->status);
        $this->assertSame(count($lastmods), (int) $row->url_count);

        $providers = (array) json_decode((string) $row->providers, true);
        $this->assertArrayHasKey('pages', $providers);
        $this->assertArrayHasKey('static', $providers);
        $this->assertSame((int) $row->url_count, array_sum(array_map('intval', $providers)), 'The breakdown accounts for every URL.');

        $slugs = DB::table('pages')->pluck('slug')->map(static fn (mixed $slug): string => (string) $slug)->all();
        $pageLocs = array_filter(
            array_keys($lastmods),
            static fn (string $loc): bool => str_starts_with($loc, $base.'/') && in_array(substr($loc, strlen($base) + 1), $slugs, true),
        );
        $this->assertSame(count($pageLocs), (int) $providers['pages'], 'The pages provider counts exactly the page URLs.');

        // Disabled: 404.
        $this->setSetting('seo.sitemap_enabled', false);
        $this->bumpPublicCache('FT-46 sitemap off');

        $this->get('/sitemap.xml')->assertNotFound();
    }

    /** FT-47 */
    public function test_robots_txt_in_each_mode(): void
    {
        $base = 'https://www.ft47.example.test';
        $this->setSetting('seo.canonical_base_url', $base);
        $this->setSetting('seo.robots_txt_mode', 'auto');
        $this->setSetting('seo.robots_indexable', true);
        $this->setSetting('seo.sitemap_enabled', true);

        $auto = $this->get('/robots.txt')->assertOk();
        $this->assertStringStartsWith('text/plain', (string) $auto->headers->get('Content-Type'));

        $body = (string) $auto->getContent();

        foreach (['/admin', '/collaborator', '/student', '/teacher', '/client', '/login', '/preview'] as $path) {
            $this->assertMatchesRegularExpression('~^Disallow: '.preg_quote($path, '~').'$~m', $body, sprintf('auto mode disallows %s.', $path));
        }

        $this->assertMatchesRegularExpression('~^Sitemap: '.preg_quote($base.'/sitemap.xml', '~').'$~m', $body);

        // Not indexable: stay away, and no sitemap line.
        $this->setSetting('seo.robots_indexable', false);

        $body = (string) $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~^Disallow: /$~m', $body);
        $this->assertStringNotContainsString('Sitemap:', $body);

        $this->setSetting('seo.robots_indexable', true);

        // Maintenance: the same, and robots.txt still answers 200 while the site is closed.
        $this->setSetting('maintenance.maintenance_mode', true);

        $this->get('/')->assertStatus(503);

        $body = (string) $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~^Disallow: /$~m', $body);
        $this->assertStringNotContainsString('Sitemap:', $body);

        $this->setSetting('maintenance.maintenance_mode', false);

        // Custom: the stored text verbatim, with the sitemap line appended.
        $this->setSetting('seo.robots_txt_mode', 'custom');
        $this->setSetting('seo.robots_txt_custom', "User-agent: *\nDisallow: /ft47-private");

        $this->assertSame(
            "User-agent: *\nDisallow: /ft47-private\n\nSitemap: {$base}/sitemap.xml\n",
            (string) $this->get('/robots.txt')->assertOk()->getContent(),
        );
    }

    /**
     * Parse the sitemap and validate it against the sitemaps.org 0.9 structure (urlset / url / loc,
     * lastmod, changefreq, priority), collecting libxml errors instead of raising warnings.
     */
    private function validatedSitemap(string $xml): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;

            $this->assertTrue($document->loadXML($xml), 'sitemap.xml is well-formed XML.');
            $this->assertSame('urlset', $document->documentElement?->localName);
            $this->assertSame(self::SITEMAP_NS, $document->documentElement?->namespaceURI);

            $valid = $document->schemaValidateSource(self::sitemapSchema());
            $errors = array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors());

            $this->assertTrue($valid, "sitemap.xml does not validate against the sitemap schema:\n".implode("\n", $errors));

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function sitemapSchema(): string
    {
        return <<<'XSD'
<?xml version="1.0" encoding="UTF-8"?>
<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema"
            targetNamespace="http://www.sitemaps.org/schemas/sitemap/0.9"
            xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
            elementFormDefault="qualified">
  <xsd:element name="urlset">
    <xsd:complexType>
      <xsd:sequence>
        <xsd:element name="url" type="tUrl" minOccurs="0" maxOccurs="unbounded"/>
      </xsd:sequence>
    </xsd:complexType>
  </xsd:element>
  <xsd:complexType name="tUrl">
    <xsd:sequence>
      <xsd:element name="loc" type="tLoc"/>
      <xsd:element name="lastmod" type="tLastmod" minOccurs="0"/>
      <xsd:element name="changefreq" type="tChangeFreq" minOccurs="0"/>
      <xsd:element name="priority" type="tPriority" minOccurs="0"/>
    </xsd:sequence>
  </xsd:complexType>
  <xsd:simpleType name="tLoc">
    <xsd:restriction base="xsd:anyURI">
      <xsd:minLength value="12"/>
      <xsd:maxLength value="2048"/>
    </xsd:restriction>
  </xsd:simpleType>
  <xsd:simpleType name="tLastmod">
    <xsd:union memberTypes="xsd:date xsd:dateTime"/>
  </xsd:simpleType>
  <xsd:simpleType name="tChangeFreq">
    <xsd:restriction base="xsd:string">
      <xsd:enumeration value="always"/>
      <xsd:enumeration value="hourly"/>
      <xsd:enumeration value="daily"/>
      <xsd:enumeration value="weekly"/>
      <xsd:enumeration value="monthly"/>
      <xsd:enumeration value="yearly"/>
      <xsd:enumeration value="never"/>
    </xsd:restriction>
  </xsd:simpleType>
  <xsd:simpleType name="tPriority">
    <xsd:restriction base="xsd:decimal">
      <xsd:minInclusive value="0.0"/>
      <xsd:maxInclusive value="1.0"/>
    </xsd:restriction>
  </xsd:simpleType>
</xsd:schema>
XSD;
    }
}
