<?php

declare(strict_types=1);

namespace SocKit\Operations\Contracts;

use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Operations\Data\MutationEffect;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\MutationSnapshot;
use SocKit\Operations\Data\OperationHandle;

/** Trusted module owner, never untrusted payload code. request/payload are side-effect-free, outside tx.
 * lock/persist use only the exact owner connection, in receipt -> surface -> group -> target order.
 * No network, ambient actor or secondary connection writes. lock supplies current owner safety/generation;
 * persist performs SQL CAS, reports real affected rows and the next revision. Errors roll back everything.
 * No automatic retry/cache/batch. A denial narrows the kernel guards. Stable SPI additions need a new capability.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface OwnerMutation
{
    public function commandType(): string;

    /** @return array<string, mixed> Canonical scalars/arrays only; floats/objects are rejected. */
    public function payload(): array;

    public function request(OperationHandle $handle): MutationRequest;

    public function lock(OperationHandle $handle): MutationSnapshot;

    public function persist(OperationHandle $handle, MutationSnapshot $snapshot): MutationEffect;
}
