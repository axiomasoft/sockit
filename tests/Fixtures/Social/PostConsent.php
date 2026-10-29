<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use Illuminate\Database\Connection;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\OperationHandle;

/** Host-owned consent fixture: revoke denies reads now, while a separately authorized hide may retry. */
final class PostConsent implements ActionAuthorizer, MutationAuthorityFence
{
    /** @var list<string> */
    public array $denied = [];

    /** @var list<int> */
    public array $levels = [];

    public function __construct(private readonly Connection $connection) {}

    public function decide(ActionRequest $request): AuthorizationDecision
    {
        $this->levels[] = $this->connection->transactionLevel();
        if ($this->connection->transactionLevel() !== 0) {
            throw new \LogicException('Host consent resolve under lock');
        }
        $allowed = $this->connection->table('panel_a.envelope_authority')->value('allowed');

        return $this->decision($request->action, $allowed === true);
    }

    public function capture(OperationHandle $handle, MutationRequest $request): string
    {
        if ($this->connection->transactionLevel() !== 0) {
            throw new \LogicException('Host consent capture under lock');
        }

        $value = $this->connection->table('panel_a.envelope_authority')->value('generation');
        if (! is_int($value) || $value < 1) {
            throw new \RuntimeException('Missing consent generation');
        }

        return (string) $value;
    }

    public function recheck(OperationHandle $handle, MutationRequest $request, string $generation): AuthorizationDecision
    {
        if ($this->connection->transactionLevel() !== 1) {
            return AuthorizationDecision::unknown('fixture.unproven');
        }
        $row = $this->connection->table('panel_a.envelope_authority')->lockForUpdate()->first();
        if ($row === null || ! is_int($row->generation) || (string) $row->generation !== $generation) {
            return AuthorizationDecision::unknown('fixture.consent_changed');
        }

        return $this->decision($request->action, $row->allowed === true);
    }

    private function decision(string $action, bool $consent): AuthorizationDecision
    {
        return ! in_array($action, $this->denied, true) && ($consent || $action === 'posts.hide' || $action === 'sockit.surfaces.get')
            ? AuthorizationDecision::allow('fixture.current_host_permission') : AuthorizationDecision::deny('fixture.host_consent_revoked');
    }
}
