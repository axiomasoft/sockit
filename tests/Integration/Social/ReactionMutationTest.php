<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OperationHandles;
use SocKit\Panels\PanelDefinitions;
use SocKit\Reactions\Data\ReactionCommand;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Social\ReactionScenario;
use SocKit\Tests\Integration\Support\PgTarget;

it('adds removes and readds one Article membership with CAS and deterministic fresh-key conflicts without posts', function (): void {
    $s = new ReactionScenario;
    try {
        $add = $s->execute();
        expect($add->isSuccess())->toBeTrue()->and($add->revision?->value)->toBe(1);
        expect($s->counts())->toBe(['memberships' => 1, 'active' => 1, 'revision' => 1, 'count' => 1, 'counter_revision' => 1, 'receipts' => 1, 'outbox' => 1]);
        $same = $s->execute();
        expect($same)->toEqual($add);
        $fresh = $s->execute($s->command(key: 'reaction.freshkey.001', expected: new Revision(1)));
        expect($fresh->problem)->toBe(ProblemCode::RevisionConflict);
        $removeCommand = $s->command(action: 'remove', expected: new Revision(1));
        $remove = $s->execute(command: $removeCommand, action: 'remove');
        expect($remove->isSuccess())->toBeTrue()->and($remove->resource)->toEqual($add->resource)->and($remove->revision?->value)->toBe(2);
        expect($s->execute(command: $s->command(action: 'remove', expected: new Revision(2), key: 'reaction.inactive.001'), action: 'remove')->problem)->toBe(ProblemCode::RevisionConflict);
        expect($s->execute($s->command(expected: new Revision(1), key: 'reaction.stalecas.001'))->problem)->toBe(ProblemCode::RevisionConflict);
        expect($s->execute($s->command(key: 'reaction.missingcas.1'))->problem)->toBe(ProblemCode::PayloadInvalid);
        $again = $s->execute($s->command(expected: new Revision(2), key: 'reaction.readded.0001'));
        expect($again->isSuccess())->toBeTrue()->and($again->resource)->toEqual($add->resource)->and($again->revision?->value)->toBe(3);
        expect($s->counts())->toBe(['memberships' => 1, 'active' => 1, 'revision' => 3, 'count' => 1, 'counter_revision' => 3, 'receipts' => 6, 'outbox' => 3]);
        expect(PgTarget::query($s->db->owner, "SELECT count(*) FROM pg_tables WHERE schemaname IN ('panel_a','panel_b') AND tablename LIKE '%posts%'")->fetchColumn())->toBe(0);
        expect($s->target->levels)->not->toBe([]);
        foreach ($s->target->levels as $level) {
            expect($level)->toBe(0);
        }
        foreach ($s->authority->levels as $level) {
            expect($level)->toBe(0);
        }
        expect($s->fence->captures)->not->toBe([])->and($s->fence->checks)->not->toBe([]);
        foreach ($s->fence->checks as $level) {
            expect($level)->toBe(1);
        }
        $event = $s->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'reactions.%'")->first();
        expect($event)->not->toBeNull();
    } finally {
        $s->cleanup();
    }
});

it('guards original-key replay lookup digest and exact subject binding', function (): void {
    $s = new ReactionScenario;
    try {
        $command = $s->command();
        $original = $s->execute($command);
        expect($original->isSuccess())->toBeTrue();
        expect($s->reactions->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key))->toEqual($original)
            ->and($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key))->toEqual($original);
        $different = $s->reaction(kind: 'thanks');
        expect($s->execute(command: $s->command(reaction: $different), reaction: $different)->problem)->toBe(ProblemCode::IdempotencyConflict);
        $other = $s->reaction(target: '43');
        expect($s->execute(command: $command, reaction: $other)->problem)->toBe(ProblemCode::PayloadInvalid);
        expect($s->execute(command: $s->command(reaction: $other), reaction: $other)->problem)->toBe(ProblemCode::IdempotencyConflict);
        $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['generation' => '2']);
        foreach (['facade', 'generic', 'execute'] as $entry) {
            $result = match ($entry) {
                'facade' => $s->reactions->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key),
                'generic' => $s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key),
                default => $s->execute($command),
            };
            expect($result?->isSuccess())->toBeFalse()->and($result?->resource)->toBeNull()->and($result?->problem)->toBe($entry === 'execute' ? ProblemCode::IdempotencyConflict : ProblemCode::AuthorizationUnknown);
        }
        expect($s->counts()['outbox'])->toBe(1);
    } finally {
        $s->cleanup();
    }
});

