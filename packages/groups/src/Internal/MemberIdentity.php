<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use Packman\Attributes\Internal;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Exceptions\InvalidPayloadException;

/** A subject actor, never the trusted executor. Resolve and verify the owning group first. */
#[Internal]
final readonly class MemberIdentity
{
    public function __construct(private string $groupId, private ActorRef $actor)
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $groupId) !== 1) {
            throw new InvalidPayloadException('Invalid group UUIDv7');
        }
    }

    /** @return array<string, string> */
    public function columns(): array
    {
        return ['group_id' => $this->groupId, 'actor_ref' => $this->actor->toWire()];
    }

    public function digest(): string
    {
        return hash('sha256', $this->actor->toWire());
    }

    /** Required even when the unique digest matches; a collision must fail closed.
     * @param array<string, mixed> $row */
    public function verify(array $row): void
    {
        foreach ($this->columns() as $key => $value) {
            if (! array_key_exists($key, $row) || $row[$key] !== $value) {
                throw new InvalidPayloadException('Group member digest collision or corrupt row');
            }
        }
        if (($row['actor_digest'] ?? null) !== $this->digest()) {
            throw new InvalidPayloadException('Group member digest mismatch');
        }
    }
}
