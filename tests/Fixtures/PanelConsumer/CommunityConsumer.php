<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\PanelConsumer;

use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

/** Standalone Composer installation with two generated production social panels. */
final class CommunityConsumer
{
    private SocialConsumer $base;

    public function __construct()
    {
        $this->base = new SocialConsumer;
    }

    public function run(): void
    {
        $c = $this->base;
        $config = $c->consumer.'/config/sockit.php';
        file_put_contents($c->consumer.'/config/social.php', "<?php return ['posts_offered' => true];\n");
        file_put_contents($config, <<<'CONFIG'
<?php
return ['panels' => [], 'modules' => ['enabled' => ['sockit.posts', 'sockit.reactions'], 'settings' => ['sockit.posts' => ['feed_keys' => ['current' => 'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE='], 'feed_key' => 'current']]],
    'privacy' => ['floor' => ['allowed_scopes' => ['direct', 'contour'], 'default_scope' => 'direct', 'max_scope' => 'contour', 'minor_default_scope' => 'direct', 'minor_max_scope' => 'direct', 'contour_levels' => ['class']]],
    'surfaces' => [], 'resolvers' => ['actor' => \Acme\Community\Host::class, 'tenant' => \Acme\Runtime\Tenant::class,
        'contour' => \Acme\Community\Host::class, 'groups' => \Acme\Runtime\Host::class,
        'surface_policy' => \Acme\Community\Host::class, 'targets' => \Acme\Social\Target::class,
        'authorizer' => \Acme\Runtime\AuthorityRouter::class, 'audience' => \SocKit\Audience\AudienceEvaluator::class],
    'storage' => ['deployments' => ['owner_a' => \Acme\PgTarget::fromEnvironment()->deployment(), 'owner_b' => \Acme\PgTarget::fromEnvironment()->deployment()]]];
CONFIG);
        foreach (['Community' => 'community', 'Neighbor' => 'neighbor'] as $name => $slug) {
            $result = $c->json([PHP_BINARY, 'artisan', 'sockit:make-panel', $name, '--id=acme.'.$slug, '--host=class', '--modules=sockit.posts,sockit.reactions', '--prefix='.$slug.'_', '--connection=owner_a']);
            expect($result['status'])->toBe('installed');
        }
        $source = (string) file_get_contents($config);
        $source = str_replace("'panels' => []", "'panels' => [\\Acme\\Panels\\CommunityPanelProvider::class, \\Acme\\Panels\\NeighborPanelProvider::class]", $source);
        $source = str_replace("'surfaces' => []", "'surfaces' => [\\Acme\\Community\\CommunitySurface::class, \\Acme\\Community\\NeighborSurface::class]", $source);
        file_put_contents($config, $source);
        $c->artisan(['config:cache']);
        foreach (['community', 'neighbor'] as $slug) {
            $snapshot = $c->json([PHP_BINARY, 'artisan', 'sockit:panel:migrations', 'acme.'.$slug]);
            expect($snapshot['status'])->toBe('generated');
            $c->artisan(['migrate', '--database=owner_a', '--path='.Scenario::text($snapshot['migration']), '--force']);
        }
        // The SocialConsumer constructor proved both private database identities before DDL.
        $c->owner->exec('CREATE TABLE public.driver_envelope_authority (allowed boolean NOT NULL, generation bigint NOT NULL DEFAULT 1)');
        $c->owner->exec('INSERT INTO public.driver_envelope_authority (allowed) VALUES (true)');
        $c->owner->exec('CREATE TABLE public.driver_p48_targets (type text NOT NULL, id text NOT NULL, incarnation bigint NOT NULL, generation text NOT NULL, visible boolean NOT NULL, PRIMARY KEY(type,id))');
        $c->owner->exec("INSERT INTO public.driver_p48_targets VALUES ('class','42',1,'1',true)");
        foreach (['community', 'neighbor'] as $slug) {
            foreach (['posts', 'post_revisions', 'reactions', 'reaction_counts', 'receipts', 'outbox'] as $table) {
                expect(PgTarget::query($c->owner, "SELECT to_regclass('public.driver_{$slug}_{$table}')")->fetchColumn())->not->toBeNull();
            }
        }
        $result = $c->json([PHP_BINARY, 'community.php']);
        expect($result['complete'])->toBeTrue()->and($result['checks'])->toBe(['generated_panels', 'teacher_publish', 'lost_response', 'observer_read_only', 'student_reaction', 'same_id_panel_isolation', 'service_on_behalf', 'author_revoke', 'privacy_shrink', 'consent_failed_hide_retry', 'archive', 'delete_late_replay', 'pending_held_restore', 'promotion_cas', 'projection_rebuild']);
        expect(PgTarget::query($c->owner, 'SELECT count(*) FROM public.driver_neighbor_posts')->fetchColumn())->toBe(1);
        expect(PgTarget::query($c->owner, 'SELECT count(*) FROM public.driver_neighbor_reactions')->fetchColumn())->toBe(0);
        expect(PgTarget::query($c->decoy, "SELECT count(*) FROM pg_tables WHERE schemaname='public'")->fetchColumn())->toBe(0);
    }

    public function cleanup(): void
    {
        $this->base->cleanup();
    }
}
