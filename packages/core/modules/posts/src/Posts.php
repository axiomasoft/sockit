<?php

declare(strict_types=1);

namespace SocKit\Posts;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Attributes\Api;
use SocKit\Errors\Contracts\ProvidesProblem;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Operations;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Data\PostResult;
use SocKit\Posts\Data\PostView;
use SocKit\Posts\Enums\PublicationState;
use SocKit\Posts\Internal\PostMutation;
use SocKit\Posts\Internal\PostStore;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;

/** Bind ContentSafety/MediaResolver explicitly as scoped adapters. No safety binding means no-prescreen;
 * a configured unavailable/error/unknown adapter denies. Media refs always require their resolver.
 * Drafts use audience=null for the trusted actor-only default (including unknown age).
 * Reuse the original key/digest after a lost response; CAS conflicts are never retried automatically.
 */
#[Api(since: '1.0')]
final readonly class Posts
{
    public function __construct(private Application $app, private Operations $operations) {}

    public function digest(OperationHandle $handle, PostCommand $post, ?Revision $expectedRevision = null): PayloadDigest
    {
        return $this->operations->digest(handle: $handle, mutation: new PostMutation(app: $this->app, post: $post), expectedRevision: $expectedRevision);
    }

    public function create(OperationHandle $handle, PostCommand $post, CommandEnvelope $command): PostResult
    {
        return $this->execute(handle: $handle, post: $post, command: $command, create: true);
    }

    public function edit(OperationHandle $handle, PostCommand $post, CommandEnvelope $command): PostResult
    {
        return $this->execute(handle: $handle, post: $post, command: $command, create: false);
    }

    /** Current hard guards, stored audience, publication and safety protect every historical body too. */
    public function read(OperationHandle $handle, ResourceRef $resource): PostView
    {
        $store = new PostStore($this->app);

        return $store->view(handle: $handle, resource: $resource, row: $store->readable($handle, $resource));
    }

    public function revision(OperationHandle $handle, ResourceRef $resource, Revision $revision): PostView
    {
        $store = new PostStore($this->app);

        return $store->view(handle: $handle, resource: $resource, row: $store->readable($handle, $resource), revision: $revision);
    }

    /** Lookup protects resource/revision metadata through kernel target capabilities, without an owner shortcut. */
    public function lookup(OperationHandle $handle, CommandNamespace $namespace, CommandKey $key): ?PostResult
    {
        try {
            new PostStore($this->app)->outside($handle);
            if (! in_array($namespace->commandType, ['posts.create', 'posts.edit'], true)) {
                return new PostResult(OperationResult::rejected(ProblemCode::PayloadInvalid), null);
            }
            $result = $this->operations->lookup(handle: $handle, namespace: $namespace, key: $key);

            return $result === null ? null : $this->result($handle, $result);
        } catch (\Throwable $error) {
            return new PostResult(OperationResult::rejected($error instanceof ProvidesProblem ? $error->problem() : ProblemCode::ProviderUnavailable), null);
        }
    }

    private function execute(OperationHandle $handle, PostCommand $post, CommandEnvelope $command, bool $create): PostResult
    {
        try {
            if ($create !== ($post->resource === null) || $create !== ($command->expectedRevision === null)) {
                return new PostResult(OperationResult::rejected(ProblemCode::PayloadInvalid), null);
            }

            return $this->result($handle, $this->operations->execute(handle: $handle, command: $command, mutation: new PostMutation(app: $this->app, post: $post)));
        } catch (\Throwable $error) {
            return new PostResult(OperationResult::rejected($error instanceof ProvidesProblem ? $error->problem() : ProblemCode::ProviderUnavailable), null);
        }
    }

    private function result(OperationHandle $handle, OperationResult $result): PostResult
    {
        if (! $result->isSuccess() || $result->resource === null || $result->revision === null) {
            return new PostResult($result, null);
        }
        // Return the publication state of the acknowledged revision, never substitute the latest edit.
        $store = new PostStore($this->app);
        $row = $store->table($handle, 'post_revisions')->where('post_id', $result->resource->id)->where('revision', $result->revision->value)->first();
        if ($row === null) {
            return new PostResult(OperationResult::rejected(ProblemCode::ProviderUnavailable), null);
        }

        return new PostResult($result, PublicationState::from($store->text($store->columns($row), 'publication_state')));
    }
}
