<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Enums\CertificateStatus;
use App\Enums\IdCardStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesTheSignedInStudent;
use App\Models\Institute\Certificate;
use App\Models\Institute\StudentIdCard;
use App\Services\Institute\CertificateService;
use App\Services\Institute\StudentIdCardService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A student's own certificates and card — `student.certificates.*`, `student.id-card.*`
 * (phase-19-23 §7.9, §9.3).
 *
 * **Nothing here takes a student id, so there is none to guess.** Every query starts from the
 * `students` row this user *is*; a certificate belonging to somebody else is a 404, never a 403,
 * because a 403 would confirm it exists (phase-14-17 §9).
 *
 * **Issued only.** §9.3 scopes this panel to `student_id = own AND status = issued`, which means a
 * student never sees a draft being prepared for them — reasonably, since a draft is a document
 * nobody has decided to give them yet — and never sees one that was revoked. A revoked certificate
 * is still resolvable from its own code by anybody holding the printed copy, with the reason on the
 * page; what it is not is a thing the institute continues to hand back.
 *
 * **The office's columns are not on this panel at all.** §9.3 names them: `eligibility_snapshot`,
 * `issued_by`, `revoked_by`, `print_count`, `verification_count`, `notes`. The views below never
 * reach for one, and the listing selects the columns it needs rather than the row.
 */
final class CertificateController extends Controller
{
    use ResolvesTheSignedInStudent;

    public function __construct(
        private readonly CertificateService $certificates,
        private readonly StudentIdCardService $cards,
    ) {}

    public function index(Request $request): View
    {
        $student = $this->student($request);

        return view('student.certificates.index', [
            'certificates' => Certificate::query()
                ->where('student_id', $student->getKey())
                ->where('status', CertificateStatus::Issued->value)
                ->orderByDesc('issued_on')
                ->paginate(20),
        ]);
    }

    /**
     * Their own certificate as a PDF.
     *
     * Generated from the stored snapshots on demand, like the admin route, so a cleared disk is not
     * a reason a student cannot have the document they earned.
     */
    public function pdf(Request $request, Certificate $certificate): Response
    {
        $this->assertIsTheirs($request, $certificate);

        $bytes = $this->certificates->renderPdf($certificate);

        return response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf(
                'inline; filename="%s.pdf"',
                (string) $certificate->getAttribute('certificate_number'),
            ),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Their card.
     *
     * One screen rather than a list, because §85 permits one live card: what a student wants to know
     * is "is mine valid, and until when". A retired card is shown when there is no live one, so
     * somebody who lost theirs sees the record rather than an empty page saying nothing.
     */
    public function card(Request $request): View
    {
        $student = $this->student($request);

        $card = StudentIdCard::query()
            ->where('student_id', $student->getKey())
            ->where('status', IdCardStatus::Active->value)
            ->latest('id')
            ->first();

        return view('student.id-card.show', [
            'card' => $card,
            'previous' => $card !== null ? null : StudentIdCard::query()
                ->where('student_id', $student->getKey())
                ->latest('id')
                ->first(),
        ]);
    }

    public function cardPdf(Request $request): Response
    {
        $student = $this->student($request);

        $card = StudentIdCard::query()
            ->where('student_id', $student->getKey())
            ->where('status', IdCardStatus::Active->value)
            ->latest('id')
            ->first();

        if (! $card instanceof StudentIdCard) {
            throw new NotFoundHttpException;
        }

        $bytes = $this->cards->renderPdf($card);

        return response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s.pdf"', (string) $card->getAttribute('card_number')),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Theirs, and issued. Anything else is a 404.
     *
     * The two conditions are checked together on purpose: a student asking for a draft of their own
     * and a student asking for somebody else's certificate get the same answer, so neither learns
     * anything from the difference.
     */
    private function assertIsTheirs(Request $request, Certificate $certificate): void
    {
        $student = $this->student($request);

        $isTheirs = (int) $certificate->getAttribute('student_id') === (int) $student->getKey();

        if (! $isTheirs || $certificate->status !== CertificateStatus::Issued) {
            throw new NotFoundHttpException;
        }
    }
}
