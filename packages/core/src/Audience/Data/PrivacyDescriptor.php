<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use SocKit\Attributes\Api;
use SocKit\Audience\Enums\Scope;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class PrivacyDescriptor
{
    public function __construct(public Scope $scope, public PrivacyFloor $floor, public ?string $contourLevel = null, public ?string $groupId = null)
    {
        if (! in_array($scope, $floor->allowedScopes, true)
            || ($contourLevel !== null && ($scope !== Scope::Contour || ! in_array($contourLevel, $floor->contourLevels, true)))
            || ($groupId !== null && $scope !== Scope::Group)) {
            throw new InvalidPayloadException('Descriptor does not match floor or scope');
        }
        if ($groupId !== null) {
            new GroupRef($groupId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toContractArray(): array
    {
        $wire = [
            'scope' => $this->scope->value,
            'allowed_scopes' => array_map(fn (Scope $scope): string => $scope->value, $this->floor->allowedScopes),
            'default_scope' => $this->floor->defaultScope->value,
            'max_scope' => $this->floor->maxScope->value,
            'minor_max_scope' => $this->floor->minorMaxScope->value,
            'minor_default_scope' => $this->floor->minorDefaultScope->value,
            'contour_levels' => $this->floor->contourLevels,
        ];
        if ($this->contourLevel !== null) {
            $wire['contour_level'] = $this->contourLevel;
        }
        if ($this->groupId !== null) {
            $wire['group_id'] = $this->groupId;
        }

        return $wire;
    }

    /**
     * @param  array<string, mixed>  $wire
     */
    public static function fromContractArray(array $wire): self
    {
        $required = ['scope', 'allowed_scopes', 'default_scope', 'max_scope', 'minor_max_scope', 'minor_default_scope', 'contour_levels'];
        if (array_diff($required, array_keys($wire)) !== [] || array_diff(array_keys($wire), [...$required, 'contour_level', 'group_id']) !== []) {
            throw new InvalidPayloadException('Invalid descriptor keys');
        }
        $scopes = [];
        foreach (['scope', 'default_scope', 'max_scope', 'minor_max_scope', 'minor_default_scope'] as $key) {
            $scopes[$key] = is_string($wire[$key]) ? (Scope::tryFrom($wire[$key]) ?? throw new InvalidPayloadException('Invalid descriptor scope')) : throw new InvalidPayloadException('Invalid descriptor scope');
        }
        if (! is_array($wire['allowed_scopes']) || ! array_is_list($wire['allowed_scopes']) || ! is_array($wire['contour_levels']) || ! array_is_list($wire['contour_levels'])) {
            throw new InvalidPayloadException('Invalid descriptor lists');
        }
        $allowed = [];
        foreach ($wire['allowed_scopes'] as $value) {
            $allowed[] = is_string($value) ? (Scope::tryFrom($value) ?? throw new InvalidPayloadException('Invalid allowed scope')) : throw new InvalidPayloadException('Invalid allowed scope');
        }
        $levels = [];
        foreach ($wire['contour_levels'] as $level) {
            $levels[] = is_string($level) ? $level : throw new InvalidPayloadException('Invalid contour level');
        }
        foreach (['contour_level', 'group_id'] as $key) {
            if (array_key_exists($key, $wire) && ! is_string($wire[$key])) {
                throw new InvalidPayloadException('Invalid optional descriptor field');
            }
        }

        return new self(
            scope: $scopes['scope'],
            floor: new PrivacyFloor(allowedScopes: $allowed, defaultScope: $scopes['default_scope'], maxScope: $scopes['max_scope'], minorDefaultScope: $scopes['minor_default_scope'], minorMaxScope: $scopes['minor_max_scope'], contourLevels: $levels),
            contourLevel: isset($wire['contour_level']) && is_string($wire['contour_level']) ? $wire['contour_level'] : null,
            groupId: isset($wire['group_id']) && is_string($wire['group_id']) ? $wire['group_id'] : null,
        );
    }
}
