<?php

declare(strict_types=1);

namespace SocKit\Audience\Internal;

use Closure;
use Packman\Attributes\Internal;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use Throwable;

/**
 * Reconfirms every subject's local group dependencies after all external batch admissions finish.
 */
#[Internal]
final readonly class LocalGroupReadGuard
{
    private const int MAX_ATTEMPTS = 2;

    public function __construct(private ?GroupMembershipResolver $groups) {}

    /**
     * @param  list<AudienceSubject>  $subjects
     * @param  Closure(): array<string, AudienceVerdict>  $evaluate
     * @return array<string, AudienceVerdict>
     */
    public function evaluateMany(ActorContext $viewer, array $subjects, Closure $evaluate): array
    {
        $verdicts = [];
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $before = $this->snapshot($viewer, $subjects);
            $verdicts = $evaluate();
            $after = $this->snapshot($viewer, $subjects);
            $stable = true;

            foreach ($subjects as $subject) {
                if ($before[$subject->key] === null || $before[$subject->key] !== $after[$subject->key]) {
                    $stable = false;
                    $verdicts[$subject->key] = AudienceVerdict::deny('audience.unknown');
                }
            }

            if ($stable) {
                return $verdicts;
            }
        }

        // Keep only verdicts whose complete local dependencies survived the final attempt.
        return $verdicts;
    }

    /**
     * Includes exclusion branches and bound management surfaces, with no external admissions.
     * A null snapshot denotes a failed local read and must never authorize content.
     *
     * @param  list<AudienceSubject>  $subjects
     * @return array<string, string|null>
     */
    public function snapshot(ActorContext $viewer, array $subjects): array
    {
        $states = [];
        foreach ($subjects as $subject) {
            $groups = $subject->spec->referencedGroups();
            if ($groups === [] || ! $this->groups instanceof SnapshotsLocalGroupState) {
                $states[$subject->key] = '';

                continue;
            }

            try {
                $states[$subject->key] = $this->groups->snapshotLocalGroupState($viewer, $subject->surface->panel, $groups);
            } catch (Throwable) {
                $states[$subject->key] = null;
            }
        }

        return $states;
    }
}
