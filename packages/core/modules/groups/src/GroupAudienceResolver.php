<?php

declare(strict_types=1);

namespace SocKit\Groups;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Actors\Data\ActorContext;
use SocKit\Attributes\Api;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\Scope;
use SocKit\Groups\Internal\PanelGroupMembership;
use SocKit\Panels\PanelDefinitions;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;

/** Explicit host opt-in via AudienceResolver. Always enter through AudienceGate.
 * The existing scalar fallback gives mixed-panel batches identical hard guards.
 * Hosts owning another group namespace must explicitly route their AudienceResolver.
 */
#[Api(since: '1.0')]
final readonly class GroupAudienceResolver implements AudienceResolver
{
    public function __construct(private Application $app, private ContourResolver $contourResolver) {}

    public function decideRead(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict
    {
        return $this->decide($viewer, $subject, null);
    }

    public function decidePublish(ActorContext $author, AudienceSubject $subject, PrivacyFloor $floor): AudienceVerdict
    {
        // No group lock may be acquired later, after a content owner lock. The supported
        // write boundary is a content surface whose target is this exact group.
        if ($subject->spec->scope === Scope::Group && ($subject->surface->host->type !== 'sockit.group'
            || $subject->surface->incarnation->value !== 1 || $subject->surface->host->value !== $subject->spec->group?->value)) {
            return AudienceVerdict::deny('audience.denied.boundary_unproven');
        }

        return $this->decide($author, $subject, $floor);
    }

    private function decide(ActorContext $actor, AudienceSubject $subject, ?PrivacyFloor $floor): AudienceVerdict
    {
        try {
            $this->app->make(PanelDefinitions::class)->get($subject->surface->panel);
            $this->app->make(SurfaceDefinitions::class)->get($subject->surface->panel, $subject->surface->kind);
            $membership = new PanelGroupMembership($this->app, $subject->surface);
            $evaluator = new AudienceEvaluator($this->contourResolver, $membership);
            $verdict = $floor === null ? $evaluator->decideRead($actor, $subject) : $evaluator->decidePublish($actor, $subject, $floor);
            if (! $verdict->canRead() || $subject->spec->scope !== Scope::Group || $subject->spec->group === null) {
                return $verdict;
            }
            $boundary = $membership->access($actor, $subject->spec->group)->verdict;
            if ($floor !== null && ! $boundary->canWrite()) {
                return AudienceVerdict::deny('audience.denied.author_outside_audience');
            }

            return $boundary;
        } catch (\Throwable) {
            return AudienceVerdict::deny('audience.resolver_error');
        }
    }
}
