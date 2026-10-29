<?php

declare(strict_types=1);

namespace SocKit\Operations;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Attributes\Api;
use SocKit\Configuration\SocKitConfig;
use SocKit\Errors\Contracts\ProvidesProblem;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Contracts\OwnerMutation;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationJobReference;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Internal\Envelope\Envelope;
use SocKit\Operations\Internal\Envelope\EnvelopeException;
use SocKit\Operations\Internal\Envelope\WorkerRuntime;
use SocKit\Support\Data\CommandNamespace;

/** Host activates isolation first. Queue metadata never switches a database or supplies authority. */
#[Api(since: '1.0')]
final readonly class OperationJobs
{
    public function __construct(private Application $app) {}

    /** Factory is trusted local code, invoked with freshly resolved worker facts outside transactions.
     * @param callable(OperationHandle): OwnerMutation $factory */
    public function execute(OperationJobReference $job, callable $factory): OperationResult
    {
        try {
            $connections = $this->app->make(ConnectionResolverInterface::class);
            if (array_any($connections->getConnections(), static fn ($connection): bool => $connection->transactionLevel() > 0)) {
                throw new EnvelopeException(ProblemCode::ConfigurationInvalid);
            }
            if (! $this->app->make(TenantResolver::class)->current()->equals($job->command->namespace->installation)
                || $this->app->make(SocKitConfig::class)->events->sourceGeneration !== $job->sourceGeneration) {
                throw new EnvelopeException(ProblemCode::ActorContextInvalid);
            }
            $runtime = WorkerRuntime::create(host: $this->app, actor: $job->actor);
            $handle = $runtime->make(OperationHandles::class)->forSurface($job->surface);
            $namespace = new CommandNamespace(installation: $handle->actor->installation, panel: $handle->surface->panel,
                integration: $job->command->namespace->integration, commandType: $job->command->namespace->commandType);
            if (! $namespace->equals($job->command->namespace)) {
                throw new EnvelopeException(ProblemCode::ActorContextInvalid);
            }
            $mutation = $factory($handle);
            if ($mutation->commandType() !== $namespace->commandType || ! ($mutation->request($handle)->target?->equals($job->target) ?? false)) {
                throw new EnvelopeException(ProblemCode::PayloadInvalid);
            }

            return new Envelope($runtime)->execute(handle: $handle, command: $job->command, mutation: $mutation);
        } catch (\Throwable $error) {
            return OperationResult::rejected($error instanceof ProvidesProblem ? $error->problem() : ProblemCode::ProviderUnavailable);
        }
        // All worker state is local; success/throw/nesting require no reset of a caller or Fiber frame.
    }
}
