<?php

declare(strict_types=1);

namespace SocKit\Internal\Console;

use Illuminate\Console\Command;
use SocKit\Configuration\SocKitConfig;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\PanelDefinitions;
use SocKit\Schema\SchemaReadiness;

final class DoctorCommand extends Command
{
    protected $signature = 'sockit:doctor {--panel=} {--json} {--configuration-only}';

    protected $description = 'Check panel runtime readiness without modifying storage';

    public function handle(): int
    {
        $panel = $this->option('panel');
        try {
            if (! is_string($panel) || $panel === '') {
                throw new \InvalidArgumentException;
            }
            $id = new PanelId($panel);
            $definition = $this->laravel->make(PanelDefinitions::class)->get($id);
        } catch (\Throwable) {
            $this->line(json_encode(['status' => 'unready', 'panel' => is_string($panel) ? $panel : null,
                'errors' => ['panel.invalid_or_unknown'], 'problems' => [['code' => 'PANEL_INVALID_OR_UNKNOWN', 'step' => 'selection',
                    'action' => 'Pass --panel=<registered panel id>; no default panel is selected.']]], JSON_THROW_ON_ERROR));

            return 2;
        }
        $result = $this->laravel->make(SchemaReadiness::class)->inspect(root: $this->laravel->basePath(), panel: $definition, runtimeProbe: function (): array {
            $errors = [];
            foreach (SocKitConfig::resolverContracts() as $key => $contract) {
                try {
                    $this->laravel->make($contract);
                } catch (\Throwable) {
                    $errors[] = 'resolver.unavailable.'.$key;
                }
            }

            return $errors;
        }, probeStorage: ! $this->option('configuration-only'));
        $errors = [];
        foreach ($result['problems'] as $problem) {
            $errors[] = $problem['code'];
        }
        $result['errors'] = $errors;
        if ($errors !== []) {
            $result['status'] = 'unready';
        }
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->line('Panel '.$panel.': '.$result['status']);
            foreach ($result['problems'] as $problem) {
                $this->line($problem['code'].' ['.$problem['step'].'] '.($problem['path'] ?? '').': '.$problem['action']);
            }
            foreach ($errors as $error) {
                $this->line($error);
            }
            $this->line('Deliveries: '.json_encode($result['deliveries'], JSON_THROW_ON_ERROR));
        }

        return $errors === [] ? 0 : 1;
    }
}
