<?php

declare(strict_types=1);

namespace Acme\Groups;

use Illuminate\Database\DatabaseManager;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Groups\Contracts\MembershipConsent;
use SocKit\Groups\Data\ConsentDecision;
use SocKit\Groups\Data\MembershipActivation;
use SocKit\Operations\Data\OperationHandle;

final class SubjectConsent implements MembershipConsent
{
    public string $mode = 'allow';

    public function __construct(private readonly DatabaseManager $db) {}

    public function assess(OperationHandle $handle, MembershipActivation $activation): ConsentDecision
    {
        $connection = $this->db->connection('owner_a');
        if ($connection->transactionLevel() !== 0) {
            throw new \RuntimeException('Consent assessed under lock');
        }
        $decision = match ($this->mode) {
            'deny' => AuthorizationDecision::deny('host.denied'),
            'unknown' => AuthorizationDecision::unknown('host.unknown'),
            default => AuthorizationDecision::allow('host.consent'),
        };

        $generation = $connection->table('public.envelope_authority')->value('generation');
        if (! is_int($generation)) {
            throw new \RuntimeException('Missing consent generation');
        }

        return new ConsentDecision($decision, (string) $generation);
    }

    public function recheck(OperationHandle $handle, MembershipActivation $activation, ConsentDecision $captured): AuthorizationDecision
    {
        $connection = $this->db->connection('owner_a');
        $row = $connection->table('public.envelope_authority')->lockForUpdate()->first();

        return $connection->transactionLevel() === 1 && $row !== null && $row->allowed === true && is_int($row->generation) && (string) $row->generation === $captured->generation
            ? AuthorizationDecision::allow('host.consent_fenced') : AuthorizationDecision::unknown('host.stale');
    }
}
