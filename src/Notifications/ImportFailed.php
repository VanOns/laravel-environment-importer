<?php

namespace VanOns\LaravelEnvironmentImporter\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class ImportFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Carbon $startedAt,
        protected Carbon $failedAt,
        protected string $exception
    ) {
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage())
            ->subject('Environment not imported')
            ->line('The environment import was not successful. Please find the details below.')
            ->line(new HtmlString('<strong>Started at:</strong> ' . $this->startedAt->toString()))
            ->line(new HtmlString('<strong>Failed at:</strong> ' . $this->failedAt->toString()))
            ->line(new HtmlString('<strong>Exception:</strong> ' . $this->exception));
    }
}
