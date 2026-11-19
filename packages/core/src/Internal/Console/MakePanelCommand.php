<?php

declare(strict_types=1);

namespace SocKit\Internal\Console;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Internal\Generation\ExclusiveWriter;
use SocKit\Internal\Generation\GenerationFailure;
use SocKit\Internal\Generation\PanelGenerator;

#[Internal]
final class MakePanelCommand extends Command
{
    protected $signature = 'sockit:make-panel {name : PascalCase panel name}
        {--id= : Required stable dotted panel ID, e.g. acme.article}
        {--host= : Required host entity alias}
        {--modules= : Comma-separated installed, offered and enabled module IDs; empty selects kernel only}
        {--prefix= : Required explicit table prefix}
        {--connection= : Required configured PostgreSQL connection with a deployment descriptor}
        {--schema=public : Explicit PostgreSQL schema}
        {--dry-run : Print the complete output plan without writing files or directories}';

    protected $description = 'Generate a consumer-owned panel provider and concrete models without overwriting host files';

    public function handle(PanelGenerator $generator, ExclusiveWriter $writer): int
    {
        $plan = null;
        try {
            $name = $this->argument('name');
            $options = [];
            foreach (['id', 'host', 'modules', 'prefix', 'connection', 'schema'] as $key) {
                $value = $this->option($key);
                if ($key === 'modules' && $value === null) {
                    $value = '';
                }
                GenerationFailure::require(valid: is_string($value), rule: 'GENERATION_OPTION_REQUIRED: --'.$key);
                $options[$key] = $value;
            }
            $plan = $generator->plan(root: $this->laravel->basePath(), name: $name, id: $options['id'], host: $options['host'],
                modules: $options['modules'] === '' ? [] : explode(',', $options['modules']), prefix: $options['prefix'], connection: $options['connection'], schema: $options['schema']);
            if (! $this->option('dry-run')) {
                $writer->install($plan);
            }
            $this->line(json_encode(['status' => $this->option('dry-run') ? 'dry_run' : 'installed', ...$plan->manifest], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $rule = match (true) {
                $error instanceof GenerationFailure => $error->getMessage(),
                $error instanceof SocKitException => $error->detail()['rule'] ?? 'GENERATION_CONFIGURATION_INVALID',
                default => 'GENERATION_PREFLIGHT_FAILED',
            };
            $this->line(json_encode(['status' => 'failed', 'problem' => $rule, 'manifest' => $plan?->manifest,
                'recovery' => $error instanceof GenerationFailure ? $error->recovery : []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
