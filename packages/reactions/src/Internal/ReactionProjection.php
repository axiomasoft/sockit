<?php

declare(strict_types=1);

namespace SocKit\Reactions\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Operations\OperationHandles;
use SocKit\Reactions\Data\ReactionQuery;
use SocKit\Reactions\Data\ReactionSummary;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Ids;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceInstance;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\SurfaceLifecycle;
use SocKit\Surfaces\SurfaceInstances;

/** Rebuildable state is never an access grant. No foreign SQL or historical delivery path. */
#[Internal]
final readonly class ReactionProjection
{
    public function __construct(private Application $app) {}

    public function summary(OperationHandle $handle, TargetRef $target): ReactionSummary
    {
        return $this->summaryMany(handle: $handle, queries: [new ReactionQuery(key: 'scalar', target: $target)])['scalar'];
    }

    /** @param list<ReactionQuery> $queries
     * @return array<string, ReactionSummary> */
    public function summaryMany(OperationHandle $handle, array $queries): array
    {
        $candidates = $result = $admissions = [];
        foreach ($queries as $query) {
            try {
                $candidates[$query->key] = $this->read(handle: $handle, target: $query->target);
            } catch (\Throwable) {
                $result[$query->key] = $this->denied();
            }
        }
        // No more projection reads after this boundary. Revalidate every candidate before batch output.
        foreach ($queries as $query) {
            if (! isset($candidates[$query->key])) {
                continue;
            }
            try {
                [$view, $surface, $resolved] = $candidates[$query->key];
                [$freshSurface, $freshTarget] = $this->guard(handle: $handle, target: $query->target, action: 'reactions.summary');
                if ($surface != $freshSurface || $resolved->privacyRevision != $freshTarget->privacyRevision || $resolved->generation !== $freshTarget->generation) {
                    throw new ReactionException(ProblemCode::AuthorizationUnknown);
                }
                $result[$query->key] = $view;
                $admissions[$query->key] = $freshTarget->read;
            } catch (\Throwable) {
                $result[$query->key] = $this->denied();
            }
        }
        $ordered = [];
        foreach ($queries as $query) {
            $admission = $admissions[$query->key] ?? null;
            $ordered[$query->key] = $admission !== null && $admission->isCurrent() ? $result[$query->key] : $this->denied();
        }

        return $ordered;
    }

    /** @return array{ReactionSummary, SurfaceInstance, TargetResolution} */
    private function read(OperationHandle $handle, TargetRef $target): array
    {
        [$surface, $resolved] = $this->guard(handle: $handle, target: $target, action: 'reactions.summary');
        $counts = $own = [];
        foreach ($this->kinds($handle) as $kind) {
            $identity = new ReactionIdentity(installation: $handle->actor->installation, surface: $handle->surface, target: $target, kind: $kind);
            $row = $this->table(handle: $handle, entity: 'reaction_counts')->where('identity_digest', $identity->digest())->first();
            $counts[$kind] = 0;
            if ($row !== null) {
                $identity->verify($this->values($row));
                if (! is_int($row->count) || $row->count < 0) {
                    throw new ReactionException(ProblemCode::PayloadInvalid);
                }
                $counts[$kind] = $row->count;
            }
            $identity = new ReactionIdentity(installation: $handle->actor->installation, surface: $handle->surface, target: $target, kind: $kind, actor: $handle->actor->actor);
            $row = $this->table(handle: $handle, entity: 'reactions')->where('identity_digest', $identity->digest())->first();
            if ($row !== null) {
                $identity->verify($this->values($row));
                if ($row->active === true) {
                    $own[] = $kind;
                }
            }
        }

        return [new ReactionSummary(counts: $counts, ownKinds: $own), $surface, $resolved];
    }

    private function denied(): ReactionSummary
    {
        // Identical shape/code for absent, hidden, denied, unknown and resolver failure.
        return new ReactionSummary(counts: null, ownKinds: null, problem: ProblemCode::ContentHidden);
    }

    public function rebuildForSurface(OperationHandle $handle): int
    {
        $this->app->make(OperationHandles::class)->assertCurrent($handle);
        $this->kinds($handle);
        $this->app->make(SurfaceInstances::class)->get($handle);
        $this->app->make(ActionAuthorizer::class)->decide(new ActionRequest($handle->actor, 'reactions.rebuild', $handle->surface))->requireAllowed();
        $scope = ['installation' => $handle->actor->installation->id, 'panel' => $handle->surface->panel->value,
            'surface_kind' => $handle->surface->kind->value, 'host_type' => $handle->surface->host->type, 'host_key' => $handle->surface->host->value,
            'surface_incarnation' => $handle->surface->incarnation->value];
        $columns = ['target_type', 'target_key', 'target_incarnation'];
        $targets = $this->table($handle, 'reactions')->where($scope)->select($columns)
            ->union($this->table($handle, 'reaction_counts')->where($scope)->select($columns));
        $count = 0;
        foreach ($targets->cursor() as $row) {
            if (! is_string($row->target_type) || ! is_string($row->target_key) || ! is_int($row->target_incarnation)) {
                throw new ReactionException(ProblemCode::PayloadInvalid);
            }
            $this->rebuild($handle, new TargetRef(new HostEntityRef($row->target_type, $row->target_key), new Incarnation($row->target_incarnation)), ReplayMode::ProjectionOnly);
            $count++;
        }

        return $count;
    }

    public function rebuild(OperationHandle $handle, TargetRef $target, ReplayMode $mode): void
    {
        if ($mode !== ReplayMode::ProjectionOnly) {
            throw new ReactionException(ProblemCode::PayloadInvalid);
        }
        [$surface, $resolved] = $this->guard(handle: $handle, target: $target, action: 'reactions.rebuild');
        if ($surface->lifecycle !== SurfaceLifecycle::Live || ! $resolved->write->canWrite()) {
            throw new ReactionException(ProblemCode::AuthorizationDenied);
        }
        $actor = $handle->actor->actor ?? throw new ReactionException(ProblemCode::AuthenticationRequired);
        $request = new MutationRequest(action: 'reactions.rebuild', subject: new AudienceSubject(key: 'reactions.rebuild', spec: AudienceSpec::direct($actor), author: null,
            surface: $handle->surface, installation: $handle->actor->installation), intent: 'write', safety: AuthorizationDecision::allow('reactions.projection_only'), generation: 'reactions.v1', target: $target);
        $authority = $this->app->make(MutationAuthorityFence::class);
        $generation = $authority->capture(handle: $handle, request: $request);
        if ($generation === '' || strlen($generation) > 128 || preg_match('/[\x00-\x1f\x7f]/', $generation)) {
            throw new ReactionException(ProblemCode::AuthorizationUnknown);
        }
        $this->connection($handle)->transaction(function () use ($handle, $target, $surface, $resolved, $request, $authority, $generation): void {
            // Same permanent surface row and target fence as add/remove, including when counters are absent.
            $locked = $this->table(handle: $handle, entity: 'surface_instances')->where('installation', $handle->actor->installation->id)
                ->where('panel', $handle->surface->panel->value)->where('kind', $handle->surface->kind->value)
                ->where('host_type', $handle->surface->host->type)->where('host_key', $handle->surface->host->value)->lockForUpdate()->first();
            if ($locked === null || $locked->id !== $surface->id || $locked->incarnation !== $handle->surface->incarnation->value
                || $locked->revision !== $surface->revision->value || $locked->privacy_generation !== $surface->privacyGeneration->value
                || $locked->source_generation !== $surface->sourceGeneration->value || $locked->lifecycle !== 'live') {
                throw new ReactionException(ProblemCode::AuthorizationUnknown);
            }
            $this->app->make(OperationHandles::class)->recheckTarget(handle: $handle, resolved: $resolved)->requireAllowed();
            $members = $this->targetRows(handle: $handle, target: $target, entity: 'reactions')->orderBy('identity_digest')->lockForUpdate()->get();
            $totals = [];
            foreach ($members as $row) {
                $kind = $row->reaction_kind;
                if (! is_string($kind) || ! is_string($row->actor_ref) || ! is_bool($row->active)) {
                    throw new ReactionException(ProblemCode::PayloadInvalid);
                }
                $identity = new ReactionIdentity(installation: $handle->actor->installation, surface: $handle->surface, target: $target, kind: $kind,
                    actor: ActorRef::fromWire($row->actor_ref));
                $identity->verify($this->values($row));
                if ($row->active) {
                    $totals[$kind] = ($totals[$kind] ?? 0) + 1;
                }
            }
            $authority->recheck(handle: $handle, request: $request, generation: $generation)->requireAllowed();
            $this->targetRows(handle: $handle, target: $target, entity: 'reaction_counts')->delete();
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            foreach ($totals as $kind => $count) {
                $identity = new ReactionIdentity(installation: $handle->actor->installation, surface: $handle->surface, target: $target, kind: $kind);
                $this->table(handle: $handle, entity: 'reaction_counts')->insert([...$identity->columns(), 'identity_digest' => $identity->digest(),
                    'id' => Ids::uuid7(), 'count' => $count, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }, attempts: 1);
    }

    /** @return array{SurfaceInstance, TargetResolution} */
    private function guard(OperationHandle $handle, TargetRef $target, string $action): array
    {
        $this->app->make(OperationHandles::class)->assertCurrent($handle);
        $this->kinds($handle);
        if ($this->connection($handle)->transactionLevel() !== 0) {
            throw new ReactionException(ProblemCode::ConfigurationInvalid);
        }
        $surface = $this->app->make(SurfaceInstances::class)->get($handle);
        $this->app->make(ActionAuthorizer::class)->decide(new ActionRequest(actor: $handle->actor, action: $action, surface: $handle->surface))->requireAllowed();
        $resolved = $this->app->make(OperationHandles::class)->resolveTarget(handle: $handle, target: $target);
        if (! $resolved->read->canRead()) {
            throw new ReactionException(ProblemCode::ContentHidden);
        }

        return [$surface, $resolved];
    }

    /** @return list<string> */
    private function kinds(OperationHandle $handle): array
    {
        if (! in_array('sockit.reactions', $handle->panel->modules, true) || ! in_array('reactions', $handle->definition->features, true)) {
            throw new ReactionException(ProblemCode::ModuleInvalid);
        }
        $kinds = $handle->panel->moduleSettings['sockit.reactions']['kinds'] ?? null;
        if (! is_array($kinds) || ! array_is_list($kinds) || $kinds === []) {
            throw new ReactionException(ProblemCode::ConfigurationInvalid);
        }
        $result = [];
        foreach ($kinds as $kind) {
            if (! is_string($kind)) {
                throw new ReactionException(ProblemCode::ConfigurationInvalid);
            }
            $result[] = $kind;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function values(\stdClass $row): array
    {
        $values = [];
        foreach (get_object_vars($row) as $key => $value) {
            if (! is_string($key)) {
                throw new ReactionException(ProblemCode::PayloadInvalid);
            }
            $values[$key] = $value;
        }

        return $values;
    }

    private function targetRows(OperationHandle $handle, TargetRef $target, string $entity): Builder
    {
        $columns = new ReactionIdentity(installation: $handle->actor->installation, surface: $handle->surface, target: $target, kind: 'like')->columns();
        unset($columns['reaction_kind']);

        return $this->table(handle: $handle, entity: $entity)->where($columns);
    }

    private function table(OperationHandle $handle, string $entity): Builder
    {
        $storage = $handle->panel->storage;
        $table = $storage->tables[$entity] ?? throw new ReactionException(ProblemCode::ConfigurationInvalid);

        return $this->connection($handle)->table(($storage->schema === null ? '' : $storage->schema.'.').$table);
    }

    private function connection(OperationHandle $handle): Connection
    {
        return $this->app->make(ConnectionResolverInterface::class)->connection($handle->panel->storage->connection);
    }
}
