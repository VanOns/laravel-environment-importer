<?php

namespace VanOns\LaravelEnvironmentImporter\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class ImportSucceeded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Carbon $startedAt,
        protected Carbon $finishedAt,
    ) {
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage())
            ->subject('Environment imported successfully')
            ->line('The environment import was successful! Please find the details below.')
            ->line(new HtmlString('<strong>Started at:</strong> ' . $this->startedAt->toString()))
            ->line(new HtmlString('<strong>Finished at:</strong> ' . $this->finishedAt->toString()));
    }
}
