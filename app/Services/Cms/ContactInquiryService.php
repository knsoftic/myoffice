<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\ContactInquiryStatus;
use App\Enums\InquiryRoutingStatus;
use App\Enums\InquirySource;
use App\Enums\InquiryType;
use App\Events\Cms\ContactInquirySubmitted;
use App\Jobs\Cms\RouteContactInquiry;
use App\Models\Cms\ContactInquiry;
use App\Models\Cms\Service;
use App\Models\User;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\Support\ContentHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The public contact form record and its admin queue (phase-04 §6.10.5, requirement §17).
 *
 * `submit()` invariants:
 *
 *   · It **always** persists a row — spam included (flagged, with its reason) — so a false positive is
 *     recoverable and nothing a visitor sent is ever lost. It returns the row either way, and the caller
 *     answers identically for spam and genuine submissions: a bot learns nothing.
 *   · Email lower-cased; HTML stripped from `message` and `subject`; IP, user agent, page URL, referrer,
 *     `utm_*` and the render→submit time recorded.
 *   · The referral snapshot (§2.20, D37): `referral_code` is stored verbatim from the `?ref=` value that
 *     reached the form; `collaborator_id` and `referral_visit_id` only from the server-side resolver once
 *     Phase 9 binds one (`REFERRAL_RESOLVER`) — **a `collaborator_id` posted by the browser is discarded**.
 *     These three columns are display snapshots: no engine and no access scope reads them.
 *   · `assigned_to` defaults to `website.inquiry_default_assignee_id` when that user exists.
 *   · Routing state starts `pending` (with its target key) for a routable type and `not_applicable`
 *     otherwise; spam is always `not_applicable`.
 *   · A genuine row fires `ContactInquirySubmitted` after commit; its listener queues routing when
 *     `website.inquiry_auto_route` is on. Spam fires nothing and notifies nobody.
 *
 * `markSpam()` never deletes a target record that routing already created — the fact is written to the
 * activity log instead. `markNotSpam()` clears the flags and queues routing again.
 */
final class ContactInquiryService
{
    private const MODULE = 'contact_inquiries';

    /**
     * Container key Phase 9 may bind to resolve the referral snapshot server-side:
     * `callable(Request $request, ?string $code): array{collaborator_id?: int|null, referral_visit_id?: int|null}`.
     * Unbound (Phase 4 – 8), the code is stored verbatim and both ids stay null.
     */
    public const REFERRAL_RESOLVER = 'cms.inquiry.referral_resolver';

    public function __construct(
        private readonly ContentHelper $content,
        private readonly SpamGuard $spam,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data, Request $request): ContactInquiry
    {
        $verdict = $this->spam->verdict($data, $request);

        $type = $data['inquiry_type'] ?? InquiryType::General;
        $type = $type instanceof InquiryType ? $type : (InquiryType::tryFrom(is_scalar($type) ? (string) $type : '') ?? InquiryType::General);

        $message = $this->content->plain($data['message'] ?? null) ?? '';
        $referralCode = $this->referralCode($data, $request);
        $referral = $verdict->isSpam ? [] : $this->resolveReferral($request, $referralCode);
        $target = $verdict->isSpam ? null : $type->routingTarget();

        return $this->content->transaction(function () use ($data, $request, $verdict, $type, $message, $referralCode, $referral, $target): ContactInquiry {
            $inquiry = new ContactInquiry;

            $inquiry->fill([
                'inquiry_type' => $type,
                'name' => $this->content->plain($data['name'] ?? null, 150) ?? '',
                'email' => $this->content->plain($data['email'] ?? null, 150) ?? '',
                'phone' => $this->content->plain($data['phone'] ?? null, 32),
                'whatsapp' => $this->content->plain($data['whatsapp'] ?? null, 32),
                'company' => $this->content->plain($data['company'] ?? null, 150),
                'service_id' => $this->serviceId($data['service_id'] ?? null),
                'course_id' => $this->content->id($data['course_id'] ?? null),
                'course_name' => $this->content->plain($data['course_name'] ?? null, 150),
                'budget' => $this->content->plain($data['budget'] ?? null, 100),
                'subject' => $this->content->plain($data['subject'] ?? null, 200),
                'message' => $message,
                'source' => $this->source($data['source'] ?? null),
                'page_url' => $this->safeUrl($data['page_url'] ?? $request->headers->get('referer')),
                'referrer_url' => $this->safeUrl($data['referrer_url'] ?? null),
                'utm_source' => $this->utm($data, $request, 'utm_source'),
                'utm_medium' => $this->utm($data, $request, 'utm_medium'),
                'utm_campaign' => $this->utm($data, $request, 'utm_campaign'),
                'filled_in_seconds' => $verdict->filledInSeconds,
                'ip_address' => mb_substr((string) $request->ip(), 0, 45) ?: null,
                'user_agent' => $this->content->plain($request->userAgent(), 1000),
            ]);

            $inquiry->forceFill([
                'status' => ContactInquiryStatus::New,
                'is_spam' => $verdict->isSpam,
                'spam_reason' => $verdict->reason,
                'routing_status' => $target === null ? InquiryRoutingStatus::NotApplicable : InquiryRoutingStatus::Pending,
                'routing_target' => $target,
                'routing_attempts' => 0,
                'referral_code' => $referralCode,
                // Server-resolved only; anything the browser posted under these names is ignored.
                'collaborator_id' => $referral['collaborator_id'] ?? null,
                'referral_visit_id' => $referral['referral_visit_id'] ?? null,
                'assigned_to' => $this->defaultAssignee(),
            ]);

            $inquiry->save();

            if (! $verdict->isSpam) {
                event(new ContactInquirySubmitted($inquiry));
            }

            return $inquiry;
        });
    }

