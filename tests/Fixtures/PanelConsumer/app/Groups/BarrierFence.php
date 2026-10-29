<?php

declare(strict_types=1);

namespace Acme\Groups;

use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\OperationHandle;

final class BarrierFence implements MutationAuthorityFence
{
    public ?\Closure $barrier = null;

    public function __construct(private readonly MutationAuthorityFence $host, private readonly string $action) {}

    public function capture(OperationHandle $handle, MutationRequest $request): string
    {
        return $this->host->capture($handle, $request);
    }

    public function recheck(OperationHandle $handle, MutationRequest $request, string $generation): AuthorizationDecision
    {
        if ($request->action === $this->action && $this->barrier !== null) {
            $barrier = $this->barrier;
            $this->barrier = null;
            $barrier();
        }

        return $this->host->recheck($handle, $request, $generation);
    }
}
