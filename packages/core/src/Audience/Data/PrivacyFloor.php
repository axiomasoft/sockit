<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Enums\Scope;
use SocKit\Audience\Exceptions\FloorWidensException;
use SocKit\Audience\Exceptions\InvalidPrivacyFloorException;

/**
 * Publication privacy floor bounding allowed scopes, defaults, minor ceilings, and ordered contour levels.
 */
#[Api(since: '1.0')]
final readonly class PrivacyFloor
{
    public const string CONTOUR_LEVEL_PATTERN = '/\A[a-z][a-z0-9_-]{0,31}\z/';

    /**
     * @param  list<Scope>  $allowedScopes
     * @param  list<string>  $contourLevels
     */
    public function __construct(
        public array $allowedScopes,
        public Scope $defaultScope,
        public Scope $maxScope,
        public Scope $minorDefaultScope,
        public Scope $minorMaxScope,
        public array $contourLevels,
    ) {
        if ($allowedScopes === []) {
            throw InvalidPrivacyFloorException::rule('F1', 'PrivacyFloor allowedScopes must not be empty.', '/allowed_scopes');
        }

        $seenScopes = [];
        foreach ($allowedScopes as $scope) {
            if (isset($seenScopes[$scope->value])) {
                throw InvalidPrivacyFloorException::rule('F1', "PrivacyFloor allowedScopes contains duplicate [{$scope->value}].", '/allowed_scopes');
            }

            $seenScopes[$scope->value] = true;
        }

        if (! isset($seenScopes[$defaultScope->value])) {
            throw InvalidPrivacyFloorException::rule('F2', "PrivacyFloor defaultScope [{$defaultScope->value}] must be in allowedScopes.", '/default_scope');
        }

        if (! isset($seenScopes[$minorDefaultScope->value])) {
            throw InvalidPrivacyFloorException::rule('F3', "PrivacyFloor minorDefaultScope [{$minorDefaultScope->value}] must be in allowedScopes.", '/minor_default_scope');
        }

        foreach ($allowedScopes as $scope) {
            if ($scope->isWiderThan($maxScope)) {
                throw InvalidPrivacyFloorException::rule('F4', "PrivacyFloor allowedScope [{$scope->value}] is wider than maxScope [{$maxScope->value}].", '/allowed_scopes');
            }
        }

        if ($minorMaxScope->isWiderThan($maxScope)) {
            throw InvalidPrivacyFloorException::rule('F5', "PrivacyFloor minorMaxScope [{$minorMaxScope->value}] is wider than maxScope [{$maxScope->value}].", '/minor_max_scope');
        }

        if ($defaultScope->isWiderThan($maxScope)) {
            throw InvalidPrivacyFloorException::rule('F6', "PrivacyFloor defaultScope [{$defaultScope->value}] is wider than maxScope [{$maxScope->value}].", '/default_scope');
        }

        if ($minorDefaultScope->isWiderThan($minorMaxScope)) {
            throw InvalidPrivacyFloorException::rule('F7', "PrivacyFloor minorDefaultScope [{$minorDefaultScope->value}] is wider than minorMaxScope [{$minorMaxScope->value}].", '/minor_default_scope');
        }

        if (isset($seenScopes[Scope::Contour->value]) && $contourLevels === []) {
            throw InvalidPrivacyFloorException::rule('F8', 'PrivacyFloor contourLevels must be non-empty when Scope::Contour is allowed.', '/contour_levels');
        }

        $seenLevels = [];
        foreach ($contourLevels as $level) {
            if (preg_match(self::CONTOUR_LEVEL_PATTERN, $level) !== 1) {
                throw InvalidPrivacyFloorException::rule('F9', "PrivacyFloor contour level [{$level}] is invalid.", '/contour_levels');
            }

            if (isset($seenLevels[$level])) {
                throw InvalidPrivacyFloorException::rule('F9', "PrivacyFloor contourLevels contains duplicate [{$level}].", '/contour_levels');
            }

            $seenLevels[$level] = true;
        }
    }

    public static function defaultDirect(): self
    {
        return new self(
            allowedScopes: [Scope::Direct],
            defaultScope: Scope::Direct,
            maxScope: Scope::Direct,
            minorDefaultScope: Scope::Direct,
            minorMaxScope: Scope::Direct,
            contourLevels: [],
        );
    }

    /** @param array<array-key, mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $rawAllowed = $wire['allowed_scopes'] ?? null;
        $rawDefault = $wire['default_scope'] ?? null;
        $rawMax = $wire['max_scope'] ?? null;
        $rawMinorDefault = $wire['minor_default_scope'] ?? null;
        $rawMinorMax = $wire['minor_max_scope'] ?? null;
        $rawLevels = $wire['contour_levels'] ?? [];

        if (! is_array($rawAllowed) || ! array_is_list($rawAllowed)) {
            throw InvalidPrivacyFloorException::rule('F1', 'PrivacyFloor allowed_scopes must be a list.', '/allowed_scopes');
        }

        $allowed = [];
        foreach ($rawAllowed as $item) {
            if (! is_string($item)) {
                throw InvalidPrivacyFloorException::rule('F1', 'PrivacyFloor allowed_scopes entries must be strings.', '/allowed_scopes');
            }

            $scope = Scope::tryFrom($item);

            if ($scope === null) {
                throw InvalidPrivacyFloorException::rule('F1', "Unknown scope [{$item}] in allowed_scopes.", '/allowed_scopes');
            }

            $allowed[] = $scope;
        }

        if (! is_array($rawLevels) || ! array_is_list($rawLevels)) {
            throw InvalidPrivacyFloorException::rule('F9', 'PrivacyFloor contour_levels must be a list.', '/contour_levels');
        }

        $levels = [];
        foreach ($rawLevels as $level) {
            if (! is_string($level)) {
                throw InvalidPrivacyFloorException::rule('F9', 'PrivacyFloor contour_levels entries must be strings.', '/contour_levels');
            }

            $levels[] = $level;
        }

        return new self(
            allowedScopes: $allowed,
            defaultScope: self::parseScope($rawDefault, 'default_scope', 'F2'),
            maxScope: self::parseScope($rawMax, 'max_scope', 'F4'),
            minorDefaultScope: self::parseScope($rawMinorDefault, 'minor_default_scope', 'F3'),
            minorMaxScope: self::parseScope($rawMinorMax, 'minor_max_scope', 'F5'),
            contourLevels: $levels,
        );
    }

    /** @return array{allowed_scopes: list<string>, default_scope: string, max_scope: string, minor_default_scope: string, minor_max_scope: string, contour_levels: list<string>} */
    public function toWire(): array
    {
        return [
            'allowed_scopes' => array_map(static fn (Scope $s): string => $s->value, $this->allowedScopes),
            'default_scope' => $this->defaultScope->value,
            'max_scope' => $this->maxScope->value,
            'minor_default_scope' => $this->minorDefaultScope->value,
            'minor_max_scope' => $this->minorMaxScope->value,
            'contour_levels' => $this->contourLevels,
        ];
    }

    public function narrowedBy(self $surface): self
    {
        $parentAllowed = array_flip(array_map(static fn (Scope $s): string => $s->value, $this->allowedScopes));

        foreach ($surface->allowedScopes as $scope) {
            if (! isset($parentAllowed[$scope->value])) {
                throw FloorWidensException::widened("Surface floor introduces disallowed scope [{$scope->value}].", '/allowed_scopes');
            }
        }

        if ($surface->maxScope->isWiderThan($this->maxScope)) {
            throw FloorWidensException::widened("Surface maxScope [{$surface->maxScope->value}] widens [{$this->maxScope->value}].", '/max_scope');
        }

        if ($surface->defaultScope->isWiderThan($this->defaultScope)) {
            throw FloorWidensException::widened("Surface defaultScope [{$surface->defaultScope->value}] widens [{$this->defaultScope->value}].", '/default_scope');
        }

        if ($surface->minorMaxScope->isWiderThan($this->minorMaxScope)) {
            throw FloorWidensException::widened("Surface minorMaxScope [{$surface->minorMaxScope->value}] widens [{$this->minorMaxScope->value}].", '/minor_max_scope');
        }

        if ($surface->minorDefaultScope->isWiderThan($this->minorDefaultScope)) {
            throw FloorWidensException::widened("Surface minorDefaultScope [{$surface->minorDefaultScope->value}] widens [{$this->minorDefaultScope->value}].", '/minor_default_scope');
        }

        $parentOrder = array_flip($this->contourLevels);
        $lastIndex = -1;

        foreach ($surface->contourLevels as $level) {
            if (! isset($parentOrder[$level])) {
                throw FloorWidensException::widened("Surface contour level [{$level}] is not defined in parent floor.", '/contour_levels');
            }

            $idx = $parentOrder[$level];

            if ($idx <= $lastIndex) {
                throw FloorWidensException::widened("Surface contour level [{$level}] reorders parent contourLevels.", '/contour_levels');
            }

            $lastIndex = $idx;
        }

        return $surface;
    }

    public function allowsScope(Scope $scope): bool
    {
        return in_array($scope, $this->allowedScopes, true);
    }

    public function allowsContourLevel(string $level): bool
    {
        return in_array($level, $this->contourLevels, true);
    }

    public function maxFor(ActorContext $author): Scope
    {
        return $author->isMinorForPolicy() ? $this->minorMaxScope : $this->maxScope;
    }

    public function defaultFor(ActorContext $author): Scope
    {
        return $author->isMinorForPolicy() ? $this->minorDefaultScope : $this->defaultScope;
    }

    private static function parseScope(mixed $raw, string $field, string $rule): Scope
    {
        if (! is_string($raw)) {
            throw InvalidPrivacyFloorException::rule($rule, "PrivacyFloor {$field} must be a string.", '/'.$field);
        }

        $scope = Scope::tryFrom($raw);

        if ($scope === null) {
            throw InvalidPrivacyFloorException::rule($rule, "Unknown scope [{$raw}] in {$field}.", '/'.$field);
        }

        return $scope;
    }
}
