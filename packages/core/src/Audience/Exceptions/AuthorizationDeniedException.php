<?php

declare(strict_types=1);

namespace SocKit\Audience\Exceptions;

use Packman\Attributes\Api;
use SocKit\Audience\Enums\AuthorizationStatus;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class AuthorizationDeniedException extends SocKitException
{
    public function __construct(public readonly AuthorizationStatus $status, string $reason)
    {
        parent::__construct(message: 'Action authorization failed', detail: ['reason' => $reason]);
    }

    public function problem(): ProblemCode
    {
        return $this->status === AuthorizationStatus::Unknown ? ProblemCode::AuthorizationUnknown : ProblemCode::AuthorizationDenied;
    }
}
