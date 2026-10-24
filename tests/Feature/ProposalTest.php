<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Contracts\ChangeApplier;
use BJTheCod3r\Docket\Enums\ProposalState;
use BJTheCod3r\Docket\Enums\ProposalStrategy;
use BJTheCod3r\Docket\Events\ProposalApplied;
use BJTheCod3r\Docket\Events\ProposalConflicted;
use BJTheCod3r\Docket\Events\ProposalSubmitted;
use BJTheCod3r\Docket\Exceptions\InvalidProposalException;
use BJTheCod3r\Docket\Exceptions\NotProposableException;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Jobs\ApplyProposal;
use BJTheCod3r\Docket\Models\Attachment;
use BJTheCod3r\Docket\Models\Proposal;
use BJTheCod3r\Docket\Models\Report;
use BJTheCod3r\Docket\Proposals\ProposalService;
use BJTheCod3r\Docket\Tests\Fixtures\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

it('proposes changes as a report with a side table', function () {
    Event::fake([ProposalSubmitted::class]);
    $user = $this->user();
    $product = $this->product();

    $proposal = $product->proposeChanges(
        changes: ['price' => 4999, 'description' => 'Updated copy'],
        by: $user,
        reason: 'Q4 repricing',
    );

    expect($proposal)->toBeInstanceOf(Proposal::class)
        ->and($proposal->state())->toBe(ProposalState::Submitted)
        ->and($proposal->proposedChanges())->toBe(['price' => 4999, 'description' => 'Updated copy'])
        ->and($proposal->originalSnapshot())->toBe(['price' => 3999, 'description' => 'A widget'])
        ->and($proposal->report->kind)->toBe('proposal')
        ->and($proposal->report->subject->is($product))->toBeTrue()
        ->and($proposal->report->reporter->is($user))->toBeTrue()
        ->and($proposal->report->description)->toBe('Q4 repricing')
        ->and($proposal->target->is($product))->toBeTrue()
        ->and($product->proposals)->toHaveCount(1)
        ->and($product->pendingProposals()->count())->toBe(1)
        ->and($proposal->diff())->toBe([
            'price' => ['from' => 3999, 'to' => 4999, 'changed' => true],
            'description' => ['from' => 'A widget', 'to' => 'Updated copy', 'changed' => true],
        ]);

    Event::assertDispatched(ProposalSubmitted::class);
});

it('rejects attributes outside the allowlist at propose time', function () {
    $this->product()->proposeChanges(changes: ['name' => 'Nope']);
})->throws(NotProposableException::class);

it('validates against proposalRules at propose time', function () {
    $this->product()->proposeChanges(changes: ['price' => -1]);
})->throws(InvalidProposalException::class, 'invalid');

it('refuses models without the trait', function () {
    app(\BJTheCod3r\Docket\Proposals\ProposalService::class)->propose($this->team(), ['name' => 'x']);
})->throws(NotProposableException::class);

it('approves and auto-applies when the approval threshold is met', function () {
    Event::fake([ProposalApplied::class]);
    $manager = $this->user('Manager');
    $product = $this->product();

    $proposal = $product->proposeChanges(changes: ['price' => 4999]);
    $proposal->approve(by: $manager, comment: 'Confirmed with finance');

    expect($proposal->state())->toBe(ProposalState::Applied)
        ->and($proposal->applied_at)->not->toBeNull()
        ->and($proposal->applied_snapshot['attributes'])->toBe(['price' => 3999])
        ->and($product->fresh()->price)->toBe(4999)
        ->and($proposal->approvals)->toHaveCount(1)
        ->and($proposal->approvals->first()->comment)->toBe('Confirmed with finance')
        ->and($proposal->report->fresh()->isClosed())->toBeTrue();

    Event::assertDispatched(ProposalApplied::class);
});

it('waits for the required number of approvals', function () {
    $product = $this->product();
    $proposal = $product->proposeChanges(changes: ['price' => 4999], requiredApprovals: 2);

    $a = $this->user('A');
    $proposal->approve(by: $a);
    $proposal->approve(by: $a);
    expect($proposal->state())->toBe(ProposalState::Submitted)->and($product->fresh()->price)->toBe(3999)
        ->and($proposal->approvalCount())->toBe(1);

    expect(fn () => $proposal->apply())->toThrow(InvalidProposalException::class, 'needs 2 approval')
        ->and(fn () => $proposal->approve())->toThrow(InvalidProposalException::class, 'needs a reviewer');

    $proposal->approve(by: $this->user('B'));
    expect($proposal->state())->toBe(ProposalState::Applied)->and($product->fresh()->price)->toBe(4999);
});

