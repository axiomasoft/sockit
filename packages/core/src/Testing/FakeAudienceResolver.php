<?php

declare(strict_types=1);

namespace SocKit\Testing;

use SocKit\Actors\Data\ActorContext;
use SocKit\Attributes\Api;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ResolvesAudienceInBatch;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final class FakeAudienceResolver implements AudienceResolver, ResolvesAudienceInBatch
{
    /**
     * @var array<string, AudienceVerdict> */
    private array $verdicts = [];

    /**
     * @var array<string, AudienceVerdict>|null */
    private ?array $batch = null;

    /**
     * @var list<array{operation: string, keys: list<string>}> */
    private array $invocations = [];

    private ?\Throwable $failure = null;

    public function verdict(string $key, AudienceVerdict $verdict): self
    {
        $this->verdicts[$key] = $verdict;

        return $this;
    }

    /**
     * @param  array<string, AudienceVerdict>  $verdicts  Override to test missing/incoherent batch output.
     */
    public function batch(array $verdicts): self
    {
        $this->batch = $verdicts;

        return $this;
    }

    public function failWith(\Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    /**
     * @return list<array{operation: string, keys: list<string>}>
     */
    public function calls(): array
    {
        return $this->invocations;
    }

    public function decideRead(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict
    {
        $this->record(operation: 'read', keys: [$subject->key]);

        return $this->verdicts[$subject->key] ?? AudienceVerdict::deny('audience.unknown');
    }

    public function decidePublish(ActorContext $author, AudienceSubject $subject, PrivacyFloor $floor): AudienceVerdict
    {
        $this->record(operation: 'publish', keys: [$subject->key]);

        return $this->verdicts[$subject->key] ?? AudienceVerdict::deny('audience.unknown');
    }

    /**
     * @param  list<AudienceSubject>  $subjects
     * @return array<string, AudienceVerdict>
     */
    public function decideReadMany(ActorContext $viewer, array $subjects): array
    {
        $this->record(operation: 'batch', keys: array_map(fn (AudienceSubject $subject): string => $subject->key, $subjects));
        if ($this->batch !== null) {
            return $this->batch;
        }
        $result = [];
        foreach ($subjects as $subject) {
            if (array_key_exists($subject->key, $result)) {
                throw new InvalidPayloadException('Duplicate audience result key');
            }
            $result[$subject->key] = $this->verdicts[$subject->key] ?? AudienceVerdict::deny('audience.unknown');
        }

        return $result;
    }

    /**
     * @param  list<string>  $keys
     */
    private function record(string $operation, array $keys): void
    {
        $this->invocations[] = ['operation' => $operation, 'keys' => $keys];
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
