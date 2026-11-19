<?php

declare(strict_types=1);

namespace SocKit\Diagnostics;

use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Api;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use SocKit\Panels\PanelDefinitions;
use SocKit\Schema\SchemaReadiness;

#[Api(since: '1.0')]
final readonly class SockitDoctorCheck implements DoctorCheck
{
    public function __construct(private Application $app) {}

    public function key(): string
    {
        return 'sockit.runtime.configuration';
    }

    public function title(): string
    {
        return 'SocKit panel configuration and storage readiness';
    }

    public function run(DoctorContext $context): array
    {
        $findings = [];
        foreach ($this->app->make(PanelDefinitions::class)->all() as $panel) {
            $report = $this->app->make(SchemaReadiness::class)->inspect($this->app->basePath(), $panel, probeStorage: $context->mayProbeStorage());
            if ($report['problems'] === []) {
                $findings[] = DoctorFinding::ok('sockit.panel.configured', 'SocKit panel configuration verified.', evidence: ['panel' => $panel->id->value, 'status' => $report['status']]);
            }
            foreach ($report['problems'] as $problem) {
                $findings[] = DoctorFinding::error('sockit.panel.unready', 'SocKit panel readiness check failed.', $problem['action'],
                    ['panel' => $panel->id->value, 'rule' => $problem['code'], 'step' => $problem['step']]);
            }
        }

        return $findings === [] ? [DoctorFinding::skipped('sockit.panels.empty', 'No registered SocKit panels.')] : $findings;
    }
}
