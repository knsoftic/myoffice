<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\IdCardStatus;
use App\Enums\PrintTemplateType;
use App\Models\Institute\PrintTemplate;
use App\Models\Institute\Student;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\Institute\StudentIdCard;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Finance\DocumentNumberService;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Issuing, replacing and retiring a student's ID card
 * (phase-19-23 §6.15, INV-21-4, requirement §85).
 *
 * **A card is a physical object, and the service is shaped by that.** There is no draft: a card is
 * numbered, snapshotted and printed in one step, because an unprinted card helps nobody. What there
 * *is* instead is a rich set of ways for it to stop being valid — expired, lost, damaged, replaced,
 * revoked — because each one means something different to the office holding the register.
 *
 * **The photograph is copied, not referenced.** A card in somebody's wallet must not change because
 * they uploaded a new profile picture; the file is duplicated onto the private disk at issue time and
 * the card points at its own copy (INV-21-4). That is also why a failure to copy it is a refusal
 * rather than a shrug — a card with no photo is not the card the institute intended to issue.
 *
 * **One active card per student, enforced by `uq_sic_live`.** The generated `live_guard` is 1 for
 * `active` and NULL otherwise, so every expired, lost and replaced predecessor coexists beside the
 * one live card. Issuing a second retires the first rather than colliding — because the office's
 * intent when they issue a replacement is unambiguous, and making them retire the old one by hand
 * first would just be a step they forget.
 */
final class StudentIdCardService
{
    use WritesAuditTrail;

    private const MODULE = 'student_id_cards';

    public function __construct(
        private readonly QrCodeService $qr,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * Issue a card. One transaction: number, code, snapshots, photo copy, and the previous card
     * retired if there was one.
     *
     * @param  array<string, mixed>  $data
     */
    public function issue(StudentBatchEnrollment $enrollment, array $data = [], ?User $actor = null): StudentIdCard
    {
        $actor ??= Auth::user();
        $student = $enrollment->student;

        if ($student === null) {
            throw CourseRuleException::refuse(
                'student_batch_enrollment_id',
                'This enrolment has no student behind it, so there is nobody to issue a card to.',
            );
        }

        $photoPath = $this->copyPhoto($student);

        if ((bool) setting('institute.id_card_require_photo', true) && $photoPath === null) {
            throw CourseRuleException::refuse(
                'photo_path',
                'This student has no photograph, and a card cannot be issued without one. Add a photo '
                .'to their profile, or turn the requirement off in the institute settings.',
            );
        }

        return $this->numbers->assign(
            'institute.id_card_prefix',
            'institute.id_card_next_number',
            '%05d',
            function (string $number) use ($enrollment, $student, $data, $photoPath, $actor): StudentIdCard {
                // The previous card steps aside first: `uq_sic_live` permits one row whose guard is 1,
                // so writing the new one before retiring the old would collide. The index is the
                // backstop, not the mechanism.
                $this->retirePrevious($student, $actor);

                $code = $this->freshCode();

                $card = new StudentIdCard;
                $card->forceFill(array_merge(
                    $this->snapshotsFor($enrollment, $student, $data),
                    [
                        'card_number' => $number,
                        'verification_code' => $code,
                        // Built now and **stored**: changing the verification host next year must not
                        // redirect a code already printed on a card (INV-21-4).
                        'qr_payload' => $this->qr->verificationUrl($code),
                        'photo_path' => $photoPath,
                        'issued_on' => Carbon::now()->toDateString(),
                        'valid_until' => $this->validUntil($data),
                        'status' => IdCardStatus::Active->value,
                        'print_template_id' => $data['print_template_id'] ?? $this->defaultTemplateId(),
                    ],
                ));
                $card->save();

                $this->audit($card, 'ID card issued', [
                    'attributes' => ['card_number' => $number],
                ], self::MODULE);

                return $card->refresh();
            },
            'uq_sic_number',
        );
    }

    /**
     * Report a card lost, damaged, expired or revoked.
     *
     * **A revocation needs a reason and the others do not**, which is the difference between a fact
     * and a decision: a card expiring is arithmetic, a card being withdrawn is somebody's judgement
     * and the register should say whose and why.
     */
    public function changeStatus(
        StudentIdCard $card,
        IdCardStatus $status,
        ?string $reason = null,
        ?User $actor = null,
    ): StudentIdCard {
        $actor ??= Auth::user();
        $reason = trim((string) $reason);

        if ($status === IdCardStatus::Revoked && $reason === '') {
            throw CourseRuleException::reasonRequired('reason', 'Say why this card is being withdrawn.');
        }

        if ($card->status === $status) {
            return $card;
        }

        if ($card->status->isTerminal()) {
            throw CourseRuleException::refuse('status', sprintf(
                'This card is already %s, and nothing further happens to it.',
                mb_strtolower($card->status->label()),
            ));
        }

        return DB::transaction(function () use ($card, $status, $reason, $actor): StudentIdCard {
            $locked = StudentIdCard::query()->whereKey($card->getKey())->lockForUpdate()->firstOrFail();
            $was = $locked->status;

            $locked->forceFill(array_merge(
                ['status' => $status->value],
                $status === IdCardStatus::Revoked ? [
                    'revoked_at' => Carbon::now(),
                    'revoked_by' => $actor?->getKey(),
                    'revocation_reason' => mb_substr($reason, 0, 255),
                ] : [],
            ))->save();

            $this->audit($locked, 'ID card '.mb_strtolower($status->label()), [
                'old' => ['status' => $was->value],
                'attributes' => ['status' => $status->value],
            ], self::MODULE, $reason === '' ? null : $reason);

            return $locked->refresh();
        });
    }

    /**
     * Replace a card that is expired, lost or damaged.
     *
     * **Not an active one** — that is `issue()`, which retires the predecessor on its way past — and
     * **not a revoked one**, because a card withdrawn for cause is not reissued by asking. The
     * replacement is a new row linked by `replacement_of_id`, with `uq_sic_replacement` permitting
     * exactly one successor.
     */
    public function replace(StudentIdCard $card, string $reason, ?User $actor = null): StudentIdCard
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::reasonRequired('reason', 'Say why a replacement card is being issued.');
        }