    public function markRead(ContactInquiry $i): void
    {
        $this->content->transaction(function () use ($i): void {
            if ($i->getAttribute('read_at') !== null) {
                return;
            }

            $changes = ['read_at' => Carbon::now(), 'read_by' => $this->content->actorId()];

            if ($i->getAttribute('status') === ContactInquiryStatus::New) {
                $changes['status'] = ContactInquiryStatus::Read;
            }

            $i->forceFill($changes)->save();
        });
    }

    public function assign(ContactInquiry $i, ?User $user): void
    {
        $this->content->transaction(function () use ($i, $user): void {
            if ($user !== null && ! $user->isActive()) {
                throw ContentRuleException::refuse('user_id', 'Choose an active user.');
            }

            $i->forceFill(['assigned_to' => $user?->getKey()])->save();
        });
    }

    public function changeStatus(ContactInquiry $i, ContactInquiryStatus $to, ?string $note = null): void
    {
        $this->content->transaction(function () use ($i, $to, $note): void {
            $current = $i->getAttribute('status');
            $from = $current instanceof ContactInquiryStatus ? $current : (ContactInquiryStatus::tryFrom((string) $current) ?? ContactInquiryStatus::New);

            if ($from === $to) {
                return;
            }

            $changes = ['status' => $to];

            if ($to !== ContactInquiryStatus::New && $i->getAttribute('read_at') === null) {
                $changes['read_at'] = Carbon::now();
                $changes['read_by'] = $this->content->actorId();
            }

            if ($to === ContactInquiryStatus::Responded && $i->getAttribute('responded_at') === null) {
                $changes['responded_at'] = Carbon::now();
            }

            $this->content->quietly($i, function () use ($i, $changes): void {
                $i->forceFill($changes)->save();
            });

            $this->content->audit(
                self::MODULE,
                sprintf('Inquiry #%d moved from %s to %s', (int) $i->getKey(), $from->label(), $to->label()),
                $i,
                ['old' => ['status' => $from->value], 'attributes' => ['status' => $to->value]],
                $note === null ? null : $this->content->plain($note, 500),
                'status_changed',
            );
        });
    }

    /**
     * Internal response notes (the admin "update" action) — audited with old and new values by the model.
     */
    public function saveNotes(ContactInquiry $i, ?string $notes): void
    {
        $notes = $this->content->plain($notes);

        if ($notes !== null && mb_strlen($notes) > 5000) {
            throw ContentRuleException::refuse('response_notes', 'Notes may be at most 5000 characters.');
        }

        $this->content->transaction(static function () use ($i, $notes): void {
            $i->forceFill(['response_notes' => $notes])->save();
        });
    }

    public function markSpam(ContactInquiry $i, string $reason): void
    {
        $reason = $this->content->plain($reason, 100) ?? throw ContentRuleException::reasonRequired();

        $this->content->transaction(function () use ($i, $reason): void {
            if ((bool) $i->getAttribute('is_spam')) {
                return;
            }

            $routed = $i->getAttribute('routed_id') !== null;
            $previous = $i->getAttribute('routing_status');

            $this->content->quietly($i, function () use ($i, $reason): void {
                $i->forceFill([
                    'is_spam' => true,
                    'spam_reason' => $reason,
                    'routing_status' => InquiryRoutingStatus::NotApplicable,
                ])->save();
            });

            $this->content->audit(
                self::MODULE,
                sprintf('Inquiry #%d marked as spam', (int) $i->getKey()),
                $i,
                [
                    'old' => ['is_spam' => false, 'routing_status' => $previous instanceof InquiryRoutingStatus ? $previous->value : $previous],
                    'attributes' => ['is_spam' => true, 'routing_status' => InquiryRoutingStatus::NotApplicable->value],
                    // Routing already created a record in another module; it is kept, and named here.
                    'routed_record_kept' => $routed ? ['type' => $i->getAttribute('routed_type'), 'id' => $i->getAttribute('routed_id')] : null,
                ],
                $reason,
                'marked_spam',
            );
        });
    }

