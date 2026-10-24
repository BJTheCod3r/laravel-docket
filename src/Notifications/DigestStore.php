<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;

/**
 * Each entry keeps its own release time, so a digest window closing early
 * never releases entries held back by a longer mute or quiet hours.
 */
class DigestStore
{
    public function __construct(protected Repository $cache) {}

    /**
     * Digest entries join the recipient's open window; anything else is held
     * until its own release time.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: Carbon, 1: bool}  the release time, and whether a job must be scheduled for it
     */
    public function push(Model|AnonymousNotifiable $recipient, array $entry, Carbon $until, bool $window = false): array
    {
        $key = $this->key($recipient);

        return $this->locked($key, function () use ($key, $entry, $until, $window) {
            if ($window && is_string($open = $this->cache->get($key.':window')) && Carbon::parse($open)->isFuture()) {
                $until = Carbon::parse($open);
            }

            $at = $until->toIso8601String();
            $entries = (array) $this->cache->get($key, []);
            $entries[] = $entry + ['until' => $at];
            $this->store($key, $entries);

            if ($window) {
                $this->cache->put($key.':window', $at, $until);
            }

            $scheduled = (array) $this->cache->get($key.':scheduled', []);

            if (in_array($at, $scheduled, true)) {
                return [$until, false];
            }

            $this->cache->put($key.':scheduled', [...$scheduled, $at], $until->copy()->addDay());

            return [$until, true];
        });
    }

    /**
     * Entries released by $until (all of them when null); later ones stay for their own job.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pull(Model|AnonymousNotifiable $recipient, ?Carbon $until = null): array
    {
        $key = $this->key($recipient);

        return $this->locked($key, function () use ($key, $until) {
            $due = [];
            $remaining = [];

            foreach ((array) $this->cache->get($key, []) as $entry) {
                if ($until === null || ! isset($entry['until']) || Carbon::parse($entry['until'])->lte($until)) {
                    $due[] = $entry;
                } else {
                    $remaining[] = $entry;
                }
            }

            $this->store($key, $remaining);

            if ($until === null) {
                $this->cache->forget($key.':scheduled');
                $this->cache->forget($key.':window');
            } else {
                $at = $until->toIso8601String();
                $scheduled = array_values(array_diff((array) $this->cache->get($key.':scheduled', []), [$at]));
                if ($scheduled === []) {
                    $this->cache->forget($key.':scheduled');
                } else {
                    $this->cache->put($key.':scheduled', $scheduled, $until->copy()->addDay());
                }

                if ($this->cache->get($key.':window') === $at) {
                    $this->cache->forget($key.':window');
                }
            }

            return $due;
        });
    }

    /**
     * push() and pull() read, change and write the same cache entries; without a lock,
     * two requests deferring at once (or a pull racing a push) lose entries.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function locked(string $key, Closure $callback): mixed
    {
        $store = $this->cache->getStore();

        return $store instanceof LockProvider
            ? $store->lock($key.':lock', 10)->block(5, $callback)
            : $callback();
    }

    public function key(Model|AnonymousNotifiable $recipient): string
    {
        return 'docket:digest:'.static::recipientKey($recipient);
    }

    public static function recipientKey(Model|AnonymousNotifiable $recipient): string
    {
        if ($recipient instanceof AnonymousNotifiable) {
            return 'anonymous|'.md5(serialize($recipient->routes));
        }

        return static::morphKey($recipient->getMorphClass(), $recipient->getKey());
    }

    /**
     * The same key built from a morph pair, for rows that only hold the type and id.
     */
    public static function morphKey(string $type, string|int $id): string
    {
        return $type.'|'.$id;
    }

    /** @param  array<int, array<string, mixed>>  $entries */
    protected function store(string $key, array $entries): void
    {
        if ($entries === []) {
            $this->cache->forget($key);

            return;
        }

        $latest = max(array_map(fn (array $entry) => Carbon::parse($entry['until'] ?? 'now'), $entries));

        $this->cache->put($key, $entries, $latest->copy()->addDay());
    }
}
