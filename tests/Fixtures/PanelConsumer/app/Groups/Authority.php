<?php

declare(strict_types=1);

namespace Acme\Groups;

use Acme\Runtime\EnvelopeAuthority;
use Acme\Runtime\EnvelopeAuthorityFence;
use Illuminate\Database\DatabaseManager;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\OperationHandle;

final readonly class Authority implements ActionAuthorizer, MutationAuthorityFence
{
    public function __construct(private DatabaseManager $db) {}

    public function decide(ActionRequest $request): AuthorizationDecision
    {
        return new EnvelopeAuthority($this->db->connection('owner_a'))->decide($request);
    }

    public function capture(OperationHandle $handle, MutationRequest $request): string
    {
        return new EnvelopeAuthorityFence($this->db->connection('owner_a'))->capture($handle, $request);
    }

    public function recheck(OperationHandle $handle, MutationRequest $request, string $generation): AuthorizationDecision
    {
        return new EnvelopeAuthorityFence($this->db->connection('owner_a'))->recheck($handle, $request, $generation);
    }
}
