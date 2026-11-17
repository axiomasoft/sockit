<?php

declare(strict_types=1);

namespace SocKit\Audience;

use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Contracts\GroupMembershipResolver;
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
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
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

        return $this->evaluateReadSpec($viewer, $subject->spec, $subject->archiveReaders, $subject->surface->panel);
    }

    /**
     * @param  list<AudienceSubject>  $subjects
     * @return array<string, AudienceVerdict>
     */
    #[Override]
    public function decideReadMany(ActorContext $viewer, array $subjects): array
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

        return $this->evaluatePublishSpec($author, $subject->spec, $floor, $subject->surface->panel);
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
