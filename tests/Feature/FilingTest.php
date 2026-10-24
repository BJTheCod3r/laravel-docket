<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Enums\Visibility;
use BJTheCod3r\Docket\Events\ReportCreated;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use BJTheCod3r\Docket\Models\Service;
use BJTheCod3r\Docket\Tests\Fixtures\Order;
use Illuminate\Support\Facades\Event;

it('files a report with zero configuration', function () {
    $user = $this->user();
    $order = Order::create(['number' => 'A1']);

    $report = Docket::report()->about($order)->from($user)->body('Wrong item shipped')->create();

    expect($report)->toBeInstanceOf(Report::class)
        ->and($report->exists)->toBeTrue()
        ->and($report->reference)->toMatch('/^DKT-\d{4}-\d{6}$/')
        ->and($report->kind)->toBe('report')
        ->and($report->status)->toBe('open')
        ->and($report->statusCategory()->value)->toBe('open')
        ->and($report->visibility)->toBe('internal')
        ->and($report->depth)->toBe(0)
        ->and($report->root_id)->toBeNull()
        ->and($report->subject->is($order))->toBeTrue()
        ->and($report->reporter->is($user))->toBeTrue()
        ->and($report->description)->toBe('Wrong item shipped');
});

it('generates sequential references per year', function () {
    $a = Docket::report()->body('one')->create();
    $b = Docket::report()->body('two')->create();

    $year = now()->format('Y');

    expect($a->reference)->toBe("DKT-{$year}-000001")
        ->and($b->reference)->toBe("DKT-{$year}-000002");
});

it('honours a custom reference format', function () {
    config()->set('docket.reports.reference_format', '{kind}-{sequence:3}');

    $report = Docket::report()->body('x')->create();

    expect($report->reference)->toBe('REP-001');
});

it('accepts the full builder surface', function () {
    $user = $this->user();
    $agent = $this->user('Grace');
    $team = $this->team();
    $service = Service::factory()->create(['key' => 'checkout-api']);

    $report = Docket::report()
        ->about($service)
        ->from($user)
        ->to($team)
        ->assignTo($agent)
        ->title('Checkout returning 500s')
        ->body('Since the 2.4 deploy')
        ->kind('report')
        ->visibility(Visibility::Internal)
        ->meta(['env' => 'production'])
        ->meta(['build' => '2026.09.1'])
        ->services([$service], 'affected')
        ->priority(1)
        ->dueIn('4 hours')
        ->create();

    expect($report->owner->is($team))->toBeTrue()
        ->and($report->assignee->is($agent))->toBeTrue()
        ->and($report->title)->toBe('Checkout returning 500s')
        ->and($report->meta)->toBe(['env' => 'production', 'build' => '2026.09.1'])
        ->and($report->priority)->toBe(1)
        ->and($report->due_at)->not->toBeNull()
        ->and($report->services)->toHaveCount(1)
        ->and($report->services->first()->pivot->role)->toBe('affected')
        ->and($service->reports()->count())->toBe(1);
});

it('resolves the owner from the subject when not given', function () {
    $owner = $this->user('Owner');
    $order = Order::create(['number' => 'A2', 'user_id' => $owner->id]);

    $report = Docket::report()->about($order)->body('x')->create();

    expect($report->owner->is($owner))->toBeTrue();
});

it('resolves the owner from the first service when the subject has none', function () {
    $team = $this->team();
    $service = Service::factory()->ownedBy($team)->create();

    $report = Docket::report()->services([$service->key])->body('x')->create();

    expect($report->owner->is($team))->toBeTrue();
});

it('lets the host app override owner resolution', function () {
    $fallback = $this->user('Fallback');

    Docket::resolveOwnerUsing(fn () => $fallback);

    $report = Docket::report()->body('x')->create();

    expect($report->owner->is($fallback))->toBeTrue();
});

it('dispatches ReportCreated for every report', function () {
    Event::fake([ReportCreated::class]);

    $report = Docket::report()->body('x')->create();

    Event::assertDispatched(ReportCreated::class, fn (ReportCreated $e) => $e->report()->is($report));
});

