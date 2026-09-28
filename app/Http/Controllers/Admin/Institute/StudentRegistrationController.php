<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\CompleteRegistrationRequest;
use App\Http\Requests\Admin\Institute\StoreRegistrationStudentRequest;
use App\Models\Institute\Course;
use App\Models\Institute\Student;
use App\Models\User;
use App\Services\Institute\StudentRegistrationService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Registration in two steps: who they are, then what they are taking and what they owe.
 *
 * **Thin on purpose.** Every rule lives behind `StudentRegistrationService`, which holds the
 * transaction and calls the services that already own each act. This class decides what the two
 * screens are given and where the operator lands afterwards.
 *
 * **The fee settings are read here and passed to the view, and read again in the service.** That is
 * deliberate rather than duplicated: the view needs them to show a breakdown before anything is
 * saved, and the service reads them again because a figure a form could post is a figure a form
 * could change. The screen previews; the server decides.
 */
final class StudentRegistrationController extends Controller
{
    public function __construct(
        private readonly StudentRegistrationService $registrations,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Step 1 — the student and their login
    |--------------------------------------------------------------------------
    */

    public function create(): View
    {
        return view('admin.students.register.student');
    }

    public function store(StoreRegistrationStudentRequest $request): RedirectResponse
    {
        $student = $this->registrations->createStudentWithLogin(
            $request->studentData(),
            (string) $request->input('password'),
            $request->user(),
        );

        return redirect()
            ->route('admin.students.register.courses', $student)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf(
                    '%s added as %s, and their portal login is ready. Now pick their courses.',
                    $student->name,
                    $student->student_code,
                ),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2 — courses and billing
    |--------------------------------------------------------------------------
    */

    public function courses(Request $request, Student $student): View
    {
        return view('admin.students.register.courses', [
            'student' => $student,
            /*
            | Every published course, flat and unGrouped, with the two columns that say what is
            | taught. One query: the description is a column on `courses`, so no eager load and no
            | join is needed, and the outline tables are deliberately not read — "what will be
            | taught" on a picker is a sentence, not a syllabus, and a syllabus would be one query
            | per course.
            */
            'courses' => Course::query()
                ->published()
                ->orderBy('name')
                ->get([
                    'id', 'name', 'code', 'short_description',
                    'course_fee', 'admission_fee', 'registration_fee', 'monthly_fee',
                    'duration_value', 'duration_unit', 'level',
                ]),
            'counselors' => User::query()->permission('admissions.create')->orderBy('name')->pluck('name', 'id'),
            'methods' => PaymentMethod::options(),
            'fees' => $this->feeSettings(),
            'canTakeMoney' => $request->user()?->can('student_fee_payments.create') ?? false,
        ]);
    }

    public function complete(CompleteRegistrationRequest $request, Student $student): RedirectResponse
    {
        $settings = $this->feeSettings();

        $result = $this->registrations->enrolAndBill(
            $student,
            $request->lines(),
            // The extra fee is added here, from settings — the form is not allowed to name it.
            array_merge($request->shared(), ['extra_fee' => $settings['extra_fee']]),
            $this->paymentPayload($request),
            $request->user(),
        );

        $count = $result['admissions']->count();

        return redirect()
            ->route('admin.students.show', $student)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf(
                    '%s registered on %d course%s. Billed %s, %s taken now, %s outstanding.',
                    $student->name,
                    $count,
                    $count === 1 ? '' : 's',
                    money(Money::add($result['paid'], $result['balance'])),
                    money($result['paid']),
                    money($result['balance']),
                ),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The three optional lines on a registration bill, as the institute has configured them.
     *
     * A zero is not "no line with a zero on it" — it is no line at all. That is what the owner asked
     * for and it is what keeps the bill readable for a school that charges none of them.
     *
     * @return array{extra_fee: string, tax_rate: string, tax_label: string}
     */
    private function feeSettings(): array
    {
        $label = trim((string) setting('institute.tax_label', 'Tax'));

        return [
            'extra_fee' => Money::of((string) setting('institute.extra_fee_amount', '0.00')),
            'tax_rate' => (string) setting('institute.tax_rate', '0.0000'),
            'tax_label' => $label === '' ? 'Tax' : $label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(CompleteRegistrationRequest $request): array
    {
        $payment = $request->payment();

        return [
            'amount' => (string) ($payment['payment_amount'] ?? '0.00'),
            'payment_method' => $payment['payment_method'] ?? PaymentMethod::Cash->value,
            'paid_on' => $payment['paid_on'] ?? null,
            'reference_no' => $payment['reference_no'] ?? null,
            'idempotency_key' => $payment['idempotency_key'] ?? null,
        ];
    }
}
