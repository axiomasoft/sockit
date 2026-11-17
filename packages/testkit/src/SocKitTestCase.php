<?php

declare(strict_types=1);

namespace SocKit\Testkit;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use LogicException;
use Override;
use Packman\Attributes\Api;
use Packman\PackmanServiceProvider;
use Packman\Panels\Laravel\PanelsServiceProvider;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Packman\Schema\Testing\MigratesSchemaSets;
use Packman\Testkit\PackageTestCase;
use SocKit\Laravel\SocKitServiceProvider;
use SocKit\Testing\SocKitFake;

/**
 * Base Orchestra Testbench test case pre-wired with Packman + SocKit providers,
 * in-memory SQLite isolation, and PostgreSQL suite connection configuration.
 */
#[Api(since: '1.0')]
abstract class SocKitTestCase extends PackageTestCase
{
    use MigratesSchemaSets;

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            PackmanServiceProvider::class,
            SchemaServiceProvider::class,
            PluginsServiceProvider::class,
            PanelsServiceProvider::class,
            SocKitServiceProvider::class,
        ];
    }

    /** @param Application $app */
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make(ConfigRepository::class);
        $config->set('sockit.modules.enabled', $this->enabledSockitModules());
        $config->set('sockit.panels', $this->sockitPanelProviders());
        $config->set('sockit.surfaces', $this->sockitSurfaceProviders());

        $floor = $this->sockitPrivacyFloor();
        if ($floor !== null) {
            $config->set('sockit.privacy.floor', $floor);
        }
    }

    /** @return array<string, mixed>|null */
    protected function sockitPrivacyFloor(): ?array
    {
        return null;
    }

    /** @return list<string> */
    protected function enabledSockitModules(): array
    {
        return ['reactions', 'posts', 'groups'];
    }

    /** @return list<class-string> */
    protected function sockitPanelProviders(): array
    {
        return [];
    }

    /** @return list<class-string> */
    protected function sockitSurfaceProviders(): array
    {
        return [];
    }

    protected function installFakeSocKit(bool $forceFakeGroups = false): SocKitFake
    {
        return SocKitFake::install($this->app ?? throw new LogicException('Application not booted.'), $forceFakeGroups);
    }

    protected function migrateSockitSchema(?string $connection = null): void
    {
        $this->migrateSchemaScope(SchemaScope::Central, $connection, includeModules: true);
    }
}
