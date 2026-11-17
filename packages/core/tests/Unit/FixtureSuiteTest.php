<?php

declare(strict_types=1);

use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\PrivacyDescriptor;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Operations\Data\OperationResult;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Data\WireVersion;
use SocKit\Surfaces\Data\SurfaceRef;

/** @param array<string, mixed> $fixture */
function verifySockitFixtureContract(array $fixture): void
{
    $contract = $fixture['contract'] ?? null;

    match ($contract) {
        'sockit.fixture.refs/v1' => (static function () use ($fixture): void {
            foreach ($fixture['host_entity_refs'] as $raw) {
                expect(HostEntityRef::fromWire((string) $raw)->toWire())->toBe($raw);
            }
            foreach ($fixture['actor_refs'] as $raw) {
                expect(ActorRef::fromWire((string) $raw)->toWire())->toBe($raw);
            }
            foreach ($fixture['installation_refs'] as $raw) {
                expect((new InstallationRef((string) $raw))->id)->toBe($raw);
            }
            foreach ($fixture['resource_refs'] as $wire) {
                expect(ResourceRef::fromWire($wire)->toWire())->toBe($wire);
            }
            foreach ($fixture['revisions'] as $rev) {
                expect(Revision::fromWire((int) $rev)->toWire())->toBe($rev);
            }
        })(),
        'sockit.fixture.command/v1' => (static function () use ($fixture): void {
            expect(WireVersion::fromWire((string) $fixture['wire_version'])->toWire())->toBe($fixture['wire_version']);
            foreach ($fixture['command_keys'] as $raw) {
                expect((new CommandKey((string) $raw))->value)->toBe($raw);
            }
            foreach ($fixture['command_namespaces'] as $raw) {
                expect(CommandNamespace::fromWire((string) $raw)->toWire())->toBe($raw);
            }
        })(),
        'sockit.fixture.digest/v1' => (static function () use ($fixture): void {
            expect(count($fixture['vectors']))->toBeGreaterThanOrEqual(3);
            foreach ($fixture['vectors'] as $vector) {
                $canonical = CanonicalJson::encode($vector['payload']);
                $digest = PayloadDigest::of((string) $vector['command_type'], $vector['payload']);
                expect($canonical)->toBe($vector['expected_canonical'])
                    ->and($digest->value)->toBe($vector['expected_digest']);
            }
        })(),
        'sockit.fixture.floor/v1' => (static function () use ($fixture): void {
            expect(PrivacyFloor::fromWire($fixture['default_direct']))->toEqual(PrivacyFloor::defaultDirect());
            $parent = PrivacyFloor::fromWire($fixture['narrowing']['parent']);
            $child = PrivacyFloor::fromWire($fixture['narrowing']['child']);
            $expected = PrivacyFloor::fromWire($fixture['narrowing']['expected']);
            expect($parent->narrowedBy($child))->toEqual($expected);
        })(),
        'sockit.fixture.descriptor/v1' => (static function () use ($fixture): void {
            foreach ($fixture['descriptors'] as $wire) {
                expect(PrivacyDescriptor::fromContractArray($wire)->toContractArray())->toBe($wire);
            }
        })(),
        'sockit.fixture.audience/v1' => (static function () use ($fixture): void {
            foreach ($fixture['specs'] as $wire) {
                expect(AudienceSpec::fromWire($wire)->toWire())->toBe($wire);
            }
        })(),
        'sockit.fixture.outcome/v1' => (static function () use ($fixture): void {
            foreach ($fixture['results'] as $entry) {
                $result = OperationResult::fromWire($entry['wire']);
                expect($result->isSuccess())->toBe($entry['expected_success'])
                    ->and($result->toWire())->toBe($entry['wire']);
            }
        })(),
        'sockit.fixture.problems/v1' => (static function () use ($fixture): void {
            expect(count($fixture['codes']))->toBe(count(SockitErrorCode::cases()));
            foreach ($fixture['codes'] as $entry) {
                $code = SockitErrorCode::from((string) $entry['code']);
                $spec = $code->spec();
                expect($spec->httpStatus)->toBe($entry['http_status'])
                    ->and($spec->retryable)->toBe($entry['retryable'])
                    ->and($spec->receiptRecorded)->toBe($entry['receipt_recorded']);
            }
        })(),
        'sockit.fixture.surfaces/v1' => (static function () use ($fixture): void {
            foreach ($fixture['surface_refs'] as $wire) {
                expect(SurfaceRef::fromWire($wire)->toWire())->toBe($wire);
            }
        })(),
        default => throw new InvalidArgumentException('Unknown SocKit fixture contract: '.(is_string($contract) ? $contract : 'null')),
    };
}

test('contract fixture suite validates every shipped fixture and fails closed on unknown contract identifiers', function (): void {
    $pattern = dirname(__DIR__, 2).'/resources/contracts/fixtures/*/*.json';
    $files = glob($pattern);

    expect($files)->toBeArray()->not->toBeEmpty();

    foreach ($files as $file) {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        verifySockitFixtureContract($decoded);
    }

    expect(fn () => verifySockitFixtureContract(['contract' => 'sockit.fixture.unknown/v99']))
        ->toThrow(InvalidArgumentException::class);
});
