<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Database\Factories;

use BJTheCod3r\Docket\Enums\ReportKind;
use BJTheCod3r\Docket\Enums\Visibility;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            'kind' => ReportKind::Report->value,
            'visibility' => Visibility::Internal->value,
            'status' => 'open',
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'meta' => [],
        ];
    }

    public function modelName(): string
    {
        return config('docket.models.report', Report::class);
    }

    public function about(Model $subject): static
    {
        return $this->state(['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey()]);
    }

    public function ownedBy(Model $owner): static
    {
        return $this->state(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()]);
    }

    public function reportedBy(Model $reporter): static
    {
        return $this->state(['reporter_type' => $reporter->getMorphClass(), 'reporter_id' => $reporter->getKey()]);
    }

    public function childOf(Report $parent): static
    {
        return $this->state(['parent_id' => $parent->getKey()]);
    }

    public function kind(string|ReportKind $kind): static
    {
        return $this->state(['kind' => $kind instanceof ReportKind ? $kind->value : $kind]);
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function public(): static
    {
        return $this->state(['visibility' => Visibility::Public->value]);
    }

    public function priority(int $priority): static
    {
        return $this->state(['priority' => $priority]);
    }
}
