<?php

declare(strict_types=1);

namespace App\Services\Crm\Exceptions;

use App\DataObjects\Crm\DuplicateReport;

/**
 * `crm.duplicate_block_on_exact` is on and the new lead matches a live record exactly (phase-05 §6.1 `create()`,
 * test 22). A 422 on `confirm_duplicate`; nothing was written. The report travels with the exception so the
 * form can render the matches — restricted matches stay restricted.
 */
final class DuplicateLeadException extends CrmRuleException
{
    public ?DuplicateReport $report = null;

    public static function forReport(DuplicateReport $report): self
    {
        $exception = self::refuse(
            'confirm_duplicate',
            'A lead or client with the same contact details already exists. Review the match and confirm to save anyway.',
        );

        $exception->report = $report;

        return $exception;
    }
}
