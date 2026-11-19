<?php

declare(strict_types=1);

namespace SocKit\Posts\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class InvalidFeedCursorException extends SocKitException
{
    public function __construct()
    {
        parent::__construct('posts.invalid_cursor');
    }

    public function problem(): ProblemCode
    {
        return ProblemCode::PayloadInvalid;
    }
}
