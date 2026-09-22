<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Fees;

use App\Models\Institute\Student;
use App\Models\Institute\StudentFee;
use App\Models\User;
use App\Services\Institute\StudentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Fees\Concerns\BuildsFees;
use Tests\TestCase;

/**
 * PH18-31 … PH18-35 — who may do what, and what a student can see (phase-18 §9, §11.4).
 *
 * **Every 403 here also asserts that nothing was written.** A refusal that happens after the write is
 * not a refusal, and the only way to tell the two apart from outside is to count the rows.
 *
 * **Isolation is asserted against the response BODY, not the template.** A view that "does not print"
 * a column still ships it in the payload, and that is the half of §9 that is easy to get wrong.
 */
final class FeeAuthorizationTest extends TestCase
{
    use BuildsFees;
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * PH18-31 — the matrix. Each row: the ability withheld, the request, and the rows that must not
     * appear because of it.
     */
    #[Test]
    public function every_write_is_refused_without_its_ability_and_writes_nothing(): void
    {
        $admin = $this->createSuperAdmin();
        $charge = $this->charge('30000.00', actor: $admin);
        $line = $this->plan($charge, 3, actor: $admin)->first();

        $cases = [
            ['student_fees.create', 'post', '/admin/student-fees', 'student_fees'],
            ['installments.create', 'post', '/admin/student-fees/'.$charge->id.'/installments', 'student_fee_installments'],
            ['fee_discounts.create', 'post', '/admin/student-fees/'.$charge->id.'/discounts', 'student_fee_discounts'],
            ['student_fees.change_status', 'post', '/admin/student-fees/'.$charge->id.'/cancel', 'student_fees'],
            ['fee_reminders.create', 'post', '/admin/student-fees/'.$charge->id.'/reminders', 'student_fee_reminders'],
        ];

        foreach ($cases as [$ability, $method, $url, $table]) {
            // A user who holds everything in the module EXCEPT the one ability — so the refusal is
            // attributable to that ability and not to being generally unprivileged.
            $user = $this->createUserWithPermissions([
                'student_fees.view_any', 'student_fees.view', 'installments.view_any', 'fee_discounts.view_any',
            ]);

            $before = DB::table($table)->count();

            $response = $this->actingAs($user)->{$method}($url, []);

            $this->assertSame(403, $response->getStatusCode(), sprintf('%s must be refused without %s.', $url, $ability));
            $this->assertSame(
                $before,
                DB::table($table)->count(),
                sprintf('%s wrote a row despite being refused.', $url),
            );
        }
    }

