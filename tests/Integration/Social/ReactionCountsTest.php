<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\OperationHandles;
use SocKit\Reactions\Data\ReactionQuery;
use SocKit\Support\Data\Revision;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Social\ExpiredAdmission;
use SocKit\Tests\Fixtures\Social\ProjectionTarget;
use SocKit\Tests\Fixtures\Social\ReactionScenario;

it('returns only exact target counts and the trusted viewers own active kinds', function (): void {
    $s = new ReactionScenario;
    try {
        expect($s->execute()->isSuccess())->toBeTrue();
        $thanks = $s->reaction('thanks');
        expect($s->execute(command: $s->command(key: 'reaction.thanks.0001', reaction: $thanks), reaction: $thanks)->isSuccess())->toBeTrue();
        expect($s->execute(command: $s->command(action: 'remove', expected: new Revision(1), key: 'reaction.remove.0001'), action: 'remove')->isSuccess())->toBeTrue();
        $other = RuntimeScenario::actor(actor: '0007');
        $s->fake->actors->actingAs($other);
        $s->fake->surfaces->allow(viewer: $other, surface: $s->handle->surface);
        $s->handle = $s->app->make(OperationHandles::class)->forSurface($s->handle->surface);
        expect($s->execute($s->command(key: 'reaction.other.0001'))->isSuccess())->toBeTrue();
        $view = $s->reactions->summary(handle: $s->handle, target: $s->reaction()->target);
        expect($view->problem)->toBeNull()->and($view->counts)->toBe(['like' => 1, 'thanks' => 1])->and($view->ownKinds)->toBe(['like']);
        $batch = $s->reactions->summaryMany(handle: $s->handle, queries: [new ReactionQuery(key: 'article42', target: $s->reaction()->target), new ReactionQuery(key: 'article43', target: $s->reaction(target: '43')->target)]);
        expect($batch['article42'])->toEqual($view)->and($batch['article43'])->toEqual($s->reactions->summary(handle: $s->handle, target: $s->reaction(target: '43')->target));
        expect($batch['article43']->counts)->toBe(['like' => 0, 'thanks' => 0])->and($batch['article43']->ownKinds)->toBe([]);
        $s->fake->surfaces->allow(viewer: $other, surface: RuntimeScenario::surface('acme.b'));
        $b = $s->app->make(OperationHandles::class)->forSurface(RuntimeScenario::surface('acme.b'));
        $viewB = $s->reactions->summary(handle: $b, target: $s->reaction()->target);
        expect($viewB->problem)->toBeNull()->and($viewB->counts)->toBe(['like' => 0, 'thanks' => 0])->and($viewB->ownKinds)->toBe([]);
        $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['visible' => false]);
        $mixed = $s->reactions->summaryMany(handle: $s->handle, queries: [new ReactionQuery(key: 'hidden', target: $s->reaction()->target), new ReactionQuery(key: 'visible', target: $s->reaction(target: '43')->target)]);
        expect($mixed['hidden']->counts)->toBeNull()->and($mixed['hidden']->ownKinds)->toBeNull();
        expect($mixed['visible'])->toEqual($batch['article43']);
        $s->counts(); // B and the default/decoy database remain byte-for-byte unchanged.
    } finally {
        $s->cleanup();
    }
});

it('does not disclose target existence counts or membership under current denial', function (string $mode): void {
    $s = new ReactionScenario;
    try {
        expect($s->execute()->isSuccess())->toBeTrue();
        $before = $s->counts();
        match ($mode) {
            'hide' => $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['visible' => false]),
            'delete' => $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['lifecycle' => 'deleted']),
            'hold' => $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['held' => true]),
            'missing' => $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->delete(),
            'incarnation' => $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['incarnation' => 2]),
            'revoke' => $s->connection()->table('panel_a.envelope_authority')->update(['allowed' => false]),
            'admission' => $s->fake->surfaces->deny(viewer: $s->handle->actor, surface: $s->handle->surface),
            'admission-expiry' => $s->app->instance(SurfaceAudiencePolicy::class, new ExpiredAdmission),
            'actor' => $s->fake->actors->actingAs(RuntimeScenario::actor(generation: '2')),
            'other-installation' => $s->fake->actors->actingAs(RuntimeScenario::actor(installation: 'other')),
            default => $s->app->instance(TargetResolver::class, new ProjectionTarget(delegate: $s->target, mode: $mode)),
        };
        $queries = 0;
        $s->connection()->listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, '"driver_a_reaction_counts"') || str_contains($query->sql, '"driver_a_reactions"')) {
                $queries++;
            }
        });
        $view = $s->reactions->summary(handle: $s->handle, target: $s->reaction()->target);
        expect($view->problem)->toBe(ProblemCode::ContentHidden)->and($view->counts)->toBeNull()->and($view->ownKinds)->toBeNull();
        if (! in_array($mode, ['second-deny', 'generation'], true)) {
            expect($queries)->toBe(0); // Guards precede even the projection SQL.
        }
        $batch = $s->reactions->summaryMany(handle: $s->handle, queries: [new ReactionQuery(key: 'protected', target: $s->reaction()->target)]);
        expect($batch['protected'])->toEqual($view);
        expect($s->counts())->toBe($before); // Revoke never fabricates membership removal.
    } finally {
        $s->cleanup();
    }
})->with(['hide', 'delete', 'hold', 'missing', 'incarnation', 'revoke', 'admission', 'admission-expiry', 'actor', 'other-installation', 'error', 'expiry', 'binding', 'installation', 'second-deny', 'generation']);

