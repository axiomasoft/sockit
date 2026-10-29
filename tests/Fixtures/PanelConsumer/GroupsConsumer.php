<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\PanelConsumer;

use PDO;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

/** Reuses the actual Composer install/source verification and populated L0 consumer. */
final class GroupsConsumer
{
    public readonly SocialConsumer $base;

    /** @var array<string, string> */
    private array $frozen = [];

    /** @var array<string, string> */
    private array $upgrades = [];

    /** @var array<string, string> */
    private array $resources = [];

    public function __construct()
    {
        $this->base = new SocialConsumer;
    }

    private function write(string $path, string $source): void
    {
        if (file_put_contents($this->base->consumer.'/'.$path, $source) !== strlen($source)) {
            throw new \RuntimeException('Cannot write owned consumer file');
        }
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->base->consumer.'/'.$path);
    }

    /** @return array<string, string> */
    private function tree(): array
    {
        $hashes = [];
        foreach (['app', 'database'] as $directory) {
            if (! is_dir($this->base->consumer.'/'.$directory)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base->consumer.'/'.$directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $hashes[substr($file->getPathname(), strlen($this->base->consumer) + 1)] = (string) hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($hashes);

        return $hashes;
    }

    private function assertFrozen(): void
    {
        $current = $this->tree();
        foreach ($this->frozen as $path => $hash) {
            expect($current[$path])->toBe($hash, $path);
        }
        fwrite(STDERR, 'P5.6 frozen/custom hashes='.json_encode($this->frozen, JSON_THROW_ON_ERROR).PHP_EOL);
    }

    private function cache(): void
    {
        $path = $this->base->consumer.'/bootstrap/cache/config.php';
        if (is_file($path) && ! unlink($path)) {
            throw new \RuntimeException('Cannot remove owned stale config cache');
        }
        $this->base->artisan(['config:clear']);
        $before = $this->base->json([PHP_BINARY, 'groups.php', 'boot']);
        $this->base->artisan(['config:cache']);
        expect($this->base->json([PHP_BINARY, 'groups.php', 'boot']))->toBe($before)
            ->and($before['queries'])->toBe(0)->and($before['connections'])->toBe(0)->and($before['default'])->toBe('decoy');
    }

    public function prepare(): void
    {
        $this->base->generate();
        $this->write('bootstrap/providers.php', "<?php return [\\Acme\\Groups\\Provider::class];\n");
        $this->write('config/sockit.php', str_replace(['\\Acme\\Social\\Policy::class', '\\SocKit\\Audience\\AudienceEvaluator::class'],
            ['\\Acme\\Groups\\Policy::class', '\\SocKit\\Groups\\GroupAudienceResolver::class'], $this->read('config/sockit.php')));
        $this->cache();
        $this->base->migrate();
        foreach (['article', 'product'] as $slug) {
            foreach (['groups', 'group_members'] as $entity) {
                expect(PgTarget::query($this->base->owner, "SELECT to_regclass('public.driver_{$slug}_{$entity}')")->fetchColumn())->toBeNull();
            }
        }
        $this->base->smoke();
        // Same physical DB via owner_b: an alias must not bypass the existing table map.
        $before = $this->tree();
        $this->base->artisan(['sockit:make-panel', 'Alias', '--id=acme.alias', '--host=article', '--modules=sockit.reactions', '--prefix=article_', '--connection=owner_b'], expected: 1);
        expect($this->tree())->toBe($before);
        fwrite(STDERR, "P5.6 offered but disabled fresh groups: no tables; populated reactions; physical connection alias rejected without writes\n");
    }

    public function upgrade(): void
    {
        $states = ['article' => $this->base->state('article'), 'product' => $this->base->state('product')];
        $this->write('config/sockit.php', str_replace("'enabled' => ['sockit.reactions']", "'enabled' => ['sockit.reactions', 'sockit.groups', 'acme.runtime']", $this->read('config/sockit.php')));
        $this->write('config/groups.php', "<?php return ['consumer' => true];\n");
        foreach (['Article', 'Product'] as $name) {
            $provider = 'app/Panels/'.$name.'PanelProvider.php';
            $this->write($provider, str_replace("->modules('sockit.reactions')", "->modules('sockit.reactions', 'sockit.groups', 'acme.runtime')", $this->read($provider))."\n// P5.6 consumer provider marker\n");
            $surface = 'app/Social/'.$name.'Surface.php';
            $this->write($surface, str_replace("->features('reactions')", "->features('reactions', 'groups')", $this->read($surface)));
        }
        $this->cache();
        $this->frozen = $this->tree();
        foreach (['article', 'product'] as $slug) {
            $args = [PHP_BINARY, 'artisan', 'sockit:panel:migrations', 'acme.'.$slug, '--upgrade'];
            $before = $this->tree();
            $dry = $this->base->json([...$args, '--dry-run']);
            expect($dry['status'])->toBe('dry_run')->and($this->tree())->toBe($before);
            $result = $this->base->json($args);
            expect($result['status'])->toBe('generated')->and(array_keys(Scenario::object($result['models'])))->toEqualCanonicalizing([
                'groups', 'group_members', 'outbox', 'reaction_counts', 'reactions', 'receipts', 'surface_instances', 'tombstones']);
            expect(Scenario::entries($result['files']))->toHaveCount(4);
            $this->upgrades[$slug] = Scenario::text($result['migration']);
            $this->assertFrozen();
            $this->base->artisan(['migrate', '--database=owner_'.($slug === 'article' ? 'a' : 'b'), '--path='.$this->upgrades[$slug], '--force']);
            expect($this->base->json($args)['status'])->toBe('no_op');
        }
        expect($this->base->state('article'))->toBe($states['article'])->and($this->base->state('product'))->toBe($states['product']);
        // The generated Group is consumer-owned too; subsequent commands must preserve its custom bytes.
        $boot = $this->base->json([PHP_BINARY, 'groups.php', 'boot']);
        $models = Scenario::object(Scenario::object($boot['models'])['acme.article']);
        $class = Scenario::text($models['groups']);
        $relative = 'app/'.str_replace('\\', '/', substr($class, strlen('Acme\\'))).'.php';
        $this->write($relative, $this->read($relative)."\n// P5.6 consumer custom group marker\n");
        $this->cache();
        $this->generationFailure();
        $this->frozen = $this->tree();
        foreach (['article', 'product'] as $slug) {
            expect($this->base->json([PHP_BINARY, 'artisan', 'sockit:panel:migrations', 'acme.'.$slug, '--upgrade'])['status'])->toBe('no_op');
        }
        $this->assertFrozen();
    }

    private function generationFailure(): void
    {
        $args = [PHP_BINARY, 'artisan', 'sockit:make-panel', 'Clubs', '--id=acme.clubs', '--host=club',
            '--modules=sockit.groups', '--prefix=clubs_', '--connection=owner_a'];
        $before = $this->tree();
        $directory = $this->base->consumer.'/app/Models/SocKit';
        if (! chmod($directory, 0500)) {
            throw new \RuntimeException('Cannot set owned filesystem failure boundary');
        }
        try {
            $failed = $this->base->json($args, expected: 1);
            expect($failed['problem'])->toBe('GENERATION_WRITE_FAILED')->and($failed['recovery'])->toBe([])
                ->and($this->tree())->toBe($before);
        } finally {
            if (! chmod($directory, 0755)) {
                throw new \RuntimeException('Cannot restore owned filesystem permissions');
            }
        }
        $generated = $this->base->json($args);
        expect($generated['status'])->toBe('installed')->and(Scenario::object($generated['models']))->toHaveKeys(['groups', 'group_members']);
        $provider = 'app/Panels/ClubsPanelProvider.php';
        $this->write($provider, $this->read($provider)."\n// P5.6 recovered consumer provider marker\n");
        $custom = $this->tree();
        $again = $this->base->json($args, expected: 1);
        expect($again['problem'])->toBe('GENERATION_FILE_EXISTS')->and($this->tree())->toBe($custom);
        fwrite(STDERR, "P5.6 actual groups make-panel: partial permission failure rolled back provider, exact recovery=[] and unchanged tree; retry installs group/member models; conflict preserves consumer marker\n");
    }

    public function smoke(): void
    {
        $result = $this->base->json([PHP_BINARY, 'groups.php', 'smoke']);
        expect($result['assertions'])->toBe(['public_create_join_read', 'no_creator_membership', 'current_host_admission', 'panel_isolation', 'models_casts_relations', 'worker_A_B_A']);
        $this->resources = array_map(Scenario::text(...), Scenario::object($result['resources']));
        foreach (Scenario::entries($result['queue']) as $entry) {
            $job = Scenario::object($entry);
            $restored = $this->base->json([PHP_BINARY, 'groups.php', 'restore', Scenario::text($job['payload'])]);
            unset($job['payload']);
            expect($restored)->toBe($job);
        }
        expect(PgTarget::query($this->base->decoy, "SELECT count(*) FROM pg_tables WHERE schemaname='public'")->fetchColumn())->toBe(0);
    }

    /** @return array<string, string> */
    private function groupsState(string $slug): array
    {
        $state = [];
        foreach (['groups', 'group_members'] as $entity) {
            $state[$entity] = Scenario::text(PgTarget::query($this->base->owner, "SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text)::text,'[]') FROM public.driver_{$slug}_{$entity} t")->fetchColumn());
        }

        return $state;
    }

    public function disableAndReenable(): void
    {
        $article = $this->groupsState('article');
        $product = [$this->groupsState('product'), $this->base->state('product')];
        $provider = 'app/Panels/ArticlePanelProvider.php';
        $original = $this->read($provider);
        $config = $this->read('config/sockit.php');
        $envelopes = PgTarget::query($this->base->owner, 'SELECT id,envelope FROM public.driver_article_outbox ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $pending = PgTarget::query($this->base->owner, "SELECT count(*) FROM public.driver_article_outbox WHERE deliveries::jsonb->'acme.inbox'->>'status'='pending'")->fetchColumn();
        expect($pending)->toBe(2);
        // Disable globally as well as on A. B remains present with reactions, its stored groups are retained.
        foreach (['Article', 'Product'] as $name) {
            $path = 'app/Panels/'.$name.'PanelProvider.php';
            $this->write($path, str_replace("->modules('sockit.reactions', 'sockit.groups', 'acme.runtime')", "->modules('sockit.reactions')", $this->read($path)));
            $surface = 'app/Social/'.$name.'Surface.php';
            $this->write($surface, str_replace("->features('reactions', 'groups')", "->features('reactions')", $this->read($surface)));
        }
        $this->write('config/sockit.php', str_replace("'enabled' => ['sockit.reactions', 'sockit.groups', 'acme.runtime']", "'enabled' => ['sockit.reactions']", $config));
        $this->write('config/groups.php', "<?php return ['consumer' => false];\n");
        $this->cache();
        $disabledFiles = $this->tree();
        expect($this->base->json([PHP_BINARY, 'artisan', 'sockit:panel:migrations', 'acme.article', '--upgrade'])['status'])->toBe('no_op')
            ->and($this->tree())->toBe($disabledFiles);
        expect($this->base->json([PHP_BINARY, 'groups.php', 'disabled', $this->resources['article']]))->toBe(['membership_denied' => true, 'quarantined' => 2]);
        expect($this->groupsState('article'))->toBe($article)->and([$this->groupsState('product'), $this->base->state('product')])->toBe($product)
            ->and(PgTarget::query($this->base->owner, 'SELECT id,envelope FROM public.driver_article_outbox ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR))->toBe($envelopes);
        expect(PgTarget::query($this->base->owner, "SELECT count(*) FROM public.driver_article_outbox WHERE deliveries::jsonb->'acme.inbox'->>'status'='quarantine' AND deliveries::jsonb->'acme.inbox'->>'reason'='outbox.consumer_unavailable'")->fetchColumn())->toBe(2);
        $this->write($provider, $original);
        $path = 'app/Panels/ProductPanelProvider.php';
        $this->write($path, str_replace("->modules('sockit.reactions')", "->modules('sockit.reactions', 'sockit.groups', 'acme.runtime')", $this->read($path)));
        $this->write('config/sockit.php', $config);
        $this->write('config/groups.php', "<?php return ['consumer' => true];\n");
        foreach (['Article', 'Product'] as $name) {
            $surface = 'app/Social/'.$name.'Surface.php';
            $this->write($surface, str_replace("->features('reactions')", "->features('reactions', 'groups')", $this->read($surface)));
        }
        $this->cache();
        expect($this->base->json([PHP_BINARY, 'groups.php', 'reenabled', $this->resources['article']]))->toBe(['preserved' => true]);
        expect($this->base->json([PHP_BINARY, 'artisan', 'sockit:panel:migrations', 'acme.article', '--upgrade'])['status'])->toBe('no_op');
        $this->assertFrozen();
        expect($this->groupsState('article'))->toBe($article);
        fwrite(STDERR, "P5.6 disabled membership denied; group data/history/envelopes retained; two real pending events durably quarantined; re-enable does not redeliver\n");
    }

    private function down(string $path): void
    {
        $statement = $this->base->owner->prepare('SELECT batch FROM public.driver_migrations WHERE migration=?');
        $statement->execute([basename($path, '.php')]);
        $batch = $statement->fetchColumn();
        if (! is_int($batch)) {
            throw new \RuntimeException('Missing exact owned migration batch');
        }
        $this->base->artisan(['migrate:rollback', '--database=owner_a', '--path='.$path, '--batch='.$batch, '--force']);
    }

    public function rollback(): void
    {
        $product = [$this->groupsState('product'), $this->base->state('product')];
        $article = $this->base->state('article');
        // Rollback must use the original physical snapshot despite changed live panel configuration.
        $provider = 'app/Panels/ArticlePanelProvider.php';
        $this->write($provider, str_replace("'article_'", "'changed_'", $this->read($provider)));
        $cache = $this->base->consumer.'/bootstrap/cache/config.php';
        if (is_file($cache) && ! unlink($cache)) {
            throw new \RuntimeException('Cannot remove owned rollback cache');
        }
        $this->down($this->upgrades['article']);
        expect($this->base->state('article'))->toBe($article);
        foreach (['groups', 'group_members'] as $entity) {
            expect(PgTarget::query($this->base->owner, "SELECT to_regclass('public.driver_article_{$entity}')")->fetchColumn())->toBeNull();
        }
        $this->down($this->base->migrations['article']);
        foreach (['surface_instances', 'receipts', 'outbox', 'tombstones', 'reactions', 'reaction_counts'] as $entity) {
            expect(PgTarget::query($this->base->owner, "SELECT to_regclass('public.driver_article_{$entity}')")->fetchColumn())->toBeNull();
        }
        expect([$this->groupsState('product'), $this->base->state('product')])->toBe($product)
            ->and(PgTarget::query($this->base->owner, 'SELECT sentinel FROM public.p48_host')->fetchColumn())->toBe('preserved')
            ->and(PgTarget::query($this->base->owner, 'SELECT count(*) FROM public.driver_p48_targets')->fetchColumn())->toBe(2);
        // Stop registering the removed panel; its files and frozen history remain consumer-owned.
        $this->write('config/sockit.php', str_replace(['\\Acme\\Panels\\ArticlePanelProvider::class, ', '\\Acme\\Social\\ArticleSurface::class, '], '', $this->read('config/sockit.php')));
        expect($this->base->json([PHP_BINARY, 'groups.php', 'product-after', $this->resources['product']]))->toBe(['revision' => 3]);
        foreach ($this->frozen as $path => $hash) {
            if ($path !== $provider) {
                expect(hash_file('sha256', $this->base->consumer.'/'.$path))->toBe($hash, $path);
            }
        }
        fwrite(STDERR, "P5.6 frozen additive down preserves reactions; initial down preserves neighbouring groups/members/reactions/receipts/outbox, custom files, host sentinel and working public Product operations\n");
    }

    public function cleanup(): void
    {
        $this->base->cleanup();
    }
}
