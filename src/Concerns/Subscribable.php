<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Concerns;

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Subscription;
use BJTheCod3r\Docket\Relations\StringKeyedMorphMany;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @mixin Model
 */
trait Subscribable
{
    /** @return MorphMany<Subscription, $this> */
    public function subscriptions(): MorphMany
    {
        return StringKeyedMorphMany::on($this, Docket::model('subscription'), 'subscribable');
    }

    /**
     * @param  array<int, string>|null  $events
     * @param  array<int, string>|null  $channels
     * @param  array<int, string>|null  $exceptKinds
     * @param  array<int, string>|null  $kinds
     */
    public function subscribe(Model $subscriber, ?array $events = null, ?array $channels = null, ?array $exceptKinds = null, ?array $kinds = null, ?DateTimeInterface $mutedUntil = null): Subscription
    {
        return Docket::subscribe($subscriber, $this, $events, $channels, $exceptKinds, $kinds, $mutedUntil);
    }

    public function unsubscribe(Model $subscriber): int
    {
        return Docket::unsubscribe($subscriber, $this);
    }
}
