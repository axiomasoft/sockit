<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use Illuminate\Database\Connection;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Posts\Contracts\ContentSafety;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Data\SafetyVerdict;
use SocKit\Posts\Enums\SafetyDecision;

final class SafetyAdapter implements ContentSafety
{
    /** @var list<int> */
    public array $levels = [];

    public function __construct(private readonly Connection $connection, public SafetyDecision $decision = SafetyDecision::Allow, public bool $fail = false) {}

    public function assess(OperationHandle $handle, PostCommand $post): SafetyVerdict
    {
        $this->levels[] = $this->connection->transactionLevel();
        if ($this->fail || $this->connection->transactionLevel() !== 0) {
            throw new \RuntimeException('Safety unavailable or called under lock');
        }

        return new SafetyVerdict(decision: $this->decision, reason: 'host.safety.'.$this->decision->value);
    }
}
