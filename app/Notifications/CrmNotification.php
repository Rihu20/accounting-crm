<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CrmNotification extends Notification
{
    public function __construct(public string $title, public string $body, public string $url) {}

    public function via(object $notifiable): array
    {
        return config('crm.mail_enabled') ? ['database', 'mail'] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['format' => 'filament', 'title' => $this->title, 'body' => $this->body, 'actions' => [['name' => 'view', 'label' => 'Open record', 'url' => $this->url, 'shouldOpenUrlInNewTab' => false]], 'duration' => 'persistent'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title)->line($this->body)->action('Open CRM', $this->url);
    }
}
