<?php

declare(strict_types=1);

namespace SocKit\Reactions\Internal;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\PanelDefinitions;
use SocKit\Reactions\Reactions;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\TargetRef;

#[Internal]
final class RebuildCommand extends Command
{
    protected $signature = 'sockit:reactions:rebuild {--panel=} {--target=} {--incarnation=1}';

    protected $description = 'Rebuild guarded reaction projections for registered maintenance handles';

    public function handle(PanelDefinitions $panels, Reactions $reactions): int
    {
        try {
            $selection = $this->option('panel');
            $definitions = is_string($selection) ? [$panels->get(new PanelId($selection))] : $panels->all();
            $wire = $this->option('target');
            $incarnation = $this->option('incarnation');
            if (! is_string($incarnation) || ! ctype_digit($incarnation)) {
                return self::INVALID;
            }
            $target = is_string($wire) ? new TargetRef(HostEntityRef::fromWire($wire), new Incarnation((int) $incarnation)) : null;
            $count = 0;
            foreach ($definitions as $panel) {
                if (! in_array('sockit.reactions', $panel->modules, true)) {
                    continue;
                }
                $handles = $this->laravel->make('sockit.relay.handles', ['panel' => $panel->id]);
                if (! is_iterable($handles)) {
                    throw new \RuntimeException;
                }
                foreach ($handles as $handle) {
                    if (! $handle instanceof OperationHandle || ! $handle->panel->id->equals($panel->id)) {
                        throw new \RuntimeException;
                    }
                    if ($target === null) {
                        $count += $reactions->rebuildForSurface($handle);
                    } else {
                        $reactions->rebuild($handle, $target);
                        $count++;
                    }
                }
            }
            $this->line(json_encode(['targets' => $count], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('reactions.rebuild.context_or_storage_unavailable');

            return self::FAILURE;
        }
    }
}