    public function markNotSpam(ContactInquiry $i): void
    {
        $this->content->transaction(function () use ($i): void {
            if (! (bool) $i->getAttribute('is_spam')) {
                return;
            }

            $type = $i->getAttribute('inquiry_type');
            $type = $type instanceof InquiryType ? $type : (InquiryType::tryFrom((string) $type) ?? InquiryType::General);
            $routed = $i->getAttribute('routed_id') !== null;
            $target = $type->routingTarget();

            $status = match (true) {
                $routed => InquiryRoutingStatus::Routed,
                $target === null => InquiryRoutingStatus::NotApplicable,
                default => InquiryRoutingStatus::Pending,
            };

            $this->content->quietly($i, function () use ($i, $status, $target, $routed): void {
                $i->forceFill([
                    'is_spam' => false,
                    'spam_reason' => null,
                    'routing_status' => $status,
                    'routing_target' => $routed ? $i->getAttribute('routing_target') : $target,
                    'routing_error' => null,
                ])->save();
            });

            $this->content->audit(
                self::MODULE,
                sprintf('Inquiry #%d marked as not spam', (int) $i->getKey()),
                $i,
                ['old' => ['is_spam' => true], 'attributes' => ['is_spam' => false, 'routing_status' => $status->value]],
                null,
                'marked_not_spam',
            );

            if ($status === InquiryRoutingStatus::Pending
                && $this->content->bool($this->content->settings()->get('website.inquiry_auto_route', true), true)) {
                RouteContactInquiry::dispatch((int) $i->getKey())->afterCommit();
            }
        });
    }

    /**
     * The admin "Route now" / "Retry routing" action.
     */
    public function routeNow(ContactInquiry $i): InquiryRoutingStatus
    {
        return app(InquiryRouter::class)->route($i, true);
    }

    public function delete(ContactInquiry $i): void
    {
        $this->content->transaction(static fn () => $i->delete());
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The `?ref=` code as it reached the form: the submitting request's own `ref`, a hidden `ref` /
     * `referral_code` field the form carried over from its page, or the session value Phase 3/9 kept.
     * Stored verbatim (trimmed, control characters removed, 32 characters) — it attributes nothing.
     *
     * @param  array<string, mixed>  $data
     */
    private function referralCode(array $data, Request $request): ?string
    {
        $candidates = [
            $request->query('ref'),
            $data['referral_code'] ?? null,
            $data['ref'] ?? null,
            $request->input('ref'),
        ];

        try {
            if ($request->hasSession()) {
                $candidates[] = $request->session()->get('referral.code');
            }
        } catch (Throwable) {
            // no session on this request
        }

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $code = trim((string) preg_replace('~[\x00-\x1F\x7F<>]~u', '', $candidate));

            if ($code !== '') {
                return mb_substr($code, 0, 32);
            }
        }

        return null;
    }

    /**
     * @return array{collaborator_id?: int|null, referral_visit_id?: int|null}
     */
    private function resolveReferral(Request $request, ?string $code): array
    {
        if (! app()->bound(self::REFERRAL_RESOLVER)) {
            return [];
        }

        try {
            $resolver = app(self::REFERRAL_RESOLVER);
            $result = is_callable($resolver) ? $resolver($request, $code) : null;
        } catch (Throwable $exception) {
            // A failing resolver can never lose an inquiry or its code (§13, Phase 8-9 block).
            report($exception);

            return [];
        }

        if (! is_array($result)) {
            return [];
        }

        return [
            'collaborator_id' => $this->content->id($result['collaborator_id'] ?? null),
            'referral_visit_id' => $this->content->id($result['referral_visit_id'] ?? null),
        ];
    }

    private function serviceId(mixed $value): ?int
    {
        $id = $this->content->id($value);

        return $id !== null && Service::query()->whereKey($id)->exists() ? $id : null;
    }

    private function source(mixed $value): InquirySource
    {
        if ($value instanceof InquirySource) {
            return $value;
        }

        return InquirySource::tryFrom(is_scalar($value) ? (string) $value : '') ?? InquirySource::Website;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function utm(array $data, Request $request, string $key): ?string
    {
        $value = $data[$key] ?? $request->query($key);

        return is_scalar($value) ? $this->content->plain($value, 100) : null;
    }

    private function safeUrl(mixed $value): ?string
    {
        $url = is_scalar($value) ? $this->content->plain($value) : null;

        if ($url === null || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }

        return mb_substr($url, 0, 255);
    }

    private function defaultAssignee(): ?int
    {
        $id = $this->content->id($this->content->settings()->get('website.inquiry_default_assignee_id'));

        if ($id === null) {
            return null;
        }

        try {
            return User::query()->active()->whereKey($id)->exists() ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }
}