it('rechecks admission after projection SQL and never trusts cached totals', function (): void {
    $s = new ReactionScenario;
    try {
        expect($s->execute()->isSuccess())->toBeTrue();
        $s->connection()->listen(function (QueryExecuted $query) use ($s): void {
            if (str_contains($query->sql, '"driver_a_reaction_counts"')) {
                $s->fake->surfaces->deny(viewer: $s->handle->actor, surface: $s->handle->surface);
            }
        });
        $view = $s->reactions->summary(handle: $s->handle, target: $s->reaction()->target);
        expect($view->counts)->toBeNull()->and($view->ownKinds)->toBeNull()->and($view->problem)->toBe(ProblemCode::ContentHidden);
    } finally {
        $s->cleanup();
    }
});

it('revalidates early batch candidates after every later projection read', function (): void {
    $s = new ReactionScenario;
    try {
        expect($s->execute()->isSuccess())->toBeTrue();
        $later = $s->reaction(target: '43');
        expect($s->execute(command: $s->command(key: 'batch.later.0000001', reaction: $later), reaction: $later)->isSuccess())->toBeTrue();
        $laterDigest = $s->connection()->table('panel_a.a_reaction_counts')->where('target_key', '43')->value('identity_digest');
        expect($laterDigest)->toBeString();
        $fired = false;
        $s->connection()->listen(function (QueryExecuted $query) use ($s, &$fired, $laterDigest): void {
            if (! $fired && str_contains($query->sql, '"driver_a_reaction_counts"') && in_array($laterDigest, $query->bindings, true)) {
                $fired = true;
                $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['visible' => false]);
            }
        });
        $batch = $s->reactions->summaryMany(handle: $s->handle, queries: [new ReactionQuery(key: 'early', target: $s->reaction()->target), new ReactionQuery(key: 'later', target: $s->reaction(target: '43')->target)]);
        expect($fired)->toBeTrue()->and($batch['early']->counts)->toBeNull()->and($batch['early']->ownKinds)->toBeNull();
        expect($batch['early'])->toEqual($s->reactions->summary(handle: $s->handle, target: $s->reaction()->target));
        expect($batch['later']->counts)->toBe(['like' => 1, 'thanks' => 0]);
    } finally {
        $s->cleanup();
    }
});

it('supports current read only and archived reads without widening write permission', function (string $mode): void {
    $s = new ReactionScenario;
    try {
        expect($s->execute()->isSuccess())->toBeTrue();
        if ($mode === 'archive') {
            $s->connection()->table('panel_a.a_surface_instances')->update(['lifecycle' => 'archived']);
        } else {
            $s->fake->surfaces->allowReadOnly(viewer: $s->handle->actor, surface: $s->handle->surface);
        }
        $view = $s->reactions->summary(handle: $s->handle, target: $s->reaction()->target);
        expect($view->problem)->toBeNull()->and($view->counts)->toBe(['like' => 1, 'thanks' => 0])->and($view->ownKinds)->toBe(['like']);
        expect($s->execute($s->command(key: 'reaction.readonly.001'))->isSuccess())->toBeFalse();
    } finally {
        $s->cleanup();
    }
})->with(['archive', 'read-only']);

it('rejects duplicate or missing batch keys before any SQL and fails closed on disabled modules', function (): void {
    $s = new ReactionScenario(feature: false);
    try {
        expect(fn () => new ReactionQuery(key: '', target: $s->reaction()->target))->toThrow(InvalidPayloadException::class);
        $query = new ReactionQuery(key: 'duplicate', target: $s->reaction()->target);
        $before = count($s->db->queries);
        expect(fn () => $s->reactions->summaryMany(handle: $s->handle, queries: [$query, $query]))->toThrow(InvalidPayloadException::class);
        expect(count($s->db->queries))->toBe($before);
        expect($s->reactions->summary(handle: $s->handle, target: $s->reaction()->target)->counts)->toBeNull();
    } finally {
        $s->cleanup();
    }
});
