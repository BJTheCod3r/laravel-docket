<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Contracts;

use BJTheCod3r\Docket\Enums\StatusCategory;

interface Status
{
    public function key(): string;

    public function label(): string;

    public function category(): StatusCategory;

    /** @return array<int, string> Status keys this status may move to. ['*'] means any. */
    public function transitionsTo(): array;

    public function isTerminal(): bool;

    public function color(): ?string;
}