it('can apply manually when auto-apply is off', function () {
    config()->set('docket.proposals.auto_apply_on_approval', false);
    $product = $this->product();

    $proposal = $product->proposeChanges(changes: ['price' => 4999]);
    $proposal->approve(by: $this->user());

    expect($proposal->state())->toBe(ProposalState::Approved)
        ->and($proposal->report->fresh()->isActive())->toBeTrue()
        ->and($product->fresh()->price)->toBe(3999);

    expect($proposal->apply())->toBeTrue()
        ->and($product->fresh()->price)->toBe(4999);
});

it('rejects and withdraws', function () {
    $product = $this->product();

    $a = $product->proposeChanges(changes: ['price' => 1]);
    $a->reject(by: $this->user(), comment: 'Wrong SKU');

    $b = $product->proposeChanges(changes: ['price' => 2]);
    $b->withdraw();

    expect($a->state())->toBe(ProposalState::Rejected)
        ->and($a->report->fresh()->isClosed())->toBeTrue()
        ->and($b->state())->toBe(ProposalState::Withdrawn)
        ->and($product->fresh()->price)->toBe(3999)
        ->and($product->pendingProposals()->count())->toBe(0);

    expect(fn () => $a->approve())->toThrow(InvalidProposalException::class);
});

it('detects drift and blocks the apply', function () {
    Event::fake([ProposalConflicted::class]);
    config()->set('docket.proposals.auto_apply_on_approval', false);

    $product = $this->product();
    $proposal = $product->proposeChanges(changes: ['price' => 4999, 'description' => 'New']);
    $proposal->approve(by: $this->user());

    $product->update(['price' => 4500]); // someone else moved it

    expect($proposal->hasConflicts())->toBeTrue()
        ->and($proposal->detectConflicts())->toBe(['price' => ['original' => 3999, 'current' => 4500, 'proposed' => 4999]])
        ->and($proposal->apply())->toBeFalse()
        ->and($proposal->state())->toBe(ProposalState::Conflicted)
        ->and($proposal->fresh()->conflicts)->toHaveKey('price')
        ->and($product->fresh()->price)->toBe(4500)
        ->and($product->fresh()->description)->toBe('A widget');

    Event::assertDispatched(ProposalConflicted::class, fn ($e) => array_keys($e->conflicts) === ['price']);

    // rebase: re-snapshot; drop what no longer differs
    $product->update(['description' => 'New']);
    $proposal->rebase();

    expect($proposal->state())->toBe(ProposalState::Approved)
        ->and($proposal->proposedChanges())->toBe(['price' => 4999])
        ->and($proposal->originalSnapshot())->toBe(['price' => 4500])
        ->and($proposal->apply())->toBeTrue()
        ->and($product->fresh()->price)->toBe(4999);
});

it('can force through a conflict', function () {
    config()->set('docket.proposals.auto_apply_on_approval', false);
    $product = $this->product();
    $proposal = $product->proposeChanges(changes: ['price' => 4999]);
    $proposal->approve(by: $this->user());
    $product->update(['price' => 4500]);

    expect($proposal->forceApply())->toBeTrue()
        ->and($product->fresh()->price)->toBe(4999);
});

it('forces a conflicting proposal through', function () {
    config()->set('docket.proposals.auto_apply_on_approval', false);
    $product = $this->product();
    $proposal = $product->proposeChanges(changes: ['price' => 4999]);
    $proposal->approve(by: $this->user());
    $product->update(['price' => 4500]);

    expect($proposal->forceApply())->toBeTrue()->and($product->fresh()->price)->toBe(4999);
});

it('reverts an applied proposal from its snapshot', function () {
    $product = $this->product();
    $proposal = $product->proposeChanges(changes: ['price' => 4999, 'tags' => ['a', 'b']]);
    $proposal->approve(by: $this->user());

    expect($product->fresh()->price)->toBe(4999)->and($product->fresh()->tags)->toBe(['a', 'b']);

    $proposal->revert();

    expect($proposal->state())->toBe(ProposalState::Reverted)
        ->and($proposal->reverted_at)->not->toBeNull()
        ->and($product->fresh()->price)->toBe(3999)
        ->and($product->fresh()->tags)->toBeNull()
        ->and($proposal->report->fresh()->status)->toBe('reopened');
});

