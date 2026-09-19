<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\Cms\Moderatable;
use App\Enums\ApprovalStatus;
use App\Events\Cms\StudentReviewApproved;
use App\Events\Cms\TestimonialApproved;
use App\Models\Cms\StudentReview;
use App\Models\Cms\Testimonial;
use App\Models\User;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\Support\ContentHelper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * One moderation code path for testimonials and student reviews (phase-04 §6.5).
 *
 * Invariants:
 *
 *   1. Allowed moves only: pending → approved, pending → rejected, approved → rejected, rejected → approved,
 *      and any → pending through `reset()`. Anything else is a 422.
 *   2. `approve()` stamps `approved_by` (the actor) and `approved_at`, clears `rejection_reason`, and fires
 *      `TestimonialApproved` / `StudentReviewApproved` after commit.
 *   3. `reject()` requires a non-empty reason (stored in `rejection_reason`) and clears the approval stamps.
 *      A rejected record is also un-featured, so it can never linger on the homepage slider.
 *   4. **The review body is never modified by moderation.** Only status, stamps, reason and the featured
 *      flag move here; editing the text is `ReviewContentService`, audited with old and new values.
 *   5. `toggleFeatured()` refuses to feature a record that is not approved (un-featuring is always allowed).
 *   6. `bulkApprove()` is one transaction, skips ids already approved (idempotent), ignores ids that do not
 *      exist or that the actor cannot view, and writes one activity entry per approved record plus one
 *      summary entry.
 *   7. Nothing that is not approved is ever public — that is each model's `scopePublic()`, not a Blade file.
 *
 * Every transition re-reads the status `FOR UPDATE`, so two moderators clicking at once cannot both win.
 */
final class ModerationService
{
    private const BULK_LIMIT = 200;

    public function __construct(
        private readonly ContentHelper $content,
    ) {}

    public function approve(Moderatable&Model $record, ?string $note = null): void
    {
        $this->content->transaction(function () use ($record, $note): void {
            $from = $this->lockedStatus($record);

            if (! in_array($from, [ApprovalStatus::Pending, ApprovalStatus::Rejected], true)) {
                throw ContentRuleException::transitionNotAllowed($from->label(), ApprovalStatus::Approved->label());
            }

            $this->approveLocked($record, $from, $note);
        });
    }

    public function reject(Moderatable&Model $record, string $reason): void
    {
        $reason = $this->reason($reason);

        $this->content->transaction(function () use ($record, $reason): void {
            $from = $this->lockedStatus($record);

            if (! in_array($from, [ApprovalStatus::Pending, ApprovalStatus::Approved], true)) {
                throw ContentRuleException::transitionNotAllowed($from->label(), ApprovalStatus::Rejected->label());
            }

            $this->transition($record, $from, ApprovalStatus::Rejected, [
                'approved_by' => null,
                'approved_at' => null,
                'rejection_reason' => $reason,
                'is_featured' => false,
            ], $reason, 'rejected');

            if ($from === ApprovalStatus::Approved) {
                $this->content->flushPublicCache($record->moderationLabel().' rejected');
            }
        });
    }

    public function reset(Moderatable&Model $record, string $reason): void
    {
        $reason = $this->reason($reason);

        $this->content->transaction(function () use ($record, $reason): void {
            $from = $this->lockedStatus($record);

            if ($from === ApprovalStatus::Pending) {
                return;
            }

            $this->transition($record, $from, ApprovalStatus::Pending, [
                'approved_by' => null,
                'approved_at' => null,
                'rejection_reason' => null,
                'is_featured' => false,
            ], $reason, 'moderation_reset');

            if ($from === ApprovalStatus::Approved) {
                $this->content->flushPublicCache($record->moderationLabel().' returned to the queue');
            }
        });
    }

    /**
     * @param  class-string<Model&Moderatable>  $modelClass
     * @param  array<int, int|string>  $ids
     * @return int how many records this call approved
     */
    public function bulkApprove(string $modelClass, array $ids): int
    {
        if (! is_subclass_of($modelClass, Model::class) || ! is_subclass_of($modelClass, Moderatable::class)) {
            throw new InvalidArgumentException(sprintf('[%s] is not a moderatable model.', $modelClass));
        }

        $requested = [];

        foreach ($ids as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $requested[(int) $id] = (int) $id;
            }
        }

        $requested = array_values($requested);

        if (count($requested) > self::BULK_LIMIT) {
            throw ContentRuleException::tooManyItems('ids', self::BULK_LIMIT);
        }

        if ($requested === []) {
            return 0;
        }

