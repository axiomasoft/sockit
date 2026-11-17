<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Capability marker for same-database {@see TargetResolver} implementations that acquire
 * local SQL row locks (`FOR UPDATE`) and may safely re-verify target state inside a write transaction.
 *
 * Resolvers not implementing this interface are never invoked while holding transaction locks.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface LocksTargetInTransaction extends TargetResolver {}
