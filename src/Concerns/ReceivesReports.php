<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Concerns;

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use BJTheCod3r\Docket\Models\Subscription;
use BJTheCod3r\Docket\Notifications\PreferenceManager;
use BJTheCod3r\Docket\Relations\StringKeyedMorphMany;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @mixin Model
 */
trait ReceivesReports
{
    /** @return MorphMany<Report, $this> */
    public function ownedReports(): MorphMany
    {
        return StringKeyedMorphMany::on($this, Docket::model('report'), 'owner', StringKeyedMorphMany::morphKeysAreStrings());
    }

    /** @return MorphMany<Report, $this> */
    public function unresolvedOwnedReports(): MorphMany
    {
        return $this->ownedReports()->unresolved();
    }

    /** @return MorphMany<Report, $this> */
    public function assignedReports(): MorphMany
    {
        return StringKeyedMorphMany::on($this, Docket::model('report'), 'assignee', StringKeyedMorphMany::morphKeysAreStrings());
    }

    public function docketPreferences(): PreferenceManager
    {
        return Docket::preferences($this);
    }

    /** @return MorphMany<Subscription, $this> */
    public function docketSubscriptions(): MorphMany
    {
        return StringKeyedMorphMany::on($this, Docket::model('subscription'), 'subscriber', StringKeyedMorphMany::morphKeysAreStrings());
    }

    /**
     * Watch a report, service, or every report of a kind.
     *
     * @param  array<int, string>|null  $events
     * @param  array<int, string>|null  $channels
     * @param  array<int, string>|null  $exceptKinds
     * @param  array<int, string>|null  $kinds
     */
    public function subscribeTo(Model|string $subscribable, ?array $events = null, ?array $channels = null, ?array $exceptKinds = null, ?array $kinds = null, ?DateTimeInterface $mutedUntil = null): Subscription
    {
        return Docket::subscribe($this, $subscribable, $events, $channels, $exceptKinds, $kinds, $mutedUntil);
    }

    public function unsubscribeFrom(Model|string $subscribable): int
    {
        return Docket::unsubscribe($this, $subscribable);
    }
}
