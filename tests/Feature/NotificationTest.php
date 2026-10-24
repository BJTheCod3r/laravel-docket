<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Events\NotificationSuppressed;
use BJTheCod3r\Docket\Events\ReportCreated;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Jobs\SendDigest;
use BJTheCod3r\Docket\Models\Service;
use BJTheCod3r\Docket\Notifications\DigestNotification;
use BJTheCod3r\Docket\Notifications\ReportNotification;
use BJTheCod3r\Docket\Notifications\StatusChangedNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

it('notifies the owner, the parent owner and the assignee — not the reporter or the actor', function () {
    $reporter = $this->user('Reporter');
    $owner = $this->user('Owner');
    $parentOwner = $this->team('Parent');
    $agent = $this->user('Agent');

    $root = Docket::report()->to($parentOwner)->body('root')->create();
    $child = $root->newChild()->from($reporter)->to($owner)->assignTo($agent)->body('child')->create();

    Notification::assertSentTo($owner, ReportNotification::class, fn ($n) => $n->report()->is($child) && $n->via($owner) === ['database', 'mail']);
    Notification::assertSentTo($parentOwner, ReportNotification::class, fn ($n) => $n->report()->is($child));
    Notification::assertSentTo($agent, ReportNotification::class, fn ($n) => $n->report()->is($child));
    Notification::assertNotSentTo($reporter, ReportNotification::class);
});

it('notifies the reporter when configured, but never the actor', function () {
    config()->set('docket.notifications.notify_reporter', true);

    $reporter = $this->user('Reporter');
    $owner = $this->user('Owner');
    $report = Docket::report()->from($reporter)->to($owner)->body('x')->create();

    Notification::assertNotSentTo($reporter, ReportNotification::class);

    $report->transitionTo('in_progress', by: $owner);

    Notification::assertSentTo($reporter, StatusChangedNotification::class);
    Notification::assertNotSentTo($owner, StatusChangedNotification::class);
});

it('notifies subscribers on the report, its services and its kind', function () {
    $watcher = $this->user('Watcher');
    $serviceWatcher = $this->user('ServiceWatcher');
    $kindWatcher = $this->user('KindWatcher');
    $service = Service::factory()->create();

    $service->subscribe($serviceWatcher, channels: ['slack']);
    $kindWatcher->subscribeTo('report', events: ['docket.report.created']);

    $report = Docket::report()->services([$service])->body('x')->create();
    $report->subscriptions()->create(['subscriber_type' => $watcher->getMorphClass(), 'subscriber_id' => $watcher->id]);
    $report->comment('reply');

    Notification::assertSentTo($serviceWatcher, ReportNotification::class, fn ($n) => $n->via($serviceWatcher) === ['slack']);
    Notification::assertSentTo($kindWatcher, ReportNotification::class, fn ($n) => $n->report()->is($report));
    Notification::assertSentTo($watcher, ReportNotification::class, fn ($n) => $n->report()->kind === 'comment');
});

it('lets a subscription opt out of noisy kinds', function () {
    $watcher = $this->user('Watcher');
    $root = Docket::report()->body('x')->create();
    $watcher->subscribeTo($root, exceptKinds: ['comment']);

    $root->comment('noise');
    $root->reply('signal', kind: 'resolution');

    Notification::assertSentToTimes($watcher, ReportNotification::class, 1);
});

it('limits a subscription to kinds and snoozes it', function () {
    $watcher = $this->user('Watcher');
    $root = Docket::report()->body('x')->create();
    $subscription = $watcher->subscribeTo($root, kinds: ['resolution'], mutedUntil: now()->addHour());

    $root->reply('snoozed', kind: 'resolution');
    Notification::assertNothingSentTo($watcher);

    $subscription->update(['muted_until' => null]);
    $root->comment('noise');
    $root->reply('signal', kind: 'resolution');

    Notification::assertSentToTimes($watcher, ReportNotification::class, 1);
});

