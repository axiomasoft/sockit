<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Modules;

use SocKit\Modules\Contracts\ModuleSettingsSchema;

final class DemoSettingsSchema implements ModuleSettingsSchema
{
    public function defaults(): array
    {
        return ['limit' => 10];
    }

    public function validate(array $settings): array
    {
        $errors = [];
        foreach (array_keys($settings) as $key) {
            if ($key !== 'limit') {
                $errors[] = ['key' => $key, 'rule' => 'UNKNOWN_KEY'];
            }
        }
        if (! is_int($settings['limit'] ?? null) || $settings['limit'] <= 0) {
            $errors[] = ['key' => 'limit', 'rule' => 'POSITIVE_INTEGER'];
        }

        return $errors;
    }
}
