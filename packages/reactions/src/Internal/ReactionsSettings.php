<?php

declare(strict_types=1);

namespace SocKit\Reactions\Internal;

use Packman\Attributes\Internal;
use SocKit\Modules\Contracts\ModuleSettingsSchema;

#[Internal]
final class ReactionsSettings implements ModuleSettingsSchema
{
    public function defaults(): array
    {
        return ['kinds' => ['like'], 'max_per_target' => 100];
    }

    public function validate(array $settings): array
    {
        $errors = [];
        foreach (array_keys($settings) as $key) {
            if (! in_array($key, ['kinds', 'max_per_target'], true)) {
                $errors[] = ['key' => $key, 'rule' => 'SETTING_UNKNOWN'];
            }
        }
        $limit = $settings['max_per_target'] ?? 100;
        if (! is_int($limit) || $limit < 1 || $limit > 100) {
            $errors[] = ['key' => 'max_per_target', 'rule' => 'SETTING_INVALID'];
        }
        $kinds = $settings['kinds'] ?? null;
        if (! is_array($kinds) || ! array_is_list($kinds) || $kinds === [] || count(array_unique($kinds, SORT_REGULAR)) !== count($kinds)
            || array_any($kinds, fn (mixed $kind): bool => ! is_string($kind) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $kind) !== 1)) {
            $errors[] = ['key' => 'kinds', 'rule' => 'REACTION_KINDS_INVALID'];
        }

        return $errors;
    }
}
