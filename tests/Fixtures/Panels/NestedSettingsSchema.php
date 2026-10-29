<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Panels;

use SocKit\Modules\Contracts\ModuleSettingsSchema;

final class NestedSettingsSchema implements ModuleSettingsSchema
{
    public function defaults(): array
    {
        return ['policy' => ['limit' => 10, 'labels' => ['a', 'b']]];
    }

    public function validate(array $settings): array
    {
        $policy = $settings['policy'] ?? null;
        if (array_keys($settings) !== ['policy'] || ! is_array($policy) || array_diff(array_keys($policy), ['limit', 'labels']) !== []
            || ! is_int($policy['limit'] ?? null) || $policy['limit'] < 1 || $policy['limit'] > 100 || ! is_array($policy['labels'] ?? null)) {
            return [['key' => 'policy', 'rule' => 'POLICY_CEILING']];
        }

        return [];
    }
}
