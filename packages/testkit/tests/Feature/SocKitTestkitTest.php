<?php

declare(strict_types=1);

namespace SocKit\Testkit\Tests\Feature;

use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use SocKit\Audience\AllowAuthenticatedPersonAuthorizer;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Plugins\SockitCorePlugin;
use SocKit\Testkit\Concerns\Conformance\AudienceDeciderContractTests;
use SocKit\Testkit\Concerns\Conformance\AuthorizationDeciderContractTests;
use SocKit\Testkit\Concerns\Conformance\SockitPluginContractTests;
use SocKit\Testkit\Data\SocialFixtureBuilder;
use SocKit\Testkit\SocKitTestCase;

final class SocKitTestkitTest extends SocKitTestCase
{
    use AudienceDeciderContractTests;
    use AuthorizationDeciderContractTests;
    use SockitPluginContractTests;

    protected function audienceDeciderUnderTest(): AudienceResolver
    {
        $fake = $this->installFakeSocKit();

        return new AudienceEvaluator($fake->contours, $fake->groups);
    }

    protected function authorizerUnderTest(): ActionAuthorizer
    {
        return new AllowAuthenticatedPersonAuthorizer;
    }

    protected function pluginUnderTest(): ExtensionPlugin
    {
        return new SockitCorePlugin;
    }

    public function test_social_fixture_builder_builds_valid_surface_and_commands(): void
    {
        $builder = SocialFixtureBuilder::make()
            ->panel('app.community')
            ->surface('sockit.feed', 'group', 'group-42');

        $surface = $builder->surfaceRef();
        $post = $builder->publishPost('Hello SocKit');
        $group = $builder->createGroup('engineers');

        self::assertSame('app.community', $surface->panel->value);
        self::assertSame('sockit.feed', $surface->kind->value);
        self::assertSame('group:group-42', $surface->host->toWire());
        self::assertSame('Hello SocKit', $post->body);
        self::assertSame('engineers', $group->name);
    }
}