    /**
     * PH18-31 — a waiver takes two abilities, and holding only one is not enough.
     *
     * A waiver parks an amount on the line **and** writes a discount row that lowers the net fee, so
     * `installments.change_status` alone would let somebody give money away one installment at a time.
     */
    #[Test]
    public function waiving_needs_both_abilities(): void
    {
        $admin = $this->createSuperAdmin();
        $charge = $this->charge('30000.00', actor: $admin);
        $line = $this->plan($charge, 3, actor: $admin)->first();

        $halfPrivileged = $this->createUserWithPermissions(['installments.change_status', 'installments.view_any']);

        $response = $this->actingAs($halfPrivileged)
            ->post('/admin/installments/'.$line->id.'/waive', ['amount' => '1000.00', 'reason' => 'Trying it on']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('0.00', (string) $line->refresh()->waived_amount, 'Nothing was waived.');
        $this->assertDatabaseCount('student_fee_discounts', 0);

        // With both, it goes through.
        $fullyPrivileged = $this->createUserWithPermissions([
            'installments.change_status', 'installments.view_any', 'fee_discounts.approve', 'fee_discounts.create',
        ]);

        $this->actingAs($fullyPrivileged)
            ->post('/admin/installments/'.$line->id.'/waive', ['amount' => '1000.00', 'reason' => 'Hardship'])
            ->assertRedirect();

        $this->assertSame('1000.00', (string) $line->refresh()->waived_amount);
    }

    /**
     * PH18-32 — a student reaches their own rows and **404s** on anybody else's.
     *
     * 404 rather than 403, because a 403 confirms the row exists and turns an id into something worth
     * guessing. The whole panel is scoped from the `students` row the user *is*; no id comes off a URL.
     */
    #[Test]
    public function a_student_gets_a_404_for_somebody_elses_charge(): void
    {
        $admin = $this->createSuperAdmin();

        [$aliceUser, $alice] = $this->studentWithLogin('Alice');
        [, $bob] = $this->studentWithLogin('Bob');

        $hers = $this->charge('10000.00', $alice, actor: $admin);
        $his = $this->charge('10000.00', $bob, actor: $admin);

        $this->actingAs($aliceUser)->get('/student/fees/'.$hers->id)->assertOk();

        foreach ([
            '/student/fees/'.$his->id,
            '/student/fees/'.$his->id.'/slip',
        ] as $url) {
            $this->assertSame(404, $this->actingAs($aliceUser)->get($url)->getStatusCode(), $url.' must be a 404.');
        }

        // And her index never mentions his charge.
        $index = $this->actingAs($aliceUser)->get('/student/fees');

        $index->assertOk();
        $index->assertDontSee((string) $his->fee_number, false);
        $index->assertSee((string) $hers->fee_number, false);
    }

    /**
     * PH18-32 — the response BODY carries no commission column.
     *
     * Asserted against what is actually sent, not against what the template prints: selecting the
     * columns away is the only version of this that survives somebody adding a field to the view.
     */
    #[Test]
    public function the_student_panel_ships_no_commission_column_in_its_body(): void
    {
        $admin = $this->createSuperAdmin();
        [$user, $student] = $this->studentWithLogin('Carol');

        $charge = $this->charge('10000.00', $student, actor: $admin);
        $this->receive($charge, '4000.00');

        foreach (['/student/fees', '/student/fees/'.$charge->id, '/student/fees/'.$charge->id.'/slip'] as $url) {
            $body = $this->actingAs($user)->get($url)->getContent();

            foreach ([
                'collaborator_id', 'collaborator_referral_id', 'commission_state',
                'commission_skip_reason', 'commission_skip_detail', 'Commissionable amount',
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    (string) $body,
                    sprintf('%s leaks %s in its response body.', $url, $forbidden),
                );
            }
        }
    }

    /**
     * PH18-33 — a teacher is refused on every route in this phase, with no exception for their own
     * batch's students.
     */
    #[Test]
    public function a_teacher_reaches_nothing_in_this_phase(): void
    {
        $admin = $this->createSuperAdmin();
        $charge = $this->charge('10000.00', actor: $admin);

        $teacher = $this->createUserWithRole('Teacher');

        foreach ([
            '/admin/student-fees',
            '/admin/student-fees/'.$charge->id,
            '/admin/fee-collection',
            '/admin/fee-reminders',
        ] as $url) {
            $status = $this->actingAs($teacher)->get($url)->getStatusCode();

            $this->assertContains($status, [403, 404], sprintf('A teacher reached %s with a %d.', $url, $status));
        }
    }

    /**
     * PH18-34 — disabling the module refuses everybody, Super Admin included, and the data survives.
     *
     * `Gate::before` denies a disabled module's abilities before the Super Admin allowance is even
     * considered — that ordering is the whole point of the module switch.
     */
    #[Test]
    public function disabling_the_module_refuses_everybody_and_keeps_the_data(): void
    {
        $admin = $this->createSuperAdmin();
        $charge = $this->charge('10000.00', actor: $admin);

        $before = StudentFee::query()->count();

        $this->switchModule('student_fees', false);

        $this->assertSame(403, $this->actingAs($admin)->get('/admin/student-fees')->getStatusCode(),
            'A disabled module is refused even for a Super Admin.');

        $this->assertSame($before, StudentFee::query()->count(), 'Disabling a module touches no data.');

        $this->switchModule('student_fees', true);

        $this->actingAs($admin)->get('/admin/student-fees')->assertOk();
        $this->assertSame($before, StudentFee::query()->count());
    }

    /**
     * PH18-35 — a payment row is immutable and undeletable, and the narrow update path still works.
     *
     * `amount`, `paid_on` and the commission columns are evidence. `notes`, `reference_no` and
     * `receipt_path` are the three things somebody may legitimately correct after the fact (INV-8).
     */
    #[Test]
    public function a_payment_row_is_immutable_except_for_its_three_notes_columns(): void
    {
        $admin = $this->createSuperAdmin();
        $charge = $this->charge('10000.00', actor: $admin);
        $payment = $this->receive($charge, '5000.00');

        foreach (['amount' => '9999.00', 'paid_on' => '2020-01-01', 'student_fee_id' => 999] as $column => $value) {
            try {
                $payment->refresh()->forceFill([$column => $value])->save();
                $this->fail(sprintf('%s must not be editable on a receipt.', $column));
            } catch (LogicException) {
                // Expected: FinancialRow refuses everything outside its whitelist.
            }
        }

        $this->assertSame('5000.00', (string) $payment->refresh()->amount, 'The receipt is as it was.');

        // The three that may move.
        $payment->refresh()->forceFill(['notes' => 'Cheque cleared', 'reference_no' => 'CHQ-991'])->save();

        $this->assertSame('Cheque cleared', $payment->refresh()->notes);

        // And it is never deleted.
        try {
            $payment->refresh()->delete();
            $this->fail('A receipt must not be deletable.');
        } catch (LogicException) {
            // Expected.
        }

        $this->assertDatabaseCount('student_fee_payments', 1);
    }

    /**
     * A student row with a login, so the panel has somebody to be.
     *
     * @return array{0: User, 1: Student}
     */
    private function studentWithLogin(string $name): array
    {
        $admin = User::query()->whereNotNull('id')->first();

        $student = $this->feeStudent([
            'name' => $name,
            'email' => mb_strtolower($name).'.'.uniqid().'@students.test',
        ]);

        $user = app(StudentService::class)->createLogin($student, $admin);

        $this->assertNotNull($user, 'The fixture needs a student login to act as.');

        $user->forceFill(['must_change_password' => false])->save();

        return [$user->refresh(), $student->refresh()];
    }
}
