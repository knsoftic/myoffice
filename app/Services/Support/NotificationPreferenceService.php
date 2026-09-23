<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\DataObjects\Support\PreferenceMatrix;
use App\Enums\NotificationDigest;
use App\Models\User;
use App\Notifications\NotificationEvent;
use App\Support\NotificationRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The §97 preference screen, and the only writer of `notification_preferences` (§6.19).
 *
 * **A missing row is the registry default, not "off".** Nothing is written when somebody first
 * appears: a new user inherits whatever the event declares, and the day an event's default changes
 * they inherit that too. Seeding a row per user per event at registration would freeze every one of
 * them at the value that happened to be declared that afternoon, and there are fifty-odd events and
 * potentially thousands of users.
 *
 * **A row is written only where the person differs from the default**, and one that comes back to
 * agreeing with it is deleted rather than kept. Otherwise the table fills with rows that say nothing
 * and the question "who has changed this?" becomes unanswerable.
 *
 * **A mandatory event cannot be switched off, and the attempt is ignored rather than refused.** The
 * screen renders those rows locked, so a post that tries anyway is either a stale form or somebody
 * with the developer tools open — neither is worth a 422, and both are worth not obeying.
 */
final class NotificationPreferenceService
{
    /**
     * Rows by user id — one dispatch to twenty people asks twenty times for the same table.
     *
     * **This cache lives here and nowhere else**, which is the point of it being here at all.
     * `NotificationService` kept its own copy first, and within one request a preference saved and
     * then dispatched against read the copy from before the save: the person switched an event off
     * and the very next one still arrived. A cache with two owners has one of them holding a stale
     * answer the moment the other writes, so the writer and the reader are now the same class and
     * {@see self::update()} empties it.
     *
     * @var array<int, array<string, object>>
     */
    private array $cache = [];

    /**
     * The whole screen: every event this person could receive, with their answer applied.
     */
    public function matrixFor(User $user): PreferenceMatrix
    {
        $events = NotificationRegistry::forUser($user);
        $rows = $this->rowsFor($user);
        $mailAvailable = (bool) setting('support.notifications_mail_enabled', false);

        $resolved = [];

        foreach ($events as $key => $event) {
            $row = $rows[$key] ?? null;
            $defaults = $event->defaults();

            $database = $row !== null ? (bool) $row->database_enabled : $defaults->database;
            $mail = $row !== null ? (bool) $row->mail_enabled : $defaults->mail;
            $digest = $row !== null
                ? (NotificationDigest::tryFrom((string) $row->mail_digest) ?? NotificationDigest::Immediate)
                : $defaults->digest;

            $resolved[$key] = [
                'event' => $event,
                // A mandatory event reads as on and locked, whatever the row underneath says —
                // including a row written before the event became mandatory.
                'database' => $event->mandatory ? true : $database,
                // Mandatory forces the bell on and leaves mail alone: the record is the guarantee,
                // and an installation with no mail server cannot honour a forced mail anyway.
                'mail' => $mail,
                'digest' => $digest,
                'locked' => $event->mandatory,
                'customised' => $row !== null,
            ];
        }

        return new PreferenceMatrix($resolved, $mailAvailable);
    }

    /**
     * Save what the screen posted.
     *
     * @param  array<string, array<string, mixed>>  $rows  event key => {database, mail, digest}
     * @return array{saved: int, cleared: int, ignored: list<string>}
     */
    public function update(User $user, array $rows): array
    {
        $events = NotificationRegistry::forUser($user);
        $now = Carbon::now();

        $saved = 0;
        $cleared = 0;
        $ignored = [];

        foreach ($rows as $key => $values) {
            $event = $events[$key] ?? null;

            if (! $event instanceof NotificationEvent) {
                // An unknown key, or an event this person cannot receive. Named in the result so a
                // controller can say so, rather than saved into a row nothing will ever read.
                $ignored[] = (string) $key;

                continue;
            }

            $defaults = $event->defaults();

            $database = $event->mandatory ? true : (bool) ($values['database'] ?? $defaults->database);
            $mail = (bool) ($values['mail'] ?? $defaults->mail);
            $digest = $values['digest'] ?? $defaults->digest;
            $digest = $digest instanceof NotificationDigest
                ? $digest
                : (NotificationDigest::tryFrom((string) $digest) ?? NotificationDigest::Immediate);

            if ($event->mandatory && ! ($values['database'] ?? true)) {
                // Recorded, not obeyed. The row is still written with `database` on.
                $ignored[] = $key.' (mandatory)';
            }

            $isDefault = $database === $defaults->database
                && $mail === $defaults->mail
                && $digest === $defaults->digest;

            if ($isDefault) {
                $cleared += DB::table('notification_preferences')
                    ->where('user_id', $user->getKey())
                    ->where('event_key', $key)
                    ->delete();

                continue;
            }

            DB::table('notification_preferences')->updateOrInsert(
                ['user_id' => $user->getKey(), 'event_key' => $key],
                [
                    'database_enabled' => $database,
                    'mail_enabled' => $mail,
                    'mail_digest' => $digest->value,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );

            $saved++;
        }

        $this->forget($user);

        return ['saved' => $saved, 'cleared' => $cleared, 'ignored' => $ignored];
    }

    /**
     * Back to the registry's defaults, by deleting every row.
     *
     * Deleting rather than rewriting is the point: a reset should put the person back on whatever
     * the event declares *today and tomorrow*, and a row written with today's values would pin them
     * to it the next time a default changes.
     */
    public function resetToDefaults(User $user): int
    {
        $deleted = DB::table('notification_preferences')
            ->where('user_id', $user->getKey())
            ->delete();

        $this->forget($user);

        return $deleted;
    }

    /** A long-running command that outlives a preference somebody changed in another process. */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * One event's answer for one person, without building the whole screen.
     *
     * @return array{database: bool, mail: bool, digest: NotificationDigest}
     */
    public function resolve(User $user, string $eventKey): array
    {
        $event = NotificationRegistry::event($eventKey);
        $defaults = $event?->defaults() ?? NotificationRegistry::defaultsFor($eventKey);
        $row = $this->rowsFor($user)[$eventKey] ?? null;

        if ($row === null) {
            return ['database' => $defaults->database, 'mail' => $defaults->mail, 'digest' => $defaults->digest];
        }

        return [
            'database' => $event?->mandatory === true ? true : (bool) $row->database_enabled,
            'mail' => (bool) $row->mail_enabled,
            'digest' => NotificationDigest::tryFrom((string) $row->mail_digest) ?? NotificationDigest::Immediate,
        ];
    }

    // ===============================================================================================

    /**
     * @return array<string, object>
     */
    private function rowsFor(User $user): array
    {
        $id = (int) $user->getKey();

        if (! array_key_exists($id, $this->cache)) {
            $this->cache[$id] = DB::table('notification_preferences')
                ->where('user_id', $id)
                ->get()
                ->keyBy(static fn (object $row): string => (string) $row->event_key)
                ->all();
        }

        return $this->cache[$id];
    }

    private function forget(User $user): void
    {
        unset($this->cache[(int) $user->getKey()]);
    }
}
