<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Database\Factories;

use BJTheCod3r\Docket\Enums\AttachmentState;
use BJTheCod3r\Docket\Models\Attachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/** @extends Factory<Attachment> */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        return [
            'collection' => 'attachments',
            'driver' => 'filesystem',
            'disk' => 'local',
            'path' => 'docket/attachments/'.$this->faker->uuid().'.png',
            'name' => $this->faker->word().'.png',
            'mime' => 'image/png',
            'size' => $this->faker->numberBetween(1000, 100000),
            'checksum' => 'sha256:'.$this->faker->sha256(),
            'order' => 0,
            'state' => AttachmentState::Live->value,
        ];
    }

    public function modelName(): string
    {
        return config('docket.models.attachment', Attachment::class);
    }

    public function for_(Model $owner, ?string $collection = null): static
    {
        return $this->state(array_filter([
            'attachable_type' => $owner->getMorphClass(),
            'attachable_id' => (string) $owner->getKey(),
            'collection' => $collection,
        ]));
    }

    public function staged(): static
    {
        return $this->state(['state' => AttachmentState::Staged->value]);
    }

    public function archived(): static
    {
        return $this->state(['state' => AttachmentState::Archived->value]);
    }
}