it('supports textual proposals with nothing to apply', function () {
    $product = $this->product();
    $proposal = $product->proposeChanges(by: $this->user(), reason: 'Please rename this to something less generic.');

    expect($proposal->isTextual())->toBeTrue()->and($proposal->diff())->toBe([]);

    $proposal->approve(by: $this->user('Manager'));

    expect($proposal->state())->toBe(ProposalState::Applied)->and($product->fresh()->price)->toBe(3999);
});

it('routes through a registered applier', function () {
    Docket::applier(Product::class, DoublingApplier::class);
    $product = $this->product();

    $proposal = $product->proposeChanges(changes: ['price' => 100]);
    $proposal->approve(by: $this->user());

    expect($product->fresh()->price)->toBe(200);

    $proposal->revert();
    expect($product->fresh()->price)->toBe(3999);
});

it('restricts reviewers to the target approvers', function () {
    $allowed = $this->user('Allowed');
    $stranger = $this->user('Stranger');
    Product::$approvers = [$allowed];

    $proposal = $this->product()->proposeChanges(changes: ['price' => 1]);

    expect(fn () => $proposal->approve(by: $stranger))->toThrow(InvalidProposalException::class, 'not allowed');

    $proposal->approve(by: $allowed);
    expect($proposal->state())->toBe(ProposalState::Applied);

    Product::$approvers = null;
});

it('patches keyed arrays by default and replaces them on request', function () {
    $product = $this->product(['tags' => ['size' => 'L']]);

    $product->proposeChanges(changes: ['tags' => ['color' => 'red']])->approve(by: $this->user());
    expect($product->fresh()->tags)->toBe(['size' => 'L', 'color' => 'red']);

    $replace = $product->fresh()->proposeChanges(changes: ['tags' => ['color' => 'blue']], strategy: ProposalStrategy::Replace);
    $replace->approve(by: $this->user());
    expect($product->fresh()->tags)->toBe(['color' => 'blue']);

    $replace->revert();
    expect($product->fresh()->tags)->toBe(['size' => 'L', 'color' => 'red']);
});

it('rejects an unknown strategy', function () {
    $product = $this->product();

    expect(fn () => $product->proposeChanges(changes: ['price' => 1], strategy: 'nonsense'))->toThrow(InvalidProposalException::class, 'strategy [nonsense]');
});

it('rebases onto drift', function () {
    config()->set('docket.proposals.auto_apply_on_approval', false);
    $product = $this->product();
    $proposal = $product->proposeChanges(changes: ['price' => 4999, 'description' => 'New copy']);
    $proposal->approve(by: $this->user());
    $product->update(['price' => 4500]);

    expect($proposal->rebase()->apply())->toBeTrue()
        ->and($product->fresh()->price)->toBe(4999)
        ->and($product->fresh()->description)->toBe('New copy')
        ->and($proposal->fresh()->original['price'])->toBe(4500);
});

it('applies on a queue when configured', function () {
    Bus::fake([ApplyProposal::class]);
    config()->set('docket.proposals.queue', 'proposals');
    $product = $this->product();
    $proposal = $product->proposeChanges(changes: ['price' => 4999]);

    $proposal->approve(by: $this->user());

    expect($proposal->state())->toBe(ProposalState::Approved)->and($product->fresh()->price)->toBe(3999);
    Bus::assertDispatched(ApplyProposal::class, fn (ApplyProposal $job) => $job->queue === 'proposals' && $job->proposal->is($proposal));

    (new ApplyProposal($proposal))->handle(app(ProposalService::class));
    (new ApplyProposal($proposal->fresh()))->handle(app(ProposalService::class)); // a retry is a no-op

    expect($proposal->fresh()->state())->toBe(ProposalState::Applied)->and($product->fresh()->price)->toBe(4999);
});

