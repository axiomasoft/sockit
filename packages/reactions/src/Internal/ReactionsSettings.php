<?php

declare(strict_types=1);

namespace SocKit\Reactions\Internal;

use SocKit\Modules\Contracts\ModuleSettingsSchema;

final class ReactionsSettings implements ModuleSettingsSchema
{
    public function defaults(): array
    {
        return ['kinds' => ['like']];
    }

    public function validate(array $settings): array
    {
        $errors = [];
        foreach (array_keys($settings) as $key) {
            if ($key !== 'kinds') {
                $errors[] = ['key' => $key, 'rule' => 'SETTING_UNKNOWN'];
            }
        }
        $kinds = $settings['kinds'] ?? null;
        if (! is_array($kinds) || ! array_is_list($kinds) || $kinds === [] || count(array_unique($kinds, SORT_REGULAR)) !== count($kinds)
            || array_any($kinds, fn (mixed $kind): bool => ! is_string($kind) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $kind) !== 1)) {
            $errors[] = ['key' => 'kinds', 'rule' => 'REACTION_KINDS_INVALID'];
        }

        return $errors;
    }
}
