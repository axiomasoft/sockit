<?php

declare(strict_types=1);

namespace SocKit\Audience;

use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ResolvesAudienceInBatch;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\Scope;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;
use Throwable;

/**
 * Mandatory fail-closed audience gate enforcing installation, panel/surface admission,
 * containment, privacy floors, archival downgrade, and error isolation around any {@see AudienceResolver}.
 */
#[Api(since: '1.0')]
final readonly class AudienceGate
{
    public function __construct(
        private AudienceResolver $resolver,
        private ?SurfaceAudiencePolicy $surfacePolicy = null,
    ) {}

    public function admitForRead(ActorContext $actor, SurfaceRef $surface): AudienceVerdict
    {
        return $this->callAdmit($actor, $surface);
    }

    public function admitForWrite(ActorContext $actor, SurfaceRef $surface): AudienceVerdict
    {
        if ($actor->kind === ActorKind::Anonymous) {
            return AudienceVerdict::deny('audience.denied.anonymous');
        }

        $admission = $this->callAdmit($actor, $surface);

        if ($admission->decision === VerdictDecision::AllowReadOnly) {
            return AudienceVerdict::deny('audience.denied.read_only');
        }

        return $admission;
    }

    public function read(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict
    {
        if (! $viewer->installation->equals($subject->installation)) {
            return AudienceVerdict::deny('audience.denied.other_installation');
        }

        $admission = $this->admitSurface($viewer, $subject);

        if (! $admission->canRead()) {
            return $admission;
        }

        try {
            $content = $this->resolver->decideRead($viewer, $subject);
        } catch (Throwable) {
            return AudienceVerdict::deny('audience.resolver_error');
        }

        return $this->finalizeRead($admission, $content, $subject->surfaceArchived);
    }

    /**
     * @param  list<AudienceSubject>  $subjects
     * @return array<string, AudienceVerdict>
     */
    public function readMany(ActorContext $viewer, array $subjects): array
    {
        if ($subjects === []) {
            return [];
        }

        $seenKeys = [];
        $admissions = [];
        $admittedSubjects = [];
        $surfaceCache = [];

        foreach ($subjects as $subject) {
            if (isset($seenKeys[$subject->key])) {
                throw InvalidPayloadException::invalid("Duplicate AudienceSubject key [{$subject->key}] in batch.", ['key' => $subject->key]);
            }

            $seenKeys[$subject->key] = true;

            if (! $viewer->installation->equals($subject->installation)) {
                $admissions[$subject->key] = AudienceVerdict::deny('audience.denied.other_installation');

                continue;
            }

            $surfaceKey = hash('sha256', json_encode($subject->surface->toWire(), JSON_THROW_ON_ERROR));

            $surfaceCache[$surfaceKey] ??= $this->admitSurface($viewer, $subject);

            $admission = $surfaceCache[$surfaceKey];
            $admissions[$subject->key] = $admission;

            if ($admission->canRead()) {
                $admittedSubjects[] = $subject;
            }
        }

        $contentVerdicts = [];

        if ($admittedSubjects !== []) {
            if ($this->resolver instanceof ResolvesAudienceInBatch) {
                try {
                    $contentVerdicts = $this->resolver->decideReadMany($viewer, $admittedSubjects);
                } catch (Throwable) {
                    $contentVerdicts = [];
                    foreach ($admittedSubjects as $subject) {
                        $contentVerdicts[$subject->key] = AudienceVerdict::deny('audience.resolver_error');
                    }
                }
            } else {
                foreach ($admittedSubjects as $subject) {
                    try {
                        $contentVerdicts[$subject->key] = $this->resolver->decideRead($viewer, $subject);
                    } catch (Throwable) {
                        $contentVerdicts[$subject->key] = AudienceVerdict::deny('audience.resolver_error');
                    }
                }
            }
        }

        $final = [];

        foreach ($subjects as $subject) {
            $admission = $admissions[$subject->key];

            if (! $admission->canRead()) {
                $final[$subject->key] = $admission;

                continue;
            }

            $content = $contentVerdicts[$subject->key] ?? AudienceVerdict::deny('audience.unknown');
            $final[$subject->key] = $this->finalizeRead($admission, $content, $subject->surfaceArchived);
        }

        return $final;
    }

    public function publish(ActorContext $author, AudienceSubject $subject, PrivacyFloor $floor): AudienceVerdict
    {
        if ($author->kind === ActorKind::Anonymous) {
            return AudienceVerdict::deny('audience.denied.anonymous');
        }

        if (! $author->installation->equals($subject->installation)) {
            return AudienceVerdict::deny('audience.denied.other_installation');
        }

        if ($subject->surfaceArchived) {
            return AudienceVerdict::deny('audience.denied.archived');
        }

        $admission = $this->admitSurface($author, $subject);

        if ($admission->decision === VerdictDecision::AllowReadOnly) {
            return AudienceVerdict::deny('audience.denied.read_only');
        }

        if (! $admission->canWrite()) {
            return $admission;
        }

        if (! $this->surfacePolicy instanceof SurfaceAudiencePolicy) {
            return AudienceVerdict::deny('audience.unknown');
        }

        try {
            $containment = $this->surfacePolicy->contains($author, $subject->surface, $subject->spec);
        } catch (Throwable) {
            return AudienceVerdict::deny('audience.resolver_error');
        }

        if ($containment->isUnknown()) {
            return AudienceVerdict::deny('audience.denied.boundary_unproven');
        }

        if (! $containment->isAllowed()) {
            return AudienceVerdict::deny('audience.denied.outside_surface');
        }

        $floorDenial = $this->enforcePublishFloor($author, $subject->spec, $floor);
        if ($floorDenial instanceof AudienceVerdict) {
            return $floorDenial;
        }

        try {
            $decision = $this->resolver->decidePublish($author, $subject, $floor);
        } catch (Throwable) {
            return AudienceVerdict::deny('audience.resolver_error');
        }

        if ($decision->decision === VerdictDecision::AllowReadOnly) {
            return AudienceVerdict::deny('audience.denied.read_only');
        }

        return $decision;
    }

    private function enforcePublishFloor(ActorContext $author, AudienceSpec $spec, PrivacyFloor $floor): ?AudienceVerdict
    {
        if ($spec->operator === 'all_of' || $spec->operator === 'any_of') {
            foreach ($spec->children as $child) {
                $denial = $this->enforcePublishFloor($author, $child, $floor);
                if ($denial instanceof AudienceVerdict) {
                    return $denial;
                }
            }

            return null;
        }

        if ($spec->operator === 'exclude') {
            return $this->enforcePublishFloor($author, $spec->children[0], $floor);
        }

        if (! $spec->scope instanceof Scope) {
            return AudienceVerdict::deny('audience.unknown');
        }

        if (! $floor->allowsScope($spec->scope)) {
            return AudienceVerdict::deny('audience.denied.scope_not_allowed');
        }

        if ($spec->scope->isWiderThan($floor->maxFor($author))) {
            return AudienceVerdict::deny('audience.denied.scope_too_wide');
        }

        if ($spec->scope === Scope::Contour && ($spec->contourLevel === null || ! $floor->allowsContourLevel($spec->contourLevel))) {
            return AudienceVerdict::deny('audience.denied.contour_level_unknown');
        }

        return null;
    }

    private function admitSurface(ActorContext $actor, AudienceSubject $subject): AudienceVerdict
    {
        return $this->callAdmit($actor, $subject->surface);
    }

    private function callAdmit(ActorContext $actor, SurfaceRef $surface): AudienceVerdict
    {
        if (! $this->surfacePolicy instanceof SurfaceAudiencePolicy) {
            return AudienceVerdict::deny('audience.unknown');
        }

        try {
            return $this->surfacePolicy->admit($actor, $surface);
        } catch (Throwable) {
            return AudienceVerdict::deny('audience.resolver_error');
        }
    }

    private function finalizeRead(AudienceVerdict $admission, AudienceVerdict $content, bool $surfaceArchived): AudienceVerdict
    {
        $combined = $admission->intersect($content);

        if ($surfaceArchived && $combined->canRead()) {
            return $combined->downgradeToReadOnly();
        }

        return $combined;
    }

    /**
     * Pre-sample contour memberships outside SQL transactions for group-coupled target lock checks.
     *
     * @return array<string, AudienceVerdict>
     */
    public function sampleContours(ActorContext $actor, AudienceSpec $spec, bool $archiveReaders = false): array
    {
        if ($this->resolver instanceof AudienceEvaluator) {
            return $this->resolver->sampleContours($actor, $spec, $archiveReaders);
        }

        return [];
    }

    /**
     * Lock referenced groups inside the current SQL transaction and verify local group membership.
     *
     * @param  array<string, AudienceVerdict>  $preSampledContours
     */
    public function reverifyLockedGroupsInTransaction(
        ActorContext $actor,
        AudienceSpec $spec,
        PanelId $panel,
        array $preSampledContours = [],
    ): AudienceVerdict {
        if ($spec->referencedGroups() === []) {
            return AudienceVerdict::allow('audience.allowed.no_groups');
        }

        if (! $this->resolver instanceof AudienceEvaluator) {
            return AudienceVerdict::deny('audience.unknown');
        }

        return $this->resolver->lockAndVerifyGroupsInTransaction($actor, $spec, $panel, $preSampledContours);
    }
}
