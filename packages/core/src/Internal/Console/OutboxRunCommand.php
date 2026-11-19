<?php

declare(strict_types=1);

namespace SocKit\Internal\Console;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use SocKit\Panels\PanelDefinitions;

#[Internal]
final class OutboxRunCommand extends Command
{
    protected $signature = 'sockit:outbox:run {--panel=} {--limit=100} {--replay-mode=normal}';

    protected $description = 'Run one bounded pass on the selected panel or all registered panels';

    public function handle(PanelDefinitions $panels): int
    {
        $panel = $this->option('panel');
        $ids = is_string($panel) ? [$panel] : array_keys($panels->all());
        $status = self::SUCCESS;
        foreach ($ids as $id) {
            $status = max($status, $this->call('sockit:relay', ['--panel' => $id, '--once' => true,
                '--limit' => $this->option('limit'), '--replay-mode' => $this->option('replay-mode')]));
        }

        return $status;
    }
}
