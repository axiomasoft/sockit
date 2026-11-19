<?php

declare(strict_types=1);

namespace SocKit\Testkit\Concerns\Conformance;

use Packman\Attributes\Api;
use Packman\Testkit\Concerns\Conformance\PluginContractTests;
use PHPUnit\Framework\Assert;

/**
 * Extends Packman's {@see PluginContractTests} with SocKit manifest namespace checks.
 */
#[Api(since: '1.0')]
trait SockitPluginContractTests
{
    use PluginContractTests;

    public function test_sockit_plugin_uses_sockit_manifest_namespace(): void
    {
        $id = $this->pluginUnderTest()->manifest()->id;

        Assert::assertStringStartsWith('axiomasoft.sockit.', $id);
    }
}
