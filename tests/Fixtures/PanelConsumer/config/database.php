<?php

declare(strict_types=1);
use Acme\PgTarget;

$target = PgTarget::fromEnvironment();

return ['default' => 'decoy', 'connections' => ['owner_a' => $target->configuration(), 'owner_b' => $target->configuration(), 'decoy' => $target->configuration(decoy: true)]];
