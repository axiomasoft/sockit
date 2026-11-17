<?php

declare(strict_types=1);

namespace SocKit\Panels\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class PanelException extends SocKitException
{
    public static function notFound(string $panelId): self
    {
        return new self(
            SockitErrorCode::PanelNotFound,
            "Panel [{$panelId}] is not registered.",
            ['panel' => $panelId],
            '/panel',
        );
    }

    public static function bindingMismatch(string $panelId, string $reason): self
    {
        return new self(
            SockitErrorCode::PanelBindingMismatch,
            $reason,
            ['panel' => $panelId, 'reason' => $reason],
            '/panel',
        );
    }

    /** @param array<string, scalar|list<scalar|null>|null> $detail */
    public static function invalidConfig(string $diagnosticCode, string $message, array $detail = [], ?string $pointer = null): self
    {
        return new self(
            SockitErrorCode::ConfigurationInvalid,
            $message,
            ['diagnostic' => $diagnosticCode, ...$detail],
            $pointer,
        );
    }
}
