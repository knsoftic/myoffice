<?php

declare(strict_types=1);

namespace App\Notifications;

use App\DataObjects\Support\ChannelSet;
use App\Enums\NotificationGroup;
use App\Enums\NotificationLevel;
use App\Enums\PanelType;
use Closure;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * One notifiable event, declared once (phase-19-23 §6.19, §10.3).
 *
 * **This is a declaration, not a table.** `PermissionRegistry`, `SettingsRegistry` and
 * `DashboardRegistry` all took the same shape and for the same reason: a list that lives in code is
 * one a `grep` can answer questions about, a migration cannot drift from, and a code review can see
 * changing. The rows this produces — preferences, bell entries — are data; the catalogue is not.
 *
 * **`module` is what makes a disabled module silent.** An institute that has switched the institute
 * off should not receive "a result was published"; the event names its module and
 * `NotificationService` drops it before resolving a single recipient.
 *
 * **`requiredPermission` drops a recipient, not the event.** A collaborator commission notification
 * needs `collaborator_portal.student_commission`: without it the row would appear in the bell,
 * linking to a page that 403s. Somebody else in the same audience may still hold it, so the answer
 * is per recipient.
 *
 * **`mandatory` cannot be switched off, and the list is short on purpose.** A reversed commission, a
 * paid payout and a breached SLA are the three where "I never saw it" is a dispute rather than an
 * inconvenience. Everything else is the person's own choice, because a preference screen where most
 * rows are locked is a preference screen nobody believes.
 */
final readonly class NotificationEvent
{
    /**
     * @param  string  $key  `meeting.invited` — stable; preferences and bell rows are filed under it
     * @param  string  $module  the module slug that gates it
     * @param  ?string  $notification  the class a phase already ships, when it ships one
     * @param  ?Closure  $factory  `fn (array $payload, self $event): Notification` — overrides the generic one
     * @param  string|Closure|null  $audience  `permission:<name>`, or `fn (array $payload): iterable`
     * @param  ?Closure  $urlBuilder  `fn (array $payload): ?string` — the deep link in the bell
     * @param  ?string  $requiredPermission  a recipient without it gets no row at all
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public NotificationGroup $group,
        public string $module,
        public NotificationLevel $level = NotificationLevel::Info,
        public ?string $notification = null,
        public ?Closure $factory = null,
        public string|Closure|null $audience = null,
        public ChannelSet $defaultChannels = new ChannelSet(true, false),
        public bool $mandatory = false,
        public ?Closure $urlBuilder = null,
        public ?string $requiredPermission = null,
        /**
         * The panels this event can reach. Empty means every panel.
         *
         * **`requiredPermission` alone cannot answer "could this ever reach you".** A student holds
         * no permission at all on `meetings`, `messages` or `support_tickets` — their access runs
         * through `student_portal.*` — yet they are invited to meetings and raise tickets every
         * day. Gating the preference screen on module permissions would hide exactly the rows they
         * most need. Gating on nothing shows them "a wallet disagrees with its ledger".
         *
         * So the registry says it outright. This is §6.19's "whose audience can include this user",
         * written down rather than inferred from a permission that was never about audience.
         *
         * @var list<PanelType>
         */
        public array $panels = [],
        /** The phase that triggers it — documentation for the §10.3 table, and for a reviewer. */
        public ?int $ownedByPhase = null,
    ) {}

    /**
     * Could somebody on these panels ever be in this event's audience?
     *
     * @param  list<PanelType>  $held
     */
    public function reachesAnyOf(array $held): bool
    {
        if ($this->panels === []) {
            return true;
        }

        foreach ($held as $panel) {
            foreach ($this->panels as $allowed) {
                if ($panel === $allowed) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The deep link for one payload, or null.
     *
     * **A builder that throws returns null rather than taking the notification down with it.** The
     * link is decoration; a route renamed under a builder nobody updated should cost the person a
     * click, not the message. The failure is worth knowing about, so it is logged by the service
     * rather than swallowed here.
     *
     * @param  array<string, mixed>  $payload
     */
    public function url(array $payload): ?string
    {
        if ($this->urlBuilder === null) {
            return $payload['url'] ?? null;
        }

        try {
            $url = ($this->urlBuilder)($payload);
        } catch (Throwable) {
            return $payload['url'] ?? null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** Is this event's audience declared as "whoever holds this permission"? */
    public function audiencePermission(): ?string
    {
        return is_string($this->audience) && str_starts_with($this->audience, 'permission:')
            ? mb_substr($this->audience, 11)
            : null;
    }

    /**
     * The channels this event asks for before any preference is applied.
     *
     * A mandatory event's `database` is always on, whatever the declaration says — the declaration
     * cannot be the thing that lets a mandatory event go unrecorded.
     */
    public function defaults(): ChannelSet
    {
        return $this->mandatory && ! $this->defaultChannels->database
            ? new ChannelSet(true, $this->defaultChannels->mail, $this->defaultChannels->digest)
            : $this->defaultChannels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'description' => $this->description,
            'group' => $this->group->value,
            'module' => $this->module,
            'level' => $this->level->value,
            'mandatory' => $this->mandatory,
            'database' => $this->defaultChannels->database,
            'mail' => $this->defaultChannels->mail,
            'required_permission' => $this->requiredPermission,
            'panels' => array_map(static fn (PanelType $panel): string => $panel->value, $this->panels),
            'phase' => $this->ownedByPhase,
        ];
    }
}
