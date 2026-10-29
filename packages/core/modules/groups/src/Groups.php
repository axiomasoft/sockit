<?php

declare(strict_types=1);

namespace SocKit\Groups;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Attributes\Api;
use SocKit\Errors\Contracts\ProvidesProblem;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Groups\Data\GroupCommand;
use SocKit\Groups\Data\GroupView;
use SocKit\Groups\Internal\GroupMutation;
use SocKit\Groups\Internal\GroupStore;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Operations;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;

#[Api(since: '1.0')]
final readonly class Groups
{
    public function __construct(private Application $app, private Operations $operations) {}

    public function digest(OperationHandle $handle, GroupCommand $group, ?Revision $expectedRevision = null): PayloadDigest
    {
        return $this->operations->digest($handle, new GroupMutation($this->app, $group), $expectedRevision);
    }

    public function create(OperationHandle $handle, GroupCommand $group, CommandEnvelope $command): OperationResult
    {
        return $this->execute($handle, $group, $command, true);
    }

    public function setJoinPolicy(OperationHandle $handle, GroupCommand $group, CommandEnvelope $command): OperationResult
    {
        return $this->execute($handle, $group, $command, false);
    }

    public function read(OperationHandle $handle, ResourceRef $resource): GroupView
    {
        $store = new GroupStore($this->app);

        return $store->view($resource, $store->readable($handle, $resource));
    }

    public function lookup(OperationHandle $handle, CommandNamespace $namespace, CommandKey $key): ?OperationResult
    {
        try {
            new GroupStore($this->app)->outside($handle);
            if (! in_array($namespace->commandType, ['groups.create', 'groups.policy'], true)) {
                return OperationResult::rejected(ProblemCode::PayloadInvalid);
            }

            return $this->operations->lookup($handle, $namespace, $key);
        } catch (\Throwable $error) {
            return OperationResult::rejected($error instanceof ProvidesProblem ? $error->problem() : ProblemCode::ProviderUnavailable);
        }
    }

    private function execute(OperationHandle $handle, GroupCommand $group, CommandEnvelope $command, bool $create): OperationResult
    {
        if ($create !== ($group->resource === null) || $create !== ($command->expectedRevision === null)) {
            return OperationResult::rejected(ProblemCode::PayloadInvalid);
        }

        return $this->operations->execute($handle, $command, new GroupMutation($this->app, $group));
    }
}
