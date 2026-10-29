<?php

declare(strict_types=1);

namespace SocKit\Posts\Internal;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Posts\Contracts\ContentSafety;
use SocKit\Posts\Contracts\MediaResolver;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Data\SafetyVerdict;
use SocKit\Posts\Enums\PublicationState;
use SocKit\Posts\Enums\SafetyDecision;

final readonly class PostPolicies
{
    public function __construct(private Application $app) {}

    public function assess(OperationHandle $handle, PostCommand $post): PublicationState
    {
        if ($this->app->make(ConnectionResolverInterface::class)->connection($handle->panel->storage->connection)->transactionLevel() !== 0) {
            throw new PostException(ProblemCode::ConfigurationInvalid);
        }
        // No binding explicitly means no-prescreen. A configured failure never falls back.
        $safety = $this->app->bound(ContentSafety::class)
            ? $this->app->make(ContentSafety::class)->assess(handle: $handle, post: $post)
            : new SafetyVerdict(decision: SafetyDecision::Allow, reason: 'posts.no_prescreen');
        if ($safety->decision === SafetyDecision::Unknown) {
            throw new PostException(ProblemCode::ProviderUnavailable);
        }
        foreach ($post->media as $ref) {
            if (! $this->app->bound(MediaResolver::class)) {
                throw new PostException(ProblemCode::ProviderUnavailable);
            }
            $this->app->make(MediaResolver::class)->associate(handle: $handle, media: $ref)->requireAllowed();
        }

        return match ($safety->decision) {
            SafetyDecision::Allow => $post->publish ? PublicationState::Published : PublicationState::Pending,
            SafetyDecision::Hold => PublicationState::Held, SafetyDecision::Reject => PublicationState::Rejected,
        };
    }
}
