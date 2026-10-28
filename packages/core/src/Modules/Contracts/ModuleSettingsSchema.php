<?php

declare(strict_types=1);

namespace SocKit\Modules\Contracts;

use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ModuleSettingsSchema
{
    /** @return array<string, scalar|array<array-key, mixed>|null> */
    public function defaults(): array;

    /** @param array<string, scalar|array<array-key, mixed>|null> $settings
     * @return list<array{key:string, rule:string}> */
    public function validate(array $settings): array;
}
