<?php

declare(strict_types=1);

namespace App\Notifications\Cms;

use App\Enums\InquiryType;
use App\Models\Cms\ContactInquiry;
use App\Notifications\Cms\Concerns\BuildsCmsNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A new inquiry arrived" (phase-04 §10.2) — to users holding `contact_inquiries.view_any` (database +
 * mail) and to every address in `website.contact_notify_emails` (mail only).
 *
 * Carries the type, the visitor's name, the subject and the first 200 characters of the message, plus a
 * deep link to the inquiry. Never sent for spam, and never sent to the visitor (there is no auto-reply).
 * The technical block (IP, user agent, UTM) is deliberately not included: it is gated by
 * `contact_inquiries.view_logs` on the admin screen and must not leak through a mailbox.
 */
final class NewContactInquiryNotification extends Notification
{
    use BuildsCmsNotification;

    public function __construct(
        public readonly ContactInquiry $inquiry,
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
        $inquiry = $this->inquiry;

        return (new MailMessage)
            ->subject(sprintf('New %s inquiry from %s', mb_strtolower($this->typeLabel()), (string) $inquiry->getAttribute('name')))
            ->greeting('New website inquiry')
            ->line(sprintf('Type: %s', $this->typeLabel()))
            ->line(sprintf('From: %s', (string) $inquiry->getAttribute('name')))
            ->when(filled($inquiry->getAttribute('subject')), fn (MailMessage $mail) => $mail->line(sprintf('Subject: %s', (string) $inquiry->getAttribute('subject'))))
            ->line($this->excerpt((string) $inquiry->getAttribute('message')))
            ->action('Open the inquiry', $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $inquiry = $this->inquiry;

        return [
            'kind' => 'contact_inquiry.new',
            'module' => 'contact_inquiries',
            'contact_inquiry_id' => (int) $inquiry->getKey(),
            'inquiry_type' => $this->typeValue(),
            'title' => sprintf('New %s inquiry', mb_strtolower($this->typeLabel())),
            'name' => (string) $inquiry->getAttribute('name'),
            'subject' => $inquiry->getAttribute('subject'),
            'excerpt' => $this->excerpt((string) $inquiry->getAttribute('message')),
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        $id = (int) $this->inquiry->getKey();

        return $this->link('admin.contact-inquiries.show', ['inquiry' => $id], '/admin/contact-inquiries/'.$id);
    }

    private function typeValue(): string
    {
        $type = $this->inquiry->getAttribute('inquiry_type');

        return $type instanceof InquiryType ? $type->value : (string) $type;
    }

    private function typeLabel(): string
    {
        return InquiryType::tryFrom($this->typeValue())?->label() ?? 'Website';
    }
}
