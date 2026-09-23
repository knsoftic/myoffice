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
use App\Services\Institute\StudentIdCardService;
use App\Support\CsvWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The student ID card register — `admin.id-cards.*` (§85, phase-19-23 §7.6).
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

        return view('admin.id-cards.index', [
            'cards' => $cards,
            'statuses' => IdCardStatus::cases(),
            'courses' => Course::query()->orderBy('name')->get(['id', 'name']),
            'batches' => Batch::query()->orderByDesc('id')->get(['id', 'code', 'name']),
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
    public function candidates(Request $request): View
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

        return view('admin.id-cards.candidates', [
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
            ->route('admin.id-cards.show', $card)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('Card %s issued.', (string) $card->getAttribute('card_number')),
            ]);
    }

    public function show(Request $request, StudentIdCard $card): View
    {
        Gate::authorize('view', $card);

        return view('admin.id-cards.show', [
            'card' => $card->load([
                'student:id,name,student_code', 'course:id,name', 'batch:id,code,name',
                'branch:id,name', 'template:id,code,name',
                'replacementOf:id,card_number', 'replacedBy:id,card_number,replacement_of_id',
                'revoker:id,name', 'lastPrinter:id,name',
            ]),
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
            ->route('admin.id-cards.show', $replacement)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('Replacement %s issued.', (string) $replacement->getAttribute('card_number')),
            ]);
    }

    public function print(Request $request, StudentIdCard $card): Response
    {
        Gate::authorize('print', $card);

        $this->cards->markPrinted($card, $request->user());

        return response()->view('admin.id-cards.print', [
            'cards' => collect([$card->load(['student:id,name,student_code'])]),
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

        return response()->view('admin.id-cards.print', ['cards' => $cards]);
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

    /** @return array<string, int> */
    private function statusCounts(Request $request): array
    {
        $counts = [];

        foreach (IdCardStatus::cases() as $status) {
            $counts[$status->value] = (clone $this->filtered($request))->where('status', $status->value)->count();
        }

        return $counts;
    }
}
