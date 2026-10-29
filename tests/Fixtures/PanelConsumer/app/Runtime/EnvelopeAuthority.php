<?php

declare(strict_types=1);

namespace Acme\Runtime;

use Illuminate\Database\Connection;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AuthorizationDecision;

final class EnvelopeAuthority implements ActionAuthorizer
{
    /** @var list<int> */
    public array $levels = [];

    public function __construct(private readonly Connection $connection, private readonly string $schema = 'public') {}

    public function decide(ActionRequest $request): AuthorizationDecision
    {
        $this->levels[] = $this->connection->transactionLevel();
        if ($this->connection->transactionLevel() !== 0) {
            throw new \LogicException('Authority resolver under owner lock');
        }

        return $this->connection->table($this->schema.'.envelope_authority')->value('allowed') === true ? AuthorizationDecision::allow('fixture.current_authority') : AuthorizationDecision::deny('fixture.revoked');
    }
}
