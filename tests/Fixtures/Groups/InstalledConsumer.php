<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use SocKit\Tests\Fixtures\PanelConsumer\SocialConsumer;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

/** Composer installs a non-symlink package before any generated panel is booted. */
final class InstalledConsumer
{
    private SocialConsumer $base;

    public function __construct()
    {
        $this->base = new SocialConsumer;
    }

    public function run(): void
    {
        $c = $this->base;
        $root = $c->consumer;
        file_put_contents($root.'/bootstrap/providers.php', "<?php return [\\Acme\\Groups\\Provider::class];\n");
        file_put_contents($root.'/config/social.php', "<?php return ['posts_offered' => true];\n");
        file_put_contents($root.'/config/groups.php', "<?php return ['consumer' => false, 'content' => true];\n");
        $config = <<<'CONFIG'
<?php
return ['panels' => [], 'modules' => ['enabled' => ['sockit.groups', 'sockit.posts', 'sockit.reactions'], 'settings' => ['sockit.posts' => ['feed_keys' => ['current' => 'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE='], 'feed_key' => 'current']]],
    'privacy' => ['floor' => ['allowed_scopes' => ['direct', 'group'], 'default_scope' => 'direct', 'max_scope' => 'group', 'minor_default_scope' => 'direct', 'minor_max_scope' => 'direct', 'contour_levels' => []]],
    'surfaces' => [], 'resolvers' => ['actor' => \Acme\Groups\Host::class, 'tenant' => \Acme\Groups\Tenant::class,
        'contour' => \Acme\Groups\Host::class, 'groups' => \Acme\Runtime\Host::class, 'surface_policy' => \Acme\Groups\Host::class,
        'targets' => \Acme\Groups\Target::class, 'authorizer' => \Acme\Groups\Authority::class, 'audience' => \SocKit\Groups\GroupAudienceResolver::class],
    'storage' => ['deployments' => ['owner_a' => \Acme\PgTarget::fromEnvironment()->deployment(), 'owner_b' => \Acme\PgTarget::fromEnvironment()->deployment()]]];
CONFIG;
        file_put_contents($root.'/config/sockit.php', $config);
        foreach (['Article' => 'article', 'Product' => 'product'] as $name => $slug) {
            $result = $c->json([PHP_BINARY, 'artisan', 'sockit:make-panel', $name, '--id=acme.'.$slug, '--host='.$slug, '--modules=sockit.groups,sockit.posts,sockit.reactions', '--prefix='.$slug.'_', '--connection=owner_a']);
            expect($result['status'])->toBe('installed');
            $path = $root.'/app/Panels/'.$name.'PanelProvider.php';
            file_put_contents($path, str_replace("->hostTypes('".$slug."')", "->hostTypes('".$slug."', 'sockit.group')", (string) file_get_contents($path)));
        }
        $config = str_replace("'panels' => []", "'panels' => [\\Acme\\Panels\\ArticlePanelProvider::class, \\Acme\\Panels\\ProductPanelProvider::class]", $config);
        $config = str_replace("'surfaces' => []", "'surfaces' => [\\Acme\\Groups\\ArticleManagement::class, \\Acme\\Groups\\ProductManagement::class, \\Acme\\Groups\\ArticleContent::class, \\Acme\\Groups\\ProductContent::class]", $config);
        file_put_contents($root.'/config/sockit.php', $config);
        $c->artisan(['config:cache']);
        foreach (['article', 'product'] as $slug) {
            $migration = $c->json([PHP_BINARY, 'artisan', 'sockit:panel:migrations', 'acme.'.$slug]);
            expect($migration['status'])->toBe('generated');
            $c->artisan(['migrate', '--database=owner_a', '--path='.Scenario::text($migration['migration']), '--force']);
        }
        // SocialConsumer proves the exact invocation-owned DB identity before these host writes.
        $c->owner->exec('CREATE TABLE public.driver_envelope_authority (allowed boolean NOT NULL, generation bigint NOT NULL DEFAULT 1)');
        $c->owner->exec('INSERT INTO public.driver_envelope_authority (allowed) VALUES (true)');
        $c->owner->exec('CREATE TABLE public.driver_p48_targets (type text NOT NULL, id text NOT NULL, incarnation bigint NOT NULL, generation text NOT NULL, visible boolean NOT NULL, PRIMARY KEY(type,id))');
        $c->owner->exec("INSERT INTO public.driver_p48_targets VALUES ('article','42',1,'1',true),('product','42',1,'1',true)");
        $result = $c->json([PHP_BINARY, 'groups.php', 'content']);
        expect($result['complete'])->toBeTrue();
        expect(PgTarget::query($c->decoy, "SELECT count(*) FROM pg_tables WHERE schemaname='public'")->fetchColumn())->toBe(0);
    }

