<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Service;
use BJTheCod3r\Docket\Models\Subscription;

it('is a routing primitive with owner, stakeholders and reports', function () {
    $team = $this->team();
    $service = Service::create(['key' => 'checkout-api', 'title' => 'Checkout API', 'owner_type' => $team->getMorphClass(), 'owner_id' => $team->id]);

    $about = $service->report('Card declines spiking')->create();
    $implicated = Docket::report()->services([$service], 'cause')->body('x')->create();

    expect($service->owner->is($team))->toBeTrue()
        ->and($service->reports)->toHaveCount(1)
        ->and($service->reports->first()->is($implicated))->toBeTrue()
        ->and($service->reports()->open()->count())->toBe(1)
        ->and($service->rootReports)->toHaveCount(1)
        ->and($service->subjectReports->first()->is($about))->toBeTrue()
        ->and($about->owner->is($team))->toBeTrue()
        ->and(Service::active()->count())->toBe(1);
});

it('manages subscriptions', function () {
    $user = $this->user();
    $service = Service::factory()->create();

    $subscription = $service->subscribe($user, events: ['docket.report.created'], channels: ['slack']);

    expect($subscription)->toBeInstanceOf(Subscription::class)
        ->and($service->subscriptions)->toHaveCount(1)
        ->and($user->docketSubscriptions)->toHaveCount(1)
        ->and($subscription->wantsEvent('docket.report.created'))->toBeTrue()
        ->and($subscription->wantsEvent('docket.report.edited'))->toBeFalse();

    $service->subscribe($user, channels: ['mail']); // idempotent update
    expect($service->subscriptions()->count())->toBe(1)->and($service->subscriptions()->first()->channels)->toBe(['mail']);

    expect($service->unsubscribe($user))->toBe(1)->and($service->subscriptions()->count())->toBe(0);

    $user->subscribeTo('incident');
    expect(Subscription::forKind('incident')->count())->toBe(1);
});

it('resolves services by key or id in the builder', function () {
    $service = Service::factory()->create(['key' => 'payments']);

    $report = Docket::report()->services(['payments', $service->getKey()])->body('x')->create();

    expect($report->services)->toHaveCount(1);
});

it('throws for unknown services', function () {
    Docket::report()->services(['nope'])->body('x')->create();
})->throws(\BJTheCod3r\Docket\Exceptions\DocketException::class, 'Unknown service');
