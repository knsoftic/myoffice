<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Enums\Cms\SectionPlacement;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use App\Support\Cms\SectionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The CEO (`leadership`) and office (`office`) landing sections, and the home page's Organization JSON-LD
 * that states the same facts to search engines (`OrganizationSchema`).
 */
final class LeadershipAndOfficeTest extends TestCase
{
    use CmsBehaviourFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    #[Test]
    public function both_types_are_offered_on_the_home_page_with_a_photo_each_can_hold(): void
    {
        $allowed = collect(require base_path('resources/data/icons.php'))->flatten()->all();

        foreach (['leadership', 'office'] as $key) {
            $this->assertTrue(SectionRegistry::exists($key));
            $this->assertTrue(SectionRegistry::allowedIn($key, SectionPlacement::Home));
            $this->assertContains(SectionRegistry::icon($key), $allowed);
        }

        $this->assertNotNull(SectionRegistry::mediaRole('leadership', 'photo'));
        $this->assertTrue(SectionRegistry::hasRepeater('office', 'photo'));
        $this->assertTrue(SectionRegistry::hasRepeater('leadership', 'fact'));
    }

    #[Test]
    public function a_ceo_section_with_no_name_cannot_be_published(): void
    {
        $this->expectException(InvalidSectionContentException::class);

        $section = $this->sections()->place('leadership', SectionPlacement::Home);
        $section = $this->sections()->saveDraft($section, ['name' => '']);
        $this->publisher()->publish($section);
    }

    #[Test]
    public function the_ceo_and_the_office_show_on_the_home_page_and_in_its_json_ld(): void
    {
        $this->officeSettings();

        $portrait = $this->makeImageAsset(['alt_text' => 'Portrait of Test Person']);
        $lab = $this->makeImageAsset(['alt_text' => 'Students at work in the training lab']);

        $ceo = $this->sections()->place('leadership', SectionPlacement::Home);
        $ceo = $this->sections()->saveDraft($ceo, [
            'name' => 'Test Person',
            'designation' => 'Chief Executive Officer',
            'message' => "We started small.\n\nWe still answer every message ourselves.",
            'linkedin_url' => 'https://www.linkedin.com/in/test-person',
            'is_founder' => true,
        ], ['photo' => (int) $portrait->getKey()]);
        $this->sections()->upsertItem($ceo, 'fact', ['value' => '10+ years', 'label' => 'building software', 'is_enabled' => true]);
        $this->publisher()->publish($ceo->fresh());

        $office = $this->sections()->place('office', SectionPlacement::Home);
        $office = $this->sections()->saveDraft($office, ['heading' => 'Inside our office']);
        $this->sections()->upsertItem($office, 'photo', ['image' => (int) $lab->getKey(), 'caption' => 'The training lab', 'is_enabled' => true]);
        $this->publisher()->publish($office->fresh());

        $response = $this->get(route('site.home'))->assertOk();

        $response->assertSee('Test Person')
            ->assertSee('We still answer every message ourselves.')
            ->assertSee('10+ years')
            ->assertSee('Inside our office')
            ->assertSee('The training lab')
            ->assertSee('alt="Students at work in the training lab"', false)
            ->assertSee('alt="Portrait of Test Person"', false)
            ->assertSee('12 Test Road, Testabad, Pakistan')
            ->assertSee('Mon–Fri')
            ->assertSee('https://www.google.com/maps/search/?api=1&amp;query=', false);

        $graph = collect($this->jsonLd($response->getContent())['@graph']);

        $organization = $graph->first(static fn (array $node): bool => in_array('Organization', (array) $node['@type'], true));
        $person = $graph->firstWhere('@type', 'Person');

        $this->assertNotNull($organization);
        $this->assertSame(['Organization', 'LocalBusiness'], $organization['@type']);
        $this->assertSame('Example Softic', $organization['name']);
        $this->assertSame('12 Test Road', $organization['address']['streetAddress']);
        $this->assertSame('Testabad', $organization['address']['addressLocality']);
        $this->assertSame('office@example.test', $organization['email']);
        $this->assertSame('2019', $organization['foundingDate']);
        $this->assertContains('https://www.linkedin.com/company/example-softic', $organization['sameAs']);
        $this->assertStringContainsString((string) $lab->filename, $organization['image'][0]);

        // Mon–Fri share one row, Saturday has its own, Sunday is closed and absent.
        $this->assertCount(2, $organization['openingHoursSpecification']);
        $this->assertSame('09:00', $organization['openingHoursSpecification'][0]['opens']);
        $this->assertCount(5, $organization['openingHoursSpecification'][0]['dayOfWeek']);

        $this->assertNotNull($person);
        $this->assertSame('Test Person', $person['name']);
        $this->assertSame('Chief Executive Officer', $person['jobTitle']);
        $this->assertSame(['https://www.linkedin.com/in/test-person'], $person['sameAs']);
        $this->assertStringContainsString((string) $portrait->filename, $person['image']);
        $this->assertSame($organization['@id'], $person['worksFor']['@id']);
        $this->assertSame($person['@id'], $organization['founder']['@id']);

        $this->assertNotNull($graph->firstWhere('@type', 'WebSite'));
    }

    #[Test]
    public function with_no_ceo_section_the_json_ld_names_no_person_and_no_founder(): void
    {
        $this->setSetting('company.name', 'Example Softic');
        $this->setSetting('contact.address', '');
        $this->setSetting('contact.city', '');
        $this->bumpPublicCache();

        $graph = collect($this->jsonLd($this->get(route('site.home'))->assertOk()->getContent())['@graph']);
        $organization = $graph->first();

        // No address: an Organization, not a LocalBusiness, and no opening hours either.
        $this->assertSame('Organization', $organization['@type']);
        $this->assertArrayNotHasKey('openingHoursSpecification', $organization);
        $this->assertArrayNotHasKey('founder', $organization);
        $this->assertNull($graph->firstWhere('@type', 'Person'));
    }

    #[Test]
    public function the_office_section_renders_nothing_without_photos_or_details(): void
    {
        $html = view('site.sections.office', [
            'section' => ['id' => 1, 'anchor' => null],
            'content' => ['heading' => 'Our office', 'show_details' => false],
            'items' => ['photo' => []],
            'media' => [],
        ])->render();

        $this->assertSame('', trim($html));
    }

    private function officeSettings(): void
    {
        $this->setSetting('company.name', 'Example Softic');
        $this->setSetting('company.founded_year', '2019');
        $this->setSetting('contact.address', '12 Test Road');
        $this->setSetting('contact.city', 'Testabad');
        $this->setSetting('contact.country', 'Pakistan');
        $this->setSetting('contact.email', 'office@example.test');
        $this->setSetting('contact.latitude', null);
        $this->setSetting('contact.longitude', null);
        $this->setSetting('social.linkedin', 'https://www.linkedin.com/company/example-softic');
        $this->setSetting('contact.business_hours', [
            'monday' => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
            'tuesday' => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
            'wednesday' => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
            'thursday' => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
            'friday' => ['open' => '09:00', 'close' => '18:00', 'closed' => false],
            'saturday' => ['open' => '10:00', 'close' => '14:00', 'closed' => false],
            'sunday' => ['open' => null, 'close' => null, 'closed' => true],
        ]);
        $this->bumpPublicCache();
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonLd(string $html): array
    {
        $this->assertSame(1, preg_match('~<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $match), 'The home page carries no JSON-LD.');

        $data = json_decode($match[1], true);
        $this->assertIsArray($data);

        return $data;
    }
}
