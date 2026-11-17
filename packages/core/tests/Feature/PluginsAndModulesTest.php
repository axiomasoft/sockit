<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Data\Plugin\PluginDescription;
use Packman\Plugins\Testing\PluginGraph;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Core\Tests\Fixtures\L0ArticleSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PanelL0Provider;
use SocKit\Modules\Groups\GroupsPlugin;
use SocKit\Modules\Posts\PostsPlugin;
use SocKit\Modules\Reactions\Contracts\ReactionService;
use SocKit\Modules\Reactions\Data\AddReactionCommand;
use SocKit\Modules\Reactions\Data\RemoveReactionCommand;
use SocKit\Modules\Reactions\ReactionsPlugin;
use SocKit\Panels\Data\PanelId;
use SocKit\Plugins\SockitCorePlugin;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Testkit\SocKitTestCase;

final class PluginsAndModulesTest extends SocKitTestCase
{
    /** @return list<string> */
    protected function enabledSockitModules(): array
    {
        return ['reactions'];
    }

    /** @return list<class-string> */
    protected function sockitPanelProviders(): array
    {
        return [PanelL0Provider::class];
    }

    /** @return list<class-string> */
    protected function sockitSurfaceProviders(): array
    {
        return [L0ArticleSurfaceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function sockitPrivacyFloor(): array
    {
        return [
            'allowed_scopes' => ['direct', 'platform'],
            'default_scope' => 'platform',
            'max_scope' => 'platform',
            'minor_default_scope' => 'direct',
            'minor_max_scope' => 'platform',
            'contour_levels' => [],
        ];
    }

    public function test_all_built_in_plugins_compile_cleanly_as_stateless_code_plugins(): void
    {
        $registry = PluginGraph::compile($this->app, [
            SockitCorePlugin::class,
            ReactionsPlugin::class,
            PostsPlugin::class,
            GroupsPlugin::class,
        ]);

        $ids = array_map(static fn (PluginDescription $d): string => $d->id, $registry->describe());

        self::assertSame([
            'axiomasoft.sockit.core',
            'axiomasoft.sockit.groups',
            'axiomasoft.sockit.reactions',
            'axiomasoft.sockit.posts',
        ], $ids);
    }

    public function test_disabled_modules_contribute_zero_tables_and_l0_reactions_work_on_host_targets(): void
    {
        $this->migrateSockitSchema();

        $inspector = $this->app->make(PluginInspector::class);
        $activeIds = array_map(static fn (PluginDescription $d): string => $d->id, $inspector->describe());
        self::assertSame(['axiomasoft.sockit.core', 'axiomasoft.sockit.reactions'], $activeIds);

        self::assertTrue(Schema::hasTable('sk_surface_instances'));
        self::assertTrue(Schema::hasTable('sk_reactions'));
        self::assertTrue(Schema::hasTable('sk_reaction_counts'));
        self::assertFalse(Schema::hasTable('sk_posts'));
        self::assertFalse(Schema::hasTable('sk_post_revisions'));
        self::assertFalse(Schema::hasTable('sk_groups'));
        self::assertFalse(Schema::hasTable('sk_group_members'));

        $ownership = $this->app->make(SchemaOwnership::class);
        self::assertSame('axiomasoft.sockit.reactions', $ownership->ownerOf('reactions'));
        self::assertNull($ownership->ownerOf('posts'));
        self::assertNull($ownership->ownerOf('groups'));

        $fake = $this->installFakeSocKit();
        $target = new HostEntityRef('article', 'art-42');
        $fake->targets->register($target, new ActorRef('user', '1'), AudienceSpec::platform());

        $surface = new SurfaceRef(
            panel: new PanelId('sockit.l0'),
            kind: new SurfaceKindId('host.article'),
            host: $target,
            incarnation: Incarnation::initial(),
        );

        $reactions = $this->app->make(ReactionService::class);
        $res = $reactions->add(new AddReactionCommand(
            key: new CommandKey('cmd-l0-react-000001'),
            surface: $surface,
            target: $target,
            reactionType: 'like',
        ));

        self::assertTrue($res->isSuccess());
        $counts = $reactions->counts($surface, $target);
        self::assertSame(['like' => 1], $counts->counts);
        self::assertSame(1, $counts->total);

        $reactions->remove(new RemoveReactionCommand(
            key: new CommandKey('cmd-l0-react-000002'),
            surface: $surface,
            target: $target,
            reactionType: 'like',
        ));

        $afterRemove = $reactions->counts($surface, $target);
        self::assertSame([], $afterRemove->counts);
        self::assertSame(0, $afterRemove->total);
    }
}
