<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use SocKit\Audience\Data\AudienceSpec;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Posts\Data\PostResult;
use SocKit\Posts\Data\PostTransition;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final readonly class PostLifecycle
{
    public function __construct(public PostScenario $scenario) {}

    public function transition(ResourceRef $resource, string $action, ?AudienceSpec $audience = null): PostTransition
    {
        return new PostTransition(surface: $this->scenario->handle->surface, resource: $resource, action: $action, reason: 'host.reason.'.$action, audience: $audience);
    }

    public function command(PostTransition $transition, int $expected, ?string $key = null): CommandEnvelope
    {
        $s = $this->scenario;
        $revision = new Revision($expected);

        return new CommandEnvelope(key: new CommandKey($key ?? 'post.lifecycle.'.$transition->action.'.'.$expected),
            namespace: new CommandNamespace(installation: $s->handle->actor->installation, panel: $s->handle->surface->panel, integration: 'host.client', commandType: 'posts.'.$transition->action),
            digest: $s->posts->transitionDigest(handle: $s->handle, transition: $transition, expectedRevision: $revision), expectedRevision: $revision);
    }

    public function execute(ResourceRef $resource, string $action, int $expected, ?AudienceSpec $audience = null): PostResult
    {
        $transition = $this->transition($resource, $action, $audience);

        return $this->scenario->posts->transition(handle: $this->scenario->handle, transition: $transition, command: $this->command($transition, $expected));
    }

    /** @return array<string, mixed> */
    public function row(ResourceRef $resource): array
    {
        $raw = $this->scenario->connection()->table('panel_a.a_posts')->where('id', $resource->id)->first() ?? throw new \RuntimeException('Missing owner row');

        return Scenario::object(get_object_vars($raw));
    }

    /** @return array<string, mixed> */
    public function event(int $revision): array
    {
        $wire = $this->scenario->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'posts.%'")
            ->whereRaw("envelope::jsonb->>'source_revision' = ?", [(string) $revision])->value('envelope');

        return Scenario::object(json_decode(Scenario::text($wire), true, flags: JSON_THROW_ON_ERROR));
    }

    /** Terminal state from the future purge owner; only private isolated fixture SQL, no purge claim. */
    public function erased(ResourceRef $resource): void
    {
        $s = $this->scenario;
        $s->connection()->table('panel_a.a_posts')->where('id', $resource->id)->update(['content_state' => 'erased', 'body' => null, 'media_refs' => '[]', 'erased_at' => new \DateTimeImmutable]);
        $s->connection()->table('panel_a.a_post_revisions')->where('post_id', $resource->id)->update(['body' => null, 'media_refs' => '[]']);
    }

    public static function denied(?OperationResult $result): void
    {
        expect($result)->not->toBeNull()->and($result?->isSuccess())->toBeFalse()->and($result?->resource)->toBeNull()->and($result?->revision)->toBeNull()->and($result?->problem)->not->toBeNull();
    }
}
