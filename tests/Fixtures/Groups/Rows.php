<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use SocKit\Groups\Internal\GroupIdentity;
use SocKit\Groups\Internal\MemberIdentity;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Ids;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;

final class Rows
{
    public static function identity(string $panel = 'a', string $key = '0042', int $incarnation = 1, string $installation = 'host-installation', string $kind = 'host.club', string $type = 'club'): GroupIdentity
    {
        return new GroupIdentity(new InstallationRef($installation), new SurfaceRef(new PanelId('groups.'.$panel),
            new SurfaceKindId($kind), new HostEntityRef($type, $key), new Incarnation($incarnation)));
    }

    /** @return array<string, mixed> */
    public static function group(?GroupIdentity $identity = null): array
    {
        $identity ??= self::identity();

        return [...$identity->columns(), 'surface_digest' => $identity->digest(), 'id' => Ids::uuid7(),
            'name' => 'Клуб «Шахматы»', 'join_policy' => 'approval', 'revision' => 1, 'membership_generation' => 1,
            'created_at' => '2026-10-29 00:00:00+00', 'updated_at' => '2026-10-29 00:00:00+00'];
    }

    /** @return array<string, mixed> */
    public static function member(string $group, ?ActorRef $actor = null): array
    {
        $identity = new MemberIdentity($group, $actor ?? new ActorRef('person', '0042'));

        return [...$identity->columns(), 'actor_digest' => $identity->digest(), 'id' => Ids::uuid7(), 'state' => 'pending',
            'revision' => 1, 'consent_generation' => null, 'consent_token' => null,
            'created_at' => '2026-10-29 00:00:00+00', 'updated_at' => '2026-10-29 00:00:00+00'];
    }
}
