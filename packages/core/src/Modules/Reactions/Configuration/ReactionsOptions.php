<?php

declare(strict_types=1);

namespace SocKit\Modules\Reactions\Configuration;

use Packman\Attributes\Api;

/**
 * Typed runtime view of compiled Reactions module settings on a panel.
 */
#[Api(since: '1.0')]
final readonly class ReactionsOptions
{
    /** @param list<string> $allowedTypes */
    public function __construct(
        public array $allowedTypes,
        public int $maxPerTarget,
    ) {}

    /** @param array<string, scalar|array<array-key, mixed>|null> $settings */
    public static function fromSettings(array $settings): self
    {
        $rawTypes = $settings['allowed_types'] ?? ['like', 'love', 'laugh', 'wow', 'sad', 'angry'];
        $types = [];

        if (is_array($rawTypes)) {
            foreach ($rawTypes as $type) {
                if (is_string($type) && $type !== '') {
                    $types[] = $type;
                }
            }
        }

        $max = is_int($settings['max_per_target'] ?? null) ? $settings['max_per_target'] : 10;

        return new self(
            allowedTypes: $types !== [] ? array_values(array_unique($types)) : ['like'],
            maxPerTarget: $max,
        );
    }

    public function allows(string $reactionType): bool
    {
        return in_array($reactionType, $this->allowedTypes, true);
    }
}
