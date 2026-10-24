<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Enums;

enum StatusCategory: string
{
    case Open = 'open';
    case Active = 'active';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * Lower rank = less resolved. Used by the "severest" rollup strategy.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Open => 0,
            self::Active => 1,
            self::Resolved => 2,
            self::Closed => 3,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Resolved || $this === self::Closed;
    }

    public static function fromMixed(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }
}
