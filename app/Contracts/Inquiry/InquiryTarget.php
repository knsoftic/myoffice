<?php

declare(strict_types=1);

namespace App\Contracts\Inquiry;

use App\Enums\InquiryType;
use App\Models\Cms\ContactInquiry;
use Illuminate\Database\Eloquent\Model;

/**
 * A module that turns a routed public contact inquiry into its own working record (phase-04 §6.10.1, ND-4).
 *
 * Phase 4 declares the contract and ships **no** implementation: Phase 5 registers
 * `App\Support\Inquiry\CrmLeadInquiryTarget` (key `crm_lead`, module `leads`) and Phase 14-17 registers
 * `CourseInquiryTarget` (key `course_inquiry`, module `course_inquiries`), each from its own service
 * provider, into the singleton `App\Services\Cms\InquiryRouter`. The namespace is domain-neutral because
 * the CMS, CRM and institute sides all meet here.
 *
 * Rules an implementation must keep:
 *
 *   · `handle()` creates **one** record and returns it; it is called inside the router's transaction
 *     (a savepoint), so throwing rolls back only the target record — the inquiry itself is never moved,
 *     edited or deleted by a target.
 *   · It must be idempotent on the inquiry id (the lead's `UNIQUE uq_leads_inquiry(contact_inquiry_id)`,
 *     the course inquiry's `uq_ci_inquiry`): a second call for the same inquiry returns the existing row.
 *   · `isAvailable()` is false while the module is missing or switched off; the router then leaves the
 *     inquiry `pending` and retries later — it never fails a job for it.
 */
interface InquiryTarget
{
    /**
     * `crm_lead` | `course_inquiry` — the value `InquiryType::routingTarget()` returns.
     */
    public function key(): string;

    /**
     * Shown in the admin routing cell ("CRM Lead").
     */
    public function label(): string;

    /**
     * The target module exists AND `Modules::enabled($slug)`.
     */
    public function isAvailable(): bool;

    public function handles(InquiryType $type): bool;

    /**
     * Create (or return the existing) target record for this inquiry.
     */
    public function handle(ContactInquiry $inquiry): Model;
}
