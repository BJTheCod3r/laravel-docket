<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Database\Factories;

use BJTheCod3r\Docket\Enums\ProposalState;
use BJTheCod3r\Docket\Enums\ReportKind;
use BJTheCod3r\Docket\Models\Proposal;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/** @extends Factory<Proposal> */
class ProposalFactory extends Factory
{
    protected $model = Proposal::class;

    public function definition(): array
    {
        return [
            'report_id' => Report::factory()->kind(ReportKind::Proposal),
            'changes' => ['name' => $this->faker->name()],
            'original' => ['name' => $this->faker->name()],
            'strategy' => 'patch',
            'state' => ProposalState::Submitted->value,
            'required_approvals' => 1,
        ];
    }

    public function modelName(): string
    {
        return config('docket.models.proposal', Proposal::class);
    }

    public function targeting(Model $target): static
    {
        return $this->state(['target_type' => $target->getMorphClass(), 'target_id' => $target->getKey()]);
    }

    public function state_(ProposalState $state): static
    {
        return $this->state(['state' => $state->value]);
    }
}
