<?php

declare(strict_types=1);

namespace SocKit\Audience;

use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ResolvesAudienceInBatch;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\AuthorizationStatus;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Audience\Internal\PublicationFloor;
use SocKit\Support\Exceptions\InvalidPayloadException;

/** Sole audience entry for core/modules. Lifecycle, action authorization and safety remain owner guards.
 * Context and subject must come from trusted host resolution, never directly from a command payload.
 */
#[Api(since: '1.0')]
final readonly class AudienceGate
{
    public function __construct(private AudienceResolver $resolver, private ?SurfaceAudiencePolicy $surfacePolicy = null) {}

    public function read(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict
    {
        try {
            $admission = $this->admission(viewer: $viewer, subject: $subject);
            if (! $admission->canRead()) {
                return $admission;
            }

            $verdict = $this->intersect(admission: $admission, content: $this->resolver->decideRead(viewer: $viewer, subject: $subject), subject: $subject);

            return $verdict->canRead() && ! $verdict->isCurrent() ? AudienceVerdict::deny('audience.unknown') : $verdict;
        } catch (\Throwable) {
            return AudienceVerdict::deny('audience.resolver_error');
        }
    }

    /**
     * @param  list<AudienceSubject>  $subjects
     * @return array<string, AudienceVerdict>
     */
    public function readMany(ActorContext $viewer, array $subjects): array
    {
        $keys = [];
        foreach ($subjects as $subject) {
            if (isset($keys[$subject->key])) {
                throw new InvalidPayloadException('Duplicate audience result key');
            }
            $keys[$subject->key] = true;
        }
        if (! $this->resolver instanceof ResolvesAudienceInBatch) {
            $result = [];
            foreach ($subjects as $subject) {
                $result[$subject->key] = $this->read(viewer: $viewer, subject: $subject);
            }

            return $this->currentReads($result);
        }
        $result = $admissions = $eligible = [];
        foreach ($subjects as $subject) {
            try {
                $admission = $this->admission(viewer: $viewer, subject: $subject);
                if ($admission->canRead()) {
                    $admissions[$subject->key] = $admission;
                    $eligible[] = $subject;
                } else {
                    $result[$subject->key] = $admission;
                }
            } catch (\Throwable) {
                $result[$subject->key] = AudienceVerdict::deny('audience.resolver_error');
            }
        }
        if ($eligible !== []) {
            try {
                $batch = $this->resolver->decideReadMany(viewer: $viewer, subjects: $eligible);
            } catch (\Throwable) {
                $batch = [];
                foreach ($eligible as $subject) {
                    $result[$subject->key] = AudienceVerdict::deny('audience.resolver_error');
                }
            }
            foreach ($eligible as $subject) {
                if (isset($result[$subject->key])) {
                    continue;
                }
                try {
                    $content = $batch[$subject->key] ?? null;
                    // P1 contract verification: an incoherent capability must never widen scalar access.
                    // No verdict cache or cross-panel grouping. Missing/malformed keys fail closed.
                    if (! $content instanceof AudienceVerdict) {
                        $result[$subject->key] = AudienceVerdict::deny('audience.unknown');
                    } else {
                        $scalar = $this->resolver->decideRead(viewer: $viewer, subject: $subject);
                        $result[$subject->key] = $this->intersect(admission: $admissions[$subject->key], content: $this->reconcile(batch: $content, scalar: $scalar), subject: $subject);
                    }
                } catch (\Throwable) {
                    $result[$subject->key] = AudienceVerdict::deny('audience.resolver_error');
                }
            }
        }
        $ordered = [];
        foreach ($subjects as $subject) {
            $ordered[$subject->key] = $result[$subject->key];
        }

        return $this->currentReads($ordered);
    }

    /** @param array<string, AudienceVerdict> $verdicts
     * @return array<string, AudienceVerdict> */
    private function currentReads(array $verdicts): array
    {
        foreach ($verdicts as $key => $verdict) {
            if ($verdict->canRead() && ! $verdict->isCurrent()) {
                $verdicts[$key] = AudienceVerdict::deny('audience.unknown');
            }
        }

        return $verdicts;
    }

    public function publish(ActorContext $author, AudienceSubject $subject, PrivacyFloor $floor): AudienceVerdict
    {
        try {
            $admission = $this->admission(viewer: $author, subject: $subject);
            if (! $admission->canRead()) {
                return $admission;
            }
            if ($subject->surfaceArchived) {
                return AudienceVerdict::deny('audience.denied.archived');
            }
            if ($author->kind === ActorKind::Anonymous) {
                return AudienceVerdict::deny('audience.denied.anonymous');
            }
            if (! $admission->canWrite()) {
                return AudienceVerdict::deny('audience.denied.author_outside_audience');
            }
            if (($denied = PublicationFloor::denial(author: $author, spec: $subject->spec, floor: $floor)) !== null) {
                return $denied;
            }
            $boundary = $this->surfacePolicy?->contains(actor: $author, surface: $subject->surface, proposed: $subject->spec);
            if ($boundary === null || $boundary->status === AuthorizationStatus::Unknown) {
                return AudienceVerdict::deny('audience.denied.boundary_unproven');
            }
            if (! $boundary->isAllowed()) {
                return AudienceVerdict::deny('audience.denied.outside_surface');
            }
            $content = $this->resolver->decidePublish(author: $author, subject: $subject, floor: $floor);
            if ($content->decision === VerdictDecision::AllowReadOnly) {
                return AudienceVerdict::deny('audience.denied.author_outside_audience');
            }
            // An expired allow still reports canRead/canWrite. Reads already fail closed; publish must too.
            if (! $admission->isCurrent() || ($content->canRead() && ! $content->isCurrent())) {
                return AudienceVerdict::deny('audience.unknown');
            }

            return $content;
        } catch (\Throwable) {
            return AudienceVerdict::deny('audience.resolver_error');
        }
    }

    private function admission(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict
    {
        if (! $subject->installation->equals($viewer->installation)) {
            return AudienceVerdict::deny('audience.denied.other_installation');
        }

        return $this->surfacePolicy?->admit(viewer: $viewer, surface: $subject->surface) ?? AudienceVerdict::deny('audience.unknown');
    }

    private function intersect(AudienceVerdict $admission, AudienceVerdict $content, AudienceSubject $subject): AudienceVerdict
    {
        if (! $content->canRead()) {
            return $content;
        }
        $readOnly = $subject->surfaceArchived || ! $admission->canWrite() || ! $content->canWrite();

        return new AudienceVerdict(
            decision: $readOnly ? VerdictDecision::AllowReadOnly : $content->decision,
            reason: ! $admission->canWrite() ? $admission->reason : $content->reason,
            policyVersion: $content->policyVersion,
            expiresAt: $this->earliestExpiry(left: $admission->expiresAt, right: $content->expiresAt),
            checkpoints: [...$admission->checkpoints, ...$content->checkpoints],
        );
    }

    private function reconcile(AudienceVerdict $batch, AudienceVerdict $scalar): AudienceVerdict
    {
        if ($batch->decision !== $scalar->decision || $batch->reason !== $scalar->reason || $batch->policyVersion !== $scalar->policyVersion) {
            return AudienceVerdict::deny('audience.unknown');
        }

        // TTL is metadata computed at each invocation, not part of the access decision.
        // A missing deadline never discards the other resolver's finite deadline.
        return new AudienceVerdict(
            decision: $batch->decision,
            reason: $batch->reason,
            policyVersion: $batch->policyVersion,
            expiresAt: $this->earliestExpiry(left: $batch->expiresAt, right: $scalar->expiresAt),
            checkpoints: [...$batch->checkpoints, ...$scalar->checkpoints],
        );
    }

    private function earliestExpiry(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): ?\DateTimeImmutable
    {
        if ($left === null) {
            return $right;
        }

        return $right === null || $left <= $right ? $left : $right;
    }
}
