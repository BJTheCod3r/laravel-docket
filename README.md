<p align="center">
  <img src="art/logo.svg" alt="Laravel Docket" width="160" height="160">
</p>

<h1 align="center">Laravel Docket</h1>

<p align="center">
  Reporting and change management for Laravel. Give any Eloquent model a report thread — statuses, ownership, attachments, notifications — and a propose → review → apply workflow for changes to it.
</p>

---

- **One `Report` model.** Comments, resolutions, check results and change proposals are reports with a different `kind`, threaded under a root.
- **Extensible statuses.** Every status has a category (`open` / `active` / `resolved` / `closed`), and the package only branches on category.
- **Notifications.** Recipient resolution, per-event and per-channel preferences, mutes, quiet hours, cooldowns and digests.
- **Change management.** Propose, review and apply changes to attributes and files, with conflict detection and revert.
- **No media package required.** Attachments run on Laravel's `Storage`.

## Installation

```bash
composer require bjthecod3r/laravel-docket
php artisan docket:install
```

`docket:install` publishes the config and migration, asks whether to enable change management and offers to migrate. Or by hand:

```bash
php artisan vendor:publish --tag=docket-config
php artisan vendor:publish --tag=docket-migrations
php artisan migrate
```

Requires PHP 8.2+ and Laravel 11, 12 or 13.

## Quick start

```php
use BJTheCod3r\Docket\Facades\Docket;

Docket::report()->about($order)->from($user)->body('Wrong item shipped')->create();
```

Add the traits to your models for a fluent API:

```php
use BJTheCod3r\Docket\Concerns\FilesReports;
use BJTheCod3r\Docket\Concerns\IsReportable;
use BJTheCod3r\Docket\Concerns\ReceivesReports;

class User extends Authenticatable
{
    use Notifiable, ReceivesReports, FilesReports;   // owns reports, files reports
}

class Order extends Model
{
    use IsReportable;                                 // reports can be *about* it
}
```

```php
$report = $order->report('Damaged on arrival')->from($user)->create();

$report->comment('Reproduced on staging.', by: $agent);
$report->transitionTo('in_progress', by: $agent, reason: 'Picked up by on-call');
$report->reply('Rolled back the deploy.', kind: 'resolution', status: 'closed', by: $agent);

$report->fresh()->isClosed();      // true — rolled up from the resolution
```

## Filing reports

```php
$report = Docket::report()
    ->about($service)                          // subject (any model)
    ->from($request->user())                   // reporter
    ->to($service->owner)                      // owner — see resolution order below
    ->assignTo($agent)                         // optional, narrower than owner
    ->title('Checkout returning 500s')
    ->body($request->input('description'))
    ->kind('report')                           // report | comment | resolution | proposal | yours
    ->visibility(Visibility::Internal)         // internal | public
    ->meta(['env' => 'production', 'build' => '2026.09.1'])
    ->services([$checkout, $payments], 'affected')
    ->attach($request->file('screenshots'))
    ->priority(1)                              // 1 = most urgent
    ->dueIn('4 hours')
    ->create();
```

Without `->to()`, the owner is resolved from: `Docket::resolveOwnerUsing()` → the parent's owner → the subject's `docketOwner()` (defaults to its `owner` relation) → the first service's owner.

