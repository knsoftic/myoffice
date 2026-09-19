<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Enums\Cms\ContentStatus;
use App\Models\Cms\Service;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 tests 8-9 — the service catalogue's money and public visibility.
 *
 *   8. `starting_price` travels form → `decimal(15,2)` column → public page with no precision loss, rendered by
 *      `money()`; `price_visible = false` hides it publicly while the column keeps the value (§6.4 invariant 1);
 *   9. a draft is 404 on its page and absent from the list; publishing makes both appear (§9.2 `scopePublic()`).
 */
final class ServiceCatalogueTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    private const PRICE = '1234567.89';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    public function test_08_starting_price_round_trips_without_precision_loss_and_hides_when_not_visible(): void
    {
        $column = DB::selectOne(
            "SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'starting_price'"
        );
        $this->assertSame('decimal(15,2)', strtolower((string) $column->type), 'starting_price is a decimal(15,2) money column.');

        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->post(route('admin.services.store'), [
                'name' => 'Enterprise ERP Implementation',
                'short_description' => 'End-to-end ERP delivery.',
                'starting_price' => self::PRICE,
                'price_visible' => '1',
                'status' => ContentStatus::Published->value,
            ])
            ->assertSessionHasNoErrors();

        /** @var Service $service */
        $service = Service::query()->where('name', 'Enterprise ERP Implementation')->firstOrFail();

        $this->assertSame(self::PRICE, (string) DB::table('services')->where('id', $service->getKey())->value('starting_price'), 'The stored value is the exact decimal string.');
        $this->assertSame(0, Money::compare(self::PRICE, (string) $service->starting_price), 'The cast value compares equal through Money.');
        $this->assertSame(ContentStatus::Published, $service->status);

        $this->becomeGuest();
        $this->bumpPublicCache('test 08 render');

        $formatted = money(self::PRICE);
        $html = (string) $this->get(route('site.services.show', ['service' => $service->slug]))->assertOk()->getContent();

        $this->assertStringContainsString($this->squashedText($formatted), $this->squashedText($html), 'The price is rendered through money() with the configured currency.');

        // Hide the price: the value stays, the public page stops printing it.
        $this->actingAs($admin);
        $this->serviceContent()->update($service->fresh(), ['price_visible' => false], null);

        $this->assertSame(self::PRICE, (string) DB::table('services')->where('id', $service->getKey())->value('starting_price'), 'Hiding the price never loses it.');
        $this->assertFalse((bool) DB::table('services')->where('id', $service->getKey())->value('price_visible'));

        $this->becomeGuest();
        $this->bumpPublicCache('test 08 hidden price');

        $show = (string) $this->get(route('site.services.show', ['service' => $service->slug]))->assertOk()->getContent();
        $index = (string) $this->get(route('site.services.index'))->assertOk()->getContent();

        foreach (['show' => $show, 'index' => $index] as $page => $body) {
            $this->assertStringContainsString('Enterprise ERP Implementation', $body, sprintf('The %s page still lists the service.', $page));
            $this->assertStringNotContainsString($this->squashedText($formatted), $this->squashedText($body), sprintf('The %s page omits a hidden price.', $page));
            $this->assertStringNotContainsString('1234567', $body);
            $this->assertStringNotContainsString('1,234,567', $body);
        }
    }

    public function test_09_a_draft_service_is_not_public_until_it_is_published(): void
    {
        $this->actAsSuperAdmin();

        $service = $this->makeService('Draft Cloud Migration Service', ['short_description' => 'Moving workloads to the cloud.']);
        $this->assertSame(ContentStatus::Draft, $service->status);

        $this->becomeGuest();
        $this->bumpPublicCache('test 09 draft');

        $this->get(route('site.services.show', ['service' => $service->slug]))->assertNotFound();
        $this->get(route('site.services.index'))->assertOk()->assertDontSee('Draft Cloud Migration Service');

        $this->actAsSuperAdmin();
        $this->serviceContent()->changeStatus($service->fresh(), ContentStatus::Published);

        $this->becomeGuest();

        $this->get(route('site.services.show', ['service' => $service->slug]))->assertOk()->assertSee('Draft Cloud Migration Service');
        $this->get(route('site.services.index'))->assertOk()->assertSee('Draft Cloud Migration Service');
        $this->assertTrue(Service::query()->public()->whereKey($service->getKey())->exists());
    }
}
