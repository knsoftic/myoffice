<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Models\Cms\ContactInquiry;
use App\Models\Cms\JobApplication;
use App\Models\User;
use App\Services\Cms\SpamGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 tests 55-56 — row scoping of the two PII queues (§9.1.2, §9.1.3, F-12.4).
 *
 *  55. `ContactInquiry::visibleTo()`: a user with only `contact_inquiries.view` lists exactly the rows assigned to it,
 *      gets 404 on another row's detail, never sees spam outside the Spam tab, and — without `view_logs` — never
 *      receives the technical block (IP, user agent, UTM, referrer, fill time, spam reason) in a query or a page;
 *  56. `JobApplication::visibleTo()`: a reviewer with only `job_applications.view` sees what is assigned to it plus the
 *      applications of openings it created, 404s on anything else, and never receives `internal_notes` without
 *      `job_applications.edit`.
 *
 * The owner is always decided on the server from `assigned_to` / `job_openings.created_by`; no hidden form field
 * is involved.
 */
final class ReviewerIsolationTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    private const REVIEWER_IP = '203.0.113.55';

    private const REVIEWER_AGENT = 'IsolationProbeAgent/55 (tests)';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        $this->setSetting('website.inquiry_auto_route', false);
    }

    public function test_55_a_view_only_reviewer_reaches_exactly_its_assigned_inquiries(): void
    {
        $reviewer = $this->createUserWithPermissions(['contact_inquiries.view']);
        $colleague = $this->createUserWithPermissions(['contact_inquiries.view']);

        $mine = $this->inquiry('Mine Fifty Five Visitor', $reviewer, ['utm_source' => 'utm-secret-55']);
        $theirs = $this->inquiry('Theirs Fifty Five Visitor', $colleague);
        $unassigned = $this->inquiry('Unassigned Fifty Five Visitor', null);
        $mySpam = $this->inquiry('Spam Fifty Five Visitor', $reviewer, [SpamGuard::HONEYPOT_FIELD => 'bot-filled']);

        $this->assertTrue((bool) $mySpam->is_spam);

        // The scope.
        $this->assertSame([(int) $mine->getKey()], $this->ids(ContactInquiry::query()->visibleTo($reviewer)->tab('all')));
        $this->assertSame([(int) $mySpam->getKey()], $this->ids(ContactInquiry::query()->visibleTo($reviewer)->tab('spam')));
        $this->assertEqualsCanonicalizing([(int) $mine->getKey(), (int) $mySpam->getKey()], $this->ids(ContactInquiry::query()->visibleTo($reviewer)));

        // The technical block is absent from the query itself.
        $row = ContactInquiry::query()->visibleTo($reviewer)->selectVisibleColumns($reviewer)->findOrFail($mine->getKey());

        foreach (ContactInquiry::TECHNICAL_COLUMNS as $column) {
            $this->assertArrayNotHasKey($column, $row->getAttributes(), sprintf('%s is not selected without view_logs.', $column));
        }

        // The screens.
        $this->actingAs($reviewer)
            ->get(route('admin.contact-inquiries.index'))
            ->assertOk()
            ->assertSee('Mine Fifty Five Visitor')
            ->assertDontSee('Theirs Fifty Five Visitor')
            ->assertDontSee('Unassigned Fifty Five Visitor')
            ->assertDontSee('Spam Fifty Five Visitor');

        $this->actingAs($reviewer)
            ->get(route('admin.contact-inquiries.index', ['tab' => 'spam']))
            ->assertOk()
            ->assertSee('Spam Fifty Five Visitor')
            ->assertDontSee('Mine Fifty Five Visitor');

        $this->actingAs($reviewer)->get(route('admin.contact-inquiries.show', $theirs->getKey()))->assertNotFound();
        $this->actingAs($reviewer)->get(route('admin.contact-inquiries.show', $unassigned->getKey()))->assertNotFound();

        $detail = $this->actingAs($reviewer)
            ->get(route('admin.contact-inquiries.show', $mine->getKey()))
            ->assertOk()
            ->assertSee('Mine Fifty Five Visitor');

        foreach ([self::REVIEWER_IP, self::REVIEWER_AGENT, 'utm-secret-55'] as $secret) {
            $detail->assertDontSee($secret);
        }

        // A holder of view_logs does see the block (the gate, not a missing value, hid it).
        $auditor = $this->createUserWithPermissions(['contact_inquiries.view_any', 'contact_inquiries.view', 'contact_inquiries.view_logs']);
        $this->actingAs($auditor)
            ->get(route('admin.contact-inquiries.show', $mine->getKey()))
            ->assertOk()
            ->assertSee(self::REVIEWER_IP)
            ->assertSee('utm-secret-55');

        // Reassigning moves the row between reviewers.
        DB::table('contact_inquiries')->where('id', $theirs->getKey())->update(['assigned_to' => $reviewer->getKey()]);
        $this->assertEqualsCanonicalizing([(int) $mine->getKey(), (int) $theirs->getKey()], $this->ids(ContactInquiry::query()->visibleTo($reviewer)->tab('all')));
        $this->assertSame([(int) $unassigned->getKey()], array_values(array_diff(
            $this->ids(ContactInquiry::query()->visibleTo($auditor)->tab('all')->whereKey([$mine->getKey(), $theirs->getKey(), $unassigned->getKey()])),
            [(int) $mine->getKey(), (int) $theirs->getKey()],
        )), 'view_any sees every non-spam row.');
    }

    public function test_56_a_view_only_reviewer_reaches_assigned_and_owned_opening_applications(): void
    {
        $manager = $this->createUserWithPermissions(['job_applications.view']);

        $this->actAsSuperAdmin();
        $otherOpening = $this->makeOpening('Opening Owned By Someone Else');

        $this->actingAs($manager);
        $ownOpening = $this->makeOpening('Opening Owned By The Manager');

        $this->assertSame((int) $manager->getKey(), (int) DB::table('job_openings')->where('id', $ownOpening->getKey())->value('created_by'));

        $owned = $this->makeApplication($ownOpening, 'Owned Opening Applicant');
        $assigned = $this->makeApplication($otherOpening, 'Assigned Applicant', [
            'assigned_to' => $manager->getKey(),
            'internal_notes' => 'SECRET-NOTE-56 do not show reviewers',
            'rating' => 4,
        ]);
        $hidden = $this->makeApplication($otherOpening, 'Hidden Applicant');

        // The scope.
        $this->assertEqualsCanonicalizing(
            [(int) $owned->getKey(), (int) $assigned->getKey()],
            $this->ids(JobApplication::query()->visibleTo($manager)),
        );

        // The screens.
        $this->actingAs($manager)
            ->get(route('admin.job-applications.index'))
            ->assertOk()
            ->assertSee('Owned Opening Applicant')
            ->assertSee('Assigned Applicant')
            ->assertDontSee('Hidden Applicant');

        $this->actingAs($manager)->get(route('admin.job-applications.show', $hidden->getKey()))->assertNotFound();

        $this->actingAs($manager)
            ->get(route('admin.job-applications.show', $assigned->getKey()))
            ->assertOk()
            ->assertSee('Assigned Applicant')
            ->assertDontSee('SECRET-NOTE-56');

        $this->actingAs($manager)
            ->get(route('admin.job-applications.show', $owned->getKey()))
            ->assertOk()
            ->assertSee('Owned Opening Applicant');

        // With `edit` the notes are rendered (they were withheld, not missing).
        $this->grantPermissions($manager, 'job_applications.edit');

        $this->actingAs($manager->fresh())
            ->get(route('admin.job-applications.show', $assigned->getKey()))
            ->assertOk()
            ->assertSee('SECRET-NOTE-56');

        // HR (view_any) sees every application.
        $hr = $this->createUserWithPermissions(['job_applications.view_any', 'job_applications.view']);
        $this->assertEqualsCanonicalizing(
            [(int) $owned->getKey(), (int) $assigned->getKey(), (int) $hidden->getKey()],
            $this->ids(JobApplication::query()->visibleTo($hr)->whereKey([$owned->getKey(), $assigned->getKey(), $hidden->getKey()])),
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function inquiry(string $name, ?User $assignee, array $extra = []): ContactInquiry
    {
        $data = array_merge(['name' => $name], $extra);

        $inquiry = $this->submitInquiry($data, $this->publicRequest($data, self::REVIEWER_IP, self::REVIEWER_AGENT));

        DB::table('contact_inquiries')->where('id', $inquiry->getKey())->update(['assigned_to' => $assignee?->getKey()]);

        return $inquiry->fresh();
    }

    /**
     * @param  Builder<Model>  $query
     * @return list<int>
     */
    private function ids(Builder $query): array
    {
        return $query->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }
}
