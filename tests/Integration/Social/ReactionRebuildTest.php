<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Operations\OperationHandles;
use SocKit\Support\Data\Revision;
use SocKit\Support\Ids;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Social\ProjectionRace;
use SocKit\Tests\Fixtures\Social\ReactionScenario;

it('repairs exact active membership aggregates and leaves all historical state byte identical', function (): void {
    $s = new ReactionScenario;
    try {
        expect($s->execute()->isSuccess())->toBeTrue();
        $thanks = $s->reaction('thanks');
        expect($s->execute(command: $s->command(key: 'rebuild.thanks.00001', reaction: $thanks), reaction: $thanks)->isSuccess())->toBeTrue();
        $original = $s->handle;
        $actor = RuntimeScenario::actor(actor: '0007');
        $s->fake->actors->actingAs($actor);
        $s->fake->surfaces->allow(viewer: $actor, surface: $s->handle->surface);
        $s->handle = $s->app->make(OperationHandles::class)->forSurface($s->handle->surface);
        expect($s->execute(command: $s->command(key: 'rebuild.thanks.00002', reaction: $thanks), reaction: $thanks)->isSuccess())->toBeTrue();
        $s->fake->surfaces->deny(viewer: $actor, surface: $s->handle->surface);
        $s->fake->actors->actingAs($original->actor);
        $s->handle = $original; // Revoked reactor remains authoritative membership; no fabricated remove.
        expect($s->execute(command: $s->command(action: 'remove', expected: new Revision(1), key: 'rebuild.remove.00001'), action: 'remove')->isSuccess())->toBeTrue();
        $other = $s->reaction(target: '43');
        expect($s->execute(command: $s->command(key: 'rebuild.other.000001', reaction: $other), reaction: $other)->isSuccess())->toBeTrue();
        $s->connection()->table('panel_a.a_reaction_counts')->where('target_key', '42')->where('reaction_kind', 'like')->update(['count' => 999]);
        $s->connection()->table('panel_a.a_reaction_counts')->where('target_key', '42')->where('reaction_kind', 'thanks')->delete();
        // A previous target incarnation is never part of the current projection boundary.
        $old = $s->connection()->table('panel_a.a_reaction_counts')->where('target_key', '43')->first();
        if ($old === null) {
            throw new RuntimeException('Missing source counter');
        }
        $s->connection()->table('panel_a.a_reaction_counts')->insert([...get_object_vars($old), 'id' => Ids::uuid7(), 'identity_digest' => str_repeat('a', 64), 'target_key' => '42', 'target_incarnation' => 2, 'count' => 777]);
        $oldMember = $s->connection()->table('panel_a.a_reactions')->where('target_key', '43')->first();
        if ($oldMember === null) {
            throw new RuntimeException('Missing source membership');
        }
        $s->connection()->table('panel_a.a_reactions')->insert([...get_object_vars($oldMember), 'id' => Ids::uuid7(), 'identity_digest' => str_repeat('b', 64), 'target_key' => '42', 'target_incarnation' => 2]);
        $s->connection()->table('panel_a.a_outbox')->update(['deliveries' => json_encode(['historical.notification' => ['state' => 'acked', 'attempts' => 3]], JSON_THROW_ON_ERROR)]);
        $historical = static function () use ($s): array {
            $snapshot = [];
            foreach (['a_reactions', 'a_receipts', 'a_outbox', 'a_tombstones', 'a_surface_instances', 'envelope_targets', 'envelope_authority'] as $table) {
                $snapshot[$table] = $s->connection()->table('panel_a.'.$table)->orderByRaw('1')->get()->toJson();
            }
            $snapshot['other_counts'] = $s->connection()->table('panel_a.a_reaction_counts')->where(fn (Builder $q): Builder => $q->where('target_key', '43')->orWhere('target_incarnation', 2))->orderBy('id')->get()->toJson();

            return $snapshot;
        };
        $before = $historical();
        foreach ([1, 2] as $run) {
            $s->reactions->rebuild(handle: $s->handle, target: $s->reaction()->target, mode: ReplayMode::ProjectionOnly);
            expect($historical())->toBe($before);
            $view = $s->reactions->summary(handle: $s->handle, target: $s->reaction()->target);
            expect($view->counts)->toBe(['like' => 0, 'thanks' => 2])->and($view->ownKinds)->toBe(['thanks']);
            expect($s->connection()->table('panel_a.a_reaction_counts')->where('target_key', '42')->where('target_incarnation', 1)->pluck('count', 'reaction_kind')->all())->toBe(['thanks' => 2]);
            $s->counts(); // B and decoy remain unchanged.
        }
        $s->reactions->rebuild(handle: $s->handle, target: $other->target);
        expect($s->reactions->summary(handle: $s->handle, target: $other->target)->counts)->toBe(['like' => 1, 'thanks' => 0]);
        fwrite(STDERR, "P4.3 repeat rebuild exact; membership/receipts/outbox/deliveries/targets/surfaces/tombstones unchanged\n");
    } finally {
        $s->cleanup();
    }
});

