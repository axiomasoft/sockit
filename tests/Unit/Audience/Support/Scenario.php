<?php

declare(strict_types=1);

namespace SocKit\Tests\Unit\Audience\Support;

use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\PrivacyDescriptor;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Surfaces\Data\SurfaceRef;

final class Scenario
{
    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (! is_array($value)) {
            throw new \RuntimeException('Fixture object expected');
        }
        $result = [];
        foreach ($value as $key => $entry) {
            if (! is_string($key)) {
                throw new \RuntimeException('Fixture object key expected');
            }
            $result[$key] = $entry;
        }

        return $result;
    }

    public static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new \RuntimeException('Fixture string expected');
    }

    /** @return list<mixed> */
    public static function entries(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : throw new \RuntimeException('Fixture list expected');
    }

    public static function flag(mixed $value): bool
    {
        return is_bool($value) ? $value : throw new \RuntimeException('Fixture bool expected');
    }

    /** @param array<string, mixed> $wire */
    public static function floor(array $wire): PrivacyFloor
    {
        return PrivacyDescriptor::fromContractArray(['scope' => $wire['default_scope'], ...$wire])->floor;
    }

    /** @return array<string, mixed> */
    public static function floorWire(): array
    {
        return ['allowed_scopes' => ['direct', 'group', 'contour', 'platform', 'public'], 'default_scope' => 'contour', 'max_scope' => 'public', 'minor_max_scope' => 'contour', 'minor_default_scope' => 'direct', 'contour_levels' => ['class', 'school']];
    }

    /** @param array<string, mixed> $wire */
    public static function actor(array $wire): ActorContext
    {
        return new ActorContext(
            actor: $wire['actor'] === null ? null : ActorRef::fromWire(self::text($wire['actor'])),
            kind: ActorKind::from(self::text($wire['kind'])),
            installation: new InstallationRef(self::text($wire['installation'])),
            ageBand: AgeBand::from(self::text($wire['age_band'])),
            provenance: ContextProvenance::Direct,
        );
    }

    public static function viewer(): ActorContext
    {
        return self::actor(['actor' => 'user:1', 'kind' => 'person', 'installation' => 'i1', 'age_band' => 'adult']);
    }

    /** @param array<string, mixed> $wire */
    public static function subject(array $wire): AudienceSubject
    {
        return new AudienceSubject(
            key: self::text($wire['key']), spec: AudienceSpec::fromWire(self::object($wire['spec'])),
            author: $wire['author'] === null ? null : ActorRef::fromWire(self::text($wire['author'])),
            surface: SurfaceRef::fromWire(self::object($wire['surface'])), installation: new InstallationRef(self::text($wire['installation'])),
            surfaceArchived: self::flag($wire['surface_archived']), archiveReaders: self::flag($wire['archive_readers']),
        );
    }

    /** @return array<string, mixed> */
    public static function subjectWire(string $key = 'p1'): array
    {
        return ['key' => $key, 'installation' => 'i1', 'author' => 'user:9', 'spec' => ['version' => '1.0', 'scope' => 'contour', 'contour_level' => 'class', 'contour' => 'edufy.class:42'], 'surface' => ['panel' => 'edufy.community', 'kind' => 'edufy.class-feed', 'host' => 'edufy.class:42', 'incarnation' => 1], 'surface_archived' => false, 'archive_readers' => false];
    }
}
