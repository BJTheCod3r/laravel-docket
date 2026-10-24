<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Support;

use Illuminate\Support\Str;

/**
 * Looks up config maps keyed by event key: an exact key wins, then a pattern
 * such as "docket.proposal.*", then the "*" entry.
 */
final class EventKeyMap
{
    /** @param  array<string, mixed>  $map */
    public static function lookup(array $map, string $eventKey, mixed $default = null): mixed
    {
        return $map[$eventKey]
            ?? collect($map)->first(fn ($value, string $pattern) => $pattern !== '*' && Str::is($pattern, $eventKey))
            ?? $map['*']
            ?? $default;
    }
}
