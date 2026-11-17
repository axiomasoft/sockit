<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Internal;

use Packman\Attributes\Internal;
use RuntimeException;

/**
 * Internal rollback signal thrown when post receipt replay preflight must be refreshed outside SQL locks
 * after concurrent post or receipt changes.
 */
#[Internal]
final class PostReplayPreflightStaleException extends RuntimeException {}
