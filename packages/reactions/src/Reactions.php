<?php

declare(strict_types=1);

namespace SocKit\Reactions;

use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Api;
use SocKit\Errors\Contracts\ProvidesProblem;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\Operations;
use SocKit\Reactions\Data\ReactionCommand;
use SocKit\Reactions\Data\ReactionQuery;
use SocKit\Reactions\Data\ReactionSummary;
use SocKit\Reactions\Internal\ReactionMutation;
use SocKit\Reactions\Internal\ReactionProjection;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\Revision;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\TargetRef;

/** add/remove return immutable kernel results. First add: expectedRevision=null, revision=1.
 * Existing membership: expected revision required, remove/re-add retain identity and advance revision.
 * A fresh key requesting an already active/inactive state returns revision_conflict without effects.
 * Lost response: reuse the original envelope or lookup its original key; never invent a new key.
 */
#[Api(since: '1.0')]
final readonly class Reactions
{
    public function __construct(private Application $app, private Operations $operations) {}

    public function summary(OperationHandle $handle, TargetRef $target): ReactionSummary
    {
        return new ReactionProjection($this->app)->summary(handle: $handle, target: $target);
    }

    /** Scalar guards run for every entry, without an authorization cache or a batch resolver shortcut.
     * @param list<ReactionQuery> $queries
     * @return array<string, ReactionSummary> */
    public function summaryMany(OperationHandle $handle, array $queries): array
    {
        $keys = [];
        foreach ($queries as $query) {
            if (array_key_exists($query->key, $keys)) {
                throw new InvalidPayloadException('Duplicate reaction batch key');
            }
            $keys[$query->key] = true;
        }

        return new ReactionProjection($this->app)->summaryMany(handle: $handle, queries: $queries);
    }

    /** Explicit host ActionAuthorizer permission reactions.rebuild and local authority/target fences required.
     * Only counters change; no receipts, historical events, relay, notifications or delivery state.
     * Throws on denial, interruption or an unproven fence. */
    public function rebuild(OperationHandle $handle, TargetRef $target, ReplayMode $mode = ReplayMode::ProjectionOnly): void
    {
        new ReactionProjection($this->app)->rebuild(handle: $handle, target: $target, mode: $mode);
    }

    /** Rebuild each distinct stored target under current per-target guards. */
    public function rebuildForSurface(OperationHandle $handle): int
    {
        return new ReactionProjection($this->app)->rebuildForSurface($handle);
    }

    public function digest(OperationHandle $handle, ReactionCommand $reaction, string $action, ?Revision $expectedRevision = null): PayloadDigest
    {
        return $this->operations->digest(handle: $handle, mutation: new ReactionMutation(app: $this->app, reaction: $reaction, action: $action), expectedRevision: $expectedRevision);
    }

    public function add(OperationHandle $handle, ReactionCommand $reaction, CommandEnvelope $command): OperationResult
    {
        return $this->execute(handle: $handle, reaction: $reaction, command: $command, action: 'add');
    }

    public function remove(OperationHandle $handle, ReactionCommand $reaction, CommandEnvelope $command): OperationResult
    {
        return $this->execute(handle: $handle, reaction: $reaction, command: $command, action: 'remove');
    }

    /** Lookup revalidates current authority/target through the same kernel read guards. */
    public function lookup(OperationHandle $handle, CommandNamespace $namespace, CommandKey $key): ?OperationResult
    {
        try {
            $this->app->make(OperationHandles::class)->assertCurrent(handle: $handle, namespace: $namespace);
            if (! in_array('sockit.reactions', $handle->panel->modules, true) || ! in_array('reactions', $handle->definition->features, true)) {
                return OperationResult::rejected(ProblemCode::ModuleInvalid);
            }
            if (! in_array($namespace->commandType, ['reactions.add', 'reactions.remove'], true)) {
                return OperationResult::rejected(ProblemCode::PayloadInvalid);
            }

            return $this->operations->lookup(handle: $handle, namespace: $namespace, key: $key);
        } catch (\Throwable $error) {
            return OperationResult::rejected($error instanceof ProvidesProblem ? $error->problem() : ProblemCode::ProviderUnavailable);
        }
    }

    private function execute(OperationHandle $handle, ReactionCommand $reaction, CommandEnvelope $command, string $action): OperationResult
    {
        try {
            return $this->operations->execute(handle: $handle, command: $command, mutation: new ReactionMutation(app: $this->app, reaction: $reaction, action: $action));
        } catch (\Throwable $error) {
            return OperationResult::rejected($error instanceof ProvidesProblem ? $error->problem() : ProblemCode::ProviderUnavailable);
        }
    }
}
