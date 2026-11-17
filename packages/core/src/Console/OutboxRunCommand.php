<?php

declare(strict_types=1);

namespace SocKit\Console;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use SocKit\Operations\Contracts\OutboxRelay;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelDefinition;
use SocKit\Panels\Data\PanelId;

#[Internal]
final class OutboxRunCommand extends Command
{
    protected $signature = 'sockit:outbox:run {--panel= : Limit relay to a specific panel ID} {--limit=100 : Maximum pending events per panel} {--replay-mode=normal : normal|projection_only}';

    protected $description = 'Relay pending transactional outbox events across SocKit panels.';

    public function handle(OutboxRelay $relay, PanelDefinitions $panels): int
    {
        $limitOpt = $this->option('limit');
        $limit = max(1, is_int($limitOpt) || is_string($limitOpt) ? (int) $limitOpt : 100);
        $modeOpt = $this->option('replay-mode');
        $rawMode = is_string($modeOpt) ? $modeOpt : 'normal';
        $mode = ReplayMode::tryFrom($rawMode);

        if ($mode === null) {
            $this->error("Invalid replay mode [{$rawMode}]. Expected 'normal' or 'projection_only'.");

            return self::INVALID;
        }

        $panelOption = $this->option('panel');
        $targetIds = is_string($panelOption) && $panelOption !== ''
            ? [new PanelId($panelOption)]
            : array_map(static fn (PanelDefinition $def): PanelId => $def->id, array_values($panels->all()));

        $total = 0;
        foreach ($targetIds as $panelId) {
            $delivered = $relay->relayPending($panelId, $limit, $mode);
            $total += $delivered;
            $this->info("Panel [{$panelId->value}]: relayed {$delivered} event(s).");
        }

        $this->line("Total relayed: {$total}");

        return self::SUCCESS;
    }
}
