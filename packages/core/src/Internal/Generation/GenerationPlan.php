<?php

declare(strict_types=1);

namespace SocKit\Internal\Generation;

use Packman\Attributes\Internal;

#[Internal]
final readonly class GenerationPlan
{
    /** @param array<string, string> $sources Relative path => source
     * @param array<string, mixed> $manifest */
    public function __construct(public string $root, public array $sources, public array $manifest) {}
}
