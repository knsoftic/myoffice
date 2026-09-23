<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Services\Institute\CertificateVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public verification page — `site.verify.*` (§84, phase-19-23 §6.14, INV-21-3).
 *
 * **The only unauthenticated write path in the institute**, so every decision here is made against a
 * hostile caller rather than a helpful one. It is deliberately thin: `CertificateVerificationService`
 * normalises the code, rate limits *before* touching the database, matches in constant time, logs
 * every attempt including the throttled ones, and returns a payload already filtered by whitelist.
 * This controller picks a status code and a view.
 *
 * **Every answer that is not "here it is" is the same answer.** A draft, a privacy opt-out, a
 * soft-deleted row and a code that never existed all render the same page with the same 404.
 * Distinguishing them would hand an unauthenticated guesser an oracle — *this code is real but
 * hidden* is precisely the fact a privacy opt-out was asked for to conceal. `VerificationResult`
 * fixes the shared status code; rendering one view for all of them is what stops the shared *message*
 * drifting apart later.
 *
 * **A revoked certificate resolves and says revoked.** It is the one negative answer that is more
 * informative than a miss, because somebody holding a revoked certificate needs to be told — which is
 * the entire purpose of the page.
 *
 * **It goes through `contentPage()` like every other public page**, so it carries the site's header,
 * footer and SEO rather than rendering as a bare document. `layouts.site` tolerates a missing `$site`
 * — it is all `data_get()` — which is exactly why this is worth stating: the page would have rendered
 * without chrome and nothing would have complained.
 */
final class VerificationController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    public function __construct(
        private readonly CertificateVerificationService $verification,
    ) {}

    /** The empty form: somewhere to type a code read off a printed certificate. */
    public function index(): Response
    {
        return $this->page([
            'outcome' => null,
            'result' => null,
            'payload' => [],
            'subject' => null,
            'retryAfter' => null,
            'submitted' => '',
        ]);
    }

    /**
     * The form posts, and we redirect to the canonical URL rather than rendering in place.
     *
     * A scanned QR code lands on `show()` directly, so a typed code should end up in the same place —
     * one URL per result means a verification can be bookmarked, shown to a registrar, or opened
     * twice without re-submitting a form.
     */
    public function submit(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        return redirect()->route('site.verify.show', ['code' => trim($validated['code'])]);
    }

    /**
     * Resolve a code. The QR payload on every certificate and card points here.
     *
     * The response carries the service's status code, so a miss is a genuine 404 rather than a 200
     * with sad wording — a crawler, a monitor and a screen reader should all agree nothing is there.
     */
    public function show(Request $request, string $code): Response
    {
        $outcome = $this->verification->verify($code, $request);

        $response = $this->page([
            'outcome' => $outcome,
            'result' => $outcome->result,
            'payload' => $outcome->payload,
            'subject' => $outcome->subject,
            'retryAfter' => $outcome->retryAfterSeconds,
            'submitted' => $code,
        ], $outcome->httpStatus());

        if ($outcome->retryAfterSeconds !== null) {
            // The standard header, so a well-behaved client backs off on its own rather than being
            // told in prose it cannot read.
            $response->headers->set('Retry-After', (string) $outcome->retryAfterSeconds);
        }

        return $response;
    }

    /**
     * One place that builds the response, so the two entry points cannot drift apart on headers.
     *
     * **`no-store` and `noindex` are set after `contentPage()`**, which applies the SEO payload's own
     * robots header and would otherwise leave an indexable page behind. Both matter and neither is
     * about performance:
     *
     * `no-store` because a verification answers about one document at one moment — a revocation an
     * hour ago has to show now, and a shared cache holding yesterday's "valid" would be worse than
     * the page not existing.
     *
     * `noindex` because the codes are not secret but a search engine holding one page per certificate
     * would turn a verification service into a directory of students, reachable by anybody who never
     * had a certificate in their hand.
     *
     * @param  array<string, mixed>  $data
     */
    private function page(array $data, int $status = Response::HTTP_OK): Response
    {
        $response = $this->contentPage(
            'site.verify.show',
            $data,
            $this->routeSeo('site.verify.index'),
            'page-verify',
            ['title' => 'Verify a document', 'slug' => 'verify'],
            false,
            $status,
        );

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
