<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use Illuminate\Database\Connection;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Support\Data\ActorRef;

final class OutsideActor implements ActorContextResolver
{
    /** @var list<int> */
    public array $levels = [];

    public function __construct(private readonly Connection $connection, private readonly ActorContextResolver $delegate) {}

    public function current(): ActorContext
    {
        $this->outside();

        return $this->delegate->current();
    }

    public function reResolve(ActorRef $actor, ContextProvenance $provenance): ActorContext
    {
        $this->outside();

        return $this->delegate->reResolve(actor: $actor, provenance: $provenance);
    }

    private function outside(): void
    {
        $level = $this->connection->transactionLevel();
        $this->levels[] = $level;
        if ($level !== 0) {
            throw new \LogicException('Actor resolution under post/reaction locks');
        }
    }
}
