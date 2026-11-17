<?php

declare(strict_types=1);

namespace SocKit\Console\Diagnostics;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Doctor\Enums\CheckStatus;
use SocKit\Diagnostics\SockitDoctorCheck;

#[Internal]
final class DiagnoseCommand extends Command
{
    protected $signature = 'sockit:diagnose {--json : Emit machine-readable JSON} {--probe-storage : Probe live database schema tables}';

    protected $description = 'Run read-only SocKit configuration, panel, surface, and schema diagnostics.';

    public function handle(SockitDoctorCheck $check): int
    {
        $findings = $check->run(new DoctorContext(probeStorage: (bool) $this->option('probe-storage')));
        $hasErrors = false;

        foreach ($findings as $finding) {
            if ($finding->status === CheckStatus::Error) {
                $hasErrors = true;
            }
        }

        if ((bool) $this->option('json')) {
            $payload = array_map(static fn (DoctorFinding $finding): array => [
                'status' => $finding->status->value,
                'severity' => $finding->severity->value,
                'code' => $finding->code,
                'message' => $finding->message,
                'remediation' => $finding->remediation,
                'evidence' => $finding->evidence,
            ], $findings);

            $this->line(json_encode(['findings' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $hasErrors ? self::FAILURE : self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $label = strtoupper($finding->status->value);
            $this->line("[{$label}] {$finding->code}: {$finding->message}");
            if ($finding->remediation !== '') {
                $this->line('  -> '.$finding->remediation);
            }
        }

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }
}
