<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Testing;

use BJTheCod3r\Docket\Contracts\DocketEvent;
use BJTheCod3r\Docket\DocketManager;
use BJTheCod3r\Docket\Events\NotificationSuppressed;
use BJTheCod3r\Docket\Events\ProposalApplied;
use BJTheCod3r\Docket\Events\ProposalConflicted;
use BJTheCod3r\Docket\Events\ReportCreated;
use BJTheCod3r\Docket\Events\StatusChanged;
use BJTheCod3r\Docket\Models\Proposal;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * A spy, not a stub: reports still persist, so your app keeps working, while
 * every domain event and notification is recorded for assertions.
 */
class DocketFake extends DocketManager
{
    /** @var array<int, Report> */
    protected array $reported = [];

    /** @var array<int, StatusChanged> */
    protected array $transitions = [];

    /** @var array<int, DocketEvent> */
    protected array $events = [];

    /** @var array<int, NotificationSuppressed> */
    protected array $suppressed = [];

    /** @var array<int, ProposalApplied> */
    protected array $applied = [];

    /** @var array<int, ProposalConflicted> */
    protected array $conflicted = [];

    protected NotificationFake $notifications;

    public function __construct(Container $container)
    {
        parent::__construct($container);

        $this->notifications = Notification::fake();

        Event::listen(DocketEvent::class, function (DocketEvent $event): void {
            $this->events[] = $event;

            match (true) {
                $event instanceof ReportCreated => $this->reported[] = $event->report(),
                $event instanceof StatusChanged => $this->transitions[] = $event,
                $event instanceof ProposalApplied => $this->applied[] = $event,
                $event instanceof ProposalConflicted => $this->conflicted[] = $event,
                default => null,
            };
        });

        Event::listen(NotificationSuppressed::class, function (NotificationSuppressed $event): void {
            $this->suppressed[] = $event;
        });
    }

    public function assertReported(?callable $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->reportedMatching($callback),
            'Expected a report to be filed, none matched.',
        );
    }

    public function assertReportedTimes(int $times, ?callable $callback = null): void
    {
        $count = count($this->reportedMatching($callback));

        PHPUnit::assertSame($times, $count, "Expected {$times} report(s) filed, got {$count}.");
    }

    public function assertNotReported(callable $callback): void
    {
        PHPUnit::assertEmpty($this->reportedMatching($callback), 'Expected no matching report, found some.');
    }

    public function assertNothingReported(): void
    {
        PHPUnit::assertEmpty($this->reported, sprintf('Expected nothing reported, found %d.', count($this->reported)));
    }

    /** @return array<int, Report> */
    public function reported(): array
    {
        return $this->reported;
    }

    public function assertStatusChanged(Report $report, ?string $from = null, ?string $to = null): void
    {
        $matched = collect($this->transitions)->contains(
            fn (StatusChanged $e) => $e->report()->is($report)
                && ($from === null || $e->from === $from)
                && ($to === null || $e->to === $to),
        );

        PHPUnit::assertTrue($matched, sprintf(
            'Expected report [%s] to change status%s%s; it did not.',
            $report->reference,
            $from !== null ? " from [{$from}]" : '',
            $to !== null ? " to [{$to}]" : '',
        ));
    }

    public function assertStatusNotChanged(Report $report): void
    {
        $matched = collect($this->transitions)->contains(fn (StatusChanged $e) => $e->report()->is($report));

        PHPUnit::assertFalse($matched, sprintf('Expected report [%s] not to change status, but it did.', $report->reference));
    }

    /**
     * @param  class-string<DocketEvent>|string  $event  Class or event key.
     */
    public function assertDispatched(string $event, ?callable $callback = null): void
    {
        PHPUnit::assertNotEmpty($this->eventsMatching($event, $callback), "Expected event [{$event}] to be dispatched.");
    }

    public function assertNotDispatched(string $event, ?callable $callback = null): void
    {
        PHPUnit::assertEmpty($this->eventsMatching($event, $callback), "Expected event [{$event}] not to be dispatched.");
    }

    public function assertNotified(Model $notifiable, string $notification, ?callable $callback = null): void
    {
        $this->notifications->assertSentTo($notifiable, $notification, $callback);
    }

    public function assertNotNotified(Model $notifiable, ?string $notification = null): void
    {
        if ($notification === null) {
            $this->notifications->assertNothingSentTo($notifiable);

            return;
        }

        $this->notifications->assertNotSentTo($notifiable, $notification);
    }

    public function assertNothingNotified(): void
    {
        $this->notifications->assertNothingSent();
    }

    public function assertSuppressed(?Model $recipient = null, ?string $reason = null): void
    {
        $matched = collect($this->suppressed)->contains(
            fn (NotificationSuppressed $e) => ($recipient === null || ($e->recipient instanceof Model && $e->recipient->is($recipient)))
                && ($reason === null || $e->reason === $reason),
        );

        PHPUnit::assertTrue($matched, sprintf(
            'Expected a suppressed notification%s%s.',
            $recipient ? ' for '.class_basename($recipient).' #'.$recipient->getKey() : '',
            $reason ? " with reason [{$reason}]" : '',
        ));
    }

    /** @return array<int, NotificationSuppressed> */
    public function suppressed(): array
    {
        return $this->suppressed;
    }

    public function notificationFake(): NotificationFake
    {
        return $this->notifications;
    }

    public function assertProposalApplied(Proposal $proposal): void
    {
        PHPUnit::assertTrue(
            collect($this->applied)->contains(fn (ProposalApplied $e) => $e->proposal->is($proposal)),
            sprintf('Expected proposal [%s] to be applied.', $proposal->getKey()),
        );
    }

    public function assertProposalNotApplied(Proposal $proposal): void
    {
        PHPUnit::assertFalse(
            collect($this->applied)->contains(fn (ProposalApplied $e) => $e->proposal->is($proposal)),
            sprintf('Expected proposal [%s] not to be applied.', $proposal->getKey()),
        );
    }

    public function assertProposalConflicted(Proposal $proposal): void
    {
        PHPUnit::assertTrue(
            collect($this->conflicted)->contains(fn (ProposalConflicted $e) => $e->proposal->is($proposal)),
            sprintf('Expected proposal [%s] to conflict.', $proposal->getKey()),
        );
    }

    /** @return array<int, Report> */
    protected function reportedMatching(?callable $callback): array
    {
        return collect($this->reported)->filter(fn (Report $r) => $callback === null || $callback($r))->values()->all();
    }

    /** @return array<int, DocketEvent> */
    protected function eventsMatching(string $event, ?callable $callback): array
    {
        return collect($this->events)
            ->filter(fn (DocketEvent $e) => ($e instanceof $event || $e::key() === $event) && ($callback === null || $callback($e)))
            ->values()
            ->all();
    }
}