it('notifies service stakeholders including the contact email', function () {
    $team = $this->team();
    $service = Service::factory()->ownedBy($team)->create(['email' => 'ops@example.com']);

    Docket::report()->services([$service])->to($this->user())->body('x')->create();

    Notification::assertSentTo($team, ReportNotification::class);
    Notification::assertSentOnDemand(ReportNotification::class, fn ($n, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'ops@example.com' && $channels === ['mail']);
});

it('resolves channels from preferences, model method and config in order', function () {
    $owner = $this->user('Owner');

    expect(Docket::preferences($owner)->channelsFor('docket.report.created'))->toBe(['database', 'mail'])
        ->and(Docket::preferences($owner)->channelsFor('docket.report.edited'))->toBe(['database']);

    $owner->preferredChannels = ['slack'];
    expect(Docket::preferences($owner)->channelsFor('docket.report.created'))->toBe(['slack']);

    Docket::preferences($owner)->allow('docket.report.created', ['mail', 'vonage'])->deny('docket.report.created', 'vonage');

    expect(Docket::preferences($owner)->channelsFor('docket.report.created'))->toBe(['mail'])
        ->and(Docket::preferences($owner)->allows('docket.report.created', 'mail'))->toBeTrue()
        ->and(Docket::preferences($owner)->allows('docket.report.created', 'slack'))->toBeFalse();

    Docket::report()->to($owner)->body('x')->create();

    Notification::assertSentTo($owner, ReportNotification::class, fn ($n) => $n->via($owner) === ['mail']);

    Docket::preferences($owner)->deny('docket.report.edited', 'slack')->reset('docket.report.created');
    expect(Docket::preferences($owner)->channelsFor('docket.report.created'))->toBe(['slack'])
        ->and(Docket::preferences($owner)->channelsFor('docket.report.edited'))->toBe([]);

    Docket::preferences($owner)->reset();
    expect(Docket::preferences($owner)->channelsFor('docket.report.edited'))->toBe(['slack']);
});

it('skips a recipient who denied every channel', function () {
    Event::fake([NotificationSuppressed::class]);
    $owner = $this->user('Owner');
    Docket::preferences($owner)->deny('*', ['database', 'mail']);

    Docket::report()->to($owner)->body('x')->create();

    Notification::assertNothingSentTo($owner);
    Event::assertDispatched(NotificationSuppressed::class, fn ($e) => $e->reason === 'no_channels' && $e->recipient->is($owner));
});

it('drops everything about a muted service before resolving recipients', function () {
    Event::fake([NotificationSuppressed::class]);
    $owner = $this->user('Owner');
    $service = Service::factory()->create();

    Docket::mute($service)->for(hours: 2)->reason('Deploying 2.4.0');

    expect(Docket::activeMute($service))->not->toBeNull()
        ->and(Docket::activeMute($service)->reason)->toBe('Deploying 2.4.0')
        ->and($service->inMaintenance())->toBeTrue();

    Docket::report()->services([$service])->to($owner)->body('x')->create();

    Notification::assertNothingSent();
    Event::assertDispatched(NotificationSuppressed::class, fn ($e) => $e->reason === 'muted' && $e->recipient === null);

    Docket::unmute($service);
    expect($service->inMaintenance())->toBeFalse();

    Docket::report()->services([$service])->to($owner)->body('y')->create();
    Notification::assertSentTo($owner, ReportNotification::class);
});

it('supports a global freeze and the maintenance() sugar', function () {
    $owner = $this->user('Owner');
    $service = Service::factory()->create();

    Docket::mute()->for(minutes: 30);
    Docket::report()->to($owner)->body('x')->create();
    Notification::assertNothingSent();

    Docket::unmute();
    $service->maintenance(hours: 1, reason: 'DB migration');
    Docket::report()->to($owner)->body('y')->create();
    Notification::assertSentTo($owner, ReportNotification::class);

    Docket::report()->services([$service])->to($owner)->body('z')->create();
    Notification::assertSentToTimes($owner, ReportNotification::class, 1);
});

it('lets urgent reports punch through a mute', function () {
    $owner = $this->user('Owner');
    $service = Service::factory()->create();
    Docket::mute($service)->for(hours: 1)->minPriority(1)->reason('Deploy');

    Docket::report()->services([$service])->to($owner)->priority(3)->body('meh')->create();
    Notification::assertNothingSent();

    Docket::report()->services([$service])->to($owner)->priority(1)->body('outage')->create();
    Notification::assertSentTo($owner, ReportNotification::class, fn (ReportNotification $n) => $n->toArray($owner)['bypassed_mute']['reason'] === 'Deploy'
        && str_contains($n->toMail($owner)->render()->toHtml(), 'Sent despite an active mute (Deploy)'));
});

it('only mutes the channels a mute lists', function () {
    $owner = $this->user('Owner');
    $service = Service::factory()->create();
    Docket::mute($service)->for(hours: 1)->channels(['mail']);

    Docket::report()->services([$service])->to($owner)->body('x')->create();
    Notification::assertSentTo($owner, ReportNotification::class, fn ($n) => $n->via($owner) === ['database']);

    Docket::unmute($service);
    Docket::mute($owner)->direction('for')->for(hours: 1)->channels(['database']);

    Docket::report()->to($owner)->body('y')->create();
    Notification::assertSentTo($owner, ReportNotification::class, fn ($n) => $n->via($owner) === ['mail']);
});

it('defers instead of dropping when the mute defers, collapsing into a digest', function () {
    Bus::fake([SendDigest::class]);
    $owner = $this->user('Owner');
    $service = Service::factory()->create();

    Docket::mute($service)->for(hours: 2)->deferring();

    Docket::report()->services([$service])->to($owner)->body('a')->create();
    Docket::report()->services([$service])->to($owner)->body('b')->create();

    Notification::assertNothingSent();
    Bus::assertDispatchedTimes(SendDigest::class, 1);

    (new SendDigest($owner))->handle(app(\BJTheCod3r\Docket\Notifications\DigestStore::class));

    Notification::assertSentTo($owner, DigestNotification::class, fn (DigestNotification $n) => count($n->entries) === 2 && $n->via($owner) === ['database', 'mail']);
});

it('suppresses delivery to a recipient who is on leave', function () {
    $owner = $this->user('Owner');
    Docket::preferences($owner)->mute(until: now()->addDays(3), reason: 'Leave');

    expect(Docket::activeMute($owner))->not->toBeNull();

    Docket::report()->to($owner)->body('x')->create();
    Notification::assertNothingSentTo($owner);

    Docket::unmute($owner);
    Docket::report()->to($owner)->body('y')->create();
    Notification::assertSentTo($owner, ReportNotification::class);
});

it('mutes delivery to the members of a muted group', function () {
    $owner = $this->user('Owner');
    $team = $this->team();
    $owner->update(['team_id' => $team->id]);

    Docket::mute($team)->direction('for')->for(hours: 1);

    Docket::report()->to($owner)->body('x')->create();
    Notification::assertNothingSentTo($owner);

    $owner->update(['team_id' => null]);
    Docket::report()->to($owner)->body('y')->create();
    Notification::assertSentTo($owner, ReportNotification::class);
});

it('defers during quiet hours using the recipient timezone', function () {
    Bus::fake([SendDigest::class]);
    Carbon::setTestNow('2026-03-01 23:30:00');

    $owner = $this->user('Owner');
    Docket::preferences($owner)->quietHours('22:00', '07:00', 'Europe/London');

    Docket::report()->to($owner)->body('x')->create();

    Notification::assertNothingSentTo($owner);
    Bus::assertDispatched(SendDigest::class, fn (SendDigest $job) => $job->delay?->format('Y-m-d H:i') === '2026-03-02 07:00');

    Carbon::setTestNow('2026-03-01 12:00:00');
    Docket::report()->to($owner)->body('y')->create();
    Notification::assertSentTo($owner, ReportNotification::class);

    Carbon::setTestNow();
});

it('only quiets the configured channels', function () {
    Bus::fake([SendDigest::class]);
    Carbon::setTestNow('2026-03-01 23:30:00');

    $owner = $this->user('Owner');
    Docket::preferences($owner)->quietHours('22:00', '07:00', 'UTC', channels: ['mail']);

    Docket::report()->to($owner)->body('x')->create();

    Notification::assertSentTo($owner, ReportNotification::class, fn ($n) => $n->via($owner) === ['database']);
    Bus::assertDispatched(SendDigest::class);

    Carbon::setTestNow();
});

it('throttles repeated events per recipient, thread and event key', function () {
    config()->set('docket.notifications.cooldown_seconds', 300);
    Event::fake([NotificationSuppressed::class]);

    $owner = $this->user('Owner');
    $root = Docket::report()->to($owner)->body('x')->create();
    $root->comment('a');
    $root->comment('b');

    // Root created → sent; both replies are "created" events in the same thread inside the window → throttled.
    Notification::assertSentToTimes($owner, ReportNotification::class, 1);
    Event::assertDispatched(NotificationSuppressed::class, fn ($e) => $e->reason === 'cooldown' && $e->recipient->is($owner));
});

it('batches into a digest window when digests are enabled', function () {
    Bus::fake([SendDigest::class]);
    config()->set('docket.notifications.digest', ['enabled' => true, 'window_minutes' => 10]);

    $owner = $this->user('Owner');
    Docket::report()->to($owner)->body('x')->create();
    Docket::report()->to($owner)->body('y')->create();

    Notification::assertNothingSent();
    Bus::assertDispatchedTimes(SendDigest::class, 1);
});

it('falls back to the configured digest setting when the person has a settings row', function () {
    Bus::fake([SendDigest::class]);
    config()->set('docket.notifications.digest', ['enabled' => true, 'window_minutes' => 10]);

    $owner = $this->user('Owner');
    Docket::preferences($owner)->settings();
    Docket::report()->to($owner)->body('x')->create();

    Notification::assertNothingSent();
    Bus::assertDispatchedTimes(SendDigest::class, 1);
});

it('releases each deferred entry only when its own hold ends', function () {
    Bus::fake([SendDigest::class]);
    config()->set('docket.notifications.digest', ['enabled' => true, 'window_minutes' => 10]);

    $owner = $this->user('Owner');
    $service = Service::factory()->create();
    Docket::mute($service)->for(hours: 2)->deferring();

    Docket::report()->services([$service])->to($owner)->body('held by the mute')->create();
    Docket::report()->to($owner)->body('in the digest')->create();

    $jobs = Bus::dispatched(SendDigest::class)->sortBy(fn (SendDigest $job) => $job->until)->values();
    expect($jobs)->toHaveCount(2);

    $jobs[0]->handle(app(\BJTheCod3r\Docket\Notifications\DigestStore::class));
    Notification::assertSentTo($owner, DigestNotification::class, fn (DigestNotification $n) => count($n->entries) === 1 && $n->entries[0]['reason'] === 'digest');

    $jobs[1]->handle(app(\BJTheCod3r\Docket\Notifications\DigestStore::class));
    Notification::assertSentTo($owner, DigestNotification::class, fn (DigestNotification $n) => count($n->entries) === 1 && $n->entries[0]['reason'] === 'muted');
});

it('only lists an entry on the channels it was held back on', function () {
    $notification = new DigestNotification([
        ['reference' => 'A', 'channels' => ['mail']],
        ['reference' => 'B', 'channels' => ['database', 'mail']],
    ], ['database', 'mail']);

    expect(collect($notification->entriesFor('database'))->pluck('reference')->all())->toBe(['B'])
        ->and(collect($notification->entriesFor('mail'))->pluck('reference')->all())->toBe(['A', 'B']);
});

it('ignores configured events and honours the kill switch', function () {
    Event::fake([NotificationSuppressed::class]);
    $owner = $this->user('Owner');

    $report = Docket::report()->to($owner)->body('x')->create();
    $report->edit(['priority' => 1]); // ReportUpdated is ignored by default

    Event::assertDispatched(NotificationSuppressed::class, fn ($e) => $e->reason === 'ignored' && $e->eventKey() === 'docket.report.updated');

    config()->set('docket.notifications.enabled', false);
    Docket::report()->to($owner)->body('y')->create();
    Notification::assertSentToTimes($owner, ReportNotification::class, 1);

    config()->set('docket.notifications.enabled', true);
    Docket::notifications()->withoutNotifications(fn () => Docket::report()->to($owner)->body('z')->create());
    Notification::assertSentToTimes($owner, ReportNotification::class, 1);
});

it('adds custom recipient resolvers and honours the kind notifies flag', function () {
    $extra = $this->user('Extra');
    Docket::addRecipientResolver(fn (ReportCreated $event) => $extra);
    Docket::kind('heartbeat', ['notifies' => false]);

    Docket::report()->body('x')->create();
    Notification::assertSentTo($extra, ReportNotification::class);

    Docket::report()->kind('heartbeat')->to($this->user('Owner'))->body('y')->create();
    Notification::assertSentToTimes($extra, ReportNotification::class, 1);
});

it('swaps notification classes through config and renders mail', function () {
    Notification::fake();
    config()->set('docket.notifications.classes', ['docket.report.created' => CustomReportNotification::class] + config('docket.notifications.classes'));

    $owner = $this->user('Owner');
    $report = Docket::report()->to($owner)->title('Hello')->body('x')->create();

    Notification::assertSentTo($owner, CustomReportNotification::class);

    $mail = (new ReportNotification(new ReportCreated($report, $owner), ['mail']))->toMail($owner);
    $rendered = (string) $mail->render();

    expect($mail->subject)->toContain($report->reference)
        ->and($rendered)->toContain('Hello')->toContain('Owner filed a report');

    $status = new StatusChangedNotification(new \BJTheCod3r\Docket\Events\StatusChanged($report, 'open', 'closed', $owner, 'done'), ['database']);
    expect($status->toArray($owner))->toMatchArray(['from' => 'open', 'to' => 'closed'])
        ->and($status->summary())->toContain('Open')->toContain('Closed')->toContain('done');
});

class CustomReportNotification extends ReportNotification
{
}

it('honours the channels an owner chose when they also subscribed', function () {
    $owner = $this->user('Owner');
    $service = Service::factory()->create();
    $service->subscribe($owner, channels: ['slack']);

    Docket::report()->to($owner)->services($service)->body('x')->create();

    Notification::assertSentTo($owner, ReportNotification::class, fn ($n) => $n->via($owner) === ['slack']);
});

it('runs the same number of queries however many recipients an event has', function () {
    $queriesFor = function (int $watchers) {
        $service = Service::factory()->create();
        collect(range(1, $watchers))->each(fn (int $i) => $service->subscribe($this->user("Watcher {$i}")));
        $report = Docket::report()->services($service)->body('x')->create();

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        Docket::notifications()->handle(new ReportCreated($report->fresh()));

        return count(\Illuminate\Support\Facades\DB::getQueryLog());
    };

    expect($queriesFor(5))->toBe($queriesFor(1));
});

it('merges the global, pattern and exact preferences for an event', function () {
    $owner = $this->user('Owner');

    Docket::preferences($owner)
        ->deny('*', 'mail')
        ->allow('docket.proposal.*', 'slack')
        ->allow('docket.report.created', 'database');

    expect(Docket::preferences($owner)->preferencesFor('docket.report.created'))->toBe(['mail' => false, 'database' => true])
        ->and(Docket::preferences($owner)->preferencesFor('docket.proposal.approved'))->toBe(['mail' => false, 'slack' => true]);
});

it('keeps resolvers registered before Docket::fake()', function () {
    $watcher = $this->user('Watcher');
    Docket::addRecipientResolver(fn () => [$watcher]);

    Docket::fake();
    Docket::report()->body('x')->create();

    Docket::assertNotified($watcher, ReportNotification::class);
});

it('honours withoutNotifications inside an outer transaction', function () {
    $owner = $this->user('Owner');

    \Illuminate\Support\Facades\DB::transaction(fn () => Docket::notifications()->withoutNotifications(
        fn () => Docket::report()->to($owner)->body('x')->create()
    ));

    Notification::assertNotSentTo($owner, ReportNotification::class);
});