it('rolls back interrupted replacement before or after the new counters are written', function (string $stage): void {
    $s = new ReactionScenario;
    try {
        expect($s->execute()->isSuccess())->toBeTrue();
        $s->connection()->table('panel_a.a_reaction_counts')->update(['count' => 888]);
        $before = $s->connection()->table('panel_a.a_reaction_counts')->get()->toJson();
        $fired = false;
        $s->connection()->listen(function (QueryExecuted $query) use (&$fired, $stage): void {
            if (! $fired && str_starts_with($query->sql, $stage) && str_contains($query->sql, '"driver_a_reaction_counts"')) {
                $fired = true;
                throw new RuntimeException('interrupted projection');
            }
        });
        expect(fn () => $s->reactions->rebuild(handle: $s->handle, target: $s->reaction()->target))->toThrow(RuntimeException::class, 'interrupted projection');
        expect($fired)->toBeTrue()->and($s->connection()->transactionLevel())->toBe(0);
        expect($s->connection()->table('panel_a.a_reaction_counts')->get()->toJson())->toBe($before);
        $s->reactions->rebuild(handle: $s->handle, target: $s->reaction()->target);
        expect($s->reactions->summary(handle: $s->handle, target: $s->reaction()->target)->counts)->toBe(['like' => 1, 'thanks' => 0]);
    } finally {
        $s->cleanup();
    }
})->with(['delete', 'insert']);

it('serializes projection rebuild against real independent process add remove and crash', function (string $mode): void {
    $s = new ReactionScenario;
    try {
        if (str_ends_with($mode, 'remove')) {
            expect($s->execute()->isSuccess())->toBeTrue();
        }
        ProjectionRace::run(s: $s, mode: $mode);
        $state = $s->counts();
        expect($state['active'])->toBe(str_ends_with($mode, 'remove') ? 0 : 1)->and($state['count'])->toBe($state['active']);
        // Exactly the mutation's deliveries exist; rebuild never claims a historical event/receipt.
        expect($state['receipts'])->toBe(str_ends_with($mode, 'remove') ? 2 : 1)->and($state['outbox'])->toBe($state['receipts']);
        $s->reactions->rebuild(handle: $s->handle, target: $s->reaction()->target);
        $after = $s->counts();
        expect($after['count'])->toBe($state['active'])->and($after['receipts'])->toBe($state['receipts'])->and($after['outbox'])->toBe($state['outbox']);
    } finally {
        $s->cleanup();
    }
})->with(['rebuild-add', 'rebuild-remove', 'mutation-add', 'mutation-remove', 'rebuild-rollback', 'rebuild-kill']);

it('requires explicit trusted maintenance permission current fences and projection only mode', function (string $mode): void {
    $s = new ReactionScenario;
    try {
        expect($s->execute()->isSuccess())->toBeTrue();
        $before = $s->counts();
        switch ($mode) {
            case 'permission':
                $s->app->instance(ActionAuthorizer::class, new readonly class($s->authority) implements ActionAuthorizer
                {
                    public function __construct(private ActionAuthorizer $delegate) {}

                    public function decide(ActionRequest $request): AuthorizationDecision
                    {
                        return $request->action === 'reactions.rebuild' ? AuthorizationDecision::deny('fixture.maintenance_denied') : $this->delegate->decide($request);
                    }
                });
                break;
            case 'target': $s->target->unproven = true;
                break;
            case 'authority': $s->fence->unproven = true;
                break;
            case 'surface-generation': $s->target->changeSurfaceAfterResolve = true;
                break;
            case 'generation': $s->target->changeAfterResolve = true;
                break;
            case 'stale': $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['incarnation' => 2]);
                break;
            case 'archive': $s->connection()->table('panel_a.a_surface_instances')->update(['lifecycle' => 'archived']);
                break;
            case 'read-only': $s->fake->surfaces->allowReadOnly(viewer: $s->handle->actor, surface: $s->handle->surface);
                break;
        }
        expect(fn () => $s->reactions->rebuild(handle: $s->handle, target: $s->reaction()->target, mode: $mode === 'delivery' ? ReplayMode::Delivery : ReplayMode::ProjectionOnly))->toThrow(SocKitException::class);
        expect($s->counts())->toBe($before)->and($s->connection()->transactionLevel())->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with(['permission', 'target', 'authority', 'surface-generation', 'generation', 'stale', 'archive', 'read-only', 'delivery']);
