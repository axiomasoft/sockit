<?php

declare(strict_types=1);

use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Enums\OperationOutcome;
use SocKit\Operations\Exceptions\InvalidOperationResultException;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;

it('distinguishes confirmed from other outcomes', function (): void {
    $resource = new ResourceRef(panel: new PanelId('acme.main'), kind: ResourceKind::Post, id: 'p1');
    expect(OperationResult::confirmed(receiptId: 'r1', resource: $resource, revision: new Revision(1))->isSuccess())->toBeTrue()
        ->and(OperationResult::rejected(ProblemCode::RevisionConflict)->isSuccess())->toBeFalse()
        ->and(OperationResult::pending('r1')->isSuccess())->toBeFalse()
        ->and(OperationResult::unknown('r1')->isSuccess())->toBeFalse();
});

it('rejects pending without a receipt', function (): void {
    new OperationResult(outcome: OperationOutcome::Pending, receiptId: null, resource: null, revision: null, problem: null);
})->throws(InvalidOperationResultException::class);

it('rejects malformed wire', function (): void {
    OperationResult::fromWire(['outcome' => 'confirmed']);
})->throws(InvalidOperationResultException::class);