        if (! $card->status->isReplaceable()) {
            throw CourseRuleException::refuse('status', sprintf(
                'A card that is %s is not replaced from here. %s',
                mb_strtolower($card->status->label()),
                $card->status === IdCardStatus::Active
                    ? 'Issue a new card — the current one is retired automatically.'
                    : 'Issue a fresh card instead.',
            ));
        }

        if ($card->wasReplaced()) {
            throw CourseRuleException::refuse(
                'replacement_of_id',
                'This card has already been replaced. One successor, and it exists.',
            );
        }

        $enrollment = $card->enrollment;

        if ($enrollment === null) {
            throw CourseRuleException::refuse(
                'student_batch_enrollment_id',
                'This card is not attached to an enrolment, so a replacement cannot be prepared from it.',
            );
        }

        $actor ??= Auth::user();

        return DB::transaction(function () use ($card, $enrollment, $reason, $actor): StudentIdCard {
            $replacement = $this->issue($enrollment, [
                'print_template_id' => $card->getAttribute('print_template_id'),
            ], $actor);

            $replacement->forceFill([
                'replacement_of_id' => $card->getKey(),
                'replacement_reason' => mb_substr($reason, 0, 255),
            ])->save();

            // The original becomes `replaced`, which is terminal — it is neither lost nor in use any
            // more, and saying so is what stops it being replaced twice.
            StudentIdCard::query()
                ->whereKey($card->getKey())
                ->update(['status' => IdCardStatus::Replaced->value]);

            $this->audit($replacement, 'ID card replaced', [
                'attributes' => ['replacement_of_id' => $card->getKey()],
            ], self::MODULE, $reason);

            return $replacement->refresh();
        });
    }

    /**
     * The expiry sweep: active, dated, and past its date.
     *
     * Scoped to `active` rather than to every non-terminal status, so a card already marked lost is
     * not quietly relabelled expired — which would lose the fact that it is missing.
     *
     * @return int how many were swept
     */
    public function sweepExpired(?Carbon $asOf = null, int $limit = 500): int
    {
        $asOf ??= Carbon::now();
        $swept = 0;

        StudentIdCard::query()
            ->dueToExpire($asOf)
            ->limit($limit)
            ->get()
            ->each(function (StudentIdCard $card) use (&$swept): void {
                $this->changeStatus($card, IdCardStatus::Expired);
                $swept++;
            });

        return $swept;
    }

    public function markPrinted(StudentIdCard $card, ?User $actor = null): StudentIdCard
    {
        $actor ??= Auth::user();

        return DB::transaction(function () use ($card, $actor): StudentIdCard {
            $locked = StudentIdCard::query()->whereKey($card->getKey())->lockForUpdate()->firstOrFail();

            $count = (int) $locked->getAttribute('print_count') + 1;

            $locked->forceFill([
                'print_count' => $count,
                'last_printed_at' => Carbon::now(),
                'last_printed_by' => $actor?->getKey(),
            ])->save();

            $this->audit($locked, 'ID card printed', [
                'attributes' => ['print_count' => $count],
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    // ===========================================================================================

    /** Retire whatever card this student currently holds, so the new one can be the live row. */
    private function retirePrevious(Student $student, ?User $actor): void
    {
        StudentIdCard::query()
            ->where('student_id', $student->getKey())
            ->where('status', IdCardStatus::Active->value)
            ->get()
            ->each(function (StudentIdCard $previous): void {
                $previous->forceFill(['status' => IdCardStatus::Replaced->value])->save();

                $this->audit($previous, 'ID card superseded by a new issue', [
                    'old' => ['status' => IdCardStatus::Active->value],
                    'attributes' => ['status' => IdCardStatus::Replaced->value],
                ], self::MODULE);
            });
    }

    /**
     * Copy the student's photograph onto the private disk — **a copy, not a pointer**.
     *
     * Returns null when they have none, which the caller turns into a refusal or a blank card
     * depending on `institute.id_card_require_photo`. A copy that fails is also null rather than an
     * exception: the caller's rule decides whether a missing photo blocks the card, and this should
     * not pre-empt it.
     */
    private function copyPhoto(Student $student): ?string
    {
        $source = (string) $student->getAttribute('photo_path');

        if (trim($source) === '') {
            return null;
        }

        $private = Storage::disk('private');
        $public = Storage::disk('public');

        $from = $public->exists($source) ? $public : ($private->exists($source) ? $private : null);

        if ($from === null) {
            return null;
        }

        $extension = pathinfo($source, PATHINFO_EXTENSION) ?: 'jpg';
        $target = sprintf('id-cards/photos/%s.%s', (string) Str::ulid(), $extension);

        $stream = $from->readStream($source);

        if ($stream === null || $stream === false) {
            return null;
        }

        $private->writeStream($target, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $target;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function snapshotsFor(StudentBatchEnrollment $enrollment, Student $student, array $data): array
    {
        $batch = $enrollment->batch;
        $course = $batch?->course;

        return [
            'branch_id' => $student->getAttribute('branch_id'),
            'student_id' => $student->getKey(),
            'student_batch_enrollment_id' => $enrollment->getKey(),
            'course_id' => $course?->getKey(),
            'batch_id' => $batch?->getKey(),

            'student_name_snapshot' => $student->getAttribute('name'),
            'father_name_snapshot' => $student->getAttribute('father_name'),
            'student_code_snapshot' => $student->getAttribute('student_code'),
            'registration_number_snapshot' => $student->getAttribute('registration_number'),
            'course_name_snapshot' => $course?->getAttribute('name'),
            'batch_name_snapshot' => $batch?->getAttribute('name') ?? $batch?->getAttribute('code'),
            'joining_date_snapshot' => $student->getAttribute('joining_date'),
            'guardian_phone_snapshot' => $student->getAttribute('guardian_phone'),

            'notes' => isset($data['notes']) ? mb_substr(trim((string) $data['notes']), 0, 500) : null,
        ];
    }

    /**
     * `issued_on + institute.id_card_validity_months`, or **null when that is zero**.
     *
     * Null means no expiry, which is a deliberate configuration rather than a missing value — an
     * institute that does not date its cards should not have every card swept as expired on day one.
     */
    private function validUntil(array $data): ?string
    {
        if (array_key_exists('valid_until', $data)) {
            return $data['valid_until'] === null ? null : (string) $data['valid_until'];
        }

        $months = (int) setting('institute.id_card_validity_months', 12);

        return $months <= 0 ? null : Carbon::now()->addMonths($months)->toDateString();
    }

    private function freshCode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $this->qr->newCode();

            $taken = StudentIdCard::query()->withTrashed()->where('verification_code', $code)->exists();

            if (! $taken) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate an unused verification code after ten attempts.');
    }

    private function defaultTemplateId(): ?int
    {
        $template = PrintTemplate::query()
            ->where('type', PrintTemplateType::StudentIdCard->value)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first(['id']);

        return $template === null ? null : (int) $template->getKey();
    }
}
