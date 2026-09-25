<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Http\Requests\Site\PublicQuoteRequest;
use App\Services\Cms\ContactInquiryService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public request-a-quote page and form — `site.quote.index` / `site.quote.store`.
 *
 * **The same inbox as /contact, framed as a project brief.** The submission goes through
 * `ContactInquiryService::submit()` — the one path that stores an inquiry, classifies it for spam,
 * picks up the `?ref=` code, assigns the default owner and queues routing. Writing a `ContactInquiry`
 * here directly would produce rows that never reach a human, so the only thing this controller decides
 * is the shape of the payload; `PublicQuoteRequest::inquiryPayload()` builds it, typed
 * `InquiryType::Service`, because a quote request is a service enquiry and the enum has no other
 * honest case for it.
 *
 * `website.quote_page_enabled` is off by default: the page is a 404 until somebody turns it on, and the
 * POST is a 404 with it. `maintenance.contact_form_enabled` is the inbox switch the contact page
 * already answers to — with it off the page still renders (with contact details), but the form is
 * replaced by the closed notice and the POST 404s, so quote requests cannot slip past a switch that was
 * meant to stop exactly them. Both checks are repeated in `PublicQuoteRequest::authorize()`, which runs
 * first: a Form Request validates before the controller method, and without that the answer for a
 * switched-off page would depend on the body (422 for a bad field, 404 for a good one).
 *
 * **This route is deliberately not behind `site.cache`** (routes/web.php): the page prints a per-request
 * CSRF token and a fresh `SpamGuard` render token, and a cached form is a form handing out somebody
 * else's token. Nothing here adds caching of its own.
 */
final class QuoteController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    public function __construct(
        private readonly ContactInquiryService $inquiries,
    ) {}

    public function index(): Response
    {
        if (! $this->siteFlag('website.quote_page_enabled', false)) {
            return $this->notFound();
        }

        return $this->contentPage('site.quote.index', [
            'form' => [
                'action' => route('site.quote.store'),
                'enabled' => $this->siteFlag('maintenance.contact_form_enabled'),
                // The contact form's own list, so one inbox holds one vocabulary for "how much".
                'budgetOptions' => $this->budgetOptions(),
                // The Form Request owns the list and validates against it; the view only prints it.
                'timelineOptions' => PublicQuoteRequest::TIMELINE_OPTIONS,
                'spam' => $this->spamFields(),
            ],
            'submitted' => (bool) session('quote_submitted', false),
            // A view never reads a setting (§8.11): the aside's details are resolved here.
            'contact' => $this->contactDetails(),
        ], $this->routeSeo('site.quote.index'), 'site-quote', ['title' => 'Request a quote', 'slug' => 'request-a-quote']);
    }

    public function store(PublicQuoteRequest $request): Response
    {
        if (! $this->siteFlag('website.quote_page_enabled', false) || ! $this->siteFlag('maintenance.contact_form_enabled')) {
            return $this->notFound();
        }

        // Spam included: the service always stores the row and always returns it, and the answer below
        // is identical either way, so a bot learns nothing from what it gets back.
        $this->inquiries->submit($request->inquiryPayload(), $request);

        $message = 'Thank you — your brief is with us. We will come back to you with questions or a proposal.';

        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $message]);
        }

        return back()
            ->with('toast', ['type' => 'success', 'message' => $message])
            ->with('quote_submitted', true);
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

    /**
     * The direct-contact lines for the aside, already cleaned: an address that is not an address and a
     * phone number that is not one never reach the view.
     *
     * @return array{email: string|null, phone: string|null, whatsapp: string|null}
     */
    private function contactDetails(): array
    {
        $text = static function (string $key, int $max): ?string {
            $value = setting($key);
            $value = is_scalar($value) ? trim((string) $value) : '';

            return $value === '' ? null : mb_substr($value, 0, $max);
        };

        $email = $text('contact.email', 150);
        $whatsapp = $text('contact.whatsapp', 32);

        return [
            'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
            'phone' => $text('contact.phone', 32),
            'whatsapp' => $whatsapp,
        ];
    }
}
