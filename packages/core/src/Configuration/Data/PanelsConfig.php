<?php

declare(strict_types=1);

namespace SocKit\Configuration\Data;

use SocKit\Attributes\Api;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Panels\PanelProvider;

#[Api(since: '1.0')]
final readonly class PanelsConfig
{
    /** @var list<class-string<PanelProvider>> */
    public array $providers;

    /** @param list<class-string> $providers */
    public function __construct(array $providers)
    {
        $validated = [];
        foreach ($providers as $provider) {
            if (! is_subclass_of($provider, PanelProvider::class) || ! new \ReflectionClass($provider)->isInstantiable()) {
                throw new InvalidConfigurationException(message: 'Invalid panel provider implementation', detail: ['key' => 'sockit.panels', 'provider' => $provider, 'rule' => 'PANEL_PROVIDER_INVALID']);
            }
            $validated[] = $provider;
        }
        $this->providers = $validated;
    }
}
