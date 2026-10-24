<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Facades;

use BJTheCod3r\Docket\Attachments\AttachmentManager;
use BJTheCod3r\Docket\Builders\ReportBuilder;
use BJTheCod3r\Docket\Contracts\ChangeApplier;
use BJTheCod3r\Docket\Contracts\FileBinding;
use BJTheCod3r\Docket\Contracts\RecipientResolver;
use BJTheCod3r\Docket\Contracts\ReferenceGenerator;
use BJTheCod3r\Docket\DocketManager;
use BJTheCod3r\Docket\Events\EventMap;
use BJTheCod3r\Docket\Models\Mute;
use BJTheCod3r\Docket\Models\Proposal;
use BJTheCod3r\Docket\Models\Report;
use BJTheCod3r\Docket\Models\Subscription;
use BJTheCod3r\Docket\Notifications\NotificationPipeline;
use BJTheCod3r\Docket\Notifications\PreferenceManager;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ReportBuilder report()
 * @method static StatusRegistry statuses()
 * @method static TransitionGuard guards()
 * @method static DocketManager guard(Closure $using, ?string $to = null, ?string $from = null)
 * @method static KindRegistry kinds()
 * @method static Kind kind(string $key, array<string, mixed> $options = [])
 * @method static EventMap events()
 * @method static RollupManager rollup()
 * @method static AttachmentManager attachments()
 * @method static DocketManager attachmentDriver(string $key, string|Closure $driver)
 * @method static NotificationPipeline notifications()
 * @method static DocketManager resolveOwnerUsing(Closure $resolver)
 * @method static Closure|null ownerResolver()
 * @method static DocketManager addRecipientResolver(Closure|RecipientResolver $resolver)
 * @method static array<int, RecipientResolver> recipientResolvers()
 * @method static bool allows(?Model $user, string $ability, Model|string $subject, array<int, mixed> $arguments = [])
 * @method static void authorize(?Model $user, string $ability, Model|string $subject, array<int, mixed> $arguments = [])
 * @method static ProposalService proposals()
 * @method static PreferenceManager preferences(Model $notifiable)
 * @method static Subscription subscribe(Model $subscriber, Model|string $subscribable, array<int, string>|null $events = null, array<int, string>|null $channels = null, array<int, string>|null $exceptKinds = null, array<int, string>|null $kinds = null, \DateTimeInterface|null $mutedUntil = null)
 * @method static int unsubscribe(Model $subscriber, Model|string $subscribable)
 * @method static SuppressionGate suppression()
 * @method static MuteBuilder mute(?Model $scope = null)
 * @method static int unmute(?Model $scope = null)
 * @method static Mute|null activeMute(?Model $scope = null)
 * @method static DocketManager applier(string $target, string|ChangeApplier $applier)
 * @method static ChangeApplier applierFor(Model|string $target)
 * @method static DocketManager bindFile(string $target, string $field, FileBinding $binding)
 * @method static FileBinding fileBinding(Model|string $target, string $field)
 * @method static DocketManager generateReferencesUsing(ReferenceGenerator|string $generator)
 * @method static string table(string $name)
 * @method static string|null tenantColumn()
 * @method static string|int|null currentTenantId()
 * @method static DocketFake fake()
 * @method static void assertReported(?callable $callback = null)
 * @method static void assertReportedTimes(int $times, ?callable $callback = null)
 * @method static void assertNotReported(callable $callback)
 * @method static void assertNothingReported()
 * @method static array<int, Report> reported()
 * @method static array<int, \BJTheCod3r\Docket\Events\NotificationSuppressed> suppressed()
 * @method static \Illuminate\Support\Testing\Fakes\NotificationFake notificationFake()
 * @method static void assertStatusChanged(Report $report, ?string $from = null, ?string $to = null)
 * @method static void assertStatusNotChanged(Report $report)
 * @method static void assertDispatched(string $event, ?callable $callback = null)
 * @method static void assertNotDispatched(string $event, ?callable $callback = null)
 * @method static void assertNotified(Model $notifiable, string $notification, ?callable $callback = null)
 * @method static void assertNotNotified(Model $notifiable, ?string $notification = null)
 * @method static void assertNothingNotified()
 * @method static void assertSuppressed(?Model $recipient = null, ?string $reason = null)
 * @method static void assertProposalApplied(Proposal $proposal)
 * @method static void assertProposalNotApplied(Proposal $proposal)
 * @method static void assertProposalConflicted(Proposal $proposal)
 *
 * @see DocketManager
 */
class Docket extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DocketManager::class;
    }
}
