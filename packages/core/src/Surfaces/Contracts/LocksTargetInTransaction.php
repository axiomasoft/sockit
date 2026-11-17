<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Marks a {@see TargetWriteFence} whose target storage resides on the same panel owner
 * connection and acquires `FOR UPDATE` locks safely inside active SQL transactions.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface LocksTargetInTransaction extends TargetWriteFence {}
