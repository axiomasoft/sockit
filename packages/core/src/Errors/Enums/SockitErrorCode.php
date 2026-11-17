<?php

declare(strict_types=1);

namespace SocKit\Errors\Enums;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;
use Packman\Errors\Enums\ErrorCategory;
use SocKit\Errors\Data\ProblemSpec;

/**
 * Stable wire error taxonomy for SocKit runtime, privacy, panel, surface, and module failures.
 */
#[Api(since: '1.0')]
enum SockitErrorCode: string implements ErrorCode
{
    case AuthenticationRequired = 'sockit.authentication_required';
    case ActorContextInvalid = 'sockit.actor_context_invalid';
    case AuthorizationDenied = 'sockit.authorization_denied';
    case AuthorizationUnknown = 'sockit.authorization_unknown';
    case AudienceDenied = 'sockit.audience_denied';
    case AudienceNotContained = 'sockit.audience_not_contained';
    case AudienceVersionUnknown = 'sockit.audience_version_unknown';
    case SurfaceNotFound = 'sockit.surface_not_found';
    case SurfaceArchived = 'sockit.surface_archived';
    case SurfaceIncarnationMismatch = 'sockit.surface_incarnation_mismatch';
    case BackendBindingMismatch = 'sockit.backend_binding_mismatch';
    case ContentHeld = 'sockit.content_held';
    case ContentHidden = 'sockit.content_hidden';
    case ContentDeleted = 'sockit.content_deleted';
    case ContentErased = 'sockit.content_erased';
    case RevisionConflict = 'sockit.revision_conflict';
    case IdempotencyConflict = 'sockit.idempotency_conflict';
    case ProviderUnavailable = 'sockit.provider_unavailable';
    case ExternalOutcomeUnknown = 'sockit.external_outcome_unknown';
    case EventIdentityConflict = 'sockit.event_identity_conflict';
    case ConfigurationInvalid = 'sockit.configuration_invalid';
    case PayloadInvalid = 'sockit.payload_invalid';
    case WireVersionUnsupported = 'sockit.wire_version_unsupported';
    case ModuleInvalid = 'sockit.module_invalid';
    case PanelNotFound = 'sockit.panel_not_found';
    case PanelBindingMismatch = 'sockit.panel_binding_mismatch';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }

    public function spec(): ProblemSpec
    {
        return new ProblemSpec(
            code: $this,
            httpStatus: $this->defaultStatus(),
            retryable: $this->isRetryable(),
            receiptRecorded: $this->receiptRecorded(),
            title: $this->title(),
        );
    }

    public function defaultStatus(): int
    {
        return match ($this) {
            self::AuthenticationRequired => 401,
            self::ActorContextInvalid,
            self::AuthorizationDenied,
            self::AudienceDenied => 403,
            self::SurfaceNotFound,
            self::ContentHidden,
            self::PanelNotFound => 404,
            self::SurfaceArchived,
            self::SurfaceIncarnationMismatch,
            self::BackendBindingMismatch,
            self::ContentHeld,
            self::RevisionConflict,
            self::IdempotencyConflict,
            self::EventIdentityConflict,
            self::PanelBindingMismatch => 409,
            self::ContentDeleted,
            self::ContentErased => 410,
            self::AudienceNotContained,
            self::AudienceVersionUnknown,
            self::PayloadInvalid,
            self::WireVersionUnsupported => 422,
            self::ConfigurationInvalid,
            self::ModuleInvalid => 500,
            self::AuthorizationUnknown,
            self::ProviderUnavailable,
            self::ExternalOutcomeUnknown => 503,
        };
    }

    public function isRetryable(): bool
    {
        return match ($this) {
            self::AuthorizationUnknown,
            self::ProviderUnavailable,
            self::ExternalOutcomeUnknown => true,
            default => false,
        };
    }

    public function receiptRecorded(): bool
    {
        return match ($this) {
            self::AuthorizationDenied,
            self::AudienceDenied,
            self::AudienceNotContained,
            self::SurfaceArchived,
            self::SurfaceIncarnationMismatch,
            self::BackendBindingMismatch,
            self::ContentHeld,
            self::RevisionConflict,
            self::ExternalOutcomeUnknown,
            self::PanelBindingMismatch => true,
            default => false,
        };
    }

    public function category(): ErrorCategory
    {
        return match ($this) {
            self::AuthenticationRequired => ErrorCategory::Authentication,
            self::ActorContextInvalid,
            self::AuthorizationDenied,
            self::AudienceDenied => ErrorCategory::Authorization,
            self::AudienceNotContained,
            self::PayloadInvalid => ErrorCategory::Validation,
            self::AudienceVersionUnknown,
            self::WireVersionUnsupported => ErrorCategory::Unsupported,
            self::SurfaceNotFound,
            self::ContentHidden,
            self::ContentDeleted,
            self::ContentErased,
            self::PanelNotFound => ErrorCategory::NotFound,
            self::SurfaceArchived,
            self::SurfaceIncarnationMismatch,
            self::BackendBindingMismatch,
            self::ContentHeld,
            self::RevisionConflict,
            self::IdempotencyConflict,
            self::EventIdentityConflict,
            self::PanelBindingMismatch => ErrorCategory::Conflict,
            self::AuthorizationUnknown,
            self::ProviderUnavailable,
            self::ExternalOutcomeUnknown => ErrorCategory::Unavailable,
            self::ConfigurationInvalid,
            self::ModuleInvalid => ErrorCategory::Internal,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::AuthenticationRequired => 'Authentication required',
            self::ActorContextInvalid => 'Invalid actor or installation context',
            self::AuthorizationDenied => 'Action authorization denied',
            self::AuthorizationUnknown => 'Authorization outcome unknown',
            self::AudienceDenied => 'Audience policy denied',
            self::AudienceNotContained => 'Audience specification not contained within floor',
            self::AudienceVersionUnknown => 'Unsupported audience wire version',
            self::SurfaceNotFound => 'Surface not found',
            self::SurfaceArchived => 'Surface is archived',
            self::SurfaceIncarnationMismatch => 'Surface incarnation mismatch',
            self::BackendBindingMismatch => 'Surface backend binding mismatch',
            self::ContentHeld => 'Content held for safety review',
            self::ContentHidden => 'Content not available',
            self::ContentDeleted => 'Content deleted',
            self::ContentErased => 'Content erased',
            self::RevisionConflict => 'Expected revision conflict',
            self::IdempotencyConflict => 'Command idempotency payload mismatch',
            self::ProviderUnavailable => 'Required host provider unavailable',
            self::ExternalOutcomeUnknown => 'External outcome unknown',
            self::EventIdentityConflict => 'Event identity payload mismatch',
            self::ConfigurationInvalid => 'Invalid SocKit configuration',
            self::PayloadInvalid => 'Invalid command or wire payload',
            self::WireVersionUnsupported => 'Unsupported wire version',
            self::ModuleInvalid => 'Invalid SocKit module definition',
            self::PanelNotFound => 'Panel not found',
            self::PanelBindingMismatch => 'Panel binding mismatch',
        };
    }
}
