<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\DataObjects\Finance\InvoiceData;
use App\Enums\DiscountMode;
use App\Enums\InvoiceStatus;
use App\Models\Finance\Invoice;
use App\Services\Finance\InvoiceService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsInvoiceFixtures;
use Tests\TestCase;

/**
 * The invoice document (phase-13 §2.4, §2.7, §2.8, §2.9, §6.1).
 *
 * Three things are proved here and nothing else is worth proving first. **The arithmetic balances** —
 * the five identities of §2.7, re-read from the database rather than taken from the service's own
 * return value. **The number is gap-free** — a draft carries none, an abandoned draft consumes none,
 * and a cancelled invoice keeps the one it has for ever. And **the status is derived**, so the same
 * ladder gives the same answer whoever asks.
 */
final class InvoiceTest extends TestCase
{
    use BuildsInvoiceFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting('finance.tax_enabled', true);
        $this->setting('finance.tax_label', 'GST');
        $this->setting('finance.default_tax_rate', '17.0000');
        $this->setting('finance.payment_terms_days', 14);
        $this->setting('finance.invoice_round_off_enabled', false);
        $this->setting('finance.invoice_allow_edit_after_issue', true);
        $this->setting('finance.invoice_prefix', 'INV-');
    }

    /*
    |--------------------------------------------------------------------------
    | The arithmetic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_line_is_quantised_before_anything_else(): void
    {
        // 1.5 × 3,333.33 is 5,000.00 on the page, not 4,999.995 rounded at the end into a total that
        // disagrees with the column above it.
        $invoice = $this->draftInvoice(lines: [$this->line('3333.33', '1.5000')]);

        $this->assertSame('5000.00', (string) $invoice->subtotal_amount);
        $this->assertSame('5000.00', (string) $invoice->total_amount);
        $this->assertInvoiceBalances($invoice);
    }

    #[Test]
    public function tax_is_charged_after_both_discounts_and_never_on_an_exempt_line(): void
    {
        $invoice = $this->draftInvoice(lines: [
            $this->line('10000.00', '1.0000', ['is_taxable' => true, 'tax_rate' => '17.0000']),
            $this->line('5000.00', '1.0000', ['is_taxable' => false]),
        ], options: ['discount_mode' => DiscountMode::Percentage, 'discount_rate' => '10.0000']);

        // 15,000 subtotal, 1,500 off, so the taxable line's share is 1,000 and it is taxed on 9,000.
        $this->assertSame('15000.00', (string) $invoice->subtotal_amount);
        $this->assertSame('1500.00', (string) $invoice->discount_amount);
        $this->assertSame('9000.00', (string) $invoice->taxable_amount);
        $this->assertSame('1530.00', (string) $invoice->tax_amount);
        $this->assertSame('15030.00', (string) $invoice->total_amount);

        $exempt = DB::table('invoice_items')->where('invoice_id', $invoice->getKey())->orderByDesc('id')->first();

        $this->assertSame('0.00', (string) $exempt->tax_amount);
        $this->assertSame('0.00', (string) $exempt->taxable_amount,
            'An exempt line is unaffected by a discount given on a taxable one.');

        $this->assertInvoiceBalances($invoice);
    }

    /** The apportionment exists so a discount that does not divide evenly still adds up. */
    #[Test]
    public function an_invoice_discount_is_apportioned_to_the_paisa(): void
    {
        $invoice = $this->draftInvoice(lines: [
            $this->line('100.00'), $this->line('100.00'), $this->line('100.00'),
        ], options: ['discount_mode' => DiscountMode::Fixed, 'discount_fixed' => '10.00']);

        $shares = DB::table('invoice_items')
            ->where('invoice_id', $invoice->getKey())
            ->orderBy('sort_order')
            ->pluck('allocated_discount_amount')
            ->map(fn ($v): string => (string) $v)
            ->all();

        $this->assertSame(['3.33', '3.34', '3.33'], $shares);
        $this->assertSame('10.00', Money::sum($shares));
        $this->assertSame('290.00', (string) $invoice->total_amount);
        $this->assertInvoiceBalances($invoice);
    }

    /** The round-off is signed, and printed as its own line rather than hidden in the total. */
    #[Test]
    public function rounding_is_off_by_default_and_signed_when_it_is_on(): void
    {
        $lines = [$this->line('1234.56')];

        $this->assertSame('1234.56', (string) $this->draftInvoice(lines: $lines)->total_amount);

        $this->setting('finance.invoice_round_off_enabled', true);
        $this->setting('finance.invoice_rounding_precision', '10');

        $rounded = $this->draftInvoice(lines: $lines);

        $this->assertSame('1230.00', (string) $rounded->total_amount);
        $this->assertSame('-4.56', (string) $rounded->round_off_amount);
        $this->assertInvoiceBalances($rounded);
    }

    /** The tax snapshot is a fact about the day it was raised. */
    #[Test]
    public function changing_the_tax_rate_never_rewrites_an_invoice_already_raised(): void
    {
        $invoice = $this->draftInvoice(lines: [
            $this->line('10000.00', '1.0000', ['is_taxable' => true]),
        ]);

        $this->assertSame('17.0000', (string) $invoice->tax_rate);
        $this->assertSame('1700.00', (string) $invoice->tax_amount);

        $this->setting('finance.default_tax_rate', '5.0000');

        app(InvoiceService::class)->recalculate($invoice->fresh());

        $this->assertSame('1700.00', (string) $invoice->fresh()->tax_amount,
            'The rate lives on the line, snapshotted from the invoice, not read from settings at render time.');
    }

    /*
    |--------------------------------------------------------------------------
    | The number
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_draft_has_no_number_and_an_abandoned_one_consumes_none(): void
    {
        $abandoned = $this->draftInvoice();

        $this->assertNull($abandoned->invoice_number);
        $this->assertSame('DRAFT-'.$abandoned->getKey(), $abandoned->draft_reference);

        $abandoned->delete();

        $issued = app(InvoiceService::class)->issue($this->draftInvoice(), $this->createSuperAdmin());

        $this->assertSame('INV-000001', $issued->invoice_number,
            'The abandoned draft took no number with it: that is the whole of the gap-free rule.');
    }

    #[Test]
    public function a_cancelled_invoice_keeps_its_number_for_ever(): void
    {
        $actor = $this->createSuperAdmin();
        $invoice = app(InvoiceService::class)->issue($this->draftInvoice(), $actor);
        $number = $invoice->invoice_number;

        app(InvoiceService::class)->cancel($invoice, 'Client withdrew the order', $actor);

        $this->assertSame($number, $invoice->fresh()->invoice_number,
            'A reused number makes two documents answer to one reference in a dispute.');
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);

        $next = app(InvoiceService::class)->issue($this->draftInvoice(), $actor);

        $this->assertSame('INV-000002', $next->invoice_number, 'The series moves on; the cancelled number is not reused.');
    }

    #[Test]
    public function an_invoice_for_nothing_cannot_be_issued(): void
    {
        $empty = app(InvoiceService::class)->create(new InvoiceData(
            clientId: (int) $this->billingClient()->getKey(),
            issueDate: Carbon::now(),
            lines: [],
        ));

        $this->expectExceptionMessageMatches('/no lines/');

        app(InvoiceService::class)->issue($empty, $this->createSuperAdmin());
    }

    #[Test]
    public function a_zero_invoice_cannot_be_issued_either(): void
    {
        $zero = $this->draftInvoice(lines: [$this->line('0.00')]);

        $this->assertSame('0.00', (string) $zero->total_amount);

        $this->expectExceptionMessageMatches('/An invoice for nothing/');

        app(InvoiceService::class)->issue($zero, $this->createSuperAdmin());
    }

    /*
    |--------------------------------------------------------------------------
    | The status ladder
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_status_is_derived_from_five_facts_and_never_typed(): void
    {
        $service = app(InvoiceService::class);
        $actor = $this->createSuperAdmin();

        // Issued a few days ago, so the due date can later move into the past without tripping
        // `chk_inv_dates` — which correctly refuses a bill due before it was raised.
        $invoice = $this->draftInvoice(options: [
            'issue_date' => now()->subDays(3)->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);

        $invoice = $service->markSent($invoice, ['buyer@example.test'], $actor);
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame(1, (int) $invoice->sent_count);

        // A part payment, applied the way the register applies one.
        DB::table('invoices')->where('id', $invoice->getKey())->update([
            'paid_amount' => '4000.00',
            'balance_amount' => Money::sub((string) $invoice->total_amount, '4000.00'),
        ]);

        $this->assertSame(InvoiceStatus::Partial, $service->recomputeStatus($invoice->fresh())->status);

        // Past due beats part-paid: it is a claim somebody has to chase.
        DB::table('invoices')->where('id', $invoice->getKey())->update([
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame(InvoiceStatus::Overdue, $service->recomputeStatus($invoice->fresh())->status);

        DB::table('invoices')->where('id', $invoice->getKey())->update([
            'paid_amount' => (string) $invoice->total_amount,
            'balance_amount' => '0.00',
        ]);

        $this->assertSame(InvoiceStatus::Paid, $service->recomputeStatus($invoice->fresh())->status);
    }

    /** An overpaid invoice is paid with a negative balance, and the credit is visible. */
    #[Test]
    public function an_overpaid_invoice_reads_as_paid_and_shows_the_credit(): void
    {
        $service = app(InvoiceService::class);
        $invoice = $service->markSent($this->draftInvoice(), ['buyer@example.test'], $this->createSuperAdmin());

        DB::table('invoices')->where('id', $invoice->getKey())->update([
            'paid_amount' => '12000.00',
            'balance_amount' => Money::sub((string) $invoice->total_amount, '12000.00'),
        ]);

        $settled = $service->recomputeStatus($invoice->fresh());

        $this->assertSame(InvoiceStatus::Paid, $settled->status);
        $this->assertTrue($settled->isOverpaid());
        $this->assertSame('-2000.00', (string) $settled->balance_amount);
    }

    /*
    |--------------------------------------------------------------------------
    | Correcting one
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_invoice_with_money_against_it_is_not_corrected_in_place(): void
    {
        $service = app(InvoiceService::class);
        $actor = $this->createSuperAdmin();
        $invoice = $service->markSent($this->draftInvoice(), ['buyer@example.test'], $actor);

        DB::table('invoices')->where('id', $invoice->getKey())->update(['paid_amount' => '1000.00']);

        $this->expectExceptionMessageMatches('/cancel it and issue a replacement/i');

        $service->update($invoice->fresh(), new InvoiceData(
            clientId: (int) $invoice->client_id,
            issueDate: Carbon::now(),
            lines: [$this->line('999.00')],
        ), $actor);
    }

    #[Test]
    public function cancelling_is_refused_while_money_has_been_received(): void
    {
        $service = app(InvoiceService::class);
        $actor = $this->createSuperAdmin();
        $invoice = $service->markSent($this->draftInvoice(), ['buyer@example.test'], $actor);

        DB::table('invoices')->where('id', $invoice->getKey())->update(['paid_amount' => '1000.00']);

        $this->expectExceptionMessageMatches('/Refund the receipts first/');

        $service->cancel($invoice->fresh(), 'Client changed their mind', $actor);
    }

    #[Test]
    public function a_replacement_points_back_at_what_it_replaces(): void
    {
        $service = app(InvoiceService::class);
        $actor = $this->createSuperAdmin();

        $original = $service->issue($this->draftInvoice(), $actor);
        $service->cancel($original, 'Wrong rate agreed with the client', $actor);

        $replacement = $service->replace($original->fresh(), new InvoiceData(
            clientId: (int) $original->client_id,
            issueDate: Carbon::now(),
            lines: [$this->line('12000.00')],
        ), $actor);

        $this->assertSame((int) $original->getKey(), (int) $replacement->replaces_invoice_id);
        $this->assertNull($replacement->invoice_number, 'A replacement starts as a draft, like any other.');
        $this->assertSame('12000.00', (string) $replacement->total_amount);
    }

    #[Test]
    public function a_duplicate_carries_the_lines_and_none_of_the_history(): void
    {
        $actor = $this->createSuperAdmin();
        $original = app(InvoiceService::class)->issue($this->draftInvoice(lines: [
            $this->line('7000.00'), $this->line('3000.00'),
        ]), $actor);

        $copy = app(InvoiceService::class)->duplicate($original, $actor);

        $this->assertNull($copy->invoice_number);
        $this->assertNull($copy->issued_at);
        $this->assertNull($copy->sent_at);
        $this->assertSame(InvoiceStatus::Draft, $copy->status);
        $this->assertSame('10000.00', (string) $copy->total_amount);
        $this->assertSame(2, $copy->items()->count());
        $this->assertInvoiceBalances($copy);
    }

    /** Regenerating the link is what revokes the copies already emailed. */
    #[Test]
    public function regenerating_the_public_token_needs_a_reason_and_changes_it(): void
    {
        $actor = $this->createSuperAdmin();
        $invoice = app(InvoiceService::class)->issue($this->draftInvoice(), $actor);

        $before = DB::table('invoices')->where('id', $invoice->getKey())->value('public_token');
        $this->assertNotNull($before);

        try {
            app(InvoiceService::class)->regeneratePublicToken($invoice, '  ', $actor);
            $this->fail('A link was revoked without a reason.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Say why', $e->getMessage());
        }

        app(InvoiceService::class)->regeneratePublicToken($invoice, 'Sent to the wrong address', $actor);

        $this->assertNotSame($before, DB::table('invoices')->where('id', $invoice->getKey())->value('public_token'));
    }

    /** Reading a bill is not paying it. */
    #[Test]
    public function marking_it_viewed_stamps_once_and_changes_no_status(): void
    {
        $actor = $this->createSuperAdmin();
        $invoice = app(InvoiceService::class)->markSent($this->draftInvoice(), ['buyer@example.test'], $actor);

        app(InvoiceService::class)->markViewed($invoice);
        $first = $invoice->fresh()->viewed_at;

        $this->assertNotNull($first);

        app(InvoiceService::class)->markViewed($invoice->fresh());

        $this->assertEquals($first, $invoice->fresh()->viewed_at, 'It is the first time, not the last.');
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    /** The due date comes from the snapshotted terms, not from today's setting. */
    #[Test]
    public function the_due_date_defaults_from_the_terms_snapshot(): void
    {
        $invoice = $this->draftInvoice(options: ['issue_date' => '2026-04-01']);

        $this->assertSame(14, (int) $invoice->payment_terms_days);
        $this->assertSame('2026-04-15', $invoice->due_date->toDateString());

        $this->setting('finance.payment_terms_days', 30);

        $this->assertSame('2026-04-15', $invoice->fresh()->due_date->toDateString(),
            'A settings change never moves the due date of an invoice already raised.');
    }

    /**
     * Write a setting the way the system context does.
     */
    protected function setting(string $key, mixed $value): void
    {
        settings_repo()->asSystem(fn ($settings) => $settings->set($key, $value));
    }
}
