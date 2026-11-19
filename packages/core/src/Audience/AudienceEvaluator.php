<?php

declare(strict_types=1);

namespace SocKit\Audience;

use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Contracts\ResolvesAudienceInBatch;
use SocKit\Audience\Contracts\ResolvesGroupVerdicts;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\ContourMembership;
use SocKit\Audience\Enums\Scope;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Audience\Internal\PublicationFloor;
use SocKit\Support\Exceptions\InvalidPayloadException;

/** Content audience evaluation only. Always enter through AudienceGate for hard surface boundaries. */
#[Api(since: '1.0')]
final readonly class AudienceEvaluator implements AudienceResolver, ResolvesAudienceInBatch
{
    public function __construct(private ContourResolver $contours, private GroupMembershipResolver $groups) {}

    public function decideRead(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict
    {
        if (! $viewer->installation->equals($subject->installation)) {
            return AudienceVerdict::deny('audience.denied.other_installation');
        }
        if ($subject->spec->operator !== null) {
            return $this->expression($viewer, $subject, null);
        }
        if ($subject->spec->scope === Scope::Public) {
            return AudienceVerdict::allow('audience.allowed.public');
        }
        if ($viewer->kind === ActorKind::Anonymous) {
            return AudienceVerdict::deny('audience.denied.anonymous');
        }

        if ($subject->spec->scope === Scope::Group && $subject->spec->group !== null && $this->groups instanceof ResolvesGroupVerdicts) {
            return $this->groups->decideMembership($viewer, $subject->spec->group);
        }

        return match ($subject->spec->scope) {
            Scope::Platform => AudienceVerdict::allow('audience.allowed.platform'),
            Scope::Direct => array_any($subject->spec->actors, $viewer->sameActor(...))
                ? AudienceVerdict::allow('audience.allowed.direct') : AudienceVerdict::deny('audience.denied.not_addressed'),
            Scope::Group => $subject->spec->group !== null && $this->groups->isMember(actor: $viewer, group: $subject->spec->group)
                ? AudienceVerdict::allow('audience.allowed.group') : AudienceVerdict::deny('audience.denied.not_in_group'),
            Scope::Contour => $this->contourVerdict(viewer: $viewer, subject: $subject),
            null => AudienceVerdict::deny('audience.unknown'),
        };
    }

    public function decidePublish(ActorContext $author, AudienceSubject $subject, PrivacyFloor $floor): AudienceVerdict
    {
        if (! $author->installation->equals($subject->installation)) {
            return AudienceVerdict::deny('audience.denied.other_installation');
        }
        if ($subject->surfaceArchived) {
            return AudienceVerdict::deny('audience.denied.archived');
        }
        if ($author->kind === ActorKind::Anonymous) {
            return AudienceVerdict::deny('audience.denied.anonymous');
        }
        if (($denied = PublicationFloor::denial(author: $author, spec: $subject->spec, floor: $floor)) !== null) {
            return $denied;
        }
        if ($subject->spec->operator !== null) {
            return $this->expression($author, $subject, $floor);
        }
        if ($subject->spec->scope === Scope::Direct) {
            return AudienceVerdict::allow('audience.allowed.direct');
        }
        $verdict = $this->decideRead(viewer: $author, subject: $subject);

        return $verdict->canWrite() ? $verdict : AudienceVerdict::deny('audience.denied.author_outside_audience');
    }

    /**
     * @param  list<AudienceSubject>  $subjects
     * @return array<string, AudienceVerdict>
     */
    public function decideReadMany(ActorContext $viewer, array $subjects): array
    {
        $result = [];
        foreach ($subjects as $subject) {
            if (array_key_exists($subject->key, $result)) {
                throw new InvalidPayloadException('Duplicate audience result key');
            }
            try {
                $result[$subject->key] = $this->decideRead(viewer: $viewer, subject: $subject);
            } catch (\Throwable) {
                $result[$subject->key] = AudienceVerdict::deny('audience.resolver_error');
            }
        }

        return $result;
    }

    private function expression(ActorContext $actor, AudienceSubject $subject, ?PrivacyFloor $floor): AudienceVerdict
    {
        $evaluate = function (AudienceSpec $spec) use ($actor, $subject, $floor): AudienceVerdict {
            $child = new AudienceSubject($subject->key, $spec, $subject->author, $subject->surface, $subject->installation,
                $subject->surfaceArchived, $subject->archiveReaders);

            try {
                return $floor === null ? $this->decideRead($actor, $child) : $this->decidePublish($actor, $child, $floor);
            } catch (\Throwable) {
                return AudienceVerdict::deny('audience.resolver_error');
            }
        };
        $spec = $subject->spec;
        if ($spec->operator === 'exclude') {
            $include = $evaluate($spec->children[0]);
            if (! $include->canRead() || $floor !== null) {
                return $include;
            }
            $excluded = $evaluate($spec->children[1]);
            if ($excluded->canRead()) {
                return AudienceVerdict::deny('audience.denied.excluded')->withEvidence($excluded);
            }

            return in_array($excluded->reason, ['audience.denied.anonymous', 'audience.denied.not_addressed', 'audience.denied.not_in_group',
                'audience.denied.not_in_contour', 'audience.denied.archived', 'audience.denied.excluded'], true)
                ? $include->withEvidence($excluded) : $excluded;
        }
        if ($spec->operator === 'any_of' && $floor === null) {
            $readonly = $denied = $unknown = null;
            foreach ($spec->children as $child) {
                $verdict = $evaluate($child);
                if ($verdict->canWrite()) {
                    return $verdict;
                }
                if ($verdict->canRead()) {
                    $readonly ??= $verdict;
                } elseif (in_array($verdict->reason, ['audience.unknown', 'audience.resolver_error'], true)) {
                    $unknown ??= $verdict;
                } else {
                    $denied = $denied?->withEvidence($verdict) ?? $verdict;
                }
            }

            return $readonly ?? $unknown ?? $denied ?? AudienceVerdict::deny('audience.unknown');
        }
        $result = AudienceVerdict::allow('audience.allowed.'.$spec->operator);
        foreach ($spec->children as $child) {
            $verdict = $evaluate($child);
            if (! $verdict->canRead() || ($floor !== null && ! $verdict->canWrite())) {
                return $verdict;
            }
            $result = $result->decision === VerdictDecision::AllowReadOnly ? $result->withEvidence($verdict) : $verdict->withEvidence($result);
        }

        return $result;
    }

    private function contourVerdict(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict
    {
        $spec = $subject->spec;
        if ($spec->contour === null || $spec->contourLevel === null) {
            return AudienceVerdict::deny('audience.unknown');
        }

        return match ($this->contours->membership(actor: $viewer, level: $spec->contourLevel, contour: $spec->contour)) {
            ContourMembership::Live => AudienceVerdict::allow('audience.allowed.contour_live'),
            ContourMembership::ReadOnly => AudienceVerdict::allowReadOnly('audience.allowed.observer'),
            ContourMembership::Archived => $subject->archiveReaders ? AudienceVerdict::allowReadOnly('audience.allowed.contour_archived') : AudienceVerdict::deny('audience.denied.archived'),
            ContourMembership::None => AudienceVerdict::deny('audience.denied.not_in_contour'),
        };
    }
}
