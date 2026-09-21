<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\CourseInquiryStatus;
use App\Enums\FollowUpOutcome;
use App\Enums\InquirySource;
use App\Models\Institute\CourseInquiry;
use App\Models\Institute\CourseInquiryFollowUp;
use App\Models\User;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The institute's funnel head (§86, §68, phase-14-17 §6.12).
 *
 * **Every status move goes through `changeStatus()` and the §2.30.2 table.** A follow-up whose outcome
 * says "not interested" while the enquiry still reads `contacted` is a queue that disagrees with its own
 * history, and whichever half the next person reads is the one they act on. `logFollowUp()` therefore
 * applies `FollowUpOutcome::suggestsStatus()` through the same method a human would use, so the two can
 * never diverge — and the transition table is what refuses a move nobody should be able to make.
 *
 * **A public submission is deduped by its key, not by its content.** `uq_ci_idem` makes a replayed POST
 * return the enquiry it already created, and `uq_ci_inquiry` does the same for Phase 4's router
 * re-delivering one `contact_inquiries` row (F-3.8). Both are caught as a 1062 and answered with the
 * existing row, because a visitor who taps submit twice should see one confirmation, not an error.
 *
 * **The referral code is captured, resolved for display, and attached to nobody.** An enquiry is not a
 * subject the spine's `collaborator_referrals` CHECK allows, and the real attribution happens at
 * conversion (§6.4, [D-IN-13]). What is stored here is evidence, kept verbatim even when it resolves to
 * nothing — a mistyped code a partner gave out is worth seeing.
 */
final class CourseInquiryService
{
    /** §2.30.2, verbatim. A move that is not in this table throws and names both ends. */
    private const TRANSITIONS = [
        'new' => ['contacted', 'demo_scheduled', 'admission_confirmed', 'not_interested'],
        'contacted' => ['interested', 'not_interested', 'demo_scheduled', 'admission_confirmed'],
        'interested' => ['demo_scheduled', 'admission_confirmed', 'not_interested'],
        'demo_scheduled' => ['interested', 'not_interested', 'admission_confirmed'],
        'admission_confirmed' => [],
        'not_interested' => ['contacted'],
    ];

