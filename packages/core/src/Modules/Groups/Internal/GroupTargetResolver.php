<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Internal;

use Override;
use Packman\Attributes\Internal;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\GroupRef;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Modules\Groups\Enums\GroupVisibility;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetResolution;

/**
 * TargetResolver slot handler contributed by GroupsPlugin for `group:*` targets.
 */
#[Internal]
final readonly class GroupTargetResolver implements TargetResolver
{
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
    ) {}

    #[Override]
    public function supports(HostEntityRef $target): bool
    {
        return $target->type === 'group';
    }

    #[Override]
    public function resolve(ActorContext $actor, SurfaceRef $surface, HostEntityRef $target): TargetResolution
    {
        if (! $this->panels->has($surface->panel) || ! $this->panels->get($surface->panel)->hasModule('groups')) {
            return TargetResolution::missing($target, 'group.module_disabled');
        }

        $connection = $this->tables->connection($surface->panel);
        $groupsTable = $this->tables->table('groups', $surface->panel);

        $row = $connection->table($groupsTable)
            ->where('panel_id', $surface->panel->value)
            ->where('id', $target->value)
            ->first();

        if (! is_object($row)) {
            return TargetResolution::missing($target, 'group.not_found');
        }

        if (SockitRows::str($row, 'state') !== 'active') {
            return TargetResolution::hidden($target, 'group.inactive');
        }

        $visibility = GroupVisibility::from(SockitRows::str($row, 'visibility'));
        $groupId = SockitRows::str($row, 'id');
        $audience = $visibility === GroupVisibility::Public
            ? AudienceSpec::platform()
            : AudienceSpec::group(new GroupRef($groupId));

        return TargetResolution::available(
            target: $target,
            owner: new ActorRef(SockitRows::str($row, 'created_by_type'), SockitRows::str($row, 'created_by_id')),
            audience: $audience,
            privacyRevision: new Revision(SockitRows::int($row, 'revision', 1)),
        );
    }
}
