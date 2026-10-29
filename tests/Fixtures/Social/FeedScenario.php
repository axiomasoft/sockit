<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use SocKit\Audience\Data\AudienceSpec;
use SocKit\Posts\Data\FeedPage;
use SocKit\Posts\Data\FeedQuery;
use SocKit\Support\Data\ResourceRef;

final readonly class FeedScenario
{
    public PostScenario $s;

    public function __construct(int $chunk = 2, int $budget = 20, bool $reactions = false, bool $contours = false)
    {
        $this->s = new PostScenario(reactions: $reactions, privacy: $contours ? ['floor' => ['allowed_scopes' => ['direct', 'contour'], 'default_scope' => 'direct', 'max_scope' => 'contour', 'minor_default_scope' => 'direct', 'minor_max_scope' => 'direct', 'contour_levels' => ['class']]] : [], feed: ['feed_keys' => ['current' => base64_encode(random_bytes(32))], 'feed_key' => 'current', 'feed_chunk' => $chunk, 'feed_budget' => $budget]);
    }

    public function add(string $body, ?AudienceSpec $audience = null, bool $publish = true): ResourceRef
    {
        $post = $this->s->post(body: $body, audience: $audience, publish: $publish);
        $result = $this->s->posts->create($this->s->handle, $post, $this->s->command($post, key: 'feed.create.'.bin2hex(random_bytes(8))));
        expect($result->operation->isSuccess())->toBeTrue();

        return $result->operation->resource ?? throw new \RuntimeException('Create failed');
    }

    /** @return list<ResourceRef> */
    public function tied(int $count): array
    {
        $refs = [];
        for ($i = 0; $i < $count; $i++) {
            $refs[] = $this->add('post-'.$i);
        }
        $this->s->connection()->table('panel_a.a_posts')->update(['published_at' => '2026-10-29 01:00:00.123456']);
        usort($refs, fn (ResourceRef $a, ResourceRef $b): int => strcmp($b->id, $a->id));

        return $refs;
    }

    public function page(int $limit = 2, ?string $cursor = null): FeedPage
    {
        return $this->s->posts->feed($this->s->handle, new FeedQuery($limit, $cursor));
    }

    /** @return list<string> */
    public static function ids(FeedPage $page): array
    {
        return array_map(fn ($view): string => $view->resource->id, $page->items);
    }
}
