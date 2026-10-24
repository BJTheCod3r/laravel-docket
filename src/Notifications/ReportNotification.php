<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications;

use BJTheCod3r\Docket\Events\ChildReportAdded;
use BJTheCod3r\Docket\Events\ReportAssigned;
use BJTheCod3r\Docket\Events\ReportEdited;
use BJTheCod3r\Docket\Events\ReportOwnerChanged;
use BJTheCod3r\Docket\Facades\Docket;
use Illuminate\Support\Str;

class ReportNotification extends DocketNotification
{
    public function subjectLine(): string
    {
        $report = $this->report();
        $title = $report->title ? " — {$report->title}" : '';

        return "[{$report->reference}] ".$this->headline().$title;
    }

    public function summary(): string
    {
        $report = $this->report();

        return match (true) {
            $this->event instanceof ChildReportAdded => sprintf(
                '%s added a %s to %s: %s',
                $this->actorName(),
                $this->kindLabel($this->event->child->kind),
                $report->reference,
                (string) str($this->event->child->description ?? '')->limit(200),
            ),
            $this->event instanceof ReportAssigned => sprintf(
                '%s assigned %s to %s.',
                $this->actorName(),
                $report->reference,
                $this->event->assignee?->getAttribute('name') ?? 'nobody',
            ),
            $this->event instanceof ReportOwnerChanged => sprintf('%s changed the owner of %s.', $this->actorName(), $report->reference),
            $this->event instanceof ReportEdited => sprintf(
                '%s edited %s (%s).',
                $this->actorName(),
                $report->reference,
                implode(', ', $this->event->revision->changedFields()),
            ),
            default => sprintf(
                '%s filed a %s%s: %s',
                $this->actorName(),
                $this->kindLabel($report->kind),
                $report->subject ? ' about '.class_basename($report->subject).' #'.$report->subject->getKey() : '',
                (string) str($report->description ?? '')->limit(200),
            ),
        };
    }

    protected function headline(): string
    {
        return match (true) {
            $this->event instanceof ChildReportAdded => 'New reply',
            $this->event instanceof ReportAssigned => 'Assigned',
            $this->event instanceof ReportOwnerChanged => 'Owner changed',
            $this->event instanceof ReportEdited => 'Edited',
            default => 'New '.$this->kindLabel($this->report()->kind),
        };
    }

    protected function kindLabel(string $kind): string
    {
        return Str::lower(Docket::kinds()->find($kind)->label ?? str_replace('_', ' ', $kind));
    }
}
