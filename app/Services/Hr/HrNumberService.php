<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Services\Finance\DocumentNumberService;

/**
 * Issues every number this phase prints (phase-07 §5, [D-HR-14], **D27**).
 *
 * **A thin delegate, like Phase 6's.** `DocumentNumberService` is shipped by Phase 5 and already holds the
 * whole mechanism: the `SELECT ... FOR UPDATE` on the counter's settings row inside the caller's
 * transaction, the increment, and the single retry when a unique index still rejects the number. This
 * class adds the five key pairs and nothing else — **no second numbering implementation exists anywhere**
 * for somebody to reach for by mistake.
 *
 * Every call passes `'%05d'` **explicitly**. The class default is `'%06d'`, and relying on it would make
 * the shape of an employee code depend on a constant in another phase's file.
 */
final readonly class HrNumberService
{
    /** Five digits, always passed explicitly (§5). */
    public const PAD = '%05d';

    public function __construct(private DocumentNumberService $numbers) {}

    /**
     * `EMP-00001` — requirement §24's Employee ID.
     */
    public function employeeCode(): string
    {
        return $this->next('hr.employee_code_prefix', 'hr.employee_code_next_number');
    }

    /**
     * `LVR-00001`.
     */
    public function leaveRequestNumber(): string
    {
        return $this->next('hr.leave_request_prefix', 'hr.leave_request_next_number');
    }

    /**
     * `ADV-00001`.
     */
    public function advanceNumber(): string
    {
        return $this->next('hr.advance_number_prefix', 'hr.advance_next_number');
    }

    /**
     * `PR-00001`.
     */
    public function payrollRunNumber(): string
    {
        return $this->next('hr.payroll_run_prefix', 'hr.payroll_run_next_number');
    }

    /**
     * `SLP-00001` — printed on the slip, so it is issued once and never reissued.
     */
    public function slipNumber(): string
    {
        return $this->next('hr.payslip_prefix', 'hr.payslip_next_number');
    }

    private function next(string $prefixKey, string $counterKey): string
    {
        return $this->numbers->next($prefixKey, $counterKey, self::PAD);
    }
}
