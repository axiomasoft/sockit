<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Override;
use Packman\Attributes\Internal;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Modules\Posts\Configuration\PostsOptions;
use SocKit\Modules\Posts\Contracts\FeedService;
use SocKit\Modules\Posts\Data\FeedCursor;
use SocKit\Modules\Posts\Data\FeedPageView;
use SocKit\Modules\Posts\Data\GetFeedQuery;
use SocKit\Modules\Posts\Data\PostView;
use SocKit\Modules\Posts\Enums\ContentState;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Exceptions\PanelException;
use SocKit\Registrars\Internal\SockitRuntime;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Enums\SurfaceLifecycleState;

/**
 * Keyset-paginated surface feed reader filtering every batch through {@see AudienceGate::readMany()}.
 */
#[Internal]
final readonly class DatabaseFeedService implements FeedService
{
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
        private SurfaceDefinitions $surfaces,
        private SockitRuntime $runtime,
    ) {}

    #[Override]
    public function feed(GetFeedQuery $query): FeedPageView
    {
        $panelDef = $this->panels->get($query->surface->panel);
        $surfaceDef = $this->surfaces->get($query->surface->panel, $query->surface->kind);

        if (! $panelDef->hasModule('posts')) {
            throw PanelException::bindingMismatch($query->surface->panel->value, "Panel [{$query->surface->panel->value}] does not enable module [posts].");
        }

        if ($surfaceDef->features !== [] && ! $surfaceDef->supportsFeature('posts')) {
            throw PanelException::bindingMismatch($query->surface->panel->value, "Surface [{$surfaceDef->key()}] does not enable feature [posts].");
        }

        $options = PostsOptions::fromSettings($panelDef->settingsFor('posts'));
        $limit = min($query->limit, $options->maxPageLimit);

        $viewer = $this->runtime->actors()->current();
        $this->runtime->authorizer()->decide(new ActionRequest(
            actor: $viewer,
            action: 'sockit.posts.feed',
            surface: $query->surface,
        ))->requireAllowed();

        $connection = $this->tables->connection($query->surface->panel);
        $surfaceRow = $connection->table($this->tables->table('surface_instances', $query->surface->panel))
            ->where('panel_id', $query->surface->panel->value)
            ->where('kind', $query->surface->kind->value)
            ->where('host_entity_type', $query->surface->host->type)
            ->where('host_entity_value', $query->surface->host->value)
            ->where('incarnation', $query->surface->incarnation->value)
            ->first();

        if (! is_object($surfaceRow)) {
            return new FeedPageView([], null, false);
        }

        $surfaceArchived = SockitRows::str($surfaceRow, 'lifecycle_state') === SurfaceLifecycleState::Archived->value;
        $surfaceInstanceId = SockitRows::str($surfaceRow, 'id');
        $postsTable = $this->tables->table('posts', $query->surface->panel);
        $cursor = $query->cursor;
        $batchSize = max($limit * 2, 20);
        $accepted = [];
        $hasMoreRaw = false;
        $audienceGate = $this->runtime->audienceGate();

        while (count($accepted) < $limit) {
            $qb = $connection->table($postsTable)
                ->where('panel_id', $query->surface->panel->value)
                ->where('surface_instance_id', $surfaceInstanceId)
                ->where('state', ContentState::Published->value)
                ->whereNotNull('published_at');

            if ($cursor instanceof FeedCursor) {
                $cAt = $cursor->publishedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
                $cId = $cursor->id;
                $qb->where(static function (Builder $nested) use ($cAt, $cId): void {
                    $nested->where('published_at', '<', $cAt)
                        ->orWhere(static function (Builder $tie) use ($cAt, $cId): void {
                            $tie->where('published_at', '=', $cAt)
                                ->where('id', '<', $cId);
                        });
                });
            }

            $rows = $qb->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit($batchSize + 1)
                ->get()
                ->all();

            if ($rows === []) {
                $hasMoreRaw = false;

                break;
            }

            $hasMoreRaw = count($rows) > $batchSize;
            $slice = array_slice($rows, 0, $batchSize);

            $subjects = [];
            $audiences = [];
            $authors = [];
            foreach ($slice as $row) {
                $postId = SockitRows::str($row, 'id');
                $spec = AudienceSpec::fromWire(SockitRows::jsonArray($row, 'audience_wire'));
                $author = new ActorRef(SockitRows::str($row, 'author_type'), SockitRows::str($row, 'author_id'));

                $audiences[$postId] = $spec;
                $authors[$postId] = $author;
                $subjects[] = new AudienceSubject(
                    key: $postId,
                    spec: $spec,
                    author: $author,
                    surface: $query->surface,
                    installation: $viewer->installation,
                    surfaceArchived: $surfaceArchived,
                    archiveReaders: $surfaceDef->archiveReaders,
                );
            }

            $verdicts = $audienceGate->readMany($viewer, $subjects);
            $lastSeenRow = null;

            foreach ($slice as $index => $row) {
                $lastSeenRow = $row;
                $postId = SockitRows::str($row, 'id');
                $verdict = $verdicts[$postId] ?? null;

                if ($verdict !== null && $verdict->canRead()) {
                    $accepted[] = new PostView(
                        id: $postId,
                        panel: $query->surface->panel,
                        surface: $query->surface,
                        author: $authors[$postId],
                        state: ContentState::Published,
                        bodyFormat: SockitRows::str($row, 'body_format'),
                        body: SockitRows::optStr($row, 'current_body'),
                        revision: new Revision(SockitRows::int($row, 'revision', 1)),
                        privacyRevision: new Revision(SockitRows::int($row, 'privacy_revision', 1)),
                        audience: $audiences[$postId],
                        publishedAt: SockitRows::date($row, 'published_at'),
                        createdAt: SockitRows::date($row, 'created_at'),
                        updatedAt: SockitRows::date($row, 'updated_at'),
                    );

                    if (count($accepted) >= $limit) {
                        $remainingInSlice = count($slice) - 1 - $index;
                        $hasMoreRaw = $hasMoreRaw || $remainingInSlice > 0;

                        break;
                    }
                }
            }

            if ($lastSeenRow === null || count($accepted) >= $limit || ! $hasMoreRaw) {
                break;
            }

            $cursor = new FeedCursor(SockitRows::date($lastSeenRow, 'published_at'), SockitRows::str($lastSeenRow, 'id'));
        }

        $nextCursor = null;
        if ($hasMoreRaw && $accepted !== []) {
            $lastItem = $accepted[array_key_last($accepted)];
            if ($lastItem->publishedAt instanceof DateTimeImmutable) {
                $nextCursor = new FeedCursor($lastItem->publishedAt, $lastItem->id);
            }
        }

        return new FeedPageView(
            items: $accepted,
            nextCursor: $nextCursor,
            hasMore: $hasMoreRaw && $nextCursor instanceof FeedCursor,
        );
    }
}
