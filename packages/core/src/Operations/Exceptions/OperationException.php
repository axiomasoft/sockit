<?php

declare(strict_types=1);

namespace SocKit\Operations\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class OperationException extends SocKitException
{
    public static function idempotencyConflict(string $keyHash): self
    {
        return new self(
            SockitErrorCode::IdempotencyConflict,
            'CommandKey was already executed with a different canonical payload digest.',
            ['command_key_hash' => $keyHash],
            '/command_key',
        );
    }

    public static function revisionConflict(int $expected, int $actual): self
    {
        return new self(
            SockitErrorCode::RevisionConflict,
            "Expected revision [{$expected}] does not match current revision [{$actual}].",
            ['expected_revision' => $expected, 'current_revision' => $actual],
            '/expected_revision',
        );
    }

    public static function providerUnavailable(string $provider): self
    {
        return new self(
            SockitErrorCode::ProviderUnavailable,
            "Required host SPI [{$provider}] is not configured or unavailable.",
            ['provider' => $provider],
        );
    }

    public static function eventIdentityConflict(string $eventId): self
    {
        return new self(
            SockitErrorCode::EventIdentityConflict,
            "Outbox/inbox event [{$eventId}] encountered a payload digest conflict.",
            ['event_id' => $eventId],
        );
    }

    public static function contentHidden(string $reason = 'content.hidden'): self
    {
        return new self(SockitErrorCode::ContentHidden, 'Requested content is not visible.', ['reason' => $reason]);
    }

    public static function contentDeleted(string $resourceId): self
    {
        return new self(SockitErrorCode::ContentDeleted, "Resource [{$resourceId}] was deleted.", ['resource_id' => $resourceId]);
    }

    public static function contentErased(string $resourceId): self
    {
        return new self(SockitErrorCode::ContentErased, "Resource [{$resourceId}] was erased.", ['resource_id' => $resourceId]);
    }

    public static function contentHeld(string $reason = 'safety.hold'): self
    {
        return new self(SockitErrorCode::ContentHeld, 'Content is held for safety review.', ['reason' => $reason]);
    }

    public static function audienceDenied(string $reason): self
    {
        return new self(SockitErrorCode::AudienceDenied, "Audience denied [{$reason}].", ['reason' => $reason]);
    }

    public static function audienceNotContained(string $reason): self
    {
        return new self(SockitErrorCode::AudienceNotContained, "Audience not contained within surface policy [{$reason}].", ['reason' => $reason]);
    }

    public static function nestedTransaction(string $panel): self
    {
        return new self(
            SockitErrorCode::ConfigurationInvalid,
            "Refusing to execute operation inside an existing open database transaction for panel [{$panel}].",
            ['panel' => $panel, 'reason' => 'operations.nested_transaction_forbidden'],
        );
    }
}
