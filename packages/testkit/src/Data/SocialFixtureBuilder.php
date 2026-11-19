<?php

declare(strict_types=1);

namespace SocKit\Testkit\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Groups\Data\GroupCommand;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Panels\Data\PanelId;
use SocKit\Posts\Data\PostCommand;
use SocKit\Reactions\Data\ReactionCommand;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Ids;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;

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

    public function publishPost(string $body = 'Hello world', ?AudienceSpec $audience = null, bool $asDraft = false): PostCommand
    {
        return new PostCommand(surface: $this->surfaceRef(), body: $body, audience: $audience, publish: ! $asDraft);
    }

    public function addReaction(TargetRef $target, string $kind = 'like'): ReactionCommand
    {
        return new ReactionCommand(surface: $this->surfaceRef(), target: $target, kind: $kind);
    }

    public function createGroup(string $name, JoinPolicy $joinPolicy = JoinPolicy::Closed): GroupCommand
    {
        return new GroupCommand(joinPolicy: $joinPolicy, name: $name);
    }
}