        return $this->content->transaction(function () use ($modelClass, $requested): int {
            /** @var Collection<int, Model&Moderatable> $records */
            $records = $modelClass::query()->whereIn('id', $requested)->orderBy('id')->lockForUpdate()->get();
            $actor = Auth::user();

            $approved = [];
            $already = [];
            $hidden = [];

            foreach ($records as $record) {
                if ($actor instanceof User && Gate::getPolicyFor($record) !== null && ! $actor->can('view', $record)) {
                    $hidden[] = (int) $record->getKey();

                    continue;
                }

                $from = $record->moderationStatus();

                if ($from === ApprovalStatus::Approved) {
                    $already[] = (int) $record->getKey();

                    continue;
                }

                $this->approveLocked($record, $from, null);
                $approved[] = (int) $record->getKey();
            }

            $found = $records->map(static fn (Model $record): int => (int) $record->getKey())->all();
            $prototype = new $modelClass;

            $this->content->audit(
                method_exists($prototype, 'moduleSlug') ? (string) $prototype->moduleSlug() : $prototype->getTable(),
                sprintf('Bulk approval: %d approved, %d already approved', count($approved), count($already)),
                null,
                [
                    'model' => $modelClass,
                    'requested' => $requested,
                    'approved' => $approved,
                    'already_approved' => $already,
                    'not_found' => array_values(array_diff($requested, $found)),
                    'not_visible' => $hidden,
                ],
                null,
                'bulk_approved',
            );

            return count($approved);
        });
    }

    public function toggleFeatured(Moderatable&Model $record): void
    {
        $this->content->transaction(function () use ($record): void {
            $status = $this->lockedStatus($record);
            $featuring = ! (bool) $record->getAttribute('is_featured');

            if ($featuring && $status !== ApprovalStatus::Approved) {
                throw ContentRuleException::notApproved();
            }

            $this->content->quietly($record, function () use ($record, $featuring): void {
                $record->forceFill(['is_featured' => $featuring])->save();
            });

            $this->content->audit(
                $this->module($record),
                sprintf('%s %s', $record->moderationLabel(), $featuring ? 'featured' : 'unfeatured'),
                $record,
                ['old' => ['is_featured' => ! $featuring], 'attributes' => ['is_featured' => $featuring]],
                null,
                $featuring ? 'featured' : 'unfeatured',
            );

            if ($status === ApprovalStatus::Approved) {
                $this->content->flushPublicCache($record->moderationLabel().($featuring ? ' featured' : ' unfeatured'));
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function approveLocked(Moderatable&Model $record, ApprovalStatus $from, ?string $note): void
    {
        $note = $note === null ? null : $this->content->plain($note, 500);

        $this->transition($record, $from, ApprovalStatus::Approved, [
            'approved_by' => $this->content->actorId(),
            'approved_at' => Carbon::now(),
            'rejection_reason' => null,
        ], $note, 'approved');

        $event = match (true) {
            $record instanceof Testimonial => new TestimonialApproved($record),
            $record instanceof StudentReview => new StudentReviewApproved($record),
            default => null,
        };

        if ($event !== null) {
            event($event);
        } else {
            $this->content->flushPublicCache($record->moderationLabel().' approved');
        }
    }

    /**
     * @param  array<string, mixed>  $stamps
     */
    private function transition(Moderatable&Model $record, ApprovalStatus $from, ApprovalStatus $to, array $stamps, ?string $reason, string $event): void
    {
        $this->content->quietly($record, function () use ($record, $to, $stamps): void {
            $record->forceFill(['status' => $to, ...$stamps])->save();
        });

        $this->content->audit(
            $this->module($record),
            sprintf('%s moved from %s to %s', $record->moderationLabel(), $from->label(), $to->label()),
            $record,
            [
                'old' => ['status' => $from->value],
                'attributes' => array_merge(['status' => $to->value], array_map(
                    static fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
                    $stamps,
                )),
            ],
            $reason,
            $event,
        );
    }

    private function lockedStatus(Moderatable&Model $record): ApprovalStatus
    {
        $stored = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->value('status');

        if ($stored === null) {
            throw ContentRuleException::refuse('status', 'This review no longer exists.');
        }

        // Eloquent's value() returns the cast attribute — the enum itself.
        $status = $stored instanceof ApprovalStatus ? $stored : (ApprovalStatus::tryFrom((string) $stored) ?? ApprovalStatus::Pending);

        $record->setAttribute('status', $status);
        $record->syncOriginalAttribute('status');

        return $status;
    }

    private function reason(string $reason): string
    {
        $clean = $this->content->plain($reason, 255);

        if ($clean === null) {
            throw ContentRuleException::reasonRequired();
        }

        return $clean;
    }

    private function module(Model $record): string
    {
        return method_exists($record, 'moduleSlug') ? (string) $record->moduleSlug() : $record->getTable();
    }
}
