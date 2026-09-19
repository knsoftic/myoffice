<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Models\Activity;
use App\Models\Cms\Service;
use App\Models\Cms\ServiceCategory;
use App\Models\Cms\Technology;
use App\Services\Cms\ContentOrderService;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\TaxonomyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 test 60 — taxonomies and ordering (§6.2).
 *
 * Deleting a category that still has 5 services is refused; with `reassign_to` all 5 move to the target in one
 * transaction and only then is the category soft-deleted. The target must be a different, existing term. Deleting
 * a technology only detaches pivot rows. Reordering writes `sort_order` 1..n and exactly one `reordered` activity
 * entry (never one per row); a foreign id refuses the whole reorder.
 */
final class TaxonomyTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        $this->actAsSuperAdmin();
    }

    public function test_60_deleting_a_category_with_services_needs_a_reassign_target(): void
    {
        /** @var ServiceCategory $source */
        $source = $this->taxonomy()->store(ServiceCategory::class, ['name' => 'Mobile Apps']);
        /** @var ServiceCategory $target */
        $target = $this->taxonomy()->store(ServiceCategory::class, ['name' => 'Software Development']);

        $services = [];

        for ($i = 1; $i <= 5; $i++) {
            $services[] = $this->makeService('Mobile Service '.$i, ['service_category_id' => $source->getKey()]);
        }

        try {
            $this->taxonomy()->delete($source->fresh());
            $this->fail('A category with services cannot be deleted without a reassign target.');
        } catch (ContentRuleException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('reassign_to', $exception->errors());
        }

        $this->assertNotSoftDeleted('service_categories', ['id' => $source->getKey()]);
        $this->assertSame(5, Service::query()->where('service_category_id', $source->getKey())->count(), 'A refused delete moves nothing.');

        // The target must be a different, existing term.
        foreach ([(int) $source->getKey(), 999_999_999] as $invalid) {
            try {
                $this->taxonomy()->delete($source->fresh(), $invalid);
                $this->fail('An invalid reassign target must be refused.');
            } catch (ContentRuleException $exception) {
                $this->assertArrayHasKey('reassign_to', $exception->errors());
            }
        }

        $this->assertNotSoftDeleted('service_categories', ['id' => $source->getKey()]);

        $this->taxonomy()->delete($source->fresh(), (int) $target->getKey());

        $this->assertSoftDeleted('service_categories', ['id' => $source->getKey()]);
        $this->assertSame(0, Service::withTrashed()->where('service_category_id', $source->getKey())->count());
        $this->assertSame(5, Service::query()->where('service_category_id', $target->getKey())->count(), 'All five services moved.');

        foreach ($services as $service) {
            $this->assertNotSoftDeleted('services', ['id' => $service->getKey()]);
        }

        // A category with no children deletes without a target.
        $empty = $this->taxonomy()->store(ServiceCategory::class, ['name' => 'Empty Category']);
        $this->taxonomy()->delete($empty->fresh());
        $this->assertSoftDeleted('service_categories', ['id' => $empty->getKey()]);
    }

    public function test_60_deleting_a_technology_only_detaches_it(): void
    {
        /** @var Technology $laravel */
        $laravel = $this->taxonomy()->store(Technology::class, ['name' => 'Laravel', 'color' => '#FF2D20']);
        $service = $this->serviceContent()->store(['name' => 'Laravel Development'], null, [$laravel->getKey()]);

        $this->assertSame(1, DB::table('service_technology')->where('technology_id', $laravel->getKey())->count());

        $this->taxonomy()->delete($laravel->fresh());

        $this->assertSoftDeleted('technologies', ['id' => $laravel->getKey()]);
        $this->assertSame(0, DB::table('service_technology')->where('technology_id', $laravel->getKey())->count());
        $this->assertNotSoftDeleted('services', ['id' => $service->getKey()]);
    }

    public function test_60_reordering_writes_one_to_n_and_one_activity_entry(): void
    {
        $categories = [];

        foreach (['Alpha Category', 'Beta Category', 'Gamma Category', 'Delta Category'] as $name) {
            $categories[] = $this->taxonomy()->store(ServiceCategory::class, ['name' => $name]);
        }

        $order = [
            (int) $categories[2]->getKey(),
            (int) $categories[0]->getKey(),
            (int) $categories[3]->getKey(),
            (int) $categories[1]->getKey(),
        ];

        $marker = $this->lastActivityId();

        $this->order()->reorder(ServiceCategory::class, $order);

        foreach ($order as $position => $id) {
            $this->assertSame($position + 1, (int) DB::table('service_categories')->where('id', $id)->value('sort_order'));
        }

        $entries = $this->activitiesSince($marker, 'service_categories', 'reordered');
        $this->assertCount(1, $entries, 'One reordered entry for the whole act, not one per row.');
        $this->assertSame($order, array_map('intval', $this->propertiesOf($entries->first())['ids'] ?? []));
        $this->assertSame(0, Activity::query()->where('id', '>', $marker)->where('module', 'service_categories')->whereIn('event', ['updated'])->count(), 'No per-row update entries.');

        // A foreign id refuses the whole reorder and changes nothing.
        $service = $this->makeService('Not A Category');
        $before = DB::table('service_categories')->whereIn('id', $order)->orderBy('id')->pluck('sort_order', 'id')->all();

        try {
            $this->order()->reorder(ServiceCategory::class, [...array_reverse($order), 999_999_999]);
            $this->fail('A reorder naming an id that is not a service category must be refused.');
        } catch (ContentRuleException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $this->assertSame($before, DB::table('service_categories')->whereIn('id', $order)->orderBy('id')->pluck('sort_order', 'id')->all());
        $this->assertNotNull($service->fresh());
    }

    private function taxonomy(): TaxonomyService
    {
        return app(TaxonomyService::class);
    }

    private function order(): ContentOrderService
    {
        return app(ContentOrderService::class);
    }
}