    /** Moves that may not happen without somebody saying why. */
    private const REASON_REQUIRED = ['not_interested', 'contacted'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly StudentNumberService $numbers,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Creating one
    |--------------------------------------------------------------------------
    */

    /**
     * The public path: the website form, and Phase 4's router delivering a contact-form enquiry.
     *
     * @param  array<string, mixed>  $data
     */
    public function createFromPublic(array $data, ?User $actor = null): CourseInquiry
    {
        $existing = $this->findExisting($data);

        if ($existing instanceof CourseInquiry) {
            return $existing;
        }

        $data['source'] = $data['source'] ?? InquirySource::Website->value;

        try {
            return $this->write($data, $actor, assignRoundRobin: true);
        } catch (QueryException $e) {
            // 1062 on either guard: somebody got there first — the same submission, a moment earlier.
            // The caller wanted an enquiry to exist, and one does.
            $duplicate = $this->isDuplicateKey($e) ? $this->findExisting($data) : null;

            if ($duplicate instanceof CourseInquiry) {
                return $duplicate;
            }

            throw $e;
        }
    }

    /**
     * The staff path: a walk-in, a phone call, a card somebody left.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): CourseInquiry
    {
        return $this->write($data, $actor, assignRoundRobin: ! array_key_exists('assigned_to', $data));
    }

    /*
    |--------------------------------------------------------------------------
    | Working one
    |--------------------------------------------------------------------------
    */

    /**
     * Append a contact attempt and let its outcome move the enquiry.
     *
     * @param  array<string, mixed>  $data
     */
    public function logFollowUp(CourseInquiry $inquiry, array $data, ?User $actor = null): CourseInquiryFollowUp
    {
        $outcome = $data['outcome'] instanceof FollowUpOutcome
            ? $data['outcome']
            : FollowUpOutcome::from((string) $data['outcome']);

        return $this->db->transaction(function () use ($inquiry, $data, $outcome, $actor): CourseInquiryFollowUp {
            $before = $inquiry->status;

            $contactedAt = isset($data['contacted_at'])
                ? Carbon::parse((string) $data['contacted_at'])
                : Carbon::now();

            // Default the next date from the setting rather than leaving it blank: an enquiry with no
            // next action is one that quietly leaves the queue, which is the failure §68 is about.
            $next = isset($data['next_follow_up_at']) && $data['next_follow_up_at'] !== null
                ? Carbon::parse((string) $data['next_follow_up_at'])->startOfDay()
                : $this->defaultNextFollowUp($outcome, $contactedAt);

            $followUp = new CourseInquiryFollowUp;
            $followUp->fill([
                'channel' => $data['channel'] instanceof \BackedEnum ? $data['channel']->value : (string) $data['channel'],
                'outcome' => $outcome->value,
                'contacted_at' => $contactedAt,
                'notes' => $data['notes'] ?? null,
                'next_follow_up_at' => $next,
            ]);
            $followUp->forceFill([
                'course_inquiry_id' => $inquiry->getKey(),
                'status_before' => $before->value,
                'user_id' => $actor?->getKey(),
                'user_name' => $actor?->name,
                'created_by' => $actor?->getKey(),
            ])->save();

            // A first contact on a `new` enquiry is what "contacted" means; after that the outcome
            // decides. Both go through changeStatus(), so both obey §2.30.2.
            $suggested = $before === CourseInquiryStatus::New && $outcome->reachedSomebody()
                ? CourseInquiryStatus::Contacted
                : $outcome->suggestsStatus();

            if ($suggested instanceof CourseInquiryStatus && $suggested !== $before) {
                $this->changeStatus(
                    $inquiry,
                    $suggested,
                    $suggested === CourseInquiryStatus::NotInterested
                        ? ($data['notes'] ?? 'Recorded from a follow-up outcome.')
                        : null,
                    $actor,
                    silent: true,
                );
            }

            $followUp->forceFill(['status_after' => $inquiry->refresh()->status->value])->save();

            $this->recount($inquiry, $next);

            return $followUp->refresh();
        }, 3);
    }

    /**
     * §2.30.2. `silent` exists for the follow-up path, which has already written the row that explains
     * the move — a second identical activity entry would double-count the same event.
     */
    public function changeStatus(
        CourseInquiry $inquiry,
        CourseInquiryStatus $to,
        ?string $reason = null,
        ?User $actor = null,
        bool $silent = false,
    ): CourseInquiry {
        $from = $inquiry->status;

        if ($from === $to) {
            return $inquiry;
        }

        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw CourseRuleException::refuse('status', sprintf(
                'An enquiry cannot go from %s to %s. %s',
                $from->label(),
                $to->label(),
                $allowed === []
                    ? sprintf('%s is where this one ends.', $from->label())
                    : 'From here it can only become: '.implode(', ', $allowed).'.',
            ));
        }

        $reason = trim((string) $reason);

        if (in_array($to->value, self::REASON_REQUIRED, true) && $reason === '' && $from !== CourseInquiryStatus::New) {
            throw CourseRuleException::reasonRequired('status', $to === CourseInquiryStatus::NotInterested
                ? 'Losing an enquiry takes a reason: the lost ones are the list worth reading later.'
                : 'Re-opening an enquiry somebody closed takes a reason.');
        }

        return $this->db->transaction(function () use ($inquiry, $to, $reason, $actor, $silent): CourseInquiry {
            if ($reason !== '' && ! $silent) {
                $inquiry->withReason($reason);
            }

            $attributes = [
                'status' => $to->value,
                'updated_by' => $actor?->getKey(),
            ];

            if ($to === CourseInquiryStatus::NotInterested && $reason !== '') {
                $attributes['lost_reason'] = $reason;
            }

            if ($to === CourseInquiryStatus::AdmissionConfirmed) {
                $attributes['converted_at'] = Carbon::now();
            }

            // Re-opening clears the loss: `chk_ci_lost_reason` only demands one while the status says
            // the enquiry was lost, and a stale reason on a live enquiry reads as a current verdict.
            if ($to === CourseInquiryStatus::Contacted) {
                $attributes['lost_reason'] = null;
            }

            $inquiry->forceFill($attributes)->save();

            return $inquiry->refresh();
        }, 3);
    }

    /**
     * Hand the enquiry to somebody else. Audited, because "who was supposed to call them" is the
     * question a missed follow-up turns into.
     */
    public function assign(CourseInquiry $inquiry, User $to, ?string $note = null, ?User $actor = null): CourseInquiry
    {
        return $this->db->transaction(function () use ($inquiry, $to, $note, $actor): CourseInquiry {
            $note = trim((string) $note);

            $inquiry->withReason($note !== ''
                ? $note
                : sprintf('Assigned to %s.', $to->name));

            $inquiry->forceFill([
                'assigned_to' => $to->getKey(),
                'updated_by' => $actor?->getKey(),
            ])->save();

            return $inquiry->refresh();
        }, 3);
    }

