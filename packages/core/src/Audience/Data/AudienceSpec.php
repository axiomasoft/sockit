<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Enums\Scope;
use SocKit\Audience\Exceptions\InvalidAudienceException;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\WireVersion;

/**
 * Versioned audience set predicate supporting atomic scopes (direct, group, contour, platform, public)
 * and algebraic combinators (allOf, anyOf, exclude).
 */
#[Api(since: '1.0')]
final readonly class AudienceSpec
{
    public const int MAX_DIRECT_ACTORS = 500;

    /**
     * @param  list<ActorRef>  $actors
     * @param  list<AudienceSpec>  $children
     */
    private function __construct(
        public WireVersion $version,
        public ?Scope $scope,
        public ?string $operator,
        public array $actors = [],
        public ?GroupRef $group = null,
        public ?string $contourLevel = null,
        public ?HostEntityRef $contour = null,
        public array $children = [],
    ) {}

    public static function direct(ActorRef ...$actors): self
    {
        if ($actors === [] || count($actors) > self::MAX_DIRECT_ACTORS) {
            throw InvalidAudienceException::invalidSpec('Direct audience requires 1..500 actors.', '/actors');
        }

        $seen = [];
        $unique = [];
        foreach ($actors as $actor) {
            $wire = $actor->toWire();

            if (isset($seen[$wire])) {
                throw InvalidAudienceException::invalidSpec("Duplicate actor [{$wire}] in direct audience.", '/actors');
            }

            $seen[$wire] = true;
            $unique[] = $actor;
        }

        return new self(
            version: WireVersion::v1(),
            scope: Scope::Direct,
            operator: null,
            actors: $unique,
        );
    }

    public static function group(GroupRef $group): self
    {
        return new self(
            version: WireVersion::v1(),
            scope: Scope::Group,
            operator: null,
            group: $group,
        );
    }

    public static function contour(string $level, HostEntityRef $contour): self
    {
        if (preg_match(PrivacyFloor::CONTOUR_LEVEL_PATTERN, $level) !== 1) {
            throw InvalidAudienceException::invalidSpec("Invalid contour level [{$level}].", '/contour_level');
        }

        return new self(
            version: WireVersion::v1(),
            scope: Scope::Contour,
            operator: null,
            contourLevel: $level,
            contour: $contour,
        );
    }

    public static function platform(): self
    {
        return new self(
            version: WireVersion::v1(),
            scope: Scope::Platform,
            operator: null,
        );
    }

    public static function public(): self
    {
        return new self(
            version: WireVersion::v1(),
            scope: Scope::Public,
            operator: null,
        );
    }

    public static function allOf(self ...$specs): self
    {
        if ($specs === []) {
            throw InvalidAudienceException::invalidSpec('allOf audience requires at least one child spec.', '/specs');
        }

        return new self(
            version: WireVersion::v1(),
            scope: null,
            operator: 'all_of',
            children: array_values($specs),
        );
    }

    public static function anyOf(self ...$specs): self
    {
        if ($specs === []) {
            throw InvalidAudienceException::invalidSpec('anyOf audience requires at least one child spec.', '/specs');
        }

        return new self(
            version: WireVersion::v1(),
            scope: null,
            operator: 'any_of',
            children: array_values($specs),
        );
    }

    public static function exclude(self $include, self $exclude): self
    {
        return new self(
            version: WireVersion::v1(),
            scope: null,
            operator: 'exclude',
            children: [$include, $exclude],
        );
    }

    /** @param array<array-key, mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $rawVersion = $wire['version'] ?? null;

        if (! is_string($rawVersion) || $rawVersion === '') {
            throw InvalidAudienceException::invalidSpec('AudienceSpec wire requires string version.', '/version');
        }

        if (preg_match('/\A([1-9]\d*)\.(0|[1-9]\d*)\z/', $rawVersion, $m) !== 1 || (int) $m[1] !== 1) {
            throw InvalidAudienceException::unsupportedVersion($rawVersion);
        }

        $operator = $wire['operator'] ?? null;

        if (is_string($operator)) {
            return match ($operator) {
                'all_of' => self::allOf(...self::decodeChildren($wire['specs'] ?? null, '/specs')),
                'any_of' => self::anyOf(...self::decodeChildren($wire['specs'] ?? null, '/specs')),
                'exclude' => self::decodeExclude($wire),
                default => throw InvalidAudienceException::invalidSpec("Unknown AudienceSpec operator [{$operator}].", '/operator'),
            };
        }

        $rawScope = $wire['scope'] ?? null;

        if (! is_string($rawScope)) {
            throw InvalidAudienceException::invalidSpec('AudienceSpec wire requires scope or operator.', '/scope');
        }

        $scope = Scope::tryFrom($rawScope);

        if ($scope === null) {
            throw InvalidAudienceException::invalidSpec("Unknown AudienceSpec scope [{$rawScope}].", '/scope');
        }

        return match ($scope) {
            Scope::Public => self::public(),
            Scope::Platform => self::platform(),
            Scope::Group => self::decodeGroup($wire),
            Scope::Contour => self::decodeContour($wire),
            Scope::Direct => self::decodeDirect($wire),
        };
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        if ($this->operator === 'all_of' || $this->operator === 'any_of') {
            return [
                'version' => $this->version->toWire(),
                'operator' => $this->operator,
                'specs' => array_map(static fn (self $child): array => $child->toWire(), $this->children),
            ];
        }

        if ($this->operator === 'exclude') {
            return [
                'version' => $this->version->toWire(),
                'operator' => 'exclude',
                'include' => $this->children[0]->toWire(),
                'exclude' => $this->children[1]->toWire(),
            ];
        }

        return match ($this->scope) {
            Scope::Public => [
                'version' => $this->version->toWire(),
                'scope' => Scope::Public->value,
            ],
            Scope::Platform => [
                'version' => $this->version->toWire(),
                'scope' => Scope::Platform->value,
            ],
            Scope::Group => [
                'version' => $this->version->toWire(),
                'scope' => Scope::Group->value,
                'group_id' => $this->group instanceof GroupRef ? $this->group->value : '',
            ],
            Scope::Contour => [
                'version' => $this->version->toWire(),
                'scope' => Scope::Contour->value,
                'contour_level' => $this->contourLevel ?? '',
                'contour' => $this->contour?->toWire() ?? '',
            ],
            Scope::Direct => [
                'version' => $this->version->toWire(),
                'scope' => Scope::Direct->value,
                'actors' => array_map(static fn (ActorRef $a): string => $a->toWire(), $this->actors),
            ],
            default => throw InvalidAudienceException::invalidSpec('Incomplete AudienceSpec.'),
        };
    }

    /** @return list<self> */
    private static function decodeChildren(mixed $raw, string $pointer): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
            throw InvalidAudienceException::invalidSpec('Composite AudienceSpec requires non-empty specs list.', $pointer);
        }

        $specs = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                throw InvalidAudienceException::invalidSpec('Child AudienceSpec must be an array.', $pointer);
            }

            $specs[] = self::fromWire($entry);
        }

        return $specs;
    }

    /** @param array<array-key, mixed> $wire */
    private static function decodeExclude(array $wire): self
    {
        $include = $wire['include'] ?? null;
        $exclude = $wire['exclude'] ?? null;

        if (! is_array($include) || ! is_array($exclude)) {
            throw InvalidAudienceException::invalidSpec('Exclude AudienceSpec requires include and exclude objects.');
        }

        return self::exclude(self::fromWire($include), self::fromWire($exclude));
    }

    /** @param array<array-key, mixed> $wire */
    private static function decodeGroup(array $wire): self
    {
        $groupId = $wire['group_id'] ?? null;

        if (! is_string($groupId)) {
            throw InvalidAudienceException::invalidSpec('Group AudienceSpec requires string group_id.', '/group_id');
        }

        return self::group(new GroupRef($groupId));
    }

    /** @param array<array-key, mixed> $wire */
    private static function decodeContour(array $wire): self
    {
        $level = $wire['contour_level'] ?? null;
        $contour = $wire['contour'] ?? null;

        if (! is_string($level) || ! is_string($contour)) {
            throw InvalidAudienceException::invalidSpec('Contour AudienceSpec requires string contour_level and contour.');
        }

        return self::contour($level, HostEntityRef::fromWire($contour));
    }

    /** @param array<array-key, mixed> $wire */
    private static function decodeDirect(array $wire): self
    {
        $rawActors = $wire['actors'] ?? null;

        if (! is_array($rawActors) || ! array_is_list($rawActors)) {
            throw InvalidAudienceException::invalidSpec('Direct AudienceSpec requires actors list.', '/actors');
        }

        $actors = [];
        foreach ($rawActors as $rawActor) {
            if (! is_string($rawActor)) {
                throw InvalidAudienceException::invalidSpec('Direct actor wire values must be strings.', '/actors');
            }

            $actors[] = ActorRef::fromWire($rawActor);
        }

        return self::direct(...$actors);
    }

    /**
     * Collect all distinct group references inside this spec in deterministic sorted order for deadlock-free locking.
     *
     * @return list<GroupRef>
     */
    public function referencedGroups(): array
    {
        /** @var array<string, GroupRef> $collected */
        $collected = [];
        $this->collectGroups($this, $collected);
        ksort($collected);

        return array_values($collected);
    }

    /** @param array<string, GroupRef> $collected */
    private function collectGroups(self $node, array &$collected): void
    {
        if ($node->scope === Scope::Group && $node->group instanceof GroupRef) {
            $collected[$node->group->value] = $node->group;
        }

        foreach ($node->children as $child) {
            $this->collectGroups($child, $collected);
        }
    }
}
