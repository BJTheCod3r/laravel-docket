<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Builders;

use BJTheCod3r\Docket\Contracts\Status;
use BJTheCod3r\Docket\Enums\ReportKind;
use BJTheCod3r\Docket\Enums\Visibility;
use BJTheCod3r\Docket\Exceptions\DocketException;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use BJTheCod3r\Docket\Models\Service;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Conditionable;

class ReportBuilder
{
    use Conditionable;

    /** @var array<string, mixed> */
    protected array $attributes = [];

    protected ?Model $subject = null;

    protected ?Model $reporter = null;

    protected ?Model $owner = null;

    protected ?Model $assignee = null;

    protected ?Report $parent = null;

    /** @var array<int, array{service: Service|string|int, role: ?string}> */
    protected array $services = [];

    /** @var array<int, array{file: UploadedFile|string, collection: string, options: array<string, mixed>}> */
    protected array $files = [];

    /** @var array<string, Service|null> Looked-up services, so owner resolution and syncing share one query each. */
    protected array $resolvedServices = [];

    public function about(Model $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function from(?Model $reporter): static
    {
        $this->reporter = $reporter;

        return $this;
    }

    public function to(?Model $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function assignTo(?Model $assignee): static
    {
        $this->assignee = $assignee;

        return $this;
    }

    public function parent(Report $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function title(?string $title): static
    {
        $this->attributes['title'] = $title;

        return $this;
    }

    public function body(?string $body): static
    {
        $this->attributes['description'] = $body;

        return $this;
    }

    public function kind(string|ReportKind $kind): static
    {
        $this->attributes['kind'] = $kind instanceof ReportKind ? $kind->value : $kind;

        return $this;
    }

    public function visibility(Visibility|string $visibility): static
    {
        $this->attributes['visibility'] = $visibility instanceof Visibility ? $visibility->value : $visibility;

        return $this;
    }

    public function status(string|Status $status): static
    {
        $this->attributes['status'] = $status instanceof Status ? $status->key() : $status;

        return $this;
    }

    /** @param  array<string, mixed>  $meta */
    public function meta(array $meta): static
    {
        $this->attributes['meta'] = array_merge($this->attributes['meta'] ?? [], $meta);

        return $this;
    }

    public function priority(?int $priority): static
    {
        $this->attributes['priority'] = $priority;

        return $this;
    }

    public function dueIn(DateTimeInterface|DateInterval|string $in): static
    {
        $this->attributes['due_at'] = $in instanceof DateTimeInterface ? Carbon::instance($in) : Carbon::now()->add($in);

        return $this;
    }

    /**
     * @param  iterable|Service|string|int  $services
     * @param  string|null  $role
     *
     * @phpstan-param  iterable<int, Service|string|int>|Service|string|int  $services
     */
    public function services(iterable|Service|string|int $services, ?string $role = null): static
    {
        Collection::wrap($services)->each(fn ($service) => $this->services[] = ['service' => $service, 'role' => $role]);

        return $this;
    }

    /**
     * @param  UploadedFile|string|iterable|null  $files
     * @param  string  $collection
     * @param  array<string, mixed>  $options
     *
     * @phpstan-param  UploadedFile|string|iterable<int, UploadedFile|string>|null  $files
     */
    public function attach(UploadedFile|string|iterable|null $files, string $collection = 'attachments', array $options = []): static
    {
        Collection::wrap($files ?? [])->each(fn ($file) => $this->files[] = ['file' => $file, 'collection' => $collection, 'options' => $options]);

        return $this;
    }

    /**
     * Raw attributes for anything without a dedicated setter — a fixed
     * reference on import, a tenant id, columns on an extended Report model.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function with(array $attributes): static
    {
        $this->attributes = array_merge($this->attributes, $attributes);

        return $this;
    }

    public function make(): Report
    {
        /** @var class-string<Report> $class */
        $class = Docket::model('report');
        $report = new $class;

        $subject = $this->subject ?? $this->parent?->subject;
        $owner = $this->resolveOwner($subject);

        // Kind, visibility and status defaults are applied by ReportObserver::creating().
        $report->forceFill($this->attributes);

        $report->parent()->associate($this->parent);

        // Columns only, not associate(): the report should load its own copy of these models.
        foreach (['subject' => $subject, 'reporter' => $this->reporter, 'owner' => $owner, 'assignee' => $this->assignee] as $morph => $model) {
            $report->forceFill(["{$morph}_type" => $model?->getMorphClass(), "{$morph}_id" => $model?->getKey()]);
        }

        return $report;
    }

    public function create(): Report
    {
        $report = $this->make();

        $report->getConnection()->transaction(function () use ($report) {
            $report->save();

            $this->syncServices($report);

            foreach ($this->files as $file) {
                Docket::attachments()->store($file['file'], $report, $file['collection'], $file['options']);
            }
        });

        return $report;
    }

    /**
     * explicit ->to() → resolveOwnerUsing() → parent's owner → subject's owner → first service's owner.
     */
    protected function resolveOwner(?Model $subject): ?Model
    {
        if ($this->owner !== null) {
            return $this->owner;
        }

        if ($custom = Docket::ownerResolver()) {
            $resolved = $custom($this, $subject, $this->parent);

            if ($resolved instanceof Model) {
                return $resolved;
            }
        }

        if ($this->parent?->owner !== null) {
            return $this->parent->owner;
        }

        if ($subject !== null && method_exists($subject, 'docketOwner') && ($owner = $subject->docketOwner()) instanceof Model) {
            return $owner;
        }

        foreach ($this->services as $entry) {
            if (($owner = $this->resolveService($entry['service'])?->owner) instanceof Model) {
                return $owner;
            }
        }

        return null;
    }

    protected function syncServices(Report $report): void
    {
        if ($this->services === []) {
            return;
        }

        $sync = collect($this->services)->mapWithKeys(function (array $entry) {
            $service = $this->resolveService($entry['service']);

            throw_unless($service, DocketException::class, 'Unknown service ['.(is_object($entry['service']) ? $entry['service']->getKey() : $entry['service']).'].');

            return [$service->getKey() => ['role' => $entry['role']]];
        });

        $report->services()->syncWithoutDetaching($sync->all());
    }

    protected function resolveService(Service|string|int $service): ?Service
    {
        if ($service instanceof Service) {
            return $service;
        }

        /** @var class-string<Service> $class */
        $class = Docket::model('service');
        $instance = new $class;

        return $this->resolvedServices[(string) $service] ??= $class::query()
            ->where(fn ($query) => $query->where('key', (string) $service)
                ->when($instance->couldBeKey($service), fn ($query) => $query->orWhere($instance->getKeyName(), $service)))
            ->first();
    }
}
