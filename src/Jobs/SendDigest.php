<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Jobs;

use BJTheCod3r\Docket\Notifications\DigestNotification;
use BJTheCod3r\Docket\Notifications\DigestStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

class SendDigest implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public bool $deleteWhenMissingModels = true;

    /** @param  Carbon|null  $until  release entries due by then; null releases everything */
    public function __construct(public Model|AnonymousNotifiable $recipient, public ?Carbon $until = null)
    {
        if ($queue = config('docket.notifications.queue')) {
            $this->onQueue($queue);
        }
    }

    public function handle(DigestStore $store): void
    {
        $entries = $store->pull($this->recipient, $this->until);

        if ($entries === []) {
            return;
        }

        $channels = collect($entries)->flatMap(fn (array $entry) => (array) ($entry['channels'] ?? []))->unique()->values()->all();

        /** @var class-string<DigestNotification> $class */
        $class = config('docket.notifications.classes.digest', DigestNotification::class);

        Notification::send($this->recipient, new $class($entries, $channels));
    }
}
