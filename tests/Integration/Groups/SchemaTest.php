<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Groups\Enums\MembershipState;
use SocKit\Groups\Internal\GroupIdentity;
use SocKit\Groups\Internal\MemberIdentity;
use SocKit\Panels\Data\PanelId;
use SocKit\Schema\Internal\ReadinessCatalog;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Support\Ids;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Tests\Fixtures\Groups\Rows;
use SocKit\Tests\Fixtures\Groups\SchemaFixture;
use SocKit\Tests\Fixtures\Groups\Selection;
use SocKit\Tests\Fixtures\Schema\SnapshotFixture;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('installs only selected module tables and verifies real catalogs bindings and own mapped FK in two panels', function (array $selected, array $entities): void {
    $selected = array_map(Scenario::text(...), array_values($selected));
    $entities = array_map(Scenario::text(...), array_values($entities));
    $f = new SchemaFixture($selected);
    try {
        foreach (['a', 'b'] as $id) {
            $f->migrations[$id]->up();
            $stmt = $f->pg->owner->prepare('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename');
            $stmt->execute(['groups_'.$id]);
            expect($stmt->fetchAll(PDO::FETCH_COLUMN))->toEqualCanonicalizing(array_map(
                fn (string $entity): string => 'drv_'.$id.'_'.($entity === 'groups' ? 'custom_groups' : $entity),
                ['surface_instances', 'receipts', 'outbox', 'tombstones', ...$entities]));
            $physical = Scenario::object(SnapshotFixture::decode($f->migrations[$id])['physical']);
            expect(new ReadinessCatalog()->inspect($f->pg->owner, $physical, $f->migrations[$id]->constant('DIGEST')))->toBe([]);
            $constraints = PgTarget::query($f->pg->owner, "SELECT conname FROM pg_constraint WHERE connamespace='groups_{$id}'::regnamespace")->fetchAll(PDO::FETCH_COLUMN);
            foreach (Scenario::object($physical['entities']) as $entity => $value) {
                $spec = Scenario::object($value);
                foreach (Scenario::object($spec['checks']) as $check) {
                    expect($constraints)->toContain(Scenario::object($check)['name']);
                }
                foreach (Scenario::object($spec['indexes']) as $value) {
                    $index = Scenario::object($value);
                    $name = Scenario::text($index['name']);
                    expect(strlen($name))->toBeLessThanOrEqual(63);
                    $stmt = $f->pg->owner->prepare('SELECT pg_get_indexdef(indexrelid) FROM pg_index WHERE indexrelid=to_regclass(?)');
                    $stmt->execute(['groups_'.$id.'.'.$name]);
                    $definition = Scenario::text($stmt->fetchColumn());
                    expect($definition)->toContain('('.implode(', ', array_map(Scenario::text(...), Scenario::entries($index['columns']))).')')
                        ->and(str_contains($definition, 'UNIQUE'))->toBe($index['unique']);
                }
                foreach (array_keys(Scenario::object($spec['columns'])) as $column) {
                    expect($f->model($entity, $id)->getCasts())->toHaveKey($column);
                }
            }
            $fks = PgTarget::query($f->pg->owner, "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE connamespace='groups_{$id}'::regnamespace AND contype='f'")->fetchAll(PDO::FETCH_COLUMN);
            expect($fks)->toHaveCount((int) in_array('sockit.groups', $selected, true) + (int) in_array('sockit.posts', $selected, true));
            if (in_array('sockit.groups', $selected, true)) {
                expect(implode(' ', array_map(Scenario::text(...), $fks)))->toContain('REFERENCES groups_'.$id.'.drv_'.$id.'_custom_groups(id)');
                $columns = PgTarget::query($f->pg->owner, "SELECT column_name,data_type FROM information_schema.columns WHERE table_schema='groups_{$id}' AND table_name='drv_{$id}_group_members'")->fetchAll(PDO::FETCH_KEY_PAIR);
                expect($columns['actor_ref'])->toBe('text')->and($columns['consent_generation'])->toBe('bigint')->and($columns['created_at'])->toBe('timestamp with time zone')
                    ->and($columns)->not->toHaveKey('tenant_id');
            }
        }
        expect(PgTarget::query($f->pg->owner, 'SELECT value FROM public.p51_host')->fetchColumn())->toBe('host-sentinel');
        fwrite(STDERR, 'P5.1 real catalogs/checks/indexes/FKs/casts selected='.json_encode($selected, JSON_THROW_ON_ERROR)."; two panel mappings verified\n");
    } finally {
        $f->cleanup();
    }
})->with([
    'disabled' => [[], []],
    'reactions only' => [['sockit.reactions'], ['reactions', 'reaction_counts']],
    'groups only' => [['sockit.groups'], ['groups', 'group_members']],
    'all' => [['sockit.groups', 'sockit.reactions', 'sockit.posts'], ['groups', 'group_members', 'reactions', 'reaction_counts', 'posts', 'post_revisions']],
]);

