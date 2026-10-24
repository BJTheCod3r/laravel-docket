<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Http\Resources;

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Report */
class ReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'reference' => $this->reference,
            'parent_id' => $this->parent_id,
            'root_id' => $this->root_id,
            'depth' => $this->depth,
            'kind' => $this->kind,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'status_category' => Docket::statuses()->find($this->status)?->category->value,
            'title' => $this->title,
            'description' => $this->description,
            'meta' => $this->meta,
            'priority' => $this->priority,
            'due_at' => $this->due_at,
            'resolved_at' => $this->resolved_at,
            'closed_at' => $this->closed_at,
            'subject' => $this->subject_type ? ['type' => $this->subject_type, 'id' => $this->subject_id] : null,
            'owner' => $this->owner_type ? ['type' => $this->owner_type, 'id' => $this->owner_id] : null,
            'reporter' => $this->reporter_type ? ['type' => $this->reporter_type, 'id' => $this->reporter_id] : null,
            'assignee' => $this->assignee_type ? ['type' => $this->assignee_type, 'id' => $this->assignee_id] : null,
            'children' => static::collection($this->whenLoaded('children')),
            'transitions' => $this->whenLoaded('transitions', fn () => $this->transitions->map->only([
                'from', 'to', 'from_category', 'to_category', 'reason', 'via_rollup', 'actor_type', 'actor_id', 'created_at',
            ])),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map->only([
                'id', 'collection', 'name', 'mime', 'size', 'order', 'created_at',
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
