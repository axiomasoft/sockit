<?php

declare(strict_types=1);
use Acme\PgTarget;

return ['panels' => [], 'modules' => ['enabled' => ['acme.demo-targets']],
    'storage' => ['deployments' => ['owner_a' => PgTarget::fromEnvironment()->deployment()]]];
