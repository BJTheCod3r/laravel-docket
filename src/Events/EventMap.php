<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

use BJTheCod3r\Docket\Contracts\DocketEvent;
use BJTheCod3r\Docket\Exceptions\DocketException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Resolves package event classes through config('docket.events') so a host
 * app (or a subclassed Report) can emit its own classes.
 */
class EventMap
{
    public function __construct(protected Container $container) {}

    /**
     * @param  class-string<DocketEvent>  $event
     * @return class-string<DocketEvent>
     */
    public function resolve(string $event): string
    {
        $resolved = config("docket.events.{$event}", $event);

        if (! is_string($resolved) || ! is_a($resolved, $event, true)) {
            throw new DocketException(sprintf('[%s] must extend [%s] to replace it.', is_string($resolved) ? $resolved : gettype($resolved), $event));
        }

        return $resolved;
    }

    /** @param  class-string<DocketEvent>  $event */
    public function dispatch(string $event, mixed ...$arguments): DocketEvent
    {
        $class = $this->resolve($event);
        $instance = new $class(...$arguments);

        // Resolve lazily so Event::fake() (which swaps the binding) is honoured.
        $this->container->make(Dispatcher::class)->dispatch($instance);

        return $instance;
    }
}