    public function groupsOnly(): void
    {
        $c = $this->base;
        $root = $c->consumer;
        file_put_contents($root.'/bootstrap/providers.php', "<?php return [\\Acme\\Groups\\OnlyProvider::class];\n");
        file_put_contents($root.'/config/sockit.php', <<<'CONFIG'
<?php
return ['panels' => [], 'modules' => ['enabled' => ['sockit.groups']], 'surfaces' => [],
    'resolvers' => ['actor' => \Acme\Groups\Host::class, 'tenant' => \Acme\Groups\Tenant::class,
        'contour' => \Acme\Groups\Host::class, 'groups' => \Acme\Runtime\Host::class,
        'surface_policy' => \Acme\Groups\Host::class, 'targets' => \Acme\Groups\Target::class,
        'authorizer' => \Acme\Groups\Authority::class, 'audience' => \SocKit\Groups\GroupAudienceResolver::class],
    'storage' => ['deployments' => ['owner_a' => \Acme\PgTarget::fromEnvironment()->deployment()]]];
CONFIG);
        $generated = $c->json([PHP_BINARY, 'artisan', 'sockit:make-panel', 'Article', '--id=acme.article', '--host=article', '--modules=sockit.groups', '--prefix=article_', '--connection=owner_a']);
        expect($generated['status'])->toBe('installed');
        $configPath = $root.'/config/sockit.php';
        $config = (string) file_get_contents($configPath);
        $config = str_replace("'panels' => []", "'panels' => [\\Acme\\Panels\\ArticlePanelProvider::class]", $config);
        $config = str_replace("'surfaces' => []", "'surfaces' => [\\Acme\\Groups\\ArticleManagement::class]", $config);
        file_put_contents($configPath, $config);
        $c->artisan(['config:cache']);
        $migration = $c->json([PHP_BINARY, 'artisan', 'sockit:panel:migrations', 'acme.article']);
        $c->artisan(['migrate', '--database=owner_a', '--path='.Scenario::text($migration['migration']), '--force']);
        $c->owner->exec('CREATE TABLE public.driver_envelope_authority (allowed boolean NOT NULL, generation bigint NOT NULL DEFAULT 1)');
        $c->owner->exec('INSERT INTO public.driver_envelope_authority (allowed) VALUES (true)');
        $c->owner->exec('CREATE TABLE public.driver_p48_targets (type text NOT NULL, id text NOT NULL, incarnation bigint NOT NULL, generation text NOT NULL, visible boolean NOT NULL, PRIMARY KEY(type,id))');
        $c->owner->exec("INSERT INTO public.driver_p48_targets VALUES ('article','42',1,'1',true)");
        foreach (['posts', 'post_revisions', 'reactions', 'reaction_counts'] as $table) {
            expect(PgTarget::query($c->owner, "SELECT to_regclass('public.driver_article_{$table}')")->fetchColumn())->toBeNull();
        }
        expect($c->json([PHP_BINARY, 'app/Groups/only.php']))->toBe(['membership' => 'active']);
        expect(PgTarget::query($c->decoy, "SELECT count(*) FROM pg_tables WHERE schemaname='public'")->fetchColumn())->toBe(0);
    }

    public function cleanup(): void
    {
        $this->base->cleanup();
    }
}
