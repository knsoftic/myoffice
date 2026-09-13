<?php

declare(strict_types=1);

namespace App\Notifications\Cms;

use App\Models\Cms\Page;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * phase-03 §10.3 — a scheduled page went live by itself (`cms:publish-scheduled`).
 *
 * Sent by `ContentPublisher::publishDue()` to the page's `created_by` and to every active holder of
 * `pages.change_status`. The contract names the database channel, mail-ready (§97). Phase 22 owns
 * the `notifications` table: until it exists the notification is mailed instead, so it is never lost
 * and never fails the promotion that triggered it.
 */
final class ScheduledPagePublished extends Notification
{
    use Queueable;

    private static ?bool $databaseChannelAvailable = null;

    public function __construct(
        public readonly int $pageId,
        public readonly string $title,
        public readonly string $slug,
    ) {}

    public static function forPage(Page $page): self
    {
        return new self((int) $page->getKey(), (string) $page->title, (string) $page->slug);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return self::databaseChannelAvailable() ? ['database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(sprintf('Scheduled page published: %s', $this->title))
            ->line(sprintf('The page "%s" reached its scheduled time and is now live on the website.', $this->title))
            ->action('Open the page', $this->pageUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'cms.scheduled_page_published',
            'page_id' => $this->pageId,
            'title' => $this->title,
            'slug' => $this->slug,
            'url' => $this->pageUrl(),
        ];
    }

    private function pageUrl(): string
    {
        try {
            if (Route::has('admin.website.pages.edit')) {
                return route('admin.website.pages.edit', ['page' => $this->pageId]);
            }
        } catch (Throwable) {
            // fall through to the public URL
        }

        return url('/'.$this->slug);
    }

    private static function databaseChannelAvailable(): bool
    {
        if (self::$databaseChannelAvailable === null) {
            try {
                self::$databaseChannelAvailable = Schema::hasTable('notifications');
            } catch (Throwable) {
                self::$databaseChannelAvailable = false;
            }
        }

        return self::$databaseChannelAvailable;
    }
}
