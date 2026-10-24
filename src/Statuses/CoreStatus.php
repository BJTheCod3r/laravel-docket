<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Statuses;

use BJTheCod3r\Docket\Contracts\Status;
use BJTheCod3r\Docket\Enums\StatusCategory;

enum CoreStatus: string implements Status
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Closed = 'closed';
    case Reopened = 'reopened';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::Closed => 'Closed',
            self::Reopened => 'Reopened',
        };
    }

    public function category(): StatusCategory
    {
        return match ($this) {
            self::Open, self::Reopened => StatusCategory::Open,
            self::InProgress => StatusCategory::Active,
            self::Closed => StatusCategory::Closed,
        };
    }

    public function transitionsTo(): array
    {
        return match ($this) {
            self::Open => [self::InProgress->value, self::Closed->value],
            self::InProgress => [self::Closed->value, self::Open->value],
            self::Closed => [self::Reopened->value],
            self::Reopened => [self::InProgress->value, self::Closed->value],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => '#3b82f6',
            self::InProgress => '#f59e0b',
            self::Closed => '#6b7280',
            self::Reopened => '#8b5cf6',
        };
    }
}