it('retains full maximum refs names and actor wires with bounded uniqueness and fail closed collision verification', function (): void {
    $f = new SchemaFixture;
    try {
        foreach ($f->migrations as $migration) {
            $migration->up();
        }
        $identity = new GroupIdentity(new InstallationRef(str_repeat('i', 64)), new SurfaceRef(new PanelId('p.'.str_repeat('p', 94)),
            new SurfaceKindId('s.'.str_repeat('s', 94)), new HostEntityRef(str_repeat('h', 96), str_repeat('Я', 256)), new Incarnation(1)));
        $row = [...Rows::group($identity), 'name' => str_repeat('Ж', 256)];
        $group = $f->model('groups')->newQuery()->create($row);
        expect($f->model('group_members')->newQuery()->count())->toBe(0);
        $loaded = $group->fresh();
        assert($loaded !== null);
        $identity->verify($loaded->getAttributes());
        expect($loaded->getAttribute('host_key'))->toBe(str_repeat('Я', 256))->and($loaded->getAttribute('name'))->toBe(str_repeat('Ж', 256))
            ->and($loaded->getAttribute('membership_generation'))->toBe(1)->and($loaded->getAttribute('created_at'))->toBeInstanceOf(DateTimeImmutable::class)
            ->and($loaded->getConnectionName())->toBe('groups_a');
        expect(fn () => $group->newQuery()->create([...$row, 'id' => Ids::uuid7()]))->toThrow(QueryException::class);
        foreach ([Rows::identity(), Rows::identity(key: '42'), Rows::identity(key: '0042', incarnation: 2), Rows::identity(installation: 'other'),
            Rows::identity(panel: 'b'), Rows::identity(kind: 'host.subject'), Rows::identity(type: 'subject')] as $other) {
            $group->newQuery()->create(Rows::group($other));
            expect($other->digest())->not->toBe($identity->digest());
            expect(fn () => $identity->verify([...$other->columns(), 'surface_digest' => $identity->digest()]))->toThrow(InvalidPayloadException::class);
        }
        expect(fn () => $identity->verify([...$row, 'surface_digest' => str_repeat('0', 64)]))->toThrow(InvalidPayloadException::class);
        $actor = new ActorRef(str_repeat('a', 96), str_repeat('Я', 256));
        $groupId = Scenario::text($row['id']);
        $memberIdentity = new MemberIdentity($groupId, $actor);
        $memberRow = Rows::member($groupId, $actor);
        $member = $f->model('group_members')->newQuery()->create($memberRow);
        $loadedMember = $member->fresh();
        assert($loadedMember !== null);
        $memberIdentity->verify($loadedMember->getAttributes());
        expect(strlen(Scenario::text($loadedMember->getAttribute('actor_ref'))))->toBe(609)
            ->and($loadedMember->getAttribute('revision'))->toBe(1)->and($loadedMember->getAttribute('consent_generation'))->toBeNull()
            ->and($loadedMember->getAttribute('consent_token'))->toBeNull();
        expect(fn () => $member->newQuery()->create([...$memberRow, 'id' => Ids::uuid7()]))->toThrow(QueryException::class);
        foreach ([new ActorRef('person', '42'), new ActorRef('person', '0042'), new ActorRef('service', '0042')] as $otherActor) {
            $other = Rows::member($groupId, $otherActor);
            $member->newQuery()->create($other);
            expect(fn () => $memberIdentity->verify([...$other, 'actor_digest' => $memberIdentity->digest()]))->toThrow(InvalidPayloadException::class);
        }
        expect(fn () => $memberIdentity->verify([...$memberRow, 'group_id' => Ids::uuid7()]))->toThrow(InvalidPayloadException::class);
        expect(fn () => $memberIdentity->verify([...$memberRow, 'actor_digest' => str_repeat('0', 64)]))->toThrow(InvalidPayloadException::class);
        expect(fn () => new MemberIdentity('42', $actor))->toThrow(InvalidPayloadException::class);
        $otherGroup = Rows::group(Rows::identity(key: 'other'));
        $group->newQuery()->create($otherGroup);
        $member->newQuery()->create(Rows::member(Scenario::text($otherGroup['id']), $actor));
        foreach (MembershipState::cases() as $i => $state) {
            $member->newQuery()->whereKey($memberRow['id'])->update(['state' => $state->value, 'revision' => $i + 1, 'consent_generation' => 7, 'consent_token' => 'opaque-fence']);
            $loaded = $member->fresh();
            assert($loaded !== null);
            expect($loaded->getAttribute('state'))->toBe($state->value)->and($loaded->getAttribute('revision'))->toBe($i + 1)
                ->and($loaded->getAttribute('consent_generation'))->toBe(7)->and($loaded->getAttribute('consent_token'))->toBe('opaque-fence');
        }
        // A different state cannot create a second membership for the same actor.
        expect(fn () => $member->newQuery()->create([...$memberRow, 'id' => Ids::uuid7(), 'state' => 'active']))->toThrow(QueryException::class);
        foreach (JoinPolicy::cases() as $policy) {
            $group->newQuery()->whereKey($row['id'])->update(['join_policy' => $policy->value]);
            expect($group->fresh()?->getAttribute('join_policy'))->toBe($policy->value);
        }
        $f->model('groups', 'b')->newQuery()->create($row);
        $f->model('group_members', 'b')->newQuery()->create($memberRow);
        expect($f->model('group_members', 'b')->newQuery()->whereKey($memberRow['id'])->firstOrFail()->getAttribute('state'))->toBe('pending');
        fwrite(STDERR, "P5.1 maximum multibyte refs/name, 609-byte actor, all identity dimensions, same UUID across panels, unique collisions/full-ref guards and typed round trips verified\n");
    } finally {
        $f->cleanup();
    }
});

