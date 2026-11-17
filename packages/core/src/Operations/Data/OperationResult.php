<?php

declare(strict_types=1);

namespace SocKit\Operations\Data;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Operations\Enums\OperationOutcome;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Exceptions\InvalidPayloadException;

/**
 * Explicit four-state operation result. Only Confirmed ever counts as success.
 */
#[Api(since: '1.0')]
final readonly class OperationResult
{
    /** @param array<string, scalar|list<scalar|null>|null> $problemDetail */
    public function __construct(
        public OperationOutcome $outcome,
        public ?string $receiptId,
        public ?ResourceRef $resource,
        public ?Revision $revision,
        public ?SockitErrorCode $problem,
        public array $problemDetail = [],
    ) {
        if ($outcome === OperationOutcome::Confirmed && (! $resource instanceof ResourceRef || $problem instanceof SockitErrorCode)) {
            throw InvalidPayloadException::invalid('Confirmed OperationResult requires a ResourceRef and null problem.');
        }

        if ($outcome === OperationOutcome::Rejected && ! $problem instanceof SockitErrorCode) {
            throw InvalidPayloadException::invalid('Rejected OperationResult requires a SockitErrorCode.');
        }

        if ($outcome === OperationOutcome::Pending && ($receiptId === null || $receiptId === '')) {
            throw InvalidPayloadException::invalid('Pending OperationResult requires a receiptId.');
        }

        if ($outcome === OperationOutcome::Unknown && $problem !== SockitErrorCode::ExternalOutcomeUnknown) {
            throw InvalidPayloadException::invalid('Unknown OperationResult requires problem sockit.external_outcome_unknown.');
        }
    }

    public static function confirmed(string $receiptId, ResourceRef $resource, Revision $revision): self
    {
        return new self(
            outcome: OperationOutcome::Confirmed,
            receiptId: $receiptId,
            resource: $resource,
            revision: $revision,
            problem: null,
        );
    }

    /** @param array<string, scalar|list<scalar|null>|null> $detail */
    public static function rejected(SockitErrorCode $problem, array $detail = [], ?string $receiptId = null): self
    {
        return new self(
            outcome: OperationOutcome::Rejected,
            receiptId: $receiptId,
            resource: null,
            revision: null,
            problem: $problem,
            problemDetail: $detail,
        );
    }

    public static function pending(string $receiptId, ?ResourceRef $resource = null): self
    {
        return new self(
            outcome: OperationOutcome::Pending,
            receiptId: $receiptId,
            resource: $resource,
            revision: null,
            problem: null,
        );
    }

    public static function unknown(string $receiptId): self
    {
        return new self(
            outcome: OperationOutcome::Unknown,
            receiptId: $receiptId,
            resource: null,
            revision: null,
            problem: SockitErrorCode::ExternalOutcomeUnknown,
        );
    }

    public function isSuccess(): bool
    {
        return $this->outcome === OperationOutcome::Confirmed;
    }

    /** @param array<array-key, mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $rawOutcome = $wire['outcome'] ?? null;

        if (! is_string($rawOutcome)) {
            throw InvalidPayloadException::invalid('OperationResult wire requires string outcome.');
        }

        $outcome = OperationOutcome::tryFrom($rawOutcome);

        if ($outcome === null) {
            throw InvalidPayloadException::invalid("Unknown OperationOutcome [{$rawOutcome}].");
        }

        $receiptId = isset($wire['receipt_id']) && is_string($wire['receipt_id']) ? $wire['receipt_id'] : null;
        $resource = isset($wire['resource']) && is_array($wire['resource']) ? ResourceRef::fromWire($wire['resource']) : null;
        $revision = isset($wire['revision']) && is_int($wire['revision']) ? new Revision($wire['revision']) : null;
        $problem = isset($wire['problem']) && is_string($wire['problem']) ? SockitErrorCode::tryFrom($wire['problem']) : null;

        if (isset($wire['problem']) && is_string($wire['problem']) && $problem === null) {
            throw InvalidPayloadException::invalid("Unknown SockitErrorCode [{$wire['problem']}].");
        }

        $rawDetail = $wire['problem_detail'] ?? [];
        /** @var array<string, scalar|list<scalar|null>|null> $detail */
        $detail = is_array($rawDetail) ? $rawDetail : [];

        return new self(
            outcome: $outcome,
            receiptId: $receiptId,
            resource: $resource,
            revision: $revision,
            problem: $problem,
            problemDetail: $detail,
        );
    }

    /**
     * @return array{
     *     outcome: string,
     *     receipt_id: ?string,
     *     resource: ?array{panel: string, kind: string, id: string},
     *     revision: ?int,
     *     problem: ?string,
     *     problem_detail: array<string, scalar|list<scalar|null>|null>,
     * }
     */
    public function toWire(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'receipt_id' => $this->receiptId,
            'resource' => $this->resource?->toWire(),
            'revision' => $this->revision?->value,
            'problem' => $this->problem?->value,
            'problem_detail' => $this->problemDetail,
        ];
    }
}
