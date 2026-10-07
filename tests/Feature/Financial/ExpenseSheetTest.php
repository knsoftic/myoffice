<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\DataObjects\Finance\ExpenseSheet;
use App\Models\User;
use App\Services\Finance\ExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsInvoiceFixtures;
use Tests\TestCase;

/**
 * Expense Sheet & Analytics (`admin.expense-sheet.index`): the register's filtered set by day, week and
 * month, pivoted by category, split by category and by status (D178).
 *
 * The fixture, with today frozen at Wednesday 18 March 2026 and the range 23 Feb – 08 Mar:
 *
 *   Fri 27 Feb  Rent         1000.00  approved
 *   Sun 01 Mar  Rent          100.00  approved   (decides which week it falls in)
 *   Mon 02 Mar  Rent          500.00  pending
 *   Tue 03 Mar  Utilities     250.00  approved   ("Electricity")
 *   Wed 04 Mar  Rent         4000.00  rejected   (not spend unless filtered for)
 */
final class ExpenseSheetTest extends TestCase
{
    use BuildsInvoiceFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    private const RANGE = ['from' => '2026-02-23', 'to' => '2026-03-08'];

    private ?User $viewer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-03-18 10:00:00'));
        $this->setting('finance.expense_approval_required', true);
        $this->setting('finance.expense_approval_threshold', '0.00');
        $this->setting('finance.backdate_limit_days', 400);
        $this->setting('localization.week_start', 'monday');
    }

    #[Test]
    public function the_sheet_buckets_by_month_and_leaves_rejected_claims_out(): void
    {
        $this->fixture();

        $sheet = $this->sheet(self::RANGE + ['period' => 'month']);
        $months = $sheet->period('month')['rows'];

        $this->assertSame(['Feb 2026', 'Mar 2026'], array_column($months, 'label'));
        $this->assertSame(['1000.00', '850.00'], array_column($months, 'total'));
        $this->assertSame('1850.00', $sheet->totals['total']);
        $this->assertSame(4, $sheet->totals['count']);
        $this->assertSame('1350.00', $sheet->totals['statuses']['approved']);
        $this->assertSame('500.00', $sheet->totals['statuses']['pending']);

        // The status split still shows the rejected claim.
        $this->assertSame(['Approved', 'Awaiting approval', 'Rejected'], $sheet->statusSplit['labels']);
        $this->assertSame(['1350.00', '500.00', '4000.00'], $sheet->statusSplit['values']);
    }

    #[Test]
    public function weeks_follow_the_week_start_setting(): void
    {
        $this->fixture();

        $monday = $this->sheet(self::RANGE)->period('week')['rows'];
        $this->assertSame(['1100.00', '750.00'], array_column($monday, 'total'), 'Sunday 01 Mar closes the Monday week.');

        $this->setting('localization.week_start', 'sunday');

        $sunday = $this->sheet(self::RANGE)->period('week')['rows'];
        $this->assertSame(['1000.00', '850.00', '0.00'], array_column($sunday, 'total'), 'Sunday 01 Mar opens a week, and Sunday 08 Mar a third.');
        $this->assertSame('23 Feb – 28 Feb', $sunday[0]['label'], 'The first week is cut to the range.');
    }

    #[Test]
    public function every_day_of_the_range_is_a_row_even_an_empty_one(): void
    {
        $this->fixture();

        $days = $this->sheet(self::RANGE)->period('day')['rows'];

        $this->assertCount(14, $days);
        $this->assertSame('0.00', $days[0]['total']);
        $this->assertSame('1000.00', $days[4]['total']);
        $this->assertSame('250.00', $days[8]['cells']['c'.$this->expenseCategory('utilities')->getKey()]);
    }

    #[Test]
    public function the_filters_of_the_table_drive_the_sheet(): void
    {
        $this->fixture();

        $rejected = $this->sheet(self::RANGE + ['status' => 'rejected']);
        $this->assertSame('4000.00', $rejected->totals['total'], 'A Status filter shows exactly that status.');

        $utilities = $this->sheet(self::RANGE + ['category' => (string) $this->expenseCategory('utilities')->getKey()]);
        $this->assertSame('250.00', $utilities->totals['total']);

        $searched = $this->sheet(self::RANGE + ['q' => 'Electricity']);
        $this->assertSame('250.00', $searched->totals['total']);

        $narrow = $this->sheet(['from' => '2026-03-02', 'to' => '2026-03-03']);
        $this->assertSame('750.00', $narrow->totals['total']);
        $this->assertSame('day', $narrow->defaultPeriod);
    }

    #[Test]
    public function a_reader_without_view_financial_sees_counts_and_no_amount(): void
    {
        $this->fixture();
        $reader = $this->createUserWithPermissions(['expenses.view_any']);

        $response = $this->actingAs($reader)->get(route('admin.expense-sheet.index', self::RANGE));

        $response->assertOk();
        $response->assertSee('expenses.view_financial');
        $response->assertDontSee('1,850.00');
        $response->assertDontSee('1,350.00');

        $sheet = $response->viewData('sheet');
        $this->assertInstanceOf(ExpenseSheet::class, $sheet);
        $this->assertFalse($sheet->seesMoney);
        $this->assertSame(4, $sheet->totals['total'], 'Without the money permission every figure is a count.');
    }

    #[Test]
    public function the_sheet_has_the_same_door_as_the_table(): void
    {
        $this->get(route('admin.expense-sheet.index'))->assertRedirect();

        $stranger = $this->createUserWithPermissions(['income.view_any']);
        $this->actingAs($stranger)->get(route('admin.expense-sheet.index'))->assertForbidden();

        $admin = $this->createSuperAdmin();
        $this->switchModule('expenses', false);
        $this->assertSame(403, $this->actingAs($admin)->get(route('admin.expense-sheet.index'))->getStatusCode());
        $this->switchModule('expenses', true);
    }

    #[Test]
    public function an_empty_register_still_renders(): void
    {
        $response = $this->actingAs($this->createSuperAdmin())->get(route('admin.expense-sheet.index'));

        $response->assertOk();
        $response->assertSee('No expenses match these filters');
        $response->assertSee('Expense Sheet');
    }

    #[Test]
    public function junk_in_the_query_string_is_never_a_server_error(): void
    {
        $this->fixture();
        $admin = $this->createSuperAdmin();

        foreach ([
            ['status' => 'bogus'],
            ['from' => 'garbage', 'to' => '1 AND SLEEP(3)'],
            ['period' => 'fortnight'],
            ['q' => "' OR 1=1 --"],
            ['category' => 'abc', 'context' => '<script>'],
            ['from' => '2026-03-08', 'to' => '2026-02-23'],
        ] as $query) {
            $status = $this->actingAs($admin)->get(route('admin.expense-sheet.index', $query))->getStatusCode();
            $this->assertContains($status, [200, 302, 404, 422], 'Query '.json_encode($query).' answered '.$status);
        }
    }

    #[Test]
    public function both_pages_offer_the_view_switch_and_keep_the_filters(): void
    {
        $admin = $this->createSuperAdmin();
        $query = self::RANGE + ['q' => 'rent'];

        $index = $this->actingAs($admin)->get(route('admin.expenses.index', $query + ['page' => 2]));
        $index->assertOk();
        $index->assertSee('Sheet &amp; Analytics', false);
        $index->assertSee(e(route('admin.expense-sheet.index', $query)), false);

        $sheet = $this->actingAs($admin)->get(route('admin.expense-sheet.index', $query));
        $sheet->assertOk();
        $sheet->assertSee('Table View');
        $sheet->assertSee(e(route('admin.expenses.index', $query)), false);
    }

    /**
     * The five expenses of the class docblock.
     */
    private function fixture(): void
    {
        $approver = $this->createSuperAdmin();
        $expenses = app(ExpenseService::class);

        $expenses->approve($this->spend('1000.00', ['on' => '2026-02-27']), $approver);
        $expenses->approve($this->spend('100.00', ['on' => '2026-03-01']), $approver);
        $this->spend('500.00', ['on' => '2026-03-02']);
        $expenses->approve($this->spend('250.00', [
            'on' => '2026-03-03',
            'category' => $this->expenseCategory('utilities'),
            'title' => 'Electricity',
        ]), $approver);
        $expenses->reject($this->spend('4000.00', ['on' => '2026-03-04']), 'Not a business cost', $approver);
    }

    /**
     * The sheet the page was rendered with, as a Super Admin.
     *
     * @param  array<string, string>  $query
     */
    private function sheet(array $query): ExpenseSheet
    {
        $admin = $this->viewer ??= $this->createSuperAdmin();

        $response = $this->actingAs($admin)->get(route('admin.expense-sheet.index', $query));
        $response->assertOk();

        return $response->viewData('sheet');
    }

    protected function setting(string $key, mixed $value): void
    {
        settings_repo()->asSystem(fn ($settings) => $settings->set($key, $value));
    }
}