A report has one `subject` (what it's about) and any number of `services` (what it implicates).

### Traits

| Trait | Grants |
|---|---|
| `IsReportable` | `reports()` / `subjectReports()`, `rootReports()`, `unresolvedReports()`, `report(...)`, `docketOwner()` |
| `ReceivesReports` | `ownedReports()`, `unresolvedOwnedReports()`, `assignedReports()`, `subscribeTo()`, `docketPreferences()` |
| `FilesReports` | `filedReports()`, `fileReport(...)` |
| `HasProposals` + `Proposable` contract | `proposals()`, `proposeChanges(...)`, `$proposable`, `$proposableFiles`, `proposalRules()` |
| `HasAttachments` | `attachments()`, `attach(...)`, `attachmentsIn(...)` |

### Scopes

```php
Report::open()->forOwner($team)->whereMeta('env', 'production')->latest()->paginate();
Report::unresolved()->stale(days: 7);
Report::overdue();
Report::roots()->withCount('children');
Report::thread($rootId);                 // whole tree, single query, ordered by depth
Report::visibleTo($user);                // public + involved; everything if `docket.view-internal` gate passes
Report::category(StatusCategory::Active);
Report::kind('comment')->forSubject($order);
```

## Threads

```php
$root->comment('note', by: $user);                       // kind: comment
$root->reply('body', kind: 'resolution', status: 'closed');
$root->newChild()->kind('check_result')->to($team)->create(); // full builder for children

$root->children;      // direct children
$root->descendants;   // whole subtree (via root_id)
$root->fullThread();  // root + descendants
$leaf->ancestorOwners(depth: 2);

$root->lock();        // no further children
```

Children inherit the parent's subject, owner and visibility. Nesting is capped by `docket.reports.max_depth`.

## Statuses

Four statuses ship: `open`, `in_progress`, `closed`, `reopened`. Every status, including yours, has a category, which is what `isOpen()`, rollup and the scopes check.

```php
$report->transitionTo('in_progress', by: $user, reason: 'Picked up');
$report->canTransitionTo('closed');       // bool
$report->checkTransition('closed');       // true, or the reason string
$report->tryTransitionTo('closed');       // false instead of throwing

$report->statusCategory();               // StatusCategory::Open, …
$report->isOpen(); $report->isActive(); $report->isResolved(); $report->isClosed(); $report->isTerminal();
$report->transitions;                     // append-only audit trail
```

> Change status only through `transitionTo()`. `$report->update(['status' => …])` throws `DirectStatusUpdateException`.

### Adding statuses

In config:

```php
'statuses' => [
    'custom' => [
        'awaiting_vendor' => ['label' => 'Awaiting vendor', 'category' => 'active', 'transitions' => ['in_progress', 'closed'], 'color' => '#f59e0b'],
    ],
],
```

At runtime, in a service provider:

```php
Docket::statuses()->register(key: 'legal_review', label: 'Legal review', category: StatusCategory::Active, transitions: ['approved', 'rejected']);
Docket::statuses()->allow('in_progress', 'legal_review');   // add an edge to an existing status
```

Transitions of `['*']` allow any target. Later sources override earlier ones: enum → config → `register()`.

### Guards

The transition map runs first, then a `transition($user, $report, $to)` policy method if your `Report` policy defines one, then your closures. Rollup and proposal bookkeeping skip the policy; pass `authorize: false` when your own code moves a report on someone's behalf. Return `false` or a message to veto:

```php
Docket::guard(to: 'closed', using: function (Report $report, ?Model $actor) {
    return $report->children()->unresolved()->doesntExist()
        ?: 'Cannot close while child reports are still open.';
});
```

### Rollup

A child's status can move its parent. Comments and proposals use `none`, resolutions `latest_child`, and everything else `rollup.default`.

| Strategy | Behaviour |
|---|---|
| `none` | Children never affect the parent |
| `latest_child` | Parent mirrors the most recent child transition |
| `all_terminal` | Parent moves to the terminal status only once every child is terminal |
| `severest` | Parent takes the least-resolved child by category (`open` > `active` > `resolved` > `closed`) — **default** |
| FQCN | Your own `RollupStrategy` |

A kind can set its own strategy: `'kinds' => ['incident' => ['rollup' => 'all_terminal']]`. Children whose kind uses `none` are ignored, and deleting a child recalculates the parent (a parent with no rolling-up children left keeps its status). Rollup transitions skip the transition map but still run guards. Use `Docket::rollup()->withoutRollup(fn () => …)` for bulk work.

## Services

A Service is something reports are filed against, with an owner and subscribers.

```php
$service = Service::create(['key' => 'checkout-api', 'title' => 'Checkout API', 'owner_type' => Team::class, 'owner_id' => $team->id]);

$service->report('Card declines spiking')->create();          // subject = the service
Docket::report()->services([$service, 'payments'])->create(); // implicated services, by model, key or id

$service->reports()->open()->get();                            // implicated
$service->subjectReports;                                      // about it
$service->subscribe($user, events: ['docket.report.created'], channels: ['slack']);
$service->maintenance(hours: 2, reason: 'Deploying 2.4.0');    // mute — see Notifications
```

## Events

All events implement `DocketEvent` and dispatch after the transaction commits.

| Event | Key | Fired when |
|---|---|---|
| `ReportCreated` | `docket.report.created` | any report, children included |
| `ReportUpdated` | `docket.report.updated` | any attribute change |
| `ReportEdited` | `docket.report.edited` | title / description / meta / visibility changed; carries the `Revision` |
| `StatusChanged` | `docket.report.status_changed` | `from`, `to`, `actor`, `reason`, `viaRollup` |
| `ReportAssigned` / `ReportOwnerChanged` | `docket.report.assigned` / `owner_changed` | |
| `ChildReportAdded` | `docket.report.child_added` | on the **parent** |
| `ReportResolved` / `ReportClosed` / `ReportReopened` | `docket.report.resolved` / `closed` / `reopened` | category-based |
| `ProposalSubmitted` / `Approved` / `Rejected` / `Withdrawn` / `Applied` / `Conflicted` / `Reverted` | `docket.proposal.*` | |
| `NotificationSuppressed` | — | the pipeline decided not to deliver; carries recipient, event and reason |

To fire your own subclass instead, map it in config: `'events' => [StatusChanged::class => App\Events\OurStatusChanged::class]`. Any event implementing `DocketEvent` goes through the notification pipeline.

## Notifications

### Who gets notified

The owner, ancestor owners (`ancestor_depth`), the assignee, the reporter (with `notify_reporter`), subscribers of the report, its root, services or kind, and service owners and contact emails. Add your own:

```php
Docket::addRecipientResolver(fn (DocketEvent $event) => $event->report()->subject?->watchers);
```

Whoever caused the event is skipped unless `notify_actor` is on. Events in `notifications.ignore` never notify.

### Which channels

```
explicit subscription channels
  -> the notifiable's stored preferences ('*', then patterns like docket.proposal.*, then the exact key)
  -> the notifiable's preferredDocketChannels($eventKey) method
  -> config('docket.notifications.defaults.{event_key}.channels')
  -> config('docket.notifications.defaults.*.channels')
```

A `deny` always wins.

```php
Docket::preferences($user)
    ->allow('docket.report.status_changed', ['mail', 'database'])
    ->deny('docket.report.created', 'mail');

Docket::preferences($user)->allows('docket.report.created', 'slack');  // bool
Docket::preferences($user)->channelsFor('docket.report.created');     // ['database', 'mail']
```

### Subscriptions

```php
$user->subscribeTo($report);
$user->subscribeTo($service, channels: ['slack']);
$user->subscribeTo('check_result', events: ['docket.report.created']);     // a whole kind
$user->subscribeTo($incident, exceptKinds: ['check_result']);              // stay subscribed, skip the noise
$user->subscribeTo($incident, kinds: ['resolution']);                     // only these kinds
$user->subscribeTo($incident, mutedUntil: now()->addDay());               // snooze
```

### Mutes: maintenance windows and leave

An `about` mute silences notifications concerning a scope; a `for` mute silences notifications delivered to one. The mute is saved immediately and each chained call updates it.

```php
Docket::mute($service)->for(hours: 2)->reason('Deploying 2.4.0');   // about: drop
Docket::mute($service)->between($start, $end)->deferring();          // about: defer into a digest
Docket::mute()->for(minutes: 30);                                    // global freeze
Docket::preferences($user)->mute(until: $monday, reason: 'Leave');   // for: drop
Docket::mute($team)->direction('for')->until($monday);               // for: everyone in the team
Docket::mute($service)->for(hours: 1)->channels(['mail']);           // only these channels

Docket::unmute($service);
Docket::activeMute($service);   // ?Mute
$service->maintenance(hours: 2);
```

A `for` mute on a group covers members whose model lists that group:

```php
public function docketMuteGroups(): iterable
{
    return $this->teams;
}
```

Reports with a priority at or below the mute's `min_priority` (1 = most urgent; default `suppression.min_priority_bypass`) are still delivered, and the notification records the mute it bypassed.

### Quiet hours, cooldown, digests

```php
Docket::preferences($user)->quietHours('22:00', '07:00', 'Europe/London');          // defer everything until 07:00 local
Docket::preferences($user)->quietHours('22:00', '07:00', channels: ['mail']);        // only quiet mail
Docket::preferences($user)->digest(true, windowMinutes: 15);
```

- **Cooldown:** at most one notification per recipient, thread and event key every `cooldown_seconds`.
- **Deferred** notifications (quiet hours, deferring mutes, digests) are delivered together as one `DigestNotification`.
- Every notification not sent fires `NotificationSuppressed` with a reason (`muted`, `quiet_hours`, `cooldown`, …).

### The notifications

`ReportNotification`, `StatusChangedNotification`, `ProposalNotification` and `DigestNotification` are queued, with publishable mail views (`--tag=docket-views`). Swap any of them:

```php
'notifications' => [
    'classes' => [
        'docket.report.status_changed' => App\Notifications\StatusChanged::class,
        'docket.proposal.*' => ProposalNotification::class,
        '*' => ReportNotification::class,
        'digest' => DigestNotification::class,
    ],
],
```

Mail buttons link to `$report->docketUrl()` if you define it, or the `docket.reports.show` route if the API is enabled.

## Change management

```php
use BJTheCod3r\Docket\Concerns\HasProposals;
use BJTheCod3r\Docket\Contracts\Proposable;

class Product extends Model implements Proposable
{
    use HasProposals;

    protected array $proposable = ['price', 'description', 'tags'];

    public function proposalRules(): array
    {
        return ['price' => ['integer', 'min:0'], 'description' => ['string', 'max:2000']];
    }

    public function proposalApprovers(): ?iterable   // null = anyone
    {
        return $this->team->managers;
    }
}
```

```php
$proposal = $product->proposeChanges(
    changes: ['price' => 4999, 'description' => 'Updated copy'],
    by: $user,
    reason: 'Q4 repricing — approved in the pricing doc',
);

$proposal->diff();          // ['price' => ['from' => 3999, 'to' => 4999, 'changed' => true], …]
$proposal->approve(by: $manager, comment: 'Confirmed with finance');   // applies automatically once required_approvals is met
$proposal->reject(by: $manager, comment: 'Wrong SKU');
$proposal->withdraw(by: $user);
$proposal->apply();         // manual, when auto_apply_on_approval is off — returns bool
$proposal->revert();        // restores the pre-apply snapshot
```

Approvals and rejections need a reviewer (`by`), and each reviewer counts once. A `Proposal` policy's `approve()`, `reject()`, `forceApply()`, `revert()` and `withdraw()` methods are checked when defined. Set `proposals.queue` to apply in a queued job. Rules run when proposing and again when applying.

### Conflicts

If the target changed since the proposal was made, nothing is written and the proposal moves to `conflicted`.

```php
$proposal->detectConflicts();  // ['price' => ['original' => 3999, 'current' => 4500, 'proposed' => 4999]]
$proposal->rebase();           // re-snapshot; drop attributes that no longer differ
$proposal->forceApply();       // overwrite the drift
```

The `patch` strategy (default) merges JSON objects into the current value; `replace` overwrites them:

```php
$product->proposeChanges(changes: ['settings' => ['theme' => 'dark']]);                     // other settings kept
$product->proposeChanges(changes: ['settings' => ['theme' => 'dark']], strategy: ProposalStrategy::Replace);   // or 'replace'
```

### Appliers

Register an applier for changes that aren't plain attributes:

```php
Docket::applier(Product::class, ProductChangeApplier::class);

class ProductChangeApplier implements ChangeApplier
{
    public function apply(Model $target, array $changes, Proposal $proposal): array;  // returns the revert snapshot
    public function revert(Model $target, array $snapshot, Proposal $proposal): void;
}
```

### Proposing files — avatars and galleries

```php
class User extends Model implements Proposable
{
    use HasProposals, HasAttachments;

    protected array $proposableFiles = [
        'avatar' => ['multiple' => false, 'mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'max_kb' => 2048],
    ];
}

class Artist extends Model implements Proposable
{
    use HasProposals, HasAttachments;

    protected array $proposableFiles = [
        'artwork' => ['multiple' => true, 'max' => 12, 'mimes' => ['image/*'], 'max_kb' => 10240],
    ];
}
```

```php
$user->proposeChanges(files: ['avatar' => $request->file('avatar')], by: $user, reason: 'New headshot');

$artist->proposeChanges(files: [
    'artwork' => [
        ['op' => 'add', 'file' => $request->file('new_piece')],
        ['op' => 'remove', 'attachment' => $oldPiece],
        ['op' => 'reorder', 'order' => [$b->id, $a->id, $c->id]],
    ],
], by: $curator);
```

`op` also accepts a `FileOperation` case.

Uploads are staged on `proposals.staging_disk` (keep it private) until the proposal is applied. The replaced file is archived, so `revert()` can restore it.

By default a file field is a Docket attachment collection on the target. If the file lives elsewhere, implement `FileBinding` and register it with `Docket::bindFile(User::class, 'avatar', new YourBinding)`.

`docket:prune` deletes staged files from proposals that were never applied and archived files past the revert window.

## Attachments

Attachments are stored on a Laravel disk (`attachments.disk`), so S3, R2 and GCS work out of the box. Write an `AttachmentDriver` only when another system owns the files, as in the medialibrary recipe below.

```php
$report->attach($request->file('photos'), collection: 'evidence');
$report->attachments;                            // ordered MorphMany
$report->attachmentsIn('evidence');

$attachment->url();
$attachment->url('thumb');                       // falls back to the original if no variant
$attachment->temporaryUrl(now()->addMinutes(5));
$attachment->stream();
$attachment->response(disposition: 'attachment'); // download; Range requests on local disks
$attachment->checksum;                           // "sha256:…"
$attachment->delete();                           // removes the bytes too
```

`max_kb` and `mimes` come from config or per-call options.

### Variants

Docket doesn't resize images itself. Set `attachments.variant_generator` to your own `VariantGenerator` and declare variants per collection:

```php
'variant_generator' => App\Docket\InterventionVariantGenerator::class,
'variants' => [
    'attachments' => ['thumb' => ['width' => 320, 'fit' => 'contain']],
    'avatar' => ['thumb' => ['width' => 128, 'fit' => 'crop']],
],
```

Each spec is passed to your generator as-is. Variants are generated in a queued job (see `queue_variants`).

### Recipe: spatie/laravel-medialibrary

Your `Report` (or proposal target) needs `HasMedia` / `InteractsWithMedia`: extend `BJTheCod3r\Docket\Models\Report` and point `models.report` at it.

```php
use BJTheCod3r\Docket\Contracts\AttachmentDriver;
use BJTheCod3r\Docket\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaLibraryDriver implements AttachmentDriver
{
    public function store(UploadedFile|string $file, Model $owner, string $collection, array $options = []): Attachment
    {
        $media = $owner->addMedia($file)->preservingOriginal()->toMediaCollection($collection, $options['disk'] ?? 'media');

        return Attachment::createFor($owner, $collection, [
            'driver' => 'medialibrary',
            'external_type' => 'media',
            'external_id' => (string) $media->id,
            'disk' => $media->disk,
            'name' => $media->file_name,
            'mime' => $media->mime_type,
            'size' => $media->size,
            'checksum' => 'sha256:'.hash_file('sha256', $media->getPath()),
            'state' => $options['state'] ?? 'live',
        ]);
    }

    public function promote(Attachment $attachment, Model $owner, string $collection): Attachment
    {
        $media = $this->media($attachment);

        // Idempotent: a retried apply finds the media already moved.
        if ($media->model_type !== $owner->getMorphClass() || (string) $media->model_id !== (string) $owner->getKey() || $media->collection_name !== $collection) {
            $media = $media->move($owner, $collection);   // medialibrary's move() makes this trivial
        }

        return $attachment->moveTo($owner, $collection, ['external_id' => (string) $media->id, 'disk' => $media->disk, 'state' => 'live']);
    }

    public function url(Attachment $attachment, ?string $variant = null): string
    {
        return $this->media($attachment)->getUrl($variant ?? '');
    }

    public function temporaryUrl(Attachment $attachment, \DateTimeInterface $expires, ?string $variant = null): string
    {
        return $this->media($attachment)->getTemporaryUrl(\Carbon\Carbon::instance($expires), $variant ?? '');
    }

    public function stream(Attachment $attachment, ?string $variant = null)
    {
        return $this->media($attachment)->stream();
    }

    public function delete(Attachment $attachment): void
    {
        $this->media($attachment)?->delete();
    }

    private function media(Attachment $attachment): ?Media
    {
        return Media::find($attachment->external_id);
    }
}
```

```php
// AppServiceProvider::boot()
Docket::attachmentDriver('medialibrary', MediaLibraryDriver::class);

// config/docket.php
'attachments' => ['driver' => 'medialibrary'],
```

For proposals that change a medialibrary collection on your models, add a `FileBinding`. Archived media moves to a `{field}.archived` collection so it can be restored on revert.

```php
use BJTheCod3r\Docket\Contracts\FileBinding;
use BJTheCod3r\Docket\Contracts\Proposable;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaLibraryBinding implements FileBinding
{
    public function current(Model $target, string $field): mixed
    {
        $items = $target->getMedia($field)->map(fn (Media $media) => $this->pointer($target, $field, $media));

        return $this->isMultiple($target, $field) ? $items : $items->first();
    }

    public function apply(Model $target, string $field, Attachment $attachment): void
    {
        if (! $attachment->exists) {
            return;   // transient pointer from a revert snapshot — the media never left
        }

        if ($attachment->collection !== $field || (string) $attachment->attachable_id !== (string) $target->getKey() || ! $attachment->isLive()) {
            Docket::attachments()->promote($attachment, $target, $field);
        }

        if (! $this->isMultiple($target, $field)) {
            $target->getMedia($field)
                ->reject(fn (Media $media) => (string) $media->id === $attachment->external_id)
                ->each(fn (Media $media) => $this->remove($target, $field, $this->pointer($target, $field, $media)));
        }
    }

    public function remove(Model $target, string $field, Attachment $attachment): void
    {
        Docket::attachments()->promote($attachment, $target, "{$field}.archived")->markArchived();
    }

    public function reorder(Model $target, string $field, array $attachmentIds): void
    {
        $mediaIds = Attachment::query()->whereKey($attachmentIds)->pluck('external_id', 'id');

        Media::setNewOrder(collect($attachmentIds)->map(fn ($id) => (int) $mediaIds[$id])->all());
    }

    private function pointer(Model $target, string $field, Media $media): Attachment
    {
        return Attachment::query()->where('driver', 'medialibrary')->where('external_id', (string) $media->id)->first()
            ?? new Attachment([
                'attachable_type' => $target->getMorphClass(),
                'attachable_id' => (string) $target->getKey(),
                'collection' => $field,
                'driver' => 'medialibrary',
                'external_type' => 'media',
                'external_id' => (string) $media->id,
                'disk' => $media->disk,
                'name' => $media->file_name,
                'mime' => $media->mime_type,
                'size' => $media->size,
                'checksum' => 'sha256:'.hash_file('sha256', $media->getPath()),
                'state' => 'live',
            ]);
    }

    private function isMultiple(Model $target, string $field): bool
    {
        return $target instanceof Proposable && (bool) ($target->proposableFiles()[$field]['multiple'] ?? false);
    }
}
```

```php
// AppServiceProvider::boot()
Docket::bindFile(User::class, 'avatar', new MediaLibraryBinding);
```

## Extension points

| Point | How |
|---|---|
| Models | `config('docket.models')` — swap `Report`, `Service`, `Proposal`, … |
| Statuses | config + `Docket::statuses()->register()` / `allow()` |
| Transition rules | transition map + policy `transition()` + `Docket::guard()` |
| Rollup | per-kind strategy or a `RollupStrategy` class |
| Report kinds | `Docket::kind('incident', ['rollup' => 'severest', 'default_status' => …, 'notifies' => true])` |
| Events | `config('docket.events')` class map; implement `DocketEvent` |
| Notifications | `config('docket.notifications.classes')` map |
| Channels | any Laravel channel; preferences are channel-agnostic strings |
| Recipients | `Docket::addRecipientResolver()` |
| Ownership | `Docket::resolveOwnerUsing()` |
| Suppression | `Docket::mute()`, quiet hours, `SuppressionGate` |
| Change appliers | `Docket::applier()` |
| Attachment storage | `Docket::attachmentDriver()` |
| Image variants | `VariantGenerator` binding |
| File fields on targets | `Docket::bindFile()` — a `FileBinding` per model + field |
| References | `Docket::generateReferencesUsing(MyGenerator::class)` — `ReferenceGenerator` contract; default format `DKT-{year}-{sequence:6}` |
| Authorization | standard policies; `docket.view-internal` gate ability |
| Table prefix / key types / tenancy | config |

## Testing

`Docket::fake()` captures events and notifications while reports still persist.

```php
Docket::fake();

// … exercise your app …

Docket::assertReported(fn (Report $r) => $r->subject->is($order) && $r->kind === 'report');
Docket::assertReportedTimes(1);
Docket::assertNothingReported();
Docket::assertStatusChanged($report, from: 'open', to: 'closed');
Docket::assertDispatched('docket.report.closed');
Docket::assertNotified($user, StatusChangedNotification::class);
Docket::assertNotNotified($user, StatusChangedNotification::class);
Docket::assertSuppressed($user, reason: 'quiet_hours');
Docket::assertProposalApplied($proposal);
Docket::assertProposalConflicted($proposal);
```

Factories ship for `Report`, `Service`, `Attachment` and `Proposal`. `FakeAttachmentDriver` keeps bytes in memory:

```php
Docket::attachmentDriver('fake', FakeAttachmentDriver::class);
config()->set('docket.attachments.driver', 'fake');
```

## Console

| Command | Purpose |
|---|---|
| `docket:install` | Publish config and migrations, optionally migrate |
| `docket:prune [--staged=7] [--archived=90] [--dry-run]` | Remove stale staged / archived attachments |

## Optional HTTP API

Set `docket.api.enabled` to expose a small JSON API under `docket.api.prefix` behind `docket.api.middleware`:

```
GET  /docket/reports                     roots visible to the user (?category=, ?kind=, ?per_page=)
POST /docket/reports                     description, title, kind, visibility, priority, meta, services[], parent, attachments[]
GET  /docket/reports/{reference|id}      with the children the user may see, transitions, attachments
DELETE /docket/reports/{reference|id}    soft-deletes; checks a policy delete() when one exists
POST /docket/reports/{reference|id}/transition   status, reason; an illegal transition is a 422
```

Endpoints only reach reports the user can see. A `Report` policy's `create`, `delete` and `transition` methods are checked when defined and allowed when not, so register a policy before exposing the API.

## Configuration

Set these in `config/docket.php` before migrating:

- **`key_type`** (`ulid` | `uuid` | `id`): primary keys of Docket's tables. **`morph_key_type`** (`id` | `ulid` | `uuid` | `string`): keys of your models that Docket points at.
- **`table_prefix`**: defaults to `docket_`.
- **`tenancy`**: stamps `tenant_id` and scopes report queries to the current tenant. Bypass with `withoutGlobalScope('docket_tenant')`.
- **`meta.index`**: makes `whereMeta()` use an indexed side table instead of a JSON query.

## Development

```bash
composer test       # Pest
composer analyse    # PHPStan
```

## License

MIT