it('rejects invalid enums bounds revisions UUIDs digests and foreign membership without mutating valid rows', function (): void {
    $f = new SchemaFixture;
    try {
        $f->migrations['a']->up();
        $groupRow = Rows::group();
        $group = $f->model('groups')->newQuery()->create($groupRow);
        $memberRow = Rows::member(Scenario::text($groupRow['id']));
        $member = $f->model('group_members')->newQuery()->create($memberRow);
        $groupInvalid = [
            ['join_policy' => 'unknown'], ['revision' => 0], ['membership_generation' => 0], ['surface_incarnation' => 0],
            ['installation' => ''], ['panel' => ''], ['surface_kind' => ''], ['host_type' => ''], ['host_key' => ''], ['name' => ''],
            ['installation' => str_repeat('Ж', 33)], ['panel' => str_repeat('Ж', 49)], ['surface_kind' => str_repeat('Ж', 49)],
            ['host_type' => str_repeat('Ж', 49)], ['host_key' => str_repeat('Ж', 257)], ['name' => str_repeat('Ж', 257)],
            ['id' => '019a1ba0-0000-4000-8000-000000000042'], ['surface_digest' => str_repeat('X', 64)],
        ];
        $memberInvalid = [
            ['state' => 'unknown'], ['revision' => 0], ['consent_generation' => 0], ['actor_ref' => ''], ['actor_ref' => str_repeat('Я', 305)],
            ['actor_digest' => str_repeat('X', 64)], ['consent_token' => ''], ['consent_token' => str_repeat('Я', 257)],
            ['id' => '019a1ba0-0000-4000-8000-000000000042'],
        ];
        foreach ([[$group, $groupInvalid], [$member, $memberInvalid]] as [$model, $invalid]) {
            foreach ($invalid as $patch) {
                try {
                    $model->newQuery()->whereKey($model->getKey())->update($patch);
                    throw new RuntimeException('Invalid row accepted: '.json_encode($patch, JSON_THROW_ON_ERROR));
                } catch (QueryException $e) {
                    expect(($e->errorInfo[0] ?? null))->toBe('23514');
                }
            }
        }
        try {
            $member->newQuery()->whereKey($member->getKey())->update(['group_id' => Ids::uuid7()]);
            throw new RuntimeException('Foreign group accepted');
        } catch (QueryException $e) {
            expect(($e->errorInfo[0] ?? null))->toBe('23503');
        }
        expect(fn () => $group->delete())->toThrow(QueryException::class);
        expect($group->fresh()?->getAttribute('join_policy'))->toBe('approval')
            ->and($member->fresh()?->getAttribute('state'))->toBe('pending');
        fwrite(STDERR, "P5.1 real SQLSTATE 23514 bounds/enums/positive/UUID/digest, 23503 own FK/restrict verified; valid rows retained\n");
    } finally {
        $f->cleanup();
    }
});

