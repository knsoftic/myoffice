<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Http;

use App\Models\Cms\ContactInquiry;
use App\Models\Cms\JobApplication;
use App\Services\Cms\JobApplicationService;
use App\Support\SettingsRepository;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures;
use Tests\Feature\Cms\Marketing\Http\Concerns\MarketingHttpFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Data isolation of the two PII queues (phase-04 §9.1.2, §9.1.3, §9.3; CLAUDE.md rule 10; F-12.4):
 *
 *   · §11 test 55 — `ContactInquiry::visibleTo()`: a user holding only `contact_inquiries.view` lists exactly
 *     the rows assigned to it, gets a 404 on any other row, never sees a spam row outside the Spam tab, and
 *     never receives the technical / PII block without `contact_inquiries.view_logs`;
 *   · §11 test 56 — `JobApplication::visibleTo()`: the same on `assigned_to`, widened to the openings the
 *     user created, and `internal_notes` absent from the HTML for a reviewer without `edit`;
 *   · §11 test 64 — a collaborator snapshot on an inquiry grants nothing: not to the collaborator, not to a
 *     `view`-only reviewer, and no collaborator route reaches the table.
 */
final class MarketingReviewerIsolationTest extends TestCase
{
    use CmsHttpFixtures;
    use InteractsWithRbac;
    use MarketingHttpFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_contract_55_a_view_only_reviewer_sees_exactly_its_assigned_inquiries_without_the_technical_block(): void
    {
        $reviewer = $this->createUserWithPermissions(['contact_inquiries.view']);
        $colleague = $this->createUserWithPermissions(['contact_inquiries.view']);

        $technical = [
            'ip_address' => '198.51.100.77',
            'user_agent' => 'IsolationProbe/9.9 (reviewer test)',
            'utm_source' => 'isolation-utm-source',
            'utm_campaign' => 'isolation-utm-campaign',
            'referrer_url' => 'https://referrer.example/isolation-probe',
        ];

        $mine = $this->makeContactInquiry(array_merge(['assigned_to' => (int) $reviewer->getKey(), 'email' => 'mine-55@example.com'], $technical));
        $theirs = $this->makeContactInquiry(['assigned_to' => (int) $colleague->getKey(), 'email' => 'theirs-55@example.com']);
        $unassigned = $this->makeContactInquiry(['email' => 'unassigned-55@example.com']);
        $mySpam = $this->makeContactInquiry(['assigned_to' => (int) $reviewer->getKey(), 'email' => 'my-spam-55@example.com', 'is_spam' => true, 'spam_reason' => 'honeypot']);

        // The scope itself (§9.1.2).
        $this->assertSame(
            [(int) $mine->getKey(), (int) $mySpam->getKey()],
            ContactInquiry::query()->visibleTo($reviewer)->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );

        // The index lists exactly the reviewer's non-spam row.
        $this->actingAs($reviewer)
            ->get(route('admin.contact-inquiries.index'))
            ->assertOk()
            ->assertSee('mine-55@example.com', false)
            ->assertDontSee('theirs-55@example.com', false)
            ->assertDontSee('unassigned-55@example.com', false)
            ->assertDontSee('my-spam-55@example.com', false);

        foreach (ContactInquiry::TABS as $tab) {
            if (in_array($tab, ['spam', 'trashed'], true)) {
                continue;
            }

            $this->actingAs($reviewer)
                ->get(route('admin.contact-inquiries.index', ['tab' => $tab]))
                ->assertOk()
                ->assertDontSee('my-spam-55@example.com', false)
                ->assertDontSee('theirs-55@example.com', false);
        }

        // Spam appears in the Spam tab only.
        $this->actingAs($reviewer)
            ->get(route('admin.contact-inquiries.index', ['tab' => 'spam']))
            ->assertOk()
            ->assertSee('my-spam-55@example.com', false)
            ->assertDontSee('mine-55@example.com', false);

        // Another row's detail is a 404 (never a 403: ids cannot be probed).
        $this->actingAs($reviewer)->get(route('admin.contact-inquiries.show', $theirs->getKey()))->assertNotFound();
        $this->actingAs($reviewer)->get(route('admin.contact-inquiries.show', $unassigned->getKey()))->assertNotFound();

        // Its own row renders — without a single technical value in the response body (F-12.4).
        $page = $this->actingAs($reviewer)->get(route('admin.contact-inquiries.show', $mine->getKey()))->assertOk();
        $page->assertSee('mine-55@example.com', false);

        foreach ($technical as $column => $value) {
            $page->assertDontSee($value, false);
        }

        // The export follows the same scope and the same column rule.
        $exporter = $this->createUserWithPermissions(['contact_inquiries.view', 'contact_inquiries.export']);
        $this->makeContactInquiry(array_merge(['assigned_to' => (int) $exporter->getKey(), 'email' => 'exported-55@example.com'], $technical));

        $csv = $this->actingAs($exporter)->get(route('admin.contact-inquiries.export'))->assertOk()->streamedContent();

        $this->assertStringContainsString('exported-55@example.com', $csv);
        $this->assertStringNotContainsString('mine-55@example.com', $csv);
        $this->assertStringNotContainsString('198.51.100.77', $csv);
        $this->assertStringNotContainsString('IP address', $csv);

        // view_logs is the one key to the technical block.
        $auditor = $this->createUserWithPermissions(['contact_inquiries.view', 'contact_inquiries.view_logs']);
        $mine->forceFill(['assigned_to' => $auditor->getKey()])->save();

        $this->actingAs($auditor)
            ->get(route('admin.contact-inquiries.show', $mine->getKey()))
            ->assertOk()
            ->assertSee('198.51.100.77', false)
            ->assertSee('isolation-utm-source', false);

        // A queue manager (view_any) sees every non-spam row, and spam only in its tab.
        $manager = $this->createUserWithPermissions(['contact_inquiries.view_any', 'contact_inquiries.view']);

        $this->actingAs($manager)
            ->get(route('admin.contact-inquiries.index'))
            ->assertOk()
            ->assertSee('mine-55@example.com', false)
            ->assertSee('theirs-55@example.com', false)
            ->assertSee('unassigned-55@example.com', false)
            ->assertDontSee('my-spam-55@example.com', false);

        $this->actingAs($manager)->get(route('admin.contact-inquiries.show', $theirs->getKey()))->assertOk()->assertDontSee('198.51.100.77', false);
    }

