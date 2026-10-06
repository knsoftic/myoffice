<?php

declare(strict_types=1);

namespace Tests\Feature\AdvancedReports;

use App\DataObjects\Finance\RefundData;
use App\DataObjects\Institute\IssueFeeData;
use App\DataObjects\Reporting\AdvancedReportFilters;
use App\DataObjects\Reporting\AdvancedReportRow;
use App\Enums\EnrollmentStatus;
use App\Enums\ExportFormat;
use App\Enums\ProgressStatus;
use App\Enums\ReportPaymentStatus;
use App\Enums\ReportStudentStatus;
use App\Enums\ReversalType;
use App\Enums\StudentFeeType;
use App\Enums\StudentStatus;
use App\Models\Branch;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeePayment;
use App\Models\Role;
use App\Models\User;
use App\Services\Institute\BatchEnrollmentService;
use App\Services\Reporting\AdvancedReportExporter;
use App\Services\Reporting\AdvancedStudentReportService;
use App\Support\Format;
use App\Support\Money;
use App\Support\Sidebar;
use App\Support\XlsxWriter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Fees\Concerns\BuildsFees;
use Tests\TestCase;
use ZipArchive;

/**
 * Advanced Reports — students, enrolments and fees on one filterable screen (D177).
 *
 * **One fixture, built the way the application builds one.** Every admission below walks the real
 * pipeline (`AdmissionService` create → register → assign a batch → activate → complete / withdraw),
 * every charge is raised by `StudentFeeService::issue()`, every receipt and refund goes through
 * `PaymentService`, and "overdue" is reached by the nightly sweep, `markOverdue()`. A report is only
 * as right as the rows it reads; a fixture that wrote the caches by hand would be testing a shape the
 * application never produces — and the admission caches refuse a write from outside the fee service
 * anyway.
 *
 * The cast, one row per admission (D172), so a student on two courses is two rows:
 *
 *   | label        | student         | course | batch  | joined     | report status | payment  | fee / net paid / remaining |
 *   |--------------|-----------------|--------|--------|------------|---------------|----------|----------------------------|
 *   | ayesha_web   | Ayesha Rahman   | Web    | WEB-A  | today      | Active        | Partial  | 30,000 / 13,000 / 17,000   |
 *   | bilal        | Bilal Chaudhry  | Web    | WEB-B  | last month | Completed     | Paid     | 20,000 / 20,000 / 0        |
 *   | danish       | Danish Qureshi  | Data   | DATA-A | last year  | Inactive      | Overdue  | 25,000 / 0 / 25,000        |
 *   | farah        | Farah Siddiqui  | Data   | —      | today      | Pending       | Unpaid   | no charges                 |
 *   | hamza        | Hamza Iqbal     | Data   | —      | last month | Dropped       | Unpaid   | no charges                 |
 *   | ayesha_data  | Ayesha Rahman   | Data   | —      | today      | Pending       | Unpaid   | no charges                 |
 *
 * Ayesha's 13,000 is two receipts (10,000 and 5,000) less a 2,000 partial refund, which is what the
 * running-balance test walks. Danish is suspended, which is what makes him Inactive rather than
 * Pending (`ReportStudentStatus`, rule 3).
 *
 * Assertions about *which rows* read the paginator the controller handed the view, not the HTML: the
 * filter form lists every course, batch and status by name, so "the page does not say X" proves
 * nothing about the table.
 */
final class AdvancedReportTest extends TestCase
{
    use BuildsFees;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    private const EVERYONE = ['ayesha_data', 'ayesha_web', 'bilal', 'danish', 'farah', 'hamza'];

    /*
    |--------------------------------------------------------------------------
    | Who may open it
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.advanced-reports.index'))->assertRedirect(route('login'));
        $this->get(route('admin.advanced-reports.export', ['format' => 'csv']))->assertRedirect(route('login'));
        $this->get(route('admin.advanced-reports.print'))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_user_without_the_permissions_is_refused_every_screen_and_endpoint(): void
    {
        $actor = $this->createSuperAdmin();
        $student = $this->student(['name' => 'Nobody Sees Me'], $actor);
        $nobody = $this->createUserWithPermissions([]);

        foreach ([
            route('admin.advanced-reports.index'),
            route('admin.advanced-reports.students.show', $student),
            route('admin.advanced-reports.batches.options'),
            route('admin.advanced-reports.export', ['format' => 'csv']),
            route('admin.advanced-reports.print'),
        ] as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }
    }

    /**
     * Each route asks for exactly one permission, and holding only that one is enough (SEC-21(b)). The
     * screen does not quietly demand `view_financial` or `reports.view_reports` as well.
     */
    #[Test]
    public function each_action_needs_its_own_permission_and_only_that_one(): void
    {
        $this->scenario();

        $reader = $this->reader();
        $this->actingAs($reader)->get(route('admin.advanced-reports.index'))->assertOk();
        $this->actingAs($reader)->get(route('admin.advanced-reports.export', ['format' => 'csv']))->assertForbidden();
        $this->actingAs($reader)->get(route('admin.advanced-reports.print'))->assertForbidden();

        $exporter = $this->createUserWithPermissions(['advanced_reports.export']);
        $this->actingAs($exporter)->get(route('admin.advanced-reports.export', ['format' => 'csv']))->assertOk();
        $this->actingAs($exporter)->get(route('admin.advanced-reports.index'))->assertForbidden();

        $printer = $this->createUserWithPermissions(['advanced_reports.print']);
        $this->actingAs($printer)->get(route('admin.advanced-reports.print'))->assertOk();
        $this->actingAs($printer)->get(route('admin.advanced-reports.export', ['format' => 'csv']))->assertForbidden();
    }

    #[Test]
    public function a_disabled_module_is_closed_even_to_a_super_admin(): void
    {
        $s = $this->scenario();
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)->get(route('admin.advanced-reports.index'))->assertOk();

        $this->switchModule('advanced_reports', false);
        $this->forgetPermissionCache();

