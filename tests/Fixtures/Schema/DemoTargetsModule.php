<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use Illuminate\Contracts\Config\Repository;
use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;
use SocKit\Tests\Fixtures\Modules\DemoSettingsSchema;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final readonly class DemoTargetsModule implements Module
{
    public function __construct(private Repository $config) {}

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(id: 'acme.demo-targets');
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->settings(DemoSettingsSchema::class)->schema(Scenario::text($this->config->get('schema_fixture.contribution', DemoTargetsV2::class)));
    }
}
