<?php

declare(strict_types=1);

namespace SocKit\Console;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use SocKit\Modules\Reactions\Contracts\ReactionService;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;

#[Internal]
final class RebuildReactionsCommand extends Command
{
    protected $signature = 'sockit:reactions:rebuild {--panel= : Target panel ID (all reactions-enabled panels when omitted)}';

    protected $description = 'Rebuild reaction_counts deterministically from reactions without triggering notifications.';

    public function handle(ReactionService $reactions, PanelDefinitions $panels): int
    {
        $panelOption = $this->option('panel');
        $targets = [];

        if (is_string($panelOption) && $panelOption !== '') {
            $targets[] = new PanelId($panelOption);
        } else {
            foreach ($panels->all() as $definition) {
                if ($definition->hasModule('reactions')) {
                    $targets[] = $definition->id;
                }
            }
        }

        foreach ($targets as $panelId) {
            $rebuilt = $reactions->rebuildCounts($panelId);
            $this->info("Rebuilt {$rebuilt} reaction count bucket(s) for panel [{$panelId->value}].");
        }

        return self::SUCCESS;
    }
}
