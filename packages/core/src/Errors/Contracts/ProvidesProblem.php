<?php

declare(strict_types=1);

namespace SocKit\Errors\Contracts;

use SocKit\Attributes\Api;
use SocKit\Errors\Enums\ProblemCode;

#[Api]
interface ProvidesProblem
{
    public function problem(): ProblemCode;

    /** @return array<string, scalar|null> */
    public function detail(): array;
}
