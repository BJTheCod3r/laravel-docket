<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket;

use BJTheCod3r\Docket\Attachments\AttachmentManager;
use BJTheCod3r\Docket\Attachments\Bindings\AttachmentBinding;
use BJTheCod3r\Docket\Builders\ReportBuilder;
use BJTheCod3r\Docket\Contracts\AttachmentDriver;
use BJTheCod3r\Docket\Contracts\ChangeApplier;
use BJTheCod3r\Docket\Contracts\FileBinding;
use BJTheCod3r\Docket\Contracts\RecipientResolver;
use BJTheCod3r\Docket\Contracts\ReferenceGenerator;
use BJTheCod3r\Docket\Events\EventMap;
use BJTheCod3r\Docket\Models\Mute;
use BJTheCod3r\Docket\Models\Subscription;
use BJTheCod3r\Docket\Notifications\ChannelResolver;
use BJTheCod3r\Docket\Notifications\NotificationPipeline;
use BJTheCod3r\Docket\Notifications\PreferenceManager;
use BJTheCod3r\Docket\Notifications\Recipients\CallbackResolver;
use BJTheCod3r\Docket\Proposals\AttributeApplier;
use BJTheCod3r\Docket\Proposals\ProposalService;
use BJTheCod3r\Docket\Rollup\RollupManager;
use BJTheCod3r\Docket\Statuses\StatusRegistry;
use BJTheCod3r\Docket\Statuses\TransitionGuard;
use BJTheCod3r\Docket\Support\Kind;
use BJTheCod3r\Docket\Support\KindRegistry;
use BJTheCod3r\Docket\Suppression\MuteBuilder;
use BJTheCod3r\Docket\Suppression\SuppressionGate;
use BJTheCod3r\Docket\Testing\DocketFake;
use Closure;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Gate;

class DocketManager
{
    /** @var array<class-string, class-string<ChangeApplier>|ChangeApplier> */
    protected array $appliers = [];

    /** @var array<string, FileBinding> "Class@field" => binding */
    protected array $fileBindings = [];

    /** @var array<int, RecipientResolver> */
    protected array $recipientResolvers = [];

    protected ?Closure $ownerResolver = null;

    public function __construct(protected Container $container) {}

    public function report(): ReportBuilder
    {
        return $this->container->make(ReportBuilder::class);
    }

    public function statuses(): StatusRegistry
    {
        return $this->container->make(StatusRegistry::class);
    }

    public function guards(): TransitionGuard
    {
        return $this->container->make(TransitionGuard::class);
    }

    /**
     * Veto transitions with a closure. Return false or a string message to block.
     */
    public function guard(Closure $using, ?string $to = null, ?string $from = null): static
    {
        $this->guards()->add($using, $to, $from);

        return $this;
    }

    public function kinds(): KindRegistry
    {
        return $this->container->make(KindRegistry::class);
    }

    /** @param  array<string, mixed>  $options  label, rollup, default_status, default_visibility, notifies */
    public function kind(string $key, array $options = []): Kind
    {
        return $this->kinds()->register($key, $options);
    }

    public function events(): EventMap
    {
        return $this->container->make(EventMap::class);
    }

    public function rollup(): RollupManager
    {
        return $this->container->make(RollupManager::class);
    }

    public function attachments(): AttachmentManager
    {
        return $this->container->make(AttachmentManager::class);
    }

    /** @param  class-string<AttachmentDriver>|Closure  $driver */
    public function attachmentDriver(string $key, string|Closure $driver): static
    {
        $this->attachments()->extend($key, $driver);

        return $this;
    }

    public function notifications(): NotificationPipeline
    {
        return $this->container->make(NotificationPipeline::class);
    }

    /**
     * Override how an owner is chosen when a report is filed without ->to().
     * Receives (ReportBuilder $builder, ?Model $subject, ?Report $parent).
     */
    public function resolveOwnerUsing(Closure $resolver): static
    {
        $this->ownerResolver = $resolver;

        return $this;
    }

    public function ownerResolver(): ?Closure
    {
        return $this->ownerResolver;
    }

    public function addRecipientResolver(Closure|RecipientResolver $resolver): static
    {
        $this->recipientResolvers[] = $resolver instanceof Closure ? new CallbackResolver($resolver) : $resolver;

        return $this;
    }

