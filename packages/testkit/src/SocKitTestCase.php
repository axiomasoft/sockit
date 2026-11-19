<?php

declare(strict_types=1);

namespace SocKit\Testkit;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use LogicException;
use Override;
use Packman\Attributes\Api;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Testkit\PackageTestCase;
use SocKit\Groups\GroupsServiceProvider;
use SocKit\Posts\PostsServiceProvider;
use SocKit\Reactions\ReactionsServiceProvider;
use SocKit\SocKitServiceProvider;
use SocKit\Support\Data\InstallationRef;
use SocKit\Testing\SocKitFake;

/**
 * Base Orchestra Testbench test case pre-wired with Packman + SocKit providers,
 * in-memory SQLite isolation, and PostgreSQL suite connection configuration.
 */
#[Api(since: '1.0')]
abstract class SocKitTestCase extends PackageTestCase
{
    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            PackmanServiceProvider::class,
            PluginsServiceProvider::class,
            ReactionsServiceProvider::class,
            PostsServiceProvider::class,
            GroupsServiceProvider::class,
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
        return ['sockit.reactions', 'sockit.posts', 'sockit.groups'];
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

    protected function installFakeSocKit(?InstallationRef $installation = null): SocKitFake
    {
        return SocKitFake::install($this->app ?? throw new LogicException('Application not booted.'), $installation);
    }
}
