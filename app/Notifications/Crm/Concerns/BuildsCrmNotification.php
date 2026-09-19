<?php

declare(strict_types=1);

namespace App\Notifications\Crm\Concerns;

use App\Notifications\Cms\Concerns\BuildsCmsNotification;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * What the phase-05 notifications share (§10.3): database channel now, mail-ready.
 *
 * Built on phase-04's `BuildsCmsNotification` (named route links with a literal fallback, excerpts, the
 * `notifications`-table check) rather than a second copy of it. The one CRM rule on top: a notification that asks
 * for the `database` channel while the `notifications` table does not exist yet (Phase 22 owns it) is **mailed
 * instead**, so a follow-up reminder is never silently dropped before the notification centre ships.
 */
trait BuildsCrmNotification
{
    use BuildsCmsNotification;

    /**
     * @param  list<string>  $wanted  e.g. `crm.follow_up_reminder_channels`
     * @return list<string>
     */
    protected function crmChannels(object $notifiable, array $wanted = ['database']): array
    {
        $wanted = array_values(array_intersect(array_unique($wanted), ['database', 'mail']));

        if ($wanted === []) {
            $wanted = ['database'];
        }

        if ($notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        $channels = $this->channelsFor($notifiable, $wanted);

        if ($channels === [] && in_array('database', $wanted, true)) {
            return ['mail'];
        }

        return $channels;
    }
}
