<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Feature;

use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Errors\Contracts\ProblemRegistry;
use SocKit\Core\Tests\Fixtures\AlphaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PanelAlphaProvider;
use SocKit\Diagnostics\SockitDoctorCheck;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Testkit\SocKitTestCase;

final class DoctorAndProblemRegistryTest extends SocKitTestCase
{
    /** @return list<class-string> */
    protected function sockitPanelProviders(): array
    {
        return [PanelAlphaProvider::class];
    }

    /** @return list<class-string> */
    protected function sockitSurfaceProviders(): array
    {
        return [AlphaWallSurfaceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function sockitPrivacyFloor(): array
    {
        return [
            'allowed_scopes' => ['direct', 'group', 'contour', 'platform'],
            'default_scope' => 'platform',
            'max_scope' => 'platform',
            'minor_default_scope' => 'direct',
            'minor_max_scope' => 'group',
            'contour_levels' => ['team'],
        ];
    }

    public function test_all_sockit_error_codes_are_registered_in_packman_problem_registry(): void
    {
        $this->assertAllErrorCodesRegistered($this->app->make(ProblemRegistry::class), SockitErrorCode::class);
    }

    public function test_doctor_check_and_diagnose_command_report_schema_warnings_before_migration_and_healthy_after(): void
    {
        $doctor = $this->app->make(SockitDoctorCheck::class);

        $beforeMigrate = $doctor->run(new DoctorContext(probeStorage: true));
        self::assertNotEmpty($beforeMigrate);
        self::assertSame(CheckStatus::Warning, $beforeMigrate[0]->status);
        self::assertSame('sockit.doctor.missing_panel_table', $beforeMigrate[0]->code);

        $this->migrateSockitSchema();

        $afterMigrate = $doctor->run(new DoctorContext(probeStorage: true));
        self::assertCount(1, $afterMigrate);
        self::assertSame(CheckStatus::Ok, $afterMigrate[0]->status);
        self::assertSame('sockit.doctor.healthy', $afterMigrate[0]->code);

        $this->artisan('sockit:diagnose', ['--json' => true, '--probe-storage' => true])
            ->assertExitCode(0);
    }
}
