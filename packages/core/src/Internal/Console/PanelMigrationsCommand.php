<?php

declare(strict_types=1);

namespace SocKit\Internal\Console;

use Illuminate\Console\Command;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Internal\Generation\GenerationFailure;
use SocKit\Schema\PanelMigrations;

final class PanelMigrationsCommand extends Command
{
    protected $signature = 'sockit:panel:migrations {panel : Stable registered panel ID} {--upgrade : Append to the consumer snapshot chain} {--dry-run : Inspect the complete output plan without writing}';

    protected $description = 'Generate immutable consumer-owned initial or additive panel migrations and missing models';

    public function handle(PanelMigrations $migrations): int
    {
        try {
            $panel = $this->argument('panel');
            $result = $migrations->run(root: $this->laravel->basePath(), panel: $panel, upgrade: (bool) $this->option('upgrade'), dryRun: (bool) $this->option('dry-run'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $problem = match (true) {
                $error instanceof GenerationFailure => $error->getMessage(),
                $error instanceof SocKitException => $error->detail()['rule'] ?? 'MIGRATION_CONFIGURATION_INVALID',
                default => 'MIGRATION_PREFLIGHT_FAILED',
            };
            $this->line(json_encode(['status' => 'failed', 'problem' => $problem,
                'recovery' => $error instanceof GenerationFailure ? $error->recovery : []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
