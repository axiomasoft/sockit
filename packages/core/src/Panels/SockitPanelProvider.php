<?php

declare(strict_types=1);

namespace SocKit\Panels;

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
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Headless Packman PanelCatalogue provider exposing SocKit's panel descriptor and admission evaluation.
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

        if (! $this->actors instanceof ActorContextResolver || ! $this->tenants instanceof TenantResolver || ! $this->authorizer instanceof ActionAuthorizer) {
            return new PanelAccess(
                view: true,
                actions: ['view'],
                reason: null,
            );
        }

        try {
            $actor = $this->actors->current();
        } catch (UnresolvedActorException) {
            return PanelAccess::deny('unauthenticated_actor');
        }

        if (! $actor->installation->equals($this->tenants->current())) {
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

            if ($this->authorizer->decide(new ActionRequest($actor, $action, $surface))->isAllowed()) {
                $permitted[] = $action;
            }
        }

        return new PanelAccess(
            view: true,
            actions: $permitted,
            reason: null,
        );
    }
}
