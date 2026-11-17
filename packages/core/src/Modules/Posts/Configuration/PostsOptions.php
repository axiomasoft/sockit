<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Configuration;

use Packman\Attributes\Api;

/**
 * Typed runtime view of compiled Posts module settings on a panel.
 */
#[Api(since: '1.0')]
final readonly class PostsOptions
{
    /** @param list<string> $allowedFormats */
    public function __construct(
        public int $maxBodyBytes,
        public array $allowedFormats,
        public int $defaultPageLimit,
        public int $maxPageLimit,
    ) {}

    /** @param array<string, scalar|array<array-key, mixed>|null> $settings */
    public static function fromSettings(array $settings): self
    {
        $maxBody = is_int($settings['max_body_bytes'] ?? null) ? $settings['max_body_bytes'] : 10000;
        $rawFormats = $settings['allowed_formats'] ?? ['plain', 'markdown'];
        $formats = [];

        if (is_array($rawFormats)) {
            foreach ($rawFormats as $format) {
                if (is_string($format) && $format !== '') {
                    $formats[] = $format;
                }
            }
        }

        $defaultLimit = is_int($settings['default_page_limit'] ?? null) ? $settings['default_page_limit'] : 20;
        $maxLimit = is_int($settings['max_page_limit'] ?? null) ? $settings['max_page_limit'] : 100;

        return new self(
            maxBodyBytes: $maxBody,
            allowedFormats: $formats !== [] ? array_values(array_unique($formats)) : ['plain'],
            defaultPageLimit: $defaultLimit,
            maxPageLimit: max($defaultLimit, $maxLimit),
        );
    }

    public function allowsFormat(string $format): bool
    {
        return in_array($format, $this->allowedFormats, true);
    }
}
