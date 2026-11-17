<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Configuration;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Modules\Contracts\ModuleSettingsSchema;

/**
 * Closed module settings schema for SocKit Posts.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class PostsSettingsSchema implements ModuleSettingsSchema
{
    private const string FORMAT_PATTERN = '/\A[a-z][a-z0-9_-]{0,47}\z/';

    /** @return array<string, scalar|array<array-key, mixed>|null> */
    #[Override]
    public function defaults(): array
    {
        return [
            'max_body_bytes' => 10000,
            'allowed_formats' => ['plain', 'markdown'],
            'default_page_limit' => 20,
            'max_page_limit' => 100,
        ];
    }

    /**
     * @param  array<string, scalar|array<array-key, mixed>|null>  $settings
     * @return list<array{key: string, rule: string}>
     */
    #[Override]
    public function validate(array $settings): array
    {
        $violations = [];

        $maxBody = $settings['max_body_bytes'] ?? null;
        if (! is_int($maxBody) || $maxBody < 1 || $maxBody > 1000000) {
            $violations[] = ['key' => 'max_body_bytes', 'rule' => 'must be an integer between 1 and 1000000'];
        }

        $formats = $settings['allowed_formats'] ?? null;
        if (! is_array($formats) || ! array_is_list($formats) || $formats === []) {
            $violations[] = ['key' => 'allowed_formats', 'rule' => 'must be a non-empty list of format slugs'];
        } else {
            foreach ($formats as $format) {
                if (! is_string($format) || preg_match(self::FORMAT_PATTERN, $format) !== 1) {
                    $violations[] = ['key' => 'allowed_formats', 'rule' => 'each format must match ^[a-z][a-z0-9_-]{0,47}$'];

                    break;
                }
            }
        }

        $defaultLimit = $settings['default_page_limit'] ?? null;
        $maxLimit = $settings['max_page_limit'] ?? null;

        if (! is_int($defaultLimit) || $defaultLimit < 1 || $defaultLimit > 200) {
            $violations[] = ['key' => 'default_page_limit', 'rule' => 'must be an integer between 1 and 200'];
        }

        if (! is_int($maxLimit) || $maxLimit < 1 || $maxLimit > 500) {
            $violations[] = ['key' => 'max_page_limit', 'rule' => 'must be an integer between 1 and 500'];
        }

        if (is_int($defaultLimit) && is_int($maxLimit) && $defaultLimit > $maxLimit) {
            $violations[] = ['key' => 'default_page_limit', 'rule' => 'default_page_limit cannot exceed max_page_limit'];
        }

        return $violations;
    }
}
