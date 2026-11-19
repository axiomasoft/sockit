<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use SocKit\Internal\Generation\GenerationFailure;
use SocKit\Schema\PanelMigrations;
use SocKit\Schema\PanelModel;
use SocKit\Tests\Fixtures\Groups\Selection;

final class SqliteArticleModel extends PanelModel
{
    public const array BINDING = [
        'connection' => 'article_sqlite',
        'schema' => null,
        'table' => 'article_entries',
        'primary' => ['id'],
        'key_policy' => 'uuid7',
        'key_type' => 'string',
        'incrementing' => false,
        'timestamps' => false,
        'casts' => ['id' => 'string', 'visible' => 'boolean'],
    ];
}

it('persists and hydrates a panel-bound Eloquent model on SQLite', function (): void {
    config(['database.connections.article_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    DB::connection('article_sqlite')->getSchemaBuilder()->create('article_entries', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->boolean('visible');
    });

    $model = new SqliteArticleModel(['visible' => true]);
    expect($model->save())->toBeTrue();
    $record = SqliteArticleModel::query()->where('id', $model->getKey())->sole();
    expect($record->getAttribute('visible'))->toBeTrue()
        ->and($record->getConnectionName())->toBe('article_sqlite')
        ->and($record->delete())->toBeTrue()
        ->and(SqliteArticleModel::query()->count())->toBe(0);
});

it('refuses a SQLite preview receipt upgrade before changing files or data', function (): void {
    $root = sys_get_temp_dir().'/sockit-preview-'.bin2hex(random_bytes(8));
    mkdir($root);
    $file = $root.'/preview.sqlite';
    try {
        $writer = new PDO('sqlite:'.$file);
        $writer->exec('CREATE TABLE custom_command_history (panel_id, command_namespace, command_key, command_type, actor_kind, payload_digest, applied_revision, result_payload)');
        $writer->exec("INSERT INTO custom_command_history(command_key, result_payload) VALUES ('original', '{}')");
        unset($writer);
        $before = hash_file('sha256', $file);
        $app = Selection::app(extra: ['database' => ['connections' => ['owner' => ['driver' => 'sqlite', 'database' => $file]]]]);
        $app->boot();
        expect(fn () => $app->make(PanelMigrations::class)->run($root, 'host.social', upgrade: true))
            ->toThrow(GenerationFailure::class, 'MIGRATION_PREVIEW_SQLITE_RECEIPTS_INCOMPATIBLE');
        expect(hash_file('sha256', $file))->toBe($before)
            ->and(scandir($root))->toBe(['.', '..', 'preview.sqlite']);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});
