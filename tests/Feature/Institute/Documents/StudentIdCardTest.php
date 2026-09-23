<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Documents;

use App\Enums\IdCardStatus;
use App\Models\Institute\StudentIdCard;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Documents\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Issuing, replacing and retiring a student card (§85, phase-19-23 §6.15, INV-21-4, §11).
 *
 * **A card is a physical object, and the register is shaped by that.** There is no draft: it is
 * numbered, snapshotted and printed in one step. What there is instead is a rich set of ways for it
 * to stop being valid, because each one means something different to the office holding the register
 * — and nothing is ever deleted, because every row is a card that existed in the world.
 */
final class StudentIdCardTest extends TestCase
{
    use BuildsAdmissions;
    use BuildsCatalogue;
    use BuildsDocuments;
    use BuildsRegisters;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function issuing_numbers_the_card_and_freezes_what_is_on_it(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $card = $this->issuedCard($seat, [], $admin);

        $this->assertSame(IdCardStatus::Active, $card->status);
        $this->assertNotNull($card->getAttribute('card_number'));
        $this->assertSame(16, mb_strlen((string) $card->getAttribute('verification_code')));
        // Stored, so a printed QR points where it pointed on the day it was printed.
        $this->assertStringContainsString(
            (string) $card->getAttribute('verification_code'),
            (string) $card->getAttribute('qr_payload'),
        );
    }

    #[Test]
    public function the_photograph_is_copied_so_a_new_profile_picture_cannot_change_a_card(): void
    {
        // INV-21-4. A card in somebody's wallet must not change because they uploaded a new picture.
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $card = $this->issuedCard($seat, [], $admin);

        $cardPhoto = (string) $card->getAttribute('photo_path');
        $studentPhoto = (string) $seat->student?->getAttribute('photo_path');

        $this->assertNotSame('', $cardPhoto);
        $this->assertNotSame($studentPhoto, $cardPhoto);
        $this->assertTrue(Storage::disk('private')->exists($cardPhoto));

        // Its own copy, so replacing the student's file leaves the card's alone.
        Storage::disk('private')->delete($studentPhoto);

        $this->assertTrue(Storage::disk('private')->exists($cardPhoto));
    }

    #[Test]
    public function a_card_is_refused_when_the_institute_requires_a_photograph_and_there_is_none(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        // A card with no photo is not the card the institute intended to issue, so this is a refusal
        // rather than a shrug.
        $this->expectException(CourseRuleException::class);

        $this->cardService()->issue($seat, [], $admin);
    }

    #[Test]
    public function a_student_holds_one_live_card_and_issuing_another_retires_the_first(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $first = $this->issuedCard($seat, [], $admin);
        $second = $this->issuedCard($seat, [], $admin);

        // `uq_sic_live` permits one. The office's intent when they issue a replacement is
        // unambiguous, so the predecessor is retired in the same transaction rather than colliding.
        $this->assertNotSame(IdCardStatus::Active, $first->refresh()->status);
        $this->assertSame(IdCardStatus::Active, $second->refresh()->status);

        $this->assertSame(
            1,
            StudentIdCard::query()
                ->where('student_id', $seat->getAttribute('student_id'))
                ->where('status', IdCardStatus::Active->value)
                ->count(),
        );
    }

    #[Test]
    public function a_replacement_points_back_at_the_card_it_replaces(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $original = $this->issuedCard($seat, [], $admin);

        // An *active* card is not replaced from here — issuing another retires it in the same
        // transaction, which is what the office means when they issue a replacement for a card
        // still in somebody's pocket. `replace()` is for one that has stopped being live.
        $this->cardService()->changeStatus($original, IdCardStatus::Lost, 'Left on a bus.', $admin);

        $replacement = $this->cardService()->replace($original->refresh(), 'Snapped in half.', $admin);

        $this->assertSame((int) $original->getKey(), (int) $replacement->getAttribute('replacement_of_id'));
        $this->assertSame(IdCardStatus::Replaced, $original->refresh()->status);
        $this->assertSame(IdCardStatus::Active, $replacement->status);

        // Both stay on the register: it is a history, not a list of what is currently in wallets.
        $this->assertSame(
            2,
            StudentIdCard::query()->where('student_id', $seat->getAttribute('student_id'))->count(),
        );
    }

    #[Test]
    public function replacing_needs_a_reason(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $card = $this->issuedCard($seat, [], $admin);
        $this->cardService()->changeStatus($card, IdCardStatus::Lost, 'Left on a bus.', $admin);

        $this->expectException(CourseRuleException::class);

        $this->cardService()->replace($card->refresh(), '  ', $admin);
    }

