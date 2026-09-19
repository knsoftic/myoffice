<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Crm\Concerns;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Models\Cms\Service;
use App\Models\User;
use App\Services\Crm\Exceptions\CrmRuleException;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * What every phase-05 admin CRM controller shares (phase-05 §7, §8, CLAUDE.md §1 rules 7 and 9, §6):
 *
 *   · explicit authorization (`AuthorizesRequests`) — each action repeats its route's `can:` and asks the policy
 *     for the record rule;
 *   · one response shape for a browser form and for the Alpine `fetch()` calls (board moves, dialogs, the duplicate
 *     check): JSON with `message` + data, or a redirect with a toast;
 *   · one place a service refusal becomes an answer the user can act on. The CRM services refuse a business rule
 *     with `CrmRuleException` (a `ValidationException`): JSON callers get Laravel's 422 error bag, a browser gets the
 *     errors **and** a toast. `StaleLeadStatusException` renders its own 409;
 *   · the `crm.*` settings a screen needs, read here and handed to the view as data — never read in a view.
 */
trait RespondsForCrm
{
    use AuthorizesRequests;

    /**
     * Run a service call; a CRM rule refusal becomes a 422 (JSON) or a redirect back with errors and a toast.
     *
     * @param  Closure(): Response  $action
     */
    protected function attempt(Request $request, Closure $action): Response
    {
        try {
            return $action();
        } catch (CrmRuleException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return $this->refuseValidation($exception);
        }
    }

    /**
     * A browser post refused by a rule: back to the form with the error bag, the input and a toast.
     */
    protected function refuseValidation(ValidationException $exception): RedirectResponse
    {
        $message = (string) (collect($exception->errors())->flatten()->first() ?? $exception->getMessage());

        return back()
            ->withInput()
            ->withErrors($exception->errors())
            ->with('toast', ['type' => 'error', 'message' => $message]);
    }

    /**
     * A refusal that is not a validation error: JSON with the status, or back with a toast.
     *
     * @param  array<string, mixed>  $data
     */
    protected function refuse(Request $request, string $message, int $status = 422, string $field = 'action', array $data = []): Response
    {
        if ($request->expectsJson()) {
            $payload = array_merge(['message' => $message], $data);

            if ($status === Response::HTTP_UNPROCESSABLE_ENTITY) {
                $payload['errors'] = [$field => [$message]];
            }

            return new JsonResponse($payload, $status);
        }

        $redirect = back()->with('toast', ['type' => 'error', 'message' => $message]);

        return $status === Response::HTTP_UNPROCESSABLE_ENTITY ? $redirect->withInput()->withErrors([$field => $message]) : $redirect;
    }

