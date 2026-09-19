<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\Inquiry\InquiryTarget;
use App\Enums\InquiryRoutingStatus;
use App\Enums\InquiryType;
use App\Events\Cms\ContactInquiryRouted;
use App\Models\Cms\ContactInquiry;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Hands a public contact inquiry to the module that owns its type (phase-04 §6.10): `service` → a CRM lead
 * (Phase 5), `course` → an institute course inquiry (Phase 14-17), `general` → nowhere.
 *
 * A **singleton**: owning phases call `register()` from their service provider. Phase 4 ships with no
 * target registered, and that is a normal, non-failing state (§6.10.3) — the inquiry waits `pending`
 * with `routing_error = target_unregistered` and `inquiries:route-pending` drains the backlog the moment
 * a target appears. Nothing here throws for a missing phase, and nothing lands in `failed_jobs` for it.
 *
 * `route()` — the exact algorithm of §6.10.2:
 *
 *   1. reload the inquiry `FOR UPDATE` inside one transaction;
 *   2. spam → `not_applicable`, nothing touched (spam is never routed);
 *   3. `routed_id` already set → `routed` (idempotent: a second call creates nothing);
 *   4. no target key for the type → `not_applicable`;
 *   5. `routing_target = key`; unregistered → `pending` + `target_unregistered` (+1 attempt);
 *      registered but unavailable → `pending` + `module_disabled`;
 *   6. `handle()` inside a savepoint, and the claim (`routed_type` / `routed_id`) inside the same
 *      savepoint; success → `routed`, `ContactInquiryRouted` after commit (its listener writes the
 *      activity entry naming both records);
 *   7. any exception → the savepoint rolls back **only the target record**, then the inquiry's own
 *      state is written in a separate statement: +1 attempt, the message in `routing_error`, `failed`
 *      from the third attempt, `pending` before it; an activity entry and a log line record it.
 *   8. `uq_contact_inquiry_routed_target` is the last line of defence: when two inquiries claim one
 *      target row the loser's claim fails in step 6, its savepoint rolls back, and it stays `pending`.
 *
 * The contact inquiry is never moved, edited (beyond its routing columns) or deleted by routing. The
 * routing bookkeeping is written through the query builder so an hourly retry does not add a generic
 * "updated" row to the activity log or move `updated_at`.
 *
 * `#[Singleton]` makes every resolution share the registered targets even before a service provider binds
 * the class explicitly.
 */
#[Singleton]
final class InquiryRouter
{
    public const MAX_ATTEMPTS = 3;

    public const ERROR_UNREGISTERED = 'target_unregistered';

    public const ERROR_MODULE_DISABLED = 'module_disabled';

    private const MODULE = 'contact_inquiries';

    /** `routing_attempts` is an unsignedTinyInteger. */
    private const ATTEMPTS_CEILING = 255;