    /**
     * Rewrite the two caches from the follow-up rows. They are a convenience for the queue's sort
     * order; the rows are the record, and this is what makes that true after every write.
     */
    public function recount(CourseInquiry $inquiry, ?Carbon $nextFollowUp = null): void
    {
        $aggregate = CourseInquiryFollowUp::query()
            ->where('course_inquiry_id', $inquiry->getKey())
            ->selectRaw('COUNT(*) AS attempts, MAX(contacted_at) AS last_contacted')
            ->first();

        $attributes = [
            'contact_attempts' => (int) ($aggregate->attempts ?? 0),
            'last_contacted_at' => $aggregate->last_contacted ?? null,
        ];

        if ($nextFollowUp instanceof Carbon) {
            $attributes['follow_up_date'] = $nextFollowUp->toDateString();
        }

        CourseInquiry::query()->whereKey($inquiry->getKey())->update($attributes);

        $inquiry->forceFill($attributes);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(array $data, ?User $actor, bool $assignRoundRobin): CourseInquiry
    {
        return $this->db->transaction(function () use ($data, $actor, $assignRoundRobin): CourseInquiry {
            $inquiry = new CourseInquiry;

            $inquiry->fill($this->columns($data));
            $inquiry->forceFill([
                'inquiry_number' => $this->numbers->nextInquiryNumber(),
                'status' => CourseInquiryStatus::New->value,
                'phone' => $this->normalisePhone((string) ($data['phone'] ?? '')),
                'whatsapp' => isset($data['whatsapp']) && trim((string) $data['whatsapp']) !== ''
                    ? $this->normalisePhone((string) $data['whatsapp'])
                    : null,
                'contact_inquiry_id' => $data['contact_inquiry_id'] ?? null,
                'idempotency_key' => $this->idempotencyKey($data),
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
                'referral_code' => $data['referral_code'] ?? null,
                'referral_code_valid' => (bool) ($data['referral_code_valid'] ?? false),
                'collaborator_id' => $data['collaborator_id'] ?? null,
                'referral_visit_id' => $data['referral_visit_id'] ?? null,
                'follow_up_date' => $data['follow_up_date']
                    ?? Carbon::now()->addDays(max(0, (int) setting('institute.inquiry_followup_days', 2)))->toDateString(),
                'created_by' => $actor?->getKey(),
            ])->save();

            if ($assignRoundRobin && $inquiry->assigned_to === null) {
                $this->assignRoundRobin($inquiry);
            }

            return $inquiry->refresh();
        }, 3);
    }

    /**
     * Share new enquiries out among the people who work them — whoever holds `course_inquiries.edit`,
     * fewest open enquiries first. An unassigned enquiry is one nobody is answerable for, and "the
     * team will pick it up" is what an empty queue is made of.
     */
    private function assignRoundRobin(CourseInquiry $inquiry): void
    {
        $candidates = User::query()
            ->where('status', 'active')
            ->permission('course_inquiries.edit')
            ->pluck('id')
            ->all();

        if ($candidates === []) {
            return;
        }

        $loads = CourseInquiry::query()
            ->open()
            ->whereIn('assigned_to', $candidates)
            ->selectRaw('assigned_to, COUNT(*) AS open_count')
            ->groupBy('assigned_to')
            ->pluck('open_count', 'assigned_to')
            ->all();

        $lightest = null;
        $lightestLoad = PHP_INT_MAX;

        foreach ($candidates as $id) {
            $load = (int) ($loads[$id] ?? 0);

            if ($load < $lightestLoad) {
                $lightest = (int) $id;
                $lightestLoad = $load;
            }
        }

        if ($lightest !== null) {
            $inquiry->forceFill(['assigned_to' => $lightest])->save();
        }
    }

    /**
     * The two guards §2.11 keeps apart: one for Phase 4's router, one for this phase's own form.
     *
     * @param  array<string, mixed>  $data
     */
    private function findExisting(array $data): ?CourseInquiry
    {
        $contactInquiryId = $data['contact_inquiry_id'] ?? null;

        if ($contactInquiryId !== null) {
            $row = CourseInquiry::query()->where('contact_inquiry_id', $contactInquiryId)->first();

            if ($row instanceof CourseInquiry) {
                return $row;
            }
        }

        $key = trim((string) ($data['idempotency_key'] ?? ''));

        if ($key === '') {
            return null;
        }

        return CourseInquiry::query()->where('idempotency_key', $key)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function idempotencyKey(array $data): ?string
    {
        $key = trim((string) ($data['idempotency_key'] ?? ''));

        return $key === '' ? null : $key;
    }

    /**
     * Digits and a leading `+` only. Two receptionists writing 0300-1234567 and +92 300 1234567 mean
     * the same person, and the duplicate lookup on `phone` only finds them if the column agrees.
     */
    private function normalisePhone(string $phone): string
    {
        $trimmed = trim($phone);
        $plus = str_starts_with($trimmed, '+') ? '+' : '';

        return $plus.(preg_replace('/\D/', '', $trimmed) ?? '');
    }

    private function defaultNextFollowUp(FollowUpOutcome $outcome, Carbon $contactedAt): ?Carbon
    {
        // An enquiry that is over needs no next date; one that is still open always gets one.
        if ($outcome->suggestsStatus() === CourseInquiryStatus::NotInterested) {
            return null;
        }

        return $contactedAt->copy()
            ->addDays(max(1, (int) setting('institute.inquiry_followup_days', 2)))
            ->startOfDay();
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        return array_filter([
            'branch_id' => $data['branch_id'] ?? null,
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'city' => $data['city'] ?? null,
            'education' => $data['education'] ?? null,
            'course_id' => $data['course_id'] ?? null,
            'batch_id' => $data['batch_id'] ?? null,
            'preferred_delivery_mode' => $data['preferred_delivery_mode'] ?? null,
            'preferred_timing' => $data['preferred_timing'] ?? null,
            'source' => $data['source'] ?? null,
            'source_url' => $data['source_url'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'message' => $data['message'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], static fn ($value, string $key): bool => array_key_exists($key, $data), ARRAY_FILTER_USE_BOTH);
    }
}
