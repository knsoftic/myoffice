<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Support\NotificationRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One mail summarising the day's batched notifications (§6.19, §10.5 `notifications:digest`).
 *
 * **Mail only, and deliberately so.** Every row in this digest already exists in the bell — it was
 * written when the event happened. Writing a *second* database row saying "here are the rows you
 * already have" would double the bell's count and make the unread badge a lie. The digest is a
 * different delivery of the same facts, not a new fact.
 *
 * **It is grouped by the registry's own groups**, so a person who batches twelve events reads four
 * headings rather than twelve lines in arrival order. The registry is asked for the title, because
 * a digest that invented its own wording would disagree with the bell entry it is summarising.
 *
 * **`ShouldQueue`, like everything else here.** A digest is sent to everybody who asked for one on
 * one schedule tick; sending them inline would make that tick as long as the mail server is slow.
 */
final class DailyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<object>  $rows  raw `notifications` rows, newest first
     */
    public function __construct(
        public readonly array $rows,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->rows);

        $mail = (new MailMessage)
            ->subject(sprintf('%d notification%s from today', $count, $count === 1 ? '' : 's'))
            ->greeting('Your daily summary')
            ->line(sprintf(
                'These arrived since yesterday. You asked to receive them as one message rather than %s.',
                $count === 1 ? 'on its own' : 'one at a time',
            ));

        foreach ($this->grouped() as $group => $lines) {
            $mail->line('**'.$group.'**');

            foreach ($lines as $line) {
                $mail->line('· '.$line);
            }
        }

        return $mail->line('Everything here is also in your notifications, where you can open each one.');
    }

    /**
     * The rows as `group title => list of one-line summaries`.
     *
     * An event the registry no longer knows — a key retired since the row was written — falls under
     * its own heading rather than being dropped. The row exists and the person was told; hiding it
     * because the catalogue moved on would make the digest quietly incomplete.
     *
     * @return array<string, list<string>>
     */
    private function grouped(): array
    {
        $grouped = [];

        foreach ($this->rows as $row) {
            $key = (string) ($row->event_key ?? '');
            $event = NotificationRegistry::event($key);
            $heading = $event?->group->label() ?? 'Other';

            $data = json_decode((string) ($row->data ?? '{}'), true) ?: [];
            $title = trim((string) ($data['title'] ?? '')) ?: ($event?->title ?? $key);
            $body = trim((string) ($data['body'] ?? ''));

            $grouped[$heading][] = $body === '' ? $title : $title.' — '.$body;
        }

        return $grouped;
    }
}
