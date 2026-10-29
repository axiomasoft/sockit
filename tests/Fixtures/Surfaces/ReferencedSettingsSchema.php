<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Surfaces;

use SocKit\Modules\Contracts\ModuleSettingsSchema;

/** Stateless schema reproducing shared references created entirely within defaults(). */
final class ReferencedSettingsSchema implements ModuleSettingsSchema
{
    public function defaults(): array
    {
        $limit = 10;

        return ['policy' => ['limit' => &$limit, 'mirror' => &$limit, 'limits' => [&$limit]]];
    }

    public function validate(array $settings): array
    {
        $policy = $settings['policy'] ?? null;
        if (! is_array($policy) || ! is_int($policy['limit'] ?? null) || $policy['limit'] < 1 || $policy['limit'] > 100) {
            return [['key' => 'policy.limit', 'rule' => 'POLICY_CEILING']];
        }

        return [];
    }
}
