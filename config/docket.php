<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Table Prefix & Key Type
    |--------------------------------------------------------------------------
    |
    | Every Docket table is prefixed so nothing collides with your own schema.
    | "key_type" controls the primary key of Docket's own tables (ulid | uuid
    | | id). "morph_key_type" describes the primary keys of *your* models that
    | Docket points at (owner, reporter, subject, ...): id | ulid | uuid.
    |
    */

    'table_prefix' => 'docket_',
    'key_type' => 'ulid',
    'morph_key_type' => 'id',

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Extend any of these and point the binding at your class.
    |
    */

    'models' => [
        'report' => BJTheCod3r\Docket\Models\Report::class,
        'service' => BJTheCod3r\Docket\Models\Service::class,
        'revision' => BJTheCod3r\Docket\Models\Revision::class,
        'transition' => BJTheCod3r\Docket\Models\StatusTransition::class,
        'subscription' => BJTheCod3r\Docket\Models\Subscription::class,
        'attachment' => BJTheCod3r\Docket\Models\Attachment::class,
        'mute' => BJTheCod3r\Docket\Models\Mute::class,
        'proposal' => BJTheCod3r\Docket\Models\Proposal::class,
        'approval' => BJTheCod3r\Docket\Models\ProposalApproval::class,
        'setting' => BJTheCod3r\Docket\Models\NotificationSetting::class,
        'meta_index' => BJTheCod3r\Docket\Models\MetaIndex::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    'reports' => [
        'default_status' => BJTheCod3r\Docket\Statuses\CoreStatus::Open->value,
        'default_visibility' => BJTheCod3r\Docket\Enums\Visibility::Internal->value,
        'default_kind' => BJTheCod3r\Docket\Enums\ReportKind::Report->value,
        'max_depth' => 5,
        'reference_format' => 'DKT-{year}-{sequence:6}',
    ],

    /*
    |--------------------------------------------------------------------------
    | Statuses
    |--------------------------------------------------------------------------
    |
    | Statuses merge the shipped CoreStatus enum, the "custom" array below and
    | Docket::statuses()->register() calls. Every status MUST declare a
    | category: open | active | resolved | closed.
    |
    */

    'statuses' => [
        'custom' => [
            // 'awaiting_vendor' => [
            //     'label' => 'Awaiting vendor',
            //     'category' => 'active',
            //     'transitions' => ['in_progress', 'closed'],
            //     'color' => '#f59e0b',
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Status Rollup
    |--------------------------------------------------------------------------
    |
    | How a child's status affects its parent: none | latest_child |
    | all_terminal | severest | FQCN implementing RollupStrategy. A kind's
    | own "rollup" option wins; comments and proposals ship with "none" and
    | resolutions with "latest_child".
    |
    */

    'rollup' => [
        'default' => 'severest',
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Kinds
    |--------------------------------------------------------------------------
    |
    | Additional kinds beyond report | comment | resolution | proposal, or
    | overrides of those. Companion packages register theirs at runtime via
    | Docket::kind().
    |
    */

    'kinds' => [
        // 'incident' => ['label' => 'Incident', 'rollup' => 'severest'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta Index
    |--------------------------------------------------------------------------
    |
    | When "index" is true, whereMeta() queries an indexed side table instead
    | of a JSON path. Only reports saved after it is turned on are indexed;
    | backfill existing reports yourself. Strings over 255 characters are
    | indexed by key only.
    |
    */

    'meta' => [
        'index' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | "queue" is the queue notifications are sent on (null = the default
    | queue). "notify_actor" notifies the person who caused the event.
    | "ancestor_depth" is how many parent owners up the thread are notified
    | (1 = the parent's owner).
    |
    | Events in "ignore" never notify; they are for your listeners.
    | "child_added" is ignored because the child's own "created" event
    | already reaches the parent's owner.
    |
    | Suppression: "default_behaviour" applies to maintenance windows.
    | Reports with a priority at or below "min_priority_bypass" always send
    | (1 = most urgent). "timezone_fallback" null = config('app.timezone').
    |
    | "classes" maps an event key to the notification class that sends it.
    |
    */

    'notifications' => [
        'enabled' => true,
        'queue' => null,
        'notify_reporter' => false,
        'notify_actor' => false,
        'ancestor_depth' => 1,
        'cooldown_seconds' => 300,
        'digest' => ['enabled' => false, 'window_minutes' => 15],
        'ignore' => [
            'docket.report.updated',
            'docket.report.child_added',
            'docket.report.resolved',
            'docket.report.closed',
            'docket.report.reopened',
        ],
        'suppression' => [
            'default_behaviour' => 'drop',
            'quiet_hours_behaviour' => 'defer',
            'min_priority_bypass' => 1,
            'timezone_fallback' => null,
        ],
        'defaults' => [
            '*' => ['channels' => ['database']],
            'docket.report.created' => ['channels' => ['database', 'mail']],
            'docket.report.status_changed' => ['channels' => ['database', 'mail']],
        ],
        'classes' => [
            'docket.report.status_changed' => BJTheCod3r\Docket\Notifications\StatusChangedNotification::class,
            'docket.proposal.*' => BJTheCod3r\Docket\Notifications\ProposalNotification::class,
            '*' => BJTheCod3r\Docket\Notifications\ReportNotification::class,
            'digest' => BJTheCod3r\Docket\Notifications\DigestNotification::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Swap any package event for your own subclass.
    |
    */

    'events' => [
        // BJTheCod3r\Docket\Events\StatusChanged::class => App\Events\OurStatusChanged::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Change Management
    |--------------------------------------------------------------------------
    |
    | "queue" null applies approved proposals inline; a queue name runs the
    | ApplyProposal job on that queue. "staging_disk" holds proposed files
    | (null = attachments.disk); keep it private. "prune_archived_after_days"
    | is the revert window.
    |
    */

    'proposals' => [
        'enabled' => true,
        'required_approvals' => 1,
        'auto_apply_on_approval' => true,
        'queue' => null,
        'staging_disk' => null,
        'prune_staged_after_days' => 7,
        'prune_archived_after_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | "variant_generator" is a class implementing VariantGenerator (null = no
    | variants). "queue_variants": true = the default queue, false = inline,
    | a queue name = that queue.
    |
    */

    'attachments' => [
        'enabled' => true,
        'driver' => 'filesystem',
        'disk' => env('DOCKET_DISK', 'local'),
        'path' => 'docket/{collection}/{Y}/{m}',
        'max_kb' => 10240,
        'mimes' => ['image/*', 'application/pdf', 'text/plain', 'text/csv'],
        'variant_generator' => null,
        'variants' => [],
        'queue_variants' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Actions check your registered policies. "guard" is the auth guard used
    | to find the current user (null = the default guard).
    |
    */

    'authorization' => [
        'guard' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | When enabled, new reports are stamped with the current tenant and report
    | queries are scoped to it. "resolver" is a callable or class name that
    | returns the current tenant id; when it returns null nothing is filtered.
    |
    */

    'tenancy' => [
        'enabled' => false,
        'column' => 'tenant_id',
        'resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    |
    | Optional JSON routes under "prefix", behind "middleware".
    |
    */

    'api' => [
        'enabled' => false,
        'prefix' => 'docket',
        'middleware' => ['api', 'auth:sanctum'],
    ],

];
