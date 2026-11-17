<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Configuration;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Modules\Contracts\ModuleSettingsSchema;

/**
 * Closed module settings schema for SocKit Groups.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class GroupsSettingsSchema implements ModuleSettingsSchema
{
    /** @return array<string, scalar|array<array-key, mixed>|null> */
    #[Override]
    public function defaults(): array
    {
        return [
            'max_title_length' => 160,
            'allow_secret_groups' => true,
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

        $maxTitle = $settings['max_title_length'] ?? null;
        if (! is_int($maxTitle) || $maxTitle < 3 || $maxTitle > 160) {
            $violations[] = ['key' => 'max_title_length', 'rule' => 'must be an integer between 3 and 160'];
        }

        if (! is_bool($settings['allow_secret_groups'] ?? null)) {
            $violations[] = ['key' => 'allow_secret_groups', 'rule' => 'must be a boolean'];
        }

        return $violations;
    }
}
