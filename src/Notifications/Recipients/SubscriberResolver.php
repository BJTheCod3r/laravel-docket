<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications\Recipients;

use BJTheCod3r\Docket\Contracts\DocketEvent;
use BJTheCod3r\Docket\Contracts\RecipientResolver;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Subscription;
use BJTheCod3r\Docket\Notifications\DigestStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Subscriptions on the report, its root, its services, or its kind.
 * Yields the subscriber and records the matching Subscription so the
 * pipeline can honour explicit channel choices.
 */
class SubscriberResolver implements RecipientResolver
{
    /** @var array<string, Subscription> */
    protected array $matched = [];

    public function resolve(DocketEvent $event): iterable
    {
        $this->matched = [];
        $report = $event->relevantReport();
        $key = $event::key();

        foreach ($this->subscriptions($event) as $subscription) {
            if ($subscription->isMuted() || ! $subscription->wantsEvent($key) || ! $subscription->wantsKind($report->kind)) {
                continue;
            }

            $subscriber = $subscription->subscriber;

            if ($subscriber === null) {
                continue;
            }

            $this->matched[DigestStore::recipientKey($subscriber)] ??= $subscription;

            yield $subscriber;
        }
    }

    public function subscriptionFor(string $recipientKey): ?Subscription
    {
        return $this->matched[$recipientKey] ?? null;
    }

    /**
     * @return Collection<int, Subscription>
     */
    protected function subscriptions(DocketEvent $event): Collection
    {
        $report = $event->report();

        /** @var class-string<Subscription> $class */
        $class = Docket::model('subscription');

        // Built from the columns, so the root never needs loading.
        $scopes = collect([
            [$report->getMorphClass(), $report->getKey()],
            $report->root_id !== null ? [$report->getMorphClass(), $report->root_id] : null,
        ])
            ->merge($report->services->map(fn (Model $service) => [$service->getMorphClass(), $service->getKey()]))
            ->filter();

        return $class::query()
            ->where(function (Builder $query) use ($scopes, $report) {
                $scopes->each(fn (array $scope) => $query->orWhere(fn (Builder $q) => $q
                    ->where('subscribable_type', $scope[0])
                    ->where('subscribable_id', (string) $scope[1])));

                $query->orWhere(fn (Builder $q) => $q->forKind($report->kind));
            })
            ->with('subscriber')
            ->get();
    }
}
