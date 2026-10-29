<?php

declare(strict_types=1);

namespace Acme\Groups;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Groups\Contracts\MembershipConsent;
use SocKit\Groups\Data\ConsentDecision;
use SocKit\Groups\Data\MembershipActivation;
use SocKit\Operations\Data\OperationHandle;

/** Host-owned local consent fence, using only this consumer's own authority row. */
final readonly class Consent implements MembershipConsent
{
    public function __construct(private DatabaseManager $db) {}

    private function connection(OperationHandle $handle): Connection
    {
        return $this->db->connection($handle->panel->storage->connection);
    }

    public function assess(OperationHandle $handle, MembershipActivation $activation): ConsentDecision
    {
        $connection = $this->connection($handle);
        if ($connection->transactionLevel() !== 0 || $handle->actor->actor === null || ! $activation->subject->equals($handle->actor->actor)) {
            throw new \RuntimeException('Unproven subject or consent assessment under lock');
        }
        $row = $connection->table('public.envelope_authority')->first();
        if ($row === null || $row->allowed !== true || ! is_int($row->generation)) {
            throw new \RuntimeException('Unknown current consent');
        }

        return new ConsentDecision(AuthorizationDecision::allow('host.current_consent'), (string) $row->generation);
    }

    public function recheck(OperationHandle $handle, MembershipActivation $activation, ConsentDecision $captured): AuthorizationDecision
    {
        $connection = $this->connection($handle);
        if ($connection->transactionLevel() !== 1) {
            return AuthorizationDecision::unknown('host.fence_unproven');
        }
        $row = $connection->table('public.envelope_authority')->lockForUpdate()->first();

        return $row !== null && $row->allowed === true && is_int($row->generation) && (string) $row->generation === $captured->generation
            ? AuthorizationDecision::allow('host.fenced') : AuthorizationDecision::deny('host.consent_changed');
    }
}
