<?php

declare(strict_types=1);

namespace SocKit\Panels;

use Illuminate\Contracts\Container\Container;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Contracts\Registry\PanelProvider as PackmanPanelProvider;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Actors\Exceptions\UnresolvedActorException;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Headless Packman PanelCatalogue provider exposing SocKit's panel descriptor and fail-closed admission evaluation.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class SockitPanelProvider implements PackmanPanelProvider
{
    /** @var list<string> */
    private const array SUPPORTED_ACTIONS = [
        'view',
        'sockit.posts.publish',
        'sockit.posts.edit',
        'sockit.reactions.add',
        'sockit.reactions.remove',
        'sockit.groups.manage',
    ];

    public function __construct(
        private ?ActorContextResolver $actors = null,
        private ?TenantResolver $tenants = null,
        private ?ActionAuthorizer $authorizer = null,
        private ?Container $container = null,
    ) {}

    #[Override]
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'sockit',
            owningModule: 'axiomasoft/sockit',
            requiredCapability: null,
            supportedActions: self::SUPPORTED_ACTIONS,
            label: 'SocKit Social Engine',
        );
    }

    #[Override]
    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        if ($context->actorKind === 'guest') {
            return PanelAccess::deny('unauthenticated_actor');
        }

        try {
            $actors = $this->resolveActorResolver();
            $tenants = $this->resolveTenantResolver();
            $authorizer = $this->resolveAuthorizer();
        } catch (OperationException) {
            return PanelAccess::deny('provider_unavailable');
        }

        if (! $actors instanceof ActorContextResolver || ! $tenants instanceof TenantResolver || ! $authorizer instanceof ActionAuthorizer) {
            return PanelAccess::deny('provider_unavailable');
        }

        try {
            $actor = $actors->current();
        } catch (UnresolvedActorException) {
            return PanelAccess::deny('unauthenticated_actor');
        }

        if (! $actor->installation->equals($tenants->current())) {
            return PanelAccess::deny('installation_mismatch');
        }

        $panelId = str_contains($panel->panelId, '.') ? $panel->panelId : 'sockit.'.$panel->panelId;
        $surface = new SurfaceRef(
            panel: new PanelId($panelId),
            kind: new SurfaceKindId('sockit.panel'),
            host: new HostEntityRef('sockit.panel', $panel->panelId),
            incarnation: Incarnation::initial(),
        );

        $permitted = ['view'];

        foreach (self::SUPPORTED_ACTIONS as $action) {
            if ($action === 'view') {
                continue;
            }

            if ($authorizer->decide(new ActionRequest($actor, $action, $surface))->isAllowed()) {
                $permitted[] = $action;
            }
        }

        return new PanelAccess(
            view: true,
            actions: $permitted,
            reason: null,
        );
    }

    private function resolveActorResolver(): ?ActorContextResolver
    {
        if ($this->actors instanceof ActorContextResolver) {
            return $this->actors;
        }

        if ($this->container instanceof Container && $this->container->bound(ActorContextResolver::class)) {
            return $this->container->make(ActorContextResolver::class);
        }

        return null;
    }

    private function resolveTenantResolver(): ?TenantResolver
    {
        if ($this->tenants instanceof TenantResolver) {
            return $this->tenants;
        }

        if ($this->container instanceof Container && $this->container->bound(TenantResolver::class)) {
            return $this->container->make(TenantResolver::class);
        }

        return null;
    }

    private function resolveAuthorizer(): ?ActionAuthorizer
    {
        if ($this->authorizer instanceof ActionAuthorizer) {
            return $this->authorizer;
        }

        if ($this->container instanceof Container && $this->container->bound(ActionAuthorizer::class)) {
            return $this->container->make(ActionAuthorizer::class);
        }

        return null;
    }
}
