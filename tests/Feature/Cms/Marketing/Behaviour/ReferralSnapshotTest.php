<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Models\Cms\ContactInquiry;
use App\Services\Cms\ContactInquiryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 tests 61-64 — the referral snapshot on `contact_inquiries` (§2.20, ND-3, D37).
 *
 *  61. `?ref=COL-1001` is stored verbatim; with no Phase 9 resolver bound `collaborator_id` and `referral_visit_id`
 *      stay null and nothing throws; an unknown code is stored the same way and attaches nobody;
 *  62. a `collaborator_id` (or `referral_visit_id`) posted in the body is discarded — the column holds null or the
 *      server-resolved value, never the submitted one;
 *  63. the three columns carry no foreign key while their target tables do not exist, each is indexed, and the index
 *      manifest lists `collaborator_id` and `referral_visit_id`;
 *  64. a snapshot grants nothing: no collaborator route reaches the table, a collaborator-panel user is refused the
 *      queue, and `visibleTo()` for a `view`-only user follows `assigned_to` alone.
 */
final class ReferralSnapshotTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        $this->setSetting('maintenance.contact_form_enabled', true);
        $this->setSetting('website.inquiry_auto_route', false);

        // Phase 4 state: no server-side referral resolver is bound.
        $this->app->offsetUnset(ContactInquiryService::REFERRAL_RESOLVER);
    }

    public function test_61_a_ref_code_is_stored_verbatim_and_attaches_nobody(): void
    {
        $this->post(route('site.contact.store', ['ref' => 'COL-1001']), $this->contactForm(['name' => 'Referred Visitor Sixty One']))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $referred = ContactInquiry::query()->where('name', 'Referred Visitor Sixty One')->firstOrFail();

        $this->assertSame('COL-1001', $referred->referral_code);
        $this->assertNull($referred->collaborator_id);
        $this->assertNull($referred->referral_visit_id);
        $this->assertFalse((bool) $referred->is_spam);

        $this->flushSession();

        $this->post(route('site.contact.store', ['ref' => 'NOPE-0000']), $this->contactForm(['name' => 'Unknown Code Visitor Sixty One']))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $unknown = ContactInquiry::query()->where('name', 'Unknown Code Visitor Sixty One')->firstOrFail();

        $this->assertSame('NOPE-0000', $unknown->referral_code, 'An unknown code is stored just the same.');
        $this->assertNull($unknown->collaborator_id);
        $this->assertNull($unknown->referral_visit_id);
    }

    public function test_62_a_collaborator_id_posted_by_the_browser_is_discarded(): void
    {
        $this->post(route('site.contact.store', ['ref' => 'COL-2002']), $this->contactForm([
            'name' => 'Forged Attribution Visitor',
            'collaborator_id' => 4242,
            'referral_visit_id' => 777,
        ]))->assertSessionHasNoErrors();

        $row = DB::table('contact_inquiries')->where('name', 'Forged Attribution Visitor')->first();

        $this->assertNotNull($row);
        $this->assertNull($row->collaborator_id, 'The posted collaborator_id is never stored.');
        $this->assertNull($row->referral_visit_id, 'The posted referral_visit_id is never stored.');
        $this->assertSame('COL-2002', $row->referral_code);

        // The service itself ignores the fields too, whatever payload reaches it.
        $direct = $this->submitInquiry(['name' => 'Direct Forged Visitor', 'collaborator_id' => 4242, 'referral_visit_id' => 777]);
        $this->assertNull($direct->collaborator_id);
        $this->assertNull($direct->referral_visit_id);

        if (Schema::hasTable('collaborators')) {
            return; // From Phase 8 the resolved id must point at a real collaborator; the body half above still holds.
        }

        // Once a resolver is bound, the stored id is the server's answer — still never the body's.
        $this->app->instance(ContactInquiryService::REFERRAL_RESOLVER, static fn (Request $request, ?string $code): array => [
            'collaborator_id' => $code === 'COL-3003' ? 9001 : null,
            'referral_visit_id' => null,
        ]);

        $resolved = $this->submitInquiry(
            ['name' => 'Resolved Visitor', 'collaborator_id' => 4242, 'referral_code' => 'COL-3003'],
        );

        $this->assertSame(9001, (int) $resolved->collaborator_id, 'The server-resolved value wins.');
        $this->assertSame('COL-3003', $resolved->referral_code);
    }

    public function test_63_the_snapshot_columns_have_no_foreign_key_and_are_indexed(): void
    {
        $targets = [
            'collaborator_id' => 'collaborators',
            'referral_visit_id' => 'collaborator_referral_visits',
            'referral_code' => null,
        ];

        foreach ($targets as $column => $table) {
            $this->assertTrue(Schema::hasColumn('contact_inquiries', $column), sprintf('contact_inquiries.%s exists.', $column));

            $foreignKeys = DB::select(
                'SELECT REFERENCED_TABLE_NAME AS target FROM information_schema.KEY_COLUMN_USAGE'
                .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                ['contact_inquiries', $column],
            );

            if ($table === null || ! Schema::hasTable($table)) {
                $this->assertSame([], $foreignKeys, sprintf('contact_inquiries.%s must carry no foreign key (its target does not exist yet).', $column));
            }

            $leading = DB::select(
                'SELECT INDEX_NAME AS name FROM information_schema.STATISTICS'
                .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1',
                ['contact_inquiries', $column],
            );

            $this->assertNotSame([], $leading, sprintf('contact_inquiries.%s must lead an index.', $column));
        }

        $this->assertSame('varchar(32)', strtolower((string) DB::selectOne(
            "SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_inquiries' AND COLUMN_NAME = 'referral_code'"
        )->type));

        /** @var array<string, list<list<string>>> $manifest */
        $manifest = require base_path('tests/Support/index-manifest.php');

        $this->assertArrayHasKey('contact_inquiries', $manifest, 'tests/Support/index-manifest.php lists the contact_inquiries indexes (phase-04 S.8).');
        $this->assertContains(['collaborator_id'], $manifest['contact_inquiries']);
        $this->assertContains(['referral_visit_id'], $manifest['contact_inquiries']);
    }

    public function test_64_a_referral_snapshot_grants_no_reach(): void
    {
        $collaboratorUser = $this->createUserWithRole('Collaborator');
        $reviewer = $this->createUserWithPermissions(['contact_inquiries.view']);
        $colleague = $this->createUserWithPermissions(['contact_inquiries.view']);

        $snapshotId = $this->snapshotCollaboratorId((int) $collaboratorUser->getKey());

        $inquiry = $this->submitInquiry(['name' => 'Snapshot Only Visitor']);

        DB::table('contact_inquiries')->where('id', $inquiry->getKey())->update([
            'collaborator_id' => $snapshotId,
            'referral_code' => 'COL-6464',
            'assigned_to' => $colleague->getKey(),
        ]);

        // No collaborator route, scope or screen reads the table.
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'collaborator.') && ! str_starts_with($route->uri(), 'collaborator')) {
                continue;
            }

            $this->assertStringNotContainsString('contact-inquir', $route->uri(), sprintf('The collaborator route [%s] must not reach contact inquiries.', $name));

            $controller = $route->getControllerClass();

            if ($controller !== null && class_exists($controller)) {
                $source = (string) file_get_contents((string) (new ReflectionClass($controller))->getFileName());
                $this->assertStringNotContainsString('ContactInquiry', $source, sprintf('The collaborator controller [%s] must not read contact_inquiries.', $controller));
            }
        }

        $this->actingAs($collaboratorUser)->get(route('admin.contact-inquiries.index'))->assertForbidden();
        $this->actingAs($collaboratorUser)->get(route('admin.contact-inquiries.show', $inquiry->getKey()))->assertForbidden();

        // visibleTo() follows assigned_to alone: the snapshot is never an access path.
        $this->assertFalse(ContactInquiry::query()->visibleTo($reviewer)->whereKey($inquiry->getKey())->exists());
        $this->assertFalse(ContactInquiry::query()->visibleTo($collaboratorUser)->whereKey($inquiry->getKey())->exists(), 'The collaborator named in the snapshot sees nothing.');
        $this->actingAs($reviewer)->get(route('admin.contact-inquiries.show', $inquiry->getKey()))->assertNotFound();

        DB::table('contact_inquiries')->where('id', $inquiry->getKey())->update(['assigned_to' => $reviewer->getKey()]);

        $this->assertTrue(ContactInquiry::query()->visibleTo($reviewer)->whereKey($inquiry->getKey())->exists(), 'Only assigned_to opens the row.');
        $this->assertFalse(ContactInquiry::query()->visibleTo($colleague)->whereKey($inquiry->getKey())->exists());
        $this->assertSame('COL-6464', ContactInquiry::query()->findOrFail($inquiry->getKey())->referral_code, 'The snapshot itself is untouched.');
    }

    /**
     * A collaborator id to write into the snapshot: the collaborator-panel user's id before Phase 8 creates the
     * `collaborators` table (no foreign key yet), a real collaborator row afterwards, or null when none exists.
     */
    private function snapshotCollaboratorId(int $fallback): ?int
    {
        if (! Schema::hasTable('collaborators')) {
            return $fallback;
        }

        $id = DB::table('collaborators')->value('id');

        return $id === null ? null : (int) $id;
    }
}
