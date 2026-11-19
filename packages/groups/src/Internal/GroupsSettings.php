<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use Packman\Attributes\Internal;
use SocKit\Modules\Contracts\ModuleSettingsSchema;

/** Join policy belongs to each group; host consent and roles are not configuration. */
#[Internal]
final class GroupsSettings implements ModuleSettingsSchema
{
    public function defaults(): array
    {
        return ['max_title_length' => 512, 'allow_secret_groups' => true];
    }

    public function validate(array $settings): array
    {
        $errors = [];
        foreach ($settings as $key => $value) {
            $valid = match ($key) {
                'max_title_length' => is_int($value) && $value >= 1 && $value <= 512,
                'allow_secret_groups' => is_bool($value),
                default => false,
            };
            if (! $valid) {
                $errors[] = ['key' => $key, 'rule' => 'SETTING_INVALID'];
            }
        }

        return $errors;
    }
}
