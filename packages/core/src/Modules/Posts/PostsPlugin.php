<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts;

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
use SocKit\Modules\Posts\Contracts\FeedService;
use SocKit\Modules\Posts\Contracts\PostService;
use SocKit\Modules\Posts\Database\PostsSchemaSetProvider;
use SocKit\Modules\Posts\Internal\DatabaseFeedService;
use SocKit\Modules\Posts\Internal\DatabasePostService;
use SocKit\Modules\Posts\Internal\PostTargetResolver;
use SocKit\Surfaces\Contracts\TargetResolver;

/**
 * Optional code plugin adding panel-isolated Posts, keyset feeds, CAS revisions, and a TargetResolver contribution.
 */
#[Api(since: '1.0')]
final class PostsPlugin implements ExtensionPlugin, WithCapabilities, WithSchema, WithSlots
{
    use ContributesToSlots;
    use OwnsSchema;
    use ProvidesCapabilities;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.sockit.posts',
            version: '1.0.0',
            requires: ['axiomasoft.sockit.core'],
            optional: ['axiomasoft.sockit.reactions'],
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
        return ['posts', 'post_revisions'];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 3).'/database/migrations/posts';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [PostsSchemaSetProvider::class];
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            PostService::class => Cardinality::RequiredSingle->value,
            FeedService::class => Cardinality::RequiredSingle->value,
        ];
    }

    /** @return array<class-string, class-string> */
    #[Override]
    public function providedCapabilities(): array
    {
        return [
            PostService::class => DatabasePostService::class,
            FeedService::class => DatabaseFeedService::class,
        ];
    }

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    #[Override]
    public function slotContributions(): array
    {
        return [
            [
                'slot' => TargetResolver::class,
                'id' => 'sockit.posts.target',
                'implementation' => PostTargetResolver::class,
                'priority' => 50,
                'key' => 'post',
            ],
        ];
    }
}