it('denies stale handles and forged namespace actor installation panel and surface before effects', function (string $mode): void {
    $s = new ReactionScenario;
    try {
        $command = $s->command();
        if (in_array($mode, ['actor', 'installation', 'actor-generation'], true)) {
            $actor = RuntimeScenario::actor(actor: $mode === 'actor' ? '8' : '7', installation: $mode === 'installation' ? 'foreign' : 'runtime-test', generation: $mode === 'actor-generation' ? '2' : '1');
            $s->handle = new OperationHandle(surface: $s->handle->surface, actor: $actor, panel: $s->handle->panel, definition: $s->handle->definition);
        } elseif ($mode === 'namespace') {
            $command = new CommandEnvelope(key: $command->key, namespace: new CommandNamespace(installation: $command->namespace->installation, panel: RuntimeScenario::surface('acme.b')->panel, integration: 'host.client', commandType: 'reactions.add'), digest: $command->digest);
        } elseif ($mode === 'panel') {
            $s->handle = new OperationHandle(surface: $s->handle->surface, actor: $s->handle->actor, panel: $s->app->make(PanelDefinitions::class)->get(RuntimeScenario::surface('acme.b')->panel), definition: $s->handle->definition);
        }
        $reaction = $mode === 'surface' ? new ReactionCommand(surface: RuntimeScenario::surface('acme.b'), target: $s->reaction()->target) : $s->reaction();
        $result = $s->execute(command: $command, reaction: $reaction);
        expect($result->isSuccess())->toBeFalse()->and($result->resource)->toBeNull();
        expect($s->counts()['memberships'])->toBe(0)->and($s->counts()['outbox'])->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with(['actor', 'installation', 'actor-generation', 'namespace', 'panel', 'surface']);

it('denies nonwritable unproven hidden unknown or recycled targets and current audience failures', function (string $mode): void {
    $s = new ReactionScenario;
    try {
        $command = $s->command();
        switch ($mode) {
            case 'read-only': $s->fake->surfaces->allowReadOnly(viewer: $s->handle->actor, surface: $s->handle->surface);
                break;
            case 'archive': $s->connection()->table('panel_a.a_surface_instances')->update(['lifecycle' => 'archived']);
                break;
            case 'authority': $s->connection()->table('panel_a.envelope_authority')->update(['allowed' => false]);
                break;
            case 'stale-fence': $s->target->changeAfterResolve = true;
                break;
            case 'unknown-fence': $s->target->unproven = true;
                break;
            case 'unknown-admission': $s->fake->surfaces->deny(viewer: $s->handle->actor, surface: $s->handle->surface);
                break;
            case 'surface-generation': $s->target->changeSurfaceAfterResolve = true;
                break;
            case 'remote-only':
                $remote = new class($s->target) implements TargetResolver
                {
                    public function __construct(private readonly TargetResolver $delegate) {}

                    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
                    {
                        return $this->delegate->resolve(actor: $actor, surface: $surface, target: $target);
                    }
                };
                $s->app->instance(TargetResolver::class, $remote);
                break;
            default:
                $changes = match ($mode) {
                    'hidden' => ['visible' => false], 'held' => ['held' => true], 'recycled' => ['incarnation' => 2], 'target-readonly' => ['lifecycle' => 'read_only'], 'deleted' => ['lifecycle' => 'deleted'], default => ['lifecycle' => 'unknown']
                };
                $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update($changes);
        }
        $result = $s->execute($command);
        expect($result->isSuccess())->toBeFalse()->and($result->resource)->toBeNull()->and($result->problem)->not->toBeNull();
        expect($s->counts()['memberships'])->toBe(0)->and($s->counts()['outbox'])->toBe(0);
        expect($s->connection()->transactionLevel())->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with(['read-only', 'archive', 'authority', 'stale-fence', 'unknown-fence', 'unknown-admission', 'surface-generation', 'remote-only', 'hidden', 'held', 'recycled', 'target-readonly', 'deleted', 'unknown']);

it('rejects disabled feature module kind and absent remove without owner state writes', function (string $mode): void {
    $s = new ReactionScenario(feature: $mode !== 'feature', module: $mode !== 'module');
    try {
        // Obtain a valid envelope on an enabled B panel; malformed input never becomes a receipt claim.
        $command = new CommandEnvelope(key: new CommandKey('reaction.invalid.001'), namespace: new CommandNamespace(installation: $s->handle->actor->installation, panel: $s->handle->surface->panel, integration: 'host.client', commandType: 'reactions.add'), digest: PayloadDigest::of(commandType: 'reactions.add', payload: []));
        $reaction = $s->reaction(kind: $mode === 'kind' ? 'unknown' : 'like');
        if ($mode === 'absent-remove') {
            $result = $s->execute(command: $s->command(action: 'remove'), action: 'remove');
            expect($result->problem)->toBe(ProblemCode::RevisionConflict);
        } else {
            $result = $s->execute(command: $command, reaction: $reaction);
            expect($result->problem)->toBe($mode === 'kind' ? ProblemCode::PayloadInvalid : ProblemCode::ModuleInvalid);
        }
        expect($result->resource)->toBeNull();
        if (in_array($mode, ['feature', 'module'], true)) {
            expect($s->reactions->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key)?->problem)->toBe(ProblemCode::ModuleInvalid);
        }
        if ($mode !== 'module') {
            expect($s->counts()['memberships'])->toBe(0);
        }
        expect($s->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'reactions.%'")->count())->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with(['feature', 'module', 'kind', 'absent-remove']);

it('rolls back membership counter receipt and outbox at each real SQL stage', function (string $stage): void {
    $s = new ReactionScenario;
    try {
        $before = $s->counts();
        $fired = false;
        $s->connection()->listen(function (QueryExecuted $query) use ($stage, &$fired): void {
            $sql = strtolower($query->sql);
            $table = match ($stage) {
                'membership' => '"driver_a_reactions"', 'counter' => '"driver_a_reaction_counts"', 'receipt' => '"driver_a_receipts"', default => '"driver_a_outbox"'
            };
            if (! $fired && str_contains($sql, $table) && (str_starts_with($sql, 'insert') || ($stage === 'receipt' && str_starts_with($sql, 'update')))) {
                if ($stage === 'receipt' && ! str_starts_with($sql, 'update')) {
                    return;
                }
                $fired = true;
                throw new RuntimeException('injected after '.$stage);
            }
        });
        $result = $s->execute();
        expect($fired)->toBeTrue()->and($result->isSuccess())->toBeFalse()->and($result->problem)->toBe(ProblemCode::ProviderUnavailable);
        expect($s->counts())->toBe($before)->and($s->connection()->transactionLevel())->toBe(0);
        expect($s->execute()->isSuccess())->toBeTrue(); // same key usable after rollback; no aborted tx reuse.
    } finally {
        $s->cleanup();
    }
})->with(['membership', 'counter', 'receipt', 'outbox']);

it('uses unique lossless trusted actor identities with one shared counter', function (): void {
    $s = new ReactionScenario;
    try {
        $a = $s->execute();
        expect($a->isSuccess())->toBeTrue();
        $other = RuntimeScenario::actor(actor: '0007');
        $s->fake->actors->actingAs($other);
        $s->fake->surfaces->allow(viewer: $other, surface: $s->handle->surface);
        $s->handle = $s->app->make(OperationHandles::class)->forSurface($s->handle->surface);
        $b = $s->execute($s->command(key: 'reaction.actor.0007'));
        expect($b->isSuccess())->toBeTrue()->and($b->resource)->not->toEqual($a->resource);
        expect($s->counts())->toBe(['memberships' => 2, 'active' => 2, 'revision' => 1, 'count' => 2, 'counter_revision' => 2, 'receipts' => 2, 'outbox' => 2]);
        expect($s->execute(command: $s->command(action: 'remove', expected: new Revision(1), key: 'reaction.actor.remove'), action: 'remove')->isSuccess())->toBeTrue();
        expect($s->counts())->toBe(['memberships' => 2, 'active' => 1, 'revision' => 2, 'count' => 1, 'counter_revision' => 3, 'receipts' => 3, 'outbox' => 3]);
        expect($s->connection()->table('panel_a.a_reactions')->where('id', $a->resource?->id)->value('active'))->toBeTrue();
    } finally {
        $s->cleanup();
    }
});

it('rechecks current guards before exposing a receipt through facade or generic lookup', function (string $mode): void {
    $s = new ReactionScenario;
    try {
        $command = $s->command();
        expect($s->execute($command)->isSuccess())->toBeTrue();
        switch ($mode) {
            case 'revoked': $s->connection()->table('panel_a.envelope_authority')->update(['allowed' => false, 'generation' => 2]);
                break;
            case 'hidden': $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['visible' => false]);
                break;
            case 'recycled': $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['incarnation' => 2]);
                break;
            case 'privacy': $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['privacy' => 2]);
                break;
            case 'admission': $s->fake->surfaces->deny(viewer: $s->handle->actor, surface: $s->handle->surface);
                break;
            case 'actor-generation': $s->fake->actors->actingAs(RuntimeScenario::actor(generation: '2'));
                break;
            case 'other-actor':
                $actor = RuntimeScenario::actor(actor: '8');
                $s->fake->actors->actingAs($actor);
                $s->fake->surfaces->allow(viewer: $actor, surface: $s->handle->surface);
                $s->handle = $s->app->make(OperationHandles::class)->forSurface($s->handle->surface);
                break;
        }
        foreach ([$s->reactions, $s->operations] as $api) {
            $result = $api->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key);
            expect($result?->isSuccess())->toBeFalse()->and($result?->resource)->toBeNull()->and($result?->revision)->toBeNull()->and($result?->problem)->not->toBeNull();
        }
        expect($s->counts())->toBe(['memberships' => 1, 'active' => 1, 'revision' => 1, 'count' => 1, 'counter_revision' => 1, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
})->with(['revoked', 'hidden', 'recycled', 'privacy', 'admission', 'actor-generation', 'other-actor']);

it('rejects anonymous context before membership or receipt writes', function (): void {
    $s = new ReactionScenario;
    try {
        $command = $s->command();
        $anonymous = new ActorContext(actor: null, kind: ActorKind::Anonymous, installation: $s->handle->actor->installation,
            ageBand: AgeBand::Unknown, provenance: ContextProvenance::Direct, generation: 'anonymous');
        $s->fake->actors->actingAs($anonymous);
        $s->handle = $s->app->make(OperationHandles::class)->forSurface($s->handle->surface);
        expect($s->execute($command)->problem)->toBe(ProblemCode::AuthenticationRequired);
        expect($s->counts())->toBe(['memberships' => 0, 'active' => 0, 'revision' => 0, 'count' => 0, 'counter_revision' => 0, 'receipts' => 0, 'outbox' => 0]);
    } finally {
        $s->cleanup();
    }
});
