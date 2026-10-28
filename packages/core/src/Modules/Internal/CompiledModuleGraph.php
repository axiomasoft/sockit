<?php

declare(strict_types=1);

namespace SocKit\Modules\Internal;

use SocKit\Modules\Contracts\ModuleSettingsSchema;
use SocKit\Modules\ModuleManifest;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Surfaces\Contracts\TargetResolver;

final readonly class CompiledModuleGraph
{
    /** @param array<string, ModuleManifest> $manifests
     * @param array<string, array{surfaces:list<string>, features:list<string>, settings:class-string<ModuleSettingsSchema>|null, targets:array<string, class-string<TargetResolver>>, consumers:array<string, class-string<OutboxConsumer>>}> $declarations
     * @param array<string, array<string, scalar|array<array-key, mixed>|null>> $settings */
    public function __construct(public array $manifests, public array $declarations, public array $settings) {}
}
