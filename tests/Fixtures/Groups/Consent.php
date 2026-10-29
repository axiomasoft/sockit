<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use Illuminate\Database\Connection;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Groups\Contracts\MembershipConsent;
use SocKit\Groups\Data\ConsentDecision;
use SocKit\Groups\Data\MembershipActivation;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Support\CanonicalJson;

/** Host-owned local consent with an actual PostgreSQL fence shared by its revoke writer. */
final class Consent implements MembershipConsent
{
    /** @var list<int> */
    public array $assessLevels = [];

    /** @var list<int> */
    public array $checkLevels = [];

    /** @var list<array{executor: string, subject: string, transition: string}> */
    public array $subjects = [];

    public string $mode = 'normal';

    public ?\Closure $afterAssess = null;

    public function __construct(private readonly Connection $connection) {}

    public function assess(OperationHandle $handle, MembershipActivation $activation): ConsentDecision
    {
        $this->assessLevels[] = $this->connection->transactionLevel();
        $this->subjects[] = ['executor' => $handle->actor->actor?->toWire() ?? '', 'subject' => $activation->subject->toWire(), 'transition' => $activation->transition];
        if ($this->connection->transactionLevel() !== 0 || $this->mode === 'error') {
            throw new \LogicException('Consent resolve failed or ran under locks');
        }
        $row = $this->row($handle, $activation);
        $decision = $this->decision($row);
        if ($this->mode === 'unknown') {
            $decision = AuthorizationDecision::unknown('fixture.unproven_subject');
        }
        $generation = $this->token($handle, $activation, $row);
        if ($this->afterAssess !== null) {
            ($this->afterAssess)();
        }

        return new ConsentDecision($decision, $this->mode === 'empty' ? '' : $generation);
    }

    public function recheck(OperationHandle $handle, MembershipActivation $activation, ConsentDecision $captured): AuthorizationDecision
    {
        $this->checkLevels[] = $this->connection->transactionLevel();
        if ($this->connection->transactionLevel() !== 1 || $this->mode === 'unproven') {
            return AuthorizationDecision::unknown('fixture.unproven_fence');
        }
        if ($this->mode === 'recheck_error') {
            throw new \RuntimeException('Local fence failure');
        }
        $row = $this->row($handle, $activation, lock: true);
        $this->decision($row)->requireAllowed();
        if ($this->token($handle, $activation, $row) !== $captured->generation) {
            return AuthorizationDecision::unknown('fixture.stale_consent');
        }

        return AuthorizationDecision::allow('fixture.consent_fence_held');
    }

    private function row(OperationHandle $handle, MembershipActivation $activation, bool $lock = false): ?\stdClass
    {
        $query = $this->connection->table('panel_a.membership_consent')->where('installation', $handle->actor->installation->id)
            ->where('subject', $activation->subject->toWire());

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function decision(?\stdClass $row): AuthorizationDecision
    {
        return $row === null ? AuthorizationDecision::unknown('fixture.unknown_subject')
            : ($row->allowed === true ? AuthorizationDecision::allow('fixture.subject_consent') : AuthorizationDecision::deny('fixture.subject_revoked'));
    }

    private function token(OperationHandle $handle, MembershipActivation $activation, ?\stdClass $row): string
    {
        return hash('sha256', CanonicalJson::encode(['installation' => $handle->actor->installation->id, 'surface' => $handle->surface->toWire(),
            'executor' => $handle->actor->actor?->toWire(), 'group' => $activation->group->toWire(), 'subject' => $activation->subject->toWire(),
            'transition' => $activation->transition, 'generation' => $row->generation ?? null]));
    }
}
