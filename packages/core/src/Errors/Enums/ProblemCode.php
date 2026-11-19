<?php

declare(strict_types=1);

namespace SocKit\Errors\Enums;

use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;
use Packman\Errors\Enums\ErrorCategory;
use SocKit\Errors\Data\ProblemSpec;

#[Api]
enum ProblemCode: string implements ErrorCode
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

    public function value(): string
    {
        return $this->value;
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

    public function spec(): ProblemSpec
    {
        return match ($this) {
            self::AuthenticationRequired => new ProblemSpec(code: $this->value, httpStatus: 401, retryable: false, receiptRecorded: false, title: 'Authentication Required'),
            self::ActorContextInvalid => new ProblemSpec(code: $this->value, httpStatus: 403, retryable: false, receiptRecorded: false, title: 'Actor Context Invalid'),
            self::AuthorizationDenied => new ProblemSpec(code: $this->value, httpStatus: 403, retryable: false, receiptRecorded: true, title: 'Authorization Denied'),
            self::AuthorizationUnknown => new ProblemSpec(code: $this->value, httpStatus: 503, retryable: true, receiptRecorded: false, title: 'Authorization Unknown'),
            self::AudienceDenied => new ProblemSpec(code: $this->value, httpStatus: 403, retryable: false, receiptRecorded: true, title: 'Audience Denied'),
            self::AudienceNotContained => new ProblemSpec(code: $this->value, httpStatus: 422, retryable: false, receiptRecorded: true, title: 'Audience Not Contained'),
            self::AudienceVersionUnknown => new ProblemSpec(code: $this->value, httpStatus: 422, retryable: false, receiptRecorded: false, title: 'Audience Version Unknown'),
            self::SurfaceNotFound => new ProblemSpec(code: $this->value, httpStatus: 404, retryable: false, receiptRecorded: false, title: 'Surface Not Found'),
            self::SurfaceArchived => new ProblemSpec(code: $this->value, httpStatus: 409, retryable: false, receiptRecorded: true, title: 'Surface Archived'),
            self::SurfaceIncarnationMismatch => new ProblemSpec(code: $this->value, httpStatus: 409, retryable: false, receiptRecorded: true, title: 'Surface Incarnation Mismatch'),
            self::BackendBindingMismatch => new ProblemSpec(code: $this->value, httpStatus: 409, retryable: false, receiptRecorded: true, title: 'Backend Binding Mismatch'),
            self::ContentHeld => new ProblemSpec(code: $this->value, httpStatus: 202, retryable: false, receiptRecorded: true, title: 'Content Held'),
            self::ContentHidden => new ProblemSpec(code: $this->value, httpStatus: 404, retryable: false, receiptRecorded: false, title: 'Content Hidden'),
            self::ContentDeleted => new ProblemSpec(code: $this->value, httpStatus: 410, retryable: false, receiptRecorded: false, title: 'Content Deleted'),
            self::ContentErased => new ProblemSpec(code: $this->value, httpStatus: 410, retryable: false, receiptRecorded: false, title: 'Content Erased'),
            self::RevisionConflict => new ProblemSpec(code: $this->value, httpStatus: 409, retryable: false, receiptRecorded: true, title: 'Revision Conflict'),
            self::IdempotencyConflict => new ProblemSpec(code: $this->value, httpStatus: 409, retryable: false, receiptRecorded: false, title: 'Idempotency Conflict'),
            self::ProviderUnavailable => new ProblemSpec(code: $this->value, httpStatus: 503, retryable: true, receiptRecorded: false, title: 'Provider Unavailable'),
            self::ExternalOutcomeUnknown => new ProblemSpec(code: $this->value, httpStatus: 202, retryable: true, receiptRecorded: true, title: 'External Outcome Unknown'),
            self::EventIdentityConflict => new ProblemSpec(code: $this->value, httpStatus: 409, retryable: false, receiptRecorded: false, title: 'Event Identity Conflict'),
            self::ConfigurationInvalid => new ProblemSpec(code: $this->value, httpStatus: 500, retryable: false, receiptRecorded: false, title: 'Configuration Invalid'),
            self::PayloadInvalid => new ProblemSpec(code: $this->value, httpStatus: 422, retryable: false, receiptRecorded: false, title: 'Payload Invalid'),
            self::WireVersionUnsupported => new ProblemSpec(code: $this->value, httpStatus: 422, retryable: false, receiptRecorded: false, title: 'Wire Version Unsupported'),
            self::ModuleInvalid => new ProblemSpec(code: $this->value, httpStatus: 500, retryable: false, receiptRecorded: false, title: 'Module Invalid'),
            self::PanelNotFound => new ProblemSpec(code: $this->value, httpStatus: 404, retryable: false, receiptRecorded: false, title: 'Panel Not Found'),
            self::PanelBindingMismatch => new ProblemSpec(code: $this->value, httpStatus: 409, retryable: false, receiptRecorded: true, title: 'Panel Binding Mismatch'),
        };
    }
}