    public function test_contract_56_a_view_only_reviewer_sees_assigned_and_own_opening_applications_without_internal_notes(): void
    {
        $reviewer = $this->createUserWithPermissions(['job_applications.view']);
        $colleague = $this->createUserWithPermissions(['job_applications.view']);

        $foreignOpening = $this->makeJobOpening('open', $colleague);
        $ownOpening = $this->makeJobOpening('open', $reviewer);
        $otherOpening = $this->makeJobOpening('open');

        $assigned = $this->makeJobApplication($foreignOpening, $reviewer, ['email' => 'assigned-56@example.com']);
        $ownedOpening = $this->makeJobApplication($ownOpening, null, ['email' => 'owned-opening-56@example.com']);
        $elsewhere = $this->makeJobApplication($otherOpening, $colleague, ['email' => 'elsewhere-56@example.com']);
        $colleaguesOpening = $this->makeJobApplication($foreignOpening, null, ['email' => 'colleague-opening-56@example.com']);

        $secret = 'Confidential screening note 56 — salary expectations discussed';
        $this->withoutActor(fn () => app(JobApplicationService::class)->saveNotes($assigned, $secret, 4));

        // The scope itself (§9.1.3).
        $this->assertSame(
            [(int) $assigned->getKey(), (int) $ownedOpening->getKey()],
            JobApplication::query()->visibleTo($reviewer)->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );

        $this->actingAs($reviewer)
            ->get(route('admin.job-applications.index'))
            ->assertOk()
            ->assertSee('assigned-56@example.com', false)
            ->assertSee('owned-opening-56@example.com', false)
            ->assertDontSee('elsewhere-56@example.com', false)
            ->assertDontSee('colleague-opening-56@example.com', false)
            ->assertDontSee($secret, false);

        $this->actingAs($reviewer)->get(route('admin.job-applications.show', $elsewhere))->assertNotFound();
        $this->actingAs($reviewer)->get(route('admin.job-applications.show', $colleaguesOpening))->assertNotFound();
        $this->actingAs($reviewer)->get(route('admin.job-applications.show', $ownedOpening))->assertOk()->assertSee('owned-opening-56@example.com', false);

        // Its assigned application renders — the notes are not in the HTML without `edit`.
        $this->actingAs($reviewer)
            ->get(route('admin.job-applications.show', $assigned))
            ->assertOk()
            ->assertSee('assigned-56@example.com', false)
            ->assertDontSee($secret, false)
            ->assertDontSee('name="internal_notes"', false);

        // The colleague who owns nothing of the reviewer's never sees the reviewer's assigned row either.
        $this->actingAs($colleague)->get(route('admin.job-applications.show', $ownedOpening))->assertNotFound();

        // With `edit`, the notes box and its content appear.
        $this->grantPermissions($reviewer, 'job_applications.edit');

        $this->actingAs($reviewer)
            ->get(route('admin.job-applications.show', $assigned))
            ->assertOk()
            ->assertSee($secret, false)
            ->assertSee('name="internal_notes"', false);

        // HR (view_any) reaches every application.
        $hr = $this->createUserWithRole('HR');

        foreach ([$assigned, $ownedOpening, $elsewhere, $colleaguesOpening] as $application) {
            $this->actingAs($hr)->get(route('admin.job-applications.show', $application))->assertOk();
        }
    }

