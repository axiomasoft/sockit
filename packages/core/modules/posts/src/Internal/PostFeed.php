<?php

declare(strict_types=1);

namespace SocKit\Posts\Internal;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\Operations;
use SocKit\Posts\Data\FeedPage;
use SocKit\Posts\Data\FeedQuery;
use SocKit\Posts\Data\PostView;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Surfaces\Data\SurfaceInstance;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\SurfaceLifecycle;

final readonly class PostFeed
{
    public function __construct(private Application $app) {}

    public function read(OperationHandle $handle, FeedQuery $query): FeedPage
    {
        $store = new PostStore($this->app);
        $store->outside($handle);
        $settings = $handle->panel->moduleSettings['sockit.posts'] ?? [];
        $keys = $settings['feed_keys'] ?? null;
        $active = $settings['feed_key'] ?? null;
        $ttl = $settings['feed_ttl'] ?? null;
        $chunk = $settings['feed_chunk'] ?? null;
        $budget = $settings['feed_budget'] ?? null;
        if (! is_array($keys) || ! is_string($active) || ! is_int($ttl) || ! is_int($chunk) || ! is_int($budget)) {
            throw new PostException(ProblemCode::ConfigurationInvalid);
        }
        $keyring = [];
        foreach ($keys as $id => $key) {
            if (! is_string($id) || ! is_string($key)) {
                throw new PostException(ProblemCode::ConfigurationInvalid);
            }
            $keyring[$id] = $key;
        }
        $codec = new FeedCursor($keyring, $active, $ttl);
        $context = $this->context($handle, $query);
        $position = $query->cursor === null ? null : $codec->decode($query->cursor, $context);
        $surface = $this->app->make(Operations::class)->surface($handle);
        $items = [];
        $scanned = 0;
        $exhausted = false;
        do {
            while (count($items) < $query->limit && $scanned < $budget) {
                // Never fetch beyond remaining output capacity: an unprocessed tail cannot become the cursor.
                $size = min($chunk, $budget - $scanned, $query->limit - count($items));
                $candidates = $store->table($handle, 'posts')->where($store->identity($handle))
                    ->where('surface_digest', hash('sha256', CanonicalJson::encode($handle->surface->toWire())))
                    ->where('publication_state', 'published')->where('visibility', 'visible')->where('content_state', 'retained')->whereNotNull('published_at');
                if (isset($query->filters['author'])) {
                    $candidates->where('author_ref', $query->filters['author']);
                }
                if ($position !== null) {
                    $candidates->whereRaw('(published_at, id) < (?, ?)', [$position['time'], $position['id']]);
                }
                $rows = $candidates->orderByDesc('published_at')->orderByDesc('id')->limit($size)
                    ->get(['id', 'published_at', 'audience', 'revision', 'privacy_revision', 'safety_generation', 'consent_generation']);
                if ($rows->isEmpty()) {
                    $exhausted = true;
                    break;
                }
                $subjects = [];
                foreach ($rows as $raw) {
                    $row = $store->columns($raw);
                    $subjects[] = new AudienceSubject(key: $store->text($row, 'id'), spec: AudienceSpec::fromWire($store->object($store->text($row, 'audience'))),
                        author: null, surface: $handle->surface, installation: $handle->actor->installation,
                        surfaceArchived: $surface->lifecycle === SurfaceLifecycle::Archived, archiveReaders: $handle->definition->archiveReaders);
                }
                $verdicts = $this->app->make(AudienceGate::class)->readMany(viewer: $handle->actor, subjects: $subjects);
                foreach ($rows as $raw) {
                    $candidate = $store->columns($raw);
                    $id = $store->text($candidate, 'id');
                    $position = ['time' => new \DateTimeImmutable($store->text($candidate, 'published_at'), new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), 'id' => $id];
                    $scanned++;
                    $verdict = $verdicts[$id] ?? null;
                    if ($verdict === null || ! $this->fresh($verdict)) {
                        continue;
                    }
                    $view = $this->hydrate($handle, $store, new ResourceRef(panel: $handle->surface->panel, kind: ResourceKind::Post, id: $id), $position, $query);
                    if ($view !== null) {
                        $items[] = $view;
                    }
                }
                if ($rows->count() < $size) {
                    $exhausted = true;
                    break;
                }
            }

            // No more shortlist reads before this output boundary. Later candidates may revoke earlier ones.
            $currentItems = [];
            foreach ($items as $item) {
                $time = $item->publishedAt?->format('Y-m-d H:i:s.u');
                if ($time === null) {
                    continue;
                }
                $current = $this->hydrate($handle, $store, $item->resource, ['time' => $time, 'id' => $item->resource->id], $query);
                if ($current !== null) {
                    $currentItems[] = $current;
                }
            }
            $items = $currentItems;
            // A final denial leaves a hole: continue below the last evaluated candidate while budget remains.
        } while (count($items) < $query->limit && ! $exhausted && $scanned < $budget);

        return new FeedPage(items: $items, nextCursor: $exhausted || $position === null ? null : $codec->encode($context, $position));
    }

    /** Each output samples current authority outside transactions. Remote revocation cannot be atomic with owner SQL.
     * A change during hydration restarts current authorization; repeated instability suppresses that candidate.
     *
     * @param array{time:string, id:string} $position */
    private function hydrate(OperationHandle $handle, PostStore $store, ResourceRef $resource, array $position, FeedQuery $query): ?PostView
    {
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $host = $this->app->make(OperationHandles::class)->resolveTarget(handle: $handle, target: $store->host($handle));
                $row = $store->readable($handle, $resource, $host);
                $surface = $this->app->make(Operations::class)->surface($handle);
                if (($row['author_ref'] ?? null) !== ($query->filters['author'] ?? $row['author_ref'])
                    || new \DateTimeImmutable($store->text($row, 'published_at'), new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u') !== $position['time']) {
                    return null;
                }
                $view = $store->view($handle, $resource, $row);
                // Media adapters may yield or change policy. Do not emit their captured body or refs until rechecked.
                $currentHost = $this->app->make(OperationHandles::class)->resolveTarget(handle: $handle, target: $store->host($handle));
                $current = $store->readable($handle, $resource, $currentHost);
                $currentSurface = $this->app->make(Operations::class)->surface($handle);
                if ($row === $current && $this->surfaceVersion($surface) === $this->surfaceVersion($currentSurface)
                    && $this->hostVersion($host) === $this->hostVersion($currentHost)) {
                    return $view;
                }
            }
        } catch (\Throwable) {
            // Missing, denied, expired and unstable candidates never materialize in the returned page.
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function context(OperationHandle $handle, FeedQuery $query): array
    {
        $actor = $handle->actor;

        return ['installation' => $actor->installation->toWire(), 'panel' => $handle->surface->panel->value, 'surface' => $handle->surface->toWire(),
            'viewer' => ['actor' => $actor->actor?->toWire(), 'kind' => $actor->kind->value, 'age' => $actor->ageBand->value,
                'provenance' => $actor->provenance->value, 'on_behalf_of' => $actor->onBehalfOf?->toWire(), 'generation' => $actor->generation],
            'filters' => $query->filters, 'order' => 'published_at,id:desc'];
    }

    /** @return list<int|string> */
    private function surfaceVersion(SurfaceInstance $surface): array
    {
        return [$surface->id, $surface->lifecycle->value, $surface->revision->value, $surface->sourceGeneration->value, $surface->privacyGeneration->value];
    }

    /** Sampled deadlines/verdicts are checked by resolveTarget, never used as stable generations.
     * @return list<int|string> */
    private function hostVersion(TargetResolution $host): array
    {
        return [$host->generation, $host->privacyRevision->value, $host->lifecycle->value];
    }

    private function fresh(AudienceVerdict $verdict): bool
    {
        return $verdict->canRead() && ($verdict->expiresAt === null || $verdict->expiresAt > new \DateTimeImmutable('now'));
    }
}