    /** @return array<int, RecipientResolver> */
    public function recipientResolvers(): array
    {
        return $this->recipientResolvers;
    }

    /**
     * Policies are opt-in: with no user, no policy for the subject, or no method for
     * the ability, the action is allowed.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function allows(?Model $user, string $ability, Model|string $subject, array $arguments = []): bool
    {
        return ! $this->hasPolicyFor($user, $ability, $subject)
            || Gate::forUser($user)->allows($ability, [$subject, ...$arguments]);
    }

    /**
     * Like allows(), but throws the policy's AuthorizationException when denied.
     *
     * @param  array<int, mixed>  $arguments
     *
     * @throws AuthorizationException
     */
    public function authorize(?Model $user, string $ability, Model|string $subject, array $arguments = []): void
    {
        if ($this->hasPolicyFor($user, $ability, $subject)) {
            Gate::forUser($user)->authorize($ability, [$subject, ...$arguments]);
        }
    }

    protected function hasPolicyFor(?Model $user, string $ability, Model|string $subject): bool
    {
        return $user !== null
            && ($policy = Gate::getPolicyFor($subject)) !== null
            && method_exists($policy, $ability);
    }

    public function proposals(): ProposalService
    {
        return $this->container->make(ProposalService::class);
    }

    public function preferences(Model $notifiable): PreferenceManager
    {
        return new PreferenceManager($notifiable, $this->container->make(ChannelResolver::class));
    }

    /**
     * @param  Model|string  $subscribable  A Report, a Service, or a kind name for "all reports of this kind".
     * @param  array<int, string>|null  $events
     * @param  array<int, string>|null  $channels
     * @param  array<int, string>|null  $exceptKinds
     * @param  array<int, string>|null  $kinds
     */
    public function subscribe(Model $subscriber, Model|string $subscribable, ?array $events = null, ?array $channels = null, ?array $exceptKinds = null, ?array $kinds = null, ?DateTimeInterface $mutedUntil = null): Subscription
    {
        /** @var class-string<Subscription> $class */
        $class = $this->model('subscription');

        [$type, $id] = $this->subscribableMorph($subscribable);

        return $class::query()->updateOrCreate([
            'subscriber_type' => $subscriber->getMorphClass(),
            'subscriber_id' => $subscriber->getKey(),
            'subscribable_type' => $type,
            'subscribable_id' => $id,
        ], [
            'events' => $events,
            'channels' => $channels,
            'except_kinds' => $exceptKinds,
            'kinds' => $kinds,
            'muted_until' => $mutedUntil,
        ]);
    }

    public function unsubscribe(Model $subscriber, Model|string $subscribable): int
    {
        /** @var class-string<Subscription> $class */
        $class = $this->model('subscription');

        $query = $class::query()->by($subscriber);

        return ($subscribable instanceof Model ? $query->for($subscribable) : $query->forKind($subscribable))->delete();
    }

    /** @return array{0: string, 1: string} */
    protected function subscribableMorph(Model|string $subscribable): array
    {
        return $subscribable instanceof Model
            ? [$subscribable->getMorphClass(), (string) $subscribable->getKey()]
            : [Subscription::KIND_TYPE, $subscribable];
    }

    public function suppression(): SuppressionGate
    {
        return $this->container->make(SuppressionGate::class);
    }

    /**
     * Start a mute. With no scope, this is a global freeze.
     */
    public function mute(?Model $scope = null): MuteBuilder
    {
        return MuteBuilder::about($scope);
    }

    public function unmute(?Model $scope = null): int
    {
        return $this->suppression()->lift($scope);
    }

    public function activeMute(?Model $scope = null): ?Mute
    {
        return $this->suppression()->activeMuteFor($scope);
    }

    /**
     * @param  class-string<Model>  $target
     * @param  class-string<ChangeApplier>|ChangeApplier  $applier
     */
    public function applier(string $target, string|ChangeApplier $applier): static
    {
        $this->appliers[$target] = $applier;

        return $this;
    }

