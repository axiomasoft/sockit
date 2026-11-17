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
use SocKit\Audience\Internal\LocalGroupReadGuard;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Modules\Posts\Configuration\PostsOptions;
use SocKit\Modules\Posts\Contracts\FeedService;
use SocKit\Modules\Posts\Data\FeedCursor;
use SocKit\Modules\Posts\Data\FeedPageView;
use SocKit\Modules\Posts\Data\GetFeedQuery;
use SocKit\Modules\Posts\Data\PostView;
use SocKit\Modules\Posts\Enums\ContentState;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Exceptions\PanelException;
use SocKit\Registrars\Internal\SockitRuntime;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Enums\SurfaceLifecycleState;

/**
 * Keyset-paginated surface feed reader enforcing signed context-bound cursors, surface admission,
 * batch audience filtering, and post-evaluation lifecycle/privacy re-verification before materializing bodies.
 */
#[Internal]
final readonly class DatabaseFeedService implements FeedService
{
    private const int MAX_READ_ATTEMPTS = 3;

    private const int MAX_SCAN_ROUNDS = 10;

    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
        private SurfaceDefinitions $surfaces,
        private SockitRuntime $runtime,
    ) {}

    #[Override]
    public function feed(GetFeedQuery $query): FeedPageView
    {
        for ($attempt = 0; $attempt < self::MAX_READ_ATTEMPTS; $attempt++) {
            $page = $this->readAttempt($query);
            if ($page instanceof FeedPageView) {
                return $page;
            }
        }

        throw OperationException::contentHidden('feed.concurrent_mutation');
    }

    private function readAttempt(GetFeedQuery $query): ?FeedPageView
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

        $audienceGate = $this->runtime->audienceGate();
        $admission = $audienceGate->admitForRead($viewer, $query->surface);
        if (! $admission->canRead()) {
            throw OperationException::audienceDenied($admission->reason);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $signingKey = $this->runtime->cursorSigningKey();
        $cursorTtlSeconds = $this->runtime->cursorTtlSeconds();

        if ($query->cursor instanceof FeedCursor) {
            $query->cursor->verifyForContext($query->surface, $viewer, $signingKey, $now);
        }

        $connection = $this->tables->connection($query->surface->panel);
        $surfacesTable = $this->tables->table('surface_instances', $query->surface->panel);
        $surfaceRow = $connection->table($surfacesTable)
            ->where('panel_id', $query->surface->panel->value)
            ->where('kind', $query->surface->kind->value)
            ->where('host_entity_type', $query->surface->host->type)
            ->where('host_entity_value', $query->surface->host->value)
            ->where('incarnation', $query->surface->incarnation->value)
            ->first();

        if (! is_object($surfaceRow) || SockitRows::str($surfaceRow, 'lifecycle_state') === SurfaceLifecycleState::Deleted->value) {
            return new FeedPageView([], null, false);
        }

        $surfaceArchived = SockitRows::str($surfaceRow, 'lifecycle_state') === SurfaceLifecycleState::Archived->value;
        if ($surfaceArchived && ! $surfaceDef->archiveReaders) {
            return new FeedPageView([], null, false);
        }

        $surfaceInstanceId = SockitRows::str($surfaceRow, 'id');
        $postsTable = $this->tables->table('posts', $query->surface->panel);
        $cursor = $query->cursor;
        $batchSize = max($limit * 2, 20);
        $accepted = [];
        $acceptedRows = [];
        $acceptedSubjects = [];
        $acceptedGroupStates = [];
        $groupGuard = new LocalGroupReadGuard($this->runtime->groupMembershipResolver());
        $hasMore = false;
        $lastScannedRow = null;

        for ($round = 0; $round < self::MAX_SCAN_ROUNDS && count($accepted) < $limit; $round++) {
            $qb = $connection->table($postsTable)
                ->select([
                    'id',
                    'author_type',
                    'author_id',
                    'published_at',
                    'privacy_revision',
                    'audience_wire',
                ])
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
                $hasMore = false;

                break;
            }

            $batchHasMoreAfter = count($rows) > $batchSize;
            $slice = array_slice($rows, 0, $batchSize);

            $subjects = [];
            $checkedSpecs = [];
            $checkedPrivacyRevisions = [];
            foreach ($slice as $row) {
                $postId = SockitRows::str($row, 'id');
                $spec = AudienceSpec::fromWire(SockitRows::jsonArray($row, 'audience_wire'));
                $author = new ActorRef(SockitRows::str($row, 'author_type'), SockitRows::str($row, 'author_id'));

                $checkedSpecs[$postId] = CanonicalJson::encode($spec->toWire());
                $checkedPrivacyRevisions[$postId] = SockitRows::int($row, 'privacy_revision', 1);
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

            // Start before authorization so no local change between the verdict and body fetch is missed.
            $checkedGroupStates = $groupGuard->snapshot($viewer, $subjects);
            $verdicts = $audienceGate->readMany($viewer, $subjects);

            $permittedIds = [];
            foreach ($slice as $row) {
                $postId = SockitRows::str($row, 'id');
                $verdict = $verdicts[$postId] ?? null;
                if ($verdict !== null && $verdict->canRead()) {
                    $permittedIds[] = $postId;
                }
            }

            /** @var array<string, object> $currentPosts */
            $currentPosts = [];
            $latestSurfaceArchived = $surfaceArchived;
            $surfaceAlive = true;

            if ($permittedIds !== []) {
                $latestSurface = $connection->table($surfacesTable)
                    ->where('id', $surfaceInstanceId)
                    ->first();

                if (! is_object($latestSurface) || SockitRows::str($latestSurface, 'lifecycle_state') === SurfaceLifecycleState::Deleted->value) {
                    $surfaceAlive = false;
                } else {
                    $latestSurfaceArchived = SockitRows::str($latestSurface, 'lifecycle_state') === SurfaceLifecycleState::Archived->value;
                    if ($latestSurfaceArchived && ! $surfaceDef->archiveReaders) {
                        $surfaceAlive = false;
                    }
                }

                if ($surfaceAlive) {
                    $freshRows = $connection->table($postsTable)
                        ->where('panel_id', $query->surface->panel->value)
                        ->where('surface_instance_id', $surfaceInstanceId)
                        ->whereIn('id', $permittedIds)
                        ->get();

                    foreach ($freshRows as $fresh) {
                        $currentPosts[SockitRows::str($fresh, 'id')] = $fresh;
                    }
                }
            }

            $lastSliceIndex = -1;
            foreach ($slice as $index => $row) {
                $lastScannedRow = $row;
                $lastSliceIndex = $index;
                $postId = SockitRows::str($row, 'id');
                $verdict = $verdicts[$postId] ?? null;

                if ($verdict === null || ! $verdict->canRead() || ! $surfaceAlive) {
                    continue;
                }

                $fresh = $currentPosts[$postId] ?? null;
                if (! is_object($fresh) || SockitRows::str($fresh, 'state') !== ContentState::Published->value) {
                    continue;
                }

                $freshPrivacyRevision = SockitRows::int($fresh, 'privacy_revision', 1);
                $freshSpec = AudienceSpec::fromWire(SockitRows::jsonArray($fresh, 'audience_wire'));
                $freshAuthor = new ActorRef(SockitRows::str($fresh, 'author_type'), SockitRows::str($fresh, 'author_id'));
                $freshCanonicalSpec = CanonicalJson::encode($freshSpec->toWire());

                if (
                    $freshPrivacyRevision !== $checkedPrivacyRevisions[$postId]
                    || $freshCanonicalSpec !== $checkedSpecs[$postId]
                    || $subjects[$index]->author?->equals($freshAuthor) !== true
                    || $latestSurfaceArchived !== $surfaceArchived
                ) {
                    $subjects[$index] = new AudienceSubject(
                        key: $postId,
                        spec: $freshSpec,
                        author: $freshAuthor,
                        surface: $query->surface,
                        installation: $viewer->installation,
                        surfaceArchived: $latestSurfaceArchived,
                        archiveReaders: $surfaceDef->archiveReaders,
                    );
                    $checkedGroupStates[$postId] = $groupGuard->snapshot($viewer, [$subjects[$index]])[$postId];
                    $recheckVerdict = $audienceGate->read($viewer, $subjects[$index]);

                    if (! $recheckVerdict->canRead()) {
                        continue;
                    }
                }

                $acceptedRows[$postId] = get_object_vars($fresh);
                $acceptedSubjects[] = $subjects[$index];
                $acceptedGroupStates[$postId] = $checkedGroupStates[$postId];
                $accepted[] = new PostView(
                    id: $postId,
                    panel: $query->surface->panel,
                    surface: $query->surface,
                    author: $freshAuthor,
                    state: ContentState::Published,
                    bodyFormat: SockitRows::str($fresh, 'body_format'),
                    body: SockitRows::optStr($fresh, 'current_body'),
                    revision: new Revision(SockitRows::int($fresh, 'revision', 1)),
                    privacyRevision: new Revision($freshPrivacyRevision),
                    audience: $freshSpec,
                    publishedAt: SockitRows::date($fresh, 'published_at'),
                    createdAt: SockitRows::date($fresh, 'created_at'),
                    updatedAt: SockitRows::date($fresh, 'updated_at'),
                );

                if (count($accepted) >= $limit) {
                    break;
                }
            }

            $remainingInSlice = count($slice) - 1 - $lastSliceIndex;
            $hasMore = $batchHasMoreAfter || $remainingInSlice > 0;

            if ($lastScannedRow === null || count($accepted) >= $limit || ! $hasMore) {
                break;
            }

            $cursor = FeedCursor::issue(
                publishedAt: SockitRows::date($lastScannedRow, 'published_at'),
                id: SockitRows::str($lastScannedRow, 'id'),
                surface: $query->surface,
                viewer: $viewer,
                signingKey: $signingKey,
                expiresAt: $now->modify('+'.$cursorTtlSeconds.' seconds'),
            );
        }

        $nextCursor = null;
        if ($hasMore && $lastScannedRow !== null) {
            $nextCursor = FeedCursor::issue(
                publishedAt: SockitRows::date($lastScannedRow, 'published_at'),
                id: SockitRows::str($lastScannedRow, 'id'),
                surface: $query->surface,
                viewer: $viewer,
                signingKey: $signingKey,
                expiresAt: $now->modify('+'.$cursorTtlSeconds.' seconds'),
            );
        }

        // The final confirmation is local SQL only: no external SPI/admission may follow it.
        // Later rechecks/scan rounds can invalidate ANY earlier post, surface or group snapshot.
        // Keep the exact rows used for authorization/materialization, including audience_wire,
        // privacy_revision, state, author, revision and current_body; never hydrate from unchecked rows.
        $confirmedGroupStates = $groupGuard->snapshot($viewer, $acceptedSubjects);
        $confirmSurface = $connection->table($surfacesTable)
            ->where('panel_id', $query->surface->panel->value)
            ->where('id', $surfaceInstanceId)
            ->first();

        // The original surface row was admitted with this attempt's immutable archiveReaders policy.
        // Equality also protects its identity, incarnation, deletion and archive status.
        if (! is_object($confirmSurface) || get_object_vars($confirmSurface) !== get_object_vars($surfaceRow)) {
            return null;
        }

        $confirmedRows = $connection->table($postsTable)
            ->where('panel_id', $query->surface->panel->value)
            ->where('surface_instance_id', $surfaceInstanceId)
            ->whereIn('id', array_keys($acceptedRows))
            ->get();

        if (count($confirmedRows) !== count($acceptedRows)) {
            return null;
        }

        foreach ($confirmedRows as $confirmedRow) {
            $postId = SockitRows::str($confirmedRow, 'id');
            if (
                get_object_vars($confirmedRow) !== $acceptedRows[$postId]
                || $acceptedGroupStates[$postId] === null
                || $acceptedGroupStates[$postId] !== $confirmedGroupStates[$postId]
            ) {
                return null;
            }
        }

        return new FeedPageView(
            items: $accepted,
            nextCursor: $nextCursor,
            hasMore: $hasMore && $nextCursor instanceof FeedCursor,
        );
    }
}
