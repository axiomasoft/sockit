<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ResolvesAudienceInBatch;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\PrivacyFloor;

final class FeedPolicy implements AudienceResolver, ResolvesAudienceInBatch
{
    public ?\Closure $afterBatch = null;

    public ?\Closure $afterScalar = null;

    /** @var list<string> */
    public array $denied = [];

    /** @var list<list<AudienceSubject>> */
    public array $batches = [];

    public string $mode = 'normal';

    public function __construct(private readonly AudienceResolver $delegate) {}

    public function decideRead(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict
    {
        $verdict = $this->delegate->decideRead($viewer, $subject);
        if ($this->afterScalar !== null && $subject->key === 'posts.current') {
            $hook = $this->afterScalar;
            $this->afterScalar = null;
            $hook();
        }

        return $verdict;
    }

    public function decideReadMany(ActorContext $viewer, array $subjects): array
    {
        $this->batches[] = $subjects;
        if ($this->mode === 'error') {
            throw new \RuntimeException('Unavailable batch');
        }
        $result = [];
        foreach ($subjects as $subject) {
            if ($this->mode !== 'missing') {
                $result[$subject->key] = in_array($subject->key, $this->denied, true) ? AudienceVerdict::deny('audience.unknown') : $this->delegate->decideRead($viewer, $subject);
            }
        }
        if ($this->afterBatch !== null) {
            $hook = $this->afterBatch;
            $this->afterBatch = null;
            $hook();
        }

        return $result;
    }

    public function decidePublish(ActorContext $author, AudienceSubject $subject, PrivacyFloor $floor): AudienceVerdict
    {
        return $this->delegate->decidePublish($author, $subject, $floor);
    }
}
