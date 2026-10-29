<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use SocKit\Modules\Contracts\ModuleSettingsSchema;

/** Join policy belongs to each group; host consent and roles are not configuration. */
final class GroupsSettings implements ModuleSettingsSchema
{
    public function defaults(): array
    {
        return [];
    }

    public function validate(array $settings): array
    {
        $errors = [];
        foreach (array_keys($settings) as $key) {
            $errors[] = ['key' => $key, 'rule' => 'SETTING_INVALID'];
        }

        return $errors;
    }
}
