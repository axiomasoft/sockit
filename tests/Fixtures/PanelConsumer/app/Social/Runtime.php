<?php

declare(strict_types=1);

namespace Acme\Social;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\OperationHandles;
use SocKit\Panels\Data\PanelId;
use SocKit\Reactions\Data\ReactionCommand;
use SocKit\Reactions\Reactions;
use SocKit\Schema\PanelModel;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\SurfaceInstances;

final readonly class Runtime
{
    public function __construct(private Application $app) {}

    public static function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    }

    public function handle(string $slug = 'article'): OperationHandle
    {
        return $this->app->make(OperationHandles::class)->forSurface(new SurfaceRef(panel: new PanelId('acme.'.$slug), kind: new SurfaceKindId('acme.social'), host: new HostEntityRef(type: $slug, value: '42'), incarnation: new Incarnation(1)));
    }

    public function connection(string $slug = 'article'): Connection
    {
        return $this->app->make(DatabaseManager::class)->connection($slug === 'article' ? 'owner_a' : 'owner_b');
    }

    public function reaction(OperationHandle $handle, ?string $alias = null, int $incarnation = 1): ReactionCommand
    {
        return new ReactionCommand(surface: $handle->surface, target: new TargetRef(host: new HostEntityRef(type: $alias ?? $handle->surface->host->type, value: '42'), incarnation: new Incarnation($incarnation)), kind: 'like');
    }

    public function command(OperationHandle $handle, ReactionCommand $reaction, string $key = 'consumer.reaction.0001', string $action = 'add', ?int $revision = null): CommandEnvelope
    {
        $expected = $revision === null ? null : new Revision($revision);

        return new CommandEnvelope(key: new CommandKey($key), namespace: $this->namespace($handle, 'reactions.'.$action),
            digest: $this->app->make(Reactions::class)->digest(handle: $handle, reaction: $reaction, action: $action, expectedRevision: $expected), expectedRevision: $expected);
    }

    private function namespace(OperationHandle $handle, string $type): CommandNamespace
    {
        return new CommandNamespace(installation: $handle->actor->installation, panel: $handle->surface->panel, integration: 'acme.client', commandType: $type);
    }

    public static function success(OperationResult $result): void
    {
        self::check($result->isSuccess(), 'Public operation failed: '.json_encode($result->toWire(), JSON_THROW_ON_ERROR));
    }

    public function count(OperationHandle $handle, ReactionCommand $reaction, int $count): void
    {
        $view = $this->app->make(Reactions::class)->summary(handle: $handle, target: $reaction->target);
        self::check($view->problem === null && $view->counts === ['like' => $count] && $view->ownKinds === ($count === 0 ? [] : ['like']), 'Protected counts/own membership mismatch: '.json_encode($view));
    }

    /** @return list<int> */
    private function cardinalities(Connection $connection): array
    {
        return array_map(static fn (string $entity): int => $connection->table('public.article_'.$entity)->count(), ['reactions', 'reaction_counts', 'receipts', 'outbox']);
    }

    /** @return array{assertions:list<string>,queue:string} */
    public function smoke(): array
    {
        $api = $this->app->make(Reactions::class);
        $surfaces = $this->app->make(SurfaceInstances::class);
        foreach (['article', 'product'] as $slug) {
            $h = $this->handle($slug);
            $command = new CommandEnvelope(key: new CommandKey('consumer.surface.0001'), namespace: $this->namespace($h, 'sockit.surfaces.create'), digest: $surfaces->digest(handle: $h, action: 'create'));
            self::success($surfaces->create(handle: $h, command: $command));
        }
        $a = $this->handle();
        $b = $this->handle('product');
        $ar = $this->reaction($a);
        $br = $this->reaction($b);
        $original = $this->command($a, $ar);
        $first = $api->add(handle: $a, reaction: $ar, command: $original);
        self::success($first);
        self::check($api->add(handle: $a, reaction: $ar, command: $original)->toWire() === $first->toWire(), 'Replay changed outcome');
        self::check($api->lookup(handle: $a, namespace: $original->namespace, key: $original->key)?->toWire() === $first->toWire(), 'Public lookup failed');
        $this->count($b, $br, 0);
        self::success($api->add(handle: $b, reaction: $br, command: $this->command($b, $br)));
        $this->count($a, $ar, 1);
        $this->count($b, $br, 1);
        $alias = $this->reaction($a, 'product');
        $this->count($a, $alias, 0);
        self::success($api->add(handle: $a, reaction: $alias, command: $this->command($a, $alias, 'consumer.alias.0001')));
        $this->connection()->table('public.p48_targets')->where('type', 'article')->update(['incarnation' => 2, 'generation' => '2']);
        self::check($api->summary(handle: $a, target: $ar->target)->counts === null, 'Old incarnation disclosed counts');
        $next = $this->reaction($a, incarnation: 2);
        $this->count($a, $next, 0);
        self::success($api->add(handle: $a, reaction: $next, command: $this->command($a, $next, 'consumer.incarnation.2')));
        $this->connection()->table('public.p48_targets')->where('type', 'article')->update(['incarnation' => 1, 'generation' => '1']);
        $this->count($a, $ar, 1);
        $this->count($a, $alias, 1);
        self::success($api->remove(handle: $a, reaction: $ar, command: $this->command($a, $ar, 'consumer.remove.0001', 'remove', 1)));
        $this->count($a, $ar, 0);
        $this->count($b, $br, 1);
        self::check(! $api->add(handle: $a, reaction: $ar, command: $this->command($a, $ar, 'consumer.stale.0001', revision: 1))->isSuccess(), 'Stale CAS accepted');
        self::success($api->add(handle: $a, reaction: $ar, command: $this->command($a, $ar, 'consumer.readd.0001', revision: 2)));
        $this->count($a, $ar, 1);
        $connection = $this->connection();
        $connection->table('public.p48_targets')->where('type', 'article')->update(['visible' => false]);
        self::check($api->summary(handle: $a, target: $ar->target)->counts === null && ! $api->add(handle: $a, reaction: $ar, command: $original)->isSuccess(), 'Hidden target replay/read allowed');
        $connection->table('public.p48_targets')->where('type', 'article')->update(['visible' => true]);
        $connection->table('public.article_surface_instances')->update(['lifecycle' => 'archived']);
        $this->count($a, $ar, 1);
        self::check(! $api->remove(handle: $a, reaction: $ar, command: $this->command($a, $ar, 'consumer.archived.1', 'remove', 3))->isSuccess(), 'Archive allowed write');
        $connection->table('public.article_surface_instances')->update(['lifecycle' => 'live']);
        $connection->table('public.envelope_authority')->update(['allowed' => false]);
        self::check($api->lookup(handle: $a, namespace: $original->namespace, key: $original->key)?->problem === ProblemCode::AuthenticationRequired, 'Revoked actor replay allowed');
        $connection->table('public.envelope_authority')->update(['allowed' => true]);
        $before = $this->cardinalities($connection);
        $beforeRows = $connection->table('public.article_reactions')->orderBy('id')->get()->toJson();
        $armed = true;
        $connection->listen(static function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'article_outbox')) {
                $armed = false;
                throw new \RuntimeException('P4.8 injected outbox failure');
            }
        });
        $fault = $api->remove(handle: $a, reaction: $alias, command: $this->command($a, $alias, 'consumer.atomic.0001', 'remove', 1));
        self::check(! $armed && ! $fault->isSuccess() && $before === $this->cardinalities($connection) && $beforeRows === $connection->table('public.article_reactions')->orderBy('id')->get()->toJson(), 'Atomic reaction/receipt/outbox rollback failed');
        $this->count($a, $alias, 1);
        $payload = '';
        foreach (['article', 'product', 'article'] as $slug) {
            $h = $this->handle($slug);
            $class = $h->panel->storage->models['reactions'];
            if (! is_a($class, PanelModel::class, true)) {
                throw new \RuntimeException('Generated reaction model missing');
            }
            $model = $class::query()->where('target_type', $slug)->where('target_incarnation', 1)->firstOrFail();
            self::check($model->getAttribute('panel') === 'acme.'.$slug && $model->getAttribute('active') === true && is_int($model->getAttribute('revision')), 'Model hydration/casts/panel mismatch');
            $job = unserialize(serialize(new Job($model)));
            self::check($job instanceof Job && $class === $job->model::class && $job->model->getConnectionName() === $h->panel->storage->connection, 'A-B-A queue binding leaked');
            if ($slug === 'article') {
                self::check(str_ends_with($class, '\\CustomReaction'), 'Custom model mapping ignored');
                $payload = base64_encode(serialize(new Job($model)));
            }
        }
        self::check($this->app->make(DatabaseManager::class)->getDefaultConnection() === 'decoy', 'Default database changed');

        return ['assertions' => ['public_surfaces', 'add_replay', 'panel_isolation', 'alias_incarnation_isolation', 'remove_cas', 'current_guards', 'atomic_rollback', 'models_queue'], 'queue' => $payload];
    }
}