    #[Test]
    public function one_card_can_only_be_replaced_once(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $card = $this->issuedCard($seat, [], $admin);
        $this->cardService()->changeStatus($card, IdCardStatus::Lost, 'Left on a bus.', $admin);

        $this->cardService()->replace($card->refresh(), 'Lost.', $admin);

        // `uq_sic_replacement` — a chain, not a fan.
        $this->expectException(\Throwable::class);

        $this->cardService()->replace($card->refresh(), 'Lost again.', $admin);
    }

    #[Test]
    public function a_card_can_be_marked_lost_damaged_expired_or_revoked(): void
    {
        $admin = $this->createSuperAdmin();
        [$batch] = $this->batchWithOneStudent($admin);

        foreach ([IdCardStatus::Lost, IdCardStatus::Damaged, IdCardStatus::Expired, IdCardStatus::Revoked] as $status) {
            $seat = $this->seat($batch, null, [], $admin);
            $card = $this->issuedCard($seat, [], $admin);

            $this->cardService()->changeStatus($card, $status, 'Recorded by a test.', $admin);

            $this->assertSame($status, $card->refresh()->status);
        }
    }

    #[Test]
    public function a_card_is_never_deleted(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $card = $this->issuedCard($seat, [], $admin);

        // Every row is a card that existed in the world. There is no destroy route and no delete
        // ability either — this is the third net, below both.
        $this->expectException(LogicException::class);

        $card->delete();
    }

    #[Test]
    public function a_card_with_no_expiry_is_a_setting_and_not_a_missing_value(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $card = $this->issuedCard($seat, ['valid_until' => null], $admin);

        // An institute that does not date its cards should not have every card swept as expired on
        // day one.
        $this->assertNull($card->getAttribute('valid_until'));

        $this->assertSame(0, $this->cardService()->sweepExpired());
        $this->assertSame(IdCardStatus::Active, $card->refresh()->status);
    }

    #[Test]
    public function the_sweep_expires_a_card_that_is_past_its_date_and_leaves_the_rest(): void
    {
        $admin = $this->createSuperAdmin();
        [$batch] = $this->batchWithOneStudent($admin);

        // Both students are enrolled **now**. `students.student_code` carries the year and its
        // counter is per-year, so seating somebody inside the time travel below hands the next
        // student of the real year a code the first one already took.
        $expiringSeat = $this->seat($batch, null, [], $admin);
        $currentSeat = $this->seat($batch, null, [], $admin);

        // `chk_sic_valid` refuses a card whose expiry precedes its issue date, which is correct —
        // so the card that has expired is *issued in the past*, the way a real one would be, rather
        // than back-dated into a shape the application could never produce.
        Carbon::setTestNow(Carbon::today()->subYear());

        $expiring = $this->issuedCard(
            $expiringSeat,
            ['valid_until' => Carbon::today()->addDays(2)->toDateString()],
            $admin,
        );

        Carbon::setTestNow();

        $current = $this->issuedCard(
            $currentSeat,
            ['valid_until' => Carbon::today()->addYear()->toDateString()],
            $admin,
        );

        $this->assertSame(1, $this->cardService()->sweepExpired());

        $this->assertSame(IdCardStatus::Expired, $expiring->refresh()->status);
        $this->assertSame(IdCardStatus::Active, $current->refresh()->status);
    }

    #[Test]
    public function printing_counts_the_copies(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $card = $this->issuedCard($seat, [], $admin);

        $this->cardService()->markPrinted($card, $admin);
        $this->cardService()->markPrinted($card->refresh(), $admin);

        $this->assertSame(2, (int) $card->refresh()->getAttribute('print_count'));
    }

    #[Test]
    public function the_card_prints_the_frozen_name_and_the_institutes_current_phone_number(): void
    {
        // The student's details come off the snapshots; the institute's do not. A card is a way to
        // reach the institute *today*, so printing last year's disconnected number would make it
        // worse, not more faithful.
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $card = $this->issuedCard($seat, [], $admin);

        $tokens = $this->cardService()->tokensFor($card);

        $this->assertSame((string) $card->getAttribute('student_name_snapshot'), $tokens['student_name']);
        $this->assertArrayHasKey('branch_phone', $tokens);

        // The photograph arrives as a data: URI, so dompdf never makes a network request for it.
        $this->assertStringContainsString('data:image/png;base64,', $tokens['photo']);
    }
}
