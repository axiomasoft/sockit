<?php

declare(strict_types=1);

namespace SocKit\Diagnostics;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use SocKit\Configuration\SockitOptions;
use SocKit\Database\StorageCollisionChecker;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Exceptions\PanelException;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use Throwable;

/**
 * Read-only Packman Doctor diagnostic verifying SocKit configuration, database connections,
 * compiled panel storage isolation, frozen surface definitions, and optional live schema presence.
 */
#[Api(since: '1.0')]
final readonly class SockitDoctorCheck implements DoctorCheck
{
    public function __construct(
        private Repository $config,
        private DatabaseManager $db,
        private PanelDefinitions $panels,
        private SurfaceDefinitions $surfaces,
        private StorageCollisionChecker $collisions,
    ) {}

    #[Override]
    public function key(): string
    {
        return 'sockit.runtime.configuration';
    }

    #[Override]
    public function title(): string
    {
        return 'SocKit configuration, panel isolation, surfaces, and storage schema';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        $findings = [];

        try {
            $rawConfig = $this->config->get('sockit', []);
            if (! is_array($rawConfig)) {
                return [
                    DoctorFinding::error(
                        code: 'sockit.doctor.invalid_config',
                        message: 'Configured sockit configuration root must be an array.',
                        remediation: 'Publish config/sockit.php or pass a valid array to sockit config.',
                    ),
                ];
            }

            $options = SockitOptions::fromArray($rawConfig);
        } catch (InvalidConfiguration $exception) {
            return [
                DoctorFinding::error(
                    code: 'sockit.doctor.invalid_config',
                    message: 'SocKit configuration validation failed: '.$exception->getMessage(),
                    remediation: 'Review config/sockit.php against SockitOptions closed schema.',
                ),
            ];
        }

        if ($options->connection !== null && ! is_array($this->config->get('database.connections.'.$options->connection))) {
            $findings[] = DoctorFinding::error(
                code: 'sockit.doctor.missing_connection',
                message: "Configured sockit storage connection [{$options->connection}] is not defined in database.connections.",
                remediation: 'Define the database connection in config/database.php or set sockit.storage.connection to null.',
                evidence: ['connection' => $options->connection],
            );
        }

        if (! $this->panels->isFrozen()) {
            $findings[] = DoctorFinding::error(
                code: 'sockit.doctor.panels_unfrozen',
                message: 'PanelDefinitions registry is not frozen.',
                remediation: 'Ensure SocKitServiceProvider boots panel registration before diagnostics run.',
            );

            return $findings;
        }

        if (! $this->surfaces->isFrozen()) {
            $findings[] = DoctorFinding::error(
                code: 'sockit.doctor.surfaces_unfrozen',
                message: 'SurfaceDefinitions registry is not frozen.',
                remediation: 'Ensure SocKitServiceProvider boots surface registration before diagnostics run.',
            );

            return $findings;
        }

        /** @var array<string, array<string, mixed>> $connections */
        $connections = is_array($this->config->get('database.connections')) ? $this->config->get('database.connections') : [];
        $defaultConnection = is_string($this->config->get('database.default')) ? $this->config->get('database.default') : 'sqlite';

        try {
            $this->collisions->assertNoCollisions($this->panels->all(), $connections, $defaultConnection);
        } catch (PanelException $exception) {
            $findings[] = DoctorFinding::error(
                code: 'sockit.doctor.storage_collision',
                message: $exception->getMessage(),
                remediation: 'Configure distinct table prefixes, schemas, or physical databases per SocKit panel.',
            );
        }

        $compiledPanels = $this->panels->all();

        if ($context->mayProbeStorage()) {
            if ($compiledPanels === []) {
                $targetTable = $options->tablePrefix->table('surface_instances');
                try {
                    $connName = $options->connection ?? $defaultConnection;
                    if (! $this->db->connection($connName)->getSchemaBuilder()->hasTable($targetTable)) {
                        $findings[] = DoctorFinding::warning(
                            code: 'sockit.doctor.missing_core_table',
                            message: "Core table [{$targetTable}] was not found on connection [{$connName}].",
                            remediation: 'Run SocKit schema migrations for sockit-core.',
                            evidence: ['connection' => $connName, 'table' => $targetTable],
                        );
                    }
                } catch (Throwable $exception) {
                    $findings[] = DoctorFinding::error(
                        code: 'sockit.doctor.connection_failed',
                        message: 'Database probe failed: '.$exception->getMessage(),
                        remediation: 'Verify database connectivity for the configured SocKit storage connection.',
                    );
                }
            } else {
                foreach ($compiledPanels as $panel) {
                    $connName = $panel->storage->connection !== '' ? $panel->storage->connection : ($options->connection ?? $defaultConnection);
                    $targetTable = $panel->storage->resolveTable('surface_instances');

                    try {
                        if (! $this->db->connection($connName)->getSchemaBuilder()->hasTable($targetTable)) {
                            $findings[] = DoctorFinding::warning(
                                code: 'sockit.doctor.missing_panel_table',
                                message: "Panel [{$panel->id->value}] table [{$targetTable}] does not exist on connection [{$connName}].",
                                remediation: 'Run migrations on the panel database connection.',
                                evidence: ['panel' => $panel->id->value, 'connection' => $connName, 'table' => $targetTable],
                            );
                        }
                    } catch (Throwable $exception) {
                        $findings[] = DoctorFinding::error(
                            code: 'sockit.doctor.panel_connection_failed',
                            message: "Database probe for panel [{$panel->id->value}] failed: ".$exception->getMessage(),
                            remediation: 'Verify connectivity for the panel database connection.',
                            evidence: ['panel' => $panel->id->value, 'connection' => $connName],
                        );
                    }
                }
            }
        }

        if ($findings === []) {
            $findings[] = DoctorFinding::ok(
                code: 'sockit.doctor.healthy',
                message: sprintf(
                    'SocKit is healthy (%d panel(s), %d surface(s) compiled).',
                    count($compiledPanels),
                    count($this->surfaces->all()),
                ),
                evidence: [
                    'panels' => count($compiledPanels),
                    'surfaces' => count($this->surfaces->all()),
                ],
            );
        }

        return $findings;
    }
}
