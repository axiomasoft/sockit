<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use SocKit\Modules\Modules;
use SocKit\Panels\Panel;
use SocKit\Schema\Internal\ReadinessCatalog;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Tests\Fixtures\Schema\SnapshotFixture;
use SocKit\Tests\Fixtures\Social\Rows;
use SocKit\Tests\Fixtures\Social\SchemaFixture;
use SocKit\Tests\Fixtures\Social\Selection;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('installs only selected production tables with exact catalog indexes constraints and own mapped FK', function (array $selected, array $social): void {
    $selected = array_map(Scenario::text(...), array_values($selected));
    $social = array_map(Scenario::text(...), array_values($social));
    $f = new SchemaFixture($selected);
    try {
        foreach (['a', 'b'] as $id) {
            $f->migrations[$id]->up();
            $stmt = $f->pg->owner->prepare('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename');
            $stmt->execute(['social_'.$id]);
            $expected = array_map(fn (string $entity): string => 'drv_'.$id.'_'.($entity === 'posts' ? 'custom_posts' : $entity), ['surface_instances', 'receipts', 'outbox', 'tombstones', ...$social]);
            expect($stmt->fetchAll(PDO::FETCH_COLUMN))->toEqualCanonicalizing($expected);
            $constraints = PgTarget::query($f->pg->owner, "SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE connamespace='social_{$id}'::regnamespace")->fetchAll(PDO::FETCH_KEY_PAIR);
            $physical = Scenario::object(SnapshotFixture::decode($f->migrations[$id])['physical']);
            expect(new ReadinessCatalog()->inspect($f->pg->owner, $physical, $f->migrations[$id]->constant('DIGEST')))->toBe([]);
            $specs = Scenario::object($physical['entities']);
            foreach ($specs as $entity => $value) {
                $spec = Scenario::object($value);
                foreach (Scenario::object($spec['checks']) as $value) {
                    $check = Scenario::object($value);
                    expect($constraints)->toHaveKey(Scenario::text($check['name']));
                }
                foreach (Scenario::object($spec['indexes']) as $value) {
                    $index = Scenario::object($value);
                    $stmt = $f->pg->owner->prepare('SELECT pg_get_indexdef(indexrelid) FROM pg_index WHERE indexrelid=to_regclass(?)');
                    $stmt->execute(['social_'.$id.'.'.Scenario::text($index['name'])]);
                    $definition = $stmt->fetchColumn();
                    expect($definition)->toBeString();
                    assert(is_string($definition));
                    expect($definition)->toContain('('.implode(', ', array_map(Scenario::text(...), Scenario::entries($index['columns']))).')');
                    expect(str_contains($definition, 'UNIQUE'))->toBe($index['unique']);
                }
                foreach (array_keys(Scenario::object($spec['columns'])) as $column) {
                    expect($f->model($entity, $id)->getCasts())->toHaveKey($column);
                }
            }
            $fks = PgTarget::query($f->pg->owner, "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE connamespace='social_{$id}'::regnamespace AND contype='f'")->fetchAll(PDO::FETCH_COLUMN);
            expect($fks)->toHaveCount(in_array('sockit.posts', $selected, true) ? 1 : 0);
            if ($fks !== []) {
                expect($fks[0])->toContain('REFERENCES social_'.$id.'.drv_'.$id.'_custom_posts(id)');
            }
            $columns = PgTarget::query($f->pg->owner, "SELECT column_name,data_type FROM information_schema.columns WHERE table_schema='social_{$id}'")->fetchAll(PDO::FETCH_KEY_PAIR);
            expect($columns)->not->toHaveKey('tenant_id');
            if (in_array('sockit.posts', $selected, true)) {
                expect($columns['audience'])->toBe('jsonb')->and($columns['published_at'])->toBe('timestamp with time zone');
            }
        }
        fwrite(STDERR, 'P4.1 catalogs selected='.json_encode($selected, JSON_THROW_ON_ERROR)."; exact tables/indexes/check names/FKs/casts verified for both panels\n");
    } finally {
        $f->cleanup();
    }
})->with([
    'empty' => [[], []],
    'reactions' => [['sockit.reactions'], ['reactions', 'reaction_counts']],
    'posts' => [['sockit.posts'], ['posts', 'post_revisions']],
    'both' => [['sockit.reactions', 'sockit.posts'], ['reactions', 'reaction_counts', 'posts', 'post_revisions']],
]);

