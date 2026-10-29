<?php

declare(strict_types=1);

namespace Acme\Runtime;

use Illuminate\Database\DatabaseManager;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\OperationHandle;

final readonly class AuthorityRouter implements ActionAuthorizer, MutationAuthorityFence
{
    public function __construct(private DatabaseManager $db) {}

    private function fence(string $panel): EnvelopeAuthorityFence
    {
        $b = $panel === 'acme.product';

        return new EnvelopeAuthorityFence(connection: $this->db->connection($b ? 'owner_b' : 'owner_a'), schema: $b ? 'public' : 'public');
    }

    public function decide(ActionRequest $request): AuthorizationDecision
    {
        $b = $request->surface->panel->value === 'acme.product';

        return new EnvelopeAuthority(connection: $this->db->connection($b ? 'owner_b' : 'owner_a'), schema: $b ? 'public' : 'public')->decide($request);
    }

    public function capture(OperationHandle $handle, MutationRequest $request): string
    {
        return $this->fence($handle->surface->panel->value)->capture(handle: $handle, request: $request);
    }

    public function recheck(OperationHandle $handle, MutationRequest $request, string $generation): AuthorizationDecision
    {
        return $this->fence($handle->surface->panel->value)->recheck(handle: $handle, request: $request, generation: $generation);
    }
}
