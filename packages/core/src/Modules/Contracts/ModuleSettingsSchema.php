<?php

declare(strict_types=1);

namespace SocKit\Modules\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Stateless module settings schema validating merged module defaults, global settings, and panel overrides.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ModuleSettingsSchema
{
    /** @return array<string, scalar|array<array-key, mixed>|null> */
    public function defaults(): array;

    /**
     * @param  array<string, scalar|array<array-key, mixed>|null>  $settings
     * @return list<array{key: string, rule: string}>
     */
    public function validate(array $settings): array;
}
