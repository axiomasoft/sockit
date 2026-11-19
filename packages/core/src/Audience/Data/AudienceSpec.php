<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Enums\Scope;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\WireVersion;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class AudienceSpec
{
    public WireVersion $version;

    private int $depth;

    /**
     * @param  list<ActorRef>  $actors
     * @param  list<self>  $children
     */
    private function __construct(
        public ?Scope $scope,
        public array $actors = [],
        public ?GroupRef $group = null,
        public ?string $contourLevel = null,
        public ?HostEntityRef $contour = null,
        public ?string $operator = null,
        public array $children = [],
    ) {
        $this->depth = $children === [] ? 1 : 1 + max(array_map(static fn (self $spec): int => $spec->depth, $children));
        if ($this->depth > 32 || count($children) > 500 || ($operator !== null && $children === [])) {
            throw new InvalidPayloadException('Invalid audience expression size');
        }
        $this->version = new WireVersion(major: 1, minor: 0);
    }

    public static function direct(ActorRef ...$actors): self
    {
        $actors = array_values($actors);
        $keys = array_map(fn (ActorRef $actor): string => $actor->toWire(), $actors);
        if (count($keys) < 1 || count($keys) > 500 || count(array_unique($keys)) !== count($keys)) {
            throw new InvalidPayloadException('Direct audience needs 1..500 unique actors');
        }

        return new self(scope: Scope::Direct, actors: $actors);
    }

    public static function group(GroupRef $group): self
    {
        return new self(scope: Scope::Group, group: $group);
    }

    public static function contour(string $level, HostEntityRef $contour): self
    {
        if (! preg_match('~^[a-z][a-z0-9_-]{0,31}$~D', $level)) {
            throw new InvalidPayloadException('Invalid contour level');
        }

        return new self(scope: Scope::Contour, contourLevel: $level, contour: $contour);
    }

    public static function platform(): self
    {
        return new self(Scope::Platform);
    }

    public static function public(): self
    {
        return new self(Scope::Public);
    }

    public static function allOf(self ...$specs): self
    {
        return new self(scope: null, operator: 'all_of', children: array_values($specs));
    }

    public static function anyOf(self ...$specs): self
    {
        return new self(scope: null, operator: 'any_of', children: array_values($specs));
    }

    public static function exclude(self $include, self $exclude): self
    {
        return new self(scope: null, operator: 'exclude', children: [$include, $exclude]);
    }

    /** @return list<GroupRef> */
    public function referencedGroups(): array
    {
        $groups = $this->group === null ? [] : [$this->group->value => $this->group];
        foreach ($this->children as $child) {
            foreach ($child->referencedGroups() as $group) {
                $groups[$group->value] = $group;
            }
        }

        return array_values($groups);
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        if ($this->operator !== null) {
            return ['version' => $this->version->toWire(), 'operator' => $this->operator] + ($this->operator === 'exclude'
                ? ['include' => $this->children[0]->toWire(), 'exclude' => $this->children[1]->toWire()]
                : ['specs' => array_map(static fn (self $spec): array => $spec->toWire(), $this->children)]);
        }
        assert($this->scope !== null);

        return ['version' => $this->version->toWire(), 'scope' => $this->scope->value] + match ($this->scope) {
            Scope::Direct => ['actors' => array_map(fn (ActorRef $actor): string => $actor->toWire(), $this->actors)],
            Scope::Group => ['group' => $this->group?->value],
            Scope::Contour => ['contour_level' => $this->contourLevel, 'contour' => $this->contour?->toWire()],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $wire
     */
    public static function fromWire(array $wire): self
    {
        return self::decode($wire, 1);
    }

    /** @param array<array-key,mixed> $wire */
    private static function decode(array $wire, int $depth): self
    {
        if ($depth > 32 || ! is_string($wire['version'] ?? null)) {
            throw new InvalidPayloadException('Invalid audience wire');
        }
        new WireVersion(major: 1, minor: 0)->requireSupported(WireVersion::fromWire($wire['version']));
        if (array_key_exists('operator', $wire)) {
            $decode = static fn (mixed $child): self => is_array($child) ? self::decode($child, $depth + 1) : throw new InvalidPayloadException('Invalid audience child');
            if ($wire['operator'] === 'exclude' && count($wire) === 4 && isset($wire['include'], $wire['exclude'])) {
                return self::exclude($decode($wire['include']), $decode($wire['exclude']));
            }
            if (count($wire) !== 3 || ! is_array($wire['specs'] ?? null) || ! array_is_list($wire['specs']) || count($wire['specs']) > 500) {
                throw new InvalidPayloadException('Invalid audience expression');
            }

            return match ($wire['operator']) {
                'all_of' => self::allOf(...array_map($decode, $wire['specs'])),
                'any_of' => self::anyOf(...array_map($decode, $wire['specs'])),
                default => throw new InvalidPayloadException('Invalid audience operator'),
            };
        }
        $scope = Scope::tryFrom(is_string($wire['scope'] ?? null) ? $wire['scope'] : '') ?? throw new InvalidPayloadException('Unknown audience scope');
        $expected = ['version', 'scope', ...match ($scope) {
            Scope::Direct => ['actors'], Scope::Group => ['group'], Scope::Contour => ['contour_level', 'contour'], default => [],
        }];
        if (count($wire) !== count($expected) || array_diff($expected, array_keys($wire)) !== []) {
            throw new InvalidPayloadException('Audience must contain exactly one scope form');
        }
        if ($scope === Scope::Direct) {
            if (! is_array($wire['actors']) || ! array_is_list($wire['actors'])) {
                throw new InvalidPayloadException('Invalid direct actors');
            }
            $actors = [];
            foreach ($wire['actors'] as $actor) {
                $actors[] = ActorRef::fromWire(is_string($actor) ? $actor : throw new InvalidPayloadException('Invalid actor'));
            }

            return self::direct(...$actors);
        }

        return match ($scope) {
            Scope::Group => self::group(new GroupRef(is_string($wire['group']) ? $wire['group'] : throw new InvalidPayloadException('Invalid group'))),
            Scope::Contour => self::contour(
                level: is_string($wire['contour_level']) ? $wire['contour_level'] : throw new InvalidPayloadException('Invalid level'),
                contour: HostEntityRef::fromWire(is_string($wire['contour']) ? $wire['contour'] : throw new InvalidPayloadException('Invalid contour')),
            ),
            Scope::Platform => self::platform(), Scope::Public => self::public(),
        };
    }
}
