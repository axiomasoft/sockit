<?php

declare(strict_types=1);

namespace SocKit\Audience;

use Closure;
use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Contracts\EvaluatesPanelGroupMembership;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Contracts\LocksGroupMembershipInTransaction;
use SocKit\Audience\Contracts\PanelGroupMembershipResolver;
use SocKit\Audience\Contracts\ResolvesAudienceInBatch;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\GroupRef;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\ContourMembership;
use SocKit\Audience\Enums\Scope;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Audience\Internal\LocalGroupReadGuard;
use SocKit\Audience\Internal\SnapshotsLocalGroupState;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\SurfaceRef;
use Throwable;

/**
 * Pure built-in audience set evaluator supporting atomic scopes and allOf/anyOf/exclude combinators.
 */
#[Api(since: '1.0')]
final readonly class AudienceEvaluator implements AudienceResolver, ResolvesAudienceInBatch
{
    /** @var list<string> */
    private const array PROVEN_EXCLUDE_DENIALS = [
        'audience.denied.anonymous',
        'audience.denied.other_installation',
        'audience.denied.not_addressed',
        'audience.denied.not_in_group',
        'audience.denied.not_in_contour',
        'audience.denied.archived',
        'audience.denied.excluded',
    ];

    public function __construct(
        private ?ContourResolver $contours = null,
        private ?GroupMembershipResolver $groups = null,
    ) {}

    #[Override]
    public function decideRead(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict
    {
        if (! $viewer->installation->equals($subject->installation)) {
            return AudienceVerdict::deny('audience.denied.other_installation');
        }

        return $this->evaluateWithStableGroups(
            $viewer,
            $subject,
            fn (): AudienceVerdict => $this->evaluateReadSpec($viewer, $subject->spec, $subject->archiveReaders, $subject->surface->panel),
        );
    }

    /**
     * @param  list<AudienceSubject>  $subjects
     * @return array<string, AudienceVerdict>
     */
    #[Override]
    public function decideReadMany(ActorContext $viewer, array $subjects): array
    {
        return new LocalGroupReadGuard($this->groups)->evaluateMany(
            $viewer,
            $subjects,
            fn (): array => $this->evaluateMany($viewer, $subjects),
        );
    }

    /**
     * @param  list<AudienceSubject>  $subjects
     * @return array<string, AudienceVerdict>
     */
    private function evaluateMany(ActorContext $viewer, array $subjects): array
    {
        $results = [];
        $memo = [];

        foreach ($subjects as $subject) {
            $cacheKey = $this->readGroupKey($viewer, $subject);

            $memo[$cacheKey] ??= $this->decideRead($viewer, $subject);

            $results[$subject->key] = $memo[$cacheKey];
        }

        return $results;
    }

    #[Override]
    public function decidePublish(ActorContext $author, AudienceSubject $subject, PrivacyFloor $floor): AudienceVerdict
    {
        if ($author->kind === ActorKind::Anonymous) {
            return AudienceVerdict::deny('audience.denied.anonymous');
        }

        if (! $author->installation->equals($subject->installation)) {
            return AudienceVerdict::deny('audience.denied.other_installation');
        }

        return $this->evaluateWithStableGroups(
            $author,
            $subject,
            fn (): AudienceVerdict => $this->evaluatePublishSpec($author, $subject->spec, $floor, $subject->surface->panel),
        );
    }

    /** @param Closure(): AudienceVerdict $evaluate */
    private function evaluateWithStableGroups(ActorContext $actor, AudienceSubject $subject, Closure $evaluate): AudienceVerdict
    {
        $groups = $subject->spec->referencedGroups();
        if ($groups === [] || ! $this->groups instanceof SnapshotsLocalGroupState) {
            return $evaluate();
        }

        try {
            $before = $this->groups->snapshotLocalGroupState($actor, $subject->surface->panel, $groups);
            $verdict = $evaluate();

            // Reconfirm every group after later external admissions, including groups used in exclusions.
            if ($before !== $this->groups->snapshotLocalGroupState($actor, $subject->surface->panel, $groups)) {
                return AudienceVerdict::deny('audience.unknown');
            }

            return $verdict;
        } catch (Throwable) {
            return AudienceVerdict::deny('audience.resolver_error');
        }
    }

    private function evaluateReadSpec(ActorContext $viewer, AudienceSpec $spec, bool $archiveReaders, PanelId $panel): AudienceVerdict
    {
        if ($spec->operator === 'all_of') {
            $combined = AudienceVerdict::allow('audience.allowed.all_of');

            foreach ($spec->children as $child) {
                $verdict = $this->evaluateReadSpec($viewer, $child, $archiveReaders, $panel);

                if (! $verdict->canRead()) {
                    return $verdict;
                }

                $combined = $combined->intersect($verdict);
            }

            return $combined;
        }

        if ($spec->operator === 'any_of') {
            $firstReadOnly = null;
            $firstIndeterminate = null;
            $firstDeny = null;

            foreach ($spec->children as $child) {
                $verdict = $this->evaluateReadSpec($viewer, $child, $archiveReaders, $panel);

                if ($verdict->decision === VerdictDecision::Allow) {
                    return $verdict;
                }

                if ($verdict->decision === VerdictDecision::AllowReadOnly) {
                    $firstReadOnly ??= $verdict;

                    continue;
                }

                if (in_array($verdict->reason, ['audience.resolver_error', 'audience.unknown'], true)) {
                    $firstIndeterminate ??= $verdict;
                } else {
                    $firstDeny ??= $verdict;
                }
            }

            return $firstReadOnly ?? $firstIndeterminate ?? $firstDeny ?? AudienceVerdict::deny('audience.unknown');
        }

        if ($spec->operator === 'exclude') {
            $include = $this->evaluateReadSpec($viewer, $spec->children[0], $archiveReaders, $panel);

            if (! $include->canRead()) {
                return $include;
            }

            $excluded = $this->evaluateReadSpec($viewer, $spec->children[1], $archiveReaders, $panel);

            if ($excluded->canRead()) {
                return AudienceVerdict::deny('audience.denied.excluded');
            }

            // Fail closed unless the exclusion check proved non-membership without resolver errors or missing SPIs.
            if (! in_array($excluded->reason, self::PROVEN_EXCLUDE_DENIALS, true)) {
                return $excluded;
            }

            return $include;
        }

        return match ($spec->scope) {
            Scope::Public => AudienceVerdict::allow('audience.allowed.public'),
            Scope::Platform => $viewer->kind === ActorKind::Anonymous
                ? AudienceVerdict::deny('audience.denied.anonymous')
                : AudienceVerdict::allow('audience.allowed.platform'),
            Scope::Direct => $this->evaluateDirectRead($viewer, $spec->actors),
            Scope::Group => $this->evaluateGroupRead($viewer, $spec, $panel),
            Scope::Contour => $this->evaluateContourRead($viewer, $spec, $archiveReaders),
            default => AudienceVerdict::deny('audience.unknown'),
        };
    }

    /** @param list<ActorRef> $actors */
    private function evaluateDirectRead(ActorContext $viewer, array $actors): AudienceVerdict
    {
        if ($viewer->kind === ActorKind::Anonymous || ! $viewer->actor instanceof ActorRef) {
            return AudienceVerdict::deny('audience.denied.anonymous');
        }

        foreach ($actors as $candidate) {
            if ($candidate->equals($viewer->actor)) {
                return AudienceVerdict::allow('audience.allowed.direct');
            }
        }

        return AudienceVerdict::deny('audience.denied.not_addressed');
    }

    private function evaluateGroupRead(ActorContext $viewer, AudienceSpec $spec, PanelId $panel): AudienceVerdict
    {
        if ($viewer->kind === ActorKind::Anonymous) {
            return AudienceVerdict::deny('audience.denied.anonymous');
        }

        if (! $this->groups instanceof GroupMembershipResolver || ! $spec->group instanceof GroupRef) {
            return AudienceVerdict::deny('audience.unknown');
        }

        if ($this->groups instanceof EvaluatesPanelGroupMembership) {
            try {
                return $this->groups->membershipVerdictInPanel($viewer, $panel, $spec->group);
            } catch (Throwable) {
                return AudienceVerdict::deny('audience.resolver_error');
            }
        }

        try {
            $isMember = $this->groups instanceof PanelGroupMembershipResolver
                ? $this->groups->isMemberInPanel($viewer, $panel, $spec->group)
                : $this->groups->isMember($viewer, $spec->group);
        } catch (Throwable) {
            return AudienceVerdict::deny('audience.resolver_error');
        }

        return $isMember
            ? AudienceVerdict::allow('audience.allowed.group')
            : AudienceVerdict::deny('audience.denied.not_in_group');
    }

    private function evaluateContourRead(ActorContext $viewer, AudienceSpec $spec, bool $archiveReaders): AudienceVerdict
    {
        if ($viewer->kind === ActorKind::Anonymous) {
            return AudienceVerdict::deny('audience.denied.anonymous');
        }

        if (! $this->contours instanceof ContourResolver || $spec->contourLevel === null || ! $spec->contour instanceof HostEntityRef) {
            return AudienceVerdict::deny('audience.unknown');
        }

        try {
            $membership = $this->contours->membership($viewer, $spec->contourLevel, $spec->contour);
        } catch (Throwable) {
            return AudienceVerdict::deny('audience.resolver_error');
        }

        return match ($membership) {
            ContourMembership::Live => AudienceVerdict::allow('audience.allowed.contour_live'),
            ContourMembership::ReadOnly => AudienceVerdict::allowReadOnly('audience.allowed.observer'),
            ContourMembership::Archived => $archiveReaders
                ? AudienceVerdict::allowReadOnly('audience.allowed.contour_archived')
                : AudienceVerdict::deny('audience.denied.archived'),
            ContourMembership::None => AudienceVerdict::deny('audience.denied.not_in_contour'),
        };
    }

    private function evaluatePublishSpec(ActorContext $author, AudienceSpec $spec, PrivacyFloor $floor, PanelId $panel): AudienceVerdict
    {
        if ($spec->operator === 'all_of' || $spec->operator === 'any_of') {
            $combined = AudienceVerdict::allow('audience.allowed.'.$spec->operator);

            foreach ($spec->children as $child) {
                $verdict = $this->evaluatePublishSpec($author, $child, $floor, $panel);

                if (! $verdict->canWrite()) {
                    return $verdict;
                }

                $combined = $combined->intersect($verdict);
            }

            return $combined;
        }

        if ($spec->operator === 'exclude') {
            return $this->evaluatePublishSpec($author, $spec->children[0], $floor, $panel);
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

        return match ($spec->scope) {
            Scope::Public => AudienceVerdict::allow('audience.allowed.public'),
            Scope::Platform => AudienceVerdict::allow('audience.allowed.platform'),
            Scope::Direct => AudienceVerdict::allow('audience.allowed.direct'),
            Scope::Group => $this->evaluateGroupPublish($author, $spec, $panel),
            Scope::Contour => $this->evaluateContourPublish($author, $spec, $floor),
        };
    }

    private function evaluateGroupPublish(ActorContext $author, AudienceSpec $spec, PanelId $panel): AudienceVerdict
    {
        if (! $this->groups instanceof GroupMembershipResolver || ! $spec->group instanceof GroupRef) {
            return AudienceVerdict::deny('audience.unknown');
        }

        if ($this->groups instanceof EvaluatesPanelGroupMembership) {
            try {
                $verdict = $this->groups->membershipVerdictInPanel($author, $panel, $spec->group);
            } catch (Throwable) {
                return AudienceVerdict::deny('audience.resolver_error');
            }

            if ($verdict->canWrite()) {
                return AudienceVerdict::allow('audience.allowed.group');
            }

            return AudienceVerdict::deny($verdict->decision === VerdictDecision::AllowReadOnly ? 'audience.denied.read_only' : $verdict->reason);
        }

        try {
            $isMember = $this->groups instanceof PanelGroupMembershipResolver
                ? $this->groups->isMemberInPanel($author, $panel, $spec->group)
                : $this->groups->isMember($author, $spec->group);
        } catch (Throwable) {
            return AudienceVerdict::deny('audience.resolver_error');
        }

        return $isMember
            ? AudienceVerdict::allow('audience.allowed.group')
            : AudienceVerdict::deny('audience.denied.author_outside_audience');
    }

    /**
     * Pre-sample external contour leaves outside any database transaction.
     *
     * @return array<string, AudienceVerdict>
     */
    public function sampleContours(ActorContext $viewer, AudienceSpec $spec, bool $archiveReaders = false): array
    {
        $samples = [];
        $this->collectContourSamples($viewer, $spec, $archiveReaders, $samples);

        return $samples;
    }

    /**
     * Resolve bound management surface descriptors for all referenced groups without acquiring row locks.
     *
     * @return array<string, SurfaceRef> Keyed by canonical surface wire encoding
     */
    public function managementSurfacesForGroups(PanelId $panel, AudienceSpec $spec): array
    {
        $groups = $spec->referencedGroups();
        if ($groups === [] || ! $this->groups instanceof LocksGroupMembershipInTransaction) {
            return [];
        }

        return $this->groups->managementSurfacesInPanel($panel, $groups);
    }

    /**
     * Lock all referenced groups in canonical primary-key order inside the current DB transaction and
     * re-evaluate group-coupled audience locally without external SPI calls.
     *
     * @param  array<string, AudienceVerdict>  $preSampledContours
     * @param  array<string, AudienceVerdict>|null  $preSampledSurfaceAdmissions
     */
    public function lockAndVerifyGroupsInTransaction(
        ActorContext $viewer,
        AudienceSpec $spec,
        PanelId $panel,
        array $preSampledContours = [],
        ?array $preSampledSurfaceAdmissions = null,
        bool $forPublish = false,
    ): AudienceVerdict {
        $groups = $spec->referencedGroups();

        if ($groups === []) {
            return AudienceVerdict::allow('audience.allowed.no_groups');
        }

        if (! $this->groups instanceof LocksGroupMembershipInTransaction) {
            return AudienceVerdict::deny('audience.unknown');
        }

        try {
            $this->groups->lockGroupsInTransaction($panel, $groups);
        } catch (Throwable) {
            return AudienceVerdict::deny('audience.resolver_error');
        }

        return $this->evaluateLocalSpecInTransaction(
            viewer: $viewer,
            spec: $spec,
            panel: $panel,
            preSampledContours: $preSampledContours,
            preSampledSurfaceAdmissions: $preSampledSurfaceAdmissions,
            forPublish: $forPublish,
        );
    }

    /** @param array<string, AudienceVerdict> $samples */
    private function collectContourSamples(ActorContext $viewer, AudienceSpec $spec, bool $archiveReaders, array &$samples): void
    {
        if ($spec->scope === Scope::Contour && $spec->contourLevel !== null && $spec->contour instanceof HostEntityRef) {
            $key = $spec->contourLevel.'|'.$spec->contour->toWire();
            $samples[$key] ??= $this->evaluateContourRead($viewer, $spec, $archiveReaders);

            return;
        }

        foreach ($spec->children as $child) {
            $this->collectContourSamples($viewer, $child, $archiveReaders, $samples);
        }
    }

    /**
     * @param  array<string, AudienceVerdict>  $preSampledContours
     * @param  array<string, AudienceVerdict>|null  $preSampledSurfaceAdmissions
     */
    private function evaluateLocalSpecInTransaction(
        ActorContext $viewer,
        AudienceSpec $spec,
        PanelId $panel,
        array $preSampledContours,
        ?array $preSampledSurfaceAdmissions,
        bool $forPublish,
    ): AudienceVerdict {
        if ($forPublish) {
            if ($spec->operator === 'all_of' || $spec->operator === 'any_of') {
                $combined = AudienceVerdict::allow('audience.allowed.'.$spec->operator);

                foreach ($spec->children as $child) {
                    $verdict = $this->evaluateLocalSpecInTransaction($viewer, $child, $panel, $preSampledContours, $preSampledSurfaceAdmissions, true);
                    if (! $verdict->canWrite()) {
                        return $verdict;
                    }

                    $combined = $combined->intersect($verdict);
                }

                return $combined;
            }

            if ($spec->operator === 'exclude') {
                return $this->evaluateLocalSpecInTransaction($viewer, $spec->children[0], $panel, $preSampledContours, $preSampledSurfaceAdmissions, true);
            }
        } else {
            if ($spec->operator === 'all_of') {
                $combined = AudienceVerdict::allow('audience.allowed.all_of');

                foreach ($spec->children as $child) {
                    $verdict = $this->evaluateLocalSpecInTransaction($viewer, $child, $panel, $preSampledContours, $preSampledSurfaceAdmissions, false);
                    if (! $verdict->canRead()) {
                        return $verdict;
                    }

                    $combined = $combined->intersect($verdict);
                }

                return $combined;
            }

            if ($spec->operator === 'any_of') {
                $firstReadOnly = null;
                $firstIndeterminate = null;
                $firstDeny = null;

                foreach ($spec->children as $child) {
                    $verdict = $this->evaluateLocalSpecInTransaction($viewer, $child, $panel, $preSampledContours, $preSampledSurfaceAdmissions, false);

                    if ($verdict->decision === VerdictDecision::Allow) {
                        return $verdict;
                    }

                    if ($verdict->decision === VerdictDecision::AllowReadOnly) {
                        $firstReadOnly ??= $verdict;

                        continue;
                    }

                    if (in_array($verdict->reason, ['audience.resolver_error', 'audience.unknown'], true)) {
                        $firstIndeterminate ??= $verdict;
                    } else {
                        $firstDeny ??= $verdict;
                    }
                }

                return $firstReadOnly ?? $firstIndeterminate ?? $firstDeny ?? AudienceVerdict::deny('audience.unknown');
            }

            if ($spec->operator === 'exclude') {
                $include = $this->evaluateLocalSpecInTransaction($viewer, $spec->children[0], $panel, $preSampledContours, $preSampledSurfaceAdmissions, false);
                if (! $include->canRead()) {
                    return $include;
                }

                $excluded = $this->evaluateLocalSpecInTransaction($viewer, $spec->children[1], $panel, $preSampledContours, $preSampledSurfaceAdmissions, false);
                if ($excluded->canRead()) {
                    return AudienceVerdict::deny('audience.denied.excluded');
                }

                if (! in_array($excluded->reason, self::PROVEN_EXCLUDE_DENIALS, true)) {
                    return $excluded;
                }

                return $include;
            }
        }

        return match ($spec->scope) {
            Scope::Public => AudienceVerdict::allow('audience.allowed.public'),
            Scope::Platform => $viewer->kind === ActorKind::Anonymous
                ? AudienceVerdict::deny('audience.denied.anonymous')
                : AudienceVerdict::allow('audience.allowed.platform'),
            Scope::Direct => $forPublish
                ? ($viewer->kind === ActorKind::Anonymous ? AudienceVerdict::deny('audience.denied.anonymous') : AudienceVerdict::allow('audience.allowed.direct'))
                : $this->evaluateDirectRead($viewer, $spec->actors),
            Scope::Group => $this->evaluateLockedGroup($viewer, $spec, $panel, $preSampledSurfaceAdmissions),
            Scope::Contour => $this->lookupSampledContour($spec, $preSampledContours),
            default => AudienceVerdict::deny('audience.unknown'),
        };
    }

    /** @param array<string, AudienceVerdict>|null $preSampledSurfaceAdmissions */
    private function evaluateLockedGroup(
        ActorContext $viewer,
        AudienceSpec $spec,
        PanelId $panel,
        ?array $preSampledSurfaceAdmissions,
    ): AudienceVerdict {
        if ($viewer->kind === ActorKind::Anonymous) {
            return AudienceVerdict::deny('audience.denied.anonymous');
        }

        if (! $this->groups instanceof LocksGroupMembershipInTransaction || ! $spec->group instanceof GroupRef) {
            return AudienceVerdict::deny('audience.unknown');
        }

        try {
            return $this->groups->membershipVerdictInTransaction($viewer, $panel, $spec->group, $preSampledSurfaceAdmissions);
        } catch (Throwable) {
            return AudienceVerdict::deny('audience.resolver_error');
        }
    }

    /** @param array<string, AudienceVerdict> $preSampledContours */
    private function lookupSampledContour(AudienceSpec $spec, array $preSampledContours): AudienceVerdict
    {
        if ($spec->contourLevel === null || ! $spec->contour instanceof HostEntityRef) {
            return AudienceVerdict::deny('audience.unknown');
        }

        $key = $spec->contourLevel.'|'.$spec->contour->toWire();

        return $preSampledContours[$key] ?? AudienceVerdict::deny('audience.unknown');
    }

    private function evaluateContourPublish(ActorContext $author, AudienceSpec $spec, PrivacyFloor $floor): AudienceVerdict
    {
        if ($spec->contourLevel === null || ! $spec->contour instanceof HostEntityRef) {
            return AudienceVerdict::deny('audience.unknown');
        }

        if (! $floor->allowsContourLevel($spec->contourLevel)) {
            return AudienceVerdict::deny('audience.denied.contour_level_unknown');
        }

        if (! $this->contours instanceof ContourResolver) {
            return AudienceVerdict::deny('audience.unknown');
        }

        try {
            $membership = $this->contours->membership($author, $spec->contourLevel, $spec->contour);
        } catch (Throwable) {
            return AudienceVerdict::deny('audience.resolver_error');
        }

        return $membership === ContourMembership::Live
            ? AudienceVerdict::allow('audience.allowed.contour_live')
            : AudienceVerdict::deny('audience.denied.author_outside_audience');
    }

    private function readGroupKey(ActorContext $viewer, AudienceSubject $subject): string
    {
        return hash('sha256', json_encode([
            'viewer' => $viewer->actor?->toWire(),
            'kind' => $viewer->kind->value,
            'installation' => $viewer->installation->id,
            'generation' => $viewer->generation,
            'panel' => $subject->surface->panel->value,
            'surface' => $subject->surface->toWire(),
            'subject_installation' => $subject->installation->id,
            'archive_readers' => $subject->archiveReaders,
            'spec' => $subject->spec->toWire(),
        ], JSON_THROW_ON_ERROR));
    }
}
