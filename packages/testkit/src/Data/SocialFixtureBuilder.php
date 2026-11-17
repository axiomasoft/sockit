<?php

declare(strict_types=1);

namespace SocKit\Testkit\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Modules\Groups\Data\CreateGroupCommand;
use SocKit\Modules\Groups\Enums\GroupVisibility;
use SocKit\Modules\Posts\Data\PublishPostCommand;
use SocKit\Modules\Reactions\Data\AddReactionCommand;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Ids;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Fluent fixture builder for tests constructing SocKit surface references, actors, and commands.
 */
#[Api(since: '1.0')]
final class SocialFixtureBuilder
{
    private string $panel = 'sockit.default';

    private string $surfaceKind = 'sockit.wall';

    private string $hostType = 'board';

    private string $hostId = '1';

    private int $incarnation = 1;

    public static function make(): self
    {
        return new self;
    }

    public function panel(string $panel): self
    {
        $this->panel = $panel;

        return $this;
    }

    public function surface(string $kind, string $hostType = 'board', string $hostId = '1', int $incarnation = 1): self
    {
        $this->surfaceKind = $kind;
        $this->hostType = $hostType;
        $this->hostId = $hostId;
        $this->incarnation = $incarnation;

        return $this;
    }

    public function surfaceRef(): SurfaceRef
    {
        return new SurfaceRef(
            panel: new PanelId($this->panel),
            kind: new SurfaceKindId($this->surfaceKind),
            host: new HostEntityRef($this->hostType, $this->hostId),
            incarnation: new Incarnation($this->incarnation),
        );
    }

    public function actor(string $id = '1', string $type = 'user'): ActorRef
    {
        return new ActorRef($type, $id);
    }

    public function commandKey(?string $prefix = null): CommandKey
    {
        return new CommandKey(($prefix !== null ? $prefix.'-' : '').Ids::uuid7());
    }

    public function publishPost(
        string $body = 'Hello world',
        ?AudienceSpec $audience = null,
        ?CommandKey $key = null,
        bool $asDraft = false,
    ): PublishPostCommand {
        return new PublishPostCommand(
            key: $key ?? $this->commandKey('post'),
            surface: $this->surfaceRef(),
            body: $body,
            audience: $audience,
            asDraft: $asDraft,
        );
    }

    public function addReaction(
        HostEntityRef $target,
        string $reactionType = 'like',
        ?CommandKey $key = null,
    ): AddReactionCommand {
        return new AddReactionCommand(
            key: $key ?? $this->commandKey('react'),
            surface: $this->surfaceRef(),
            target: $target,
            reactionType: $reactionType,
        );
    }

    public function createGroup(
        string $slug,
        string $title = 'Sample Group',
        GroupVisibility $visibility = GroupVisibility::Private,
        ?CommandKey $key = null,
    ): CreateGroupCommand {
        return new CreateGroupCommand(
            key: $key ?? $this->commandKey('group'),
            surface: $this->surfaceRef(),
            slug: $slug,
            title: $title,
            visibility: $visibility,
        );
    }
}
