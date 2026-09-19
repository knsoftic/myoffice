<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Enums\ApprovalStatus;
use App\Enums\TestimonialType;
use App\Models\Cms\StudentReview;
use App\Models\Cms\Testimonial;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\ModerationService;
use App\Services\Cms\ReviewContentService;
use App\Services\Cms\TestimonialFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 tests 15, 16, 18, 19 and 20 — one moderation path for testimonials and student reviews (§6.5).
 *
 *  15. pending is invisible; approving publishes, stamps `approved_by` / `approved_at`, and logs pending → approved;
 *  16. rejecting needs a reason; a rejection stores it and keeps the row out of every public query;
 *  18. a rating of 0 or 6 is refused, null is accepted (the service-side net behind the Form Request);
 *  19. bulk-approving 5 ids of which 2 are approved reports 3 and leaves the 2 untouched (idempotent);
 *  20. featuring a pending review is refused 422.
 *
 * The review body is never modified by moderation (§6.5 invariant 4) — asserted on every transition here.
 */
final class ModerationTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        $this->setSetting('website.testimonial_auto_approve', false);
    }

    public function test_15_approving_a_pending_testimonial_publishes_it_with_stamps_and_an_audit_entry(): void
    {
        $moderator = $this->actAsSuperAdmin();

        $testimonial = $this->testimonial('Sana Pending Client', 'The team delivered our portal ahead of schedule.');

        $this->assertSame(ApprovalStatus::Pending, $testimonial->status);
        $this->assertNotContains((int) $testimonial->getKey(), $this->publicTestimonialIds(), 'A pending testimonial is not in the public feed.');

        $marker = $this->lastActivityId();

        $this->moderation()->approve($testimonial->fresh());

        $fresh = $testimonial->fresh();
        $this->assertSame(ApprovalStatus::Approved, $fresh->status);
        $this->assertSame((int) $moderator->getKey(), (int) $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
        $this->assertNull($fresh->rejection_reason);
        $this->assertSame('The team delivered our portal ahead of schedule.', $fresh->review, 'Moderation never edits the body.');
        $this->assertContains((int) $testimonial->getKey(), $this->publicTestimonialIds(), 'An approved testimonial is in the public feed.');

        $entries = $this->activitiesSince($marker, 'testimonials', 'approved');
        $this->assertCount(1, $entries);
        $properties = $this->propertiesOf($entries->first());
        $this->assertSame('pending', $properties['old']['status'] ?? null);
        $this->assertSame('approved', $properties['attributes']['status'] ?? null);
        $this->assertSame((int) $moderator->getKey(), (int) $entries->first()->causer_id);
    }

    public function test_16_rejection_requires_a_reason_and_keeps_the_row_out_of_public_queries(): void
    {
        $this->actAsSuperAdmin();

        $testimonial = $this->testimonial('Omar Rejected Client', 'Please remove this later, it was a draft review.');

        foreach (['', '   '] as $blank) {
            try {
                $this->moderation()->reject($testimonial->fresh(), $blank);
                $this->fail('A rejection without a reason must be refused.');
            } catch (ContentRuleException $exception) {
                $this->assertSame(422, $exception->status);
                $this->assertArrayHasKey('reason', $exception->errors());
            }
        }

        $this->assertSame(ApprovalStatus::Pending, $testimonial->fresh()->status, 'A refused rejection changes nothing.');

        $this->moderation()->reject($testimonial->fresh(), 'Contains a competitor name');

        $fresh = $testimonial->fresh();
        $this->assertSame(ApprovalStatus::Rejected, $fresh->status);
        $this->assertSame('Contains a competitor name', $fresh->rejection_reason);
        $this->assertNull($fresh->approved_by);
        $this->assertNull($fresh->approved_at);

        $this->assertFalse(Testimonial::query()->public()->whereKey($testimonial->getKey())->exists());
        $this->assertNotContains((int) $testimonial->getKey(), $this->publicTestimonialIds());

        // An approved review rejected later leaves the public feed too, on the student side as well.
        $review = $this->reviews()->storeStudentReview(['student_name' => 'Hira Student', 'course_name' => 'Laravel Bootcamp', 'review' => 'Great mentors and practical projects.'], null);
        $this->moderation()->approve($review->fresh());
        $this->assertTrue(StudentReview::query()->public()->whereKey($review->getKey())->exists());

        $this->moderation()->reject($review->fresh(), 'Student asked to withdraw it');
        $this->assertFalse(StudentReview::query()->public()->whereKey($review->getKey())->exists());
        $this->assertNotContains((int) $review->getKey(), app(TestimonialFeed::class)->studentReviews(48)->modelKeys());
    }

    public function test_18_rating_bounds_are_enforced_and_null_is_accepted(): void
    {
        $this->actAsSuperAdmin();

        foreach ([0, 6] as $rating) {
            $before = Testimonial::withTrashed()->count();

            try {
                $this->testimonial('Rating '.$rating.' Client', 'Rated outside the scale on purpose.', ['rating' => $rating]);
                $this->fail(sprintf('A rating of %d must be refused.', $rating));
            } catch (ContentRuleException $exception) {
                $this->assertSame(422, $exception->status);
                $this->assertArrayHasKey('rating', $exception->errors());
            }

            $this->assertSame($before, Testimonial::withTrashed()->count());
        }

        $unrated = $this->testimonial('No Rating Client', 'We did not want to give a star rating.', ['rating' => null]);
        $this->assertNull(DB::table('testimonials')->where('id', $unrated->getKey())->value('rating'), 'A null rating is stored as null (no stars).');

        $rated = $this->testimonial('Five Star Client', 'Top quality work.', ['rating' => 5]);
        $this->assertSame(5, (int) DB::table('testimonials')->where('id', $rated->getKey())->value('rating'));
    }

    public function test_19_bulk_approve_is_idempotent_and_reports_only_new_approvals(): void
    {
        $firstModerator = $this->actAsSuperAdmin();

        $records = [];

        for ($i = 1; $i <= 5; $i++) {
            $records[] = $this->testimonial('Bulk Client '.$i, 'Bulk approval candidate number '.$i.'.');
        }

        $this->moderation()->approve($records[0]->fresh());
        $this->moderation()->approve($records[1]->fresh());

        $alreadyApproved = [
            (int) $records[0]->getKey() => $records[0]->fresh()->approved_at?->toDateTimeString(),
            (int) $records[1]->getKey() => $records[1]->fresh()->approved_at?->toDateTimeString(),
        ];

        $this->travel(5)->minutes();
        $secondModerator = $this->actAsSuperAdmin();

        $ids = array_map(static fn (Testimonial $record): int => (int) $record->getKey(), $records);
        $marker = $this->lastActivityId();

        $this->assertSame(3, $this->moderation()->bulkApprove(Testimonial::class, $ids), 'Three were newly approved.');

        foreach ($records as $index => $record) {
            $fresh = $record->fresh();
            $this->assertSame(ApprovalStatus::Approved, $fresh->status);

            if ($index < 2) {
                $this->assertSame((int) $firstModerator->getKey(), (int) $fresh->approved_by, 'An already-approved record keeps its approver.');
                $this->assertSame($alreadyApproved[(int) $record->getKey()], $fresh->approved_at?->toDateTimeString(), 'An already-approved record keeps its timestamp.');
            } else {
                $this->assertSame((int) $secondModerator->getKey(), (int) $fresh->approved_by);
            }
        }

        $this->assertCount(3, $this->activitiesSince($marker, 'testimonials', 'approved'), 'One entry per newly approved record.');
        $this->assertCount(1, $this->activitiesSince($marker, 'testimonials', 'bulk_approved'), 'Plus one summary entry.');

        // Running it again approves nothing.
        $this->assertSame(0, $this->moderation()->bulkApprove(Testimonial::class, $ids));
    }

    public function test_20_featuring_a_pending_review_is_refused(): void
    {
        $this->actAsSuperAdmin();

        $pending = $this->testimonial('Feature Me Client', 'I would love to be on the home page slider.');

        try {
            $this->moderation()->toggleFeatured($pending->fresh());
            $this->fail('A pending review cannot be featured.');
        } catch (ContentRuleException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $this->assertFalse((bool) $pending->fresh()->is_featured);

        $review = $this->reviews()->storeStudentReview(['student_name' => 'Pending Student', 'review' => 'Waiting for moderation still.'], null);

        try {
            $this->moderation()->toggleFeatured($review->fresh());
            $this->fail('A pending student review cannot be featured either.');
        } catch (ContentRuleException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $this->assertFalse((bool) $review->fresh()->is_featured);

        // Approved first, featuring works.
        $this->moderation()->approve($pending->fresh());
        $this->moderation()->toggleFeatured($pending->fresh());
        $this->assertTrue((bool) $pending->fresh()->is_featured);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function testimonial(string $author, string $review, array $extra = []): Testimonial
    {
        return $this->reviews()->storeTestimonial(array_merge([
            'type' => TestimonialType::Client->value,
            'author_name' => $author,
            'author_company' => 'Acme Industries',
            'review' => $review,
        ], $extra), null)->fresh();
    }

    /**
     * @return list<int>
     */
    private function publicTestimonialIds(): array
    {
        return array_map('intval', app(TestimonialFeed::class)->testimonials(48)->modelKeys());
    }

    private function moderation(): ModerationService
    {
        return app(ModerationService::class);
    }

    private function reviews(): ReviewContentService
    {
        return app(ReviewContentService::class);
    }
}
