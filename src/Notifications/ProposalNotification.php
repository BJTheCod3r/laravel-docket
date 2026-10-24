<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications;

use BJTheCod3r\Docket\Events\ProposalEvent;
use BJTheCod3r\Docket\Models\Proposal;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

class ProposalNotification extends DocketNotification
{
    public function proposal(): ?Proposal
    {
        return $this->event instanceof ProposalEvent ? $this->event->proposal : $this->report()->proposal;
    }

    public function subjectLine(): string
    {
        $report = $this->report();

        return "[{$report->reference}] Proposal ".$this->verb().($report->title ? " — {$report->title}" : '');
    }

    public function summary(): string
    {
        $proposal = $this->proposal();
        $target = $proposal?->target;

        return sprintf(
            '%s %s a change proposal%s.',
            $this->actorName(),
            $this->verb(),
            $target ? ' for '.class_basename($target).' #'.$target->getKey() : '',
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subjectLine())
            ->markdown('docket::mail.proposal', [
                'notification' => $this,
                'report' => $this->report(),
                'proposal' => $this->proposal(),
                'diff' => $this->proposal()?->diff() ?? [],
                'summary' => $this->summary(),
                'url' => $this->url(),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return parent::toArray($notifiable) + [
            'proposal_id' => $this->proposal()?->getKey(),
            'proposal_state' => $this->proposal()?->state,
        ];
    }

    protected function verb(): string
    {
        return match (Str::afterLast($this->eventKey(), '.')) {
            'submitted' => 'submitted',
            'approved' => 'approved',
            'rejected' => 'rejected',
            'withdrawn' => 'withdrew',
            'applied' => 'applied',
            'conflicted' => 'found a conflict in',
            'reverted' => 'reverted',
            default => 'updated',
        };
    }
}
