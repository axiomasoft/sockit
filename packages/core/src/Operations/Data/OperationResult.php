<?php

declare(strict_types=1);

namespace SocKit\Operations\Data;

use SocKit\Attributes\Api;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Enums\OperationOutcome;
use SocKit\Operations\Exceptions\InvalidOperationResultException;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class OperationResult
{
    /** @param array<string, mixed> $problemDetail */
    public function __construct(public OperationOutcome $outcome, public ?string $receiptId, public ?ResourceRef $resource, public ?Revision $revision, public ?ProblemCode $problem, public array $problemDetail = [])
    {
        if (($outcome === OperationOutcome::Confirmed && ($resource === null || $problem !== null))
            || ($outcome === OperationOutcome::Rejected && $problem === null)
            || ($outcome === OperationOutcome::Pending && $receiptId === null)
            || ($outcome === OperationOutcome::Unknown && $problem !== ProblemCode::ExternalOutcomeUnknown)) {
            throw new InvalidOperationResultException('Invalid operation result');
        }
    }

    public static function confirmed(string $receiptId, ResourceRef $resource, Revision $revision): self
    {
        return new self(outcome: OperationOutcome::Confirmed, receiptId: $receiptId, resource: $resource, revision: $revision, problem: null);
    }

    /** @param array<string, mixed> $detail */
    public static function rejected(ProblemCode $problem, array $detail = [], ?string $receiptId = null): self
    {
        return new self(outcome: OperationOutcome::Rejected, receiptId: $receiptId, resource: null, revision: null, problem: $problem, problemDetail: $detail);
    }

    public static function pending(string $receiptId, ?ResourceRef $resource = null): self
    {
        return new self(outcome: OperationOutcome::Pending, receiptId: $receiptId, resource: $resource, revision: null, problem: null);
    }

    public static function unknown(string $receiptId): self
    {
        return new self(outcome: OperationOutcome::Unknown, receiptId: $receiptId, resource: null, revision: null, problem: ProblemCode::ExternalOutcomeUnknown);
    }

    public function isSuccess(): bool
    {
        return $this->outcome === OperationOutcome::Confirmed;
    }

    /** @return array{outcome: string, receipt_id: ?string, resource: ?array{panel: string, kind: string, id: string}, revision: ?int, problem: ?string, problem_detail: array<string, mixed>} */
    public function toWire(): array
    {
        return ['outcome' => $this->outcome->value, 'receipt_id' => $this->receiptId, 'resource' => $this->resource?->toWire(), 'revision' => $this->revision?->value, 'problem' => $this->problem?->value, 'problem_detail' => $this->problemDetail];
    }

    /** @param array<string, mixed> $w */
    public static function fromWire(array $w): self
    {
        foreach (['outcome', 'receipt_id', 'resource', 'revision', 'problem', 'problem_detail'] as $key) {
            if (! array_key_exists($key, $w)) {
                throw new InvalidOperationResultException('Missing operation result field');
            }
        }
        $outcome = is_string($w['outcome']) ? OperationOutcome::tryFrom($w['outcome']) : null;
        $problem = is_string($w['problem']) ? ProblemCode::tryFrom($w['problem']) : null;
        if ($outcome === null || ($w['receipt_id'] !== null && ! is_string($w['receipt_id'])) || ($w['problem'] !== null && $problem === null) || ($w['revision'] !== null && ! is_int($w['revision'])) || ($w['resource'] !== null && ! is_array($w['resource'])) || ! is_array($w['problem_detail'])) {
            throw new InvalidOperationResultException('Invalid operation result wire');
        }
        foreach ($w['problem_detail'] as $key => $_) {
            if (! is_string($key)) {
                throw new InvalidOperationResultException('Invalid problem detail');
            }
        }
        try {
            $resource = null;
            if (is_array($w['resource'])) {
                $resourceWire = [];
                foreach ($w['resource'] as $key => $value) {
                    if (! is_string($key)) {
                        throw new InvalidOperationResultException('Invalid resource wire');
                    }
                    $resourceWire[$key] = $value;
                }
                $resource = ResourceRef::fromWire($resourceWire);
            }
            $revision = is_int($w['revision']) ? Revision::fromWire($w['revision']) : null;
        } catch (InvalidPayloadException $e) {
            throw new InvalidOperationResultException(message: 'Invalid operation result wire', previous: $e);
        }

        return new self(outcome: $outcome, receiptId: $w['receipt_id'], resource: $resource, revision: $revision, problem: $problem, problemDetail: $w['problem_detail']);
    }
}
