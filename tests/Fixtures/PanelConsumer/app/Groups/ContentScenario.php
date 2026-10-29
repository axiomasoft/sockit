<?php

declare(strict_types=1);

namespace Acme\Groups;

use Acme\Social\Runtime;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\GroupRef;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Groups\Contracts\MembershipConsent;
use SocKit\Groups\Data\GroupCommand;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Groups\Groups;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationJobReference;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\OperationJobs;
use SocKit\Panels\Data\PanelId;
use SocKit\Posts\Data\FeedQuery;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Posts;
use SocKit\Reactions\Data\ReactionCommand;
use SocKit\Reactions\Reactions;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\SurfaceInstances;

final class ContentScenario
{
    /** @return array<string, mixed> */
    public static function run(Application $app): array
    {
        $host = new Host;
        $app->instance(Host::class, $host);
        $tenant = new Tenant;
        $app->instance(Tenant::class, $tenant);
        $consent = $app->make(SubjectConsent::class);
        $app->instance(MembershipConsent::class, $consent);
        $handles = $app->make(OperationHandles::class);
        $surfaces = $app->make(SurfaceInstances::class);
        $groups = $app->make(Groups::class);
        $posts = $app->make(Posts::class);
        $reactions = $app->make(Reactions::class);
        $namespace = static fn (OperationHandle $h, string $type): CommandNamespace => new CommandNamespace($h->actor->installation, $h->surface->panel, 'host.client', $type);
        $ref = new SurfaceRef(new PanelId('acme.article'), new SurfaceKindId('acme.management'), new HostEntityRef('article', '42'), new Incarnation(1));
        $h = $handles->forSurface($ref);
        $surfaceCommand = new CommandEnvelope(new CommandKey('p57.surface.management.0001'), $namespace($h, 'sockit.surfaces.create'), $surfaces->digest($h, 'create'));
        Runtime::success($surfaces->create($h, $surfaceCommand));
        $input = new GroupCommand(JoinPolicy::Auto, 'P57 group');
        $create = new CommandEnvelope(new CommandKey('p57.group.create.0001'), $namespace($h, 'groups.create'), $groups->digest($h, $input));
        $group = $groups->create($h, $input, $create);
        Runtime::success($group);
        $resource = $group->resource ?? throw new \RuntimeException('Missing group');
        $join = new MembershipCommand($resource, 'join');
        $rev = new Revision(1);
        $joinCmd = new CommandEnvelope(new CommandKey('p57.group.join.0001'), $namespace($h, 'groups.join'), $groups->digest($h, $join, $rev), $rev);
        Runtime::success($groups->join($h, $join, $joinCmd));
        $contentRef = new SurfaceRef($ref->panel, new SurfaceKindId('acme.content'), new HostEntityRef('sockit.group', $resource->id), new Incarnation(1));
        $content = $handles->forSurface($contentRef);
        $surfaceCommand = new CommandEnvelope(new CommandKey('p57.surface.content.0001'), $namespace($content, 'sockit.surfaces.create'), $surfaces->digest($content, 'create'));
        Runtime::success($surfaces->create($content, $surfaceCommand));
        $post = new PostCommand($contentRef, 'Group content', AudienceSpec::group(new GroupRef($resource->id)), publish: true);
        $postCmd = new CommandEnvelope(new CommandKey('p57.post.create.0001'), $namespace($content, 'posts.create'), $posts->digest($content, $post));
        $created = $posts->create($content, $post, $postCmd);
        Runtime::check($created->isPublished(), 'Group post failed: '.json_encode($created->operation->toWire(), JSON_THROW_ON_ERROR));
        $postRef = $created->operation->resource ?? throw new \RuntimeException('Missing post');
        $target = new TargetRef(new HostEntityRef('sockit.post', $postRef->id), new Incarnation(1));
        $reaction = new ReactionCommand($contentRef, $target, 'like');
        $reactionCmd = new CommandEnvelope(new CommandKey('p57.reaction.add.0001'), $namespace($content, 'reactions.add'), $reactions->digest($content, $reaction, 'add'));
        $locks = [];
        $app->make(DatabaseManager::class)->connection('owner_a')->listen(static function (QueryExecuted $event) use (&$locks): void {
            if (str_contains(strtolower($event->sql), 'for update')) {
                $locks[] = strtolower($event->sql);
            }
        });
        Runtime::success($reactions->add($content, $reaction, $reactionCmd));
        $groupLock = $postLock = null;
        foreach ($locks as $index => $sql) {
            if (str_contains($sql, 'article_groups')) {
                $groupLock ??= $index;
            }
            if (str_contains($sql, 'article_posts')) {
                $postLock ??= $index;
            }
        }
        Runtime::check(is_int($groupLock) && is_int($postLock) && $groupLock < $postLock, 'Group lock was not taken before post lock');
        $cachedPage = $posts->feed($content, new FeedQuery);
        Runtime::check($posts->read($content, $postRef)->body === 'Group content' && count($cachedPage->items) === 1 && $reactions->summary($content, $target)->counts === ['like' => 1], 'Group content missing');
        // All membership transitions use the public facade and the current group revision.
        $key = 0;
        $membership = static function (string $actor, string $action, ?string $subject = null) use ($host, $handles, $ref, $resource, $groups, $namespace, &$key) {
            $host->id = $actor;
            $management = $handles->forSurface($ref);
            $input = new MembershipCommand($resource, $action, $subject === null ? null : new ActorRef('user', $subject));
            $revision = $groups->read($management, $resource)->revision;
            $command = new CommandEnvelope(new CommandKey('p57.member.command.'.++$key), $namespace($management, 'groups.'.$action), $groups->digest($management, $input, $revision), $revision);

            return match ($action) {
                'join' => $groups->join($management, $input, $command),
                'leave' => $groups->leave($management, $input, $command),
                'invite' => $groups->invite($management, $input, $command),
                'approve' => $groups->approve($management, $input, $command),
                default => throw new \RuntimeException('Invalid membership action'),
            };
        };
        $policy = static function (JoinPolicy $policy) use ($host, $handles, $ref, $resource, $groups, $namespace, &$key): void {
            $host->id = '7';
            $management = $handles->forSurface($ref);
            $revision = $groups->read($management, $resource)->revision;
            $input = new GroupCommand($policy, resource: $resource);
            $command = new CommandEnvelope(new CommandKey('p57.policy.command.'.++$key), $namespace($management, 'groups.policy'), $groups->digest($management, $input, $revision), $revision);
            Runtime::success($groups->setJoinPolicy($management, $input, $command));
        };
        $deniedRead = static function (string $actor) use ($host, $handles, $contentRef, $posts, $postRef): void {
            $host->id = $actor;
            try {
                $posts->read($handles->forSurface($contentRef), $postRef);
                throw new \RuntimeException('Nonmember read group post: '.$actor);
            } catch (SocKitException) {
            }
        };
        $connection = $app->make(DatabaseManager::class)->connection('owner_a');
        $state = static fn (string $actor): mixed => $connection->table('public.article_group_members')->where('group_id', $resource->id)->where('actor_ref', new ActorRef('user', $actor)->toWire())->value('state');
        Runtime::check($state('nonmember') === null, 'Unexpected nonmember row');
        $deniedRead('nonmember');
        $policy(JoinPolicy::Approval);
        Runtime::success($membership('8', 'join'));
        Runtime::check($state('8') === 'pending', 'Approval request was not pending');
        $deniedRead('8');
        Runtime::success($membership('7', 'approve', '8'));
        Runtime::check($state('8') === 'active', 'Approval did not activate subject');
        $host->id = '8';
        Runtime::check($posts->read($handles->forSurface($contentRef), $postRef)->body === 'Group content', 'Approved member denied');
        $policy(JoinPolicy::Invite);
        Runtime::success($membership('7', 'invite', '9'));
        Runtime::check($state('9') === 'invited', 'Invitation state missing');
        $deniedRead('9');
        Runtime::success($membership('9', 'join'));
        Runtime::check($state('9') === 'active', 'Self join did not activate invitation');
        $host->id = '9';
        Runtime::check($posts->read($handles->forSurface($contentRef), $postRef)->body === 'Group content', 'Invited member denied');
        $policy(JoinPolicy::Closed);
        Runtime::check(! $membership('10', 'join')->isSuccess(), 'Closed group accepted join');
        $policy(JoinPolicy::Auto);
        $consent->mode = 'deny';
        Runtime::check(! $membership('10', 'join')->isSuccess(), 'Denied consent accepted');
        $consent->mode = 'unknown';
        Runtime::check(! $membership('10', 'join')->isSuccess(), 'Unknown consent accepted');
        Runtime::check($state('10') === null, 'Closed or unconsented join changed membership');
        $consent->mode = 'allow';
        Runtime::success($membership('parent', 'join'));
        $host->parentObserver = true;
        $host->id = 'parent';
        $parent = $handles->forSurface($contentRef);
        Runtime::check($posts->read($parent, $postRef)->body === 'Group content', 'Observer cannot read');
        $parentPost = new PostCommand($contentRef, 'Observer write', AudienceSpec::group(new GroupRef($resource->id)), publish: true);
        $parentCmd = new CommandEnvelope(new CommandKey('p57.parent.post.0001'), $namespace($parent, 'posts.create'), $posts->digest($parent, $parentPost));
        Runtime::check(! $posts->create($parent, $parentPost, $parentCmd)->operation->isSuccess(), 'Observer became writer');
        $host->id = '7';
        $h = $handles->forSurface($ref);
        $content = $handles->forSurface($contentRef);
        foreach (['banned', 'consent'] as $guard) {
            $host->$guard = $guard === 'banned';
            $deniedRead('7');
            $host->$guard = $guard !== 'banned';
        }
        $host->unknown = true;
        $unknownPost = new PostCommand($contentRef, 'Unknown boundary', AudienceSpec::group(new GroupRef($resource->id)), publish: true);
        $unknownCmd = new CommandEnvelope(new CommandKey('p57.unknown.post.0001'), $namespace($content, 'posts.create'), $posts->digest($content, $unknownPost));
        Runtime::check(! $posts->create($content, $unknownPost, $unknownCmd)->operation->isSuccess(), 'Unknown boundary allowed publication');
        $host->unknown = false;
        // A second generated panel uses the same actor and host identifier, but its own tables.
        $neighborRef = new SurfaceRef(new PanelId('acme.product'), new SurfaceKindId('acme.management'), new HostEntityRef('product', '42'), new Incarnation(1));
        $neighbor = $handles->forSurface($neighborRef);
        $neighborSurface = new CommandEnvelope(new CommandKey('p57.neighbor.surface.0001'), $namespace($neighbor, 'sockit.surfaces.create'), $surfaces->digest($neighbor, 'create'));
        Runtime::success($surfaces->create($neighbor, $neighborSurface));
        $neighborInput = new GroupCommand(JoinPolicy::Auto, 'Neighbor');
        $neighborCreate = new CommandEnvelope(new CommandKey('p57.neighbor.create.0001'), $namespace($neighbor, 'groups.create'), $groups->digest($neighbor, $neighborInput));
        $neighborGroup = $groups->create($neighbor, $neighborInput, $neighborCreate)->resource ?? throw new \RuntimeException('Missing neighbor group');
        $neighborJoin = new MembershipCommand($neighborGroup, 'join');
        $neighborRev = new Revision(1);
        $neighborCmd = new CommandEnvelope(new CommandKey('p57.neighbor.join.0001'), $namespace($neighbor, 'groups.join'), $groups->digest($neighbor, $neighborJoin, $neighborRev), $neighborRev);
        Runtime::success($groups->join($neighbor, $neighborJoin, $neighborCmd));
        $neighborContent = new SurfaceRef($neighborRef->panel, new SurfaceKindId('acme.content'), new HostEntityRef('sockit.group', $neighborGroup->id), new Incarnation(1));
        $same = AudienceSpec::group(new GroupRef($resource->id));
        $other = AudienceSpec::group(new GroupRef($neighborGroup->id));
        $gate = $app->make(AudienceGate::class);
        $batch = $gate->readMany($content->actor, [
            new AudienceSubject('a', $same, null, $contentRef, $content->actor->installation),
            new AudienceSubject('b', $other, null, $neighborContent, $content->actor->installation),
            new AudienceSubject('cross', $same, null, $neighborContent, $content->actor->installation),
            new AudienceSubject('installation', $same, null, $contentRef, new InstallationRef('other-installation')),
        ]);
        Runtime::check($batch['a']->canRead() && $batch['b']->canRead() && ! $batch['cross']->canRead() && ! $batch['installation']->canRead(), 'Mixed panel or installation batch leaked');
        // Nested worker execution is isolated even when both factories throw.
        $jobs = $app->make(OperationJobs::class);
        $workerA = new OperationJobReference($contentRef, $target, new ActorRef('user', '7'), '1', $postCmd);
        $workerB = new OperationJobReference($neighborRef, new TargetRef(new HostEntityRef('product', '42'), new Incarnation(1)), new ActorRef('user', '8'), '1', $neighborCmd);
        $seen = [];
        $outer = $jobs->execute($workerA, static function (OperationHandle $worker) use ($jobs, $workerB, &$seen) {
            $seen[] = $worker->surface->panel->value.':'.$worker->actor->actor?->id;
            $inner = $jobs->execute($workerB, static function (OperationHandle $worker) use (&$seen) {
                $seen[] = $worker->surface->panel->value.':'.$worker->actor->actor?->id;
                throw new \RuntimeException('Nested worker failure');
            });
            Runtime::check(! $inner->isSuccess(), 'Nested worker exception accepted');
            $seen[] = $worker->surface->panel->value.':'.$worker->actor->actor?->id;
            throw new \RuntimeException('Outer worker failure');
        });
        Runtime::check(! $outer->isSuccess() && $seen === ['acme.article:7', 'acme.product:8', 'acme.article:7'] && $handles->forSurface($ref)->actor->actor?->id === '7', 'Worker context leaked on nested exception');
        $tenant->id = 'other-installation';
        $host->installation = 'other-installation';
        $otherHandle = $handles->forSurface($ref);
        $otherSurface = new CommandEnvelope(new CommandKey('p57.other.surface.0001'), $namespace($otherHandle, 'sockit.surfaces.create'), $surfaces->digest($otherHandle, 'create'));
        Runtime::success($surfaces->create($otherHandle, $otherSurface));
        $otherInput = new GroupCommand(JoinPolicy::Auto, 'Other installation');
        $otherCommand = new CommandEnvelope(new CommandKey('p57.other.group.0001'), $namespace($otherHandle, 'groups.create'), $groups->digest($otherHandle, $otherInput));
        $otherGroup = $groups->create($otherHandle, $otherInput, $otherCommand)->resource ?? throw new \RuntimeException('Other installation group missing');
        $otherJoin = new MembershipCommand($otherGroup, 'join');
        $otherRev = new Revision(1);
        $otherJoinCmd = new CommandEnvelope(new CommandKey('p57.other.join.0001'), $namespace($otherHandle, 'groups.join'), $groups->digest($otherHandle, $otherJoin, $otherRev), $otherRev);
        Runtime::success($groups->join($otherHandle, $otherJoin, $otherJoinCmd));
        $tenant->id = 'runtime-test';
        $host->installation = 'runtime-test';
        $content = $handles->forSurface($contentRef);
        $crossInstall = $gate->readMany($content->actor, [
            new AudienceSubject('own', $same, null, $contentRef, $content->actor->installation),
            new AudienceSubject('other', AudienceSpec::group(new GroupRef($otherGroup->id)), null, $contentRef, new InstallationRef('other-installation')),
        ]);
        Runtime::check($crossInstall['own']->canRead() && ! $crossInstall['other']->canRead(), 'Other installation leaked');
        $connection->table('public.p48_targets')->where('type', 'article')->update(['visible' => false]);
        $deniedRead('7');
        $connection->table('public.p48_targets')->where('type', 'article')->update(['visible' => true]);
        $host->id = '7';
        $archiveRevision = $surfaces->get($content)->revision;
        $archive = new CommandEnvelope(new CommandKey('p57.content.archive.0001'), $namespace($content, 'sockit.surfaces.archive'), $surfaces->digest($content, 'archive', $archiveRevision), $archiveRevision);
        Runtime::success($surfaces->archive($content, $archive));
        Runtime::check($posts->read($content, $postRef)->body === 'Group content', 'Archive denied allowed reader');
        $archivePost = new PostCommand($contentRef, 'Archive write', $same, publish: true);
        $archivePostCmd = new CommandEnvelope(new CommandKey('p57.archive.post.0001'), $namespace($content, 'posts.create'), $posts->digest($content, $archivePost));
        Runtime::check(! $posts->create($content, $archivePost, $archivePostCmd)->operation->isSuccess(), 'Archive allowed post');
        $archiveReaction = new CommandEnvelope(new CommandKey('p57.archive.reaction.0001'), $namespace($content, 'reactions.add'), $reactions->digest($content, $reaction, 'add'));
        Runtime::check(! $reactions->add($content, $reaction, $archiveReaction)->isSuccess(), 'Archive allowed reaction');
        $resumeRevision = $surfaces->get($content)->revision;
        $resume = new CommandEnvelope(new CommandKey('p57.content.resume.0001'), $namespace($content, 'sockit.surfaces.resume'), $surfaces->digest($content, 'resume', $resumeRevision), $resumeRevision);
        Runtime::success($surfaces->resume($content, $resume));
        foreach (['post', 'reaction'] as $mode) {
            $beforePosts = $connection->table('public.article_posts')->count();
            $beforeReactions = $connection->table('public.article_reactions')->count();
            $beforeGeneration = $connection->table('public.article_groups')->where('id', $resource->id)->value('membership_generation');
            if (! is_int($beforeGeneration)) {
                throw new \RuntimeException('Missing group generation');
            }
            Race::leaveFirst($app, $resource->id, $postRef->id, $mode, static fn () => $membership('7', 'leave'));
            Runtime::check($connection->table('public.article_posts')->count() === $beforePosts && $connection->table('public.article_reactions')->count() === $beforeReactions && $connection->table('public.article_groups')->where('id', $resource->id)->value('membership_generation') === $beforeGeneration + 1, 'Stale race wrote content or group generation did not change');
            Runtime::success($membership('7', 'join'));
            $host->id = '7';
            $content = $handles->forSurface($contentRef);
            Runtime::check($posts->read($content, $postRef)->body === 'Group content', 'Rejoin failed after race');
        }
        $h = $handles->forSurface($ref);
        $leave = new MembershipCommand($resource, 'leave');
        $rev = $groups->read($h, $resource)->revision;
        $leaveCmd = new CommandEnvelope(new CommandKey('p57.group.leave.0001'), $namespace($h, 'groups.leave'), $groups->digest($h, $leave, $rev), $rev);
        Runtime::success($groups->leave($h, $leave, $leaveCmd));
        Runtime::check($state('7') === 'left', 'Leave did not persist');
        foreach ([fn () => $posts->read($content, $postRef), fn () => $posts->revision($content, $postRef, new Revision(1))] as $read) {
            try {
                $read();
                throw new \RuntimeException('Left author read content');
            } catch (SocKitException) {
            }
        }
        try {
            $handles->resolveTarget($content, $target);
            throw new \RuntimeException('Left author resolved target');
        } catch (SocKitException) {
        }
        Runtime::check($cachedPage->items[0]->resource->id === $postRef->id && ! $reactions->add($content, $reaction, $reactionCmd)->isSuccess(), 'Cached reference or reaction replay granted access');
        Runtime::check($posts->feed($content, new FeedQuery)->items === [] && $reactions->summary($content, $target)->counts === null && $posts->lookup($content, $postCmd->namespace, $postCmd->key)?->operation->resource === null && ! $reactions->lookup($content, $reactionCmd->namespace, $reactionCmd->key)?->isSuccess(), 'Leave disclosed content, counts or receipt');

        return ['complete' => true];
    }
}
