<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DailyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $overdueCases
     * @param  array<int, string>  $overdueTasks
     * @param  array<int, string>  $dueSoonTasks
     * @param  array<int, string>  $waitingItems
     */
    public function __construct(public string $digestDate, public array $overdueCases, public array $overdueTasks, public array $dueSoonTasks, public array $waitingItems) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Dnevni sažetak otvorenih obaveza — '.$this->digestDate)
            ->greeting('Poštovani,')
            ->line('Dnevni sažetak otvorenih obaveza za '.$this->digestDate.'.');

        $message->line('## Prekoračeni rokovi');
        if ($this->overdueTasks === [] && $this->overdueCases === []) {
            $message->line('Nema prekoračenih rokova.');
        } else {
            foreach ($this->overdueCases as $case) {
                $message->line('- [Postupak] '.$case);
            }
            foreach ($this->overdueTasks as $task) {
                $message->line('- [Stavka] '.$task);
            }
        }

        $message->line('## Rokovi koji se približavaju');
        if ($this->dueSoonTasks === []) {
            $message->line('Nema rokova u narednim danima.');
        } else {
            foreach ($this->dueSoonTasks as $task) {
                $message->line('- '.$task);
            }
        }

        $message->line('## Čeka se na odgovor institucija');
        if ($this->waitingItems === []) {
            $message->line('Nema stavki koje čekaju odgovor.');
        } else {
            foreach ($this->waitingItems as $item) {
                $message->line('- '.$item);
            }
        }

        return $message->action('Otvori '.config('app.name'), route('my-tasks.index'))
            ->salutation(config('app.name'));
    }
}
