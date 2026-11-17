<?php

declare(strict_types=1);

namespace SocKit\Operations\Contracts;

use Closure;
use Illuminate\Database\Connection;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Authoritative atomic mutation envelope coordinating pre-tx authorization, receipt claim,
 * panel-scoped SQL transaction, replay lifecycle verification, and transactional outbox persistence.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface OperationEnvelopeRunner
{
    /**
     * @param  Closure(ActorContext): void  $preTransactionChecks
     * @param  Closure(ActorContext, Connection): array{result: OperationResult, event?: array{aggregate_type: string, aggregate_id: string, aggregate_revision: int, event_type: string, payload: array<string, mixed>}|null}  $mutation
     * @param  (Closure(ActorContext, Connection, OperationResult): void)|null  $replayChecks
     */
    public function run(
        CommandEnvelope $envelope,
        SurfaceRef $surface,
        string $action,
        Closure $preTransactionChecks,
        Closure $mutation,
        ?Closure $replayChecks = null,
    ): OperationResult;
}
