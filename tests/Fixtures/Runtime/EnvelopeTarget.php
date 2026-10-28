<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use Illuminate\Database\Connection;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Contracts\TargetWriteFence;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\TargetLifecycle;

final class EnvelopeTarget implements TargetResolver, TargetWriteFence
{
    /** @var list<int> */
    public array $levels = [];

    public bool $changeAfterResolve = false;

    public bool $changeSurfaceAfterResolve = false;

    public bool $unproven = false;

    public int $fences = 0;

    public function __construct(private readonly Connection $connection) {}

    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
    {
        $this->levels[] = $this->connection->transactionLevel();
        if ($this->connection->transactionLevel() !== 0) {
            throw new \LogicException('Remote resolve under owner lock');
        }
        $row = $this->connection->table('panel_a.envelope_targets')->where('id', $target->host->value)->first();
        $visible = $row !== null && $row->incarnation === $target->incarnation->value && $row->visible === true && $row->held === false;
        $life = $row === null || ! is_string($row->lifecycle) ? TargetLifecycle::Unknown : TargetLifecycle::from($row->lifecycle);
        $read = $visible && in_array($life, [TargetLifecycle::Live, TargetLifecycle::ReadOnly], true) ? AudienceVerdict::allow('audience.allowed.platform') : AudienceVerdict::deny('audience.unknown');
        $write = $life === TargetLifecycle::Live ? $read : AudienceVerdict::deny('audience.unknown');
        $resolved = new TargetResolution(surface: $surface, target: $target, installation: $actor->installation, read: $read, write: $write, lifecycle: $life,
            privacyRevision: new Revision($row !== null && is_int($row->privacy) ? $row->privacy : 1), generation: $row !== null && is_string($row->generation) ? $row->generation : 'unknown');
        if ($this->changeAfterResolve) {
            $this->connection->table('panel_a.envelope_targets')->where('id', $target->host->value)->update(['generation' => 'changed']);
        }
        if ($this->changeSurfaceAfterResolve) {
            $this->connection->table('panel_a.a_surface_instances')->increment('privacy_generation');
        }

        return $resolved;
    }

    public function recheck(OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision
    {
        $this->fences++;
        if ($this->unproven || $this->connection->transactionLevel() !== 1) {
            return AuthorizationDecision::unknown('fixture.fence_unproven');
        }
        $row = $this->connection->table('panel_a.envelope_targets')->where('id', $resolved->target->host->value)->lockForUpdate()->first();
        if ($row === null || $row->generation !== $resolved->generation || $row->incarnation !== $resolved->target->incarnation->value || $row->visible !== true || $row->held !== false || $row->lifecycle !== 'live' || $row->privacy !== $resolved->privacyRevision->value) {
            return AuthorizationDecision::unknown('fixture.fence_changed');
        }

        return AuthorizationDecision::allow('fixture.fenced');
    }
}
