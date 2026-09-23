<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Notifications\Channels\RichDatabaseChannel;
use App\Notifications\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The notification every registry event gets unless its phase ships a richer one (§6.19, §10.3).
 *
 * **Sixty-odd events do not need sixty-odd classes.** A bell row is a title, a line of body, a level
 * and a link; writing that sixty times produces sixty places for the module slug to be spelled
 * differently and the deep link to be built by hand. The registry already declares all four, so this
 * reads them. A phase whose message needs more — a table of figures, an attached PDF, a subject line
 * with a document number — declares a `factory` on its event and ships its own class; the registry
 * still owns the key, the channels and the preference row.
 *
 * **`ShouldQueue` is not optional** (INV-22-8). A notification sent inline is a business write that
 * can fail because a mail server is down, and "the payment was not recorded because the receipt
 * could not be emailed" is the wrong trade every time.
 *
 * **The payload is written into `data` verbatim, minus the keys the columns already hold.** Storing
 * `level` and `url` twice would let the two disagree after an edit, and the columns are the ones the
 * bell query reads.
 */
class RegistryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Keys promoted to their own column, so they are not duplicated inside `data`. */
    private const PROMOTED = ['title', 'body', 'url', 'level', 'module', 'event_key', 'actor_id'];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly NotificationEvent $event,
        public readonly array $payload = [],
        public readonly ?int $actorId = null,
        /** @var list<string> */
        public readonly array $channels = ['database'],
    ) {}

    /**
     * The `database` channel is this system's richer one — see {@see RichDatabaseChannel} for why
     * the five bell columns cannot be filled after the fact.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return array_map(
            static fn (string $channel): string => $channel === 'database' ? RichDatabaseChannel::class : $channel,
            $this->channels,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $extra = array_diff_key($this->payload, array_flip(self::PROMOTED));

        return [
            'event_key' => $this->event->key,
            'title' => $this->title(),
            'body' => $this->body(),
            'level' => $this->event->level->value,
            'module' => $this->event->module,
            'url' => $this->event->url($this->payload),
            'icon' => $this->event->level->icon(),
            'actor_id' => $this->actorId,
        ] + $extra;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting($this->greeting())
            ->line($this->body());

        $url = $this->event->url($this->payload);

        if ($url !== null) {
            $mail->action($this->payload['action'] ?? 'Open it', $url);
        }

        // A critical message says so in the mail as well as in the bell: somebody skimming a
        // mailbox should not have to open it to find out it matters.
        return $this->event->level->demandsAttention()
            ? $mail->error()
            : $mail;
    }

    /**
     * The columns the row is written with — read by {@see RichDatabaseChannel} at insert time.
     *
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    public function databaseColumns($notifiable): array
    {
        $url = $this->event->url($this->payload);

        return [
            'event_key' => mb_substr($this->event->key, 0, 64),
            'module' => mb_substr($this->event->module, 0, 64),
            'level' => $this->event->level->value,
            'url' => $url !== null ? mb_substr($url, 0, 500) : null,
            'actor_id' => $this->actorId,
        ];
    }

    // ===============================================================================================

    private function title(): string
    {
        $title = trim((string) ($this->payload['title'] ?? ''));

        return $title === '' ? $this->event->title : $title;
    }

    private function body(): string
    {
        $body = trim((string) ($this->payload['body'] ?? ''));

        return $body === '' ? $this->event->description : $body;
    }

    private function greeting(): string
    {
        $name = $this->payload['greeting'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : $this->event->title;
    }
}
