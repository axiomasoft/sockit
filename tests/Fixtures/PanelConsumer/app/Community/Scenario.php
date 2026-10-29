<?php

declare(strict_types=1);

namespace Acme\Community;

use Acme\Social\Runtime;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use RuntimeException;
use SocKit\Actors\Exceptions\ActorContextMismatchException;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\Operations;
use SocKit\Panels\Data\PanelId;
use SocKit\Posts\Contracts\ContentSafety;
use SocKit\Posts\Contracts\MediaResolver;
use SocKit\Posts\Data\FeedQuery;
use SocKit\Posts\Data\MediaReference;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Data\PostTransition;
use SocKit\Posts\Enums\SafetyDecision;
use SocKit\Posts\Posts;
use SocKit\Reactions\Data\ReactionCommand;
use SocKit\Reactions\Reactions;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\SurfaceInstances;

final class Scenario
{
    public static function run(Application $app): void
    {
        $host = new Host;
        $app->instance(MediaResolver::class, new Media);
        $app->instance(Host::class, $host);
        $handles = $app->make(OperationHandles::class);
        $posts = $app->make(Posts::class);
        $reactions = $app->make(Reactions::class);
        $surfaces = $app->make(SurfaceInstances::class);
        $ref = static fn (string $panel): SurfaceRef => new SurfaceRef(new PanelId('acme.'.$panel), new SurfaceKindId('acme.class-community'), new HostEntityRef('class', '42'), new Incarnation(1));
        $handle = static fn (string $panel = 'community'): OperationHandle => $handles->forSurface($ref($panel));
        $namespace = static fn (OperationHandle $h, string $type): CommandNamespace => new CommandNamespace($h->actor->installation, $h->surface->panel, 'host.client', $type);
        foreach (['community', 'neighbor'] as $panel) {
            $h = $handle($panel);
            $cmd = new CommandEnvelope(new CommandKey('community.surface.create'), $namespace($h, 'sockit.surfaces.create'), $surfaces->digest($h, 'create'));
            Runtime::success($surfaces->create($h, $cmd));
        }
        $h = $handle();
        $audience = AudienceSpec::contour('class', new HostEntityRef('class', '42'));
        $post = new PostCommand($h->surface, 'Class announcement', $audience, publish: true, media: [new MediaReference('host.media', 'class-photo')]);
        $create = new CommandEnvelope(new CommandKey('community.post.create'), $namespace($h, 'posts.create'), $posts->digest($h, $post));
        $created = $posts->create($h, $post, $create);
        Runtime::success($created->operation);
        $resource = $created->operation->resource ?? throw new RuntimeException('Missing post');
        Runtime::check($posts->create($h, $post, $create)->operation->resource?->id === $resource->id, 'Lost response duplicated post');
        $target = new TargetRef(new HostEntityRef('sockit.post', $resource->id), new Incarnation(1));
        $neighborTeacher = $handle('neighbor');
        $neighborPost = new PostCommand($neighborTeacher->surface, 'Neighbor announcement', $audience, publish: true);
        $neighborCmd = new CommandEnvelope(new CommandKey('community.post.create'), $namespace($neighborTeacher, 'posts.create'), $posts->digest($neighborTeacher, $neighborPost));
        $neighborResult = $posts->create($neighborTeacher, $neighborPost, $neighborCmd);
        Runtime::success($neighborResult->operation);
        $neighborRef = $neighborResult->operation->resource ?? throw new RuntimeException('Missing neighbor post');
        Runtime::check($neighborRef->panel->value === 'acme.neighbor' && $resource->panel->value === 'acme.community', 'Panel identity lost');
        $host->id = 'parent';
        $parent = $handle();
        Runtime::check(count($posts->feed($parent, new FeedQuery)->items) === 1, 'Parent cannot read feed');
        $reaction = new ReactionCommand($parent->surface, $target, 'like');
        $parentCommand = new CommandEnvelope(new CommandKey('community.parent.reaction'), $namespace($parent, 'reactions.add'), $reactions->digest($parent, $reaction, 'add'));
        Runtime::check(! $reactions->add($parent, $reaction, $parentCommand)->isSuccess(), 'Parent can react');
        $parentPost = new PostCommand($parent->surface, 'Forbidden parent post', $audience, publish: true);
        $parentPostCmd = new CommandEnvelope(new CommandKey('community.parent.post'), $namespace($parent, 'posts.create'), $posts->digest($parent, $parentPost));
        Runtime::check(! $posts->create($parent, $parentPost, $parentPostCmd)->operation->isSuccess(), 'Parent can publish');
        $host->id = 'student';
        $student = $handle();
        $reaction = new ReactionCommand($student->surface, $target, 'like');
        $command = new CommandEnvelope(new CommandKey('community.student.reaction'), $namespace($student, 'reactions.add'), $reactions->digest($student, $reaction, 'add'));
        Runtime::success($reactions->add($student, $reaction, $command));
        Runtime::check($reactions->summary($student, $target)->counts === ['like' => 1], 'Reaction missing');
        $neighbor = $handle('neighbor');
        Runtime::check(count($posts->feed($neighbor, new FeedQuery)->items) === 1 && $posts->feed($neighbor, new FeedQuery)->items[0]->resource->id === $neighborRef->id, 'Neighbor feed mixed panels');
        Runtime::check($reactions->summary($neighbor, $target)->counts === null, 'Neighbor leaked count');
        $host->id = 'school';
        $service = $handle();
        $official = new PostCommand($service->surface, 'Official school post', $audience, publish: true);
        $officialCommand = new CommandEnvelope(new CommandKey('community.service.create'), $namespace($service, 'posts.create'), $posts->digest($service, $official));
        Runtime::success($posts->create($service, $official, $officialCommand)->operation);
        Runtime::check($service->actor->onBehalfOf?->id === '7', 'Service context lost');
        $host->revoked['teacher'] = true;
        $host->id = 'teacher';
        $revoked = $handle();
        try {
            $posts->read($revoked, $resource);
            throw new RuntimeException('Revoked author read');
        } catch (SocKitException) {
        }
        Runtime::check(! $posts->create($revoked, $post, $create)->operation->isSuccess(), 'Revoked author replay');
        $host->revoked = [];
        $host->installation = 'other-installation';
        try {
            $handle();
            throw new RuntimeException('Other installation accepted');
        } catch (ActorContextMismatchException) {
        }
        $host->installation = 'runtime-test';
        $host->id = 'teacher';
        $h = $handle();
        $transition = static function (string $action, int $version, ?AudienceSpec $spec = null) use ($h, $resource, $posts, $namespace): array {
            $t = new PostTransition($h->surface, $resource, $action, 'host.'.$action, $spec);
            $revision = new Revision($version);
            $c = new CommandEnvelope(new CommandKey('community.transition.'.$action.'.'.$version), $namespace($h, 'posts.'.$action), $posts->transitionDigest($h, $t, $revision), $revision);

            return [$t, $c];
        };
        $direct = AudienceSpec::direct(new ActorRef('user', 'teacher'), new ActorRef('user', 'student'));
        [$change, $changeCmd] = $transition('audience', 1, $direct);
        Runtime::success($posts->transition($h, $change, $changeCmd)->operation);
        $host->id = 'student';
        $student = $handle();
        Runtime::check($posts->read($student, $resource)->media[0]->id === 'class-photo', 'Current media missing');
        $host->id = 'teacher';
        $h = $handle();
        [$shrink, $shrinkCmd] = $transition('audience', 2, AudienceSpec::direct(new ActorRef('user', 'teacher')));
        Runtime::success($posts->transition($h, $shrink, $shrinkCmd)->operation);
        $host->id = 'student';
        $student = $handle();
        Runtime::check($posts->feed($student, new FeedQuery)->items !== [] && count($posts->feed($student, new FeedQuery)->items) === 1, 'Shrunk post leaked in feed'); // service post remains
        foreach ([fn () => $posts->read($student, $resource), fn () => $posts->revision($student, $resource, new Revision(1))] as $read) {
            try {
                $read();
                throw new RuntimeException('Current privacy leaked content or historical media');
            } catch (SocKitException) {
            }
        }
        Runtime::check($reactions->summary($student, $target)->counts === null, 'Shrunk audience leaked count');
        Runtime::check($reactions->lookup($student, $command->namespace, $command->key)?->isSuccess() === false, 'Shrunk audience leaked receipt');
        Runtime::check($app->make(Operations::class)->lookup($student, $command->namespace, $command->key)?->isSuccess() === false, 'Shrunk audience leaked generic receipt');
        Runtime::check(! $reactions->add($student, $reaction, $command)->isSuccess(), 'Shrunk audience replay succeeded');
        $host->id = 'teacher';
        $h = $handle();
        $conn = $app->make(DatabaseManager::class)->connection('owner_a');
        $counts = static fn (): array => array_map(fn (string $table): int => $conn->table('public.community_'.$table)->count(), ['posts', 'post_revisions', 'reactions', 'reaction_counts', 'receipts', 'outbox']);
        $beforeRebuild = $counts();
        $reactions->rebuild($h, $target);
        Runtime::check($counts() === $beforeRebuild, 'Rebuild emitted historical effects');
        $consent = new Consent($conn);
        $app->instance(ActionAuthorizer::class, $consent);
        $app->instance(MutationAuthorityFence::class, $consent);
        [$hide, $hideCmd] = $transition('hide', 3);
        $conn->table('public.envelope_authority')->update(['allowed' => false, 'generation' => 2]);
        $before = $counts();
        $failed = false;
        $conn->listen(static function (QueryExecuted $query) use (&$failed): void {
            if (! $failed && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'community_outbox')) {
                $failed = true;
                throw new RuntimeException('Injected hide outbox failure');
            }
        });
        Runtime::check(! $posts->transition($h, $hide, $hideCmd)->operation->isSuccess() && $failed && $counts() === $before && $conn->table('public.community_posts')->where('id', $resource->id)->value('visibility') === 'visible', 'Hide rollback failed');
        try {
            $posts->read($h, $resource);
            throw new RuntimeException('Consent revoked but read succeeded');
        } catch (SocKitException) {
        }
        Runtime::check($posts->lookup($h, $create->namespace, $create->key)?->operation->isSuccess() === false, 'Revoked receipt disclosed');
        Runtime::check($app->make(Operations::class)->lookup($h, $create->namespace, $create->key)?->isSuccess() === false, 'Generic revoked receipt disclosed');
        Runtime::success($posts->transition($h, $hide, $hideCmd)->operation);
        Runtime::check($reactions->summary($h, $target)->counts === null, 'Hidden count disclosed');
        $conn->table('public.envelope_authority')->update(['allowed' => true, 'generation' => 3]);
        [$restore, $restoreCmd] = $transition('restore', 4);
        Runtime::check($posts->transition($h, $restore, $restoreCmd)->isPublished(), 'Restore did not preserve published state');
        Runtime::check($posts->read($h, $resource)->body === 'Class announcement', 'Restored content missing');
        $archiveReaction = new ReactionCommand($h->surface, $target, 'like');
        $archiveCommand = new CommandEnvelope(new CommandKey('community.archive.reaction'), $namespace($h, 'reactions.add'), $reactions->digest($h, $archiveReaction, 'add'));
        $archivePost = new PostCommand($h->surface, 'Archive write', AudienceSpec::direct(new ActorRef('user', 'teacher')), publish: true);
        $archivePostCmd = new CommandEnvelope(new CommandKey('community.archive.post'), $namespace($h, 'posts.create'), $posts->digest($h, $archivePost));
        $conn->table('public.community_surface_instances')->update(['lifecycle' => 'archived']);
        Runtime::check($posts->read($h, $resource)->body === 'Class announcement', 'Archive lost eligible read');
        $host->id = 'graduate';
        $graduate = $handle();
        Runtime::check(count($posts->feed($graduate, new FeedQuery)->items) === 1, 'Eligible graduate archive feed missing');
        $host->id = 'teacher';
        $h = $handle();
        Runtime::check(! $reactions->add($h, $archiveReaction, $archiveCommand)->isSuccess(), 'Archive allowed reaction');
        Runtime::check(! $posts->create($h, $archivePost, $archivePostCmd)->operation->isSuccess(), 'Archive allowed publication');
        [$delete, $deleteCmd] = $transition('delete', 5);
        Runtime::check(! $posts->transition($h, $delete, $deleteCmd)->operation->isSuccess(), 'Archive allowed delete');
        $conn->table('public.community_surface_instances')->update(['lifecycle' => 'live']);
        $deleteCmd = new CommandEnvelope(new CommandKey('community.delete.after.archive'), $deleteCmd->namespace, $deleteCmd->digest, $deleteCmd->expectedRevision);
        Runtime::success($posts->transition($h, $delete, $deleteCmd)->operation);
        Runtime::check($posts->feed($h, new FeedQuery)->items !== [] && count($posts->feed($h, new FeedQuery)->items) === 1, 'Deleted post leaked');
        Runtime::check($reactions->summary($h, $target)->counts === null, 'Deleted count disclosed');
        Runtime::check(! $posts->create($h, $post, $create)->operation->isSuccess(), 'Deleted post replay succeeded');
        Runtime::check($conn->table('public.community_posts')->where('id', $resource->id)->value('visibility') === 'deleted', 'Delete terminal marker missing');
        Runtime::check(! $posts->transition($h, $restore, $restoreCmd)->operation->isSuccess(), 'Late restore revived deleted post');
        Runtime::check($conn->table('public.community_post_revisions')->where('post_id', $resource->id)->where('revision', 6)->value('body') === null, 'Delete marker retains body');
        $bad = new PostCommand($h->surface, 'Other class', AudienceSpec::contour('class', new HostEntityRef('class', '43')), publish: true);
        $badCmd = new CommandEnvelope(new CommandKey('community.cross.class'), $namespace($h, 'posts.create'), $posts->digest($h, $bad));
        Runtime::check(! $posts->create($h, $bad, $badCmd)->operation->isSuccess(), 'Cross class publication accepted');
        $safety = new Safety;
        $app->instance(ContentSafety::class, $safety);
        $draft = new PostCommand($h->surface, 'Draft for promotion', AudienceSpec::direct(new ActorRef('user', 'teacher')), publish: false);
        $draftCmd = new CommandEnvelope(new CommandKey('community.draft.create'), $namespace($h, 'posts.create'), $posts->digest($h, $draft));
        $draftResult = $posts->create($h, $draft, $draftCmd);
        Runtime::success($draftResult->operation);
        $draftRef = $draftResult->operation->resource ?? throw new RuntimeException('Missing draft');
        $draftTarget = new TargetRef(new HostEntityRef('sockit.post', $draftRef->id), new Incarnation(1));
        Runtime::check($reactions->summary($h, $draftTarget)->counts === null && count($posts->feed($h, new FeedQuery)->items) === 1, 'Pending exposed');
        $draftReaction = new ReactionCommand($h->surface, $draftTarget, 'like');
        $draftReactionCmd = new CommandEnvelope(new CommandKey('community.draft.reaction'), $namespace($h, 'reactions.add'), $reactions->digest($h, $draftReaction, 'add'));
        Runtime::check(! $reactions->add($h, $draftReaction, $draftReactionCmd)->isSuccess(), 'Pending accepted reaction');
        $draftHide = new PostTransition($h->surface, $draftRef, 'hide', 'host.hide');
        $draftHideCmd = new CommandEnvelope(new CommandKey('community.draft.hide'), $namespace($h, 'posts.hide'), $posts->transitionDigest($h, $draftHide, new Revision(1)), new Revision(1));
        Runtime::success($posts->transition($h, $draftHide, $draftHideCmd)->operation);
        $draftRestore = new PostTransition($h->surface, $draftRef, 'restore', 'host.restore');
        $draftRestoreCmd = new CommandEnvelope(new CommandKey('community.draft.restore'), $namespace($h, 'posts.restore'), $posts->transitionDigest($h, $draftRestore, new Revision(2)), new Revision(2));
        $restoredDraft = $posts->transition($h, $draftRestore, $draftRestoreCmd);
        Runtime::success($restoredDraft->operation);
        Runtime::check(! $restoredDraft->isPublished() && $reactions->summary($h, $draftTarget)->counts === null, 'Restored draft exposed');
        $promote = new PostTransition($h->surface, $draftRef, 'publish', 'host.promote');
        $promoteCmd = new CommandEnvelope(new CommandKey('community.draft.promote'), $namespace($h, 'posts.publish'), $posts->transitionDigest($h, $promote, new Revision(3)), new Revision(3));
        $promoted = $posts->transition($h, $promote, $promoteCmd);
        Runtime::check($promoted->isPublished(), 'Draft promotion failed');
        Runtime::check($posts->transition($h, $promote, $promoteCmd)->operation->resource?->id === $draftRef->id, 'Promotion replay changed resource');
        $duplicate = new CommandEnvelope(new CommandKey('community.draft.promote.again'), $promoteCmd->namespace, $promoteCmd->digest, $promoteCmd->expectedRevision);
        Runtime::check(! $posts->transition($h, $promote, $duplicate)->operation->isSuccess(), 'Stale promotion accepted');
        Runtime::check($conn->table('public.community_post_revisions')->where('post_id', $draftRef->id)->count() === 4, 'Duplicate promotion revision');
        $safety->decision = SafetyDecision::Hold;
        $held = new PostCommand($h->surface, 'Held post', AudienceSpec::direct(new ActorRef('user', 'teacher')), publish: true);
        $heldCmd = new CommandEnvelope(new CommandKey('community.held.create'), $namespace($h, 'posts.create'), $posts->digest($h, $held));
        $heldResult = $posts->create($h, $held, $heldCmd);
        Runtime::success($heldResult->operation);
        $heldRef = $heldResult->operation->resource ?? throw new RuntimeException('Missing held post');
        $heldTarget = new TargetRef(new HostEntityRef('sockit.post', $heldRef->id), new Incarnation(1));
        Runtime::check(! $heldResult->isPublished() && $reactions->summary($h, $heldTarget)->counts === null && count($posts->feed($h, new FeedQuery)->items) === 2, 'Held exposed');
        $heldReaction = new ReactionCommand($h->surface, $heldTarget, 'like');
        $heldReactionCmd = new CommandEnvelope(new CommandKey('community.held.reaction'), $namespace($h, 'reactions.add'), $reactions->digest($h, $heldReaction, 'add'));
        Runtime::check(! $reactions->add($h, $heldReaction, $heldReactionCmd)->isSuccess(), 'Held accepted reaction');
        $heldHide = new PostTransition($h->surface, $heldRef, 'hide', 'host.hide');
        $heldHideCmd = new CommandEnvelope(new CommandKey('community.held.hide'), $namespace($h, 'posts.hide'), $posts->transitionDigest($h, $heldHide, new Revision(1)), new Revision(1));
        Runtime::success($posts->transition($h, $heldHide, $heldHideCmd)->operation);
        $heldRestore = new PostTransition($h->surface, $heldRef, 'restore', 'host.restore');
        $heldRestoreCmd = new CommandEnvelope(new CommandKey('community.held.restore'), $namespace($h, 'posts.restore'), $posts->transitionDigest($h, $heldRestore, new Revision(2)), new Revision(2));
        $restoredHeld = $posts->transition($h, $heldRestore, $heldRestoreCmd);
        Runtime::success($restoredHeld->operation);
        Runtime::check(! $restoredHeld->isPublished() && $reactions->summary($h, $heldTarget)->counts === null, 'Restored held became public');
        $safety->decision = SafetyDecision::Allow;
        $erased = new PostCommand($h->surface, 'Erasure fixture', AudienceSpec::direct(new ActorRef('user', 'teacher')), publish: false);
        $erasedCmd = new CommandEnvelope(new CommandKey('community.erased.create'), $namespace($h, 'posts.create'), $posts->digest($h, $erased));
        $erasedRef = $posts->create($h, $erased, $erasedCmd)->operation->resource ?? throw new RuntimeException('Missing erasure fixture');
        $erasedPublish = new PostTransition($h->surface, $erasedRef, 'publish', 'host.promote');
        $erasedPublishCmd = new CommandEnvelope(new CommandKey('community.erased.publish'), $namespace($h, 'posts.publish'), $posts->transitionDigest($h, $erasedPublish, new Revision(1)), new Revision(1));
        // Simulates a future purge owner's terminal state; this is not an erasure implementation.
        $conn->table('public.community_posts')->where('id', $erasedRef->id)->update(['content_state' => 'erased', 'body' => null, 'media_refs' => '[]', 'erased_at' => new \DateTimeImmutable]);
        $conn->table('public.community_post_revisions')->where('post_id', $erasedRef->id)->update(['body' => null, 'media_refs' => '[]']);
        Runtime::check(! $posts->transition($h, $erasedPublish, $erasedPublishCmd)->operation->isSuccess(), 'Erased draft promoted');
        fwrite(STDERR, 'P4.9 SQL final counts='.json_encode($counts(), JSON_THROW_ON_ERROR).' tombstones='.$conn->table('public.community_tombstones')->count().PHP_EOL);

        echo json_encode(['complete' => true, 'checks' => ['generated_panels', 'teacher_publish', 'lost_response', 'observer_read_only', 'student_reaction', 'same_id_panel_isolation', 'service_on_behalf', 'author_revoke', 'privacy_shrink', 'consent_failed_hide_retry', 'archive', 'delete_late_replay', 'pending_held_restore', 'promotion_cas', 'projection_rebuild']], JSON_THROW_ON_ERROR);
    }
}
