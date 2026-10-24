<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use BJTheCod3r\Docket\Tests\Fixtures\Order;


it('works with auto-increment keys, string morphs and a custom prefix', function () {
    $user = $this->user();
    $order = Order::create(['number' => 'A1']);

    $root = Docket::report()->about($order)->from($user)->to($user)->body('x')->create();
    $child = $root->comment('y', by: $user);
    $child->transitionTo('closed');

    expect($root->getKey())->toBeInt()
        ->and($root->getTable())->toBe('dk_reports')
        ->and($child->root_id)->toBe($root->getKey())
        ->and($root->subject->is($order))->toBeTrue()
        ->and($root->owner->is($user))->toBeTrue()
        ->and($child->transitions()->count())->toBe(1)
        ->and(Docket::table('services'))->toBe('dk_services');

    $proposal = $this->product()->proposeChanges(changes: ['price' => 1]);
    $proposal->approve(by: $user);
    expect($proposal->getKey())->toBeInt()->and($proposal->isApplied())->toBeTrue();
});

it('exposes the optional JSON API', function () {
    $user = $this->user();
    $root = Docket::report()->from($user)->to($user)->title('Hello')->body('x')->create();
    $root->comment('reply');

    $this->getJson('/docket/reports')->assertOk()->assertJsonCount(0, 'data'); // guests only see public reports

    $this->actingAs($user);

    $this->getJson('/docket/reports')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $root->reference);

    $this->getJson('/docket/reports/'.$root->reference)
        ->assertOk()
        ->assertJsonPath('data.title', 'Hello')
        ->assertJsonCount(1, 'data.children');

    $this->postJson('/docket/reports', ['description' => 'Created over HTTP', 'kind' => 'report', 'meta' => ['via' => 'api']])
        ->assertCreated()
        ->assertJsonPath('data.meta.via', 'api');

    $this->postJson('/docket/reports/'.$root->reference.'/transition', ['status' => 'in_progress', 'reason' => 'Looking'])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress');

    $this->postJson('/docket/reports/'.$root->reference.'/transition', ['status' => 'not_a_status'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $this->postJson('/docket/reports', ['description' => 'x', 'visibility' => 'secret'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('visibility');

    $this->getJson('/docket/reports?category=bogus')->assertUnprocessable();

    $this->postJson('/docket/reports/'.$root->reference.'/transition', ['status' => 'reopened'])
        ->assertUnprocessable() // registered status, but not reachable from in_progress
        ->assertJsonValidationErrors('status');

    expect(Report::count())->toBe(3);

    $this->deleteJson('/docket/reports/'.$root->reference)->assertNoContent();
    $this->getJson('/docket/reports/'.$root->reference)->assertNotFound();

    expect(Report::withTrashed()->find($root->getKey())->trashed())->toBeTrue();
});

it('hides internal reports and replies from API callers who cannot see them', function () {
    $user = $this->user();
    $internal = Docket::report()->body('staff only')->visibility('internal')->create();
    $public = Docket::report()->body('public')->visibility('public')->create();
    $public->comment('staff note', visibility: 'internal');

    $this->postJson('/docket/reports/'.$internal->reference.'/transition', ['status' => 'closed'])->assertNotFound();
    $this->postJson('/docket/reports', ['description' => 'x', 'parent' => $internal->reference])->assertNotFound();
    $this->getJson('/docket/reports/'.$public->reference)->assertOk()->assertJsonCount(0, 'data.children');

    expect($internal->fresh()->status)->toBe('open');

    \Illuminate\Support\Facades\Gate::define('docket.view-internal', fn () => true);
    $this->actingAs($user)->getJson('/docket/reports/'.$public->reference)->assertJsonCount(1, 'data.children');
});

it('checks a policy delete method on the API', function () {
    \Illuminate\Support\Facades\Gate::policy(Report::class, ReportDeleteDenyPolicy::class);

    $user = $this->user();
    $report = Docket::report()->from($user)->to($user)->body('x')->create();

    $this->actingAs($user)->deleteJson('/docket/reports/'.$report->reference)->assertForbidden();

    expect($report->fresh()->trashed())->toBeFalse();
});

class ReportDeleteDenyPolicy
{
    public function view($user, Report $report): bool
    {
        return true;
    }

    public function delete($user, Report $report): bool
    {
        return false;
    }
}

it('accepts form-encoded input and rejects soft-deleted services', function () {
    $this->actingAs($this->user());
    $service = \BJTheCod3r\Docket\Models\Service::create(['key' => 'billing', 'title' => 'Billing']);

    $this->post('/docket/reports', ['description' => 'x', 'priority' => '2', 'services' => ['billing']], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.priority', 2);

    $service->delete();

    $this->postJson('/docket/reports', ['description' => 'y', 'services' => ['billing']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('services.0');
});
