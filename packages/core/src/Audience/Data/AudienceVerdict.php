<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use SocKit\Attributes\Api;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class AudienceVerdict
{
    private const array REASONS = [
        'audience.allowed.public', 'audience.allowed.platform', 'audience.allowed.contour_live',
        'audience.allowed.contour_archived', 'audience.allowed.observer', 'audience.allowed.group', 'audience.allowed.direct',
        'audience.denied.anonymous', 'audience.denied.other_installation', 'audience.denied.not_in_contour',
        'audience.denied.archived', 'audience.denied.not_in_group', 'audience.denied.not_addressed',
        'audience.denied.scope_not_allowed', 'audience.denied.scope_too_wide', 'audience.denied.contour_level_unknown',
        'audience.denied.author_outside_audience', 'audience.denied.boundary_unproven', 'audience.denied.outside_surface',
        'audience.unknown', 'audience.resolver_error',
    ];

    public function __construct(public VerdictDecision $decision, public string $reason, public string $policyVersion = '1', public ?\DateTimeImmutable $expiresAt = null)
    {
        if (! in_array($reason, self::REASONS, true) || $policyVersion === ''
            || (($decision === VerdictDecision::Deny) === str_starts_with($reason, 'audience.allowed.'))
            || ($decision === VerdictDecision::Allow && in_array($reason, ['audience.allowed.observer', 'audience.allowed.contour_archived'], true))) {
            throw new InvalidPayloadException('Invalid audience verdict');
        }
    }

    public static function allow(string $reason): self
    {
        return new self(decision: VerdictDecision::Allow, reason: $reason);
    }

    public static function allowReadOnly(string $reason): self
    {
        return new self(decision: VerdictDecision::AllowReadOnly, reason: $reason);
    }

    public static function deny(string $reason): self
    {
        return new self(decision: VerdictDecision::Deny, reason: $reason);
    }

    public function canRead(): bool
    {
        return $this->decision !== VerdictDecision::Deny;
    }

    public function canWrite(): bool
    {
        return $this->decision === VerdictDecision::Allow;
    }
}
