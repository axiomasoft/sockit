<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups;

use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Laravel\PluginRegistrar;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Modules\Groups\Contracts\GroupService;
use SocKit\Modules\Groups\Database\GroupsSchemaSetProvider;
use SocKit\Modules\Groups\Internal\DatabaseGroupMembershipResolver;
use SocKit\Modules\Groups\Internal\DatabaseGroupService;
use SocKit\Modules\Groups\Internal\GroupTargetResolver;
use SocKit\Surfaces\Contracts\TargetResolver;

/**
 * Optional code plugin adding panel-isolated Groups, live membership resolution, and TargetResolver contribution.
 */
#[Api(since: '1.0')]
final class GroupsPlugin implements ExtensionPlugin, WithCapabilities, WithSchema, WithSlots
{
    use ContributesToSlots;
    use OwnsSchema;
    use ProvidesCapabilities;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.sockit.groups',
            version: '1.0.0',
            requires: ['axiomasoft.sockit.core'],
            resourceRoot: dirname(__DIR__, 3),
            databases: ['pgsql', 'sqlite'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    #[Override]
    public function tablePrefix(): string
    {
        return 'sk_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return ['groups', 'group_members'];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 3).'/database/migrations/groups';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [GroupsSchemaSetProvider::class];
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            GroupService::class => Cardinality::RequiredSingle->value,
        ];
    }

    /** @return array<class-string, class-string|array{config: string, default?: class-string|null}> */
    #[Override]
    public function providedCapabilities(): array
    {
        return [
            GroupService::class => DatabaseGroupService::class,
            GroupMembershipResolver::class => [
                'config' => 'sockit.resolvers.groups',
                'default' => DatabaseGroupMembershipResolver::class,
            ],
        ];
    }

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    #[Override]
    public function slotContributions(): array
    {
        return [
            [
                'slot' => TargetResolver::class,
                'id' => 'sockit.groups.target',
                'implementation' => GroupTargetResolver::class,
                'priority' => 60,
                'key' => 'group',
            ],
        ];
    }
}
