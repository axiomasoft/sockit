<?php

declare(strict_types=1);

namespace Acme\Runtime;

use Illuminate\Database\Connection;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Support\CanonicalJson;

/** Test-only local authority row governs the changing grants in this fixture.
 * Its revoker updates this same row/version. Production hosts supply their own composite fence.
 */
final class EnvelopeAuthorityFence implements MutationAuthorityFence
{
    /** @var list<int> */
    public array $captures = [];

    /** @var list<int> */
    public array $checks = [];

    public bool $unproven = false;

    public function __construct(private readonly Connection $connection, private readonly string $schema = 'public') {}

    public function capture(OperationHandle $handle, MutationRequest $request): string
    {
        $this->captures[] = $this->connection->transactionLevel();
        if ($this->connection->transactionLevel() !== 0) {
            throw new \LogicException('Authority capture under owner lock');
        }
        $generation = $this->connection->table($this->schema.'.envelope_authority')->value('generation');
        if (! is_int($generation)) {
            throw new \RuntimeException('Authority generation missing');
        }

        return $this->token(handle: $handle, request: $request, generation: $generation);
    }

    public function recheck(OperationHandle $handle, MutationRequest $request, string $generation): AuthorizationDecision
    {
        $this->checks[] = $this->connection->transactionLevel();
        if ($this->unproven || $this->connection->transactionLevel() !== 1) {
            return AuthorizationDecision::unknown('fixture.authority_unproven');
        }
        $row = $this->connection->table($this->schema.'.envelope_authority')->lockForUpdate()->first();
        if ($row === null || ! is_int($row->generation)) {
            return AuthorizationDecision::unknown('fixture.authority_missing');
        }
        if ($row->allowed !== true) {
            return AuthorizationDecision::deny('fixture.revoked');
        }
        if ($this->token(handle: $handle, request: $request, generation: $row->generation) !== $generation) {
            return AuthorizationDecision::unknown('fixture.authority_stale');
        }

        return AuthorizationDecision::allow('fixture.authority_fence_held');
    }

    private function token(OperationHandle $handle, MutationRequest $request, int $generation): string
    {
        return hash('sha256', CanonicalJson::encode(['installation' => $handle->actor->installation->id, 'actor' => $handle->actor->actor?->toWire(),
            'actor_generation' => $handle->actor->generation, 'surface' => $handle->surface->toWire(), 'action' => $request->action,
            'subject' => $request->subject->spec->toWire(), 'proposed' => $request->proposedAudience?->toWire(), 'target' => $request->target?->toWire(), 'generation' => $generation]));
    }
}
