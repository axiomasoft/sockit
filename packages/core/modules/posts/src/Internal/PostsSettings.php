<?php

declare(strict_types=1);

namespace SocKit\Posts\Internal;

use SocKit\Modules\Contracts\ModuleSettingsSchema;

final class PostsSettings implements ModuleSettingsSchema
{
    public function defaults(): array
    {
        return [];
    }

    public function validate(array $settings): array
    {
        return array_map(fn (string $key): array => ['key' => $key, 'rule' => 'SETTING_UNKNOWN'], array_keys($settings));
    }
}
