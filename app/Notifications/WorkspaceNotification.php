<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $kind, public string $title, public string $url, public ?string $dueDate = null, public array $extra = []) {}

    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title)
            ->greeting('Poštovani,')
            ->line($this->title);

        if ($this->dueDate) {
            $message->line('Datum: '.$this->dueDate);
        }

        foreach ($this->extra['lines'] ?? [] as $line) {
            $message->line($line);
        }

        return $message->action('Otvori u aplikaciji', $this->url)
            ->salutation(config('app.name'));
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'url' => $this->url, 'due_date' => $this->dueDate, 'extra' => $this->extra];
    }

    private function channelsFor(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }

        $channels = $notifiable->notificationSettingFor()->channels();

        // E-mail je opcionalan pri kreiranju korisnika; bez adrese mail kanal bi rušio queue.
        if (blank($notifiable->email)) {
            $channels = array_values(array_diff($channels, ['mail']));
        }

        return $channels;
    }
}
