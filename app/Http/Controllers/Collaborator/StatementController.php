<?php

declare(strict_types=1);

namespace App\Http\Controllers\Collaborator;

use App\DataObjects\Collaborator\StatementData;
use App\DataObjects\Collaborator\StatementFilters;
use App\DataObjects\Collaborator\StatementLine;
use App\Http\Controllers\Collaborator\Concerns\ResolvesOwnCollaborator;
use App\Http\Controllers\Controller;
use App\Models\Collaborator\Collaborator;
use App\Services\Collaborator\CollaboratorStatementService;
use App\Support\CsvWriter;
use App\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A partner's own statement — `collaborator.statement.*` (phase-10-12 §7.5, §8.7).
 *
 * The same `CollaboratorStatementService` the admin screen uses, scoped to the session ([D-IMP-7]):
 * the document a partner downloads is byte-for-byte the document the office can pull up, which is the
 * point. Two builders would eventually produce two answers to "what do I balance at", and the partner
 * would be holding the one nobody in the office recognises.
 *
 * Technical rows default from `collaborator.statement_show_technical_rows` and are hidden unless the
 * partner asks: they did not do anything that caused a write-off, and seeing one unexplained reads as
 * an error.
 */
final class StatementController extends Controller
{
    use ResolvesOwnCollaborator;

    public function __construct(
        private readonly CollaboratorStatementService $statements,
    ) {}

    public function index(Request $request): View
    {
        $collaborator = $this->ownCollaborator($request);
        $range = $this->range($request);

        return view('collaborator.statement.index', [
            'collaborator' => $collaborator,
            'statement' => $this->statements->build($collaborator, $range, StatementFilters::fromRequest($request)),
            'range' => $range,
        ]);
    }

    public function export(Request $request, string $format): View|StreamedResponse
    {
        abort_unless(in_array($format, ['print', 'pdf', 'csv'], true), 404);

        $collaborator = $this->ownCollaborator($request);
        $range = $this->range($request);
        $statement = $this->statements->build($collaborator, $range, StatementFilters::fromRequest($request));

        if ($format === 'csv') {
            return $this->csv($collaborator, $statement);
        }

        return view('admin.statements.print', [
            'collaborator' => $collaborator,
            'statement' => $statement,
            'range' => $range,
            'asPdf' => $format === 'pdf',
            'generatedAt' => now(),
        ]);
    }

    private function csv(Collaborator $collaborator, StatementData $statement): StreamedResponse
    {
        $rows = [['', 'Opening balance', '', '', '', '', '', '', $statement->opening]];

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
            sprintf('my-statement-%s-to-%s.csv',
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