    /**
     * A success: JSON (`message` plus any data) for a `fetch()`, otherwise a redirect with a toast.
     *
     * @param  array<string, mixed>  $data
     */
    protected function done(Request $request, string $message, ?RedirectResponse $redirect = null, array $data = [], string $type = 'success'): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse(array_merge(['message' => $message], $data));
        }

        return ($redirect ?? back())->with('toast', ['type' => $type, 'message' => $message]);
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, Response::HTTP_FORBIDDEN);

        return $actor;
    }

    /**
     * A record the user may not see is a 404, never a 403 — ids cannot be probed (resolutions §8 #5).
     */
    protected function abortUnlessVisible(bool $visible): void
    {
        abort_unless($visible, Response::HTTP_NOT_FOUND);
    }

    /**
     * Rows per page for a list screen (`appearance.table_page_size`, clamped by the helper).
     */
    protected function perPage(): int
    {
        return per_page();
    }

    /*
    |--------------------------------------------------------------------------
    | crm.* settings (phase-05 §5) — read in PHP, handed to views as data
    |--------------------------------------------------------------------------
    */

    protected function crmSetting(string $key, mixed $default = null): mixed
    {
        try {
            return settings_repo()->get('crm.'.$key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    protected function crmInt(string $key, int $default, int $min = 0): int
    {
        $value = $this->crmSetting($key, $default);

        return max($min, is_numeric($value) ? (int) $value : $default);
    }

    protected function crmBool(string $key, bool $default): bool
    {
        $value = $this->crmSetting($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * A list-valued setting (`lost_reasons`, `lead_statuses_on_board`), tolerant of a JSON or comma string.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    protected function crmList(string $key, array $default): array
    {
        $value = $this->crmSetting($key, $default);

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (! is_array($value)) {
            return $default;
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * `crm.whatsapp_link_template` — every WhatsApp button is built from it; no URL is hardcoded anywhere.
     */
    protected function whatsappTemplate(): ?string
    {
        $template = $this->crmSetting('whatsapp_link_template', 'https://wa.me/{phone}');

        return is_string($template) && trim($template) !== '' ? trim($template) : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Shared view data
    |--------------------------------------------------------------------------
    */

    /**
     * `LeadStatus` value => label, in board order.
     *
     * @return array<string, string>
     */
    protected function statusOptions(): array
    {
        $cases = LeadStatus::cases();
        usort($cases, static fn (LeadStatus $a, LeadStatus $b): int => $a->sortOrder() <=> $b->sortOrder());

        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * The §2.11 table as plain values, for the optimistic UI and the "Move to" menus.
     *
     * @return array<string, list<string>>
     */
    protected function transitions(): array
    {
        $table = [];

        foreach (LeadStatus::cases() as $status) {
            $table[$status->value] = array_map(static fn (LeadStatus $to): string => $to->value, $status->allowedTransitions());
        }

        return $table;
    }

    /**
     * The statuses `crm.require_follow_up_on_contacted` guards (§2.11), or none when the setting is off.
     *
     * @return list<string>
     */
    protected function followUpRequiredStatuses(): array
    {
        if (! $this->crmBool('require_follow_up_on_contacted', true)) {
            return [];
        }

        return [
            LeadStatus::Contacted->value,
            LeadStatus::Interested->value,
            LeadStatus::Negotiation->value,
            LeadStatus::ProposalSent->value,
        ];
    }

    /**
     * The five manual activity types, value => label.
     *
     * @return array<string, string>
     */
    protected function manualActivityTypeOptions(): array
    {
        $options = [];

        foreach (LeadActivityType::manual() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }

    /**
     * `crm.lost_reasons` — the select on the lost dialog (free text is still allowed).
     *
     * @return list<string>
     */
    protected function lostReasons(): array
    {
        return $this->crmList('lost_reasons', ['Budget', 'Timeline', 'Chose a competitor', 'No response', 'Not a fit', 'Duplicate', 'Other']);
    }

    /**
     * The follow-up scheduler's pre-filled value: now + `crm.follow_up_default_offset_hours`, as `Y-m-d\TH:i` in the
     * display timezone (the `datetime-local` input format).
     */
    protected function followUpDefaultAt(): string
    {
        $hours = $this->crmInt('follow_up_default_offset_hours', 24);

        return CarbonImmutable::now(Format::displayTimezone())->addHours($hours)->startOfHour()->format('Y-m-d\TH:i');
    }

    /**
     * Active users who hold `$permission` on their own grants, id => name — the people a lead or client may be
     * assigned to. Nobody outside this list can be chosen: the Form Requests apply the same rule.
     *
     * @return array<int, string>
     */
    protected function usersHolding(string $permission): array
    {
        return User::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'status'])
            ->filter(static fn (User $user): bool => $user->can($permission))
            ->mapWithKeys(static fn (User $user): array => [(int) $user->getKey() => (string) $user->name])
            ->all();
    }

    /**
     * The Phase 4 service catalogue for "interested service", id => name; empty while the table is absent.
     *
     * @return array<int, string>
     */
    protected function serviceOptions(): array
    {
        try {
            return Service::query()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * A LIKE pattern with the wildcards of the term itself escaped.
     */
    protected function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
    }
}
