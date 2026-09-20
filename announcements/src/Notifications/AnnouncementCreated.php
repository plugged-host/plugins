<?php

namespace Boy132\Announcements\Notifications;

use App\Models\User;
use Boy132\Announcements\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AnnouncementCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Announcement $announcement) {}

    /** @return string[] */
    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->language ?? 'en';

        $mailMessage = (new MailMessage())
            ->subject($this->announcement->title)
            ->greeting(trans('mail.greeting', ['name' => $notifiable->username], $locale))
            ->line($this->announcement->body ?? $this->announcement->title);

        if ($this->announcement->url_label && $this->announcement->url_link) {
            $mailMessage->action($this->announcement->url_label, $this->announcement->url_link);
        }

        return $mailMessage;
    }
}
