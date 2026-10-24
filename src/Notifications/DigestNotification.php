<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<int, string>  $channels
     */
    public function __construct(
        public array $entries,
        public array $channels = ['database'],
    ) {
        if ($queue = config('docket.notifications.queue')) {
            $this->onQueue($queue);
        }
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $entries = $this->entriesFor('mail');

        return (new MailMessage)
            ->subject(sprintf('%d Docket update%s while you were away', count($entries), count($entries) === 1 ? '' : 's'))
            ->markdown('docket::mail.digest', ['entries' => $entries]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $entries = $this->entriesFor('database');

        return [
            'event' => 'docket.digest',
            'count' => count($entries),
            'entries' => $entries,
        ];
    }

    /**
     * Only the entries that were held back on this channel; the rest already went out on it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function entriesFor(string $channel): array
    {
        return collect($this->entries)
            ->filter(fn (array $entry) => ! isset($entry['channels']) || in_array($channel, (array) $entry['channels'], true))
            ->values()
            ->all();
    }
}