    /** @var array<string, InquiryTarget> */
    private array $targets = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CmsAuditor $auditor,
    ) {}

    /**
     * Register (or replace) the target for its key. Called from the owning phase's service provider.
     */
    public function register(InquiryTarget $target): void
    {
        $key = trim($target->key());

        if ($key === '') {
            throw new RuntimeException(sprintf('Inquiry target [%s] declares an empty key.', $target::class));
        }

        $this->targets[$key] = $target;
    }

    /**
     * @return array<string, InquiryTarget> key => target
     */
    public function targets(): array
    {
        return $this->targets;
    }

    public function target(string $key): ?InquiryTarget
    {
        return $this->targets[$key] ?? null;
    }

    /**
     * Route one inquiry; returns the routing status it ends in. `$manual` marks an admin "Route now" in
     * the audit trail — the algorithm is identical.
     */
    public function route(ContactInquiry $inquiry, bool $manual = false): InquiryRoutingStatus
    {
        $connection = $this->db->connection();

        [$status, $attributes] = $connection->transaction(
            fn (): array => $this->routeLocked($connection, (int) $inquiry->getKey(), $manual)
        );

        if ($attributes !== null) {
            $inquiry->setRawAttributes($attributes, true);
        }

        return $status;
    }

    /**
     * Walk the `pending` / `failed` backlog, oldest first, spam excluded — safe to run repeatedly.
     *
     * @return array{routed: int, pending: int, failed: int}
     */
    public function routePending(int $limit = 200): array
    {
        $result = ['routed' => 0, 'pending' => 0, 'failed' => 0];

        $ids = ContactInquiry::query()
            ->whereIn('routing_status', [InquiryRoutingStatus::Pending->value, InquiryRoutingStatus::Failed->value])
            ->where('is_spam', false)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');

        foreach ($ids as $id) {
            $inquiry = ContactInquiry::query()->find($id);

            if (! $inquiry instanceof ContactInquiry) {
                continue;
            }

            $status = $this->route($inquiry);

            match ($status) {
                InquiryRoutingStatus::Routed => $result['routed']++,
                InquiryRoutingStatus::Pending => $result['pending']++,
                InquiryRoutingStatus::Failed => $result['failed']++,
                InquiryRoutingStatus::NotApplicable => null,
            };
        }

        return $result;
    }

    /**
     * Is the target for this inquiry's type registered and available right now? Drives the admin
     * "Route now" button (disabled with a tooltip while it is not).
     */
    public function canRoute(ContactInquiry $inquiry): bool
    {
        $key = $this->typeOf($inquiry)?->routingTarget();
        $target = $key === null ? null : $this->target($key);

        if ($target === null || (bool) $inquiry->getAttribute('is_spam') || $inquiry->getAttribute('routed_id') !== null) {
            return false;
        }

        try {
            return $target->isAvailable();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The human reason an inquiry is waiting (`target_unregistered` → "Lead module not installed yet").
     */
    public function waitingReason(ContactInquiry $inquiry): ?string
    {
        $error = $inquiry->getAttribute('routing_error');
        $key = (string) $inquiry->getAttribute('routing_target');
        $module = $key === InquiryType::TARGET_COURSE_INQUIRY ? 'Course inquiry' : 'Lead';

        return match ($error) {
            null, '' => null,
            self::ERROR_UNREGISTERED => $module.' module not installed yet',
            self::ERROR_MODULE_DISABLED => ($key === InquiryType::TARGET_COURSE_INQUIRY ? 'Course inquiries' : 'Leads').' module is disabled',
            default => (string) $error,
        };
    }

    /**
     * @return array{0: InquiryRoutingStatus, 1: array<string, mixed>|null}
     */
    private function routeLocked(ConnectionInterface $connection, int $id, bool $manual): array
    {
        /** @var ContactInquiry|null $locked */
        $locked = ContactInquiry::query()->whereKey($id)->lockForUpdate()->first();

        // A trashed (or vanished) inquiry is never routed.
        if ($locked === null) {
            return [InquiryRoutingStatus::NotApplicable, null];
        }

        // 2. Spam is never routed, and nothing is touched.
        if ((bool) $locked->getAttribute('is_spam')) {
            return [InquiryRoutingStatus::NotApplicable, $locked->getAttributes()];
        }

        // 3. Already routed: idempotent.
        if ($locked->getAttribute('routed_id') !== null) {
            if ($this->statusOf($locked) !== InquiryRoutingStatus::Routed) {
                $this->write($connection, $locked, ['routing_status' => InquiryRoutingStatus::Routed->value, 'routing_error' => null]);
            }

            return [InquiryRoutingStatus::Routed, $locked->getAttributes()];
        }

        // 4. A type with no target.
        $key = $this->typeOf($locked)?->routingTarget();

        if ($key === null) {
            $this->write($connection, $locked, [
                'routing_status' => InquiryRoutingStatus::NotApplicable->value,
                'routing_error' => null,
            ]);

            return [InquiryRoutingStatus::NotApplicable, $locked->getAttributes()];
        }

        // 5. Resolve the target.
        $target = $this->target($key);

        if ($target === null) {
            $this->write($connection, $locked, [
                'routing_target' => $key,
                'routing_status' => InquiryRoutingStatus::Pending->value,
                'routing_error' => self::ERROR_UNREGISTERED,
                'routing_attempts' => $this->nextAttempt($locked),
            ]);

            return [InquiryRoutingStatus::Pending, $locked->getAttributes()];
        }

        try {
            if (! $target->isAvailable()) {
                $this->write($connection, $locked, [
                    'routing_target' => $key,
                    'routing_status' => InquiryRoutingStatus::Pending->value,
                    'routing_error' => self::ERROR_MODULE_DISABLED,
                ]);

                return [InquiryRoutingStatus::Pending, $locked->getAttributes()];
            }

            // 6. Create the target record and claim it — both inside one savepoint.
            $record = $connection->transaction(function () use ($connection, $locked, $target, $key): Model {
                $record = $target->handle($locked);

                if (! $record->exists || $record->getKey() === null) {
                    throw new RuntimeException(sprintf('Inquiry target [%s] returned an unsaved record.', $key));
                }

                $this->write($connection, $locked, [
                    'routing_target' => $key,
                    'routed_type' => mb_substr($record::class, 0, 255),
                    'routed_id' => (int) $record->getKey(),
                    'routed_at' => Carbon::now(),
                    'routing_status' => InquiryRoutingStatus::Routed->value,
                    'routing_error' => null,
                    'routing_attempts' => $this->nextAttempt($locked),
                ]);

                return $record;
            });
        } catch (DeadlockException $exception) {
            // InnoDB rolls back the whole transaction on a deadlock, not just the savepoint: nothing can be
            // recorded on the inquiry here. Let it fail as a whole; the job's retry routes it again.
            throw $exception;
        } catch (Throwable $exception) {
            // 7. The savepoint already rolled back the target record; record the failure on the inquiry.
            return $this->recordFailure($connection, $locked, $key, $exception, $manual);
        }

        event(new ContactInquiryRouted($locked, $record, $manual));

        return [InquiryRoutingStatus::Routed, $locked->getAttributes()];
    }

    /**
     * @return array{0: InquiryRoutingStatus, 1: array<string, mixed>}
     */
    private function recordFailure(ConnectionInterface $connection, ContactInquiry $inquiry, string $key, Throwable $exception, bool $manual): array
    {
        $attempts = $this->nextAttempt($inquiry);
        $status = $attempts >= self::MAX_ATTEMPTS ? InquiryRoutingStatus::Failed : InquiryRoutingStatus::Pending;
        $message = trim($exception->getMessage());
        $message = mb_substr($message === '' ? class_basename($exception) : $message, 0, 255);

        // The in-memory model may hold the claim the savepoint rolled back; restore the stored values.
        $inquiry->setAttribute('routed_type', null);
        $inquiry->setAttribute('routed_id', null);
        $inquiry->setAttribute('routed_at', null);

        $this->write($connection, $inquiry, [
            'routing_target' => $key,
            'routing_status' => $status->value,
            'routing_error' => $message,
            'routing_attempts' => $attempts,
        ]);

        Log::warning('Contact inquiry routing failed', [
            'contact_inquiry_id' => $inquiry->getKey(),
            'target' => $key,
            'attempts' => $attempts,
            'status' => $status->value,
            'error' => $message,
            'exception' => $exception::class,
        ]);

        $this->auditor->record(
            module: self::MODULE,
            description: sprintf('Routing to %s failed (attempt %d)', $key, $attempts),
            subject: $inquiry,
            properties: [
                'target' => $key,
                'attempts' => $attempts,
                'routing_status' => $status->value,
                'error' => $message,
                'manual' => $manual,
            ],
            event: 'routing_failed',
        );

        return [$status, $inquiry->getAttributes()];
    }

    /**
     * Write routing columns with one statement and mirror them onto the locked model.
     *
     * @param  array<string, mixed>  $values
     */
    private function write(ConnectionInterface $connection, ContactInquiry $inquiry, array $values): void
    {
        $connection->table($inquiry->getTable())->where('id', $inquiry->getKey())->update($values);

        foreach ($values as $column => $value) {
            $inquiry->setAttribute($column, $value);
        }

        $inquiry->syncOriginal();
    }

    private function nextAttempt(ContactInquiry $inquiry): int
    {
        return min(self::ATTEMPTS_CEILING, (int) $inquiry->getRawOriginal('routing_attempts', $inquiry->getAttribute('routing_attempts')) + 1);
    }

    private function typeOf(ContactInquiry $inquiry): ?InquiryType
    {
        $type = $inquiry->getAttribute('inquiry_type');

        return $type instanceof InquiryType ? $type : InquiryType::tryFrom((string) $type);
    }

    private function statusOf(ContactInquiry $inquiry): ?InquiryRoutingStatus
    {
        $status = $inquiry->getAttribute('routing_status');

        return $status instanceof InquiryRoutingStatus ? $status : InquiryRoutingStatus::tryFrom((string) $status);
    }
}
