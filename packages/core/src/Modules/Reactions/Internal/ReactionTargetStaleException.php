<?php

declare(strict_types=1);

namespace SocKit\Modules\Reactions\Internal;

use Packman\Attributes\Internal;
use RuntimeException;

/**
 * Internal rollback signal thrown when a same-DB locked target's audience or privacy revision
 * changed between pre-transaction authorization and lock acquisition.
 */
#[Internal]
final class ReactionTargetStaleException extends RuntimeException {}
