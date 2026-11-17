<?php

declare(strict_types=1);

namespace SocKit\Modules\Reactions\Configuration;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Modules\Contracts\ModuleSettingsSchema;

/**
 * Closed module settings schema for SocKit Reactions.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ReactionsSettingsSchema implements ModuleSettingsSchema
{
    private const string TYPE_PATTERN = '/\A[a-z][a-z0-9_-]{0,63}\z/';

    /** @return array<string, scalar|array<array-key, mixed>|null> */
    #[Override]
    public function defaults(): array
    {
        return [
            'allowed_types' => ['like', 'love', 'laugh', 'wow', 'sad', 'angry'],
            'max_per_target' => 10,
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
        $types = $settings['allowed_types'] ?? null;

        if (! is_array($types) || ! array_is_list($types) || $types === []) {
            $violations[] = ['key' => 'allowed_types', 'rule' => 'must be a non-empty list of reaction slugs'];
        } else {
            foreach ($types as $type) {
                if (! is_string($type) || preg_match(self::TYPE_PATTERN, $type) !== 1) {
                    $violations[] = ['key' => 'allowed_types', 'rule' => 'each type must match ^[a-z][a-z0-9_-]{0,63}$'];

                    break;
                }
            }
        }

        $maxPerTarget = $settings['max_per_target'] ?? null;
        if (! is_int($maxPerTarget) || $maxPerTarget < 1 || $maxPerTarget > 100) {
            $violations[] = ['key' => 'max_per_target', 'rule' => 'must be an integer between 1 and 100'];
        }

        return $violations;
    }
}
