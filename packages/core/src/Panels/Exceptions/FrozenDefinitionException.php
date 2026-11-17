<?php

declare(strict_types=1);

namespace SocKit\Panels\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class FrozenDefinitionException extends SocKitException
{
    public static function forBuilder(string $subject): self
    {
        return new self(
            SockitErrorCode::ConfigurationInvalid,
            "Cannot mutate frozen [{$subject}] after materialization/compile.",
            ['diagnostic' => 'REGISTRY_FROZEN', 'subject' => $subject],
        );
    }

    public static function forRegistry(string $registry): self
    {
        return new self(
            SockitErrorCode::ConfigurationInvalid,
            "Cannot read or mutate [{$registry}] in the current registry lifecycle phase.",
            ['diagnostic' => 'PANEL_REGISTRY_PHASE', 'registry' => $registry],
        );
    }
}
