<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Enums\InquiryType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Http\Requests\Cms\PublicContactRequest;
use App\Http\Requests\Cms\SiteListRequest;
use App\Models\Cms\Service;
use App\Services\Cms\ContactInquiryService;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public contact page and form — `site.contact.index` / `site.contact.store` (phase-04 §6.9,
 * §6.10.5, §7.1, §8.11).
 *
 * `GET /contact` is added by Phase 4 because Phase 3 declares no contact route (its catch-all reserves
 * the `contact` slug). The page carries the form partial: inquiry type, the published services
 * (pre-selectable with `?type=service&service={id}`), a free-text course field until Phase 14 ships
 * courses, the budget options of `website.contact_budget_options`, the honeypot and a fresh signed
 * render token — all passed as view data, never read from settings in Blade.
 *
 * `POST /contact` is 404 while `maintenance.contact_form_enabled` is false. Otherwise it always hands
 * the submission to `ContactInquiryService::submit()`, which stores it (spam included) and routes it,
 * and it answers **the same way for spam and non-spam** — a redirect back with a success toast and the
 * on-page confirmation flag — so a bot learns nothing (§6.10.5, test 50).
 */
final class ContactController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    public function __construct(
        private readonly ContactInquiryService $inquiries,
    ) {}

    public function index(SiteListRequest $request): Response
    {
        $type = InquiryType::tryFrom((string) $request->validated('type'));
        // 200, not every published service: the contact form's "what is this about" `<select>` is on a
        // public, uncached-per-visitor path, and **`Service` is deliberately not a small reference table**
        // (its categories are — phase-24-25 section 6.4, PRF-05). A catalogue with more than 200 live
        // services has outgrown a dropdown, not this limit.
        $services = Modules::enabled('services')
            ? Service::query()->public()->orderBy('sort_order')->orderBy('name')->limit(200)->get(['id', 'name', 'slug'])
            : collect();

        $serviceId = $request->validatedId('service');

        if ($serviceId !== null && ! $services->contains(static fn (Service $service): bool => (int) $service->getKey() === $serviceId)) {
            $serviceId = null;
        }

        return $this->contentPage('site.contact', [
            'form' => [
                'action' => route('site.contact.store'),
                'enabled' => $this->siteFlag('maintenance.contact_form_enabled'),
                'types' => InquiryType::options(),
                'selectedType' => ($type ?? ($serviceId !== null ? InquiryType::Service : InquiryType::General))->value,
                'services' => $services,
                'selectedService' => $serviceId,
                // A select once Phase 14's courses exist; a text input until then (§8.11).
                'courseField' => 'text',
                'budgetOptions' => $this->budgetOptions(),
                'spam' => $this->spamFields(),
            ],
            'submitted' => (bool) session('contact_submitted', false),
        ], $this->routeSeo('site.contact.index'), 'site-contact', ['title' => 'Contact us', 'slug' => 'contact']);
    }

    public function store(PublicContactRequest $request): Response
    {
        if (! $this->siteFlag('maintenance.contact_form_enabled')) {
            return $this->notFound();
        }

        $this->inquiries->submit($request->inquiryPayload(), $request);

        $message = 'Thank you — your message was received. We will get back to you soon.';

        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $message]);
        }

        return back()
            ->with('toast', ['type' => 'success', 'message' => $message])
            ->with('contact_submitted', true);
    }

    /**
     * @return list<string>
     */
    private function budgetOptions(): array
    {
        $options = setting('website.contact_budget_options', []);

        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = is_array($decoded) ? $decoded : [];
        }

        return is_array($options)
            ? array_values(array_filter($options, static fn (mixed $option): bool => is_string($option) && $option !== ''))
            : [];
    }
}
