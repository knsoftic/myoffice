<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Enums\DeliveryMode;
use App\Enums\PreferredTiming;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Http\Requests\Site\StoreAdmissionApplicationRequest;
use App\Models\Institute\Course;
use App\Models\Institute\StudentApplication;
use App\Services\Institute\CourseInquiryService;
use App\Services\Institute\StudentApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * §67's public admission form and §86's public enquiry — `site.admission.*` (phase-14-17 §7.10).
 *
 * **[D-IN-7] The POST creates one application row. Nothing else.** No student, no login, no fee. The
 * thank-you page quotes the application number so the applicant can ask about it, and the URL is
 * signed so a number that is guessable by design cannot be walked.
 *
 * **The referral field is Phase 9's component and carries a token, never a code or an id.** Whatever
 * the browser sends about who referred them is a claim: `StudentApplicationService::resolveReferral()`
 * hands it to Phase 9's ladder and stores the verdict. A `collaborator_id` in the request body is
 * ignored entirely (INV-I4).
 *
 * **A replayed submit is the same application, not an error.** The form carries a one-time key; the
 * second POST finds the row the first one wrote and shows the same confirmation.
 */
final class AdmissionController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    public function __construct(
        private readonly StudentApplicationService $applications,
        private readonly CourseInquiryService $inquiries,
    ) {}

    public function create(Request $request): Response
    {
        $courses = Course::query()
            ->published()
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'course_category_id']);

        return $this->contentPage(
            'site.admission.create',
            [
                'courses' => $courses,
                'selected' => $request->string('course')->toString(),
                'timings' => PreferredTiming::options(),
                'modes' => DeliveryMode::options(),
                // One per rendered form. The service refuses a POST without it and returns the same
                // application for a replay of one.
                'idempotencyKey' => (string) Str::ulid(),
                'renderedAt' => time(),
                'requiresBatch' => (bool) setting('institute.admission_form_require_batch', false),
                // Set by EnsureAdmissionFormOpen when a staff user is previewing a closed form.
                'isPreview' => (bool) $request->attributes->get('admission_form_preview', false),
            ],
            $this->routeSeo('site.admission.create'),
            'site-admission',
            ['title' => 'Apply for admission', 'slug' => 'admission'],
        );
    }

    public function store(StoreAdmissionApplicationRequest $request): RedirectResponse
    {
        $application = $this->applications->submitFromPublic($request->validated(), $request);

        // Signed, so the number cannot be enumerated even though it is sequential by design.
        return redirect()->signedRoute('site.admission.submitted', [
            'application' => $application->application_number,
        ]);
    }

    public function submitted(StudentApplication $application): Response
    {
        return $this->contentPage(
            'site.admission.submitted',
            ['application' => $application->load('course:id,name,slug')],
            $this->routeSeo('site.admission.submitted'),
            'site-admission',
            ['title' => 'Application received', 'slug' => 'admission/submitted'],
        );
    }

    /**
     * §86's enquiry block, posted from a course page. Answers back to where it came from, because it
     * is a block on another page rather than a page of its own.
     */
    public function storeInquiry(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^[0-9+\-\s()]{7,32}$/'],
            'email' => ['nullable', 'email:rfc', 'max:180'],
            'city' => ['nullable', 'string', 'max:100'],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'preferred_timing' => ['nullable', Rule::enum(PreferredTiming::class)],
            'message' => ['nullable', 'string', 'max:2000'],
            'referral_code' => ['nullable', 'string', 'max:32'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'website' => ['nullable', 'prohibited'],
        ]);

        $decision = $this->applications->resolveReferral($validated, $request);

        $this->inquiries->createFromPublic(array_merge($validated, [
            'source_url' => $request->headers->get('referer'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referral_code_valid' => $decision->hasWinner(),
            'collaborator_id' => $decision->winnerId(),
            'referral_visit_id' => $decision->visit?->getKey(),
        ]));

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Thank you — somebody will call you back shortly.',
        ]);
    }
}