it('rolls back the frozen owning panel with changed configuration preserving the other panel and host and decoy sentinels', function (): void {
    $f = new SchemaFixture;
    try {
        $f->pg->decoy->exec('CREATE TABLE public.p51_foreign (value text NOT NULL)');
        $f->pg->decoy->exec("INSERT INTO public.p51_foreign VALUES ('foreign-sentinel')");
        foreach (['a', 'b'] as $id) {
            $f->migrations[$id]->up();
            $group = Rows::group(Rows::identity(panel: $id, key: '0042'));
            $f->model('groups', $id)->newQuery()->create($group);
            $f->model('group_members', $id)->newQuery()->create(Rows::member(Scenario::text($group['id'])));
        }
        $oid = PgTarget::query($f->pg->owner, "SELECT 'groups_b.drv_b_custom_groups'::regclass::oid")->fetchColumn();
        $changed = Selection::app();
        $changed->boot();
        $f->migrations['a']->down();
        expect(PgTarget::query($f->pg->owner, "SELECT count(*) FROM pg_tables WHERE schemaname='groups_a'")->fetchColumn())->toBe(0)
            ->and(PgTarget::query($f->pg->owner, "SELECT 'groups_b.drv_b_custom_groups'::regclass::oid")->fetchColumn())->toBe($oid)
            ->and(PgTarget::query($f->pg->owner, 'SELECT host_key FROM groups_b.drv_b_custom_groups')->fetchColumn())->toBe('0042')
            ->and(PgTarget::query($f->pg->owner, 'SELECT count(*) FROM groups_b.drv_b_group_members')->fetchColumn())->toBe(1)
            ->and(PgTarget::query($f->pg->owner, 'SELECT value FROM public.p51_host')->fetchColumn())->toBe('host-sentinel')
            ->and(PgTarget::query($f->pg->decoy, 'SELECT value FROM public.p51_foreign')->fetchColumn())->toBe('foreign-sentinel');
        $f->migrations['b']->down();
        expect(PgTarget::query($f->pg->owner, "SELECT count(*) FROM pg_tables WHERE schemaname='groups_b'")->fetchColumn())->toBe(0);
        fwrite(STDERR, "P5.1 frozen reverse down removes only owning panel; other panel OID/data and host/decoy sentinels unchanged\n");
    } finally {
        $f->pg->decoy->exec('DROP TABLE public.p51_foreign');
        $f->cleanup();
    }
});

it('upgrades populated v1 PostgreSQL group and member rows with unprivileged role defaults', function (): void {
    $f = new SchemaFixture(previousGroups: true);
    try {
        $f->migrations['a']->up();
        $model = $f->model('groups')->newQuery()->create(Rows::group());
        $key = $model->getAttribute('id');
        assert(is_string($key));
        $member = $f->model('group_members')->newQuery()->create(Rows::member($key));
        foreach ($f->groupUpgrade['a'] as $sql) {
            $f->connection()->statement($sql);
        }
        expect($model->fresh()?->getAttribute('visibility'))->toBe('public')
            ->and($model->fresh()?->getAttribute('name'))->toBe('Клуб «Шахматы»')
            ->and($member->fresh()?->getAttribute('role'))->toBe('member')
            ->and($member->fresh()?->getAttribute('state'))->toBe('pending');
    } finally {
        $f->cleanup();
    }
});
