<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\Cms\MediaAsset;
use App\Models\User;
use App\Support\SettingsRepository;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures;
use Tests\Feature\Cms\Marketing\Http\Concerns\MarketingHttpFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Every website-content list offers its rows' Edit and Delete controls, and they work.
 *
 * The taxonomy lists and the two moderation queues build their route names at run time
 * (`route($prefix.'.edit')`), so a static grep for `admin.blog-tags.destroy` finds nothing: these tests
 * read the RENDERED page instead — the control is there, it points at the right URL with the right
 * method, the request it sends does what it says (with a toast), and a user without the ability sees
 * no control and is refused with a 403.
 */
final class CmsRowActionsTest extends TestCase
{
    use CmsHttpFixtures;
    use InteractsWithRbac;
    use MarketingHttpFixtures;
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
        Storage::fake('public');
        Storage::fake('local');

        $this->superAdmin = $this->createSuperAdmin();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}> module, route resource, full SEO editor
     */
    public static function taxonomies(): array
    {
        return [
            'service categories' => ['service_categories', 'service-categories', true],
            'portfolio categories' => ['portfolio_categories', 'portfolio-categories', true],
            'blog categories' => ['blog_categories', 'blog-categories', true],
            'blog tags' => ['blog_tags', 'blog-tags', false],
            'technologies' => ['technologies', 'technologies', false],
        ];
    }

    /**
     * @dataProvider taxonomies
     */
    public function test_taxonomy_rows_carry_working_edit_and_delete_controls(string $module, string $resource, bool $seo): void
    {
        $term = $this->makeTerm($module);
        $html = $this->actingAs($this->superAdmin)->get(route('admin.'.$resource.'.index'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        // Edit: the three categories open their SEO editor page; tags and technologies open the row's
        // dialog, whose form PUTs to update (and GET …/edit lands back on the list with that dialog open).
        if ($seo) {
            $this->assertLinkTo($xpath, route('admin.'.$resource.'.edit', $term), 'Edit '.$term->name.' (details and SEO)');
            $this->actingAs($this->superAdmin)->get(route('admin.'.$resource.'.edit', $term))->assertOk();
        } else {
            $this->assertGreaterThan(0, $xpath->query(sprintf('//*[@aria-label="Edit %s"]', $term->name))->length, 'The row has an Edit button.');
            $this->assertSame('PUT', $this->formMethod($xpath, route('admin.'.$resource.'.update', $term), 'PUT'), 'The edit dialog PUTs to update.');
            $this->actingAs($this->superAdmin)->get(route('admin.'.$resource.'.edit', $term))
                ->assertRedirect(route('admin.'.$resource.'.index', ['edit' => $term->getKey()]));
        }

        // Delete: a confirm dialog wrapping a DELETE form on destroy.
        $this->assertSame('DELETE', $this->formMethod($xpath, route('admin.'.$resource.'.destroy', $term)));
        $this->assertGreaterThan(0, $xpath->query(sprintf('//*[@aria-label="Delete %s"]', $term->name))->length);

        $this->actingAs($this->superAdmin)->delete(route('admin.'.$resource.'.destroy', $term))
            ->assertRedirect(route('admin.'.$resource.'.index'))
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'success');
        $this->assertSoftDeleted($term);
    }

    /**
     * @dataProvider taxonomies
     */
    public function test_taxonomy_row_controls_are_hidden_and_refused_without_the_ability(string $module, string $resource, bool $seo): void
    {
        $term = $this->makeTerm($module);
        $viewer = $this->createUserWithPermissions([$module.'.view_any', $module.'.view']);

        $html = $this->actingAs($viewer)->get(route('admin.'.$resource.'.index'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $this->assertNull($this->formMethod($xpath, route('admin.'.$resource.'.destroy', $term)));
        $this->assertSame(0, $xpath->query(sprintf('//*[@aria-label="Edit %s" or @aria-label="Edit %s (details and SEO)"]', $term->name, $term->name))->length);

        $this->actingAs($viewer)->get(route('admin.'.$resource.'.edit', $term))->assertForbidden();
        $this->actingAs($viewer)->delete(route('admin.'.$resource.'.destroy', $term))->assertForbidden();
        $this->assertNotSoftDeleted($term);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function moderationQueues(): array
    {
        return [
            'testimonials' => ['testimonials', 'testimonials'],
            'student reviews' => ['student_reviews', 'student-reviews'],
        ];
    }

    /**
     * @dataProvider moderationQueues
     */
    public function test_moderation_rows_carry_working_edit_and_delete_controls(string $module, string $resource): void
    {
        $record = $this->moderationRecord($module);
        $html = $this->actingAs($this->superAdmin)->get(route('admin.'.$resource.'.index'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $this->assertLinkTo($xpath, route('admin.'.$resource.'.edit', $record));
        $this->actingAs($this->superAdmin)->get(route('admin.'.$resource.'.edit', $record))->assertOk();

        $this->assertSame('DELETE', $this->formMethod($xpath, route('admin.'.$resource.'.destroy', $record)));

        $this->actingAs($this->superAdmin)->delete(route('admin.'.$resource.'.destroy', $record))
            ->assertRedirect()
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'success');
        $this->assertSoftDeleted($record);
    }

    /**
     * @dataProvider moderationQueues
     */
    public function test_moderation_row_controls_are_hidden_and_refused_without_the_ability(string $module, string $resource): void
    {
        $record = $this->moderationRecord($module);
        $viewer = $this->createUserWithPermissions([$module.'.view_any', $module.'.view']);

        $xpath = $this->xpath($this->actingAs($viewer)->get(route('admin.'.$resource.'.index'))->assertOk()->getContent());

        $this->assertNull($this->formMethod($xpath, route('admin.'.$resource.'.destroy', $record)));
        $this->assertSame(0, $xpath->query(sprintf('//a[@href="%s"]', route('admin.'.$resource.'.edit', $record)))->length);

        $this->actingAs($viewer)->get(route('admin.'.$resource.'.edit', $record))->assertForbidden();
        $this->actingAs($viewer)->delete(route('admin.'.$resource.'.destroy', $record))->assertForbidden();
        $this->assertNotSoftDeleted($record);
    }

    /*
    |--------------------------------------------------------------------------
    | Media library — the grid cards had no row actions (added)
    |--------------------------------------------------------------------------
    */

    public function test_media_cards_carry_edit_and_a_confirmed_delete_while_unused(): void
    {
        $unused = $this->makeMediaAsset();
        $used = $this->makeMediaAsset();
        MediaAsset::query()->whereKey($used->getKey())->update(['usage_count' => 2]);

        $xpath = $this->xpath($this->actingAs($this->superAdmin)->get(route('admin.website.media.index'))->assertOk()->getContent());

        $this->assertLinkTo($xpath, route('admin.website.media.show', $unused), 'Edit '.($unused->title ?: $unused->original_name));
        $this->assertSame('DELETE', $this->formMethod($xpath, route('admin.website.media.destroy', $unused)));

        // A file something still uses offers no Delete (the detail screen explains why).
        $this->assertNull($this->formMethod($xpath, route('admin.website.media.destroy', $used)));

        $this->actingAs($this->superAdmin)->delete(route('admin.website.media.destroy', $unused))
            ->assertRedirect(route('admin.website.media.index'))
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'success');
        $this->assertSoftDeleted($unused);
    }

    public function test_media_card_controls_are_hidden_and_refused_without_the_ability(): void
    {
        $asset = $this->makeMediaAsset();
        $viewer = $this->createUserWithPermissions(['website_media.view_any', 'website_media.view']);

        $xpath = $this->xpath($this->actingAs($viewer)->get(route('admin.website.media.index'))->assertOk()->getContent());

        $label = 'Edit '.($asset->title ?: $asset->original_name);
        $this->assertSame(0, $xpath->query(sprintf('//*[@aria-label="%s"]', $label))->length);
        $this->assertNull($this->formMethod($xpath, route('admin.website.media.destroy', $asset)));

        $this->actingAs($viewer)->delete(route('admin.website.media.destroy', $asset))->assertForbidden();
        $this->assertNotSoftDeleted($asset);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function moderationRecord(string $module): Model
    {
        return $module === 'testimonials' ? $this->makeTestimonial() : $this->makeStudentReview();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function assertLinkTo(DOMXPath $xpath, string $url, ?string $label = null): void
    {
        $query = $label === null
            ? sprintf('//a[@href="%s"]', $url)
            : sprintf('//a[@href="%s" and @aria-label="%s"]', $url, $label);

        $this->assertGreaterThan(0, $xpath->query($query)->length, sprintf('No link to %s%s on the page.', $url, $label ? ' labelled "'.$label.'"' : ''));
    }

    /**
     * The effective method of the form whose action is $url and whose method (its `_method` override,
     * or its own) is $method — or null when the page has no such form. Update and destroy share a URL,
     * so the method is part of the question.
     */
    private function formMethod(DOMXPath $xpath, string $url, string $method = 'DELETE'): ?string
    {
        foreach ($xpath->query(sprintf('//form[@action="%s"]', $url)) as $form) {
            if (! $form instanceof DOMElement) {
                continue;
            }

            $override = $xpath->query('.//input[@name="_method"]', $form)->item(0);
            $effective = $override instanceof DOMElement ? strtoupper($override->getAttribute('value')) : strtoupper($form->getAttribute('method'));

            if ($effective === $method) {
                return $effective;
            }
        }

        return null;
    }
}
