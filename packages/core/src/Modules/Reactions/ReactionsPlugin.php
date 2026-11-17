<?php

declare(strict_types=1);

namespace SocKit\Modules\Reactions;

use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Laravel\PluginRegistrar;
use SocKit\Modules\Reactions\Contracts\ReactionService;
use SocKit\Modules\Reactions\Database\ReactionsSchemaSetProvider;
use SocKit\Modules\Reactions\Internal\DatabaseReactionService;
use SocKit\Surfaces\Contracts\TargetResolver;

/**
 * Optional code plugin adding panel-isolated reactions via the {@see TargetResolver} slot.
 */
#[Api(since: '1.0')]
final class ReactionsPlugin implements ExtensionPlugin, WithCapabilities, WithSchema
{
    use OwnsSchema;
    use ProvidesCapabilities;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.sockit.reactions',
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
        return ['reactions', 'reaction_counts'];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 3).'/database/migrations/reactions';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [ReactionsSchemaSetProvider::class];
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            ReactionService::class => Cardinality::RequiredSingle->value,
        ];
    }

    /** @return array<class-string, class-string> */
    #[Override]
    public function providedCapabilities(): array
    {
        return [
            ReactionService::class => DatabaseReactionService::class,
        ];
    }
}