    public function test_contract_64_a_collaborator_snapshot_on_an_inquiry_grants_nothing(): void
    {
        $collaborator = $this->createUserWithRole('Collaborator');
        $reviewer = $this->createUserWithPermissions(['contact_inquiries.view']);

        $inquiry = $this->makeContactInquiry([
            'email' => 'referred-64@example.com',
            'collaborator_id' => (int) $collaborator->getKey(),
            'referral_code' => 'COL-1001',
        ]);

        // The scope never reads the snapshot (D37).
        $this->assertFalse(ContactInquiry::query()->visibleTo($reviewer)->whereKey($inquiry->getKey())->exists());
        $this->assertFalse(ContactInquiry::query()->visibleTo($collaborator)->whereKey($inquiry->getKey())->exists());

        $this->actingAs($reviewer)->get(route('admin.contact-inquiries.show', $inquiry->getKey()))->assertNotFound();
        $this->actingAs($reviewer)->get(route('admin.contact-inquiries.index'))->assertOk()->assertDontSee('referred-64@example.com', false);

        // The collaborator it names cannot reach it through any admin route.
        $this->actingAs($collaborator)->get(route('admin.contact-inquiries.index'))->assertForbidden();
        $this->actingAs($collaborator)->get(route('admin.contact-inquiries.show', $inquiry->getKey()))->assertForbidden();
        $this->actingAs($collaborator)->getJson(route('admin.contact-inquiries.export'))->assertForbidden();

        // No collaborator route, and nothing under the collaborator controllers, reads the table (§9.3).
        $violations = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'collaborator.')) {
                continue;
            }

            $controller = $route->getControllerClass();

            if ($controller === null || ! class_exists($controller)) {
                continue;
            }

            $file = (new ReflectionClass($controller))->getFileName();
            $source = is_string($file) ? (string) file_get_contents($file) : '';

            if (str_contains($source, 'ContactInquiry') || str_contains($source, 'contact_inquiries')) {
                $violations[] = $route->getName().' ('.$controller.')';
            }
        }

        $directory = app_path('Http/Controllers/Collaborator');

        if (is_dir($directory)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                $source = (string) file_get_contents($file->getPathname());

                if (str_contains($source, 'ContactInquiry') || str_contains($source, 'contact_inquiries')) {
                    $violations[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $violations, "A collaborator route or controller reads contact inquiries (§9.3):\n".implode("\n", $violations));

        // Only an assignment — never the snapshot — opens the row to the reviewer.
        $inquiry->forceFill(['assigned_to' => $reviewer->getKey()])->save();

        $this->assertTrue(ContactInquiry::query()->visibleTo($reviewer)->whereKey($inquiry->getKey())->exists());
        $this->actingAs($reviewer)->get(route('admin.contact-inquiries.show', $inquiry->getKey()))->assertOk()->assertSee('referred-64@example.com', false);
    }
}
