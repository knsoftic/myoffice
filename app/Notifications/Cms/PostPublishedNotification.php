<?php

declare(strict_types=1);

namespace App\Notifications\Cms;

use App\Models\Cms\BlogPost;
use App\Notifications\Cms\Concerns\BuildsCmsNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your scheduled post went live" (phase-04 §10.2) — database + mail, to the post's author, sent only
 * when `blog:publish-scheduled` published it (a person who clicks Publish already knows).
 */
final class PostPublishedNotification extends Notification
{
    use BuildsCmsNotification;

    public function __construct(
        public readonly BlogPost $post,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, ['database', 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(sprintf('Your scheduled post went live: %s', (string) $this->post->getAttribute('title')))
            ->greeting('Your post is live')
            ->line(sprintf('"%s" was published on schedule.', (string) $this->post->getAttribute('title')))
            ->action('View the post', $this->publicUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'blog_post.published',
            'module' => 'blog_posts',
            'blog_post_id' => (int) $this->post->getKey(),
            'title' => 'Your scheduled post went live',
            'post_title' => (string) $this->post->getAttribute('title'),
            'url' => $this->publicUrl(),
        ];
    }

    private function publicUrl(): string
    {
        $slug = (string) $this->post->getAttribute('slug');

        return $this->link('site.blog.show', ['blogPost' => $slug], '/blog/'.rawurlencode($slug));
    }
}