it('retains lossless maximum refs verifies digest collisions and isolates membership kind actor and incarnations', function (): void {
    $f = new SchemaFixture(['sockit.reactions']);
    try {
        foreach ($f->migrations as $migration) {
            $migration->up();
        }
        $long = str_repeat('Т', 256);
        $actor = new ActorRef(str_repeat('a', 96), str_repeat('A', 512));
        $identity = Rows::identity(key: $long, actor: $actor);
        $row = Rows::reaction($identity);
        $model = $f->model('reactions');
        $model->fill($row)->save();
        $loaded = $model->newQuery()->whereKey($row['id'])->firstOrFail();
        expect($loaded->getAttribute('target_key'))->toBe($long)->and($loaded->getAttribute('actor_ref'))->toBe($actor->toWire())
            ->and($loaded->getAttribute('active'))->toBeTrue()->and($loaded->getAttribute('revision'))->toBe(1)
            ->and($loaded->getAttribute('created_at'))->toBeInstanceOf(DateTimeImmutable::class);
        $identity->verify($loaded->getAttributes());
        $model->newQuery()->whereKey($row['id'])->update(['active' => false, 'revision' => 2]);
        $inactive = $model->newQuery()->whereKey($row['id'])->firstOrFail();
        expect($inactive->getKey())->toBe($row['id'])->and($inactive->getAttribute('active'))->toBeFalse()->and($inactive->getAttribute('revision'))->toBe(2);
        expect(fn () => $model->newQuery()->create(Rows::reaction($identity)))->toThrow(QueryException::class);
        foreach ([Rows::identity(key: '42'), Rows::identity(key: '0042'), Rows::identity(key: $long, actor: new ActorRef('person', '0042')),
            Rows::identity(key: $long, actor: $actor, kind: 'thanks'), Rows::identity(key: $long, actor: $actor, incarnation: 2),
            Rows::identity(key: $long, actor: $actor, surfaceIncarnation: 2)] as $other) {
            expect($other->digest())->not->toBe($identity->digest());
            $model->newQuery()->create(Rows::reaction($other));
            $forged = [...$other->columns(), 'identity_digest' => $identity->digest()];
            expect(fn () => $identity->verify($forged))->toThrow(InvalidPayloadException::class);
        }
        // Same physical UUID in a second panel, same host/target key, independent state.
        $b = Rows::reaction(Rows::identity(panel: 'b', key: $long, actor: $actor));
        $b['id'] = $row['id'];
        $f->model('reactions', 'b')->newQuery()->create($b);
        expect($f->model('reactions', 'b')->newQuery()->whereKey($row['id'])->firstOrFail()->getAttribute('active'))->toBeTrue();
        $countIdentity = Rows::identity(key: $long, actor: null);
        $count = Rows::reaction($countIdentity);
        unset($count['active']);
        $count['count'] = 0;
        $counter = $f->model('reaction_counts')->newQuery()->create($count);
        $countIdentity->verify($counter->getAttributes());
        expect($counter->getAttribute('count'))->toBe(0)->and($counter->getAttributes())->not->toHaveKey('actor_ref');
        expect(fn () => $counter->newQuery()->whereKey($counter->getKey())->update(['count' => -1]))->toThrow(QueryException::class);
        expect(fn () => $counter->newQuery()->whereKey($counter->getKey())->update(['revision' => 0]))->toThrow(QueryException::class);
        fwrite(STDERR, "P4.1 maximum 512-byte opaque host/target keys and 609-byte actor; zero count accepted, negative/revision zero rejected; collision rejected; inactive membership retains UUID/revision\n");
    } finally {
        $f->cleanup();
    }
});

