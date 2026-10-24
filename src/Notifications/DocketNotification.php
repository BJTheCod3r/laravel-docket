<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications;

use BJTheCod3r\Docket\Contracts\DocketEvent;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Route;

/**
 * Base for every shipped notification. Channels are decided by the pipeline
 * before construction, so via() simply returns them.
 */
abstract class DocketNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Set when the report was urgent enough to punch through an active mute.
     *
     * @var array{id: mixed, reason: string|null, ends_at: string|null}|null
     */
    public ?array $bypassedMute = null;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        public DocketEvent $event,
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

    public function eventKey(): string
    {
        return $this->event::key();
    }

    public function report(): Report
    {
        return $this->event->report();
    }

    abstract public function subjectLine(): string;

    abstract public function summary(): string;

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subjectLine())
            ->markdown('docket::mail.report', [
                'notification' => $this,
                'report' => $this->report(),
                'summary' => $this->summary(),
                'url' => $this->url(),
                'bypassedMute' => $this->bypassedMute,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $report = $this->report();

        return [
            'event' => $this->eventKey(),
            'report_id' => $report->getKey(),
            'reference' => $report->reference,
            'kind' => $report->kind,
            'title' => $report->title,
            'status' => $report->status,
            'status_category' => Docket::statuses()->find($report->status)?->category->value,
            'summary' => $this->summary(),
            'actor_type' => $this->event->actor()?->getMorphClass(),
            'actor_id' => $this->event->actor()?->getKey(),
            'bypassed_mute' => $this->bypassedMute,
        ];
    }

    /**
     * A link to the report in the host app, if it exposes one via a
     * `docketUrl()` method or a `docket.reports.show` named route.
     */
    public function url(): ?string
    {
        $report = $this->report();

        if (method_exists($report, 'docketUrl')) {
            return $report->docketUrl();
        }

        if (Route::has('docket.reports.show')) {
            return route('docket.reports.show', $report);
        }

        return null;
    }

    protected function actorName(): string
    {
        $actor = $this->event->actor();

        return $actor?->getAttribute('name') ?? ($actor ? class_basename($actor).' #'.$actor->getKey() : 'Someone');
    }
}