it('checks proposal policies', function () {
    Gate::policy(Proposal::class, ProposalDenyPolicy::class);
    $proposal = $this->product()->proposeChanges(changes: ['price' => 1]);

    expect(fn () => $proposal->approve(by: $this->user()))->toThrow(InvalidProposalException::class, 'not authorized to approve')
        ->and(fn () => $proposal->reject(by: $this->user()))->toThrow(InvalidProposalException::class, 'not authorized to reject');
});

it('builds valid rows from the factories', function () {
    $product = $this->product();
    $report = Report::factory()->about($product)->create();
    $proposal = Proposal::factory()->targeting($product)->create(['changes' => ['price' => 10], 'original' => ['price' => 3999]]);
    $attachment = Attachment::factory()->for_($report)->staged()->create();

    expect($report->statusCategory()->value)->toBe('open')
        ->and($report->reference)->not->toBeNull()
        ->and($proposal->report->kind)->toBe('proposal')
        ->and($proposal->target->is($product))->toBeTrue()
        ->and($attachment->attachable->is($report))->toBeTrue()
        ->and($attachment->isStaged())->toBeTrue();

    $proposal->approve(by: $this->user());
    expect($product->fresh()->price)->toBe(10);
});

it('can be disabled', function () {
    config()->set('docket.proposals.enabled', false);

    $this->product()->proposeChanges(changes: ['price' => 1]);
})->throws(InvalidProposalException::class, 'disabled');

class ProposalDenyPolicy
{
    public function approve($user, Proposal $proposal): bool
    {
        return false;
    }

    public function reject($user, Proposal $proposal): bool
    {
        return false;
    }
}

class DoublingApplier implements ChangeApplier
{
    public function apply(Model $target, array $changes, Proposal $proposal): array
    {
        $snapshot = ['price' => $target->price];
        $target->forceFill(['price' => $changes['price'] * 2])->save();

        return $snapshot;
    }

    public function revert(Model $target, array $snapshot, Proposal $proposal): void
    {
        $target->forceFill($snapshot)->save();
    }
}

it('refuses to apply a proposal twice from a stale copy', function () {
    $product = $this->product();
    $proposal = $product->proposeChanges(changes: ['price' => 4999]);
    $stale = Proposal::find($proposal->getKey());

    $proposal->approve(by: $this->user('Manager'));

    expect(fn () => $stale->forceApply())->toThrow(InvalidProposalException::class, 'while it is [applied]')
        ->and($proposal->fresh()->isApplied())->toBeTrue();
});

it('does not report a conflict for an unchanged date attribute', function () {
    $product = $this->product(['published_at' => '2026-10-24 10:00:00']);

    $proposal = $product->proposeChanges(changes: ['price' => 4999]);
    $proposal->approve(by: $this->user('Manager'));

    expect($proposal->fresh()->state())->toBe(ProposalState::Applied);

    $dated = $product->fresh()->proposeChanges(changes: ['published_at' => '2026-11-01 09:00:00']);
    expect($dated->detectConflicts())->toBe([]);
});

it('keeps the proposal report in step when the reviewer cannot transition reports', function () {
    \Illuminate\Support\Facades\Gate::policy(\BJTheCod3r\Docket\Models\Report::class, TransitionDenyPolicy::class);
    $proposal = $this->product()->proposeChanges(changes: ['price' => 1]);

    $proposal->approve(by: $this->user('Manager'));

    expect($proposal->fresh()->isApplied())->toBeTrue()
        ->and($proposal->report->fresh()->isClosed())->toBeTrue();
});

it('checks forceApply, revert and withdraw policies when defined', function () {
    Gate::policy(Proposal::class, ProposalLockdownPolicy::class);
    $proposal = $this->product()->proposeChanges(changes: ['price' => 1]);

    expect(fn () => $proposal->withdraw(by: $this->user('Someone')))->toThrow(InvalidProposalException::class, 'not authorized to withdraw')
        ->and(fn () => $proposal->forceApply(by: $this->user('Other')))->toThrow(InvalidProposalException::class, 'not authorized to forceApply');
});

class TransitionDenyPolicy
{
    public function transition($user, \BJTheCod3r\Docket\Models\Report $report, string $to): bool
    {
        return false;
    }
}

class ProposalLockdownPolicy
{
    public function withdraw($user, Proposal $proposal): bool
    {
        return false;
    }

    public function forceApply($user, Proposal $proposal): bool
    {
        return false;
    }
}
