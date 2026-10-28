<?php

declare(strict_types=1);

use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ResolvesAudienceInBatch;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Testing\FakeAudienceResolver;
use SocKit\Testing\FakeSurfaceAudiencePolicy;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('keeps dynamically computed TTL from changing batch access decisions', function (): void {
    $resolver = new class implements AudienceResolver, ResolvesAudienceInBatch
    {
        /** @var list<AudienceVerdict> */
        public array $observed = [];

        public function decideRead(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict
        {
            // Distinct microseconds are guaranteed without sleeps or clock-dependent flakiness.
            $expiry = new DateTimeImmutable('+5 minutes')->modify('+'.count($this->observed).' microseconds');
            $verdict = new AudienceVerdict(decision: VerdictDecision::Allow, reason: 'audience.allowed.public', expiresAt: $expiry);
            $this->observed[] = $verdict;

            return $verdict;
        }

        /**
         * @param  list<AudienceSubject>  $subjects
         * @return array<string, AudienceVerdict>
         */
        public function decideReadMany(ActorContext $viewer, array $subjects): array
        {
            $result = [];
            foreach ($subjects as $subject) {
                $result[$subject->key] = $this->decideRead(viewer: $viewer, subject: $subject);
            }

            return $result;
        }

        public function decidePublish(ActorContext $author, AudienceSubject $subject, PrivacyFloor $floor): AudienceVerdict
        {
            throw new LogicException('Not used');
        }
    };
    $viewer = Scenario::viewer();
    $subject = Scenario::subject(Scenario::subjectWire());
    $gate = new AudienceGate(resolver: $resolver, surfacePolicy: (new FakeSurfaceAudiencePolicy)->allow(viewer: $viewer, surface: $subject->surface));
    $scalar = $gate->read(viewer: $viewer, subject: $subject);
    $batch = $gate->readMany(viewer: $viewer, subjects: [$subject])['p1'];

    expect($batch->decision)->toBe($scalar->decision)
        ->and($batch->reason)->toBe($scalar->reason)
        ->and($batch->policyVersion)->toBe($scalar->policyVersion)
        ->and($resolver->observed[1]->expiresAt)->not->toEqual($resolver->observed[2]->expiresAt)
        ->and($batch->expiresAt)->toEqual(min($resolver->observed[1]->expiresAt, $resolver->observed[2]->expiresAt));
});

it('uses the earliest finite TTL across batch scalar and surface admission', function (?string $batchTime, ?string $scalarTime, ?string $admissionTime, ?string $expected): void {
    $viewer = Scenario::viewer();
    $subject = Scenario::subject(Scenario::subjectWire());
    $batch = new AudienceVerdict(decision: VerdictDecision::Allow, reason: 'audience.allowed.public', expiresAt: $batchTime === null ? null : new DateTimeImmutable($batchTime));
    $scalar = new AudienceVerdict(decision: VerdictDecision::Allow, reason: 'audience.allowed.public', expiresAt: $scalarTime === null ? null : new DateTimeImmutable($scalarTime));
    $resolver = (new FakeAudienceResolver)->verdict(key: 'p1', verdict: $scalar)->batch(['p1' => $batch]);
    $policy = new readonly class($admissionTime) implements SurfaceAudiencePolicy
    {
        public function __construct(private ?string $expiry) {}

        public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict
        {
            return new AudienceVerdict(decision: VerdictDecision::AllowReadOnly, reason: 'audience.allowed.observer', expiresAt: $this->expiry === null ? null : new DateTimeImmutable($this->expiry));
        }

        public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision
        {
            return AuthorizationDecision::deny('host.read_only');
        }
    };
    $verdict = new AudienceGate(resolver: $resolver, surfacePolicy: $policy)->readMany(viewer: $viewer, subjects: [$subject])['p1'];
    expect($verdict->decision)->toBe(VerdictDecision::AllowReadOnly)
        ->and($verdict->reason)->toBe('audience.allowed.observer')
        ->and($verdict->expiresAt)->toEqual($expected === null ? null : new DateTimeImmutable($expected));
})->with([
    ['2030-01-01T00:00:00.000001Z', '2030-01-01T00:00:00.000002Z', null, '2030-01-01T00:00:00.000001Z'],
    ['2030-01-01T00:00:00.000002Z', '2030-01-01T00:00:00.000001Z', null, '2030-01-01T00:00:00.000001Z'],
    [null, '2030-01-01T00:00:00Z', null, '2030-01-01T00:00:00Z'],
    ['2030-01-01T00:00:00Z', null, null, '2030-01-01T00:00:00Z'],
    [null, null, null, null],
    ['2030-01-02T00:00:00Z', '2030-01-03T00:00:00Z', '2030-01-01T00:00:00Z', '2030-01-01T00:00:00Z'],
]);

it('still fails closed on a semantic batch mismatch even when its TTL matches', function (string $difference): void {
    $viewer = Scenario::viewer();
    $subject = Scenario::subject(Scenario::subjectWire());
    $expiry = new DateTimeImmutable('2030-01-01T00:00:00Z');
    $scalar = new AudienceVerdict(decision: VerdictDecision::Allow, reason: 'audience.allowed.public', expiresAt: $expiry);
    $batch = new AudienceVerdict(
        decision: $difference === 'decision' ? VerdictDecision::AllowReadOnly : VerdictDecision::Allow,
        reason: $difference === 'reason' ? 'audience.allowed.platform' : 'audience.allowed.public',
        policyVersion: $difference === 'policyVersion' ? '2' : '1',
        expiresAt: $expiry,
    );
    $resolver = (new FakeAudienceResolver)->verdict(key: 'p1', verdict: $scalar)->batch(['p1' => $batch]);
    $gate = new AudienceGate(resolver: $resolver, surfacePolicy: (new FakeSurfaceAudiencePolicy)->allow(viewer: $viewer, surface: $subject->surface));
    expect($gate->readMany(viewer: $viewer, subjects: [$subject])['p1']->reason)->toBe('audience.unknown');
})->with(['decision', 'reason', 'policyVersion']);
