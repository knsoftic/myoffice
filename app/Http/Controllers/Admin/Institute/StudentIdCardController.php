<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\IdCardStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\IssueIdCardRequest;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\Institute\StudentIdCard;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Services\Institute\StudentIdCardService;
use App\Support\CsvWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The student ID card register — `admin.student-id-cards.*` (§85, phase-19-23 §7.6).
 *
 * **There is no destroy action and there never will be.** A certificate has a draft — a document
 * nobody has been given, which is reasonable to discard. A card does not: it is numbered,
 * snapshotted and printed in one step, so every row is a card that existed in the world. A card that
 * was issued stays on the register, marked lost, damaged, replaced or revoked, and the model refuses
 * the delete outright. `student_id_cards.delete` is not a registered ability either.
 *
 * **Issuing a second card retires the first rather than colliding.** `uq_sic_live` permits one active
 * card per student, and the service clears the predecessor inside the same transaction — because the
 * office's intent when they issue a replacement is unambiguous, and making them retire the old one by
 * hand first is just a step they forget.
 */
final class StudentIdCardController extends Controller
{
    public function __construct(
        private readonly StudentIdCardService $cards,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', StudentIdCard::class);

        $cards = $this->filtered($request)
            ->with(['student:id,name,student_code', 'course:id,name', 'batch:id,code'])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.student-id-cards.index', [
            'cards' => $cards,
            'statuses' => IdCardStatus::cases(),
            // 500, not every row: **a filter `<select>` over a table that grows every term is a page
            // that grows for ever** (phase-24-25 section 6.4, PRF-05). Same ceiling as the finance pickers.
            'courses' => Course::query()->orderBy('name')->limit(500)->get(['id', 'name']),
            'batches' => Batch::query()->orderByDesc('id')->limit(500)->get(['id', 'code', 'name']),
            'counts' => $this->statusCounts($request),
            // Counted over the whole register, not the page: a badge that changed as somebody paged
            // would be worse than no badge.
            'expiringSoon' => StudentIdCard::query()
                ->where('status', IdCardStatus::Active->value)
                ->whereNotNull('valid_until')
                ->whereDate('valid_until', '<=', now()->addDays(30)->toDateString())
                ->count(),
            'canCreate' => (bool) $request->user()?->can('create', StudentIdCard::class),
        ]);
    }

    /**
     * Students on a batch who have no live card.
     *
     * Filtered by the absence of an active card rather than by a join on every card, so a student
     * whose card was lost last term appears here again — which is the whole point of the screen.
     */
    public function create(Request $request): View
    {
        Gate::authorize('create', StudentIdCard::class);

        $enrollments = StudentBatchEnrollment::query()
            ->with(['student:id,name,student_code,photo_path', 'batch:id,code,name,course_id', 'batch.course:id,name'])
            ->whereDoesntHave('student.idCards', function (Builder $query): void {
                $query->where('status', IdCardStatus::Active->value);
            })
            ->when($request->integer('batch_id') > 0, fn (Builder $q) => $q->where('batch_id', $request->integer('batch_id')))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.student-id-cards.create', [
            'enrollments' => $enrollments,
            'batches' => Batch::query()->orderByDesc('id')->get(['id', 'code', 'name']),
            // The screen says so before somebody presses issue and gets a refusal.
            'photoRequired' => (bool) setting('institute.id_card_require_photo', true),
        ]);
    }

    public function store(IssueIdCardRequest $request): RedirectResponse
    {
        $enrollment = StudentBatchEnrollment::query()
            ->findOrFail($request->integer('student_batch_enrollment_id'));

        $card = $this->cards->issue(
            $enrollment,
            $request->safe()->except(['student_batch_enrollment_id']),
            $request->user(),
        );

        return redirect()
            ->route('admin.student-id-cards.show', $card)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('Card %s issued.', (string) $card->getAttribute('card_number')),
            ]);
    }

    public function show(Request $request, StudentIdCard $card): View
    {
        Gate::authorize('view', $card);

        return view('admin.student-id-cards.show', [
            'card' => $card->load([
                'student:id,name,student_code', 'course:id,name', 'batch:id,code,name',
                'branch:id,name', 'template:id,code,name',
                'replacementOf:id,card_number', 'replacedBy:id,card_number,replacement_of_id',
                'revoker:id,name', 'lastPrinter:id,name',
            ]),
            'displayCode' => $this->cards->displayCodeFor($card),
            'canChangeStatus' => (bool) $request->user()?->can('changeStatus', $card),
            'canReplace' => (bool) $request->user()?->can('create', StudentIdCard::class) && $card->status->isReplaceable(),
            'canPrint' => (bool) $request->user()?->can('print', $card),
            'statuses' => array_values(array_filter(
                IdCardStatus::cases(),
                static fn (IdCardStatus $s): bool => $s !== IdCardStatus::Active && $s !== IdCardStatus::Replaced,
            )),
        ]);
    }

    /**
     * Mark a card expired, lost, damaged or revoked.
     *
     * `active` and `replaced` are not offered: a card becomes active only by being issued, and
     * `replaced` only by a replacement being issued against it. Offering either would be a control
     * that lies about what it does.
     */
    public function status(Request $request, StudentIdCard $card): RedirectResponse
    {
        Gate::authorize('changeStatus', $card);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['expired', 'lost', 'damaged', 'revoked'])],
            'reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ]);

        $status = IdCardStatus::from($validated['status']);

        $this->cards->changeStatus($card, $status, $validated['reason'] ?? null, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('Card marked %s.', mb_strtolower($status->label())),
        ]);
    }

    public function replace(Request $request, StudentIdCard $card): RedirectResponse
    {
        Gate::authorize('create', StudentIdCard::class);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $replacement = $this->cards->replace($card, $validated['reason'], $request->user());

        return redirect()
            ->route('admin.student-id-cards.show', $replacement)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('Replacement %s issued.', (string) $replacement->getAttribute('card_number')),
            ]);
    }

    public function print(Request $request, StudentIdCard $card): Response
    {
        Gate::authorize('print', $card);

        $this->cards->markPrinted($card, $request->user());

        return response()->view('admin.student-id-cards.print', $this->printData(
            StudentIdCard::query()->whereKey($card->getKey())->get(),
        ));
    }

    /**
     * Issue a card to every student on a set of enrolments.
     *
     * **Each one is issued on its own and a refusal stops only that student**, because the commonest
     * reason a card cannot be issued — no photograph on file — applies to one student in a batch of
     * thirty, and losing the other twenty-nine to it would be absurd. The result names who was left
     * out, so the office knows exactly whose photo to chase.
     */
    public function bulkIssue(Request $request): RedirectResponse
    {
        Gate::authorize('create', StudentIdCard::class);

        $max = max(1, (int) setting('institute.id_card_batch_print_max', 100));

        $validated = $request->validate([
            'enrollment_ids' => ['required', 'array', 'min:1', 'max:'.$max],
            'enrollment_ids.*' => ['integer', Rule::exists('student_batch_enrollments', 'id')],
        ]);

        $issued = 0;
        $refused = [];

        // **The whole student row, not a chosen few columns.** `issue()` snapshots a name, a roll
        // number, a registration number, a joining date and a guardian's phone off this model; a
        // partial select hands it nulls for everything it did not ask for, and `student_code_snapshot`
        // is `NOT NULL`, so the first bulk issue was a 1048 from the database. A select list is an
        // optimisation on a *read*; this is a read that feeds a write.
        $enrollments = StudentBatchEnrollment::query()
            ->with('student')
            ->whereIn('id', $validated['enrollment_ids'])
            ->get();

        foreach ($enrollments as $enrollment) {
            try {
                $this->cards->issue($enrollment, [], $request->user());
                $issued++;
            } catch (CourseRuleException $e) {
                $refused[] = sprintf(
                    '%s — %s',
                    $enrollment->student?->getAttribute('name') ?? 'Unknown student',
                    implode(' ', $e->validator->errors()->all()),
                );
            }
        }

        return back()->with('toast', [
            'type' => $refused === [] ? 'success' : 'warning',
            'message' => $refused === []
                ? sprintf('%d %s issued.', $issued, $issued === 1 ? 'card' : 'cards')
                : sprintf(
                    '%d issued. %d skipped: %s',
                    $issued,
                    count($refused),
                    implode('; ', array_slice($refused, 0, 5)).(count($refused) > 5 ? ' …' : ''),
                ),
        ]);
    }

    /**
     * Stream the card as a PDF at its template's paper size.
     *
     * Generated on demand from the card's own snapshots rather than read back from a stored file: a
     * card is small, the render is cheap, and a missing `pdf_path` should not withhold a document
     * somebody is entitled to.
     */
    public function pdf(Request $request, StudentIdCard $card): Response
    {
        Gate::authorize('print', $card);

        $this->cards->markPrinted($card, $request->user());

        $bytes = $this->cards->renderPdf($card->refresh());

        return response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s.pdf"', (string) $card->getAttribute('card_number')),
            // A card names a student and carries their photograph. No shared cache, ever.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Print a set of cards as one document.
     *
     * **Bounded by `institute.id_card_batch_print_max`**, so a whole institute is never rendered into
     * one PDF by accident. The ceiling is checked here rather than in the service because it is about
     * *this request*, not about any card.
     */
    public function batchPrint(Request $request): Response
    {
        Gate::authorize('print', StudentIdCard::class);

        $max = max(1, (int) setting('institute.id_card_batch_print_max', 100));

        $validated = $request->validate([
            'card_ids' => ['required', 'array', 'min:1', 'max:'.$max],
            'card_ids.*' => ['integer', Rule::exists('student_id_cards', 'id')],
        ]);

        $cards = StudentIdCard::query()
            ->whereIn('id', $validated['card_ids'])
            ->with(['student:id,name,student_code'])
            ->get();

        // Authorised per row, not once for the set: a batch that skipped the branch check would be a
        // way to print cards the holder cannot open individually.
        foreach ($cards as $card) {
            Gate::authorize('print', $card);
        }

        foreach ($cards as $card) {
            $this->cards->markPrinted($card, $request->user());
        }

        return response()->view('admin.student-id-cards.print', $this->printData($cards));
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        Gate::authorize('export', StudentIdCard::class);

        abort_unless($format === 'csv', Response::HTTP_NOT_FOUND);

        return (new CsvWriter)->download(
            'id-cards-'.app_date(now(), 'Y-m-d').'.csv',
            ['Card', 'Student', 'Roll', 'Course', 'Batch', 'Issued', 'Valid until', 'Status', 'Prints'],
            CsvWriter::rowsFrom(
                $this->filtered($request),
                static fn (StudentIdCard $c): array => [
                    (string) $c->getAttribute('card_number'),
                    (string) $c->getAttribute('student_name_snapshot'),
                    (string) $c->getAttribute('student_code_snapshot'),
                    (string) ($c->getAttribute('course_name_snapshot') ?? ''),
                    (string) ($c->getAttribute('batch_name_snapshot') ?? ''),
                    app_date($c->getAttribute('issued_on')),
                    app_date($c->getAttribute('valid_until')),
                    $c->status->label(),
                    (string) $c->getAttribute('print_count'),
                ],
            ),
        );
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The sheet of cards, each already rendered, plus the paper they share.
     *
     * **Rendered here rather than in the view**, so the print page holds no card logic at all: a
     * Blade file that built tokens would be a second renderer, and the one thing a printed card must
     * never do is disagree with the PDF of itself.
     *
     * **The paper comes from the first card's template.** Every card on a sheet is the same size in
     * practice, and a page that tried to honour three different paper sizes at once would honour
     * none of them. A mixed selection prints at the first card's size, which is visible on screen
     * before anybody presses print.
     *
     * **An Eloquent collection, not a base one.** `collect([$card])` has no `load()`, so the single
     * card route was a 500 the moment it went through here — the two entry points look alike and are
     * not the same type.
     *
     * @param  EloquentCollection<int, StudentIdCard>  $cards
     * @return array<string, mixed>
     */
    private function printData(EloquentCollection $cards): array
    {
        $cards->load(['student:id,name,student_code']);

        $rendered = $cards->map(fn (StudentIdCard $card): array => [
            'card' => $card,
            'body' => $this->cards->renderHtml($card),
        ]);

        $template = $cards->isEmpty() ? null : $this->cards->templateFor($cards->first());

        [$width, $height] = $template === null ? [null, null] : $template->dimensionsMm();

        return [
            'rendered' => $rendered,
            'template' => $template,
            'widthMm' => $width,
            'heightMm' => $height,
        ];
    }

    /** The one filtered query the index, the counts and the export all read. */
    private function filtered(Request $request): Builder
    {
        return StudentIdCard::query()
            ->when($request->string('q')->toString() !== '', function (Builder $query) use ($request): void {
                $term = $request->string('q')->toString();
                $query->where(function (Builder $q) use ($term): void {
                    $q->where('card_number', 'like', "%$term%")
                        ->orWhere('student_name_snapshot', 'like', "%$term%")
                        ->orWhere('student_code_snapshot', 'like', "%$term%")
                        ->orWhere('verification_code', 'like', "%$term%");
                });
            })
            ->when($request->string('status')->toString() !== '', fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->integer('batch_id') > 0, fn (Builder $q) => $q->where('batch_id', $request->integer('batch_id')))
            ->when($request->integer('course_id') > 0, fn (Builder $q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->boolean('expiring'), function (Builder $q): void {
                $q->where('status', IdCardStatus::Active->value)
                    ->whereNotNull('valid_until')
                    ->whereDate('valid_until', '<=', now()->addDays(30)->toDateString());
            });
    }

    /**
     * One grouped count for the filter cards, not one count per case.
     *
     * **Six `count(*)`s that differ only in the status they test are a loop, not six screens' worth of
     * work**: the per-case version ran the same statement once per `IdCardStatus` case and PRF-02's
     * sweep saw it six times on one request (phase-24-25 section 11.7). Every case is still keyed, zero
     * included, so the card row keeps its shape when a status is empty.
     *
     * @return array<string, int>
     */
    private function statusCounts(Request $request): array
    {
        $counts = $this->filtered($request)
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $out = [];

        foreach (IdCardStatus::cases() as $status) {
            $out[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $out;
    }
}
