<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class SurfaceException extends SocKitException
{
    public static function notFound(string $surface): self
    {
        return new self(
            SockitErrorCode::SurfaceNotFound,
            "Surface [{$surface}] was not found.",
            ['surface' => $surface],
            '/surface',
        );
    }

    public static function archived(string $surface): self
    {
        return new self(
            SockitErrorCode::SurfaceArchived,
            "Surface [{$surface}] is archived and read-only.",
            ['surface' => $surface],
            '/surface',
        );
    }

    public static function deleted(string $surface): self
    {
        return new self(
            SockitErrorCode::ContentDeleted,
            "Surface [{$surface}] has been deleted.",
            ['surface' => $surface],
            '/surface',
        );
    }

    public static function incarnationMismatch(int $expected, int $actual): self
    {
        return new self(
            SockitErrorCode::SurfaceIncarnationMismatch,
            "Surface incarnation [{$actual}] does not match active incarnation [{$expected}].",
            ['expected' => $expected, 'actual' => $actual],
            '/incarnation',
        );
    }

    /** @param array<string, scalar|list<scalar|null>|null> $detail */
    public static function invalidDefinition(string $diagnosticCode, string $message, array $detail = [], ?string $pointer = null): self
    {
        return new self(
            SockitErrorCode::ConfigurationInvalid,
            $message,
            ['diagnostic' => $diagnosticCode, ...$detail],
            $pointer,
        );
    }
}
