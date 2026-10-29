<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use SocKit\Panels\Data\PanelId;
use SocKit\Reactions\Internal\ReactionIdentity;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Ids;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;

final class Rows
{
    public static function identity(string $panel = 'a', string $key = '0042', int $incarnation = 1, ?ActorRef $actor = new ActorRef('user', '0042'), string $kind = 'like', int $surfaceIncarnation = 1): ReactionIdentity
    {
        return new ReactionIdentity(new InstallationRef('host-installation'),
            new SurfaceRef(new PanelId('social.'.$panel), new SurfaceKindId('host.article'), new HostEntityRef('article', str_repeat('H', 512)), new Incarnation($surfaceIncarnation)),
            new TargetRef(new HostEntityRef('product', $key), new Incarnation($incarnation)), $kind, $actor);
    }

    /** @return array<string, mixed> */
    public static function reaction(ReactionIdentity $identity): array
    {
        return [...$identity->columns(), 'identity_digest' => $identity->digest(), 'id' => Ids::uuid7(),
            'active' => true, 'revision' => 1, 'created_at' => '2026-10-29 00:00:00+00', 'updated_at' => '2026-10-29 00:00:00+00'];
    }

    /** @return array<string, mixed> */
    public static function post(string $panel = 'a'): array
    {
        $surface = array_intersect_key(self::identity($panel)->columns(), array_flip(['installation', 'panel', 'surface_kind', 'host_type', 'host_key', 'surface_incarnation']));

        return [...$surface, 'surface_digest' => hash('sha256', json_encode($surface, JSON_THROW_ON_ERROR)),
            'id' => Ids::uuid7(), 'author_ref' => 'person:0042', 'body' => 'Post body', 'media_refs' => ['media:0042'],
            'audience' => ['type' => 'public'], 'revision' => 1, 'privacy_revision' => 1,
            'publication_state' => 'pending', 'visibility' => 'visible', 'content_state' => 'retained',
            'safety_generation' => 1, 'consent_generation' => 1, 'published_at' => null, 'erased_at' => null,
            'created_at' => '2026-10-29 00:00:00+00', 'updated_at' => '2026-10-29 00:00:00+00'];
    }

    /** @param array<string, mixed> $post
     * @return array<string, mixed> */
    public static function revision(array $post): array
    {
        return ['id' => Ids::uuid7(), 'post_id' => $post['id'], 'revision' => $post['revision'], 'privacy_revision' => $post['privacy_revision'],
            'actor_ref' => $post['author_ref'], 'reason' => 'initial', 'action' => 'create', 'publication_state' => $post['publication_state'],
            'visibility' => $post['visibility'], 'audience' => $post['audience'], 'body' => $post['body'], 'media_refs' => $post['media_refs'], 'created_at' => $post['created_at']];
    }
}