it('exposes the reportable traits', function () {
    $user = $this->user();
    $order = Order::create(['number' => 'A3']);

    $order->report('Damaged')->from($user)->create();
    $user->fileReport($order)->body('Late')->create();

    expect($order->reports)->toHaveCount(2)
        ->and($user->filedReports)->toHaveCount(2)
        ->and($order->unresolvedReports()->count())->toBe(2);
});

it('supports the query scopes', function () {
    $team = $this->team();
    $user = $this->user();

    $a = Docket::report()->to($team)->from($user)->body('a')->meta(['env' => 'prod'])->priority(1)->create();
    $b = Docket::report()->to($team)->body('b')->meta(['env' => 'staging'])->visibility(Visibility::Public)->create();
    $b->transitionTo('closed');

    expect(Report::open()->count())->toBe(1)
        ->and(Report::closed()->count())->toBe(1)
        ->and(Report::unresolved()->count())->toBe(1)
        ->and(Report::forOwner($team)->count())->toBe(2)
        ->and(Report::forReporter($user)->count())->toBe(1)
        ->and(Report::whereMeta('env', 'prod')->first()->is($a))->toBeTrue()
        ->and(Report::visibility('public')->first()->is($b))->toBeTrue()
        ->and(Report::visibleTo(null)->count())->toBe(1)
        ->and(Report::visibleTo($user)->count())->toBe(2);
});

it('scopes the visibleTo query through a gate ability when defined', function () {
    $viewer = $this->user('Viewer');
    Docket::report()->body('internal')->create();

    expect(Report::visibleTo($viewer)->count())->toBe(0);

    \Illuminate\Support\Facades\Gate::define('docket.view-internal', fn ($user) => true);

    expect(Report::visibleTo($viewer)->count())->toBe(1);
});

it('stamps the tenant column when tenancy is enabled', function () {
    config()->set('docket.tenancy.enabled', true);
    config()->set('docket.tenancy.resolver', fn () => 'acme');

    $report = Docket::report()->body('x')->create();

    expect($report->tenant_id)->toBe('acme');
});

it('only queries reports of the current tenant', function () {
    config()->set('docket.tenancy.enabled', true);
    $tenant = 'acme';
    config()->set('docket.tenancy.resolver', function () use (&$tenant) {
        return $tenant;
    });

    $acme = Docket::report()->body('acme')->create();
    $tenant = 'globex';
    Docket::report()->body('globex')->create();

    expect(Report::pluck('tenant_id')->all())->toBe(['globex'])
        ->and(Report::find($acme->getKey()))->toBeNull();

    $tenant = null;

    expect(Report::count())->toBe(2);
});

it('accepts a custom reference generator and validates model bindings', function () {
    Docket::generateReferencesUsing(new class implements \BJTheCod3r\Docket\Contracts\ReferenceGenerator
    {
        public function generate(Report $report): string
        {
            return 'CUSTOM-'.$report->kind;
        }
    });

    expect(Docket::report()->body('x')->create()->reference)->toBe('CUSTOM-report')
        ->and(Docket::model('service'))->toBe(Service::class);

    config()->set('docket.models.report', 'Nope\\Missing');
    expect(fn () => Docket::model('report'))->toThrow(\BJTheCod3r\Docket\Exceptions\DocketException::class);
});

it('uses a swapped report model everywhere', function () {
    config()->set('docket.models.report', ExtendedReport::class);

    $report = Docket::report()->body('x')->create();
    $child = $report->comment('y');

    expect($report)->toBeInstanceOf(ExtendedReport::class)
        ->and($child)->toBeInstanceOf(ExtendedReport::class)
        ->and($report->shout())->toBe('X');
});

class ExtendedReport extends Report
{
    public function shout(): string
    {
        return strtoupper((string) $this->description);
    }
}

it('applies kind and parent defaults to reports created without the builder', function () {
    Docket::kind('incident', ['default_status' => 'in_progress']);
    $parent = Docket::report()->body('root')->visibility(Visibility::Public)->create();

    $child = Report::create(['description' => 'child', 'kind' => 'incident', 'parent_id' => $parent->getKey()]);

    expect($child->status)->toBe('in_progress')
        ->and($child->visibility)->toBe(Visibility::Public->value);
});
