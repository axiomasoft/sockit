<?php

declare(strict_types=1);

namespace SocKit\Internal\Console;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Operations\OutboxRelay;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\PanelDefinitions;

#[Internal]
final class RelayCommand extends Command
{
    protected $signature = 'sockit:relay {--panel=} {--once} {--limit=100} {--replay-mode=delivery}';

    protected $description = 'Run one bounded, host-authorized panel relay pass';

    public function handle(): int
    {
        $panel = $this->option('panel');
        $limit = $this->option('limit');
        $requested = $this->option('replay-mode');
        $mode = is_string($requested) ? ReplayMode::tryFrom($requested === 'normal' ? 'delivery' : $requested) : null;
        if (! is_string($panel) || $panel === '' || ! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000 || ! $this->option('once') || $mode === null) {
            $this->error('relay.usage');

            return 2;
        }
        try {
            $id = new PanelId($panel);
            if (! $this->laravel->make(PanelDefinitions::class)->has($id)) {
                $this->error('panel.unknown');

                return 2;
            }
        } catch (\Throwable) {
            $this->error('panel.invalid');

            return 2;
        }
        try {
            if (! $this->laravel->bound('sockit.relay.handles')) {
                throw new \RuntimeException('Host relay handles unavailable');
            }
            $handles = $this->laravel->make('sockit.relay.handles', ['panel' => $id]);
            if (! is_iterable($handles)) {
                throw new \RuntimeException('Invalid host relay handles');
            }
            $remaining = (int) $limit;
            $counts = ['confirmed' => 0, 'retry' => 0, 'unknown' => 0, 'quarantine' => 0];
            foreach ($handles as $handle) {
                if (! $handle instanceof OperationHandle || ! $handle->panel->id->equals($id)) {
                    throw new \RuntimeException('Invalid host relay handle');
                }
                if ($remaining <= 0) {
                    break;
                }
                foreach ($this->laravel->make(OutboxRelay::class)->poll(handle: $handle, mode: $mode, limit: $remaining) as $result) {
                    $counts[$result->status]++;
                    $remaining--;
                    $this->line($result->reason);
                }
            }
            $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

            return $counts['retry'] + $counts['unknown'] + $counts['quarantine'] === 0 ? 0 : 1;
        } catch (\Throwable) {
            $this->error('relay.context_or_storage_unavailable');

            return 1;
        }
    }
}
