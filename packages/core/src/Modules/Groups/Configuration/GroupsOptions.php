<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Configuration;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class GroupsOptions
{
    public function __construct(
        public int $maxTitleLength,
        public bool $allowSecretGroups,
    ) {}

    /** @param array<string, scalar|array<array-key, mixed>|null> $settings */
    public static function fromSettings(array $settings): self
    {
        return new self(
            maxTitleLength: is_int($settings['max_title_length'] ?? null) ? $settings['max_title_length'] : 160,
            allowSecretGroups: is_bool($settings['allow_secret_groups'] ?? null) ? $settings['allow_secret_groups'] : true,
        );
    }
}
