<?php

declare(strict_types=1);

namespace SocKit\Configuration\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ResolversConfig
{
    /** @param class-string|null $tenant
     * @param class-string|null $actor
     * @param class-string|null $contour
     * @param class-string|null $audience
     * @param class-string|null $authorizer
     * @param class-string|null $groups
     * @param class-string|null $surfacePolicy
     * @param class-string|null $targets */
    public function __construct(public ?string $tenant, public ?string $actor, public ?string $contour, public ?string $audience, public ?string $authorizer, public ?string $groups, public ?string $surfacePolicy, public ?string $targets) {}

    /** @return class-string|null */
    public function for(string $key): ?string
    {
        return match ($key) {
            'tenant' => $this->tenant, 'actor' => $this->actor, 'contour' => $this->contour,
            'audience' => $this->audience, 'authorizer' => $this->authorizer, 'groups' => $this->groups,
            'surface_policy' => $this->surfacePolicy, 'targets' => $this->targets,
            default => throw new \InvalidArgumentException('Unknown resolver slot'),
        };
    }
}
