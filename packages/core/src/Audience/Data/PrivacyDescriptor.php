<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Enums\Scope;
use SocKit\Audience\Exceptions\InvalidAudienceException;

/**
 * Contract-compatible privacy descriptor matching Edufy `Social\Contracts\PrivacyDescriptor::toContractArray()`.
 */
#[Api(since: '1.0')]
final readonly class PrivacyDescriptor
{
    public function __construct(
        public Scope $scope,
        public PrivacyFloor $floor,
        public ?string $contourLevel = null,
        public ?string $groupId = null,
    ) {
        if ($contourLevel !== null && preg_match(PrivacyFloor::CONTOUR_LEVEL_PATTERN, $contourLevel) !== 1) {
            throw InvalidAudienceException::invalidSpec("Invalid contour_level [{$contourLevel}].", '/contour_level');
        }

        if ($groupId !== null) {
            new GroupRef($groupId);
        }
    }

    /** @param array<array-key, mixed> $wire */
    public static function fromContractArray(array $wire): self
    {
        $rawScope = $wire['scope'] ?? null;

        if (! is_string($rawScope)) {
            throw InvalidAudienceException::invalidSpec('PrivacyDescriptor scope must be a string.', '/scope');
        }

        $scope = Scope::tryFrom($rawScope);

        if ($scope === null) {
            throw InvalidAudienceException::invalidSpec("Unknown PrivacyDescriptor scope [{$rawScope}].", '/scope');
        }

        $contourLevel = $wire['contour_level'] ?? null;
        $groupId = $wire['group_id'] ?? null;

        if ($contourLevel !== null && ! is_string($contourLevel)) {
            throw InvalidAudienceException::invalidSpec('PrivacyDescriptor contour_level must be a string.', '/contour_level');
        }

        if ($groupId !== null && ! is_string($groupId)) {
            throw InvalidAudienceException::invalidSpec('PrivacyDescriptor group_id must be a string.', '/group_id');
        }

        return new self(
            scope: $scope,
            floor: PrivacyFloor::fromWire($wire),
            contourLevel: $contourLevel,
            groupId: $groupId,
        );
    }

    /** @return array<string, mixed> */
    public function toContractArray(): array
    {
        $payload = [
            'scope' => $this->scope->value,
            'allowed_scopes' => array_map(static fn (Scope $s): string => $s->value, $this->floor->allowedScopes),
            'default_scope' => $this->floor->defaultScope->value,
            'max_scope' => $this->floor->maxScope->value,
            'minor_max_scope' => $this->floor->minorMaxScope->value,
            'minor_default_scope' => $this->floor->minorDefaultScope->value,
            'contour_levels' => $this->floor->contourLevels,
        ];

        if ($this->contourLevel !== null) {
            $payload['contour_level'] = $this->contourLevel;
        }

        if ($this->groupId !== null) {
            $payload['group_id'] = $this->groupId;
        }

        return $payload;
    }
}
