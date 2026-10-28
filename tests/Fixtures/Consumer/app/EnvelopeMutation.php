<?php

declare(strict_types=1);

namespace Acme;

use Illuminate\Database\Connection;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Contracts\OwnerMutation;
use SocKit\Operations\Data\MutationEffect;
use SocKit\Operations\Data\MutationEvent;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\MutationSnapshot;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Enums\TargetLifecycle;

/** Real acme owner state/revision SQL. No social domain is implemented by this fixture. */
final class EnvelopeMutation implements OwnerMutation
{
    public const string TYPE = 'acme.runtime.mutate';

    public string $authorizationAction = self::TYPE;

    public int $writes = 0;

    public int $locks = 0;

    public int $value = 1;

    public string $targetKey = '42';

    public int $incarnation = 1;

    public string $bad = '';

    public ?AudienceSpec $proposed = null;

    public ?AuthorizationDecision $safety = null;

    /** @var array<string, mixed> */
    public array $extraPayload = [];

    public function __construct(private readonly Connection $connection, private readonly string $schema = 'panel_a') {}

    public function commandType(): string
    {
        return self::TYPE;
    }

    public function payload(): array
    {
        return ['value' => $this->value, ...$this->extraPayload];
    }

    public function request(OperationHandle $handle): MutationRequest
    {
        if ($this->connection->transactionLevel() !== 0) {
            throw new \LogicException('request under tx');
        }

        return new MutationRequest(action: $this->authorizationAction, subject: new AudienceSubject(key: 'acme.subject', spec: AudienceSpec::direct($handle->actor->actor ?? throw new \RuntimeException('actor required')), author: null, surface: $handle->surface, installation: $handle->actor->installation),
            intent: $this->proposed === null ? 'write' : 'publish', safety: $this->safety ?? AuthorizationDecision::allow('fixture.safe'), generation: '1',
            target: new TargetRef(host: new HostEntityRef(type: 'article', value: $this->targetKey), incarnation: new Incarnation($this->incarnation)), proposedAudience: $this->proposed);
    }

    public function lock(OperationHandle $handle): MutationSnapshot
    {
        $this->locks++;
        $row = $this->connection->table($this->schema.'.envelope_revisions')->where('target', $this->targetKey)->lockForUpdate()->first();
        if ($row === null || ! is_string($row->resource) || ! is_int($row->revision)) {
            throw new \RuntimeException('Missing fixture revision');
        }

        return new MutationSnapshot(resource: new ResourceRef(panel: $handle->surface->panel, kind: ResourceKind::SurfaceInstance, id: $row->resource), revision: new Revision($row->revision), lifecycle: TargetLifecycle::Live,
            generation: $this->bad === 'generation' ? 'stale' : '1', safety: $this->bad === 'safety' ? AuthorizationDecision::unknown('fixture.safety_unknown') : AuthorizationDecision::allow('fixture.safe'));
    }

    public function persist(OperationHandle $handle, MutationSnapshot $snapshot): MutationEffect
    {
        $this->writes++;
        $this->connection->table($this->schema.'.envelope_state')->where('target', $this->targetKey)->increment('effects', $this->value);
        $affected = $this->connection->table($this->schema.'.envelope_revisions')->where('target', $this->targetKey)->where('revision', $snapshot->revision?->value)->update(['revision' => $snapshot->revision?->next()->value]);
        $resource = $snapshot->resource ?? throw new \RuntimeException('No fixture resource');

        return new MutationEffect(affectedRows: $this->bad === 'rows' ? 0 : $affected, resource: $resource,
            newRevision: $this->bad === 'revision' ? new Revision(99) : ($snapshot->revision?->next() ?? new Revision(1)),
            events: $this->bad === 'events' ? [] : [new MutationEvent(type: 'acme.runtime.changed', refs: ['resource' => $resource])]);
    }
}
