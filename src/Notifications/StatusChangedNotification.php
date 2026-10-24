<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications;

use BJTheCod3r\Docket\Events\StatusChanged;
use BJTheCod3r\Docket\Facades\Docket;

class StatusChangedNotification extends DocketNotification
{
    public function subjectLine(): string
    {
        $report = $this->report();

        return "[{$report->reference}] ".$this->label($this->to()).($report->title ? " — {$report->title}" : '');
    }

    public function summary(): string
    {
        $event = $this->event;
        $reason = $event instanceof StatusChanged && $event->reason ? " Reason: {$event->reason}" : '';
        $rollup = $event instanceof StatusChanged && $event->viaRollup ? ' (rolled up from a child report)' : '';

        return sprintf(
            '%s moved %s from %s to %s%s.%s',
            $this->actorName(),
            $this->report()->reference,
            $this->label($this->from()),
            $this->label($this->to()),
            $rollup,
            $reason,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return parent::toArray($notifiable) + [
            'from' => $this->from(),
            'to' => $this->to(),
            'via_rollup' => $this->event instanceof StatusChanged && $this->event->viaRollup,
        ];
    }

    protected function from(): string
    {
        return $this->event instanceof StatusChanged ? $this->event->from : (string) $this->report()->previous_status;
    }

    protected function to(): string
    {
        return $this->event instanceof StatusChanged ? $this->event->to : $this->report()->status;
    }

    protected function label(string $status): string
    {
        return Docket::statuses()->find($status)->label ?? $status;
    }
}
