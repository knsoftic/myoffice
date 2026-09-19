<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Enums\JobOpeningStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Http\Requests\Cms\PublicJobApplicationRequest;
use App\Models\Cms\JobOpening;
use App\Services\Cms\ApplicationCvService;
use App\Services\Cms\Exceptions\JobClosedException;
use App\Services\Cms\JobApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public careers board — `site.careers.index`, `site.careers.show`, `site.careers.apply`
 * (phase-04 §6.8, §7.1, §8.11, §9.2), `site_module:jobs`.
 *
 *   · `website.careers_enabled = false` makes all three a 404 (test 36);
 *   · the list is `JobOpening::public()` (open, deadline not passed), grouped by department;
 *   · a closed, filled or expired opening still renders its detail read-only with "Applications are
 *     closed"; a draft is a 404;
 *   · a salary the business hid (`salary_visible = false`) never reaches the view — the page says
 *     "Negotiable" and the numbers are not in the HTML (test 39);
 *   · applying is `JobApplicationService::apply()`: the CV lands on the private disk, spam answers the same
 *     success and stores nothing, a closed opening is a 422 that creates nothing, a second application
 *     from the same address is a validation error on `email` (tests 33-36).
 */
final class CareerController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    public function __construct(
        private readonly JobApplicationService $applications,
        private readonly ApplicationCvService $cvs,
    ) {}

    public function index(): Response
    {
        if (! $this->careersEnabled()) {
            return $this->notFound();
        }

        $openings = JobOpening::query()
            ->public()
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('deadline')
            ->get()
            ->each(fn (JobOpening $job) => $this->withholdAmounts($job, 'salary_visible', ['salary_min', 'salary_max']));

        $groups = [];
        $ungrouped = [];

        foreach ($openings as $job) {
            $department = is_string($job->department) ? trim($job->department) : '';

            if ($department === '') {
                $ungrouped[] = $job;
            } else {
                $groups[$department][] = $job;
            }
        }

        if ($ungrouped !== []) {
            $groups[''] = $ungrouped;
        }

        return $this->contentPage('site.careers.index', [
            'openings' => $openings,
            // department label => openings; the '' key (no department) is always last.
            'groups' => $groups,
            'contactUrl' => Route::has('site.contact.index') ? route('site.contact.index') : null,
        ], $this->routeSeo('site.careers.index'), 'site-careers', ['title' => 'Careers', 'slug' => 'careers']);
    }

    public function show(JobOpening $jobOpening): Response
    {
        if (! $this->careersEnabled()) {
            return $this->notFound();
        }

        $jobStatus = $jobOpening->status instanceof JobOpeningStatus ? $jobOpening->status : JobOpeningStatus::tryFrom((string) $jobOpening->status);

        if ($jobStatus === null || $jobStatus === JobOpeningStatus::Draft) {
            return $this->notFound();
        }

        $acceptsApplications = JobOpening::query()->public()->whereKey($jobOpening->getKey())->exists();
        $job = $this->withholdAmounts($jobOpening, 'salary_visible', ['salary_min', 'salary_max']);
        $path = route('site.careers.show', ['jobOpening' => $job->slug], false);

        return $this->contentPage('site.careers.show', [
            'job' => $job,
            'acceptsApplications' => $acceptsApplications,
            'form' => $acceptsApplications ? [
                'action' => route('site.careers.apply', ['jobOpening' => $job->slug]),
                // The same numbers the server enforces (`ApplicationCvService`), shown as a courtesy hint.
                'cvMaxKb' => $this->cvs->maxKilobytes(),
                'cvMaxLabel' => $this->cvs->maxLabel(),
                'cvExtensions' => $this->cvs->allowedExtensions(),
                'cvAccept' => implode(',', array_map(static fn (string $extension): string => '.'.$extension, $this->cvs->allowedExtensions())),
                'spam' => $this->spamFields(),
            ] : null,
            'submitted' => (bool) session('application_submitted', false),
        ], $this->modelSeo($job, $path), 'site-career site-career-'.$job->slug, ['title' => $job->title, 'slug' => 'careers/'.$job->slug]);
    }

    public function apply(PublicJobApplicationRequest $request, JobOpening $jobOpening): Response
    {
        if (! $this->careersEnabled()) {
            return $this->notFound();
        }

        try {
            $this->applications->apply($jobOpening, $request->applicationPayload(), $request->cv(), $request);
        } catch (JobClosedException $exception) {
            // §6.8 invariant 1: a 422 that creates nothing and never renders a draft's content. Any other
            // refusal (a duplicate address) is an ordinary validation error on its field.
            return $this->closed($request->expectsJson(), (string) $exception->validator->errors()->first());
        }

        $message = 'Thank you — your application was received. We will contact you if your profile is a match.';

        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $message]);
        }

        return $this->backToOpening($jobOpening)
            ->with('toast', ['type' => 'success', 'message' => $message])
            ->with('application_submitted', true);
    }

    private function careersEnabled(): bool
    {
        return $this->siteFlag('website.careers_enabled');
    }

    private function closed(bool $json, string $message): Response
    {
        $message = $message !== '' ? $message : 'Applications for this position are closed.';

        if ($json) {
            return new JsonResponse(['message' => $message, 'errors' => ['job' => [$message]]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        abort(Response::HTTP_UNPROCESSABLE_ENTITY, $message);
    }

    private function backToOpening(JobOpening $job): RedirectResponse
    {
        return redirect()->to(route('site.careers.show', ['jobOpening' => $job->slug]).'#apply');
    }
}
