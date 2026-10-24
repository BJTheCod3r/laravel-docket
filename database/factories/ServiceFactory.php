<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Database\Factories;

use BJTheCod3r\Docket\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $title = $this->faker->unique()->word().' '.$this->faker->word();

        return [
            'key' => Str::slug($title),
            'title' => Str::title($title),
            'is_active' => true,
            'meta' => [],
        ];
    }

    public function modelName(): string
    {
        return config('docket.models.service', Service::class);
    }

    public function ownedBy(Model $owner): static
    {
        return $this->state(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()]);
    }
}