        $this->actingAs($admin)->get(route('admin.advanced-reports.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.advanced-reports.students.show', $s['students']['bilal']))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.advanced-reports.export', ['format' => 'csv']))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.advanced-reports.print'))->assertForbidden();

        $this->switchModule('advanced_reports', true);
        $this->forgetPermissionCache();
    }

    /**
     * The seeded grants (D177). The Institute Manager — which already exports students and holds
     * every institute fee ability — gets all four. The Accountant does not: every export of this
     * report carries each student's phone, email and progress, which that role may not export
     * anywhere else (it holds neither `students.export` nor any `student_progress.*`).
     */
    #[Test]
    public function the_seeded_institute_manager_holds_it_and_the_seeded_accountant_does_not(): void
    {
        $granted = static fn (string $role): array => Role::query()
            ->where('name', $role)
            ->firstOrFail()
            ->permissions()
            ->where('name', 'like', 'advanced\_reports.%')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $this->assertSame([
            'advanced_reports.export',
            'advanced_reports.print',
            'advanced_reports.view_financial',
            'advanced_reports.view_reports',
        ], $granted('Institute Manager'));

        $this->assertSame([], $granted('Accountant'), 'The seeded Accountant can bulk-export student contact details and progress.');

        $accountant = $this->createUserWithRole('Accountant');
        $this->assertFalse($accountant->can('advanced_reports.view_reports'));
        $this->actingAs($accountant)->get(route('admin.advanced-reports.index'))->assertForbidden();
        $this->actingAs($accountant)->get(route('admin.advanced-reports.export', ['format' => 'csv']))->assertForbidden();
    }

    /**
     * INV-23-2: a withheld money column is absent, not blank — from the table, from the cards and from
     * the data the view was handed.
     */
    #[Test]
    public function a_reader_without_view_financial_sees_the_rows_but_no_money(): void
    {
        $this->scenario();

        $response = $this->actingAs($this->reader())->get(route('admin.advanced-reports.index'));

        $response->assertOk();
        $this->assertFalse($response->viewData('canSeeMoney'));

        foreach (['Total Fees', 'Paid Amount', 'Remaining Amount', 'Total Paid', 'Total Remaining'] as $moneyLabel) {
            $response->assertDontSee($moneyLabel);
        }

        $response->assertDontSee(money('30000.00'));
        $response->assertDontSee(money('75000.00'));
        $response->assertSee('Overdue Payments');
        $response->assertSee('Danish Qureshi');

        $summary = $response->viewData('summary');
        $this->assertFalse($summary['can_see_money']);
        $this->assertSame(1, $summary['overdue_count'], 'The overdue count is shown to everybody.');

        foreach (['total_fees', 'total_paid', 'total_remaining', 'overdue_amount'] as $key) {
            $this->assertArrayNotHasKey($key, $summary, $key.' was computed for a viewer who may not see money.');
        }

        foreach ($response->viewData('rows')->items() as $row) {
            $this->assertFalse($row->moneyVisible);
            $this->assertNull($row->totalFees);
            $this->assertNull($row->remaining);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_filter_narrows_the_rows_on_its_own(): void
    {
        $s = $this->scenario();
        $viewer = $this->viewer();
        $rows = fn (array $query): array => $this->labelsOn($this->actingAs($viewer)->get(route('admin.advanced-reports.index', $query)), $s);

        $this->assertSame(self::EVERYONE, $rows([]));

        // Course and batch.
        $this->assertSame(['ayesha_web', 'bilal'], $rows(['course_id' => $s['courses']['web']->getKey()]));
        $this->assertSame(['ayesha_data', 'danish', 'farah', 'hamza'], $rows(['course_id' => $s['courses']['data']->getKey()]));
        $this->assertSame(['ayesha_web'], $rows(['batch_id' => $s['batches']['web_a']->getKey()]));
        $this->assertSame(['danish'], $rows(['batch_id' => $s['batches']['data_a']->getKey()]));

        // Student status — one bucket each, first match wins.
        $this->assertSame(['ayesha_web'], $rows(['status' => ReportStudentStatus::Active->value]));
        $this->assertSame(['bilal'], $rows(['status' => ReportStudentStatus::Completed->value]));
        $this->assertSame(['ayesha_data', 'farah'], $rows(['status' => ReportStudentStatus::Pending->value]));
        $this->assertSame(['hamza'], $rows(['status' => ReportStudentStatus::Dropped->value]));
        $this->assertSame(['danish'], $rows(['status' => ReportStudentStatus::Inactive->value]));

        // Payment status — overdue first, then by money received; no live charges is Unpaid.
        $this->assertSame(['bilal'], $rows(['payment' => ReportPaymentStatus::Paid->value]));
        $this->assertSame(['ayesha_web'], $rows(['payment' => ReportPaymentStatus::Partial->value]));
        $this->assertSame(['ayesha_data', 'farah', 'hamza'], $rows(['payment' => ReportPaymentStatus::Unpaid->value]));
        $this->assertSame(['danish'], $rows(['payment' => ReportPaymentStatus::Overdue->value]));

        // Search: a name, a batch code, an email, an admission number — and LIKE's wildcards are text.
        $this->assertSame(['danish'], $rows(['search' => 'qureshi']));
        $this->assertSame(['bilal'], $rows(['search' => 'WEB-B']));
        $this->assertSame(['farah'], $rows(['search' => 'farah.siddiqui@']));
        $this->assertSame(['hamza'], $rows(['search' => (string) $s['admissions']['hamza']->admission_number]));
        $this->assertSame([], $rows(['search' => '%']), 'A % typed into the search box matched everything: it reached LIKE unescaped.');
    }

    /**
     * The period reads `student_admissions.admission_date` — the joining date — in the business
     * timezone. Only boundaries that cannot move with the day the suite runs are asserted.
     */
    #[Test]
    public function the_period_filter_reads_the_joining_date(): void
    {
        $s = $this->scenario();
        $viewer = $this->viewer();
        $rows = fn (array $query): array => $this->labelsOn($this->actingAs($viewer)->get(route('admin.advanced-reports.index', $query)), $s);

        $lastYear = $s['dates']['last_year']->toDateString();
        $today = $s['dates']['today']->toDateString();

        $this->assertSame(['ayesha_data', 'ayesha_web', 'farah'], $rows(['period' => AdvancedReportFilters::PERIOD_TODAY]));
        $this->assertSame(['bilal', 'hamza'], $rows(['period' => AdvancedReportFilters::PERIOD_LAST_MONTH]));
        $this->assertSame(self::EVERYONE, $rows(['period' => AdvancedReportFilters::PERIOD_ALL]));

        $inLastYear = $rows(['period' => AdvancedReportFilters::PERIOD_LAST_YEAR]);
        $this->assertContains('danish', $inLastYear);
        $this->assertNotContains('ayesha_web', $inLastYear);

        $this->assertSame(['danish'], $rows(['period' => 'custom', 'from' => $lastYear, 'to' => $lastYear]));

        // Open-ended, and typed backwards: swapped rather than answered with nothing.
        $this->assertSame(['ayesha_data', 'ayesha_web', 'farah'], $rows(['period' => 'custom', 'from' => $today]));
        $this->assertSame(self::EVERYONE, $rows(['period' => 'custom', 'from' => $today, 'to' => $lastYear]));

        // A link with dates and no period means the dates.
        $this->assertSame(['danish'], $rows(['from' => $lastYear, 'to' => $lastYear]));

        // A preset wins over the date inputs the form always submits.
        $this->assertSame(['bilal', 'hamza'], $rows(['period' => AdvancedReportFilters::PERIOD_LAST_MONTH, 'from' => $lastYear, 'to' => $lastYear]));
    }

    #[Test]
    public function filters_combine_and_a_stale_course_or_batch_is_dropped_rather_than_obeyed(): void
    {
        $s = $this->scenario();
        $viewer = $this->viewer();
        $rows = fn (array $query): array => $this->labelsOn($this->actingAs($viewer)->get(route('admin.advanced-reports.index', $query)), $s);

        $this->assertSame(['ayesha_data', 'farah'], $rows([
            'period' => AdvancedReportFilters::PERIOD_TODAY,
            'course_id' => $s['courses']['data']->getKey(),
            'payment' => ReportPaymentStatus::Unpaid->value,
        ]));

        $this->assertSame(['bilal'], $rows([
            'course_id' => $s['courses']['web']->getKey(),
            'status' => ReportStudentStatus::Completed->value,
            'search' => 'Bilal',
        ]));

        // "Course Web, batch of course Data" is a stale link, not an empty report: the batch goes.
        $mismatched = $this->actingAs($viewer)->get(route('admin.advanced-reports.index', [
            'course_id' => $s['courses']['web']->getKey(),
            'batch_id' => $s['batches']['data_a']->getKey(),
        ]));
        $this->assertSame(['ayesha_web', 'bilal'], $this->labelsOn($mismatched, $s));
        $this->assertNull($mismatched->viewData('filters')->batchId);

        // A course that does not exist is no filter at all.
        $this->assertSame(self::EVERYONE, $rows(['course_id' => 999999]));
    }

    /*
    |--------------------------------------------------------------------------
    | Summary and sorting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_summary_counts_people_once_and_adds_up_the_admission_caches(): void
    {
        $s = $this->scenario();
        $viewer = $this->viewer();

        $response = $this->actingAs($viewer)->get(route('admin.advanced-reports.index'));
        $response->assertOk();

        $summary = $response->viewData('summary');

        $this->assertSame(5, $summary['total_students'], 'Ayesha is on two courses and is still one student.');
        $this->assertSame(6, $summary['total_enrolled'], 'Total Enrolled counts admissions.');
        $this->assertSame(1, $summary['active_students']);
        $this->assertSame(1, $summary['completed_students']);
        $this->assertSame(1, $summary['overdue_count']);
        $this->assertTrue($summary['can_see_money']);
        $this->assertSame('75000.00', $summary['total_fees']);
        $this->assertSame('33000.00', $summary['total_paid'], 'Paid is net of the 2,000 refund.');
        $this->assertSame('42000.00', $summary['total_remaining']);
        $this->assertSame('25000.00', $summary['overdue_amount']);
        $this->assertSame($summary['total_remaining'], Money::sub($summary['total_fees'], $summary['total_paid']));

        $response->assertSee(money('75000.00'));
        $response->assertSee(money('33000.00'));
        $response->assertSee(money('42000.00'));
        $response->assertSee(money('25000.00'));

        // The cards answer for the filtered set, not for the whole school.
        $web = $this->actingAs($viewer)
            ->get(route('admin.advanced-reports.index', ['course_id' => $s['courses']['web']->getKey()]))
            ->assertOk()
            ->viewData('summary');

        $this->assertSame(2, $web['total_students']);
        $this->assertSame(2, $web['total_enrolled']);
        $this->assertSame(1, $web['active_students']);
        $this->assertSame(1, $web['completed_students']);
        $this->assertSame(0, $web['overdue_count']);
        $this->assertSame('50000.00', $web['total_fees']);
        $this->assertSame('33000.00', $web['total_paid']);
        $this->assertSame('17000.00', $web['total_remaining']);
        $this->assertSame('0.00', $web['overdue_amount']);
    }

    #[Test]
    public function sorting_follows_the_allowlist_with_a_stable_tiebreak_and_ignores_anything_else(): void
    {
        $s = $this->scenario();
        $viewer = $this->viewer();
        $ordered = fn (array $query, ?User $as = null): array => $this->labelsOn(
            $this->actingAs($as ?? $viewer)->get(route('admin.advanced-reports.index', $query)),
            $s,
            sorted: false,
        );

        // Default: joining date, newest first; same day, newest admission first.
        $default = ['ayesha_data', 'farah', 'ayesha_web', 'hamza', 'bilal', 'danish'];
        $this->assertSame($default, $ordered([]));

        $this->assertSame(
            ['ayesha_data', 'ayesha_web', 'bilal', 'danish', 'farah', 'hamza'],
            $ordered(['sort' => 'name', 'direction' => 'asc']),
        );
        $this->assertSame(
            ['hamza', 'farah', 'danish', 'bilal', 'ayesha_data', 'ayesha_web'],
            $ordered(['sort' => 'name', 'direction' => 'desc']),
        );
        $this->assertSame(
            ['ayesha_data', 'hamza', 'farah', 'bilal', 'danish', 'ayesha_web'],
            $ordered(['sort' => 'total', 'direction' => 'asc']),
        );

        // Junk is the default order, never a 500 and never SQL.
        foreach (['1 AND SLEEP(3)', 'st.name; DROP TABLE students', 'charged_amount'] as $junk) {
            $this->assertSame($default, $ordered(['sort' => $junk]));
        }

        // A money sort by somebody who cannot see money would still reveal the order of the money.
        $reader = $this->reader();
        $this->assertSame($default, $ordered(['sort' => 'remaining', 'direction' => 'asc'], $reader));
        $this->assertSame(
            AdvancedReportFilters::DEFAULT_SORT,
            $this->actingAs($reader)->get(route('admin.advanced-reports.index', ['sort' => 'remaining']))->viewData('filters')->sort,
        );
    }

    /**
     * Every link the screen writes — sort headers, page links, exports — carries the filters as the
     * server applied them, never the query string as it was typed: a dropped money sort, an unusable
     * course or a default period must not be sent straight back. The page links are checked through
     * `withQueryString()`, which the shared pagination component calls on every paginator it renders.
     */
    #[Test]
    public function links_carry_the_filters_as_applied_not_as_typed(): void
    {
        $this->scenario();
        $reader = $this->reader();

        $typed = ['sort' => 'remaining', 'direction' => 'asc', 'course_id' => '999999', 'period' => 'all', 'status' => 'pending'];

        $response = $this->actingAs($reader)->get(route('admin.advanced-reports.index', $typed))->assertOk();
        $this->assertSame(['status' => 'pending'], $response->viewData('filters')->toQuery());
        $response->assertDontSee('sort=remaining', false);
        $response->assertDontSee('course_id=999999', false);
        $response->assertDontSee('period=all', false);

        // Two pending rows, one per page: page 2's link is the applied filters plus the page number.
        $this->app->instance('request', Request::create(route('admin.advanced-reports.index'), 'GET', $typed));
        $page = app(AdvancedStudentReportService::class)->paginate($response->viewData('filters'), $reader, 1);

        $this->assertSame(2, $page->lastPage());
        parse_str((string) parse_url($page->withQueryString()->url(2), PHP_URL_QUERY), $query);
        ksort($query);
        $this->assertSame(['page' => '2', 'status' => 'pending'], $query);
    }

    /**
     * SEC-08 / SEC-09's question, asked of this screen: a malformed parameter is a 422, a junk sort is
     * ignored, and nothing answers 500.
     */
    #[Test]
    public function malformed_parameters_are_refused_or_ignored_and_never_a_500(): void
    {
        $this->scenario();
        $viewer = $this->viewer();

        foreach ([
            ['direction' => 'sideways'],
            ['period' => 'forever'],
            ['period' => 'custom', 'from' => '2026-02-31'],
            ['from' => '../../etc/passwd'],
            ['to' => '1 AND SLEEP(3)'],
            ['status' => '1 AND SLEEP(3)'],
            ['status' => ['active']],
            ['payment' => 'free'],
            ['course_id' => 'abc'],
            ['batch_id' => '-1'],
            ['search' => str_repeat('x', AdvancedReportFilters::SEARCH_MAX + 1)],
            ['page' => 'last'],
        ] as $query) {
            $this->actingAs($viewer)
                ->get(route('admin.advanced-reports.index', $query))
                ->assertStatus(422);
        }

        foreach ([
            ['sort' => '1 AND SLEEP(3)'],
            ['q' => '../../etc/passwd', 'per_page' => '1 AND SLEEP(3)'],
            ['status' => '', 'payment' => '', 'course_id' => '', 'search' => ''],
            ['course_id' => '999999', 'batch_id' => '999999'],
        ] as $query) {
            $this->actingAs($viewer)
                ->get(route('admin.advanced-reports.index', $query))
                ->assertOk();
        }

        // The export and print routes read the same Form Request, so they refuse the same way.
        $this->actingAs($viewer)->get(route('admin.advanced-reports.export', ['format' => 'csv', 'status' => 'nope']))->assertStatus(422);
        $this->actingAs($viewer)->get(route('admin.advanced-reports.print', ['direction' => 'sideways']))->assertStatus(422);
        $this->actingAs($viewer)->get('/admin/advanced-reports/export/docx')->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | The batch select
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_batch_options_follow_the_course(): void
    {
        $s = $this->scenario();
        $reader = $this->reader();

        $web = $this->actingAs($reader)
            ->getJson(route('admin.advanced-reports.batches.options', ['course_id' => $s['courses']['web']->getKey()]))
            ->assertOk()
            ->json();

        $ids = array_map(static fn (array $option): int => (int) $option['id'], $web);
        sort($ids);

        $expected = [(int) $s['batches']['web_a']->getKey(), (int) $s['batches']['web_b']->getKey()];
        sort($expected);

        $this->assertSame($expected, $ids);
        $this->assertContains($s['batches']['web_a']->label(), array_column($web, 'label'));
        $this->assertNotContains($s['batches']['data_a']->label(), array_column($web, 'label'));

        $all = $this->actingAs($reader)->getJson(route('admin.advanced-reports.batches.options'))->assertOk()->json();
        $this->assertCount(3, $all);

        // Under "All Courses" every batch names its course, so two courses' "Batch 01" differ.
        $this->assertContains($s['batches']['web_a']->label().' (Full Stack Web Development)', array_column($all, 'label'));
        $this->assertContains($s['batches']['data_a']->label().' (Data Science Essentials)', array_column($all, 'label'));

        $this->actingAs($reader)
            ->getJson(route('admin.advanced-reports.batches.options', ['course_id' => 'abc']))
            ->assertStatus(422);

        // The page renders the narrowed list too, so the form is right without JavaScript.
        $page = $this->actingAs($reader)
            ->get(route('admin.advanced-reports.index', ['course_id' => $s['courses']['data']->getKey()]))
            ->assertOk();

        $this->assertSame([(int) $s['batches']['data_a']->getKey()], array_keys($page->viewData('batchOptions')));
    }

    /*
    |--------------------------------------------------------------------------
    | One student
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_detail_page_shows_the_student_the_enrolment_the_progress_and_the_batch(): void
    {
        $s = $this->scenario();
        $ayesha = $s['students']['ayesha'];
        $admission = $s['admissions']['ayesha_web'];

        $response = $this->actingAs($this->viewer())->get(route('admin.advanced-reports.students.show', [
            'student' => $ayesha->getKey(),
            'admission' => $admission->getKey(),
        ]));

        $response->assertOk();
        $response->assertSee('Personal Information');
        $response->assertSee('Ayesha Rahman');
        $response->assertSee((string) $ayesha->student_code);
        $response->assertSee((string) $ayesha->registration_number);
        $response->assertSee('ayesha.rahman@example.test');

        $response->assertSee('Enrollment Information');
        $response->assertSee((string) $admission->admission_number);
        $response->assertSee('Full Stack Web Development');
        $response->assertSee('Course Progress');
        $response->assertSee('Batch Information');
        $response->assertSee('WEB-A');
        $response->assertSee('Web Mornings');
        $response->assertSee('Sana Mirza');

        // Two admissions, so a tab per course.
        $response->assertSee('Data Science Essentials');

        $this->assertSame((int) $admission->getKey(), $response->viewData('selected')->admissionId);
        $this->assertSame(ReportStudentStatus::Active, $response->viewData('enrollment')['status']);
        $this->assertNotNull($response->viewData('enrollment')['course_start']);

        // Nothing covered on the topic tracker, but the admission is active and its course has begun
        // (activated today): "Not started" beside a start date would contradict itself.
        $this->assertSame('0.00', $response->viewData('progress')['percentage']);
        $this->assertSame(ProgressStatus::InProgress, $response->viewData('progress')['status']);
        $this->assertSame(ProgressStatus::InProgress, $response->viewData('enrollment')['completion_status']);
        $response->assertDontSee('Not started');

        $batch = $response->viewData('batch');
        $this->assertSame('WEB-A', $batch['code']);
        $this->assertSame('Sana Mirza', $batch['instructor']);
        $this->assertSame(EnrollmentStatus::Active, $batch['enrollment_status']);
        $this->assertNotNull($batch['roll_number']);
        $this->assertNotSame([], $batch['schedule'], 'The batch meets on Mondays; the schedule said it meets never.');
    }

    /**
     * The running balance starts at the final fee, walks every receipt and reversal in date order, and
     * ends exactly at the admission's cached balance — the number every other screen shows.
     */
    #[Test]
    public function the_payment_history_runs_down_to_the_outstanding_balance(): void
    {
        $s = $this->scenario();
        $admission = $s['admissions']['ayesha_web'];

        $response = $this->actingAs($this->viewer())->get(route('admin.advanced-reports.students.show', [
            'student' => $s['students']['ayesha']->getKey(),
            'admission' => $admission->getKey(),
        ]));

        $response->assertOk();
        $response->assertSee('Payment History');
        $response->assertSee((string) $s['receipts']['first']->receipt_no);
        $response->assertSee(ReversalType::PartialRefund->label());
        $response->assertSee(money('17000.00'));

        // The requested column wording, in order; a receipt reads as a positive amount paid, and only
        // the refund carries a minus sign (with its type beside it).
        $response->assertSeeInOrder(['Payment Date', 'Amount Paid', 'Payment Method', 'Transaction/Receipt ID', 'Notes', 'Remaining Balance']);
        $response->assertSee(money('10000.00'));
        $response->assertDontSee('−'.money('10000.00'));
        $response->assertDontSee('−'.money('5000.00'));
        $response->assertSee('−'.money('2000.00'));

        $fees = $response->viewData('fees');
        $this->assertSame('30000.00', $fees['final_fee']);
        $this->assertSame('13000.00', $fees['total_paid']);
        $this->assertSame('17000.00', $fees['remaining']);
        $this->assertSame(ReportPaymentStatus::Partial, $fees['status']);

        $history = $response->viewData('history');
        $this->assertSame('30000.00', $history['opening']);
        $this->assertSame(['payment', 'payment', 'reversal'], array_column($history['rows'], 'kind'));
        $this->assertSame(['20000.00', '15000.00', '17000.00'], array_column($history['rows'], 'balance'));
        $this->assertSame('17000.00', $history['closing']);
        $this->assertTrue($history['reconciles']);
        $this->assertSame((string) $admission->refresh()->balance_amount, $history['closing']);
        $this->assertSame(
            $history['closing'],
            $this->fees()->outstandingFor($admission),
            'The history and the fee service disagree about what is owed.',
        );
    }

    #[Test]
    public function a_completed_admission_reads_one_hundred_percent_and_a_paid_history_ends_at_zero(): void
    {
        $s = $this->scenario();

        $response = $this->actingAs($this->viewer())->get(route('admin.advanced-reports.students.show', $s['students']['bilal']));

        $response->assertOk();

        $this->assertSame((int) $s['admissions']['bilal']->getKey(), $response->viewData('selected')->admissionId);
        $this->assertSame('100.00', $response->viewData('progress')['percentage']);
        $this->assertSame(ProgressStatus::Completed, $response->viewData('progress')['status']);
        $this->assertSame('All topics', $response->viewData('progress')['topics'], 'A finished course is not "0 of N topics".');
        $this->assertNotNull($response->viewData('progress')['completed_on'], 'A completed course shows when it was completed.');
        $this->assertNotNull($response->viewData('enrollment')['actual_completion']);
        $this->assertSame(ReportPaymentStatus::Paid, $response->viewData('fees')['status']);
        $this->assertSame('0.00', $response->viewData('history')['closing']);
        $this->assertTrue($response->viewData('history')['reconciles']);
    }

    #[Test]
    public function the_detail_page_hides_money_from_a_reader_and_ignores_somebody_elses_admission(): void
    {
        $s = $this->scenario();

        $reader = $this->actingAs($this->reader())->get(route('admin.advanced-reports.students.show', [
            'student' => $s['students']['ayesha']->getKey(),
            'admission' => $s['admissions']['ayesha_web']->getKey(),
        ]));

        $reader->assertOk();
        $reader->assertSee('You do not have permission to view fee amounts');
        $reader->assertDontSee('Payment History');
        $reader->assertDontSee(money('17000.00'));
        $this->assertNull($reader->viewData('fees'));
        $this->assertNull($reader->viewData('history'));

        // Ayesha's admission id on Bilal's page is ignored, not obeyed.
        $crossed = $this->actingAs($this->viewer())->get(route('admin.advanced-reports.students.show', [
            'student' => $s['students']['bilal']->getKey(),
            'admission' => $s['admissions']['ayesha_web']->getKey(),
        ]));

        $crossed->assertOk();
        $this->assertSame((int) $s['admissions']['bilal']->getKey(), $crossed->viewData('selected')->admissionId);
        $crossed->assertDontSee('Ayesha Rahman');

        $this->actingAs($this->viewer())->get('/admin/advanced-reports/students/999999')->assertNotFound();
    }

    /**
     * A branch user sees their branch and the rows that belong to none ([D-IN-5]). Another branch's
     * student is a 404, not a 403 — a 403 would confirm the record exists.
     */
    #[Test]
    public function another_branchs_student_is_neither_listed_nor_reachable(): void
    {
        $actor = $this->createSuperAdmin();
        $today = CarbonImmutable::now(Format::timezone())->startOfDay();

        $lahore = Branch::query()->create(['name' => 'Lahore', 'code' => 'LHR', 'is_active' => true]);
        $karachi = Branch::query()->create(['name' => 'Karachi', 'code' => 'KHI', 'is_active' => true]);

        $course = $this->publishedCourse(null, ['name' => 'Branch Test Course'], $actor);
        $mine = $this->student(['name' => 'Lahore Learner', 'branch_id' => $lahore->getKey()], $actor);
        $theirs = $this->student(['name' => 'Karachi Learner', 'branch_id' => $karachi->getKey()], $actor);

        $mineAdmission = $this->admit($mine, $course, $today, $actor);
        $theirsAdmission = $this->admit($theirs, $course, $today, $actor);

        $viewer = $this->viewer();
        $viewer->forceFill(['branch_id' => $lahore->getKey()])->save();

        $response = $this->actingAs($viewer)->get(route('admin.advanced-reports.index'));
        $response->assertOk();

        $ids = array_map(static fn (AdvancedReportRow $row): int => $row->admissionId, $response->viewData('rows')->items());
        $this->assertContains((int) $mineAdmission->getKey(), $ids);
        $this->assertNotContains((int) $theirsAdmission->getKey(), $ids);
        $response->assertDontSee('Karachi Learner');

        $this->actingAs($viewer)->get(route('admin.advanced-reports.students.show', $theirs))->assertNotFound();
        $this->actingAs($viewer)->get(route('admin.advanced-reports.students.show', $mine))->assertOk();

        // The export is the same query, so it is scoped the same way.
        $csv = $this->readCsv($this->actingAs($viewer)->get(route('admin.advanced-reports.export', ['format' => 'csv'])));
        $names = array_column($csv['rows'], 'Student Name');
        $this->assertContains('Lahore Learner', $names);
        $this->assertNotContains('Karachi Learner', $names);
    }

    /*
    |--------------------------------------------------------------------------
    | Exports and print
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_csv_holds_exactly_the_filtered_rows_and_says_what_it_covers(): void
    {
        $s = $this->scenario();

        $response = $this->actingAs($this->viewer())->get(route('admin.advanced-reports.export', [
            'format' => 'csv',
            'course_id' => $s['courses']['web']->getKey(),
        ]));

        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="advanced-report-', (string) $response->headers->get('Content-Disposition'));

        $csv = $this->readCsv($response);

        $this->assertContains('Total Fees', $csv['headers']);
        $this->assertContains('Remaining Amount', $csv['headers']);

        $names = array_column($csv['rows'], 'Student Name');
        sort($names);
        $this->assertSame(['Ayesha Rahman', 'Bilal Chaudhry'], $names);

        $ayesha = $this->csvRowFor($csv, 'Ayesha Rahman');
        $this->assertSame('30000.00', $ayesha['Total Fees'], 'Money in a CSV is a plain decimal a spreadsheet can add up.');
        $this->assertSame('13000.00', $ayesha['Paid Amount']);
        $this->assertSame('17000.00', $ayesha['Remaining Amount']);
        $this->assertSame('WEB-A — Web Mornings', $ayesha['Batch']);
        $this->assertSame('Active', $ayesha['Student Status']);
        $this->assertSame('Partially Paid', $ayesha['Payment Status']);

        $this->assertSame('Full Stack Web Development', $csv['meta']['Course'] ?? null);
        $this->assertSame('2', $csv['meta']['Rows'] ?? null);
        $this->assertArrayHasKey('Generated by', $csv['meta']);
        $this->assertArrayNotHasKey('Not included (no permission)', $csv['meta']);
    }

    #[Test]
    public function the_csv_leaves_the_money_columns_out_for_somebody_who_may_not_see_them(): void
    {
        $this->scenario();
        $exporter = $this->createUserWithPermissions(['advanced_reports.export']);

        $csv = $this->readCsv($this->actingAs($exporter)->get(route('admin.advanced-reports.export', [
            'format' => 'csv',
            'status' => ReportStudentStatus::Pending->value,
            'sort' => 'total',
        ])));

        foreach (['Total Fees', 'Paid Amount', 'Remaining Amount'] as $money) {
            $this->assertNotContains($money, $csv['headers'], $money.' was exported to somebody without view_financial.');
        }

        $names = array_column($csv['rows'], 'Student Name');
        sort($names);
        $this->assertSame(['Ayesha Rahman', 'Farah Siddiqui'], $names);

        $this->assertArrayHasKey('Not included (no permission)', $csv['meta'], 'The file must say a column was withheld.');
        $this->assertSame('Joining date (descending)', $csv['meta']['Sorted by'] ?? null, 'A money sort was honoured for a viewer who cannot see money.');
    }

    #[Test]
    public function the_workbook_is_a_real_xlsx_holding_the_filtered_names(): void
    {
        $this->scenario();

        $response = $this->actingAs($this->viewer())->get(route('admin.advanced-reports.export', [
            'format' => 'excel',
            'payment' => ReportPaymentStatus::Unpaid->value,
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', XlsxWriter::MIME);
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('Content-Disposition'));

        $sheet = $this->sheetOf($response);

        $this->assertStringContainsString('Ayesha Rahman', $sheet);
        $this->assertStringContainsString('Farah Siddiqui', $sheet);
        $this->assertStringContainsString('Hamza Iqbal', $sheet);
        $this->assertStringNotContainsString('Bilal Chaudhry', $sheet);
        $this->assertStringNotContainsString('Danish Qureshi', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet, 'A workbook cell must never be a formula.');
    }

    #[Test]
    public function the_pdf_is_a_real_pdf(): void
    {
        $this->scenario();

        $response = $this->actingAs($this->viewer())->get(route('admin.advanced-reports.export', [
            'format' => 'pdf',
            'status' => ReportStudentStatus::Active->value,
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', ExportFormat::Pdf->mime());
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    #[Test]
    public function the_print_sheet_renders_the_filtered_rows_and_prints_itself(): void
    {
        $this->scenario();
        $printer = $this->createUserWithPermissions(['advanced_reports.print']);

        $response = $this->actingAs($printer)->get(route('admin.advanced-reports.print', [
            'status' => ReportStudentStatus::Completed->value,
        ]));

        $response->assertOk();
        $response->assertSee('Advanced Reports');
        $response->assertSee('Bilal Chaudhry');
        $response->assertDontSee('Danish Qureshi');
        $response->assertSee('window.print()', false);

        $this->assertSame(['Bilal Chaudhry'], array_column($response->viewData('rows'), 'Student Name'));
        $this->assertNotContains('Total Fees', $response->viewData('headers'), 'A print-only viewer was given the money columns.');
        $this->assertSame('Completed', $response->viewData('criteria')['Student status'] ?? null, 'The sheet must say which filters produced it.');
    }

    /**
     * CSV/Excel stop at `reports.export_max_rows`; PDF at `PDF_MAX_ROWS` and print at
     * `PRINT_MAX_ROWS`. A refusal goes back to the same filters with a warning, never a half-written
     * file.
     */
    #[Test]
    public function an_export_over_the_cap_is_refused_back_to_the_same_filters(): void
    {
        $s = $this->scenario();
        $viewer = $this->viewer();
        $web = (int) $s['courses']['web']->getKey();

        $this->setting('reports.export_max_rows', 1);

        foreach (['csv', 'excel'] as $format) {
            $this->actingAs($viewer)
                ->get(route('admin.advanced-reports.export', ['format' => $format, 'course_id' => $web]))
                ->assertRedirect(route('admin.advanced-reports.index', ['course_id' => $web]))
                ->assertSessionHas('toast.type', 'warning')
                ->assertSessionHas('toast.title', 'Too many rows');
        }

        // One row fits under a cap of one.
        $this->actingAs($viewer)
            ->get(route('admin.advanced-reports.export', ['format' => 'csv', 'status' => ReportStudentStatus::Completed->value]))
            ->assertOk();

        // The PDF and print caps are constants, so they are asserted where they are decided.
        $exporter = app(AdvancedReportExporter::class);
        $pdfLimit = AdvancedStudentReportService::PDF_MAX_ROWS;
        $limit = AdvancedStudentReportService::PRINT_MAX_ROWS;
        $printer = $this->createUserWithPermissions(['advanced_reports.print']);

        // dompdf renders in memory inside the request: its cap is far below the printed sheet's, low
        // enough that a capped PDF stays inside a 512 MB worker (1,000 rows exhausted it).
        $this->assertLessThan($limit, $pdfLimit);
        $this->assertLessThanOrEqual(400, $pdfLimit);
        $this->assertSame($pdfLimit, $exporter->limitFor(ExportFormat::Pdf));
        $this->assertSame($limit, $exporter->limitFor(ExportFormat::Print));
        $this->assertNull($exporter->refusal(ExportFormat::Pdf, $pdfLimit, $viewer));
        $this->assertStringContainsString('CSV or Excel', (string) $exporter->refusal(ExportFormat::Pdf, $pdfLimit + 1, $viewer));
        $this->assertNotNull($exporter->refusal(ExportFormat::Pdf, $limit, $viewer), 'A PDF as large as a printed sheet would exhaust memory.');

        $this->assertNull($exporter->refusal(ExportFormat::Print, $limit, $printer));
        $this->assertNotNull($exporter->refusal(ExportFormat::Print, $limit + 1, $printer));
        $this->assertStringNotContainsString('CSV', (string) $exporter->refusal(ExportFormat::Print, $limit + 1, $printer),
            'A spreadsheet is not a way out for somebody who may only print.');
    }

    /**
     * The file is read in the screen's order — the ids are ordered once and each chunk fetched by key —
     * so a sorted export is the sorted screen, row for row.
     */
    #[Test]
    public function an_export_lists_the_rows_in_the_order_the_screen_shows_them(): void
    {
        $s = $this->scenario();
        $viewer = $this->viewer();

        foreach ([['sort' => 'name', 'direction' => 'desc'], ['sort' => 'total', 'direction' => 'asc'], []] as $query) {
            $screen = $this->actingAs($viewer)->get(route('admin.advanced-reports.index', $query));
            $screenNames = array_map(static fn (AdvancedReportRow $row): string => $row->studentName, $screen->viewData('rows')->items());

            $csv = $this->readCsv($this->actingAs($viewer)->get(route('admin.advanced-reports.export', ['format' => 'csv'] + $query)));

            $this->assertSame($screenNames, array_column($csv['rows'], 'Student Name'), 'The CSV order drifted from the screen for '.json_encode($query).'.');
        }

        $this->assertCount(count(self::EVERYONE), $csv['rows']);
        $this->assertSame((string) $s['admissions']['ayesha_data']->admission_number, $csv['rows'][0]['Admission No']);
    }

    /*
    |--------------------------------------------------------------------------
    | Buckets, as the rest of the application moves people
    |--------------------------------------------------------------------------
    */

    /**
     * The Students screen drops or completes the *person*, the batch roster the *seat*; neither moves
     * the admission's stage. Both must still land in the right bucket.
     */
    #[Test]
    public function a_student_dropped_or_completed_outside_the_admission_lands_in_the_right_bucket(): void
    {
        $s = $this->scenario();
        $viewer = $this->viewer();
        $rows = fn (array $query): array => $this->labelsOn($this->actingAs($viewer)->get(route('admin.advanced-reports.index', $query)), $s);
        $enrollments = app(BatchEnrollmentService::class);

        // Ayesha leaves the Web batch (the seat is dropped; the admission stays "active").
        $enrollments->drop($this->latestSeat($s['admissions']['ayesha_web']), 'Left the batch', $s['actor']);

        // Farah is dropped on the Students screen (the person; her admission stays at registration).
        $this->studentService()->changeStatus($s['students']['farah']->refresh(), StudentStatus::Dropped, 'Changed her mind', $s['actor']);

        // Danish — suspended — finishes his seat on the roster: finished outranks suspended.
        $enrollments->complete($this->latestSeat($s['admissions']['danish']), $s['actor']);

        $this->assertSame(['ayesha_web', 'farah', 'hamza'], $rows(['status' => ReportStudentStatus::Dropped->value]));
        $this->assertSame(['bilal', 'danish'], $rows(['status' => ReportStudentStatus::Completed->value]));
        $this->assertSame([], $rows(['status' => ReportStudentStatus::Active->value]));
        $this->assertSame([], $rows(['status' => ReportStudentStatus::Inactive->value]));
        $this->assertSame(['ayesha_data'], $rows(['status' => ReportStudentStatus::Pending->value]));

        $summary = $this->actingAs($viewer)->get(route('admin.advanced-reports.index'))->viewData('summary');
        $this->assertSame(0, $summary['active_students'], 'A dropped seat still counted as an active student.');
        $this->assertSame(2, $summary['completed_students']);

        // The detail page reads the same bucket.
        $detail = $this->actingAs($viewer)->get(route('admin.advanced-reports.students.show', [
            'student' => $s['students']['danish']->getKey(),
        ]));
        $this->assertSame(ReportStudentStatus::Completed, $detail->viewData('enrollment')['status']);
        $this->assertSame(ProgressStatus::Completed, $detail->viewData('progress')['status']);
    }

    /**
     * On an instalment plan a student mid-course has paid instalments and future pending ones. That
     * is Partially Paid — not Unpaid because one charge is still pending.
     */
    #[Test]
    public function an_instalment_plan_with_money_received_is_partially_paid_not_unpaid(): void
    {
        $s = $this->scenario();
        $viewer = $this->viewer();
        $farah = $s['admissions']['farah'];

        // First instalment paid in full; the second due next month, nothing on it yet.
        $this->receive($this->chargeFor($farah, '10000.00', null, $s['actor']), '10000.00');
        $this->fees()->issue(new IssueFeeData(
            studentId: (int) $farah->student_id,
            feeType: StudentFeeType::Installment,
            grossAmount: '5000.00',
            studentAdmissionId: (int) $farah->getKey(),
            dueDate: $s['dates']['today']->addMonth(),
        ), $s['actor']);

        $rows = fn (array $query): array => $this->labelsOn($this->actingAs($viewer)->get(route('admin.advanced-reports.index', $query)), $s);

        $this->assertSame(['ayesha_web', 'farah'], $rows(['payment' => ReportPaymentStatus::Partial->value]));
        $this->assertSame(['ayesha_data', 'hamza'], $rows(['payment' => ReportPaymentStatus::Unpaid->value]));
        $this->assertSame(['bilal'], $rows(['payment' => ReportPaymentStatus::Paid->value]));

        $detail = $this->actingAs($viewer)->get(route('admin.advanced-reports.students.show', [
            'student' => $s['students']['farah']->getKey(),
            'admission' => $farah->getKey(),
        ]));

        $this->assertSame(ReportPaymentStatus::Partial, $detail->viewData('selected')->payment);
        $this->assertSame(ReportPaymentStatus::Partial, $detail->viewData('fees')['status'], 'The detail page and the row disagree about the bucket.');
        $this->assertSame('5000.00', $detail->viewData('fees')['remaining']);
    }

    /*
    |--------------------------------------------------------------------------
    | Branches: the filters offer what the rows contain
    |--------------------------------------------------------------------------
    */

    /**
     * Rows are scoped by the admission's branch, so a branch admission on another branch's course is
     * in the table — and its course and batch must be in the selects and must actually filter. An id
     * the viewer cannot filter by is ignored *and the screen says so*. And a row the table lists never
     * leads to a 404, even when the student's own branch is another.
     */
    #[Test]
    public function a_branch_viewer_can_filter_by_every_course_and_batch_its_rows_sit_on(): void
    {
        $actor = $this->createSuperAdmin();
        $today = CarbonImmutable::now(Format::timezone())->startOfDay();

        $lahore = Branch::query()->create(['name' => 'Lahore', 'code' => 'LHR', 'is_active' => true]);
        $karachi = Branch::query()->create(['name' => 'Karachi', 'code' => 'KHI', 'is_active' => true]);

        $karachiCourse = $this->publishedCourse(null, ['name' => 'Karachi Course'], $actor);
        $karachiCourse->forceFill(['branch_id' => $karachi->getKey()])->save();
        $karachiBatch = $this->batch($karachiCourse, ['code' => 'KHI-1', 'name' => 'Karachi Mornings'], $actor);
        $karachiBatch->forceFill(['branch_id' => $karachi->getKey()])->save();
        $elsewhere = $this->publishedCourse(null, ['name' => 'Karachi Only Course'], $actor);
        $elsewhere->forceFill(['branch_id' => $karachi->getKey()])->save();

        // A Lahore student on the Karachi course: the admission is Lahore's.
        $lahoreStudent = $this->student(['name' => 'Lahore Learner', 'branch_id' => $lahore->getKey()], $actor);
        $onKarachiCourse = $this->admissionService()->register($this->admit($lahoreStudent, $karachiCourse, $today, $actor), $actor);
        $this->admissionService()->assignBatch($onKarachiCourse, (int) $karachiBatch->getKey(), [], $actor);

        // A Karachi student whose admission was booked to Lahore.
        $karachiStudent = $this->student(['name' => 'Karachi Learner', 'branch_id' => $karachi->getKey()], $actor);
        $bookedToLahore = $this->admissionService()->create($karachiStudent->refresh(), $karachiCourse, [
            'admission_date' => $today->toDateString(),
            'branch_id' => $lahore->getKey(),
        ], null, $actor);

        $viewer = $this->viewer();
        $viewer->forceFill(['branch_id' => $lahore->getKey()])->save();

        $page = $this->actingAs($viewer)->get(route('admin.advanced-reports.index'))->assertOk();
        $this->assertArrayHasKey((int) $karachiCourse->getKey(), $page->viewData('courseOptions'));
        $this->assertArrayNotHasKey((int) $elsewhere->getKey(), $page->viewData('courseOptions'), 'A course with no visible row and another branch is not offered.');
        $this->assertArrayHasKey((int) $karachiBatch->getKey(), $page->viewData('batchOptions'));

        $byCourse = $this->actingAs($viewer)->get(route('admin.advanced-reports.index', ['course_id' => $karachiCourse->getKey()]))->assertOk();
        $this->assertSame((int) $karachiCourse->getKey(), $byCourse->viewData('filters')->courseId, 'The course filter was dropped for a course the table lists.');
        $ids = array_map(static fn (AdvancedReportRow $row): int => $row->admissionId, $byCourse->viewData('rows')->items());
        sort($ids);
        $expected = [(int) $onKarachiCourse->getKey(), (int) $bookedToLahore->getKey()];
        sort($expected);
        $this->assertSame($expected, $ids);

        $byBatch = $this->actingAs($viewer)->get(route('admin.advanced-reports.index', ['batch_id' => $karachiBatch->getKey()]))->assertOk();
        $this->assertSame((int) $karachiBatch->getKey(), $byBatch->viewData('filters')->batchId);
        $this->assertSame([(int) $onKarachiCourse->getKey()], array_map(static fn (AdvancedReportRow $row): int => $row->admissionId, $byBatch->viewData('rows')->items()));

        // The batch options JSON agrees with the page.
        $json = $this->actingAs($viewer)->getJson(route('admin.advanced-reports.batches.options', ['course_id' => $karachiCourse->getKey()]))->assertOk()->json();
        $this->assertSame([(int) $karachiBatch->getKey()], array_map(static fn (array $option): int => (int) $option['id'], $json));

        // A course the viewer cannot filter by is ignored, and the screen says so.
        $ignored = $this->actingAs($viewer)->get(route('admin.advanced-reports.index', ['course_id' => $elsewhere->getKey()]))->assertOk();
        $this->assertNull($ignored->viewData('filters')->courseId);
        $ignored->assertSee('Filter not applied');
        $ignored->assertSee('The selected course is not available to you');

        // Shown for that render only — the next page does not repeat it.
        $this->actingAs($viewer)->get(route('admin.advanced-reports.index'))->assertOk()->assertDontSee('Filter not applied');

        // The row booked to Lahore opens, although the student is Karachi's.
        $this->actingAs($viewer)->get(route('admin.advanced-reports.students.show', [
            'student' => $karachiStudent->getKey(),
            'admission' => $bookedToLahore->getKey(),
        ]))->assertOk()->assertSee('Karachi Learner');
    }

    /*
    |--------------------------------------------------------------------------
    | Source modules
    |--------------------------------------------------------------------------
    */

    /**
     * A disabled module keeps its data away from everyone; this report must not be the way round it.
     * Students off closes the report — every route carries `module:advanced_reports,students`, so it
     * is the 403 any disabled module answers, Super Admin included — and takes it off the menu and
     * the Reports hub; Student Fees off withholds the money even from a viewer holding
     * view_financial.
     */
    #[Test]
    public function the_report_follows_the_modules_it_reads(): void
    {
        $s = $this->scenario();
        $admin = $this->createSuperAdmin();
        $reportUrl = route('admin.advanced-reports.index');

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'admin.advanced-reports.')) {
                $this->assertContains('module:advanced_reports,students', $route->gatherMiddleware(), $route->getName().' is not closed with the Students module.');
            }
        }

        // On: the hub offers it and the menu lists it.
        $this->actingAs($admin)->get(route('admin.reports.index'))->assertOk()->assertSee($reportUrl);
        $this->assertContains('Advanced Reports', $this->sidebarLabels($admin));

        $this->switchModule('students', false);
        $this->forgetPermissionCache();

        foreach ([
            $reportUrl,
            route('admin.advanced-reports.students.show', $s['students']['bilal']),
            route('admin.advanced-reports.batches.options'),
            route('admin.advanced-reports.export', ['format' => 'csv']),
            route('admin.advanced-reports.print'),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertForbidden();
        }

        // Off: neither the hub button nor the menu item leads to a 403.
        $this->actingAs($admin)->get(route('admin.reports.index'))->assertOk()->assertDontSee($reportUrl);
        $this->assertNotContains('Advanced Reports', $this->sidebarLabels($admin));

        $this->switchModule('students', true);
        $this->forgetPermissionCache();

        $this->switchModule('student_fees', false);
        $this->forgetPermissionCache();

        $response = $this->actingAs($admin)->get(route('admin.advanced-reports.index'))->assertOk();
        $this->assertFalse($response->viewData('canSeeMoney'));
        $this->assertArrayNotHasKey('total_fees', $response->viewData('summary'));
        $response->assertDontSee(money('75000.00'));

        $detail = $this->actingAs($admin)->get(route('admin.advanced-reports.students.show', $s['students']['bilal']))->assertOk();
        $this->assertNull($detail->viewData('fees'));
        $this->assertNull($detail->viewData('history'));

        $csv = $this->readCsv($this->actingAs($admin)->get(route('admin.advanced-reports.export', ['format' => 'csv'])));
        $this->assertNotContains('Total Fees', $csv['headers']);

        $this->switchModule('student_fees', true);
        $this->forgetPermissionCache();

        $this->assertTrue($this->actingAs($admin)->get(route('admin.advanced-reports.index'))->viewData('canSeeMoney'));
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    /**
     * The six admissions in the class docblock, every one through the service that owns it.
     *
     * @return array{
     *     actor: User,
     *     courses: array{web: Course, data: Course},
     *     batches: array<string, Batch>,
     *     students: array<string, Student>,
     *     admissions: array<string, StudentAdmission>,
     *     receipts: array<string, StudentFeePayment>,
     *     dates: array{today: CarbonImmutable, last_month: CarbonImmutable, last_year: CarbonImmutable}
     * }
     */
    private function scenario(): array
    {
        $actor = $this->createSuperAdmin();

        // The report reads stages; whether activation demands a receipt is the admission suite's
        // question. Every activated admission below has been paid against anyway.
        $this->setting('institute.require_fee_before_activation', 'none');

        // Pinned rather than inherited: Danish's charge, ten days past due, is overdue only while the
        // grace is shorter than that, and Ayesha's refund is meant to be a settled one rather than one
        // waiting in an approval queue.
        $this->setting('institute.fee_overdue_grace_days', 0);
        $this->setting('finance.refund_approval_required', false);

        $today = CarbonImmutable::now(Format::timezone())->startOfDay();
        $lastMonth = $today->startOfMonth()->subMonthNoOverflow()->addDays(4);
        $lastYear = $today->startOfYear()->subYearNoOverflow()->addMonths(2)->addDays(9);

        $web = $this->publishedCourse(null, ['name' => 'Full Stack Web Development'], $actor);
        $data = $this->publishedCourse(null, ['name' => 'Data Science Essentials'], $actor);

        $instructor = $this->teacher(['name' => 'Sana Mirza'], $actor);
        $ends = $this->nextMonday()->addMonths(3)->toDateString();

        $batches = [
            'web_a' => $this->batch($web, [
                'code' => 'WEB-A', 'name' => 'Web Mornings', 'teacher_id' => $instructor->getKey(), 'end_date' => $ends,
            ], $actor),
            'web_b' => $this->batch($web, [
                'code' => 'WEB-B', 'name' => 'Web Evenings', 'start_time' => '17:00:00', 'end_time' => '19:00:00', 'end_date' => $ends,
            ], $actor),
            'data_a' => $this->batch($data, [
                'code' => 'DATA-A', 'name' => 'Data Mondays', 'start_time' => '13:00:00', 'end_time' => '15:00:00', 'end_date' => $ends,
            ], $actor),
        ];

        $students = [
            'ayesha' => $this->student(['name' => 'Ayesha Rahman', 'email' => 'ayesha.rahman@example.test'], $actor),
            'bilal' => $this->student(['name' => 'Bilal Chaudhry', 'email' => 'bilal.chaudhry@example.test'], $actor),
            'danish' => $this->student(['name' => 'Danish Qureshi', 'email' => 'danish.qureshi@example.test'], $actor),
            'farah' => $this->student(['name' => 'Farah Siddiqui', 'email' => 'farah.siddiqui@example.test'], $actor),
            'hamza' => $this->student(['name' => 'Hamza Iqbal', 'email' => 'hamza.iqbal@example.test'], $actor),
        ];

        $admissions = $this->admissionService();

        // Ayesha, Web: seated, charged 30,000, paid 10,000 + 5,000, 2,000 of the first refunded. Active.
        $ayeshaWeb = $admissions->register($this->admit($students['ayesha'], $web, $today, $actor), $actor);
        $ayeshaWeb = $admissions->assignBatch($ayeshaWeb, (int) $batches['web_a']->getKey(), [], $actor);
        $ayeshaFee = $this->chargeFor($ayeshaWeb, '30000.00', null, $actor);
        $first = $this->receive($ayeshaFee, '10000.00');
        $ayeshaWeb = $admissions->activate($ayeshaWeb->refresh(), $actor);
        $this->receive($ayeshaFee, '5000.00');
        $this->payments()->refund($first->refresh(), new RefundData(
            amount: '2000.00',
            reason: 'Charged twice for the first month',
            type: ReversalType::PartialRefund,
        ));

        // Bilal, Web: seated, paid in full, finished. Completed.
        $bilal = $admissions->register($this->admit($students['bilal'], $web, $lastMonth, $actor), $actor);
        $bilal = $admissions->assignBatch($bilal, (int) $batches['web_b']->getKey(), [], $actor);
        $this->receive($this->chargeFor($bilal, '20000.00', null, $actor), '20000.00');
        $bilal = $admissions->activate($bilal->refresh(), $actor);
        $admissions->complete($bilal, $actor);

        // Danish, Data: seated, charged 25,000 due ten days ago, nothing paid, then suspended. Inactive.
        $danish = $admissions->register($this->admit($students['danish'], $data, $lastYear, $actor), $actor);
        $danish = $admissions->assignBatch($danish, (int) $batches['data_a']->getKey(), [], $actor);
        $this->chargeFor($danish, '25000.00', $today->subDays(10), $actor);
        $this->studentService()->changeStatus($students['danish']->refresh(), StudentStatus::Suspended, 'Fees unpaid', $actor);

        // Farah, Data: registered, nothing else. Pending.
        $farah = $admissions->register($this->admit($students['farah'], $data, $today, $actor), $actor);

        // Hamza, Data: walked away before registering. Dropped.
        $hamza = $admissions->withdraw($this->admit($students['hamza'], $data, $lastMonth, $actor), 'Moved abroad', $actor);

        // Ayesha again, Data: a second course for the same person — a second row, not a second student.
        $ayeshaData = $this->admit($students['ayesha'], $data, $today, $actor);

        // What the 01:00 sweep does: Danish's charge is past due and still owed.
        $this->fees()->markOverdue();

        return [
            'actor' => $actor,
            'courses' => ['web' => $web, 'data' => $data],
            'batches' => $batches,
            'students' => array_map(static fn (Student $student): Student => $student->refresh(), $students),
            'admissions' => array_map(static fn (StudentAdmission $admission): StudentAdmission => $admission->refresh(), [
                'ayesha_web' => $ayeshaWeb,
                'ayesha_data' => $ayeshaData,
                'bilal' => $bilal,
                'danish' => $danish,
                'farah' => $farah,
                'hamza' => $hamza,
            ]),
            'receipts' => ['first' => $first->refresh()],
            'dates' => ['today' => $today, 'last_month' => $lastMonth, 'last_year' => $lastYear],
        ];
    }

    /** An admission at step one, on the date given — `AdmissionService::create()`, as a walk-in. */
    private function admit(Student $student, Course $course, CarbonImmutable $on, User $actor): StudentAdmission
    {
        return $this->admissionService()->create(
            $student->refresh(),
            $course,
            ['admission_date' => $on->toDateString()],
            null,
            $actor,
        );
    }

    /** One course-fee charge against an admission, through `StudentFeeService::issue()`. */
    private function chargeFor(StudentAdmission $admission, string $gross, ?CarbonInterface $dueOn, User $actor): StudentFee
    {
        return $this->fees()->issue(new IssueFeeData(
            studentId: (int) $admission->student_id,
            feeType: StudentFeeType::CourseFee,
            grossAmount: $gross,
            studentAdmissionId: (int) $admission->getKey(),
            dueDate: $dueOn,
        ), $actor);
    }

    /** The admission's latest seat — the one the report's row reads. */
    private function latestSeat(StudentAdmission $admission): StudentBatchEnrollment
    {
        return StudentBatchEnrollment::query()
            ->where('student_admission_id', $admission->getKey())
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * Every label in the user's sidebar, nested items included.
     *
     * @return list<string>
     */
    private function sidebarLabels(User $user): array
    {
        $labels = [];
        $walk = static function (array $items) use (&$walk, &$labels): void {
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (isset($item['label']) && is_string($item['label'])) {
                    $labels[] = $item['label'];
                }

                foreach (['items', 'children'] as $key) {
                    if (isset($item[$key]) && is_array($item[$key])) {
                        $walk($item[$key]);
                    }
                }
            }
        };

        $walk(Sidebar::forUser($user->fresh()));

        return $labels;
    }

    /** Everything the module declares: the screen, the files, the print sheet and the money. */
    private function viewer(): User
    {
        return $this->createUserWithPermissions([
            'advanced_reports.view_reports',
            'advanced_reports.export',
            'advanced_reports.print',
            'advanced_reports.view_financial',
        ]);
    }

    /** The screen and nothing else — the role a front desk would hold. */
    private function reader(): User
    {
        return $this->createUserWithPermissions(['advanced_reports.view_reports']);
    }

    /**
     * The scenario labels of the rows the controller handed the view.
     *
     * @param  array{admissions: array<string, StudentAdmission>}  $scenario
     * @return list<string>
     */
    private function labelsOn(TestResponse $response, array $scenario, bool $sorted = true): array
    {
        $response->assertOk();

        $labels = [];

        foreach ($scenario['admissions'] as $label => $admission) {
            $labels[(int) $admission->getKey()] = $label;
        }

        $found = array_map(
            static fn (AdvancedReportRow $row): string => $labels[$row->admissionId] ?? 'unexpected #'.$row->admissionId,
            $response->viewData('rows')->items(),
        );

        if ($sorted) {
            sort($found);
        }

        return array_values($found);
    }

    /**
     * The CSV split into its three parts: the header row, the data rows (keyed by header) and the
     * "what this covers" block written after a blank line.
     *
     * @return array{headers: list<string>, rows: list<array<string, string>>, meta: array<string, string>}
     */
    private function readCsv(TestResponse $response): array
    {
        $response->assertOk();

        // A UTF-8 byte-order mark first, so Excel on Windows reads the em dash and accents as UTF-8.
        $content = (string) $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'The CSV has no UTF-8 byte-order mark.');

        $lines = preg_split('/\r\n|\n|\r/', rtrim(substr($content, 3))) ?: [];
        $headers = str_getcsv((string) array_shift($lines), ',', '"', '');

        $rows = [];
        $meta = [];
        $inMeta = false;

        foreach ($lines as $line) {
            if ($line === '') {
                $inMeta = true;

                continue;
            }

            $cells = str_getcsv($line, ',', '"', '');

            if ($inMeta) {
                $meta[(string) $cells[0]] = (string) ($cells[1] ?? '');

                continue;
            }

            $this->assertCount(count($headers), $cells, 'A data row and the header row disagree about the columns.');
            $rows[] = array_combine($headers, array_map('strval', $cells));
        }

        return ['headers' => $headers, 'rows' => $rows, 'meta' => $meta];
    }

    /**
     * @param  array{rows: list<array<string, string>>}  $csv
     * @return array<string, string>
     */
    private function csvRowFor(array $csv, string $name): array
    {
        foreach ($csv['rows'] as $row) {
            if (($row['Student Name'] ?? null) === $name) {
                return $row;
            }
        }

        $this->fail($name.' is not in the CSV.');
    }

    /**
     * The workbook's one sheet, after checking the six parts Excel and LibreOffice need are there.
     * The temporary file is removed here — a test never sends the response, so nothing else would.
     */
    private function sheetOf(TestResponse $response): string
    {
        $file = $response->baseResponse;

        $this->assertInstanceOf(BinaryFileResponse::class, $file);

        $path = $file->getFile()->getPathname();
        $zip = new ZipArchive;
        $opened = $zip->open($path) === true;

        try {
            $this->assertTrue($opened, 'The workbook is not a zip.');

            foreach ([
                '[Content_Types].xml',
                '_rels/.rels',
                'xl/workbook.xml',
                'xl/_rels/workbook.xml.rels',
                'xl/styles.xml',
                'xl/worksheets/sheet1.xml',
            ] as $part) {
                $this->assertNotFalse($zip->locateName($part), 'The workbook has no '.$part.'.');
            }

            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        } finally {
            // Closed before the unlink: Windows will not delete a file a zip handle still holds.
            if ($opened) {
                $zip->close();
            }

            if (is_file($path)) {
                @unlink($path);
            }
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $this->assertNotFalse(simplexml_load_string($sheet), 'sheet1.xml is not well-formed XML.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $sheet;
    }
}