it('hydrates custom concrete posts and mapped revision relations with state constraints and stable chronology', function (): void {
    $f = new SchemaFixture(['sockit.posts']);
    try {
        foreach ($f->migrations as $migration) {
            $migration->up();
        }
        $post = Rows::post();
        $a = $f->model('posts')->newQuery()->create($post);
        $revision = $f->model('post_revisions')->newQuery()->create(Rows::revision($post));
        $related = $revision->getRelationValue('post');
        if (! $related instanceof Model) {
            throw new RuntimeException('Revision did not hydrate its mapped post');
        }
        expect(new ReflectionMethod($a, 'hostMarker')->invoke($a))->toBe('custom');
        expect($related->getKey())->toBe($a->getKey())
            ->and($a->getRelationValue('postRevisionsPost'))->toHaveCount(1)
            ->and($a->getAttribute('audience'))->toBe(['type' => 'public'])
            ->and($a->getAttribute('media_refs'))->toBe(['media:0042'])
            ->and($a->getConnectionName())->toBe('social_a')->and($a->getTable())->toBe('social_a.a_custom_posts');
        expect(fn () => $f->model('post_revisions')->newQuery()->create(Rows::revision($post)))->toThrow(QueryException::class);
        $orphan = Rows::revision($post);
        $orphan['post_id'] = '019a0000-0000-7000-8000-000000000001';
        expect(fn () => $f->model('post_revisions')->newQuery()->create($orphan))->toThrow(QueryException::class);
        foreach (['pending', 'held', 'published', 'rejected'] as $state) {
            $a->newQuery()->whereKey($a->getKey())->update(['publication_state' => $state, 'visibility' => 'hidden']);
            $hidden = $a->newQuery()->whereKey($a->getKey())->firstOrFail();
            expect($hidden->getAttribute('publication_state'))->toBe($state);
            $hidden->update(['visibility' => 'visible']);
            expect($hidden->fresh()?->getAttribute('publication_state'))->toBe($state);
        }
        foreach (['revision' => 0, 'privacy_revision' => 0, 'safety_generation' => 0, 'consent_generation' => 0, 'publication_state' => 'unknown', 'visibility' => 'unknown', 'content_state' => 'erased', 'body' => null] as $column => $value) {
            expect(fn () => $a->newQuery()->whereKey($a->getKey())->update([$column => $value]))->toThrow(QueryException::class);
        }
        $published = '2026-10-29 00:00:00+00';
        $a->update(['publication_state' => 'published', 'published_at' => $published]);
        $second = $f->model('posts')->newQuery()->create([...Rows::post(), 'published_at' => $published, 'publication_state' => 'published']);
        $ids = $a->newQuery()->orderBy('published_at')->orderBy('id')->pluck('id')->all();
        $expected = [$a->getKey(), $second->getKey()];
        sort($expected);
        expect($ids)->toBe($expected);
        $a->update(['body' => null, 'media_refs' => [], 'content_state' => 'erased', 'erased_at' => $published, 'visibility' => 'deleted']);
        expect($a->fresh()?->getAttribute('body'))->toBeNull()->and($a->fresh()?->getAttribute('erased_at'))->toBeInstanceOf(DateTimeImmutable::class);
        expect(fn () => $a->newQuery()->whereKey($a->getKey())->update(['body' => 'resurrect']))->toThrow(QueryException::class);
        $b = [...Rows::post('b'), 'id' => $post['id'], 'body' => 'panel B'];
        $f->model('posts', 'b')->newQuery()->create($b);
        expect($f->model('posts', 'b')->newQuery()->whereKey($post['id'])->firstOrFail()->getAttribute('body'))->toBe('panel B');
        fwrite(STDERR, "P4.1 mapped custom post models, immutable revision uniqueness, own FK, hydration/casts/relations, Pending/Held overlay restore and chronology tie verified\n");
    } finally {
        $f->cleanup();
    }
});

it('preserves installed data on disable and rolls back only the frozen selected panel after mutable configuration changes', function (): void {
    $f = new SchemaFixture;
    try {
        foreach ($f->migrations as $migration) {
            $migration->up();
        }
        foreach (['a', 'b'] as $id) {
            $f->model('posts', $id)->newQuery()->create(Rows::post($id));
            $f->model('reactions', $id)->newQuery()->create(Rows::reaction(Rows::identity($id)));
        }
        $before = PgTarget::query($f->pg->owner, 'SELECT md5(string_agg(row_to_json(t)::text,\'\' ORDER BY id)) FROM social_b.drv_b_custom_posts t')->fetchColumn();
        $app = Selection::app();
        $app->boot();
        $f->capsule->bootEloquent();
        expect($app->make(Modules::class)->schemaComponents())->toBe([]);
        $disabled = new PanelSchemaCompiler()->compile(new Panel()->id('social.a')->hostTypes('article')->connection('unavailable')->prefix('different_')->materialize(), []);
        expect($disabled->storage->tables)->not->toHaveKey('posts')->and($disabled->storage->tables)->not->toHaveKey('reactions');
        expect($f->model('posts')->newQuery()->count())->toBe(1)->and($f->model('reactions')->newQuery()->count())->toBe(1);
        $f->migrations['a']->down();
        expect(PgTarget::query($f->pg->owner, "SELECT count(*) FROM pg_tables WHERE schemaname='social_a'")->fetchColumn())->toBe(0)
            ->and(PgTarget::query($f->pg->owner, 'SELECT md5(string_agg(row_to_json(t)::text,\'\' ORDER BY id)) FROM social_b.drv_b_custom_posts t')->fetchColumn())->toBe($before);
        $f->model('posts', 'b')->newQuery()->create(Rows::post('b'));
        expect($f->model('posts', 'b')->newQuery()->count())->toBe(2)
            ->and(PgTarget::query($f->pg->owner, 'SELECT value FROM public.p41_host')->fetchColumn())->toBe('host-sentinel');
        fwrite(STDERR, "P4.1 disabled registry preserves social data; frozen A rollback drops only A; B checksum unchanged and B remains writable; host sentinel preserved\n");
    } finally {
        $f->cleanup();
    }
});
