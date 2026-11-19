<?php

declare(strict_types=1);

namespace SocKit\Internal\Console;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use SocKit\Panels\PanelDefinitions;
use Symfony\Component\Console\Output\BufferedOutput;

#[Internal]
final class DiagnoseCommand extends Command
{
    protected $signature = 'sockit:diagnose {--panel=} {--json} {--probe-storage}';

    protected $description = 'Inspect selected or all registered panel configurations, optionally probing live storage';

    public function handle(PanelDefinitions $panels): int
    {
        $panel = $this->option('panel');
        $ids = is_string($panel) ? [$panel] : array_keys($panels->all());
        $reports = [];
        $code = self::SUCCESS;
        foreach ($ids as $id) {
            $output = new BufferedOutput;
            $status = $this->runCommand('sockit:doctor', ['--panel' => $id, '--json' => true,
                '--configuration-only' => ! $this->option('probe-storage')], $output);
            $report = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
            $reports[$id] = $report;
            $code = max($code, $status);
            if (! $this->option('json')) {
                $this->line($id.' '.(is_array($report) && is_string($report['status'] ?? null) ? $report['status'] : 'unready'));
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode(['panels' => $reports], JSON_THROW_ON_ERROR));
        }

        return $code;
    }
}
