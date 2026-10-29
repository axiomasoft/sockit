<?php

declare(strict_types=1);

namespace SocKit\Posts\Internal;

use SocKit\Modules\Contracts\ModuleSettingsSchema;

final class PostsSettings implements ModuleSettingsSchema
{
    public function defaults(): array
    {
        return ['feed_keys' => [], 'feed_key' => '', 'feed_ttl' => 900, 'feed_chunk' => 20, 'feed_budget' => 200];
    }

    public function validate(array $settings): array
    {
        $errors = [];
        foreach ($settings as $key => $value) {
            $valid = match ($key) {
                'feed_keys' => is_array($value) && count($value) <= 8 && array_all(array_keys($value), fn (mixed $id): bool => is_string($id) && preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/D', $id) === 1)
                    && array_all($value, fn (mixed $secret): bool => is_string($secret) && strlen(base64_decode($secret, true) ?: '') === 32),
                'feed_key' => is_string($value) && ($value === '' || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/D', $value) === 1),
                'feed_ttl' => is_int($value) && $value >= 1 && $value <= 86400,
                'feed_chunk' => is_int($value) && $value >= 1 && $value <= 100,
                'feed_budget' => is_int($value) && $value >= 1 && $value <= 10000,
                default => false,
            };
            if (! $valid) {
                $errors[] = ['key' => $key, 'rule' => 'SETTING_INVALID'];
            }
        }

        return $errors;
    }
}
