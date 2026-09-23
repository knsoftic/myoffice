<?php

declare(strict_types=1);

namespace App\DataObjects\Support;

use App\Enums\NotificationDigest;

/**
 * Which channels one event uses for one person (phase-19-23 §6.19).
 *
 * **`database` is not a channel anybody may switch off.** The bell is the record that the person was
 * told; an event they turned the bell off for would have happened with nothing anywhere saying so,
 * and the first question after any dispute is "were they notified?". Mail is the channel a person
 * genuinely chooses — `$mail` here is a *want*, and the master switch and the event's own
 * `mandatory` flag both have a say before anything is sent.
 *
 * **`digest` only ever means something for mail.** Batching an in-app row would be batching the
 * record itself, and a daily digest of things that already happened is a mailbox concern.
 */
final readonly class ChannelSet
{
    public function __construct(
        public bool $database = true,
        public bool $mail = false,
        public NotificationDigest $digest = NotificationDigest::Immediate,
    ) {}

    public static function databaseOnly(): self
    {
        return new self(true, false);
    }

    public static function databaseAndMail(NotificationDigest $digest = NotificationDigest::Immediate): self
    {
        return new self(true, true, $digest);
    }

    public function withMail(bool $mail, ?NotificationDigest $digest = null): self
    {
        return new self($this->database, $mail, $digest ?? $this->digest);
    }

    /**
     * The Laravel channel names this resolves to, right now.
     *
     * A digest that is not `immediate` sends no mail from here: `NotificationDigest::Daily` is
     * collected by the scheduled digest job and `Off` is a refusal, so returning `mail` for either
     * would send the message twice or send one the person asked not to receive.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        $channels = [];

        if ($this->database) {
            $channels[] = 'database';
        }

        if ($this->mail && $this->digest === NotificationDigest::Immediate) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function sendsAnything(): bool
    {
        return $this->channels() !== [];
    }
}
