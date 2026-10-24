<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Contracts;

use Illuminate\Database\Eloquent\Model;

interface Proposable
{
    /** @return array<int, string> */
    public function proposableAttributes(): array;

    /** @return array<string, array<string, mixed>> */
    public function proposableFiles(): array;

    /** @return array<string, mixed> */
    public function proposalRules(): array;

    /** @return iterable<int, Model>|null */
    public function proposalApprovers(): ?iterable;
}