    public function applierFor(Model|string $target): ChangeApplier
    {
        $class = is_string($target) ? $target : $target::class;

        $applier = $this->appliers[$class] ?? collect($this->appliers)->first(fn ($applier, string $for) => is_a($class, $for, true));

        return match (true) {
            $applier instanceof ChangeApplier => $applier,
            is_string($applier) => $this->container->make($applier),
            default => $this->container->make(AttributeApplier::class),
        };
    }

    /**
     * @param  class-string<Model>  $target
     */
    public function bindFile(string $target, string $field, FileBinding $binding): static
    {
        $this->fileBindings["{$target}@{$field}"] = $binding;

        return $this;
    }

    public function fileBinding(Model|string $target, string $field): FileBinding
    {
        $class = is_string($target) ? $target : $target::class;

        return $this->fileBindings["{$class}@{$field}"] ?? collect($this->fileBindings)->first(function (FileBinding $binding, string $key) use ($class, $field) {
            [$for, $boundField] = explode('@', $key, 2);

            return $boundField === $field && is_a($class, $for, true);
        }) ?? $this->container->make(AttachmentBinding::class);
    }

    public function generateReferencesUsing(ReferenceGenerator|string $generator): static
    {
        $this->container->singleton(ReferenceGenerator::class, fn () => is_string($generator) ? $this->container->make($generator) : $generator);

        return $this;
    }

    /** @var array<string, class-string<Model>> The class each configured model must extend. */
    public const BASE_MODELS = [
        'report' => Models\Report::class,
        'service' => Models\Service::class,
        'revision' => Models\Revision::class,
        'transition' => Models\StatusTransition::class,
        'subscription' => Models\Subscription::class,
        'attachment' => Models\Attachment::class,
        'mute' => Models\Mute::class,
        'proposal' => Models\Proposal::class,
        'approval' => Models\ProposalApproval::class,
        'setting' => Models\NotificationSetting::class,
        'meta_index' => Models\MetaIndex::class,
    ];

    /**
     * The configured class for a Docket model, guaranteed to extend the shipped one.
     *
     * @return ($name is 'report' ? class-string<Models\Report> : ($name is 'service' ? class-string<Models\Service> : ($name is 'revision' ? class-string<Models\Revision> : ($name is 'transition' ? class-string<Models\StatusTransition> : ($name is 'subscription' ? class-string<Models\Subscription> : ($name is 'attachment' ? class-string<Models\Attachment> : ($name is 'mute' ? class-string<Models\Mute> : ($name is 'proposal' ? class-string<Models\Proposal> : ($name is 'approval' ? class-string<Models\ProposalApproval> : ($name is 'setting' ? class-string<Models\NotificationSetting> : ($name is 'meta_index' ? class-string<Models\MetaIndex> : class-string<Model>)))))))))))
     */
    public function model(string $name): string
    {
        $class = config("docket.models.{$name}");
        $base = self::BASE_MODELS[$name] ?? Model::class;

        if (! is_string($class) || ! is_a($class, $base, true)) {
            throw new Exceptions\DocketException("docket.models.{$name} must be a class extending [{$base}].");
        }

        return $class;
    }

    public function table(string $name): string
    {
        return config('docket.table_prefix', 'docket_').$name;
    }

    /**
     * The tenant column, or null when tenancy is off.
     */
    public function tenantColumn(): ?string
    {
        return config('docket.tenancy.enabled', false) ? (string) config('docket.tenancy.column', 'tenant_id') : null;
    }

    public function currentTenantId(): string|int|null
    {
        $resolver = config('docket.tenancy.resolver');

        if ($resolver === null) {
            return null;
        }

        if (is_string($resolver) && class_exists($resolver)) {
            $resolver = $this->container->make($resolver);
        }

        return is_callable($resolver) ? $resolver() : null;
    }

    public function fake(): DocketFake
    {
        $fake = new DocketFake($this->container);

        // Keep what the app registered on this instance, so faking doesn't change its behaviour.
        $fake->appliers = $this->appliers;
        $fake->fileBindings = $this->fileBindings;
        $fake->recipientResolvers = $this->recipientResolvers;
        $fake->ownerResolver = $this->ownerResolver;

        $this->container->instance(self::class, $fake);

        Facade::clearResolvedInstance(self::class);

        return $fake;
    }
}
