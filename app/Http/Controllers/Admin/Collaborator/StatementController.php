<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Collaborator;

use App\DataObjects\Collaborator\StatementData;
use App\DataObjects\Collaborator\StatementFilters;
use App\DataObjects\Collaborator\StatementLine;
use App\Enums\CommissionSourceType;
use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryPurpose;
use App\Http\Controllers\Controller;
use App\Models\Collaborator\Collaborator;
use App\Services\Collaborator\CollaboratorStatementService;
use App\Support\CsvWriter;
use App\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A partner's statement — `admin.statements.*` (phase-10-12 §7.4, §8.7).
 *
 * **One builder, four presenters** ([D-IMP-7]). The screen, the print view, the PDF and the CSV all
 * render the same `StatementData`, so FT-44's "the four totals agree to the paisa" is true by
 * construction rather than by four careful implementations that have to stay in step.
 *
 * The service throws rather than return an unbalanced statement, so there is no branch here for "what
 * if it does not add up": by the time this controller has a `StatementData`, it does.
 */
final class StatementController extends Controller
{
    public function __construct(
        private readonly CollaboratorStatementService $statements,
    ) {}

    public function show(Request $request, Collaborator $collaborator): View
    {
        $range = $this->range($request);
        $filters = StatementFilters::fromRequest($request);

        return view('admin.statements.show', [
            'collaborator' => $collaborator,
            'statement' => $this->statements->build($collaborator, $range, $filters),
            'range' => $range,
            'filters' => $filters,
            'purposes' => LedgerEntryPurpose::cases(),
            'statuses' => CommissionStatus::cases(),
            'sourceTypes' => CommissionSourceType::cases(),
        ]);
    }

    /**
     * `print`, `pdf` and `csv` — the other three presenters of the same object.
     */
    public function export(Request $request, Collaborator $collaborator, string $format): View|StreamedResponse
    {
        abort_unless(in_array($format, ['print', 'pdf', 'csv'], true), 404);

        $range = $this->range($request);
        $filters = StatementFilters::fromRequest($request);
        $statement = $this->statements->build($collaborator, $range, $filters);

        if ($format === 'csv') {
            return $this->csv($collaborator, $statement);
        }

        // `print` and `pdf` render the same Blade. The difference is a stylesheet and a header, not a
        // second layout — a PDF that drifts from the printed page is a support call waiting to happen.
        return view('admin.statements.print', [
            'collaborator' => $collaborator,
            'statement' => $statement,
            'range' => $range,
            'asPdf' => $format === 'pdf',
            'generatedAt' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function csv(Collaborator $collaborator, StatementData $statement): StreamedResponse
    {
        $rows = [
            // The opening balance is a row, not a heading: a spreadsheet that starts at the first
            // movement cannot be added up to the closing balance, which is the only reason to export it.
            ['', 'Opening balance', '', '', '', '', '', '', $statement->opening],
        ];

        foreach ($statement->lines as $line) {
            /** @var StatementLine $line */
            $row = $line->toArray();

            $rows[] = [
                $row['date'], $row['reference'], $row['description'], $row['type'],
                $row['rate'], $row['base'], $row['credit'] ?? '', $row['debit'] ?? '', $row['balance'],
            ];
        }

        $rows[] = ['', 'Closing balance', '', '', '', '', '', '', $statement->closing];
        $rows[] = [];
        $rows[] = ['', $statement->proof()];

        return (new CsvWriter)->download(
            sprintf('statement-%s-%s-to-%s.csv',
                $collaborator->collaborator_code,
                $statement->range->start()->toDateString(),
                $statement->range->end()->toDateString()),
            ['Date', 'Reference', 'Description', 'Type', 'Rate', 'Base', 'Credit', 'Debit', 'Balance'],
            $rows,
        );
    }

    private function range(Request $request): DateRange
    {
        return DateRange::make(
            $request->input('preset'),
            $request->input('from'),
            $request->input('to'),
        );
    }
}
