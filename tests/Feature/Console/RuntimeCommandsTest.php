<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

it('registers the commands and lists and displays help without SQL', function (): void {
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $manager = app(DatabaseManager::class);
    expect($manager->getConnections())->toBe([]);
    expect(Artisan::call('list', ['--raw' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('sockit:doctor', 'sockit:relay');
    foreach (['sockit:doctor', 'sockit:relay'] as $command) {
        expect(Artisan::call('help', ['command_name' => $command]))->toBe(0)
            ->and(Artisan::output())->toContain('--panel');
    }
    expect($queries)->toBe([])->and($manager->getConnections())->toBe([]);
});

it('rejects missing and unknown panels and invalid relay options', function (): void {
    expect(Artisan::call('sockit:doctor'))->toBe(2)
        ->and(Artisan::call('sockit:doctor', ['--panel' => 'acme.unknown']))->toBe(2)
        ->and(Artisan::call('sockit:relay', ['--once' => true]))->toBe(2)
        ->and(Artisan::call('sockit:relay', ['--panel' => 'acme.unknown', '--once' => true]))->toBe(2)
        ->and(Artisan::call('sockit:relay', ['--panel' => 'acme.unknown']))->toBe(2)
        ->and(Artisan::call('sockit:relay', ['--panel' => 'acme.unknown', '--once' => true, '--limit' => '0']))->toBe(2);
});
