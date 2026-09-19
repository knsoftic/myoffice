<?php

declare(strict_types=1);

namespace App\Support\Inquiry;

use App\Contracts\Inquiry\InquiryTarget;
use App\DataObjects\Crm\LeadData;
use App\Enums\InquirySource;
use App\Enums\InquiryType;
use App\Models\Cms\ContactInquiry;
use App\Models\Crm\Lead;
use App\Models\Scopes\LeadVisibilityScope;
use App\Services\Crm\LeadService;
use App\Support\Modules;
use App\Support\Money;
use App\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * The only path from a website contact inquiry to a CRM lead (phase-05 §6.10, F-2.1; phase-04 §6.10.4).
 *
 * Registered into Phase 4's `InquiryRouter` (key `crm_lead`, module `leads`); Phase 5 listens to no inquiry event.
 * `handle()` runs inside the router's savepoint and calls `LeadService::create()` with the field mapping of
 * phase-04 §6.10.4:
 *
 *   | inquiry                   | lead                                                                         |
 *   |---------------------------|------------------------------------------------------------------------------|
 *   | name, company, email,     | same names                                                                   |
 *   | phone, whatsapp           |                                                                              |
 *   | service_id                | `service_id`                                                                 |
 *   | budget                    | `budget_amount` when it is a plain amount; otherwise the chosen label is kept |
 *   |                           | at the top of `notes` (a range such as "50,000 – 150,000" is not a number)   |
 *   | subject + message         | `notes`, subject first                                                       |
 *   | source (+ utm_source)     | `source`; a `website` inquiry with a recognisable `utm_source` maps onto it   |
 *   | page / utm campaign       | `source_detail`                                                              |
 *   | referral_code / visit id  | `referral_code_captured` / `referral_visit_id`                               |
 *   | id                        | `contact_inquiry_id`                                                         |
 *   | —                         | status `new`; assignee = the inquiry's owner or `website.inquiry_default_assignee_id` |
 *
 * **Idempotency is the database's, never a SELECT** (F-3.7, R7): the insert simply runs, and a 1062 on
 * `uq_leads_inquiry` means this inquiry already has its lead — which is returned. A website lead is never refused
 * as a duplicate: it is stored and flagged, because a lost lead is worse than a duplicate one.
 */
final class CrmLeadInquiryTarget implements InquiryTarget
{
    public function __construct(
        private readonly LeadService $leads,
        private readonly SettingsRepository $settings,
    ) {}

    public function key(): string
    {
        return InquiryType::TARGET_CRM_LEAD;
    }

    public function label(): string
    {
        return 'CRM Lead';
    }

    public function isAvailable(): bool
    {
        try {
            return Modules::exists('leads') && Modules::enabled('leads');
        } catch (Throwable) {
            return false;
        }
    }

    public function handles(InquiryType $type): bool
    {
        return $type === InquiryType::Service;
    }

    public function handle(ContactInquiry $inquiry): Model
    {
        try {
            return $this->leads->create($this->dataFor($inquiry));
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'uq_leads_inquiry')) {
                throw $exception;
            }

            // A locking read: the row that won may have committed after this transaction's snapshot was taken,
            // and a plain consistent read would not see it.
            $existing = Lead::query()
                ->withoutGlobalScope(LeadVisibilityScope::class)
                ->withTrashed()
                ->where('contact_inquiry_id', $inquiry->getKey())
                ->sharedLock()
                ->first();

            if (! $existing instanceof Lead) {
                throw $exception;
            }

            return $existing;
        }
    }

    public function dataFor(ContactInquiry $inquiry): LeadData
    {
        $budget = trim((string) $inquiry->getAttribute('budget'));
        $amount = $this->amount($budget);

        $notes = array_filter([
            $amount === null && $budget !== '' ? 'Budget: '.$budget : null,
            $this->nullable($inquiry->getAttribute('subject')),
            $this->nullable($inquiry->getAttribute('message')),
        ]);

        $assignee = $inquiry->getAttribute('assigned_to') ?? $this->settings->get('website.inquiry_default_assignee_id');

        return new LeadData(
            name: mb_substr((string) $inquiry->getAttribute('name'), 0, 150),
            company: $this->nullable($inquiry->getAttribute('company'), 150),
            email: $this->nullable($inquiry->getAttribute('email'), 150),
            phone: $this->nullable($inquiry->getAttribute('phone'), 32),
            whatsapp: $this->nullable($inquiry->getAttribute('whatsapp'), 32),
            serviceId: $inquiry->getAttribute('service_id') === null ? null : (int) $inquiry->getAttribute('service_id'),
            budgetAmount: $amount,
            source: $this->source($inquiry),
            sourceDetail: $this->sourceDetail($inquiry),
            notes: $notes === [] ? null : implode("\n\n", $notes),
            assignedTo: is_numeric($assignee) && (int) $assignee > 0 ? (int) $assignee : null,
            referralCode: $this->nullable($inquiry->getAttribute('referral_code'), 32),
            referralVisitId: $inquiry->getAttribute('referral_visit_id') === null ? null : (int) $inquiry->getAttribute('referral_visit_id'),
            contactInquiryId: (int) $inquiry->getKey(),
            confirmDuplicate: true,
            meta: ['contact_inquiry_id' => (int) $inquiry->getKey()],
        );
    }

    private function source(ContactInquiry $inquiry): InquirySource
    {
        $source = $inquiry->getAttribute('source');
        $source = $source instanceof InquirySource ? $source : (InquirySource::tryFrom((string) $source) ?? InquirySource::Website);

        if ($source === InquirySource::Website) {
            return InquirySource::fromUtmSource($this->nullable($inquiry->getAttribute('utm_source'))) ?? InquirySource::Website;
        }

        return $source;
    }

    private function sourceDetail(ContactInquiry $inquiry): ?string
    {
        $parts = array_filter([
            $this->nullable($inquiry->getAttribute('utm_campaign')),
            $this->nullable($inquiry->getAttribute('page_url')),
        ]);

        return $parts === [] ? null : mb_substr(implode(' · ', $parts), 0, 255);
    }

    /**
     * A plain non-negative amount ("150000", "150,000.50"), or null for a label or a range.
     */
    private function amount(string $budget): ?string
    {
        $clean = str_replace([',', ' '], '', $budget);

        if ($clean === '' || preg_match('/^\d{1,13}(\.\d{1,2})?$/', $clean) !== 1) {
            return null;
        }

        return Money::of($clean);
    }

    private function nullable(mixed $value, ?int $max = null): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $max === null ? $value : mb_substr($value, 0, $max);
    }
}
