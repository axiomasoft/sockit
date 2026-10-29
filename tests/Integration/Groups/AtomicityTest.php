<?php

declare(strict_types=1);

use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Tests\Fixtures\Groups\MembershipScenario;

it('rolls back membership, aggregate and success receipt when persistence fails', function (string $stage): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Auto);
        $table = match ($stage) {
            'member' => 'driver_a_group_members', 'aggregate' => 'driver_a_groups', 'outbox' => 'driver_a_outbox', 'receipt' => 'driver_a_receipts', default => throw new RuntimeException('Invalid stage'),
        };
        $condition = match ($stage) {
            'member' => "state <> 'active'", 'aggregate' => 'revision < 2',
            'outbox' => "envelope::jsonb->>'event_type' <> 'groups.membership_activated'", default => "outcome IS DISTINCT FROM 'confirmed'",
        };
        $s->f->connection()->statement("ALTER TABLE panel_a.$table ADD CONSTRAINT p55_failure CHECK ($condition) NOT VALID");
        expect($s->run($g, 'join')->isSuccess())->toBeFalse()->and($s->state())->toBeNull()
            ->and($s->revision($g)->value)->toBe(1)
            ->and($s->f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'groups.membership_%'")->count())->toBe(0)
            ->and($s->f->connection()->table('panel_a.a_receipts')->where('outcome', 'confirmed')->count())->toBe(2);
    } finally {
        $s->cleanup();
    }
})->with(['member', 'aggregate', 'outbox', 'receipt']);

it('rolls back a failed pending membership insertion', function (): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Approval);
        $s->f->connection()->statement("ALTER TABLE panel_a.driver_a_group_members ADD CONSTRAINT p55_pending_failure CHECK (state <> 'pending')");
        expect($s->run($g, 'join')->isSuccess())->toBeFalse()->and($s->state())->toBeNull()
            ->and($s->revision($g)->value)->toBe(1)
            ->and($s->f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'groups.membership_%'")->count())->toBe(0);
    } finally {
        $s->cleanup();
    }
});
